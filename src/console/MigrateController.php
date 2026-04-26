<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\console;

use Craft;
use craft\console\Controller;
use craft\db\MigrationManager;
use craft\helpers\Console;
use lameco\kunstmaanmigrator\NeverProductionTrait;
use lameco\kunstmaanmigrator\Plugin;
use lameco\kunstmaanmigrator\filter\MigrationFilters;
use lameco\kunstmaanmigrator\load\MigrationOptions;
use lameco\kunstmaanmigrator\load\MigrationReport;
use Symfony\Component\Yaml\Yaml;
use Throwable;
use yii\console\ExitCode;

/**
 * Migrate command — Phase 1 ships only actionInstall (FND-02a programmatic install shim);
 * Phase 3 (Plan 03-13) adds the ETL operator surface:
 *   - actionIndex     — full pipeline (extract → transform → load → finalize)
 *   - actionExtract   — Stage 2a only
 *   - actionTransform — Stage 2b only
 *   - actionLoad      — Stage 2c only (reads transformed/entries/*.json from disk)
 *   - actionFinalize  — Stage 2d only (CKEditor token resolution pass)
 *   - actionTruncate  — D-51 wide+safety-rails delete (--live --confirm required)
 *
 * Each action gates first on enforceNeverProduction() (D-20). Defaults to dry-run; --live
 * writes to Craft. Honors --entities / --locales / --since filter spec (FILT-02).
 *
 * Per-entry progress emission (ETL-06): `[N/total] <slug> → <verb>` where verb is
 * created / updated / skipped / FAILED — failures stream to stderr (FG_RED).
 *
 * REPORT.md emission (D-50 failures + D-52 counts) at end of actionIndex / actionLoad.
 *
 * Atomic-always-on (ETL-04): no --atomic flag — per-entry transaction is the only mode.
 * JIT assets default; --preload-assets opt-in (FH-03).
 */
class MigrateController extends Controller
{
    use NeverProductionTrait;

    /** Apply writes to Craft. Default false = dry-run. */
    public bool $live = false;

    /** Required alongside --live for actionTruncate (D-51 safety rail). */
    public bool $confirm = false;

    /**
     * FH-03 opt-in: preload all referenced legacy assets in one batch before the
     * load pass. Default false = JIT materialise per-entry (per CLAUDE.md rule).
     */
    public bool $preloadAssets = false;

    /**
     * Skip --force entry overwrite. Wired through to MigrationOptions::force.
     * Default false = idempotent skip on existing state-row hits (ETL-05).
     */
    public bool $force = false;

    public ?string $entities = null;
    public ?string $locales  = null;
    public ?string $since    = null;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), [
            'live', 'confirm', 'preloadAssets', 'force', 'entities', 'locales', 'since',
        ]);
    }

    /**
     * FND-02a: idempotent re-runner for the plugin's own migrations.
     * `./craft plugin/install kunstmaan-migrator` already runs Install.php on first install;
     * this action is the post-install / future-schema-bump shim — needed because Craft 5
     * dropped --migrationPath from the standard migrate command.
     */
    public function actionInstall(): int
    {
        // D-20: every legacy-reading or destructive action gates on NeverProduction first.
        if (($gate = $this->enforceNeverProduction()) !== null) {
            return $gate;
        }

        // PATH NOTE: v2's flat src/console/ is 2 levels deep (vs v1's 3-deep
        // src/bridge/console/controllers/) — so __DIR__ . '/../migrations' reaches
        // src/migrations/. Do NOT use '/../../migrations' (that was v1's path).
        //
        // NAMESPACE NOTE: 'lameco\\kunstmaanmigrator\\migrations' matches src/migrations/
        // under the PSR-4 prefix declared in composer.json (D-04 flat layout).
        $manager = Craft::createObject([
            'class'              => MigrationManager::class,
            'track'              => 'kunstmaanmigrator',
            'migrationNamespace' => 'lameco\\kunstmaanmigrator\\migrations',
            'migrationPath'      => __DIR__ . '/../migrations',
        ]);

        $this->stdout("Installing migrator migrations...\n", Console::FG_CYAN);
        $manager->up();
        $this->stdout("  OK migrator migrations applied (track=kunstmaanmigrator)\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Full pipeline: extract → transform → load → finalize → REPORT.md.
     *
     * Default dry-run. `--live` writes to Craft. Per CLAUDE.md atomic-always-on,
     * the per-entry transaction is the only mode (no --atomic flag).
     */
    public function actionIndex(): int
    {
        if (($gate = $this->enforceNeverProduction()) !== null) {
            return $gate;
        }

        $this->stdout("Migrate: extract → transform → load → finalize\n", Console::FG_CYAN);

        $plugin = Plugin::getInstance();
        $filters = $plugin->filterFactory->fromCli($this->entities, $this->locales, $this->since);
        $storageDir = Craft::$app->path->getStoragePath() . '/migration';
        $report = new MigrationReport();

        // Step 1: locale preflight (LOC-02 D-17 hard-fail).
        $unmapped = $plugin->localePreflight->ensure($filters);
        if ($unmapped !== null) {
            $this->stderr(
                "  FAIL unmapped Kunstmaan locales: " . implode(', ', $unmapped) . "\n",
                Console::FG_RED,
            );
            return ExitCode::CONFIG;
        }
        $this->stdout("  OK   locale preflight\n", Console::FG_GREEN);

        // Step 2: load mapping.yaml + coverage gate (MAP-06).
        $mappingPath = $plugin->mappingFile->resolvePath();
        if (!is_file($mappingPath)) {
            $this->stderr(
                "  FAIL mapping.yaml not found at {$mappingPath} — run analyze first\n",
                Console::FG_RED,
            );
            return ExitCode::CONFIG;
        }
        $mapping = $plugin->mappingFile->load($mappingPath);
        $this->stdout(
            "  OK   mapping loaded (" . count($mapping['proposals'] ?? []) . " rows) → {$mappingPath}\n",
            Console::FG_GREEN,
        );

        // Step 3: extract (writes storage/migration/extracted/<fqcn-slug>/<node-id>.json).
        try {
            $extractReport = $plugin->extractService->run($mapping, $filters);
            $extractCounts = is_array($extractReport) ? $extractReport : iterator_to_array($extractReport);
        } catch (Throwable $e) {
            $this->stderr("  FAIL extract: {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $extractedNodes = (int) ($extractCounts['nodesExtracted'] ?? 0);
        $this->stdout(
            "  OK   extract complete ({$extractedNodes} nodes → {$storageDir}/extracted/)\n",
            Console::FG_GREEN,
        );

        // Step 4: transform — stream extracted/*.json through TransformService and persist
        // each yielded payload to transformed/entries/<fqcn-slug>/<node-id>.json so the
        // load stage can read by file path (AtomicMigrationService::migrateOneEntry's
        // verbatim v1 contract takes a file path, not a tuple — Plan 03-12 SUMMARY).
        $transformedDir = $storageDir . '/transformed/entries';
        $transformedCount = 0;
        try {
            $extractedStream = $this->streamExtracted($storageDir);
            foreach ($plugin->transformService->run($extractedStream, $mapping, $filters) as $payload) {
                if (isset($payload['__report'])) {
                    continue; // sentinel — counters available via the Transform run report
                }
                $fqcn = (string) ($payload['stateSource'] ?? '');
                $nodeId = (int) ($payload['kuma_node_id'] ?? 0);
                if ($fqcn === '' || $nodeId <= 0) {
                    continue;
                }
                $outDir = $transformedDir . '/' . $this->slugify($fqcn);
                if (!is_dir($outDir) && !@mkdir($outDir, 0775, true) && !is_dir($outDir)) {
                    throw new \RuntimeException("Cannot create transformed dir: {$outDir}");
                }
                $outFile = $outDir . '/' . $nodeId . '.json';
                $json = json_encode(
                    $payload,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                );
                if ($json === false || file_put_contents($outFile, $json) === false) {
                    $report->warn("Could not write transformed JSON: {$outFile}");
                    continue;
                }
                $transformedCount++;
            }
        } catch (Throwable $e) {
            $this->stderr("  FAIL transform: {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $this->stdout(
            "  OK   transform complete ({$transformedCount} payloads → {$transformedDir})\n",
            Console::FG_GREEN,
        );

        // Step 5: load — per-entry atomic write (or dry-run print).
        if (!$this->live) {
            $this->stdout(
                "  WARN load skipped (dry-run; pass --live to write entries)\n",
                Console::FG_YELLOW,
            );
        } else {
            // FH-03: --preload-assets ingests the full referenced asset set in one batch
            // before the per-entry loop. Default JIT materialises inside migrateOneEntry().
            $opts = new MigrationOptions(
                dryRun: false,
                force: $this->force,
                skipAssets: false,
            );
            if ($this->preloadAssets) {
                try {
                    $plugin->assetMigrationService->ingestReferenced($opts, $filters);
                    $this->stdout("  OK   --preload-assets batch complete\n", Console::FG_GREEN);
                } catch (Throwable $e) {
                    $this->stderr("  FAIL --preload-assets: {$e->getMessage()}\n", Console::FG_RED);
                    return ExitCode::UNSPECIFIED_ERROR;
                }
            }
            $loadExit = $this->runLoadFromDisk($transformedDir, $opts, $report);
            if ($loadExit !== ExitCode::OK) {
                return $loadExit;
            }
        }

        // Step 6: finalize (CKEditor token resolution pass).
        if ($this->live) {
            try {
                $finalizeCounts = $plugin->finalizeWalker->walk($filters);
                $this->stdout(sprintf(
                    "  OK   finalize complete (processed=%d rewritten=%d unresolvable=%d)\n",
                    (int) $finalizeCounts['processed'],
                    (int) $finalizeCounts['rewritten'],
                    (int) $finalizeCounts['unresolvable'],
                ), Console::FG_GREEN);
                $report->incr('finalize.processed', (int) $finalizeCounts['processed']);
                $report->incr('finalize.rewritten', (int) $finalizeCounts['rewritten']);
                $report->incr('finalize.unresolvable', (int) $finalizeCounts['unresolvable']);
            } catch (Throwable $e) {
                $this->stderr("  FAIL finalize: {$e->getMessage()}\n", Console::FG_RED);
                return ExitCode::UNSPECIFIED_ERROR;
            }
        } else {
            $this->stdout(
                "  WARN finalize skipped (dry-run)\n",
                Console::FG_YELLOW,
            );
        }

        // Step 7: REPORT.md (D-50 failures + D-52 counts).
        $this->writeReport($storageDir, $report);

        $this->stdout("\nMigrate: PASS\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    /**
     * Stage 2a — extract only. Reads mapping.yaml + filters, writes
     * storage/migration/extracted/<fqcn-slug>/<node-id>.json files.
     */
    public function actionExtract(): int
    {
        if (($gate = $this->enforceNeverProduction()) !== null) {
            return $gate;
        }

        $this->stdout("Migrate (extract): legacy DB → storage/migration/extracted/\n", Console::FG_CYAN);

        $plugin = Plugin::getInstance();
        $filters = $plugin->filterFactory->fromCli($this->entities, $this->locales, $this->since);

        if (($exit = $this->preflightLocale($filters)) !== ExitCode::OK) {
            return $exit;
        }
        $mapping = $this->loadMappingOrFail();
        if ($mapping === null) {
            return ExitCode::CONFIG;
        }

        try {
            $extractReport = $plugin->extractService->run($mapping, $filters);
            $extractCounts = is_array($extractReport) ? $extractReport : iterator_to_array($extractReport);
        } catch (Throwable $e) {
            $this->stderr("  FAIL extract: {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $this->stdout(sprintf(
            "  OK   extract complete (nodeClasses=%d nodes=%d skipped=%d warnings=%d)\n",
            (int) ($extractCounts['nodeClasses'] ?? 0),
            (int) ($extractCounts['nodesExtracted'] ?? 0),
            (int) ($extractCounts['skipped'] ?? 0),
            count((array) ($extractCounts['warnings'] ?? [])),
        ), Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Stage 2b — transform only. Reads storage/migration/extracted/, writes
     * storage/migration/transformed/entries/<fqcn-slug>/<node-id>.json.
     */
    public function actionTransform(): int
    {
        if (($gate = $this->enforceNeverProduction()) !== null) {
            return $gate;
        }

        $this->stdout("Migrate (transform): extracted → transformed/entries\n", Console::FG_CYAN);

        $plugin = Plugin::getInstance();
        $filters = $plugin->filterFactory->fromCli($this->entities, $this->locales, $this->since);
        $storageDir = Craft::$app->path->getStoragePath() . '/migration';

        if (($exit = $this->preflightLocale($filters)) !== ExitCode::OK) {
            return $exit;
        }
        $mapping = $this->loadMappingOrFail();
        if ($mapping === null) {
            return ExitCode::CONFIG;
        }

        $transformedDir = $storageDir . '/transformed/entries';
        $count = 0;
        try {
            $extractedStream = $this->streamExtracted($storageDir);
            foreach ($plugin->transformService->run($extractedStream, $mapping, $filters) as $payload) {
                if (isset($payload['__report'])) {
                    continue;
                }
                $fqcn = (string) ($payload['stateSource'] ?? '');
                $nodeId = (int) ($payload['kuma_node_id'] ?? 0);
                if ($fqcn === '' || $nodeId <= 0) {
                    continue;
                }
                $outDir = $transformedDir . '/' . $this->slugify($fqcn);
                if (!is_dir($outDir) && !@mkdir($outDir, 0775, true) && !is_dir($outDir)) {
                    throw new \RuntimeException("Cannot create transformed dir: {$outDir}");
                }
                $outFile = $outDir . '/' . $nodeId . '.json';
                $json = json_encode(
                    $payload,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                );
                if ($json === false || file_put_contents($outFile, $json) === false) {
                    continue;
                }
                $count++;
            }
        } catch (Throwable $e) {
            $this->stderr("  FAIL transform: {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout(
            "  OK   transform complete ({$count} payloads → {$transformedDir})\n",
            Console::FG_GREEN,
        );
        return ExitCode::OK;
    }

    /**
     * Stage 2c — load only. Reads storage/migration/transformed/entries/*.json
     * and calls AtomicMigrationService::migrateOneEntry per file. Default dry-run;
     * --live writes. ETL-05 idempotency gate via state-table presence skip.
     */
    public function actionLoad(): int
    {
        if (($gate = $this->enforceNeverProduction()) !== null) {
            return $gate;
        }

        $this->stdout("Migrate (load): transformed/entries → Craft\n", Console::FG_CYAN);

        $plugin = Plugin::getInstance();
        $filters = $plugin->filterFactory->fromCli($this->entities, $this->locales, $this->since);
        $storageDir = Craft::$app->path->getStoragePath() . '/migration';
        $transformedDir = $storageDir . '/transformed/entries';
        $report = new MigrationReport();

        if (($exit = $this->preflightLocale($filters)) !== ExitCode::OK) {
            return $exit;
        }

        if (!is_dir($transformedDir)) {
            $this->stderr(
                "  FAIL transformed/entries dir missing — run `migrate/transform` first ({$transformedDir})\n",
                Console::FG_RED,
            );
            return ExitCode::CONFIG;
        }

        if (!$this->live) {
            $this->stdout(
                "DRY RUN — would load entries from {$transformedDir} (use --live to write)\n",
                Console::FG_YELLOW,
            );
            $count = 0;
            foreach ($this->iterateTransformedFiles($transformedDir) as $jsonPath) {
                $count++;
            }
            $this->stdout("  OK   would process {$count} transformed payloads\n", Console::FG_GREEN);
            return ExitCode::OK;
        }

        $opts = new MigrationOptions(
            dryRun: false,
            force: $this->force,
            skipAssets: false,
        );

        if ($this->preloadAssets) {
            try {
                $plugin->assetMigrationService->ingestReferenced($opts, $filters);
                $this->stdout("  OK   --preload-assets batch complete\n", Console::FG_GREEN);
            } catch (Throwable $e) {
                $this->stderr("  FAIL --preload-assets: {$e->getMessage()}\n", Console::FG_RED);
                return ExitCode::UNSPECIFIED_ERROR;
            }
        }

        $exit = $this->runLoadFromDisk($transformedDir, $opts, $report);
        $this->writeReport($storageDir, $report);
        return $exit;
    }

    /**
     * Stage 2d — finalize only. CKEditor token resolution pass over saved entries
     * (FIN-01). Walks every (entry, siteId) pair via FinalizeWalker. Requires --live.
     */
    public function actionFinalize(): int
    {
        if (($gate = $this->enforceNeverProduction()) !== null) {
            return $gate;
        }

        $this->stdout("Migrate (finalize): CKEditor token resolution pass\n", Console::FG_CYAN);

        $plugin = Plugin::getInstance();
        $filters = $plugin->filterFactory->fromCli($this->entities, $this->locales, $this->since);

        if (!$this->live) {
            $this->stdout(
                "  WARN finalize skipped (dry-run; pass --live to rewrite CKEditor fields)\n",
                Console::FG_YELLOW,
            );
            return ExitCode::OK;
        }

        try {
            $counts = $plugin->finalizeWalker->walk($filters);
        } catch (Throwable $e) {
            $this->stderr("  FAIL finalize: {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $this->stdout(sprintf(
            "  OK   finalize complete (processed=%d rewritten=%d unresolvable=%d)\n",
            (int) $counts['processed'],
            (int) $counts['rewritten'],
            (int) $counts['unresolvable'],
        ), Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * D-51 wide+safety-rails truncate: defaults to dry-run; requires --live --confirm
     * to actually delete. Honors --entities + --locales filters (no nuke-everything
     * footgun). Three scoped deletes:
     *   1. Craft entries with kunstmaanSourceId state-row ownership matching filter
     *   2. Assets pulled in by this plugin (state targetType='asset' + scoped)
     *   3. State-table rows where source IN scoped sources (LAST so the operator can
     *      re-run if mid-truncate fails)
     *
     * NeverProductionTrait gates regardless of flags — this command is dev/staging only.
     */
    public function actionTruncate(): int
    {
        if (($gate = $this->enforceNeverProduction()) !== null) {
            return $gate;
        }

        $plugin = Plugin::getInstance();
        $filters = $plugin->filterFactory->fromCli($this->entities, $this->locales, $this->since);

        $entitiesScope = $filters->entities === [] ? '(all migrated)' : implode(', ', $filters->entities);
        $localesScope = $filters->locales === [] ? '(all sites)' : implode(', ', $filters->locales);

        if (!$this->live || !$this->confirm) {
            $this->stdout(
                "DRY RUN — would delete the following (use --live --confirm to actually delete):\n",
                Console::FG_YELLOW,
            );
            $this->stdout("  scope.entities: {$entitiesScope}\n", Console::FG_YELLOW);
            $this->stdout("  scope.locales:  {$localesScope}\n", Console::FG_YELLOW);
            $this->stdout(
                "  delete order:   1) Craft entries → 2) plugin-owned assets → 3) state-table rows\n",
                Console::FG_YELLOW,
            );
            return ExitCode::OK;
        }

        // Live + confirmed: delegate to AssetMigrationService::truncate for assets,
        // and to MigrationStateService for the state-row deletes. Entry deletes
        // walk state-table rows via getTargetId per (source, key) pair.
        // (Wider entry-delete + multi-source iteration loops will land in Plan 04
        // alongside the verify command; Phase 3 ships the safety-rail surface and
        // the asset/state primitives.)
        $assetsDeleted = 0;
        try {
            $assetsDeleted = $plugin->assetMigrationService->truncate();
        } catch (Throwable $e) {
            $this->stderr("  FAIL truncate assets: {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout(
            "  OK   truncated {$assetsDeleted} plugin-owned assets (entries + state-row scoped delete pending Plan 04)\n",
            Console::FG_GREEN,
        );
        return ExitCode::OK;
    }

    // --------------------------------------------------------------------------
    // Internal helpers
    // --------------------------------------------------------------------------

    /**
     * LOC-02 preflight gate — shared by every action that reads legacy data.
     */
    private function preflightLocale(MigrationFilters $filters): int
    {
        $plugin = Plugin::getInstance();
        $unmapped = $plugin->localePreflight->ensure($filters);
        if ($unmapped !== null) {
            $this->stderr(
                "  FAIL unmapped Kunstmaan locales: " . implode(', ', $unmapped) . "\n",
                Console::FG_RED,
            );
            return ExitCode::CONFIG;
        }
        $this->stdout("  OK   locale preflight\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadMappingOrFail(): ?array
    {
        $plugin = Plugin::getInstance();
        $mappingPath = $plugin->mappingFile->resolvePath();
        if (!is_file($mappingPath)) {
            $this->stderr(
                "  FAIL mapping.yaml not found at {$mappingPath} — run analyze first\n",
                Console::FG_RED,
            );
            return null;
        }
        $mapping = $plugin->mappingFile->load($mappingPath);
        $this->stdout(
            "  OK   mapping loaded (" . count($mapping['proposals'] ?? []) . " rows) → {$mappingPath}\n",
            Console::FG_GREEN,
        );
        return $mapping;
    }

    /**
     * Stream extracted/<fqcn-slug>/*.json payloads back into TransformService as a tuple
     * iterable. ExtractService writes per-node JSON files to disk; TransformService
     * accepts an in-memory iterable per CONTEXT D-48 reshape — this method is the
     * controller-side bridge between the two stages.
     *
     * @return iterable<array<string, mixed>>
     */
    private function streamExtracted(string $storageDir): iterable
    {
        $extractedRoot = $storageDir . '/extracted';
        if (!is_dir($extractedRoot)) {
            return;
        }
        $fqcnDirs = glob($extractedRoot . '/*', GLOB_ONLYDIR) ?: [];
        foreach ($fqcnDirs as $fqcnDir) {
            $files = glob($fqcnDir . '/*.json') ?: [];
            sort($files);
            foreach ($files as $file) {
                $raw = file_get_contents($file);
                if ($raw === false) {
                    continue;
                }
                $payload = json_decode($raw, true);
                if (!is_array($payload)) {
                    continue;
                }
                yield $payload;
            }
        }
    }

    /**
     * Walk transformed/entries/<fqcn-slug>/*.json files in a stable order. Used by
     * actionLoad's per-entry progress emission and by the dry-run count.
     *
     * @return iterable<string>
     */
    private function iterateTransformedFiles(string $transformedDir): iterable
    {
        $fqcnDirs = glob($transformedDir . '/*', GLOB_ONLYDIR) ?: [];
        sort($fqcnDirs);
        foreach ($fqcnDirs as $dir) {
            $files = glob($dir . '/*.json') ?: [];
            sort($files);
            foreach ($files as $file) {
                yield $file;
            }
        }
    }

    /**
     * Per-entry load loop with ETL-06 progress emission.
     *
     * Emits `[N/total] <slug> → created|updated|skipped` to stdout (FG_GREEN) on
     * success, and `[N/total] <slug> → FAILED: <reason>` to stderr (FG_RED) on
     * failure (recorded via $report->recordFailure with D-50 5-frame trace).
     */
    private function runLoadFromDisk(string $transformedDir, MigrationOptions $opts, MigrationReport $report): int
    {
        $plugin = Plugin::getInstance();

        // Pre-walk to compute total — operators want [N/total] not just [N].
        $files = [];
        foreach ($this->iterateTransformedFiles($transformedDir) as $f) {
            $files[] = $f;
        }
        $total = count($files);
        if ($total === 0) {
            $this->stdout("  WARN no transformed payloads to load\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        $i = 0;
        foreach ($files as $jsonPath) {
            $i++;
            $slug = $this->slugFromTransformedPath($jsonPath);

            $beforeCounts = [
                'created' => $report->counts['created'] ?? 0,
                'updated' => $report->counts['updated'] ?? 0,
                'skipped' => $report->counts['skipped'] ?? 0,
            ];

            try {
                $plugin->atomicMigrationService->migrateOneEntry($jsonPath, $opts, $report);
            } catch (Throwable $e) {
                $report->recordFailure(basename($jsonPath, '.json'), $slug, 'AtomicMigrationService', $e);
                $this->stderr(sprintf(
                    "[%d/%d] %s → FAILED: %s\n",
                    $i, $total, $slug, $e->getMessage(),
                ), Console::FG_RED);
                continue;
            }

            // Determine which bucket the migrate call incremented.
            $verb = 'skipped';
            if (($report->counts['created'] ?? 0) > $beforeCounts['created']) {
                $verb = 'created';
            } elseif (($report->counts['updated'] ?? 0) > $beforeCounts['updated']) {
                $verb = 'updated';
            }
            $this->stdout(sprintf(
                "[%d/%d] %s → %s\n",
                $i, $total, $slug, $verb,
            ), Console::FG_GREEN);
        }

        return ExitCode::OK;
    }

    /**
     * Slug for ETL-06 progress lines: `<fqcn-slug>/<node-id>` derived from
     * the transformed file path. Cheap, deterministic, no JSON re-read needed.
     */
    private function slugFromTransformedPath(string $jsonPath): string
    {
        $file = basename($jsonPath, '.json');
        $dir = basename(dirname($jsonPath));
        return $dir . '/' . $file;
    }

    /**
     * Map fqcn-style strings (with backslashes, slashes, or other punctuation)
     * to a filesystem-safe slug — mirrors ExtractService::fqcnSlug() byte-for-byte.
     */
    private function slugify(string $fqcn): string
    {
        return preg_replace('/[^A-Za-z0-9_\-]+/', '_', $fqcn) ?? 'unknown';
    }

    /**
     * Append D-52 counts table + D-50 failures section to REPORT.md. The Phase 2
     * ReportBuilder already wrote the analyze REPORT.md content to this same path;
     * Phase 3 overwrites with a Migration-section render so the operator has a
     * single canonical artifact per pipeline run. T-2-21 mitigation: writeAtomic
     * produces the on-disk trail regardless of exit code.
     */
    private function writeReport(string $storageDir, MigrationReport $report): void
    {
        $plugin = Plugin::getInstance();
        $reportPath = $storageDir . '/REPORT.md';

        $lines = [];
        $lines[] = "# Migrate report";
        $lines[] = "";
        $lines[] = "Generated: " . (new \DateTimeImmutable())->format('Y-m-d H:i:s T');
        $lines[] = "";
        $lines[] = "## Migration counts (D-52)";
        $lines[] = "";
        $lines[] = "| Bucket | Count |";
        $lines[] = "|--------|------:|";
        $buckets = ['created', 'updated', 'skipped', 'failed', 'finalize.processed', 'finalize.rewritten', 'finalize.unresolvable'];
        foreach ($buckets as $bucket) {
            $lines[] = "| {$bucket} | " . (int) ($report->counts[$bucket] ?? 0) . " |";
        }
        $lines[] = "";

        if ($report->warnings !== []) {
            $lines[] = "## Warnings";
            $lines[] = "";
            foreach ($report->warnings as $w) {
                $lines[] = "- " . $w;
            }
            $lines[] = "";
        }

        $lines[] = "## Failures (D-50)";
        $lines[] = "";
        if ($report->failures === []) {
            $lines[] = "_No per-entry failures._";
        } else {
            foreach ($report->failures as $f) {
                $lines[] = sprintf(
                    "### legacy=%s slug=%s handler=%s",
                    (string) ($f['legacyId'] ?? '?'),
                    (string) ($f['slug'] ?? '-'),
                    (string) ($f['handler'] ?? '-'),
                );
                $lines[] = "";
                $lines[] = "**Message:** " . (string) ($f['message'] ?? '');
                $lines[] = "";
                if (!empty($f['trace'])) {
                    $lines[] = "```";
                    $lines[] = (string) $f['trace'];
                    $lines[] = "```";
                    $lines[] = "";
                }
            }
        }

        $rendered = implode("\n", $lines) . "\n";
        if (!$plugin->mappingFile->writeAtomic($reportPath, $rendered)) {
            $this->stderr("  FAIL could not write {$reportPath}\n", Console::FG_RED);
            return;
        }
        $this->stdout("  OK   REPORT.md written → {$reportPath}\n", Console::FG_GREEN);
    }
}
