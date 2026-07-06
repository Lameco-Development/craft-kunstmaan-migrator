<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\fields\handlers;

use lameco\kunstmaanmigrator\fields\FieldHandler;
use lameco\kunstmaanmigrator\fields\ResolverContext;
use lameco\kunstmaanmigrator\fields\DeferredAssetToken;
use lameco\kunstmaanmigrator\fields\DeferredEntryToken;
use RuntimeException;

/**
 * Resolves a legacy entity id (or list of ids) → Craft entry ids via the
 * state table.
 *
 * Options:
 *   stateSource      (string, REQUIRED) — state-table source key (e.g. 'news', 'cases')
 *   joinTable        (string, optional) — legacy M2M join-table name. When set,
 *                    the handler issues a SELECT against this table to expand the
 *                    Page's own ref_id into a list of foreign entity ids, then
 *                    resolves each foreign id via the state table.
 *   joinLocalColumn  (string, required when joinTable set) — column in joinTable
 *                    that matches the Page entity's own ref_id.
 *   joinForeignColumn(string, required when joinTable set) — column in joinTable
 *                    that holds the foreign entity id to resolve.
 *   maxResults       (int, optional) — cap the number of resolved ids returned.
 *                    When joinTable is set, the cap is applied via LIMIT on the
 *                    SQL query. Mapping authors with huge M2M tables MUST set this
 *                    to avoid unbounded result sets (T-06-02-02).
 *   joinTranslation  (array, optional) — resolves the id-space mismatch where a
 *                    Page FK (e.g. employee_id) points at a translation table
 *                    rather than the entity table the migrator keyed state rows on.
 *                    Array shape: { table: string, sourceColumn: string, targetColumn: string }
 *                    Each legacy id is looked up in `table` WHERE `sourceColumn` = id,
 *                    the `targetColumn` value is used as the real state key.
 *   taxonomySource   (string, optional) — marks this relation as taxonomy-backed.
 *                    On non-empty state miss, RelationHandler delegates to
 *                    the TaxonomyRelationResolver injected via ResolverContext.
 *                    The handler does not create taxonomy entries directly.
 *
 * Input normalisation:
 *   - scalar      → [int $id]
 *   - int[]       → cast to int[]
 *   - array<id>   → cast each element to int
 *   - null / []   → []
 *
 * For each id we first attempt a site-scoped lookup, then a site-agnostic
 * fallback — covers sources like 'cases' that store one row per legacy id
 * regardless of site. Misses are dropped silently; the driver can flag on
 * non-empty input → empty output if strict-mode is desired.
 *
 * Output: deduped, input-order-preserved list of Craft numeric ids.
 *
 * Security notes (T-06-02-01):
 *   All identifier option values (joinTable, joinLocalColumn, joinForeignColumn,
 *   joinTranslation.table/sourceColumn/targetColumn) MUST match ^[A-Za-z0-9_]+$
 *   before being interpolated into SQL via sprintf. All scalar values are bound
 *   as named PDO parameters (:ref, :id). LIMIT is cast to int before inline
 *   interpolation (MySQL PDO limitation — emulated prepares make bound LIMITs
 *   unreliable).
 */
final class RelationHandler implements FieldHandler
{
    public function id(): string
    {
        return 'relation';
    }

    public function resolve(mixed $legacyValue, ResolverContext $ctx, array $options = []): mixed
    {
        if (!isset($options['stateSource']) || $options['stateSource'] === '') {
            throw new RuntimeException("RelationHandler requires 'stateSource' option.");
        }
        $source = (string) $options['stateSource'];

        // Dispatch 1: joinTable path — caller provides the M2M join table.
        if (isset($options['joinTable'])) {
            return $this->resolveViaJoinTable($legacyValue, $ctx, $source, $options);
        }

        // Dispatch 2: joinTranslation path — FK points at a translation table
        // whose rows map to the state-keyed entity.
        if (isset($options['joinTranslation'])) {
            return $this->resolveViaJoinTranslation($legacyValue, $ctx, $source, $options);
        }

        // Dispatch 3 (DEFAULT / BACK-COMPAT): direct-id state lookup as today.
        return $this->resolveDirect($legacyValue, $ctx, $source, $options);
    }

    /**
     * Original direct-id lookup behaviour — back-compat path, with the
     * addition (Phase 12 / Gap [C]) of deferred-entry-token emission for
     * misses on entry-state sources. The migrator's pipeline runs
     * extract → transform → load sequentially: when resolveDirect runs
     * during transform, the state table is empty. Without a deferred
     * mechanism, every cross-page entry relation collapses to []
     * (servicePagePart.page, pageSelectPagePart.textPage,
     * specializationPagePart.* — the entire family of matrix-block
     * Entries fields). Asset relations were already covered by
     * resolveViaJoinTable's `asset:N` token; this method extends the same
     * pattern to direct entry relations via DeferredEntryToken.
     *
     * Output is a mixed list of resolved Craft ids (int) and deferred
     * entry tokens (string of the form `entry:<source>:<id>`). The
     * load-time consumer (AtomicMigrationService::ingestAndResolveEntryRelations)
     * resolves what state has and records the rest for the post-load
     * fixup pass (MigrateWorkflow::resolveDeferredEntryRelations).
     *
     * @return list<int|string>
     */
    private function resolveDirect(mixed $legacyValue, ResolverContext $ctx, string $source, array $options = []): array
    {
        if ($legacyValue === null || $legacyValue === '' || $legacyValue === []) {
            return [];
        }

        $ids = is_array($legacyValue) ? $legacyValue : [$legacyValue];
        $ids = array_map('intval', $ids);
        $ids = array_filter($ids, static fn(int $v): bool => $v > 0);

        $out = [];
        foreach ($ids as $id) {
            $targetId = $ctx->state->getTargetId($source, (string) $id, $ctx->siteId);
            if ($targetId === null) {
                // Fall back to site-agnostic row (stateSource is site-agnostic
                // for things like 'cases' where one entry maps all locales).
                $targetId = $ctx->state->getTargetId($source, (string) $id, null);
            }
            if ($targetId !== null) {
                $out[] = $targetId;
                continue;
            }

            $resolvedTaxonomyId = $this->resolveTaxonomyMiss($id, $ctx, $source, $options);
            if ($resolvedTaxonomyId !== null) {
                $out[] = $resolvedTaxonomyId;
                continue;
            }

            // Taxonomy-backed relations have their own resolver track
            // (resolveTaxonomyMiss above). When the explicit
            // `taxonomySource` option is set, the relation IS taxonomy —
            // a miss here means the taxonomy resolver couldn't reach the
            // target (already logged via the report). Don't emit a
            // deferred-entry token: load-time fixup can't reach taxonomy
            // entries through state.meta.blockIds either, so emitting a
            // token would just leak a string into the saved relation
            // payload. Preserve the legacy silent-drop semantics for
            // taxonomy.
            if (!empty($options['taxonomySource'])) {
                continue;
            }

            // State miss + non-taxonomy. Defer to the load-time fixup
            // pass unless the caller opted out via
            // `deferEntryRelations: false` (a safety valve for callers
            // that prefer the legacy silent-drop).
            $deferEnabled = ($options['deferEntryRelations'] ?? true) !== false;
            if ($deferEnabled && $this->isDeferableEntrySource($source)) {
                $out[] = DeferredEntryToken::emit($source, $id);
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Limit token emission to state-sources that map to Craft entries —
     * App_Entity_*, anything that doesn't smell like the asset-only `media`
     * source. The entry-token consumer is matrix-block-only so this filter
     * is conservative; expand only when a new source class needs it.
     */
    private function isDeferableEntrySource(string $source): bool
    {
        if ($source === 'media') {
            return false;
        }
        // App_Entity_Pages_<X>, App_Entity_PageParts_<X>, App_Entity_<Custom>
        return str_starts_with($source, 'App_Entity_')
            || str_contains($source, '\\Entity\\');
    }

    /**
     * Delegate a taxonomy-backed non-empty state miss to the injected
     * TaxonomyRelationResolver.
     *
     * @param array<string, mixed> $options
     */
    private function resolveTaxonomyMiss(
        int $legacyId,
        ResolverContext $ctx,
        string $stateSource,
        array $options,
    ): ?int {
        $taxonomySource = $this->taxonomySourceFromOptions($options, $stateSource);
        if ($taxonomySource === null) {
            return null;
        }

        if ($ctx->taxonomyResolver === null) {
            $ctx->report?->warn(sprintf(
                'taxonomy relation unresolved: %s id=%d missing taxonomy resolver dependency',
                $taxonomySource,
                $legacyId,
            ));
            return null;
        }

        $resolved = $ctx->taxonomyResolver->resolveReferenced(
            $taxonomySource,
            $legacyId,
            new \lameco\kunstmaanmigrator\load\MigrationOptions(dryRun: $ctx->dryRun),
            $ctx->report,
        );
        if ($resolved === null) {
            $ctx->report?->warn(sprintf(
                'taxonomy relation unresolved: %s id=%d no Craft target available',
                $taxonomySource,
                $legacyId,
            ));
        }

        return $resolved;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function taxonomySourceFromOptions(array $options, string $stateSource): ?string
    {
        foreach (['taxonomySource', 'taxonomyFqcn'] as $key) {
            if (isset($options[$key]) && is_string($options[$key]) && $options[$key] !== '') {
                return $options[$key];
            }
        }
        if (($options['taxonomy'] ?? false) === true || ($options['taxonomyBacked'] ?? false) === true) {
            return $stateSource;
        }
        return null;
    }

    /**
     * M2M join-table path.
     *
     * Expands the Page's own ref_id (legacyValue) to a list of foreign entity ids
     * via the legacy join table, then resolves each foreign id via the state table.
     *
     * For asset relations (stateSource='media', stateKeyPrefix='kuma_media:'), ids that
     * miss the state table emit "asset:N" deferred tokens instead of being dropped —
     * consistent with AssetHandler's deferred-token contract. Load-time resolution via
     * AtomicMigrationService::ingestAndResolveAssets() then materialises and resolves them.
     *
     * @param array<string, mixed> $options
     * @return list<int|string>
     */
    private function resolveViaJoinTable(mixed $legacyValue, ResolverContext $ctx, string $source, array $options): array
    {
        // 1. Validate required sub-options.
        if (empty($options['joinLocalColumn']) || !is_string($options['joinLocalColumn'])) {
            throw new RuntimeException(
                "RelationHandler: 'joinLocalColumn' is required (non-empty string) when 'joinTable' is set."
            );
        }
        if (empty($options['joinForeignColumn']) || !is_string($options['joinForeignColumn'])) {
            throw new RuntimeException(
                "RelationHandler: 'joinForeignColumn' is required (non-empty string) when 'joinTable' is set."
            );
        }

        $joinTable = (string) $options['joinTable'];
        $joinLocalColumn = (string) $options['joinLocalColumn'];
        $joinForeignColumn = (string) $options['joinForeignColumn'];

        // 2. Identifier whitelist — T-06-02-01.
        $identifierPattern = '/^[A-Za-z0-9_]+$/';
        foreach ([$joinTable, $joinLocalColumn, $joinForeignColumn] as $identifier) {
            if (!preg_match($identifierPattern, $identifier)) {
                throw new RuntimeException(
                    "RelationHandler: identifier '$identifier' contains invalid characters. "
                    . "Only [A-Za-z0-9_] are allowed."
                );
            }
        }

        // 3. Normalise legacyValue to a single int (the Page's own ref_id).
        if ($legacyValue === null || $legacyValue === '' || $legacyValue === []) {
            return [];
        }
        $refId = is_array($legacyValue) ? (int) reset($legacyValue) : (int) $legacyValue;
        if ($refId <= 0) {
            return [];
        }

        // 4. Build SQL — identifiers are sprintf-interpolated after whitelist check;
        //    the scalar ref value is bound as a named parameter.
        $sql = sprintf(
            'SELECT %s FROM %s WHERE %s = :ref',
            $joinForeignColumn,
            $joinTable,
            $joinLocalColumn
        );
        if (isset($options['joinOrderBy']) && is_string($options['joinOrderBy'])) {
            $joinOrderBy = $options['joinOrderBy'];
            if (preg_match('/^[A-Za-z0-9_]+$/', $joinOrderBy)) {
                $sql .= ' ORDER BY ' . $joinOrderBy;
            }
        }
        if (isset($options['maxResults']) && is_int($options['maxResults']) && $options['maxResults'] > 0) {
            // Cast to int already guaranteed by the is_int check; inline (not bound) per
            // MySQL PDO emulated-prepares LIMIT binding limitation — T-06-02-02.
            $sql .= ' LIMIT ' . (int) $options['maxResults'];
        }

        // 5. Execute via the context's LegacyDbService.
        if ($ctx->legacyDb === null) {
            throw new RuntimeException('RelationHandler: ResolverContext::$legacyDb must be non-null when joinTable is set.');
        }
        $rows = $ctx->legacyDb->queryAll($sql, [':ref' => $refId]);

        // 6. Extract foreign ids, filter <= 0.
        $foreignIds = array_map(static fn(array $r): int => (int) $r[$joinForeignColumn], $rows);
        $foreignIds = array_filter($foreignIds, static fn(int $v): bool => $v > 0);

        // 7. Resolve each foreign id to a Craft entry/asset id via the state table.
        $keyPrefix = isset($options['stateKeyPrefix']) && is_string($options['stateKeyPrefix'])
            ? $options['stateKeyPrefix']
            : '';
        // Detect asset-relation path: media state source with kuma_media: key prefix.
        // When state lookup misses (empty at transform time), emit deferred "asset:N"
        // tokens that AtomicMigrationService::ingestAndResolveAssets() will resolve at
        // load time — consistent with AssetHandler's deferred-token contract.
        $isAssetRelation = ($source === 'media' && $keyPrefix === 'kuma_media:');
        $out = [];
        foreach ($foreignIds as $id) {
            $stateKey = $keyPrefix . $id;
            $targetId = $ctx->state->getTargetId($source, $stateKey, $ctx->siteId);
            if ($targetId === null) {
                $targetId = $ctx->state->getTargetId($source, $stateKey, null);
            }
            if ($targetId !== null) {
                $out[] = $targetId;
            } elseif ($isAssetRelation) {
                // Deferred token: load-time resolver will materialise and resolve.
                $out[] = DeferredAssetToken::emit($id);
            }
        }

        // 8. Return deduped, insertion-order-preserved list.
        return array_values(array_unique($out));
    }

    /**
     * joinTranslation path.
     *
     * For the id-space gotcha where a Page FK (e.g. employee_id) points at a
     * translation table (e.g. kuma_employee_translations) whose rows hold the
     * real entity id (e.g. employee.id) that the migrator keyed state rows on.
     *
     * @param array<string, mixed> $options
     * @return array<int, int>
     */
    private function resolveViaJoinTranslation(mixed $legacyValue, ResolverContext $ctx, string $source, array $options): array
    {
        // 1. Validate joinTranslation sub-options.
        $jt = $options['joinTranslation'];
        if (
            !is_array($jt)
            || empty($jt['table']) || !is_string($jt['table'])
            || empty($jt['sourceColumn']) || !is_string($jt['sourceColumn'])
            || empty($jt['targetColumn']) || !is_string($jt['targetColumn'])
        ) {
            throw new RuntimeException(
                "RelationHandler: 'joinTranslation' must be an array with non-empty string keys "
                . "'table', 'sourceColumn', and 'targetColumn'."
            );
        }

        $table = (string) $jt['table'];
        $sourceColumn = (string) $jt['sourceColumn'];
        $targetColumn = (string) $jt['targetColumn'];

        // Identifier whitelist — T-06-02-01.
        $identifierPattern = '/^[A-Za-z0-9_]+$/';
        foreach ([$table, $sourceColumn, $targetColumn] as $identifier) {
            if (!preg_match($identifierPattern, $identifier)) {
                throw new RuntimeException(
                    "RelationHandler: joinTranslation identifier '$identifier' contains invalid characters. "
                    . "Only [A-Za-z0-9_] are allowed."
                );
            }
        }

        // 2. Normalise legacyValue to int[] (same shape as resolveDirect).
        if ($legacyValue === null || $legacyValue === '' || $legacyValue === []) {
            return [];
        }
        $ids = is_array($legacyValue) ? $legacyValue : [$legacyValue];
        $ids = array_map('intval', $ids);
        $ids = array_filter($ids, static fn(int $v): bool => $v > 0);

        if ($ctx->legacyDb === null) {
            throw new RuntimeException('RelationHandler: ResolverContext::$legacyDb must be non-null when joinTranslation is set.');
        }

        $sql = sprintf(
            'SELECT %s FROM %s WHERE %s = :id LIMIT 1',
            $targetColumn,
            $table,
            $sourceColumn
        );

        // 3. Walk each legacy translation-table id, map to the real state key.
        $out = [];
        foreach ($ids as $id) {
            // a. Look up the translation row.
            $row = $ctx->legacyDb->queryOne($sql, [':id' => $id]);
            if ($row === null) {
                continue;
            }
            // c. The targetColumn value is the real state key.
            $mappedId = (int) $row[$targetColumn];
            if ($mappedId <= 0) {
                continue;
            }
            // d. Resolve state: site-scoped first, site-agnostic fallback.
            $targetId = $ctx->state->getTargetId($source, (string) $mappedId, $ctx->siteId);
            if ($targetId === null) {
                $targetId = $ctx->state->getTargetId($source, (string) $mappedId, null);
            }
            if ($targetId !== null) {
                $out[] = $targetId;
            }
        }

        // 4. Return deduped array_values.
        return array_values(array_unique($out));
    }
}
