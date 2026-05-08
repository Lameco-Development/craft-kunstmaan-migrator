<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\load;

use Craft;
use lameco\kunstmaanmigrator\Plugin;
use RuntimeException;
use Throwable;
use yii\base\Component;

/**
 * Phase 3 plan 03-12 (verbatim port from v1's bridge/load/AtomicMigrationService.php).
 *
 * Single source of truth for the per-entry atomic write unit. Called from:
 *  - MigrateController::runLoadEntries (console load path)
 *  - MigrationJob::execute (queue --async path; out of scope for v2 Phase 3)
 *
 * Both callers iterate storage/migration/transformed/entries/*.json and
 * invoke migrateOneEntry() once per file. The service:
 *   1) Resolves section + entryType by handle from the transformed JSON.
 *   2) Walks the perSite tree in a single pass via ingestAndResolveAssets(),
 *      replacing deferred "asset:N" tokens with Craft asset IDs while
 *      materialising assets on demand via AssetMigrationService::resolveFromLegacyId
 *      (dedup + ingestOne + state-table lookup combined). File I/O happens
 *      before the DB transaction (PATTERNS §13 / Pitfall 1).
 *   3) Opens a Craft DB transaction (ETL-04 atomic-always-on, no flag) wrapping:
 *      - EntryMigrationService::saveEntryForSites()
 *      - MigrationStateService::updateMeta() with refIdsByLocale
 *      - SeoMigrationService::migrateForEntry() — DROPPED for Phase 3
 *        (PHASE 4 / ADP-01 reinstates the call inside the same closure to
 *        preserve atomicity with the entry save).
 *      The transaction auto-rolls-back on any Throwable.
 *
 * NOTE: This service does NOT write to the per-run log file or emit
 * console output. Callers own those concerns.
 *
 * Sibling-DI slots are wired by Plugin::init() in Plan 03-14.
 */
class AtomicMigrationService extends Component
{
    public ?MigrationStateService $migrationStateService = null;
    public ?EntryMigrationService $entryMigrationService = null;
    public ?AssetMigrationService $assetMigrationService = null;

    /**
     * Process-local kuma_node_id → Craft entry id map. Populated by every
     * successful entry save during the migrate run; consumed by hierarchy
     * resolution to look up a parent's Craft entry id from `kuma_parent_id`.
     * Cleared at the start of each migrate via resetHierarchyState().
     *
     * Stored statically so it survives across `migrateOneEntry()` calls in
     * the same process — they're invoked one-at-a-time per transformed JSON
     * by MigrateWorkflow's load loop, but each runs in a fresh transaction
     * scope where instance state would be lost.
     *
     * @var array<int, int>
     */
    private static array $kumaNodeIdToEntryId = [];

    /**
     * Pending fix-up queue: entries whose parent wasn't yet in the map at
     * save time. Walked once at the end of migrate to set parentId on
     * children whose parent migrated later in the iteration order.
     *
     * Each entry: ['kumaNodeId' => N, 'kumaParentId' => M, 'entryId' => E,
     *              'sectionId' => S, 'siteIds' => [int]].
     *
     * @var list<array{kumaNodeId: int, kumaParentId: int, entryId: int, sectionId: int}>
     */
    private static array $hierarchyFixupQueue = [];

    public static function resetHierarchyState(): void
    {
        self::$kumaNodeIdToEntryId = [];
        self::$hierarchyFixupQueue = [];
    }

    /**
     * @return list<array{kumaNodeId: int, kumaParentId: int, entryId: int, sectionId: int}>
     */
    public static function pendingHierarchyFixups(): array
    {
        return self::$hierarchyFixupQueue;
    }

    /**
     * @return array<int, int>
     */
    public static function kumaNodeIdMap(): array
    {
        return self::$kumaNodeIdToEntryId;
    }

    /**
     * Migrate one entry (atomic unit: JIT assets + entry save + per-entry SEO in Phase 4).
     *
     * Increments $report with 'created', 'updated', or 'skipped' so callers
     * can render per-entry progress without needing access to the state table.
     *
     * @param string           $jsonPath  Absolute path to one transformed JSON file.
     * @param MigrationOptions $opts      Caller-built options (overwrite / batchSize / dryRun).
     * @param MigrationReport  $report    Caller-owned accumulator; this method calls incr().
     *
     * @throws Throwable on any per-entry failure. Callers must try/catch
     *                   and log; the transaction is already rolled back.
     */
    public function migrateOneEntry(string $jsonPath, MigrationOptions $opts, MigrationReport $report): void
    {
        $raw = file_get_contents($jsonPath);
        if ($raw === false) {
            throw new RuntimeException("Cannot read transformed JSON: {$jsonPath}");
        }
        $transformed = json_decode($raw, true);
        if (!is_array($transformed) || !isset($transformed['kunstmaanSourceId'])) {
            throw new RuntimeException("Malformed transformed JSON (no kunstmaanSourceId): {$jsonPath}");
        }

        [$sourceStream, $nodeIdStr] = array_pad(
            explode(':', (string) $transformed['kunstmaanSourceId'], 2),
            2,
            '',
        );
        $sectionHandle = (string) ($transformed['section'] ?? '');
        $entryTypeHandle = (string) ($transformed['entryType'] ?? '');
        if ($sectionHandle === '' || $entryTypeHandle === '') {
            throw new RuntimeException("Transformed JSON missing section/entryType handle: {$jsonPath}");
        }

        $section = Craft::$app->getEntries()->getSectionByHandle($sectionHandle);
        $entryType = Craft::$app->getEntries()->getEntryTypeByHandle($entryTypeHandle);
        if ($section === null || $entryType === null) {
            throw new RuntimeException(
                "Section or entryType handle missing in Craft: section={$sectionHandle} entryType={$entryTypeHandle}",
            );
        }

        $sourceId = (int) $nodeIdStr;
        $perSite = (array) ($transformed['perSite'] ?? []);
        $overwrite = $opts->force;
        $isPromotedTarget = ($transformed['kind'] ?? '') === 'promotedTarget'
            || (bool) ($transformed['promotedTarget'] ?? false);

        $module = Plugin::getInstance();
        if ($module === null) {
            throw new RuntimeException('Lameco module unavailable in AtomicMigrationService');
        }

        // ETL-05 idempotency gate — state-table presence skip unless --force.
        $existingId = $this->migrationStateService->getTargetId($sourceStream, (string) $sourceId, null);
        if ($existingId !== null && !$overwrite) {
            // Entry exists and caller did not request an overwrite — will skip.
            $report->incr('skipped');
            // saveEntryForSites would also short-circuit, so we skip the full
            // write path here to avoid unnecessary work.
            return;
        }
        $outcome = ($existingId === null) ? 'created' : 'updated';

        // PHASE A — FILE I/O BEFORE TRANSACTION (PATTERNS §13 / Pitfall 1).
        // Asset file copy is not transactional; do it first. If the later
        // DB transaction rolls back, the copied file is orphaned but
        // harmless (no element references it).
        //
        // --skipAssets short-circuits the ingest loop: entries still save but
        // their asset handler calls will resolve null (no state row → empty
        // field). Intended for fast iteration during load debugging; re-run
        // without --skipAssets later to populate the missing refs.
        if (!$opts->skipAssets) {
            // Single-pass: walk the perSite tree once; for each asset token array
            // ["asset:N"] call AssetMigrationService::resolveFromLegacyId which
            // handles dedup, ingestOne(), and state-table lookup in one shot —
            // no separate collect + resolve passes.
            $perSite = $this->ingestAndResolveAssets(
                (array) ($transformed['perSite'] ?? []),
            );
            $transformed['perSite'] = $perSite;
        }

        // Per-locale legacy ref_ids. Produced by ExtractService (via
        // LegacyDbService::translationsFor which joins public node versions),
        // written into the transformed payload by TransformService. Needed by
        // SeoMigrationService (Phase 4 / ADP-01) to fetch the correct kuma_seo
        // row per site — each locale points to a different legacy entity id.
        $refIdsByLocale = (array) ($transformed['refIdsByLocale'] ?? []);

        // Hierarchy: resolve `kuma_parent_id` to a Craft entry id via the
        // process-local kuma-node-id map and inject it into every per-site
        // payload. Kunstmaan's node tree (`kuma_nodes.parent_id`) maps
        // cleanly onto Craft's Structure-section parentId. The state table
        // is keyed by ref_id (the Page entity row id), not kuma_node_id, so
        // a state lookup can't resolve parents directly — we maintain an
        // in-memory map populated on every save() during the migrate run.
        // First-pass entries whose parent hasn't been migrated yet leave
        // parentId unset and get fixed up by the end-of-load fix-up pass.
        $kumaNodeId = (int) ($transformed['kuma_node_id'] ?? 0);
        $kumaParentId = (int) ($transformed['kuma_parent_id'] ?? 0);
        if ($kumaParentId > 0 && isset(self::$kumaNodeIdToEntryId[$kumaParentId])) {
            $parentEntryId = self::$kumaNodeIdToEntryId[$kumaParentId];
            foreach ($perSite as $handle => $siteData) {
                if (is_array($siteData)) {
                    $perSite[$handle]['parentId'] = $parentEntryId;
                }
            }
            Craft::info(
                sprintf('hierarchy: kuma_node=%d → parent kuma_node=%d → entry %d', $kumaNodeId, $kumaParentId, $parentEntryId),
                __METHOD__,
            );
        } elseif ($kumaParentId > 0) {
            Craft::info(
                sprintf('hierarchy: kuma_node=%d parent kuma_node=%d NOT YET IN MAP (deferred to fix-up)', $kumaNodeId, $kumaParentId),
                __METHOD__,
            );
        }

        // PHASE B — DB TRANSACTION (ETL-04 atomic-always-on): saveEntryForSites
        // + state meta update + (Phase 4) per-entry SEO write.
        Craft::$app->db->transaction(function () use (
            $module,
            $section,
            $entryType,
            $sourceStream,
            $sourceId,
            $perSite,
            $overwrite,
            $opts,
            $refIdsByLocale,
            $report,
            $isPromotedTarget,
            $kumaNodeId,
            $kumaParentId,
        ): void {
            $entry = $isPromotedTarget
                ? $module->entryMigrationService->savePromotedTargetForSites(
                    $section->id,
                    $entryType->id,
                    $sourceStream,
                    $sourceId,
                    $perSite,
                    $overwrite,
                    $report,
                )
                : $module->entryMigrationService->saveEntryForSites(
                    $section->id,
                    $entryType->id,
                    $sourceStream,
                    $sourceId,
                    $perSite,
                    $overwrite,
                    $report,
                );

            // Register kuma_node_id → Craft entry id so subsequent saves
            // in this run can look up parents. Queue a fix-up if the parent
            // wasn't migrated yet — finalize() walks the queue at end of
            // load and stitches in the parents that came alphabetically
            // after their children.
            if ($kumaNodeId > 0) {
                self::$kumaNodeIdToEntryId[$kumaNodeId] = (int) $entry->id;
                if ($kumaParentId > 0
                    && !isset(self::$kumaNodeIdToEntryId[$kumaParentId])
                ) {
                    self::$hierarchyFixupQueue[] = [
                        'kumaNodeId' => $kumaNodeId,
                        'kumaParentId' => $kumaParentId,
                        'entryId' => (int) $entry->id,
                        'sectionId' => (int) $section->id,
                    ];
                }
            }

            // Merge refIdsByLocale into the state row's meta so the SEO
            // migrator (Phase 4) and any re-runs can resolve per-locale ref_ids
            // without re-reading the transformed JSON. Also persist
            // kumaNodeId — RedirectMigrationService's section-move synthesis
            // needs it (the legacy URL set lives in kuma_node_translations
            // keyed by node_id) but state.sourceKey carries refId, not
            // nodeId. Without this, section-move pairs random URL/entry
            // combinations and produces broken redirects (`/nl/diensten` →
            // `/personeels-dossier` because state.sourceKey=1 happens to
            // match many different node ids in the source).
            $metaPatch = [];
            if ($refIdsByLocale !== []) {
                $metaPatch['refIdsByLocale'] = $refIdsByLocale;
            }
            if ($kumaNodeId > 0) {
                $metaPatch['kumaNodeId'] = $kumaNodeId;
            }
            if ($metaPatch !== []) {
                $module->migrationStateService->updateMeta(
                    $sourceStream,
                    (string) $sourceId,
                    null,
                    $metaPatch,
                );
            }

            // Parallel state rows for per-locale refIds. State is keyed by
            // the canonical (primary-locale) refId; relation FKs in source
            // PageParts (e.g. ServicePagePart.page_id) point at THE LOCALE-
            // SPECIFIC entity row id, which differs from the canonical.
            // Without parallel rows, RelationHandler's getTargetId(source,
            // <localeRefId>) misses and the field stores []. Record a
            // parallel state row per non-canonical refId pointing at the
            // same Craft entry — same targetId/targetUid/targetType, just
            // a different sourceKey for cross-locale lookups. Idempotent
            // via record()'s upsert.
            $canonicalSourceId = (int) $sourceId;
            foreach ($refIdsByLocale as $localeRefId) {
                $localeRefId = (int) $localeRefId;
                if ($localeRefId <= 0 || $localeRefId === $canonicalSourceId) {
                    continue;
                }
                $module->migrationStateService->record(
                    source: $sourceStream,
                    key: (string) $localeRefId,
                    targetType: 'entry',
                    targetId: (int) $entry->id,
                    targetUid: (string) $entry->uid,
                    meta: ['canonicalSourceKey' => $canonicalSourceId],
                );
            }

            // PHASE 4 / ADP-01 reinstatement point: SEOmatic per-entry write
            // goes here. v1 invoked the SEOmatic service from this site with
            // the saved entry id, $opts, and $refIdsByLocale; that call is
            // intentionally omitted in Phase 3 and will be restored in Phase 4
            // inside this same closure (preserves atomicity with the entry
            // save). Keeping the closure shape stable means Phase 4 only
            // re-inserts the call without restructuring.
            unset($entry, $opts);
        });

        $report->incr($outcome);
    }

    /**
     * Walk the perSite fieldValues tree once: for every deferred asset token
     * array (["asset:N", ...]) call AssetMigrationService::resolveFromLegacyId
     * which materialises the asset via ingestOne() on cache-miss and returns
     * the Craft asset id.
     *
     * Replaces the previous two-pass approach (collectReferencedMediaIds +
     * resolveAssetTokens). Single-pass means each kuma_media id is visited
     * exactly once per entry thanks to AssetMigrationService's in-process cache.
     *
     * The two regexes (`/^asset:\d+$/` match form + `/^asset:(\d+)$/` capture
     * form) are tightly coupled to DeferredAssetToken::emit() per Plan 03-01's
     * paired-regex contract documentation — preserve byte-for-byte.
     *
     * @param array<string, mixed> $perSite
     * @return array<string, mixed>
     */
    private function ingestAndResolveAssets(array $perSite): array
    {
        $assetMigrationService = $this->assetMigrationService;
        $resolve = static function (mixed $value) use ($assetMigrationService, &$resolve): mixed {
            if (!is_array($value)) {
                return $value;
            }
            // Deferred asset token list: ["asset:N", ...] → resolve each to Craft id.
            $firstItem = reset($value);
            if (is_string($firstItem) && preg_match('/^asset:\d+$/', $firstItem)) {
                $ids = [];
                foreach ($value as $item) {
                    if (is_string($item) && preg_match('/^asset:(\d+)$/', $item, $m)) {
                        $craftId = $assetMigrationService->resolveFromLegacyId((int) $m[1]);
                        if ($craftId > 0) {
                            $ids[] = $craftId;
                        }
                    }
                }
                return $ids;
            }
            // Matrix block list or nested array: recurse.
            foreach ($value as $k => &$item) {
                if (is_array($item)) {
                    if (isset($item['fields']) && is_array($item['fields'])) {
                        foreach ($item['fields'] as $fk => &$fv) {
                            $item['fields'][$fk] = $resolve($fv);
                        }
                        unset($fv);
                    }
                    foreach ($item as $ik => &$iv) {
                        if ($ik !== 'fields' && is_array($iv)) {
                            $item[$ik] = $resolve($iv);
                        }
                    }
                    unset($iv);
                }
            }
            unset($item);
            return $value;
        };

        foreach ($perSite as &$siteData) {
            if (!is_array($siteData)) {
                continue;
            }
            foreach ($siteData['fieldValues'] ?? [] as $handle => &$val) {
                $siteData['fieldValues'][$handle] = $resolve($val);
            }
            unset($val);
        }
        unset($siteData);

        return $perSite;
    }
}
