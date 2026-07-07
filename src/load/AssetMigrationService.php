<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\load;

use lameco\kunstmaanmigrator\db\LegacyDbService;
use Craft;
use craft\elements\Asset;
use craft\helpers\App;
use craft\helpers\Console;
use RuntimeException;
use Throwable;
use yii\base\Component;

/**
 * Referenced-only asset migration from Kunstmaan to Craft (DEC-12 / DEC-13).
 *
 * v2 reshape (FH-03 — JIT default + --preload-assets opt-in):
 *  - JIT entry point: resolveFromLegacyId(int $legacyId): int — materialises a
 *    single asset on demand. Called from CkeditorRewriterService::rewriteCurlyMediaTokens
 *    for `{{kuma:media:<id>}}` tokens.
 *  - resolveFromLegacyUrl(string $legacyUrl): int — the `_asset` JIT path,
 *    called from PayloadEntrySaver and CkeditorRewriterService.
 *  - --preload-assets opt-in batch: ingestReferenced(MigrationOptions, list<int>)
 *    pre-walks the in-scope referenced kuma_media ids before the entries loop.
 *    Repurposed from v1's batch-by-default; v2 makes it opt-in only. Not
 *    currently wired to a console flag (no caller sets it up yet).
 *
 * Each successful ingest writes a state row via MigrationStateService:
 *   source='media', sourceKey='kuma_media:{id}',
 *   targetType='asset' (local file) or 'video' (remote),
 *   targetId=<craft asset id | 0>, targetUid=<asset uid | null>,
 *   meta={ originalUrl, location, contentType, videoId? }
 *
 * Re-runs: ids already present in state are skipped unless $opts->force=true.
 *
 * Generic surface (CORE-05): Target volume and subfolder are config-driven.
 * Default: `$targetVolume = 'uploads'`, `$targetSubfolder = 'migrated'`.
 * Assets land in `migrated/{year}/` inside the existing uploads volume so
 * no dedicated volume YAML is needed. Override via setComponents or the
 * `config/kuma-loader.php` config file.
 *
 * Boundaries:
 *  - LEGACY_MEDIA_PATH env var roots the legacy file lookup.
 *  - Path traversal is handled by AssetPathResolver::resolveLocal
 *    (threat T-04-11; unit-tested).
 *  - Filename collisions are delegated to Craft's avoidFilenameConflicts
 *    handling (threat T-04-15).
 *
 * Reshape from v1 (~/Sites/craft-kunstmaan-migrator/src/bridge/load/AssetMigrationService.php):
 *  - Namespace flatten: bridge\load → load.
 *  - Drop the v1 asset-scan import — page-driven JIT default per FH-03; assets
 *    discover via the deferred-token resolver per-entry. ingestReferenced()
 *    accepts the current in-scope referenced id set; it never scans all
 *    kuma_media rows.
 *  - Drop the v1 batch-job import — queue out of scope per PROJECT.md (D-46);
 *    synchronous loop replaces queue.push.
 *  - Drop the v1 serialized-decoder import — replaced with `?object $serializedDecoder`
 *    null-slot (deferred to Phase 4); null-checks at call sites.
 *  - Drop the v1 report VO import — deferred to Plan 03-13. v1's `$report->incr(...)`
 *    becomes a local `$counts[...]` accumulator; `$report->warn(...)` becomes
 *    `Craft::warning(...)`. Plan 03-14 wires the VO and re-binds these markers.
 *  - Drop the v1 typed-config-error import → \RuntimeException.
 */
class AssetMigrationService extends Component
{
    // Public: Task 8's DoctorController::checkLegacyMediaRoot() reuses this
    // literal rather than duplicating it.
    public const LEGACY_MEDIA_ROOT_ENV = 'LEGACY_MEDIA_PATH';
    private const STATE_SOURCE = 'media';

    /**
     * Craft volume handle receiving migrated assets. Defaults to the
     * standard 'uploads' volume — no extra volume setup required.
     */
    public string $targetVolume = 'uploads';

    /**
     * Subfolder within $targetVolume where migrated assets are placed.
     * Assets land at `{$targetSubfolder}/{year}/filename`. Set to ''
     * to place directly in the volume root (not recommended).
     */
    public string $targetSubfolder = 'migrated';

    /**
     * When true, catches `yii\web\HttpException` thrown from
     * `Asset::EVENT_BEFORE_SAVE` listeners whose message matches the
     * starter-kit's "The file is too large" copy, downgrades to a WARN, and
     * skips that asset. Other validation throws still surface.
     *
     * Wired from `Settings::$skipAssetSizeValidation` via Plugin::init.
     * Surfaced 2026-05-09 — deklerk's >10MB PDF rejected by the starter-kit's
     * per-extension size cap (modules/lameco/Module.php).
     */
    public bool $skipAssetSizeValidation = false;

    /** DI slot: LegacyDbService (read-only connection to Kunstmaan MySQL). */
    public ?LegacyDbService $legacyDb = null;

    /** DI slot: MigrationStateService (has/record/forget/all state helpers). */
    public ?MigrationStateService $migrationState = null;

    /**
     * DI slot: serialized-blob decoder for kuma_media.metadata.
     * Deferred to Phase 4. Null-checked at every call site so Phase 3 ports
     * compile and run without the decoder; remote video metadata extraction
     * is a no-op until the decoder lands.
     */
    public ?object $serializedDecoder = null;

    /**
     * D-66 / D-68: per-asset RCA rows accumulated across one MigrateController
     * run. Populated by ingestRow() catch blocks every time an asset failure is
     * classified into the closed-set reason taxonomy. Read by
     * MigrateController::writeReport when emitting the `## Asset RCA` REPORT.md
     * section. Deliberately a property on the service (not threaded through
     * MigrationReport) so neither ingestRow nor ingestReferenced/ingestBatch
     * need a report-ref signature change — see plan 04-10 / Task 04 rationale.
     *
     * Each row: ['legacyId' => int, 'reason' => string, 'path' => string].
     *
     * @var list<array{legacyId: int, reason: string, path: string}>
     */
    public array $assetRcaRows = [];

    /**
     * JIT entry point (FH-03 default): materialise one asset by legacy
     * kuma_media id and return the Craft asset id. Returns 0 if the kuma_media
     * row is missing, the file cannot be located, or the asset is a remote
     * video (state row written but no Craft Asset element exists).
     *
     * Called from CkeditorRewriterService::rewriteCurlyMediaTokens for
     * `{{kuma:media:<id>}}` tokens.
     *
     * Idempotent: if a state row already exists for this kuma_media id, the
     * stored Craft asset id is returned without re-ingesting (unless force
     * is set on the active MigrationOptions — but JIT calls always use the
     * default options instance with force=false to keep per-entry cost low).
     */
    public function resolveFromLegacyId(int $legacyId): int
    {
        // Fast path: state already has this media id → return its target id.
        $existing = $this->migrationState?->getTargetId(self::STATE_SOURCE, 'kuma_media:' . $legacyId, null);
        if ($existing !== null) {
            return (int) $existing;
        }

        $opts = new MigrationOptions();
        $asset = $this->ingestOne($legacyId, $opts);
        if ($asset instanceof Asset) {
            return (int) $asset->id;
        }

        // ingestOne returned null — could be remote video (state row written,
        // no Asset element) or an unresolvable miss. Re-check state.
        $resolved = $this->migrationState?->getTargetId(self::STATE_SOURCE, 'kuma_media:' . $legacyId, null);
        return $resolved !== null ? (int) $resolved : 0;
    }

    /**
     * JIT fallback for raw CKEditor `/uploads/media/...` URLs that exist on
     * disk but no longer have a matching `kuma_media` row. This preserves live
     * editor content as the source of truth while keeping the state key distinct
     * from real `kuma_media:{id}` rows.
     */
    public function resolveFromLegacyUrl(string $legacyUrl): int
    {
        $path = parse_url($legacyUrl, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = preg_replace('/[?#].*$/', '', $legacyUrl) ?? $legacyUrl;
        }
        $path = '/' . ltrim($path, '/');
        if ($path === '/' || !str_starts_with($path, '/uploads/media/')) {
            return 0;
        }

        $stateKey = 'legacy_url:' . sha1($path);
        $existing = $this->migrationState?->getTargetId(self::STATE_SOURCE, $stateKey, null);
        if ($existing !== null) {
            return (int) $existing;
        }

        $rootDir = App::env(self::LEGACY_MEDIA_ROOT_ENV) ?: '';
        $sourcePath = AssetPathResolver::resolveLocal($path, $rootDir);
        if ($sourcePath === null) {
            return 0;
        }

        $contentType = function_exists('mime_content_type') ? (string) @mime_content_type($sourcePath) : '';
        $fileSize = @filesize($sourcePath);
        $opts = new MigrationOptions();
        $counts = [];
        $syntheticId = (int) sprintf('%u', crc32($path));
        $asset = $this->ingestRow([
            'id' => $syntheticId > 0 ? $syntheticId : 1,
            'url' => $path,
            'name' => pathinfo($path, PATHINFO_FILENAME),
            'content_type' => $contentType !== '' ? $contentType : 'application/octet-stream',
            'created_at' => date('Y-m-d H:i:s', (int) (filemtime($sourcePath) ?: time())),
            'filesize' => $fileSize !== false ? $fileSize : null,
        ], $rootDir, $opts, $counts, $stateKey);

        if ($asset instanceof Asset) {
            return (int) $asset->id;
        }

        $resolved = $this->migrationState?->getTargetId(self::STATE_SOURCE, $stateKey, null);
        return $resolved !== null ? (int) $resolved : 0;
    }

    /**
     * FH-03 opt-in: only called when MigrateController parses --preload-assets.
     * JIT default (resolveFromLegacyId) handles the rest.
     *
     * Walks the referenced-id set and ingests each kuma_media row.
     *
     * v1 returned MigrationReport — deferred to Plan 03-13. v2 emits warnings
     * via Craft::warning and accumulates counters in a local $counts array.
     * Plan 03-13 will reinstate the MigrationReport VO; Plan 03-14 re-wires
     * consumers.
     *
     * @param list<int> $referencedIds in-scope kuma_media ids collected from
     *                                 transformed/extracted payload references
     */
    public function ingestReferenced(MigrationOptions $opts, array $referencedIds = []): void
    {
        $counts = []; // MigrationReport VO deferred to Plan 03-13 — Phase 3 wiring lands in 03-14.

        $rootDir = App::env(self::LEGACY_MEDIA_ROOT_ENV);
        if (!is_string($rootDir) || $rootDir === '' || !is_dir($rootDir)) {
            // MigrationReport VO deferred to Plan 03-13 — Phase 3 wiring lands in 03-14.
            Craft::warning(
                sprintf('LEGACY_MEDIA_PATH env var missing or not a directory: %s', $rootDir ?: '(unset)'),
                __METHOD__,
            );
            return;
        }

        // Phase 9 / D-20: --preload-assets must stay page-driven. The current
        // payload set already embodies --entities and --since scoping, so this
        // method accepts that referenced-id set and explicitly avoids the old
        // full-table `SELECT id FROM kuma_media` prewalk. Empty referenced set
        // means there is nothing to preload; JIT resolution still handles any
        // asset token encountered during load.
        $ids = self::normalizeReferencedIds($referencedIds);
        $total = count($ids);

        if ($opts->verbosity > 0) {
            Console::stdout("Ingesting {$total} referenced kuma_media rows\n");
        }
        if ($total === 0) {
            return;
        }

        if (!$opts->dryRun && $opts->verbosity > 0) {
            Console::startProgress(0, $total);
        }

        $done = 0;
        foreach (array_chunk($ids, 200) as $chunk) {
            // Yii's Command::bindValues() treats integer keys as 1-indexed
            // positional PDO parameters — 0 is rejected. Use named placeholders
            // keyed by the id itself so the binding map is stable and unique.
            $bindings = [];
            $names = [];
            foreach ($chunk as $id) {
                $name = ':kid' . (int) $id;
                $bindings[$name] = (int) $id;
                $names[] = $name;
            }
            $placeholders = implode(',', $names);
            try {
                $rows = $this->legacyDb->queryAll(
                    "SELECT * FROM kuma_media WHERE id IN ({$placeholders})",
                    $bindings,
                );
            } catch (Throwable $e) {
                // MigrationReport VO deferred to Plan 03-13 — Phase 3 wiring lands in 03-14.
                Craft::warning("Batch lookup failed: {$e->getMessage()}", __METHOD__);
                continue;
            }

            foreach ($rows as $row) {
                try {
                    $this->ingestRow($row, $rootDir, $opts, $counts);
                } catch (Throwable $e) {
                    // MigrationReport VO deferred to Plan 03-13 — Phase 3 wiring lands in 03-14.
                    $counts['failed'] = ($counts['failed'] ?? 0) + 1;
                    Craft::warning(
                        "kuma_media:{$row['id']} failed: {$e->getMessage()}",
                        __METHOD__,
                    );
                    // D-08-23b: verbose RCA trace for the residual 0.04% asset
                    // failure. Emits a structured Craft::error line tagged
                    // `kuma-loader:asset-failure` so the next rehearsal's
                    // run log can be grepped for the exact source row id,
                    // exception class, resolved path context, and full
                    // trace without relying on --verbose.
                    Craft::error(
                        [
                            'tag' => 'kuma-loader:asset-failure',
                            'kuma_media_id' => $row['id'] ?? null,
                            'location' => $row['location'] ?? null,
                            'file_name' => $row['file_name'] ?? null,
                            'original_filename' => $row['original_filename'] ?? null,
                            'mime' => $row['content_type'] ?? null,
                            'file_size' => $row['file_size'] ?? null,
                            'resolved_path' => rtrim($rootDir, '/') . '/' . ltrim((string) ($row['location'] ?? ''), '/'),
                            'exception_class' => $e::class,
                            'exception_message' => $e->getMessage(),
                            'trace' => $e->getTraceAsString(),
                        ],
                        __METHOD__,
                    );
                    // D-66: structured single-line RCA emission. Closed-set reason
                    // taxonomy (filesystem_404 | mime_mismatch | too_large |
                    // deferred_unresolved). The dedicated 'kunstmaanmigrator.rca'
                    // log category lets operators grep run logs deterministically.
                    $reason = $this->classifyAssetFailureReason($e, $row);
                    $relativePath = (string) ($row['location'] ?? '');
                    Craft::info(
                        sprintf(
                            'RCA asset=%s reason=%s path=%s',
                            $row['id'] ?? '?',
                            $reason,
                            $relativePath,
                        ),
                        'kunstmaanmigrator.rca',
                    );
                    // Push into the per-run RCA collection so writeReport (D-68)
                    // can render the `## Asset RCA` table without re-grepping logs.
                    $this->assetRcaRows[] = [
                        'legacyId' => (int) ($row['id'] ?? 0),
                        'reason'   => $reason,
                        'path'     => $relativePath,
                    ];
                }

                $done++;
                if ($done % max(1, $opts->batchSize) === 0) {
                    Craft::$app->elements->invalidateAllCaches();
                    gc_collect_cycles();
                    if (!$opts->dryRun && $opts->verbosity > 0) {
                        Console::updateProgress($done, $total);
                    }
                }
            }
        }

        if (!$opts->dryRun && $opts->verbosity > 0) {
            Console::endProgress();
        }
    }

    /**
     * @param list<int> $ids
     * @return list<int>
     */
    private static function normalizeReferencedIds(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $out[$id] = true;
            }
        }
        $normalized = array_keys($out);
        sort($normalized, SORT_NUMERIC);
        return array_map('intval', $normalized);
    }

    /**
     * Ingests a single kuma_media row by id. Used by CKEditor rewrites that
     * encounter a media reference not captured by the scanner (fallback path)
     * and by the JIT entry point resolveFromLegacyId().
     */
    public function ingestOne(int $kumaMediaId, MigrationOptions $opts): ?Asset
    {
        $counts = []; // MigrationReport VO deferred to Plan 03-13 — Phase 3 wiring lands in 03-14.
        // v1 mediaById() helper dropped intentionally — page-driven JIT default per FH-03.
        // Replacement: inline kuma_media lookup via LegacyDbService::queryOne.
        $row = $this->legacyDb->queryOne(
            'SELECT * FROM kuma_media WHERE id = :id LIMIT 1',
            [':id' => $kumaMediaId],
        );
        if (!$row) {
            return null;
        }
        $rootDir = App::env(self::LEGACY_MEDIA_ROOT_ENV) ?: '';
        return $this->ingestRow($row, $rootDir, $opts, $counts);
    }

    /**
     * @param array<string, mixed> $row    kuma_media row
     * @param array<string, int>   $counts local counter accumulator
     *                                     (MigrationReport VO deferred to Plan 03-13)
     */
    private function ingestRow(
        array $row,
        string $rootDir,
        MigrationOptions $opts,
        array &$counts,
        ?string $stateKey = null,
    ): ?Asset {
        $mediaId = (int) $row['id'];
        $key = $stateKey ?? 'kuma_media:' . $mediaId;

        // D-08-20 fast-path: `--skip-assets` (CLI: options['skipAssets']=true)
        // short-circuits BEFORE any kuma_media payload read, FS stat, or
        // libpng-touching call. The stage-level short-circuit in
        // MigrateController::runLoadAssets() already covers full-run skips;
        // this inner guard makes ingestOne() (called per-entry during atomic
        // load and CKEditor rewrites) honour the same contract.
        if ($opts->skipAssets) {
            // MigrationReport VO deferred to Plan 03-13 — Phase 3 wiring lands in 03-14.
            $counts['skipped'] = ($counts['skipped'] ?? 0) + 1;
            return null;
        }

        // D-08-20 fast-path: skip FS/libpng work if this asset is already
        // in kunstmaanmigrator_state (i.e. already-migrated). One indexed
        // SELECT on (source, sourceKey) gates every re-run row BEFORE any
        // filesystem stat, copy(), or Image::* call can run.
        //
        // Even when --overwrite (force=true) is active, we skip re-processing
        // if the source file_size in kuma_media matches the file_size stored in
        // the state meta — the file is byte-for-byte identical so there is
        // nothing to update. Only a genuine mismatch (different size) or a
        // missing state record triggers the full pipeline on a force run.
        $stateRow = $this->migrationState->get(self::STATE_SOURCE, $key);
        if ($stateRow !== null) {
            $storedMeta = $stateRow['meta'] ?? null;
            if (is_string($storedMeta)) {
                $storedMeta = json_decode($storedMeta, true);
            }
            $storedSize = isset($storedMeta['file_size']) ? (int) $storedMeta['file_size'] : null;
            $sourceSize = isset($row['file_size']) ? (int) $row['file_size'] : null;

            // Skip when: not force, OR (force but sizes match — content identical).
            if (!$opts->force || ($storedSize !== null && $sourceSize !== null && $storedSize === $sourceSize)) {
                // MigrationReport VO deferred to Plan 03-13 — Phase 3 wiring lands in 03-14.
                $counts['skipped'] = ($counts['skipped'] ?? 0) + 1;
                return null;
            }
        }

        $contentType = (string) ($row['content_type'] ?? '');
        $location = $row['location'] ?? null;

        // Remote video classification: explicit remote/ content type, or
        // a content-type containing 'video' with a null/empty location
        // (per MIGRATION-PLAN §15.1). Local videos with location='local'
        // are still copied as regular files.
        $isRemoteVideo = str_starts_with($contentType, 'remote/')
            || (str_contains($contentType, 'video') && ($location === null || $location === ''));

        // Remote video: parse metadata for video id, record state, no file copy.
        if ($isRemoteVideo) {
            // Serialized-blob decoder deferred to Phase 4 — null-slot guard.
            // Without the decoder we cannot extract a video id from the
            // serialized blob; emit a warning and skip until Phase 4 wires it.
            $videoId = null;
            if ($this->serializedDecoder !== null && !empty($row['metadata'])) {
                $metadata = $this->serializedDecoder->decode((string) $row['metadata']);
                $videoId = $this->serializedDecoder->extractVideoId($metadata);
            }

            if ($videoId !== null) {
                if (!$opts->dryRun) {
                    $this->migrationState->record(
                        self::STATE_SOURCE,
                        $key,
                        'video',
                        0,
                        null,
                        null,
                        [
                            'kind'        => 'remote-video',
                            'videoId'     => $videoId,
                            'originalUrl' => $row['url'] ?? null,
                            'contentType' => $contentType,
                        ],
                    );
                }
                // MigrationReport VO deferred to Plan 03-13 — Phase 3 wiring lands in 03-14.
                $counts['created'] = ($counts['created'] ?? 0) + 1;
                return null; // no Asset element for remote videos
            }

            // No extractable id (or decoder absent) — log and skip; don't
            // persist a half-complete row.
            // MigrationReport VO deferred to Plan 03-13 — Phase 3 wiring lands in 03-14.
            Craft::warning(
                "kuma_media:{$mediaId} remote video has no extractable ID",
                __METHOD__,
            );
            $counts['skipped'] = ($counts['skipped'] ?? 0) + 1;
            return null;
        }

        // Local file path resolution with traversal guard.
        $sourcePath = AssetPathResolver::resolveLocal((string) ($row['url'] ?? ''), $rootDir);
        if ($sourcePath === null) {
            // MigrationReport VO deferred to Plan 03-13 — Phase 3 wiring lands in 03-14.
            Craft::warning(
                "kuma_media:{$mediaId} file not found or unsafe path",
                __METHOD__,
            );
            $counts['skipped'] = ($counts['skipped'] ?? 0) + 1;
            return null;
        }

        if ($opts->dryRun) {
            // MigrationReport VO deferred to Plan 03-13 — Phase 3 wiring lands in 03-14.
            $counts['created'] = ($counts['created'] ?? 0) + 1;
            return null;
        }

        // Target folder: configured volume → {subfolder}/{year}/ path.
        $volume = Craft::$app->volumes->getVolumeByHandle($this->targetVolume);
        if ($volume === null) {
            throw new RuntimeException(
                sprintf(
                    "'%s' volume not found — ensure the volume exists and run: php craft project-config/apply",
                    $this->targetVolume,
                ),
            );
        }

        $year       = AssetPathResolver::targetYear((string) ($row['created_at'] ?? ''));
        $folderPath = $this->targetSubfolder !== ''
            ? "{$this->targetSubfolder}/{$year}"
            : $year;
        $yearFolder = Craft::$app->assets->ensureFolderByFullPathAndVolume($folderPath, $volume);

        // Filename derived from the legacy URL (basename), then sanitized.
        $originalFilename = basename((string) ($row['url'] ?? 'asset-' . $mediaId));
        $safeName = AssetPathResolver::sanitizeFilename($originalFilename);
        $allowed = array_map(
            'strtolower',
            (array) Craft::$app->getConfig()->getGeneral()->allowedFileExtensions,
        );
        $safeName = self::normalizeLegacyFilenameForCraft($safeName, $contentType, $allowed);

        // Skip files whose extension is not in Craft's allowedFileExtensions
        // list (config/general.php). These are legacy-editorial artefacts
        // (.psd, .html, etc.) that Craft will refuse to save, producing
        // a validation error. Emitting a clean 'skipped' here keeps the
        // failure count honest — these rows genuinely cannot migrate and are
        // editorial garbage rather than migrator bugs.
        $extension = strtolower((string) pathinfo($safeName, PATHINFO_EXTENSION));
        if ($extension === '' || !in_array($extension, $allowed, true)) {
            // MigrationReport VO deferred to Plan 03-13 — Phase 3 wiring lands in 03-14.
            Craft::warning(
                "kuma_media:{$mediaId} file extension '{$extension}' is not in allowedFileExtensions — skipped",
                __METHOD__,
            );
            $counts['skipped'] = ($counts['skipped'] ?? 0) + 1;
            return null;
        }

        // Content fingerprint dedup: even on --force, if a Craft asset already
        // exists in the exact same folder with the same filename AND file size,
        // reuse it — skip the copy/save and always update the state record.
        $sourceFileSize = @filesize($sourcePath);
        if ($sourceFileSize !== false) {
            $existing = $this->findExistingCraftAsset($yearFolder->id, $safeName, $sourceFileSize);
            if ($existing !== null) {
                $this->migrationState->record(
                    self::STATE_SOURCE,
                    $key,
                    'asset',
                    (int) $existing->id,
                    $existing->uid,
                    null,
                    [
                        'kind'        => 'local-file',
                        'originalUrl' => $row['url'] ?? null,
                        'location'    => $location,
                        'contentType' => $contentType,
                        'copyright'   => $row['copyright'] ?? null,
                        'file_size'   => $sourceFileSize,
                        'deduped'     => true,
                    ],
                );
                // MigrationReport VO deferred to Plan 03-13 — Phase 3 wiring lands in 03-14.
                $counts['skipped'] = ($counts['skipped'] ?? 0) + 1;
                return $existing;
            }
        }

        // Copy into a temp location — Craft's saveElement will move it into
        // the volume's filesystem via the configured driver. Never rename
        // the source: LEGACY_MEDIA_PATH must stay intact for re-runs.
        $tempPath = Craft::$app->path->getTempPath()
            . '/kunstmaan-migrate-' . $mediaId . '-' . $safeName;

        $tCopyStart = microtime(true);
        if (!@copy($sourcePath, $tempPath)) {
            throw new RuntimeException("Copy failed: {$sourcePath} → {$tempPath}");
        }
        $tCopy = round((microtime(true) - $tCopyStart) * 1000);

        $asset = new Asset();
        $asset->tempFilePath = $tempPath;
        $asset->filename = $safeName;
        $asset->newFolderId = $yearFolder->id;
        $asset->avoidFilenameConflicts = true;
        if (!empty($row['name'])) {
            $asset->alt = (string) $row['name'];
        }
        $asset->setScenario(Asset::SCENARIO_CREATE);

        $tSaveStart = microtime(true);
        try {
            if (!Craft::$app->elements->saveElement($asset)) {
                @unlink($tempPath);
                throw new RuntimeException(
                    'Asset save failed: ' . json_encode($asset->getErrors()),
                );
            }
        } catch (\yii\web\HttpException $e) {
            // Bypass the starter-kit's per-extension size cap when the
            // operator opted in (Settings::$skipAssetSizeValidation). The
            // starter-kit's listener throws HttpException(400, "The file is
            // too large for {$ext} files. Maximum allowed size: …MB.").
            // Treat that specific message as a skip; bubble everything else.
            if ($this->skipAssetSizeValidation
                && $e->statusCode === 400
                && str_starts_with((string) $e->getMessage(), 'The file is too large')
            ) {
                @unlink($tempPath);
                Craft::warning(
                    "kuma_media:{$mediaId} skipped — size cap bypassed: " . $e->getMessage(),
                    __METHOD__,
                );
                $counts['skipped'] = ($counts['skipped'] ?? 0) + 1;
                return null;
            }
            @unlink($tempPath);
            throw $e;
        }
        $tSave = round((microtime(true) - $tSaveStart) * 1000);

        // Craft copied the temp file into the volume; our temp can go now.
        @unlink($tempPath);

        $tStateStart = microtime(true);
        $this->migrationState->record(
            self::STATE_SOURCE,
            $key,
            'asset',
            (int) $asset->id,
            $asset->uid,
            null,
            [
                'kind'        => 'local-file',
                'originalUrl' => $row['url'] ?? null,
                'location'    => $location,
                'contentType' => $contentType,
                'copyright'   => $row['copyright'] ?? null,
                'file_size'   => $sourceFileSize !== false ? $sourceFileSize : ($row['file_size'] ?? null),
            ],
        );
        $tState = round((microtime(true) - $tStateStart) * 1000);
        if ($opts->verbosity >= 2) {
            Craft::info(
                sprintf(
                    'kuma_media:%d copy=%dms save=%dms state=%dms total=%dms',
                    $mediaId,
                    $tCopy,
                    $tSave,
                    $tState,
                    $tCopy + $tSave + $tState,
                ),
                'kunstmaanmigrator:asset-timing',
            );
        }

        // MigrationReport VO deferred to Plan 03-13 — Phase 3 wiring lands in 03-14.
        $counts['created'] = ($counts['created'] ?? 0) + 1;
        return $asset;
    }

    /**
     * Normalize legacy image filenames that are valid browser/media content but
     * often not present in Craft's default allowedFileExtensions. Kunstmaan
     * sites can contain JPEG files named .jfif; importing them as .jpg preserves
     * the asset bytes while satisfying Craft's extension gate.
     *
     * @param list<string> $allowedExtensions lower-case Craft allowed extensions
     */
    private static function normalizeLegacyFilenameForCraft(string $safeName, string $contentType, array $allowedExtensions): string
    {
        $extension = strtolower((string) pathinfo($safeName, PATHINFO_EXTENSION));
        if (
            $extension === 'jfif'
            && !in_array('jfif', $allowedExtensions, true)
            && in_array('jpg', $allowedExtensions, true)
            && strtolower($contentType) === 'image/jpeg'
        ) {
            return preg_replace('/\.jfif$/i', '.jpg', $safeName) ?? $safeName;
        }

        return $safeName;
    }

    /**
     * Ingest a specific set of kuma_media IDs (without pre-scanning).
     * Used by integration tests and programmatic callers.
     *
     * v1 had this method serve a queue job — that import is dropped
     * intentionally (queue out of scope per PROJECT.md). The synchronous
     * loop body is preserved for test + programmatic use.
     *
     * Unlike ingestReferenced() this method has no console progress output —
     * it is intended for queue and programmatic contexts.
     *
     * @param int[] $ids
     */
    public function ingestBatch(array $ids, MigrationOptions $opts): void
    {
        $counts = []; // MigrationReport VO deferred to Plan 03-13 — Phase 3 wiring lands in 03-14.

        $rootDir = App::env(self::LEGACY_MEDIA_ROOT_ENV);
        if (!is_string($rootDir) || $rootDir === '' || !is_dir($rootDir)) {
            // MigrationReport VO deferred to Plan 03-13 — Phase 3 wiring lands in 03-14.
            Craft::warning(
                sprintf('LEGACY_MEDIA_PATH env var missing or not a directory: %s', $rootDir ?: '(unset)'),
                __METHOD__,
            );
            return;
        }

        if (count($ids) === 0) {
            return;
        }

        $bindings = [];
        $names = [];
        foreach ($ids as $id) {
            $name = ':kid' . (int) $id;
            $bindings[$name] = (int) $id;
            $names[] = $name;
        }
        $placeholders = implode(',', $names);

        try {
            $rows = $this->legacyDb->queryAll(
                "SELECT * FROM kuma_media WHERE id IN ({$placeholders})",
                $bindings,
            );
        } catch (Throwable $e) {
            // MigrationReport VO deferred to Plan 03-13 — Phase 3 wiring lands in 03-14.
            Craft::warning("Batch lookup failed: {$e->getMessage()}", __METHOD__);
            return;
        }

        foreach ($rows as $row) {
            try {
                $this->ingestRow($row, $rootDir, $opts, $counts);
            } catch (Throwable $e) {
                // MigrationReport VO deferred to Plan 03-13 — Phase 3 wiring lands in 03-14.
                $counts['failed'] = ($counts['failed'] ?? 0) + 1;
                Craft::warning(
                    "kuma_media:{$row['id']} failed: {$e->getMessage()}",
                    __METHOD__,
                );
                // D-66: structured single-line RCA emission. Same closed-set
                // reasons as ingestReferenced(); programmatic callers (queue
                // jobs, integration tests) feed REPORT.md too.
                $reason = $this->classifyAssetFailureReason($e, $row);
                $relativePath = (string) ($row['location'] ?? '');
                Craft::info(
                    sprintf(
                        'RCA asset=%s reason=%s path=%s',
                        $row['id'] ?? '?',
                        $reason,
                        $relativePath,
                    ),
                    'kunstmaanmigrator.rca',
                );
                $this->assetRcaRows[] = [
                    'legacyId' => (int) ($row['id'] ?? 0),
                    'reason'   => $reason,
                    'path'     => $relativePath,
                ];
            }
        }
    }

    /**
     * D-66: closed-set reason taxonomy classifier.
     *
     * Reasons: filesystem_404 | mime_mismatch | too_large | deferred_unresolved.
     * String-matching is intentionally loose — operators grep REPORT.md by
     * reason; over-classification is preferable to dropping into the catch-all
     * 'deferred_unresolved' bucket too eagerly.
     *
     * @param array<string, mixed> $row kuma_media row
     */
    private function classifyAssetFailureReason(Throwable $e, array $row): string
    {
        $msg = $e->getMessage();
        if (str_contains($msg, 'No such file')
            || str_contains($msg, 'not found')
            || str_contains($msg, 'Copy failed')
        ) {
            return 'filesystem_404';
        }
        if (str_contains($msg, 'mime')
            || str_contains($msg, 'content_type')
            || str_contains($msg, 'allowedFileExtensions')
        ) {
            return 'mime_mismatch';
        }
        if (str_contains($msg, 'too large') || str_contains($msg, 'PostMaxSize')) {
            return 'too_large';
        }
        return 'deferred_unresolved';
    }

    /**
     * Content-fingerprint dedup: find a Craft Asset that already lives in
     * $folderId with exactly $filename and $fileSize bytes.
     *
     * Used before every copy/save so that --force re-runs don't duplicate
     * files that are already physically present in the volume.
     */
    private function findExistingCraftAsset(int $folderId, string $filename, int $fileSize): ?Asset
    {
        $asset = Asset::find()
            ->folderId($folderId)
            ->filename($filename)
            ->size($fileSize)
            ->one();

        return $asset instanceof Asset ? $asset : null;
    }

    /**
     * Returns kuma_media ids that are NOT in the referenced set — useful for
     * the editor-review "skipped files" report (DEC-16).
     *
     * v1 used a pre-scan service to collect referenced ids; v2 dropped that
     * import (page-driven JIT default per FH-03). Orphan reporting is
     * deferred — the v2 implementation requires a referenced-id set built
     * from the entry-walk performed during transform, so this method is a
     * no-op stub until Plan 03-14 wires it.
     *
     * @return int[]
     */
    public function orphanReport(): array
    {
        // v1 pre-scan service dropped intentionally — page-driven JIT default per FH-03.
        // Phase 3 stub; orphan-set tracking deferred (NEXT-05).
        return [];
    }

}
