<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\extract;

use lameco\kunstmaanmigrator\db\LegacyDbService;
use lameco\kunstmaanmigrator\source\DetailTableResolver;
use lameco\kunstmaanmigrator\source\DoctrineEntityParser;
use lameco\kunstmaanmigrator\source\TopologicalOrderer;
use lameco\kunstmaanmigrator\source\KunstmaanCoreTables;
use lameco\kunstmaanmigrator\filter\MigrationFilters;
use Craft;
use Throwable;
use yii\base\Component;

/**
 * Stage 2a — Extract.
 *
 * Reads the Kunstmaan legacy DB (kuma_nodes + per-node detail-table + page-part refs + page-part rows),
 * groups by node_id, and writes one JSON file per node to
 * `storage/migration/extracted/<fqcn-slug>/<node-id>.json`.
 *
 * Design posture (D-01): keep the extracted payload as close to raw legacy shape as possible — the
 * Transform stage (Stage 2b) is where mapping rules + FieldHandlerRegistry are applied. Extract stays
 * read-only, deterministic, and re-runnable against the same mysqldump snapshot (D-30).
 *
 * Security (T-04-05-01 mitigation — identifier whitelist):
 *   - `sourceTable` identifiers from mapping.yaml are `preg_match('/^[a-zA-Z0-9_]+$/', $table)` guarded
 *     before any backtick-quoting into SQL. MappingValidator (plan 04-04) performs the same check at
 *     preflight, and plan 04-05 Task 3 re-verifies inside the service as defence-in-depth.
 *
 * Security (T-04-05-03 mitigation — B11 serialized-blob safety):
 *   - Every LONGTEXT/BLOB/MEDIUMBLOB column AND every column whose name matches
 *     `/^(settings|data|config|metadata|attributes)$/i` is routed through
 *     `$this->serializedDecoder->decode()` when its value carries a PHP-serialized prefix.
 *     The central decoder preserves `allowed_classes: false` so no __wakeup()/__destruct() fires.
 *
 * @see modules/lameco/CqmMigration/mapping.yaml
 */
class ExtractService extends Component
{
    public ?LegacyDbService $legacyDb = null;
    public ?DetailTableResolver $detailTableResolver = null;
    public ?TopologicalOrderer $topologicalOrderer = null;

    /**
     * Phase 8.5 / D-21 — Doctrine relation introspection for ManyToOne FK joins.
     * When non-null and `$joinFkRelations` is true, page-row + page-part-row
     * loads embed each ManyToOne target row's columns under the `_rel:<prop>.<col>`
     * synthetic-key namespace so the LLM proposer (and operator) can map the
     * cross-relation columns directly. Null parser → join is silently skipped.
     */
    public ?DoctrineEntityParser $entityParser = null;

    /**
     * Phase 8.5 / D-24 — runtime gate for relation-expanded helper columns.
     * Default false keeps extracted JSON source-faithful: raw FK columns like
     * `employee_id` remain available for mapping, without synthetic `_rel:*`
     * payloads unless operators explicitly enable the setting.
     */
    public bool $joinFkRelations = false;

    /** B11 — injected for T-04-05-03 mitigation; every serialized blob routes through here.
     *  v2 port: typed slot replaced with `?object` — the decoder dependency is deferred to Phase 4
     *  (see RECONCILIATION). When null, decode call sites fall through with the raw value. */
    public ?object $serializedDecoder = null;

    /** Root for intermediate artifacts. Defaults to Craft's `@storage/migration`. */
    public string $storagePath = '@storage/migration';

    /**
     * D-08-18 — auto-detect distinct Kunstmaan locales from the legacy DB.
     *
     * Reads `SELECT DISTINCT lang FROM kuma_node_translation` so the analyze
     * preflight can cross-reference against the mapping.yaml `sites:` block
     * and exit non-zero on orphan locales (i.e. legacy locales with no
     * corresponding Craft site mapping).
     *
     * Removes the manual hardcoding step that previously blocked reuse on
     * Simac / Enreach / Joulz — each project has a different locale set.
     *
     * @return list<string> distinct, alpha-sorted locale codes (e.g. ['en','nl'])
     */
    public function detectLocales(): array
    {
        if ($this->legacyDb === null) {
            throw new \RuntimeException('ExtractService::detectLocales: legacyDb not injected');
        }
        $rows = $this->legacyDb->queryAll(
            'SELECT DISTINCT lang FROM ' . KunstmaanCoreTables::NODE_TRANSLATIONS
            . ' WHERE lang IS NOT NULL AND lang != \'\' ORDER BY lang',
        );
        $out = [];
        foreach ($rows as $r) {
            $lang = (string) ($r['lang'] ?? '');
            if ($lang !== '') {
                $out[] = $lang;
            }
        }
        return $out;
    }

    /**
     * D-08-18 — cross-reference detected Kunstmaan locales against the
     * mapping.yaml `sites:` block (locale → Craft site handle map).
     *
     * Returns three lists:
     *   - `orphans`  : Kunstmaan locales NOT present in the sites block
     *                  (data-loss risk — these would silently drop)
     *   - `unused`   : sites-block keys NOT present in the legacy DB
     *                  (probably stale mapping; warn-level)
     *   - `detected` : the full alpha-sorted locale list from the legacy DB
     *
     * @param array<string, mixed> $sitesMap typically `$mapping['sites'] ?? []`
     * @return array{orphans: list<string>, unused: list<string>, detected: list<string>}
     */
    public function validateSitesBlock(array $sitesMap): array
    {
        $detected = $this->detectLocales();
        $mapped = array_map('strval', array_keys($sitesMap));
        $orphans = array_values(array_diff($detected, $mapped));
        $unused = array_values(array_diff($mapped, $detected));
        return [
            'orphans'  => $orphans,
            'unused'   => $unused,
            'detected' => $detected,
        ];
    }

    /**
     * Pre-count the total node rows that `run()` will iterate, given the same mapping +
     * filters. One COUNT(*) per accepted node-class table, summed. Cheap (sub-second
     * across a few dozen tables) and used by MigrateController to size the progress bar.
     *
     * @param  array<string, mixed> $mapping parsed mapping.yaml
     * @param  MigrationFilters     $filters Phase 2 / D-10 filter spec
     * @return int                          total node rows (or 0 if legacyDb missing)
     */
    public function precount(array $mapping, MigrationFilters $filters): int
    {
        if ($this->legacyDb === null) {
            return 0;
        }
        $nodeClasses = (array) ($mapping['nodeClasses'] ?? []);
        $total = 0;
        foreach ($nodeClasses as $fqcn => $spec) {
            if (!is_string($fqcn) || !is_array($spec)) {
                continue;
            }
            if (($spec['action'] ?? null) === 'SKIP') {
                continue;
            }
            if (!$filters->allows($fqcn)) {
                continue;
            }
            $sourceTable = (string) ($spec['sourceTable'] ?? '');
            if ($sourceTable === '' && $this->detailTableResolver !== null) {
                try {
                    $sourceTable = $this->detailTableResolver->resolve($fqcn);
                } catch (Throwable) {
                    continue;
                }
            }
            if ($sourceTable === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $sourceTable)) {
                continue;
            }
            try {
                $total += (int) $this->legacyDb->queryScalar('SELECT COUNT(*) FROM `' . $sourceTable . '`');
            } catch (Throwable) {
                // Pre-count is best-effort; a failed count just means the bar is approximate.
            }
        }
        return $total;
    }

    /**
     * @param  array<string, mixed> $mapping parsed mapping.yaml
     * @param  MigrationFilters     $filters Phase 2 / D-10 filter spec (entities/locales/since) — FILT-02
     * @param  array<string, mixed> $options {sites?: list<string>, onlyNodeClass?: string|null, limit?: int|null}
     * @param  (callable(int $done, int $total, string $fqcn): void)|null $onProgress
     *         Optional progress callback fired after each node row is written. `$done` is
     *         the running count, `$total` is `precount()`'s answer (or 0 if precount was
     *         skipped), `$fqcn` is the node class currently being processed. Null skips
     *         emission entirely (preserves the test path's silent behaviour).
     * @return iterable<string, mixed> report — {nodeClasses: int, nodesExtracted: int, skipped: int, warnings: list<string>}
     */
    public function run(array $mapping, MigrationFilters $filters, array $options = [], ?callable $onProgress = null): iterable
    {
        if ($this->legacyDb === null) {
            throw new \RuntimeException('ExtractService: legacyDb not injected');
        }
        // The serialized-blob decoder is deferred to Phase 4 (see RECONCILIATION).
        // When null, decodeSerializedColumns() falls through with raw values — behavioral
        // equivalence with the no-decoder path. Phase 4 reinstates the typed dependency.

        $outRoot = Craft::getAlias($this->storagePath) . '/extracted';
        if (!is_dir($outRoot) && !@mkdir($outRoot, 0775, true) && !is_dir($outRoot)) {
            throw new \RuntimeException("ExtractService: cannot create storage dir at {$outRoot}");
        }

        $report = [
            'nodeClasses'   => 0,
            'nodesExtracted' => 0,
            'promotedTargets' => 0,
            'skipped'       => 0,
            'warnings'      => [],
        ];

        // Pre-count for the progress callback when one is wired. Cheap (one COUNT(*)
        // per included node-class table). Skipped when no callback is registered to
        // keep the no-progress path zero-overhead.
        $precountTotal = $onProgress !== null ? $this->precount($mapping, $filters) : 0;

        $nodeClasses = $mapping['nodeClasses'] ?? [];
        if (!is_array($nodeClasses)) {
            return $report;
        }

        $limit = isset($options['limit']) ? (int) $options['limit'] : null;
        $onlyFqcns = null;
        if (isset($options['fqcns']) && is_array($options['fqcns'])) {
            $onlyFqcns = array_map('strval', $options['fqcns']);
        } elseif (isset($options['onlyNodeClass'])) {
            $onlyFqcns = [(string) $options['onlyNodeClass']];
        }
        // Phase 7 — single-id debug filter. When set, only the legacy node
        // row whose `kuma_nodes.id` matches gets extracted. Designed for
        // fast-iteration debug; combine with --entities for an unambiguous
        // single-entry target.
        $onlyId = isset($options['onlyId']) ? (int) $options['onlyId'] : null;

        foreach ($nodeClasses as $fqcn => $spec) {
            if ($onlyFqcns !== null && !in_array($fqcn, $onlyFqcns, true)) {
                continue;
            }
            if (!is_string($fqcn) || !is_array($spec)) {
                continue;
            }

            // Phase 9 / D-16: use the source-domain reachability set so scoped
            // Page runs include graph-reachable page-owned dependencies instead
            // of re-parsing basename filters at this stage.
            if (!$filters->allows($fqcn)) {
                continue;
            }

            // Honor SKIP rows — mapping.yaml can exclude whole node classes from extraction.
            if (($spec['action'] ?? null) === 'SKIP') {
                $report['skipped']++;
                $report['warnings'][] = "SKIP nodeClass {$fqcn}: " . ((string) ($spec['reason'] ?? 'no reason given'));
                continue;
            }

            $report['nodeClasses']++;

            $sourceTable = (string) ($spec['sourceTable'] ?? '');
            if ($sourceTable === '' && $this->detailTableResolver !== null) {
                try {
                    $sourceTable = $this->detailTableResolver->resolve($fqcn);
                } catch (Throwable $e) {
                    $report['warnings'][] = "Cannot resolve detail table for {$fqcn}: " . $e->getMessage();
                    continue;
                }
            }

            // T-04-05-01 — identifier whitelist before any backtick-quoting into SQL.
            if ($sourceTable !== '' && !preg_match('/^[a-zA-Z0-9_]+$/', $sourceTable)) {
                throw new \RuntimeException(
                    "Invalid sourceTable identifier for {$fqcn}: {$sourceTable}",
                );
            }

            $outDir = $outRoot . '/' . $this->fqcnSlug($fqcn);
            if (!is_dir($outDir) && !@mkdir($outDir, 0775, true) && !is_dir($outDir)) {
                throw new \RuntimeException("ExtractService: cannot create output dir {$outDir}");
            }

            // Stream one row per node for this FQCN. Per-locale ref_ids are resolved via
            // translationsFor() below — different locales point to different entity rows
            // (e.g. Karlijn NL is at employee_pages.id=28, EN at a different id).
            $extracted = 0;
            foreach ($this->legacyDb->streamLiveNodes($fqcn) as $nodeRow) {
                if ($limit !== null && $extracted >= $limit) {
                    break;
                }

                $nodeId = (int) ($nodeRow['id'] ?? 0);
                if ($nodeId <= 0) {
                    continue;
                }
                // Phase 7 — single-id debug filter applied at the streamed-row site.
                if ($onlyId !== null && $nodeId !== $onlyId) {
                    continue;
                }

                // Phase 2 / D-10 filter piping per FILT-02 — added in v2 port (not in v1).
                // since predicate applied as post-fetch filter (SQL-level predicate would
                // require modifying LegacyDbService::streamLiveNodes; out of scope here).
                // Skip node rows whose updated_at is older than the date floor.
                if ($filters->since !== null) {
                    $nodeUpdated = (string) ($nodeRow['updated_at'] ?? $nodeRow['updated'] ?? '');
                    if ($nodeUpdated !== '' && strcmp(substr($nodeUpdated, 0, 10), $filters->since) < 0) {
                        continue;
                    }
                }

                $parentId = isset($nodeRow['parent_id']) ? (int) $nodeRow['parent_id'] : null;

                $translations = $this->legacyDb->translationsFor($nodeId);
                $perSite = [];
                /** @var array<string, int> $refIdsByLocale */
                $refIdsByLocale = [];
                foreach ($translations as $t) {
                    $lang = (string) ($t['lang'] ?? '');
                    if ($lang === '') {
                        continue;
                    }

                    // Phase 2 / D-10 filter piping per FILT-02 — added in v2 port (not in v1).
                    // locales subset applied as post-fetch filter (kuma_node_translations
                    // JOIN is encapsulated in LegacyDbService::translationsFor; out of scope
                    // to push a `lang IN (...)` predicate down here).
                    if ($filters->locales !== [] && !in_array($lang, $filters->locales, true)) {
                        continue;
                    }

                    $perLocaleRefId = (int) ($t['ref_id'] ?? 0);
                    if ($perLocaleRefId === 0) {
                        // Translation row without a public version (draft-only, or a
                        // deleted version); skip — we can't load its detail row.
                        continue;
                    }

                    $detail = $sourceTable !== ''
                        ? $this->loadDetailRow($sourceTable, $perLocaleRefId, $fqcn)
                        : null;
                    $pageParts = $this->loadPageParts($perLocaleRefId, $fqcn);

                    $perSite[$lang] = [
                        'online'     => (bool) ($t['online'] ?? false),
                        'title'      => (string) ($t['title'] ?? ''),
                        'slug'       => (string) ($t['slug'] ?? ''),
                        'url'        => (string) ($t['url'] ?? ''),
                        'refId'      => $perLocaleRefId,
                        'detail'     => $detail,
                        'pageParts'  => $pageParts,
                    ];
                    $refIdsByLocale[$lang] = $perLocaleRefId;
                }

                // Canonical refId for kunstmaanSourceId: prefer NL (CQM is NL-primary
                // per Phase 5 D-03); fall back to the first available locale.
                $refId = $refIdsByLocale['nl']
                    ?? (int) (reset($refIdsByLocale) ?: 0);
                if ($refId === 0) {
                    // No viable public version for any locale — skip this node.
                    continue;
                }

                $payload = [
                    'kunstmaanSourceId' => $this->kunstmaanSourceId($fqcn, $refId),
                    'fqcn'              => $fqcn,
                    'kuma_node_id'      => $nodeId,
                    'kuma_parent_id'    => $parentId,
                    'ref_id'            => $refId,
                    'refIdsByLocale'    => $refIdsByLocale,
                    'sourceTable'       => $sourceTable,
                    'perSite'           => $perSite,
                ];

                $outFile = $outDir . '/' . $nodeId . '.json';
                $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if ($json === false) {
                    $report['warnings'][] = "json_encode failed for {$fqcn} node={$nodeId}";
                    continue;
                }

                if (file_put_contents($outFile, $json) === false) {
                    $report['warnings'][] = "file_put_contents failed for {$outFile}";
                    continue;
                }

                $report['nodesExtracted']++;
                $extracted++;

                if ($onProgress !== null) {
                    $onProgress($report['nodesExtracted'], $precountTotal, $fqcn);
                }
            }
        }

        $this->extractPromotedTargets($mapping, $outRoot, $report, $limit);

        return $report;
    }

    /**
     * Extract promoted/shared relation targets as standalone source records.
     *
     * @param array<string, mixed> $mapping
     * @param array<string, mixed> $report
     */
    private function extractPromotedTargets(array $mapping, string $outRoot, array &$report, ?int $limit): void
    {
        $targets = (array) ($mapping['promotedTargets'] ?? []);
        if ($targets === []) {
            return;
        }

        foreach ($targets as $key => $spec) {
            if (!is_array($spec)) {
                continue;
            }
            $stateSource = (string) ($spec['stateSource'] ?? $key);
            $sourceFqcn = $this->sourceFqcnFromRef((string) ($spec['sourceRef'] ?? ''));
            if ($stateSource === '' || $sourceFqcn === '') {
                $report['warnings'][] = 'promoted target skipped: missing stateSource/sourceRef';
                continue;
            }

            $sourceTable = (string) ($spec['sourceTable'] ?? '');
            if ($sourceTable === '' && $this->entityParser !== null) {
                $sourceTable = (string) ($this->entityParser->getByFqcn($sourceFqcn)?->tableName ?? '');
            }
            if ($sourceTable === '' && $this->detailTableResolver !== null) {
                try {
                    $sourceTable = $this->detailTableResolver->resolve($sourceFqcn);
                } catch (Throwable $e) {
                    $report['warnings'][] = "Cannot resolve promoted target table for {$sourceFqcn}: " . $e->getMessage();
                    continue;
                }
            }
            if ($sourceTable === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $sourceTable)) {
                $report['warnings'][] = "promoted target {$stateSource} skipped: invalid sourceTable";
                continue;
            }

            $outDir = $outRoot . '/promoted_' . $this->fqcnSlug($stateSource);
            if (!is_dir($outDir) && !@mkdir($outDir, 0775, true) && !is_dir($outDir)) {
                throw new \RuntimeException("ExtractService: cannot create output dir {$outDir}");
            }

            $extracted = 0;
            foreach ($this->legacyDb->streamQuery("SELECT * FROM `{$sourceTable}` ORDER BY id") as $row) {
                if ($limit !== null && $extracted >= $limit) {
                    break;
                }
                if (!is_array($row)) {
                    continue;
                }
                $id = (int) ($row['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $detail = $this->decodeSerializedColumns($row);
                $perSite = $this->promotedPerSite($sourceFqcn, $id, $detail, (array) ($mapping['sites'] ?? []));
                $payload = [
                    'kind' => 'promotedTarget',
                    'promotedTarget' => $spec,
                    'kunstmaanSourceId' => $stateSource . ':' . $id,
                    'fqcn' => $sourceFqcn,
                    'stateSource' => $stateSource,
                    'stateKey' => $id,
                    'kuma_node_id' => $id,
                    'kuma_parent_id' => null,
                    'ref_id' => $id,
                    'refIdsByLocale' => array_fill_keys(array_keys($perSite), $id),
                    'sourceTable' => $sourceTable,
                    'perSite' => $perSite,
                ];

                $outFile = $outDir . '/' . $id . '.json';
                $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if ($json === false || file_put_contents($outFile, $json) === false) {
                    $report['warnings'][] = "promoted target write failed for {$stateSource}:{$id}";
                    continue;
                }

                $report['promotedTargets']++;
                $report['nodesExtracted']++;
                $extracted++;
            }
        }
    }

    /** @param array<string, mixed> $detail @param array<string, string> $sites */
    private function promotedPerSite(string $sourceFqcn, int $id, array $detail, array $sites): array
    {
        $locales = array_keys($sites);
        if ($locales === []) {
            $locales = ['default'];
        }
        $translations = $this->legacyDb->extTranslationsFor($sourceFqcn, $id);
        $out = [];
        foreach ($locales as $locale) {
            $locale = (string) $locale;
            $localized = $detail;
            foreach ((array) ($translations[$locale] ?? []) as $field => $content) {
                $localized[(string) $field] = $content;
            }
            $title = $this->promotedTitle($localized, $id);
            $out[$locale] = [
                'online' => true,
                'title' => $title,
                'slug' => $this->slugifyText($title),
                'url' => '',
                'refId' => $id,
                'detail' => $localized,
                'pageParts' => [],
            ];
        }
        return $out;
    }

    /** @param array<string, mixed> $detail */
    private function promotedTitle(array $detail, int $id): string
    {
        foreach (['title', 'name', 'label'] as $key) {
            $value = trim((string) ($detail[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        return 'Legacy item ' . $id;
    }

    private function slugifyText(string $value): string
    {
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $value) ?? '', '-'));
        return $slug !== '' ? $slug : 'legacy-item';
    }

    private function sourceFqcnFromRef(string $sourceRef): string
    {
        foreach (['kunstmaan.entity:', 'kunstmaan.page:'] as $prefix) {
            if (str_starts_with($sourceRef, $prefix)) {
                return substr($sourceRef, strlen($prefix));
            }
        }
        return '';
    }

    /**
     * Load the detail-table row for a node, apply B11 serialized-blob safety,
     * and auto-inline any FK-related rows from non-media app tables.
     *
     * FK discovery queries information_schema.KEY_COLUMN_USAGE for the given
     * table. For each FK that points to a non-system table (not kuma_* prefix),
     * the referenced row is fetched and its columns are merged into the detail
     * dict under a "<referencedTable>.<col>" key (using the bare table name as
     * alias). This means `employee_pages.employee_id → employees` automatically
     * produces `employee.title`, `employee.image_id`, etc. in the detail row —
     * no explicit `joins:` entry in mapping.yaml required.
     *
     * kuma_media references are skipped here (they are handled by AssetHandler
     * at transform time via the state table).
     *
     * @param  string $entityFqcn  Phase 8.5 / D-21 — owning entity FQCN; used by
     *                              `joinManyToOneRelations()` to resolve Doctrine
     *                              relation metadata. Empty string preserves the
     *                              pre-8.5 behaviour (no `_rel:*` keys).
     * @return array<string, mixed>|null
     */
    private function loadDetailRow(string $table, int $refId, string $entityFqcn = ''): ?array
    {
        // Defence-in-depth: repeat the identifier-whitelist check so this method is also safe when
        // called directly from unit tests or future integrations.
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            throw new \RuntimeException("loadDetailRow: invalid table identifier {$table}");
        }

        $row = $this->legacyDb->queryOne(
            "SELECT * FROM `{$table}` WHERE id = :id LIMIT 1",
            [':id' => $refId],
        );
        if ($row === null) {
            return null;
        }

        $detail = $this->decodeSerializedColumns($row);

        // Phase 8.5 / D-21 — Doctrine ManyToOne join. Embeds each related row's
        // columns under `_rel:<prop>.<col>` so the LLM (and operator) can map
        // cross-relation fields without an additional SQL hop. Runs BEFORE the
        // legacy information_schema-based FK auto-discovery so the
        // source-truth (Doctrine attributes) wins on key ordering.
        if ($entityFqcn !== '') {
            $detail = $this->joinManyToOneRelations($entityFqcn, $detail);
        }

        // Auto-follow FK relations to non-system, non-media tables.
        // This is governed by the same join flag as Doctrine `_rel:*` expansion:
        // operators using --no-rel-join expect extracted JSON to keep raw FK IDs
        // only. When Doctrine already joined a relation, do not also emit the
        // legacy information_schema alias for the same FK.
        $doctrineFkProperties = $entityFqcn !== ''
            ? $this->doctrineManyToOneFkProperties($entityFqcn)
            : [];
        foreach ($this->joinFkRelations ? $this->discoverFkRelations($table) : [] as $fkCol => [$refTable, $refPk]) {
            $fkValue = $detail[$fkCol] ?? null;
            if ($fkValue === null || $fkValue === '' || $fkValue === 0 || $fkValue === '0') {
                continue;
            }
            $doctrineProperty = $doctrineFkProperties[$fkCol] ?? null;
            if ($doctrineProperty !== null && $this->hasJoinedRelationPrefix($detail, $doctrineProperty)) {
                continue;
            }
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $refTable)) {
                continue;
            }
            $refRow = $this->legacyDb->queryOne(
                "SELECT * FROM `{$refTable}` WHERE `{$refPk}` = :id LIMIT 1",
                [':id' => (int) $fkValue],
            );
            if ($refRow === null) {
                continue;
            }
            $refRow = $this->decodeSerializedColumns($refRow);
            // Derive a short alias from the referenced table name: strip common
            // bundle prefixes so "lameco_websitebundle_employee_employees" → "employee_employees",
            // then use the last underscore-segment as the alias for brevity.
            $alias = $this->tableAlias($refTable);
            foreach ($refRow as $col => $val) {
                // Only add if the key doesn't already exist in the detail row.
                $detail["{$alias}.{$col}"] ??= $val;
            }
        }

        return $detail;
    }

    /**
     * Phase 8.5 / D-21 — embed every ManyToOne related row's columns under
     * `_rel:<property>.<column>` keys.
     *
     * Reads the owning entity's Doctrine relation list via
     * `DoctrineEntityParser->getByFqcn()`. For each ManyToOne with a non-null
     * FK column AND a non-null FK value in the input row, the target table is
     * queried by primary key and every column of the result is merged into the
     * row under the `_rel:<prop>.` namespace.
     *
     * Why a `_rel:` prefix:
     *   - `_` already signals "synthetic" elsewhere (e.g. `__implicit_content__`
     *     pageparts, `_sourcePartRef` in transform output).
     *   - `:` cannot appear in a Doctrine column name, so the prefix is collision-free.
     *   - Property name (not target FQCN, not target table alias) gives operator-
     *     friendly identity that matches the KB markdown's Relations table.
     *
     * Failures (parser missing, target not parsed, target table invalid, FK row
     * not found, query error) are silently swallowed — extract is best-effort.
     * The FK column itself stays in the row so operators can still map
     * `employee_id → relation field` directly if they prefer.
     *
     * ManyToMany / OneToMany are out of scope for v1 (different join shape).
     *
     * @param  array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function joinManyToOneRelations(string $entityFqcn, array $row): array
    {
        if (!$this->joinFkRelations || $this->entityParser === null) {
            return $row;
        }
        $info = $this->entityParser->getByFqcn($entityFqcn);
        if ($info === null) {
            return $row;
        }
        foreach ($info->relations as $rel) {
            if ($rel->relationType !== 'ManyToOne') {
                continue;
            }
            $fkCol = $rel->fkColumn;
            if ($fkCol === null || $fkCol === '' || !array_key_exists($fkCol, $row)) {
                continue;
            }
            $fkValue = $row[$fkCol];
            if ($fkValue === null || $fkValue === '' || $fkValue === 0 || $fkValue === '0') {
                continue;
            }
            $targetInfo = $this->entityParser->getByFqcn($rel->targetEntity);
            if ($targetInfo === null) {
                continue;
            }
            $targetTable = $targetInfo->tableName;
            if ($targetTable === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $targetTable)) {
                continue;
            }
            try {
                $relRow = $this->legacyDb->queryOne(
                    "SELECT * FROM `{$targetTable}` WHERE id = :id LIMIT 1",
                    [':id' => (int) $fkValue],
                );
            } catch (Throwable $e) {
                Craft::warning(
                    sprintf(
                        'ExtractService: joinManyToOneRelations skipped %s.%s (target=%s id=%s): %s',
                        $entityFqcn,
                        $rel->propertyName,
                        $targetTable,
                        (string) $fkValue,
                        $e->getMessage(),
                    ),
                    __METHOD__,
                );
                continue;
            }
            if ($relRow === null) {
                continue;
            }
            $relRow = $this->decodeSerializedColumns($relRow);
            $prefix = '_rel:' . $rel->propertyName . '.';
            foreach ($relRow as $col => $val) {
                $key = $prefix . $col;
                if (!array_key_exists($key, $row)) {
                    $row[$key] = $val;
                }
            }
        }
        return $row;
    }

    /**
     * Query information_schema to discover FK constraints on a given table.
     *
     * Returns a map of localColumn → [referencedTable, referencedColumn] for
     * every FK that points to an application-level table (non-kuma_* prefix).
     * kuma_media references are excluded — those are handled by AssetHandler.
     *
     * Results are cached per table for the lifetime of this service instance.
     *
     * @return array<string, array{0: string, 1: string}>  fkColumn → [refTable, refPk]
     */
    private function discoverFkRelations(string $table): array
    {
        static $cache = [];
        if (isset($cache[$table])) {
            return $cache[$table];
        }

        $dbName = $this->legacyDb->getDatabaseName();
        if ($dbName === null || $dbName === '') {
            return $cache[$table] = [];
        }

        $rows = $this->legacyDb->queryAll(
            'SELECT COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME'
            . ' FROM information_schema.KEY_COLUMN_USAGE'
            . ' WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :tbl'
            . '   AND REFERENCED_TABLE_NAME IS NOT NULL',
            [':db' => $dbName, ':tbl' => $table],
        );

        $result = [];
        foreach ($rows as $r) {
            $col     = (string) ($r['COLUMN_NAME'] ?? '');
            $refTbl  = (string) ($r['REFERENCED_TABLE_NAME'] ?? '');
            $refCol  = (string) ($r['REFERENCED_COLUMN_NAME'] ?? '');
            if ($col === '' || $refTbl === '' || $refCol === '') {
                continue;
            }
            // Skip kuma_media — handled by AssetHandler. Skip kuma_* system tables.
            if (str_starts_with($refTbl, 'kuma_')) {
                continue;
            }
            $result[$col] = [$refTbl, $refCol];
        }

        return $cache[$table] = $result;
    }

    /**
     * Derive a short, stable alias from a full table name.
     *
     * Strips the longest matching bundle prefix and returns the meaningful suffix.
     * Examples:
     *   lameco_websitebundle_employee_employees  → employee_employees
     *   lameco_websitebundle_case_study_pages    → case_study_pages
     *   some_vendor_bundle_items                 → items
     */
    private function tableAlias(string $table): string
    {
        // Strip known bundle prefixes (longest first).
        foreach (['lameco_websitebundle_', 'lameco_', 'kunstmaan_'] as $prefix) {
            if (str_starts_with($table, $prefix)) {
                $table = substr($table, strlen($prefix));
                break;
            }
        }
        return $table;
    }

    /**
     * Load ALL page-part rows for a given page, across every context slot.
     *
     * Previously filtered to a single context derived from the locale code ($lang),
     * which silently dropped all content because Kunstmaan context values are named
     * zones ("main", "sidebar", "home", …) — never ISO locale codes. This fix removes
     * the context filter so every context is extracted. The `context` key in each
     * returned item lets the Transform stage pick the right slot per mapping rule.
     *
     * @return list<array<string, mixed>>
     */
    private function loadPageParts(int $refId, string $pageClass): array
    {
        $refs = $this->legacyDb->queryAll(
            'SELECT context, sequencenumber, page_part_id, page_part_entityname'
            . ' FROM ' . KunstmaanCoreTables::PAGE_PART_REFS
            . ' WHERE pageId = :pid AND pageEntityname = :class'
            . ' ORDER BY context, sequencenumber',
            [':pid' => $refId, ':class' => $pageClass],
        );

        $out = [];
        foreach ($refs as $ref) {
            $partFqcn = (string) ($ref['page_part_entityname'] ?? '');
            $partId = (int) ($ref['page_part_id'] ?? 0);
            if ($partFqcn === '' || $partId <= 0) {
                continue;
            }

            $partTable = null;
            if ($this->detailTableResolver !== null) {
                try {
                    $partTable = $this->detailTableResolver->resolve($partFqcn);
                } catch (Throwable) {
                    // Skip parts whose table we can't resolve — Transform will log the absence.
                    continue;
                }
            }
            if ($partTable === null || $partTable === '') {
                continue;
            }

            // T-04-05-01 defence-in-depth.
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $partTable)) {
                throw new \RuntimeException("loadPageParts: invalid part-table identifier {$partTable}");
            }

            $partRow = $this->legacyDb->queryOne(
                "SELECT * FROM `{$partTable}` WHERE id = :id LIMIT 1",
                [':id' => $partId],
            );
            if ($partRow === null) {
                continue;
            }

            $partRowDecoded = $this->decodeSerializedColumns($partRow);
            // Phase 8.5 / D-21 — embed ManyToOne relation columns under
            // `_rel:<prop>.<col>` so cross-relation pagepart fields (e.g. a
            // pagepart wrapping an Author entity) become first-class
            // candidates for the LLM proposer + operator mapping pass.
            $partRowDecoded = $this->joinManyToOneRelations($partFqcn, $partRowDecoded);

            $out[] = [
                'fqcn'          => $partFqcn,
                'sourcePartId'  => $partId,
                'sequence'      => (int) ($ref['sequencenumber'] ?? 0),
                'context'       => (string) ($ref['context'] ?? ''),
                'row'           => $partRowDecoded,
            ];
        }

        return $out;
    }

    /**
     * B11 — route Doctrine-serialized blob columns through the central decoder.
     *
     * Covers two signals:
     *   (a) value-prefix fingerprint: `/^[aOisbdN]:\d*[:;]/` matches a,O,s,i,b,d,N serialized payloads
     *   (b) column-name whitelist:   `/^(settings|data|config|metadata|attributes)$/i`
     *
     * Any column matching EITHER signal whose decode produces an array is replaced with the decoded
     * array. Non-serialized or failed decodes are left untouched.
     *
     * v2 port: when `$this->serializedDecoder` is null (Phase 4 follow-up), the decode call is skipped
     * and the raw value is preserved — behavioral equivalence with the no-decoder path.
     *
     * @param  array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function decodeSerializedColumns(array $row): array
    {
        foreach ($row as $col => $val) {
            if (!is_string($val) || $val === '') {
                continue;
            }

            $looksSerialized = (bool) preg_match('/^[aOisbdN]:\d*[:;]/', $val);
            $nameMatches = (bool) preg_match('/^(settings|data|config|metadata|attributes)$/i', (string) $col);

            if ($looksSerialized || $nameMatches) {
                if ($this->serializedDecoder !== null) {
                    /** @var array<string, mixed> $decoded */
                    $decoded = $this->serializedDecoder->decode($val);
                    if ($decoded !== []) {
                        $row[$col] = $decoded;
                    }
                }
                // If decoder is null (Phase 4 deferral), fall through with the raw value.
            }
        }
        return $row;
    }

    /**
     * @return array<string, string> FK column => Doctrine property name
     */
    private function doctrineManyToOneFkProperties(string $entityFqcn): array
    {
        if ($this->entityParser === null) {
            return [];
        }
        $info = $this->entityParser->getByFqcn($entityFqcn);
        if ($info === null) {
            return [];
        }

        $out = [];
        foreach ($info->relations as $rel) {
            if ($rel->relationType !== 'ManyToOne' || $rel->fkColumn === null || $rel->fkColumn === '') {
                continue;
            }
            $out[$rel->fkColumn] = $rel->propertyName;
        }

        return $out;
    }

    /** @param array<string, mixed> $detail */
    private function hasJoinedRelationPrefix(array $detail, string $propertyName): bool
    {
        $prefix = '_rel:' . $propertyName . '.';
        foreach ($detail as $key => $_value) {
            if (is_string($key) && str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Phase 7 — synthesize page-part records for content-only pages.
     *
     * When MappingCompiler emitted `mapping.pageParts['__implicit_content__|<short>|<context>']`
     * entries for the current FQCN's short name, this method produces a synthetic
     * pagePart record per (key, detail) pair. The synthetic record carries the page's
     * own detail row as its `row`, which is the only legal source for content-like
     * columns on content-only pages (no real page-part rows exist by definition).
     *
     * Sequence numbers start at 1_000_000 so synthetic blocks always sort AFTER real
     * page-parts when both are present (defence-in-depth — the "no real page-parts"
     * predicate at compile time should already preclude that case).
     *
     * @param  array<string, mixed> $detail   page detail row
     * @param  array<string, mixed> $mapping  full parsed mapping.yaml
     * @return list<array<string, mixed>>     synthetic pagePart records
     */
    public static function buildImplicitContentPageParts(string $fqcn, array $detail, array $mapping): array
    {
        $pageParts = (array) ($mapping['pageParts'] ?? []);
        if ($pageParts === []) {
            return [];
        }
        $parts = explode('\\', trim($fqcn, '\\'));
        $short = (string) end($parts);
        if ($short === '') {
            return [];
        }
        $prefix = '__implicit_content__|' . $short . '|';
        $detailId = (int) ($detail['id'] ?? 0);
        $sequence = 1_000_000;
        $out = [];
        foreach ($pageParts as $key => $spec) {
            if (!is_string($key) || !is_array($spec)) { continue; }
            if (!str_starts_with($key, $prefix)) { continue; }
            $context = (string) substr($key, strlen($prefix));
            if ($context === '') { continue; }
            $out[] = [
                'fqcn'         => $key,
                'sourcePartId' => $detailId !== 0 ? $detailId : $sequence,
                'sequence'     => $sequence,
                'context'      => $context,
                'row'          => $detail,
            ];
            $sequence++;
        }
        return $out;
    }

    /**
     * Produce a filesystem-safe slug from an FQCN.
     *   "App\Entity\Pages\SomePage" -> "App_Entity_Pages_SomePage"
     */
    private function fqcnSlug(string $fqcn): string
    {
        $slug = str_replace('\\', '_', trim($fqcn, '\\'));
        return preg_replace('/[^A-Za-z0-9_\-]+/', '_', $slug) ?? 'unknown';
    }

    /**
     * Upsert identifier consumed by Load stage (D-09). Format: "<fqcn-slug>:<legacyId>".
     */
    private function kunstmaanSourceId(string $fqcn, int $refId): string
    {
        return $this->fqcnSlug($fqcn) . ':' . $refId;
    }
}
