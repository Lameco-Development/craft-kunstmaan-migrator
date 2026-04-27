<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\source;

use Craft;
use lameco\kunstmaanmigrator\db\LegacyDbService;
use Throwable;
use yii\base\Component;

/**
 * KunstmaanSourceScanner — D-40 left-side orchestrator.
 *
 * Composes the seven Phase 02.1 source-namespace classes
 * (DoctrineEntityParser + DetailTableResolver + BodyScanColumnFinder +
 * MediaFkScanner + KnowledgeBase via Plugin wiring + KunstmaanPageStructureScanner
 * sibling — the page-structure scanner is its own orchestrator entry point and
 * is not invoked here) into a single structured `scan()` accessor that
 * AnalyzeController step 3 (D-42) consumes.
 *
 * Output shape (per CONTEXT D-40):
 *   [
 *     'tables'    => list<string>,                        // discovered Doctrine + JoinTable names
 *     'entities'  => array<string, DoctrineEntityInfo>,   // FQCN-keyed entity index
 *     'm2mJoins'  => list<array{table, owning, inverse}>, // M2M end-to-end resolution
 *     'bodyCols'  => array<string, list<string>>,         // table → list of body cols
 *     'mediaFks'  => list<array{table, column}>,          // kuma_media FK columns
 *     'drift'     => [
 *       'dbHasButScanMissing' => list<string>,             // (i) DB has table not in scan
 *       'scanHasButDbMissing' => list<string>,             // (ii) entity declared but never persisted
 *     ],
 *   ]
 *
 * D-32 drift detection: compares the scan's discovered table set against the
 * live DB's information_schema.TABLES list. WARN-by-default, `--source-strict`
 * elevates (consumer-side; this scanner only emits findings). Findings are
 * emitted to MAPPING-AUDIT.md sibling section by Plan 8.
 *
 * D-40 M2M end-to-end resolution: walks every entity's relations; for each
 * ManyToMany with an explicit JoinTable, the owning side's `joinTableName` is
 * surfaced and the target FQCN is recorded. Inverse-side relations (with
 * `mappedBy`) cross-reference back to the owning side. Both target classes'
 * tables are discovered via the union with entity tableNames.
 *
 * Threat model (T-02.1-05-01 mitigation): all information_schema queries use
 * bound PDO parameters (`:db`); never string-concatenate operator-supplied DB
 * names into SQL literals. T-02.1-05-02: scan output contains structured
 * table-name + column-name metadata only (no row data — samples live in
 * KnowledgeBase output, scoped by SAMPLE_MAX_LEN).
 */
final class KunstmaanSourceScanner extends Component
{
    /**
     * Resolved Kunstmaan source path provider (Plan 01 / D-33). Returns the
     * validated absolute realpath or null on failure; this scanner treats null
     * as a "fail closed" signal and emits an empty result shape.
     */
    public ?KunstmaanSourcePathResolver $pathResolver = null;

    /**
     * Doctrine entity parser (Plan 02). Warmed once per scan() call by feeding
     * it the resolved source path; getAll() returns the FQCN-keyed entity map.
     */
    public ?DoctrineEntityParser $entityParser = null;

    /**
     * FQCN → table resolver (Plan 02). Held for symmetry / downstream callers
     * even though scan() does not currently call into it directly — the
     * resolver shares the same DoctrineEntityParser lookup, so warming it here
     * keeps the cache hot for AnalyzeController's later steps.
     */
    public ?DetailTableResolver $tableResolver = null;

    /** Body-text column finder (Plan 03). Returns array<string, list<string>>. */
    public ?BodyScanColumnFinder $bodyFinder = null;

    /** kuma_media FK scanner (Plan 03). Returns list<array{table, column}>. */
    public ?MediaFkScanner $mediaScanner = null;

    /** Legacy DB accessor for the drift information_schema query. */
    public ?LegacyDbService $legacyDb = null;

    /**
     * Per-request cache for the structured scan output. Populated on first
     * call to scan(); subsequent calls return the same array (the underlying
     * parsers + DB queries are themselves idempotent but we avoid re-walking
     * the entity directory and re-querying information_schema).
     *
     * @var array{
     *   tables: list<string>,
     *   entities: array<string, \lameco\kunstmaanmigrator\source\DoctrineEntityInfo>,
     *   m2mJoins: list<array{table: string, owning: string, inverse: ?string}>,
     *   bodyCols: array<string, list<string>>,
     *   mediaFks: list<array{table: string, column: string}>,
     *   drift: array{dbHasButScanMissing: list<string>, scanHasButDbMissing: list<string>},
     * }|null
     */
    private ?array $cache = null;

    /**
     * Run the full source scan and return the structured output.
     *
     * @return array{
     *   tables: list<string>,
     *   entities: array<string, \lameco\kunstmaanmigrator\source\DoctrineEntityInfo>,
     *   m2mJoins: list<array{table: string, owning: string, inverse: ?string}>,
     *   bodyCols: array<string, list<string>>,
     *   mediaFks: list<array{table: string, column: string}>,
     *   drift: array{dbHasButScanMissing: list<string>, scanHasButDbMissing: list<string>},
     * }
     */
    public function scan(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        // 1. Resolve source path. Caller (AnalyzeController) is gated by D-31
        //    already, but defensively emit an empty shape if anything is wrong.
        $path = $this->pathResolver?->resolve();
        if ($path === null || $this->entityParser === null) {
            return $this->cache = $this->emptyResult();
        }

        // 2. Warm the entity parser against the resolved source path. Plan 02's
        //    parser is lazy-warmed on first getAll() / getByFqcn() / getByTable()
        //    call; we set sourceCheckoutPath here so later resolver calls share
        //    the same warmed cache.
        $this->entityParser->sourceCheckoutPath = $path;
        if ($this->tableResolver !== null && $this->tableResolver->entityParser === null) {
            $this->tableResolver->entityParser = $this->entityParser;
        }
        $entities = $this->entityParser->getAll();

        // 3. Compute discovered tables: union of every entity's tableName +
        //    every ManyToMany joinTableName. JoinTable names are extracted by
        //    re-reading the source files (the v1 DoctrineRelationInfo VO does
        //    not carry joinTableName separately — we surface them via the
        //    helper below, which mirrors the parser's regex approach).
        $tables = [];
        foreach ($entities as $info) {
            if ($info->tableName !== '') {
                $tables[$info->tableName] = true;
            }
        }

        // 4. M2M end-to-end resolution + JoinTable name discovery.
        $m2mJoins = $this->collectM2mJoins($path, $entities);
        foreach ($m2mJoins as $join) {
            if ($join['table'] !== '') {
                $tables[$join['table']] = true;
            }
        }

        $tableList = array_keys($tables);
        sort($tableList);

        // 5. Body-text columns (Plan 03 — DB-backed scan).
        $bodyCols = $this->bodyFinder !== null ? $this->bodyFinder->scan() : [];

        // 6. kuma_media FK columns (Plan 03 — DB-backed scan).
        $mediaFks = $this->mediaScanner !== null ? $this->mediaScanner->scan() : [];

        // 7. Drift detection (D-32) — compare scan tables against live DB.
        $drift = $this->detectDrift($tableList);

        return $this->cache = [
            'tables'   => $tableList,
            'entities' => $entities,
            'm2mJoins' => $m2mJoins,
            'bodyCols' => $bodyCols,
            'mediaFks' => $mediaFks,
            'drift'    => $drift,
        ];
    }

    // -------------------------------------------------------------------------
    // M2M discovery
    // -------------------------------------------------------------------------

    /**
     * Walk every entity file and surface ManyToMany joins.
     *
     * For each #[ORM\ManyToMany(...)] paired with #[ORM\JoinTable(name: '...')]
     * on the owning side, emit an entry with:
     *   - table   = the join table name
     *   - owning  = the owning entity FQCN (the file the JoinTable was found in)
     *   - inverse = the targetEntity FQCN (the inverse side / target class)
     *
     * Inverse-side relations (with `mappedBy`) cross-reference back to the
     * owning side: their target FQCN is the owning class. The DoctrineRelationInfo
     * VO already encodes targetEntity for both sides, so the M2M map is
     * symmetrical from the consumer's perspective.
     *
     * @param array<string, \lameco\kunstmaanmigrator\source\DoctrineEntityInfo> $entities
     * @return list<array{table: string, owning: string, inverse: ?string}>
     */
    private function collectM2mJoins(string $sourcePath, array $entities): array
    {
        $joins = [];
        $entityDir = rtrim($sourcePath, '/') . '/src/Entity';
        if (!is_dir($entityDir)) {
            return $joins;
        }

        // Build FQCN → file path map by walking the source tree once. The
        // parser already did this internally but did not expose the mapping;
        // we reconstruct it cheaply here via the namespace + class regex.
        $fileByFqcn = [];
        try {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($entityDir, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($it as $file) {
                /** @var \SplFileInfo $file */
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $real = (string) $file->getRealPath();
                $src = @file_get_contents($real);
                if ($src === false || $src === '') {
                    continue;
                }
                if (!preg_match('/^namespace\s+([\w\\\\]+)\s*;/m', $src, $nsMatch)) {
                    continue;
                }
                if (!preg_match('/^(?:(?:final|abstract|readonly)\s+)*class\s+(\w+)/m', $src, $cMatch)) {
                    continue;
                }
                $fileByFqcn[$nsMatch[1] . '\\' . $cMatch[1]] = $real;
            }
        } catch (Throwable $e) {
            Craft::warning(
                "KunstmaanSourceScanner: M2M file walk failed: {$e->getMessage()}",
                __METHOD__,
            );
        }

        foreach ($entities as $owningFqcn => $info) {
            $hasM2m = false;
            foreach ($info->relations as $relation) {
                if ($relation->relationType === 'ManyToMany') {
                    $hasM2m = true;
                    break;
                }
            }
            if (!$hasM2m) {
                continue;
            }

            $path = $fileByFqcn[$owningFqcn] ?? null;
            if ($path === null) {
                continue;
            }
            $src = @file_get_contents($path);
            if ($src === false || $src === '') {
                continue;
            }

            // Match #[ORM\JoinTable(name: '...')] and capture the table name.
            // We pair each JoinTable with the next ManyToMany targetEntity in
            // file order — Doctrine convention places JoinTable adjacent to
            // its ManyToMany attribute on the same property.
            preg_match_all(
                '/#\[ORM\\\\JoinTable\s*\(\s*name\s*:\s*[\'"]([^\'"]+)[\'"]/i',
                $src,
                $jtMatches,
            );
            $jtNames = $jtMatches[1] ?? [];
            if ($jtNames === []) {
                continue;
            }

            $m2mIndex = 0;
            foreach ($info->relations as $relation) {
                if ($relation->relationType !== 'ManyToMany') {
                    continue;
                }
                $tableName = $jtNames[$m2mIndex] ?? null;
                $m2mIndex++;
                if ($tableName === null || $tableName === '') {
                    continue;
                }
                $joins[] = [
                    'table'   => $tableName,
                    'owning'  => $owningFqcn,
                    'inverse' => $relation->targetEntity !== '' ? $relation->targetEntity : null,
                ];
            }
        }

        // Phase 8.7 / D-29 — fallback path: M2M relations without an explicit
        // `#[ORM\JoinTable(name: '...')]` annotation are auto-named by
        // Doctrine. Query the legacy DB's information_schema for tables
        // that have FK constraints to BOTH the owning entity's table AND
        // the target entity's table — that's the join table by structural
        // identity. Surveyed 6 CQM entities with implicit-JoinTable M2M
        // (CasesPagePart, NewsCategory, DocumentCategory, …) — all resolve
        // via this path. Empty-handed when the FK constraints are missing
        // (rare; Doctrine emits them for generated M2M tables).
        $alreadyResolved = [];
        foreach ($joins as $j) {
            $alreadyResolved[$j['owning'] . '|' . ((string) $j['inverse'])] = true;
        }
        foreach ($entities as $owningFqcn => $info) {
            $owningTable = $info->tableName;
            if ($owningTable === '') {
                continue;
            }
            foreach ($info->relations as $relation) {
                if ($relation->relationType !== 'ManyToMany') {
                    continue;
                }
                $targetFqcn = $relation->targetEntity;
                if ($targetFqcn === '' || isset($alreadyResolved[$owningFqcn . '|' . $targetFqcn])) {
                    continue;
                }
                $targetInfo = $entities[$targetFqcn] ?? null;
                $targetTable = $targetInfo?->tableName ?? '';
                if ($targetTable === '') {
                    continue;
                }
                $joinTable = $this->discoverM2mJoinTableViaInfoSchema($owningTable, $targetTable);
                if ($joinTable !== null) {
                    $joins[] = [
                        'table'   => $joinTable,
                        'owning'  => $owningFqcn,
                        'inverse' => $targetFqcn,
                    ];
                }
            }
        }

        return $joins;
    }

    /**
     * Phase 8.7 / D-29 — info_schema-based M2M join-table discovery.
     *
     * A join table has FK constraints to BOTH the owning entity's table AND
     * the target entity's table. Query KEY_COLUMN_USAGE for FKs grouped by
     * TABLE_NAME; the first table whose grouped REFERENCED_TABLE_NAME set
     * contains both is the join table. Cached per (owning, target) pair for
     * the lifetime of the service.
     *
     * Returns null when no such table exists OR legacyDb is unavailable
     * (test bootstrap, source-only scan).
     */
    private function discoverM2mJoinTableViaInfoSchema(string $owningTable, string $targetTable): ?string
    {
        static $cache = [];
        $key = $owningTable . '|' . $targetTable;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        if ($this->legacyDb === null) {
            return $cache[$key] = null;
        }
        try {
            $rows = $this->legacyDb->queryAll(
                'SELECT TABLE_NAME, REFERENCED_TABLE_NAME'
                . ' FROM information_schema.KEY_COLUMN_USAGE'
                . ' WHERE TABLE_SCHEMA = DATABASE()'
                . '   AND REFERENCED_TABLE_NAME IN (:owning, :target)',
                [':owning' => $owningTable, ':target' => $targetTable],
            );
        } catch (Throwable) {
            return $cache[$key] = null;
        }
        $byTable = [];
        foreach ($rows as $r) {
            $t = (string) ($r['TABLE_NAME'] ?? '');
            $ref = (string) ($r['REFERENCED_TABLE_NAME'] ?? '');
            if ($t === '' || $ref === '') { continue; }
            if ($t === $owningTable || $t === $targetTable) {
                continue; // self-references on the entity tables aren't join tables
            }
            $byTable[$t][$ref] = true;
        }
        foreach ($byTable as $t => $refs) {
            if (isset($refs[$owningTable], $refs[$targetTable])) {
                return $cache[$key] = $t;
            }
        }
        return $cache[$key] = null;
    }

    // -------------------------------------------------------------------------
    // Drift detection (D-32)
    // -------------------------------------------------------------------------

    /**
     * Compare scan-discovered tables against the live DB's information_schema.
     *
     * Two findings:
     *   - dbHasButScanMissing : DB tables NOT in scan output, with kuma_*
     *     core tables filtered out (KunstmaanCoreTables vendor scaffolding is
     *     expected to be present regardless of source-side discovery).
     *   - scanHasButDbMissing : scan tables NOT present in DB (entity defined
     *     but never persisted — e.g. a stale entity class).
     *
     * @param list<string> $scanTables
     * @return array{dbHasButScanMissing: list<string>, scanHasButDbMissing: list<string>}
     */
    private function detectDrift(array $scanTables): array
    {
        $dbTables = $this->fetchDbTables();
        if ($dbTables === null) {
            return ['dbHasButScanMissing' => [], 'scanHasButDbMissing' => []];
        }

        $scanSet = array_flip($scanTables);
        $dbSet = array_flip($dbTables);

        // (i) DB has table not in scan, minus the kuma_* core scaffolding.
        $coreSkip = [
            KunstmaanCoreTables::NODES             => true,
            KunstmaanCoreTables::NODE_TRANSLATIONS => true,
            KunstmaanCoreTables::NODE_VERSIONS     => true,
            KunstmaanCoreTables::PAGE_PART_REFS    => true,
            KunstmaanCoreTables::MEDIA             => true,
            KunstmaanCoreTables::SEO               => true,
            KunstmaanCoreTables::REDIRECTS         => true,
        ];
        $dbHasButScanMissing = [];
        foreach ($dbTables as $t) {
            if (isset($scanSet[$t])) {
                continue;
            }
            if (isset($coreSkip[$t])) {
                continue;
            }
            // Filter out vendor kuma_* prefix entirely — only project content
            // tables (e.g. lameco_websitebundle_*) should surface as drift.
            if (str_starts_with($t, 'kuma_')) {
                continue;
            }
            $dbHasButScanMissing[] = $t;
        }
        sort($dbHasButScanMissing);

        // (ii) scan declares table missing from DB.
        $scanHasButDbMissing = [];
        foreach ($scanTables as $t) {
            if (!isset($dbSet[$t])) {
                $scanHasButDbMissing[] = $t;
            }
        }
        sort($scanHasButDbMissing);

        return [
            'dbHasButScanMissing' => $dbHasButScanMissing,
            'scanHasButDbMissing' => $scanHasButDbMissing,
        ];
    }

    /**
     * Query information_schema for all tables in the legacy DB schema.
     *
     * Bound parameter `:db` carries the schema name — never string-concatenated
     * into the SQL literal (T-02.1-05-01 mitigation). Returns null when the
     * legacyDb component is unavailable so drift detection degrades gracefully.
     *
     * @return list<string>|null
     */
    private function fetchDbTables(): ?array
    {
        if ($this->legacyDb === null) {
            return null;
        }

        $list = [];
        try {
            $dbName = (string) $this->legacyDb->db()
                ->createCommand('SELECT DATABASE()')
                ->queryScalar();

            $rows = $this->legacyDb->queryAll(
                'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = :db',
                [':db' => $dbName],
            );
            foreach ($rows as $row) {
                $t = (string) ($row['TABLE_NAME'] ?? '');
                if ($t !== '') {
                    $list[] = $t;
                }
            }
        } catch (Throwable $e) {
            Craft::warning(
                "KunstmaanSourceScanner drift query failed: {$e->getMessage()}",
                __METHOD__,
            );
            return null;
        }

        return $list;
    }

    // -------------------------------------------------------------------------
    // Empty result shape (defensive — null source path / unwarmed parser)
    // -------------------------------------------------------------------------

    /**
     * @return array{
     *   tables: list<string>,
     *   entities: array<string, \lameco\kunstmaanmigrator\source\DoctrineEntityInfo>,
     *   m2mJoins: list<array{table: string, owning: string, inverse: ?string}>,
     *   bodyCols: array<string, list<string>>,
     *   mediaFks: list<array{table: string, column: string}>,
     *   drift: array{dbHasButScanMissing: list<string>, scanHasButDbMissing: list<string>},
     * }
     */
    private function emptyResult(): array
    {
        return [
            'tables'   => [],
            'entities' => [],
            'm2mJoins' => [],
            'bodyCols' => [],
            'mediaFks' => [],
            'drift'    => ['dbHasButScanMissing' => [], 'scanHasButDbMissing' => []],
        ];
    }
}
