<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\source;

use lameco\kunstmaanmigrator\db\LegacyDbService;
use lameco\kunstmaanmigrator\source\DoctrineEntityParser;
use lameco\kunstmaanmigrator\source\KunstmaanCoreTables;
use DateTimeInterface;
use yii\base\Component;

/**
 * KnowledgeBase — Markdown KB generator for LLM prompts.
 *
 * Single entry point for emitting the two page-reachable schema documents
 * consumed by LlmClassifier (Plan 6 wiring will replace the current
 * `'', ''` placeholders in LlmClassifier::batchPropose).
 *
 *   renderPagesMarkdown(?array $mapping, DateTimeInterface $now): string
 *   renderPagePartsMarkdown(?array $mapping, DateTimeInterface $now): string
 *
 * Phase 02.1 Plan 03 partial port (~600 LOC of v1's ~2200): only the two
 * public render methods + every transitively-reachable private helper are
 * ported. Excluded by design:
 *   - File-I/O wrapper methods that wrap the two render methods with
 *     `file_put_contents` (v2 callers want in-memory strings, not files
 *     on disk).
 *   - The reachable-set computation + walker helpers — Plan 09
 *     reconciliation may port these separately if CoverageAuditor parity
 *     surfaces a gap.
 *   - The full Craft-side KB (project-config readers, matrix-availability
 *     index) — out of scope for this phase.
 *
 * 8000-char truncation contract: KnowledgeBase emits FULL text. The
 * LlmClassifier::batchPropose call site already truncates internally via
 * wasTruncated($legacyKbMarkdown, 8000). Do NOT add truncation here.
 *
 * Reshapes from v1:
 *   - Namespace flatten: kunstmaan\\schema\\knowledgebase → source.
 *   - KunstmaanCoreTables import points at v2 sibling const class.
 *   - LegacyDbService import points at v2's db namespace.
 *   - DoctrineEntityParser import points at v2 sibling.
 *   - Drop KnowledgeBasePaths (file-path helper irrelevant for in-memory output).
 */
final class KnowledgeBase extends Component
{
    // ---- Kunstmaan side -------------------------------------------------

    public ?LegacyDbService $legacyDb = null;

    /**
     * Doctrine entity parser — when set, source code is used as the
     * authoritative column/relation source. DB is used only for row counts and
     * sample values.
     */
    public ?DoctrineEntityParser $entityParser = null;

    /** Legacy-table prefix; set from mapping.yaml meta.sourceDb.legacyTablePrefix. */
    public string $legacyTablePrefix = 'lameco_websitebundle_';

    /** Max length of an individual sample value before truncation. */
    public const SAMPLE_MAX_LEN = 40;

    public const MAX_SAMPLES = 3;

    private const IDENT_RX = '/^[A-Za-z0-9_]+$/';

    /** Core tables always included in the KB regardless of the reachability walk (K1 fix, plan 09-01). */
    private const CORE_TABLES = [
        KunstmaanCoreTables::SEO,
        KunstmaanCoreTables::MEDIA,
        KunstmaanCoreTables::REDIRECTS,
    ];

    // =====================================================================
    // Kunstmaan KB
    // =====================================================================

    /**
     * @param array<string, mixed>|null $mapping
     */
    public function renderPagePartsMarkdown(?array $mapping, DateTimeInterface $now): string
    {
        if ($this->legacyDb === null) {
            throw new \LogicException('KnowledgeBase requires legacyDb service.');
        }

        // Optional mapping overlay for annotations.
        $mappingPageParts = $mapping !== null ? (array) ($mapping['pageParts'] ?? []) : [];
        $allColumns       = $this->loadAllColumns();

        // Build reverse index: pagePart FQCN → list of page FQCNs
        /** @var array<string, list<string>> $partToPages */
        $partToPages = [];
        try {
            $allRefs = $this->legacyDb->queryAll(
                'SELECT DISTINCT pageEntityname, page_part_entityname'
                . ' FROM ' . KunstmaanCoreTables::PAGE_PART_REFS
                . ' WHERE pageEntityname IS NOT NULL AND page_part_entityname IS NOT NULL',
            );
            foreach ($allRefs as $r) {
                $page = (string) ($r['pageEntityname'] ?? '');
                $pp   = (string) ($r['page_part_entityname'] ?? '');
                if ($page !== '' && $pp !== '') {
                    $partToPages[$pp][] = $page;
                }
            }
        } catch (\Throwable) {
            // Reverse index unavailable.
        }

        $out   = [];
        $out[] = '# Kunstmaan Page Parts (deep introspection, generated ' . $now->format(DATE_ATOM) . ')';
        $out[] = '';
        $out[] = '_All page part types discovered from `kuma_page_part_refs`. Full column, relation and join-table detail._';
        $out[] = '_Page parts are reusable: a single type may appear on multiple page types._';
        $out[] = '';

        // Discover page part FQCNs from DB.
        $discoveredPpFqcns = [];
        try {
            $ppDbRows = $this->legacyDb->queryAll(
                'SELECT DISTINCT page_part_entityname FROM ' . KunstmaanCoreTables::PAGE_PART_REFS
                . ' WHERE page_part_entityname IS NOT NULL ORDER BY page_part_entityname',
            );
            foreach ($ppDbRows as $r) {
                $fqcn = (string) ($r['page_part_entityname'] ?? '');
                if ($fqcn !== '') {
                    $discoveredPpFqcns[] = $fqcn;
                }
            }
        } catch (\Throwable $e) {
            $out[] = sprintf('_Could not discover page parts from DB: %s_', $e->getMessage());
            return implode("\n", $out);
        }

        if ($discoveredPpFqcns === []) {
            $out[] = '_No page parts found in DB._';
            return implode("\n", $out);
        }

        foreach ($discoveredPpFqcns as $ppFqcn) {
            $ppSpec = is_array($mappingPageParts[$ppFqcn] ?? null) ? $mappingPageParts[$ppFqcn] : [];

            $targetBlock = $mapping !== null ? (string) ($ppSpec['target'] ?? '—') : '_not yet mapped_';
            $usedBy      = array_unique($partToPages[$ppFqcn] ?? []);
            sort($usedBy);

            // Resolve entity info first so we can fall back to tableName.
            $entityInfo    = $this->entityParser?->getByFqcn($ppFqcn);
            $entityColumns = $entityInfo !== null ? $entityInfo->columns : [];

            // sourceTable: entity's @ORM\Table name is canonical; fall back to mapping.yaml value if present.
            $sourceTable = $entityInfo?->tableName ?? '';
            if ($sourceTable === '') {
                $sourceTable = (string) ($ppSpec['sourceTable'] ?? '');
            }

            $shortName = substr($ppFqcn, (int) strrpos($ppFqcn, '\\') + 1);
            $out[] = sprintf('## %s', $shortName);
            $out[] = sprintf('- **FQCN**: `%s`', $ppFqcn);
            $out[] = sprintf('- **Target block**: `%s`', $targetBlock);
            if ($mapping !== null && isset($ppSpec['action'])) {
                $out[] = sprintf('- **Mapping action**: `%s`', $ppSpec['action']);
            }
            $out[] = sprintf('- **Source table**: %s', $sourceTable !== '' ? '`' . $sourceTable . '`' : '_(none / virtual)_');
            if ($usedBy !== []) {
                $usedByShort = array_map(fn($f) => substr($f, (int) strrpos($f, '\\') + 1), $usedBy);
                $out[] = '- **Used by**: ' . implode(', ', $usedByShort);
            }
            $out[] = '';

            // --- Columns ---
            $dbCols = $sourceTable !== '' ? ($allColumns[$sourceTable] ?? null) : null;

            $rowCount = 0;
            if ($sourceTable !== '' && $dbCols !== null) {
                try {
                    $rowCount = (int) $this->legacyDb->queryScalar('SELECT COUNT(*) FROM `' . $sourceTable . '`');
                } catch (\Throwable) {}
            }

            $colSource = $entityColumns !== [] ? $entityColumns : [];
            if ($colSource !== [] || $dbCols !== null) {
                $out[] = sprintf('### Columns (%d rows)', $rowCount);
                $out[] = '| column | type | nullable | fill% | samples |';
                $out[] = '|--------|------|----------|-------|---------|';

                $dbColMap = [];
                foreach ($dbCols ?? [] as $cm) {
                    $dbColMap[$cm['column']] = $cm;
                }

                if ($colSource !== []) {
                    foreach ($colSource as $ec) {
                        $col      = $ec->columnName;
                        $type     = $ec->type;
                        $nullable = $ec->nullable ? 'yes' : 'no';
                        $fill     = '—';
                        $samples  = '—';
                        if ($rowCount > 0 && isset($dbColMap[$col])) {
                            try {
                                $nonNull  = (int) $this->legacyDb->queryScalar(
                                    'SELECT COUNT(*) FROM `' . $sourceTable . '` WHERE `' . $col . "` IS NOT NULL AND `" . $col . "` != ''",
                                );
                                $fill    = number_format(round($nonNull / $rowCount * 100, 1), 1);
                                $samples = $nonNull > 0 ? $this->formatSamples($this->fetchSamples($sourceTable, $col)) : '—';
                            } catch (\Throwable) {}
                        }
                        $out[] = sprintf('| %s | %s | %s | %s | %s |', $col, $type, $nullable, $fill, $samples);
                    }
                } else {
                    foreach ($dbCols as $cm) {
                        $col      = $cm['column'];
                        $type     = $cm['type'];
                        $nullable = $cm['nullable'] === 'YES' ? 'yes' : 'no';
                        $fill     = '—';
                        $samples  = '—';
                        if ($rowCount > 0) {
                            try {
                                $nonNull  = (int) $this->legacyDb->queryScalar(
                                    'SELECT COUNT(*) FROM `' . $sourceTable . '` WHERE `' . $col . "` IS NOT NULL AND `" . $col . "` != ''",
                                );
                                $fill    = number_format(round($nonNull / $rowCount * 100, 1), 1);
                                $samples = $nonNull > 0 ? $this->formatSamples($this->fetchSamples($sourceTable, $col)) : '—';
                            } catch (\Throwable) {}
                        }
                        $out[] = sprintf('| %s | %s | %s | %s | %s |', $col, $type, $nullable, $fill, $samples);
                    }
                }
                $out[] = '';
            } else {
                $out[] = '_No columns found (no entity parser and no source table)._';
                $out[] = '';
            }

            // Collect all join tables from mapping.yaml for this page part.
            // Key: PHP property name; value: join table name.
            // Used below to annotate ManyToMany entity relations.
            /** @var array<string, string> $propNameToJoinTable */
            $propNameToJoinTable = [];
            foreach ((array) ($ppSpec['fields'] ?? []) as $fieldSpec) {
                if (!is_array($fieldSpec) || ($fieldSpec['handler'] ?? '') !== 'relation') {
                    continue;
                }
                // $src is the PHP property name on the entity.
                $src = (string) ($fieldSpec['source'] ?? '');
                // Explicit handlerOptions.joinTable wins.
                $jt = (string) ($fieldSpec['handlerOptions']['joinTable'] ?? '');
                if ($jt !== '' && $src !== '' && !str_contains($src, '.')) {
                    $propNameToJoinTable[$src] = $jt;
                } elseif ($jt === '' && $src !== '' && !str_contains($src, '.')) {
                    // Bare source (no dot) treated as join table by older mapping entries.
                    $propNameToJoinTable[$src] = $src;
                }
            }

            // --- Relations ---
            $relations = $entityInfo?->relations ?? [];
            if ($relations !== []) {
                $out[] = '### Relations';
                $out[] = '';
                // Phase 8.5 / D-20 — concise summary table (ManyToOne + ManyToMany).
                // Surfaces target table + target columns inline so the LLM can map
                // `_rel:<prop>.<col>` columns (D-21) without an additional lookup.
                // Detailed per-relation blocks (join-table sample nodes, child
                // table info for OneToMany) follow below.
                $this->appendRelationsTable($out, $relations);
                foreach ($relations as $rel) {
                    $relType      = $rel->relationType;
                    $propName     = $rel->propertyName;
                    $targetEntity = $rel->targetEntity;
                    $fkCol        = $rel->fkColumn ?? null;
                    // For ManyToMany, look up join table by PHP property name from mapping overlay.
                    $joinTable    = $propNameToJoinTable[$propName] ?? null;

                    $out[] = sprintf('#### `%s` — %s → %s', $propName, $relType, $targetEntity);
                    if ($fkCol !== null) {
                        $out[] = sprintf('- FK column: `%s`', $fkCol);
                    }

                    if ($joinTable !== null && $joinTable !== '') {
                        $out[] = sprintf('- Join table: `%s`', $joinTable);
                        // Count rows in join table and show sample linked node titles.
                        try {
                            $jtCount = (int) $this->legacyDb->queryScalar('SELECT COUNT(*) FROM `' . $joinTable . '`');
                            $out[] = sprintf('- Join table rows: %d', $jtCount);

                            // Try to pull sample node titles via the join table.
                            // We look for a `node_id` column in the join table.
                            $jtCols = $this->legacyDb->queryAll(
                                'SELECT COLUMN_NAME FROM information_schema.COLUMNS'
                                . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t',
                                [':t' => $joinTable],
                            );
                            $jtColNames = array_column($jtCols, 'COLUMN_NAME');
                            if (in_array('node_id', $jtColNames, true)) {
                                $sampleRows = $this->legacyDb->queryAll(
                                    'SELECT nt.title FROM `' . $joinTable . '` j'
                                    . ' JOIN ' . KunstmaanCoreTables::NODE_TRANSLATIONS . ' nt'
                                    . " ON nt.node_id = j.node_id AND nt.lang = 'nl'"
                                    . ' LIMIT ' . self::MAX_SAMPLES * 2,
                                );
                                $titles = array_column($sampleRows, 'title');
                                if ($titles !== []) {
                                    $out[] = '- Sample linked nodes: ' . implode(', ', array_map(
                                        fn($t) => '"' . $this->truncateSample((string) $t) . '"',
                                        $titles,
                                    ));
                                }
                            }
                        } catch (\Throwable $e) {
                            $out[] = sprintf('- _(could not query join table: %s)_', $e->getMessage());
                        }
                    } elseif ($relType === 'OneToMany') {
                        // For OneToMany (e.g. ClientsPagePart → ClientItem), show the child table.
                        $targetEntityInfo = $this->entityParser?->getByFqcn($targetEntity);
                        if ($targetEntityInfo !== null && $targetEntityInfo->tableName !== '') {
                            $childTable = $targetEntityInfo->tableName;
                            $out[] = sprintf('- Child table: `%s`', $childTable);
                            try {
                                $childCount = (int) $this->legacyDb->queryScalar('SELECT COUNT(*) FROM `' . $childTable . '`');
                                $out[] = sprintf('- Child rows: %d', $childCount);
                                // Show child columns for context.
                                $childEntityCols = $targetEntityInfo->columns;
                                if ($childEntityCols !== []) {
                                    $colNames = array_map(fn($c) => '`' . $c->columnName . '`', $childEntityCols);
                                    $out[] = '- Child columns: ' . implode(', ', $colNames);
                                }
                            } catch (\Throwable) {}
                        }
                    }

                    $out[] = '';
                }
            }

            $out[] = '---';
            $out[] = '';
        }

        return implode("\n", $out);
    }

    /**
     * Phase 8 / Plan 03 — render the third KB surface: non-page Doctrine
     * entity candidates that the Wave 2 LLM proposer (`proposeNonPageEntities`)
     * classifies as taxonomy / supporting / drop.
     *
     * Walks the warmed `DoctrineEntityParser` (Plan 02 source-truth) and
     * filters out every FQCN persisted in `kuma_nodes.ref_entity_name`
     * (those are page entities — already rendered by `renderPagesMarkdown`).
     *
     * Each candidate gets:
     *   - one-line metadata (FQCN, source table, COUNT(*) row count),
     *   - a columns table reused from `renderTableColumns()`,
     *   - a "Gedmo translatable fields" subsection IFF Plan 02's parser flag
     *     is present on the column object (defensive feature-detect via
     *     `property_exists` so this plan can land independently of 08-02).
     *
     * Truncation contract: emits the FULL string. The KnowledgeBase docblock
     * (lines 36-37) and the `must_haves.truths` row both pin the convention
     * that LlmClassifier::batchPropose handles truncation downstream — adding
     * truncation here would diverge from the existing two render methods.
     * (Rule 1 deviation from the plan's `<action>` skeleton; documented in
     * SUMMARY.md.)
     *
     * @param array<string, mixed>|null $mapping  Reserved for symmetry with
     *                                            the existing render methods;
     *                                            no overlay annotations consumed
     *                                            in the Phase 8 / Plan 05 prompt
     *                                            scope, but the parameter is
     *                                            kept on the signature so
     *                                            callers can pass `mapping.yaml`
     *                                            uniformly across all three
     *                                            render methods.
     */
    public function renderTaxonomiesMarkdown(?array $mapping, DateTimeInterface $now): string
    {
        if ($this->legacyDb === null) {
            throw new \LogicException('KnowledgeBase requires legacyDb service for renderTaxonomiesMarkdown().');
        }

        $out   = [];
        $out[] = '# Kunstmaan Taxonomy Candidates (non-Page Doctrine entities, generated ' . $now->format(DATE_ATOM) . ')';
        $out[] = '';
        $out[] = '_Non-Page Doctrine entities discovered by the source parser. Categories, tags and other standalone entities live here._';
        $out[] = '_Each entity may carry Gedmo Translatable properties — listed per FQCN when the source attribute is present._';
        $out[] = '';

        // 1. Page-entity exclusion set: every FQCN that has a row in kuma_nodes
        //    is a page entity (already rendered by renderPagesMarkdown). Mirrors
        //    the discovery query at lines 365-370.
        $pageFqcns = [];
        try {
            $nodeRows = $this->legacyDb->queryAll(
                'SELECT DISTINCT ref_entity_name'
                . ' FROM ' . KunstmaanCoreTables::NODES
                . ' WHERE deleted = 0 AND ref_entity_name IS NOT NULL',
            );
            foreach ($nodeRows as $r) {
                $fqcn = (string) ($r['ref_entity_name'] ?? '');
                if ($fqcn !== '') {
                    $pageFqcns[$fqcn] = true;
                }
            }
        } catch (\Throwable $e) {
            $out[] = sprintf('_Could not load page-entity exclusion set: %s_', $e->getMessage());
            return implode("\n", $out);
        }

        // 2. Walk every parsed Doctrine entity. Null parser → empty list →
        //    "no entities discovered" footer.
        $entities = $this->entityParser?->getAll() ?? [];

        /** @var array<string, \lameco\kunstmaanmigrator\source\DoctrineEntityInfo> $candidates */
        $candidates = [];
        foreach ($entities as $fqcn => $entityInfo) {
            if (!is_string($fqcn) || $fqcn === '') {
                continue;
            }
            if (isset($pageFqcns[$fqcn])) {
                continue; // page entity — out of scope for this surface.
            }
            $candidates[$fqcn] = $entityInfo;
        }

        if ($candidates === []) {
            $out[] = '_No non-Page entities discovered._';
            return implode("\n", $out);
        }

        ksort($candidates);

        // 3. Pre-load the prefix-scoped column index once. renderTableColumns()
        //    consumes it by reference + appends to $out (signature lines 498-563).
        $allColumns = $this->loadAllColumns();

        foreach ($candidates as $fqcn => $entityInfo) {
            $sourceTable = $entityInfo->tableName;

            $shortName = $fqcn;
            $lastBs = strrpos($fqcn, '\\');
            if ($lastBs !== false) {
                $shortName = substr($fqcn, $lastBs + 1);
            }

            $rowCount = '?';
            if ($sourceTable !== '' && preg_match(self::IDENT_RX, $sourceTable) === 1) {
                try {
                    $rowCount = (string) (int) $this->legacyDb->queryScalar(
                        'SELECT COUNT(*) FROM `' . $sourceTable . '`',
                    );
                } catch (\Throwable) {
                    $rowCount = '?';
                }
            }

            $out[] = sprintf('## %s', $shortName);
            $out[] = sprintf('- **FQCN**: `%s`', $fqcn);
            $out[] = sprintf(
                '- **Source table**: %s',
                $sourceTable !== '' ? '`' . $sourceTable . '`' : '_(none)_',
            );
            $out[] = sprintf('- **Row count**: %s', $rowCount);
            $out[] = '';

            if ($sourceTable !== '') {
                $this->renderTableColumns($out, $sourceTable, $allColumns);
            }

            // 4. Gedmo translatable subsection — defensive feature-detect.
            //    Plan 08-02 (sibling, wave 1) adds `isGedmoTranslatable` to
            //    DoctrineColumnInfo; until that lands, this method emits no
            //    Gedmo subsection. property_exists() is the contract: it
            //    returns true once the property is declared, regardless of
            //    visibility.
            $translatable = [];
            foreach ($entityInfo->columns as $col) {
                if (
                    property_exists($col, 'isGedmoTranslatable')
                    // @phpstan-ignore-next-line property is added by Plan 08-02
                    && $col->isGedmoTranslatable === true
                ) {
                    $name = $col->columnName !== '' ? $col->columnName : $col->propertyName;
                    if ($name !== '') {
                        $translatable[] = $name;
                    }
                }
            }
            if ($translatable !== []) {
                $out[] = '### Gedmo translatable fields';
                $out[] = '';
                foreach ($translatable as $field) {
                    $out[] = '- `' . $field . '`';
                }
                $out[] = '';
            }

            $out[] = '---';
            $out[] = '';
        }

        return implode("\n", $out);
    }

    /**
     * @param array<string, mixed>|null $mapping
     */
    public function renderPagesMarkdown(?array $mapping, DateTimeInterface $now): string
    {
        if ($this->legacyDb === null) {
            throw new \LogicException('KnowledgeBase requires legacyDb service for renderPagesMarkdown().');
        }

        $allColumns = $this->loadAllColumns();

        // Optional mapping overlay for annotations.
        $mappingNodeClasses = $mapping !== null ? (array) ($mapping['nodeClasses'] ?? []) : [];
        $mappingPageParts   = $mapping !== null ? (array) ($mapping['pageParts'] ?? []) : [];
        $mappingSections    = $mapping !== null ? (array) ($mapping['sections'] ?? []) : [];

        $out   = [];
        $out[] = '# Kunstmaan Pages (generated ' . $now->format(DATE_ATOM) . ')';
        $out[] = '';
        $out[] = '_All page types discovered from `kuma_nodes`. For deep page-part detail (columns, relations, join tables), see `kunstmaan-pageparts.md`._';
        $out[] = '';

        // Discover all page type FQCNs directly from kuma_nodes.
        $discoveredFqcns = [];
        try {
            $nodeRows = $this->legacyDb->queryAll(
                'SELECT ref_entity_name, COUNT(*) AS node_count'
                . ' FROM ' . KunstmaanCoreTables::NODES
                . ' WHERE deleted = 0'
                . ' GROUP BY ref_entity_name ORDER BY ref_entity_name',
            );
            foreach ($nodeRows as $r) {
                $fqcn = (string) ($r['ref_entity_name'] ?? '');
                if ($fqcn !== '') {
                    $discoveredFqcns[$fqcn] = (int) ($r['node_count'] ?? 0);
                }
            }
        } catch (\Throwable $e) {
            $out[] = sprintf('_Could not discover page types from DB: %s_', $e->getMessage());
            return implode("\n", $out);
        }

        foreach ($discoveredFqcns as $ncFqcn => $nodeCount) {
            $ncSpec = is_array($mappingNodeClasses[$ncFqcn] ?? null) ? $mappingNodeClasses[$ncFqcn] : [];

            // Source table: entity parser is canonical; mapping.yaml value as fallback.
            $entityInfo  = $this->entityParser?->getByFqcn($ncFqcn);
            $sourceTable = $entityInfo?->tableName ?? '';
            if ($sourceTable === '') {
                $sourceTable = (string) ($ncSpec['sourceTable'] ?? '');
            }

            $shortName = substr($ncFqcn, (int) strrpos($ncFqcn, '\\') + 1);
            $out[] = sprintf('## %s', $shortName);
            $out[] = sprintf('- **FQCN**: `%s`', $ncFqcn);
            $out[] = sprintf('- **Node count**: %d', $nodeCount);

            // Mapping annotation (optional overlay).
            if ($mapping !== null) {
                $sectionKey    = (string) ($ncSpec['section'] ?? '');
                $sectionSpec   = is_array($mappingSections[$sectionKey] ?? null) ? $mappingSections[$sectionKey] : null;
                $entryType     = $sectionSpec !== null ? (string) ($sectionSpec['entryType'] ?? '—') : '—';
                $sectionHandle = $sectionSpec !== null
                    ? (string) ($sectionSpec['section'] ?? $sectionKey)
                    : ($sectionKey !== '' ? $sectionKey : '—');
                $action        = (string) ($ncSpec['action'] ?? 'unmapped');
                $out[] = sprintf('- **Mapping**: action `%s` → entryType `%s` → section `%s`', $action, $entryType, $sectionHandle);
            }

            $out[] = sprintf('- **Source table**: %s', $sourceTable !== '' ? '`' . $sourceTable . '`' : '_none_');
            $out[] = '';

            // Own columns (entity-sourced + fill% from DB).
            if ($sourceTable !== '') {
                $out[] = '### Own columns';
                $this->renderTableColumns($out, $sourceTable, $allColumns);
            }

            // Phase 8.5 / D-20 — Relations subsection. Surfaces ManyToOne /
            // ManyToMany relations (target table + target columns) so the LLM
            // can interpret `_rel:<prop>.<col>` joined columns (see
            // ExtractService::joinManyToOneRelations + D-21).
            $pageRelations = $entityInfo?->relations ?? [];
            if ($pageRelations !== []) {
                $out[] = '### Relations';
                $out[] = '';
                $this->appendRelationsTable($out, $pageRelations);
            }

            // Page parts used by this page type.
            try {
                $ppRows = $this->legacyDb->queryAll(
                    'SELECT page_part_entityname, COUNT(*) AS cnt,'
                    . ' GROUP_CONCAT(DISTINCT context ORDER BY context SEPARATOR \', \') AS contexts'
                    . ' FROM ' . KunstmaanCoreTables::PAGE_PART_REFS
                    . ' WHERE pageEntityname = :fqcn'
                    . ' GROUP BY page_part_entityname'
                    . ' ORDER BY page_part_entityname',
                    [':fqcn' => $ncFqcn],
                );
                if ($ppRows !== []) {
                    $out[] = '### Page parts';
                    $out[] = '| page part | target block | contexts | count |';
                    $out[] = '|-----------|--------------|----------|-------|';
                    foreach ($ppRows as $ppr) {
                        $ppFqcn2     = (string) ($ppr['page_part_entityname'] ?? '');
                        $ppSpec2     = is_array($mappingPageParts[$ppFqcn2] ?? null) ? $mappingPageParts[$ppFqcn2] : null;
                        $targetBlock = $mapping !== null
                            ? ($ppSpec2 !== null ? (string) ($ppSpec2['target'] ?? '—') : '—')
                            : '_not yet mapped_';
                        $contexts = (string) ($ppr['contexts'] ?? '—');
                        $cnt      = (int) ($ppr['cnt'] ?? 0);
                        $shortPp  = substr($ppFqcn2, (int) strrpos($ppFqcn2, '\\') + 1);
                        $out[]    = sprintf('| `%s` | `%s` | %s | %d |', $shortPp, $targetBlock, $contexts, $cnt);
                    }
                    $out[] = '';
                }
            } catch (\Throwable $e) {
                $out[] = sprintf('_Could not query page parts: %s_', $e->getMessage());
                $out[] = '';
            }
        }

        // --- Appendix: node counts per type (helps AI understand data volumes) --------
        $out[] = '## Appendix: node counts';
        $out[] = '';
        $out[] = '_`extractable` = nodes with at least one `online = 1` translation. Offline-only nodes are excluded from the extract._';
        $out[] = '';
        try {
            $visRows = $this->legacyDb->queryAll(
                'SELECT ref_entity_name,'
                . ' COUNT(*) AS total_nodes,'
                . ' SUM(has_online) AS extractable'
                . ' FROM ('
                . '   SELECT n.id, n.ref_entity_name,'
                . '   MAX(CASE WHEN t.online = 1 THEN 1 ELSE 0 END) AS has_online'
                . '   FROM ' . KunstmaanCoreTables::NODES . ' n'
                . '   JOIN ' . KunstmaanCoreTables::NODE_TRANSLATIONS . ' t ON t.node_id = n.id'
                . '   WHERE n.deleted = 0'
                . '   GROUP BY n.id, n.ref_entity_name'
                . ' ) sub'
                . ' GROUP BY ref_entity_name'
                . ' ORDER BY ref_entity_name',
            );
            if ($visRows !== []) {
                $out[] = '| nodeClass | total | extractable | offline-only |';
                $out[] = '|-----------|-------|-------------|--------------|';
                foreach ($visRows as $row) {
                    $total       = (int) ($row['total_nodes'] ?? 0);
                    $extractable = (int) ($row['extractable'] ?? 0);
                    $out[]       = sprintf('| %s | %d | %d | %d |',
                        $row['ref_entity_name'] ?? '?', $total, $extractable, $total - $extractable,
                    );
                }
            }
        } catch (\Throwable $e) {
            $out[] = sprintf('_Could not query node visibility: %s_', $e->getMessage());
        }
        $out[] = '';

        return implode("\n", $out);
    }

    /**
     * Phase 8.5 / D-20 — emit the canonical Relations summary table inline.
     *
     * | property | type | target | FK column | target table | target columns |
     *
     * Filters to ManyToOne + ManyToMany only (the relations the LLM can act on
     * via `_rel:` joined columns or join-table walks). OneToMany / OneToOne are
     * intentionally excluded — they don't produce a `_rel:` column at extract
     * time and the per-relation detail block below the table covers OneToMany
     * with its child-table info.
     *
     * `target table` + `target columns` are resolved via the entity parser on
     * the target FQCN. When the target isn't a parsed Doctrine entity (e.g.
     * vendor class outside `src/Entity/`), both cells render as `—` rather
     * than failing — the summary degrades gracefully.
     *
     * Pipe characters in identifiers are escaped (Doctrine doesn't allow them
     * in column names but the escape is cheap defense-in-depth).
     *
     * @param list<string> $out
     * @param \lameco\kunstmaanmigrator\source\DoctrineRelationInfo[] $relations
     */
    private function appendRelationsTable(array &$out, array $relations): void
    {
        $rows = [];
        foreach ($relations as $rel) {
            if ($rel->relationType !== 'ManyToOne' && $rel->relationType !== 'ManyToMany') {
                continue;
            }
            $targetInfo = $this->entityParser?->getByFqcn($rel->targetEntity);
            $targetTable = $targetInfo?->tableName ?? '';
            $targetCols = '';
            if ($targetInfo !== null && $targetInfo->columns !== []) {
                $names = array_map(static fn($c) => $c->columnName, $targetInfo->columns);
                $targetCols = implode(', ', $names);
            }
            $rows[] = [
                'property'      => $rel->propertyName,
                'type'          => $rel->relationType,
                'target'        => $rel->targetEntity,
                'fkColumn'      => $rel->fkColumn ?? '',
                'targetTable'   => $targetTable,
                'targetColumns' => $targetCols,
            ];
        }
        if ($rows === []) {
            return;
        }

        $out[] = '| property | type | target | FK column | target table | target columns |';
        $out[] = '|----------|------|--------|-----------|--------------|----------------|';
        foreach ($rows as $r) {
            $out[] = sprintf(
                '| %s | %s | %s | %s | %s | %s |',
                self::escapePipe($r['property']),
                self::escapePipe($r['type']),
                self::escapePipe($r['target']),
                $r['fkColumn'] !== '' ? '`' . self::escapePipe($r['fkColumn']) . '`' : '—',
                $r['targetTable'] !== '' ? '`' . self::escapePipe($r['targetTable']) . '`' : '—',
                $r['targetColumns'] !== '' ? self::escapePipe($r['targetColumns']) : '—',
            );
        }
        $out[] = '';
    }

    private static function escapePipe(string $v): string
    {
        return str_replace('|', '\\|', $v);
    }

    /**
     * Render a table's column details inline into $out (without a ### subheading).
     *
     * @param list<string> $out
     * @param array<string, list<array{column: string, type: string, nullable: string}>> $allColumns
     */
    private function renderTableColumns(array &$out, string $table, array $allColumns): void
    {
        $entityInfo    = $this->entityParser?->getByTable($table);
        $entityColumns = $entityInfo !== null ? $entityInfo->columns : [];
        $dbCols        = $allColumns[$table] ?? null;

        if ($dbCols === null && $entityColumns === []) {
            $out[] = sprintf('_Table `%s` NOT FOUND in legacy DB._', $table);
            $out[] = '';
            return;
        }

        $rowCount = $dbCols !== null
            ? (int) $this->legacyDb->queryScalar('SELECT COUNT(*) FROM `' . $table . '`')
            : 0;
        $out[] = sprintf('_%d rows._', $rowCount);
        $out[] = '';
        $out[] = '| column | type | nullable | fill% | samples |';
        $out[] = '|--------|------|----------|-------|---------|';

        $dbColMap = [];
        foreach ($dbCols ?? [] as $cm) {
            $dbColMap[$cm['column']] = $cm;
        }

        if ($entityColumns !== []) {
            foreach ($entityColumns as $ec) {
                $col        = $ec->columnName;
                $type       = $ec->type;
                $nullable   = $ec->nullable ? 'yes' : 'no';
                $fillRate   = '—';
                $samplesStr = '—';
                if ($rowCount > 0 && isset($dbColMap[$col])) {
                    $nonNull = (int) $this->legacyDb->queryScalar(
                        'SELECT COUNT(*) FROM `' . $table . '` '
                        . 'WHERE `' . $col . '` IS NOT NULL AND `' . $col . "` != ''",
                    );
                    $fillRate   = number_format(round($nonNull / $rowCount * 100, 1), 1);
                    $samplesStr = $nonNull > 0
                        ? $this->formatSamples($this->fetchSamples($table, $col))
                        : '—';
                }
                $out[] = sprintf('| %s | %s | %s | %s | %s |', $col, $type, $nullable, $fillRate, $samplesStr);
            }
        } else {
            foreach ($dbCols as $colMeta) {
                $col        = $colMeta['column'];
                $type       = $colMeta['type'];
                $nullable   = $colMeta['nullable'] === 'YES' ? 'yes' : 'no';
                $fillRate   = '0.0';
                $samplesStr = '—';
                if ($rowCount > 0) {
                    $nonNull = (int) $this->legacyDb->queryScalar(
                        'SELECT COUNT(*) FROM `' . $table . '` '
                        . 'WHERE `' . $col . '` IS NOT NULL AND `' . $col . "` != ''",
                    );
                    $fillRate   = number_format(round($nonNull / $rowCount * 100, 1), 1);
                    $samplesStr = $nonNull > 0
                        ? $this->formatSamples($this->fetchSamples($table, $col))
                        : '—';
                }
                $out[] = sprintf('| %s | %s | %s | %s | %s |', $col, $type, $nullable, $fillRate, $samplesStr);
            }
        }
        $out[] = '';
    }

    /**
     * Load all columns for every prefix-scoped table in one information_schema query.
     *
     * @return array<string, list<array{column: string, type: string, nullable: string}>>
     */
    private function loadAllColumns(): array
    {
        // Phase 02.1 Plan 03 Task 3 reshape (Rule 3 deviation): v1 inlined
        // $this->legacyDb->db()->createCommand('SELECT DATABASE()')->queryScalar()
        // here, which forces tests to mock both the LegacyDbService AND a yii\db\Connection
        // stub. v2's LegacyDbService::queryScalar(string $sql, array $params = []) is
        // the equivalent one-call surface — semantics identical, mockable through a
        // single seam. See also BodyScanColumnFinder + MediaFkScanner which preserved
        // the v1 db()->createCommand chain (those don't yet have smoke tests).
        $dbName = (string) $this->legacyDb->queryScalar('SELECT DATABASE()');

        $coreBindings = [];
        $corePlaceholders = [];
        foreach (self::CORE_TABLES as $i => $coreTable) {
            $coreBindings[':ct' . $i] = $coreTable;
            $corePlaceholders[] = ':ct' . $i;
        }
        $coreInClause = implode(',', $corePlaceholders);

        $rows = $this->legacyDb->queryAll(
            'SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, IS_NULLABLE '
            . 'FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = :db '
            . 'AND (TABLE_NAME LIKE :prefix OR TABLE_NAME IN (' . $coreInClause . ')) '
            . 'ORDER BY TABLE_NAME, ORDINAL_POSITION',
            array_merge([':db' => $dbName, ':prefix' => $this->legacyTablePrefix . '%'], $coreBindings),
        );

        /** @var array<string, list<array{column: string, type: string, nullable: string}>> $out */
        $out = [];
        foreach ($rows as $row) {
            $table = (string) ($row['TABLE_NAME'] ?? '');
            $column = (string) ($row['COLUMN_NAME'] ?? '');
            if (!$this->isSafeIdent($table) || !$this->isSafeIdent($column)) {
                continue;
            }
            $out[$table][] = [
                'column' => $column,
                'type' => (string) ($row['DATA_TYPE'] ?? ''),
                'nullable' => (string) ($row['IS_NULLABLE'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * @return list<string>
     */
    private function fetchSamples(string $table, string $column): array
    {
        $rows = $this->legacyDb->queryAll(
            'SELECT DISTINCT `' . $column . '` AS v FROM `' . $table . '` '
            . 'WHERE `' . $column . '` IS NOT NULL AND `' . $column . "` != '' "
            . 'LIMIT ' . self::MAX_SAMPLES,
        );
        $out = [];
        foreach ($rows as $row) {
            $v = (string) ($row['v'] ?? '');
            $out[] = $this->truncateSample($v);
        }
        return $out;
    }

    /**
     * @param list<string> $samples
     */
    private function formatSamples(array $samples): string
    {
        if ($samples === []) {
            return '—';
        }
        return implode(', ', array_map(
            static fn(string $s): string => '"' . str_replace('|', '\\|', $s) . '"',
            $samples,
        ));
    }

    private function truncateSample(string $v): string
    {
        $v = preg_replace('/\s+/', ' ', $v) ?? $v;
        if (mb_strlen($v) > self::SAMPLE_MAX_LEN) {
            return mb_substr($v, 0, self::SAMPLE_MAX_LEN) . '…';
        }
        return $v;
    }

    private function isSafeIdent(string $v): bool
    {
        return preg_match(self::IDENT_RX, $v) === 1;
    }
}
