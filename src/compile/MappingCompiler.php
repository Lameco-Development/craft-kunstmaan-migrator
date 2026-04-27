<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\compile;

use yii\base\Component;

/**
 * MappingCompiler — derives the v1-shaped runtime ETL contract
 * (`nodeClasses` / `sections` / `sites`) from v2's flat `proposals[]` audit
 * trail + the page-structure scan emitted by analyze.
 *
 * Why this exists: v2 keeps `mapping.yaml` as a flat list of per-column
 * decisions (great for the rubber-stamp loop and for proposal traceability).
 * The ETL services (ExtractService, TransformService, EntryMigrationService)
 * however still consume v1's nested shape — `nodeClasses[fqcn].sourceTable`,
 * `sections[handle].entryType`, `sites[locale]`. Without a step that bridges
 * the two shapes the migrate pipeline reads `[]` for nodeClasses and
 * extracts zero rows. v1 expected the operator to hand-author these blocks
 * (the v1 AnalyzeController literally printed `# ---- paste this above
 * nodeClasses: ----`); v2 inherited the contract but lost the prompt and
 * never shipped the bridge.
 *
 * Pure logic — no I/O, no DB, no Craft. Caller (CompileController) loads
 * mapping.yaml + pageStructure.json + Settings::localeMap, calls compile(),
 * and writes the result back via MappingFile::writeAtomic.
 *
 * Scope (v1.0):
 *   - Derives `nodeClasses[fqcn]` from pageStructure entries:
 *       sourceTable from pageStructure.tableName
 *       section keyed by the dominant `targetEntryType` across that fqcn's
 *         accepted column rows (heuristic: "majority vote"; ties broken by
 *         alphabetical order to keep output stable across runs)
 *       fields[targetHandle] from accepted column rows whose targetEntryType
 *         matches the section
 *   - Derives `sections[entryType]` from accepted column rows' targetEntryType
 *       distinct values; entryType handle is the same as the section key
 *       (operators can rename later)
 *   - Derives `sites[locale]` from a passed-in operator-curated map (Settings
 *       ::localeMap) — does NOT auto-discover Craft sites here (that's the
 *       caller's job; the compiler is a pure transform)
 *
 * NOT in scope (operator hand-edits these on the compiled output):
 *   - pageBuilderHandle / pageBuilderContexts (page-part composition mapping)
 *   - headerBlock / bodyWrapBlock / bodyColumn (entry-type page-part layout)
 *   - joins (multi-table FK joins beyond the FK auto-discovery in extract)
 *   - SKIP actions (operator marks nodeClasses they explicitly opt out of)
 *
 * The compiled output emits placeholder/stub keys for the un-derived fields
 * so operators see the gaps and can fill them inline rather than hunting
 * through docs to discover what's missing.
 */
final class MappingCompiler extends Component
{
    /**
     * Compile v1-shaped runtime structures from v2 proposals + page structure.
     *
     * @param array<string, mixed> $mapping        Existing mapping.yaml contents (must contain `proposals` key)
     * @param array<string, mixed> $pageStructure  pageStructure.json contents (FQCN-keyed)
     * @param array<string, string> $sites         Operator-curated locale → Craft-site-handle map
     * @return array{
     *   proposals: list<array<string, mixed>>,
     *   nodeClasses: array<string, array<string, mixed>>,
     *   sections: array<string, array<string, mixed>>,
     *   sites: array<string, string>,
     *   _compileReport: array{
     *     nodeClassesEmitted: int,
     *     sectionsEmitted: int,
     *     fieldsEmittedPerSection: array<string, int>,
     *     skippedNodeClasses: list<string>,
     *     warnings: list<string>,
     *   },
     * }
     */
    public function compile(array $mapping, array $pageStructure, array $sites): array
    {
        $proposals = (array) ($mapping['proposals'] ?? []);

        // First pass: auto-assign targetEntryType on rows that lack one but whose
        // source table maps to a known FQCN in pageStructure. Workaround for the
        // AnalyzeController gap where buildViolationsFromSchema ships
        // `targetEntryType => ''` and CoverageAuditor wiring was never finished
        // (Plan 03 → Plan 05 carry-over). We derive the entry-type handle from
        // the FQCN basename (NewsPage → newsPage). Operators who want different
        // handles can edit mapping.yaml and re-run compile with --overwrite.
        $tableToEntryType = $this->buildTableToEntryTypeMap($pageStructure);
        [$proposals, $autoAssigned] = $this->autoAssignTargetEntryType($proposals, $tableToEntryType);

        $accepted = $this->filterAccepted($proposals);

        // Group accepted column rows by targetEntryType.
        $byEntryType = $this->groupByEntryType($accepted);

        // Build sections[]: one entry per distinct targetEntryType.
        $sections = [];
        foreach (array_keys($byEntryType) as $entryType) {
            $sections[$entryType] = [
                'entryType' => $entryType,
                'section'   => $entryType, // default: section handle == entryType handle (operator can rename)
            ];
        }
        ksort($sections);

        // Build nodeClasses[]: one entry per FQCN in pageStructure that has
        // at least one accepted column row whose source table matches.
        $nodeClasses = [];
        $skipped = [];
        $warnings = [];
        $fieldsPerSection = [];

        foreach ($pageStructure as $fqcn => $pageInfo) {
            if (!is_string($fqcn) || !is_array($pageInfo)) {
                continue;
            }
            $sourceTable = (string) ($pageInfo['tableName'] ?? '');
            if ($sourceTable === '') {
                $skipped[] = $fqcn . ' (no tableName in pageStructure)';
                continue;
            }

            // Find accepted rows whose source table matches this FQCN's table,
            // keyed by targetEntryType to compute the majority section.
            $tableRows = array_values(array_filter(
                $accepted,
                static fn(array $r): bool => (string) ($r['table'] ?? '') === $sourceTable,
            ));

            if ($tableRows === []) {
                $skipped[] = $fqcn . ' (no accepted columns for table ' . $sourceTable . ')';
                continue;
            }

            // Majority vote on targetEntryType; alphabetical tiebreak for stability.
            $entryTypeCounts = [];
            foreach ($tableRows as $r) {
                $et = (string) ($r['targetEntryType'] ?? '');
                if ($et === '') {
                    continue;
                }
                $entryTypeCounts[$et] = ($entryTypeCounts[$et] ?? 0) + 1;
            }
            if ($entryTypeCounts === []) {
                $skipped[] = $fqcn . ' (no targetEntryType assigned on any accepted column)';
                continue;
            }
            $sectionKey = $this->majorityKey($entryTypeCounts);

            // Build fields[targetHandle] from accepted rows whose targetEntryType matches.
            $sectionRows = array_values(array_filter(
                $tableRows,
                static fn(array $r): bool => (string) ($r['targetEntryType'] ?? '') === $sectionKey,
            ));
            $fields = [];
            foreach ($sectionRows as $r) {
                $targetHandle = (string) ($r['targetHandle'] ?? '');
                if ($targetHandle === '') {
                    continue;
                }
                if (isset($fields[$targetHandle])) {
                    // Multiple source columns mapped to same target handle — operator
                    // must disambiguate. Keep the first; warn.
                    $warnings[] = sprintf(
                        '%s: multiple source columns mapped to %s.%s — kept %s (column %s ignored)',
                        $fqcn,
                        $sectionKey,
                        $targetHandle,
                        (string) $fields[$targetHandle]['source'],
                        (string) ($r['column'] ?? '?'),
                    );
                    continue;
                }
                $fields[$targetHandle] = [
                    'handler' => (string) ($r['handler'] ?? 'plain'),
                    'source'  => (string) ($r['column'] ?? $targetHandle),
                ];
            }
            ksort($fields);

            // Stub un-derived keys so operator sees the gaps.
            $nodeClasses[$fqcn] = [
                'sourceTable'         => $sourceTable,
                'section'             => $sectionKey,
                'fields'              => $fields,
                // Operator-curated below this line — leave empty stubs.
                'pageBuilderHandle'   => '',
                'pageBuilderContexts' => array_map(
                    static fn(array $c): string => (string) ($c['name'] ?? ''),
                    (array) ($pageInfo['contexts'] ?? []),
                ),
                'bodyColumn'          => '',
                'headerBlock'         => null,
                'bodyWrapBlock'       => null,
                'joins'               => [],
            ];

            $fieldsPerSection[$sectionKey] = ($fieldsPerSection[$sectionKey] ?? 0) + count($fields);
        }
        ksort($nodeClasses);

        // Sites: operator-curated; pass through unchanged.
        $sitesOut = [];
        foreach ($sites as $locale => $handle) {
            if (is_string($locale) && is_string($handle) && $locale !== '' && $handle !== '') {
                $sitesOut[$locale] = $handle;
            }
        }
        ksort($sitesOut);
        if ($sitesOut === []) {
            $warnings[] = 'No sites map provided — Settings::localeMap is empty. Migrate cannot resolve per-locale Craft site IDs without it.';
        }

        return [
            'proposals'    => array_values($proposals),
            'nodeClasses'  => $nodeClasses,
            'sections'     => $sections,
            'sites'        => $sitesOut,
            '_compileReport' => [
                'nodeClassesEmitted'      => count($nodeClasses),
                'sectionsEmitted'         => count($sections),
                'fieldsEmittedPerSection' => $fieldsPerSection,
                'skippedNodeClasses'      => $skipped,
                'autoAssignedTargets'     => $autoAssigned,
                'warnings'                => $warnings,
            ],
        ];
    }

    /**
     * Build sourceTable → entryTypeHandle lookup from pageStructure entries.
     * Entry-type handle is derived from the FQCN basename via lcfirst (so
     * `App\Entity\Pages\NewsPage` → `newsPage`). Operators can rename handles
     * after the fact in the resulting mapping.yaml.
     *
     * @param  array<string, mixed> $pageStructure
     * @return array<string, string>  table → entryType
     */
    private function buildTableToEntryTypeMap(array $pageStructure): array
    {
        $out = [];
        foreach ($pageStructure as $fqcn => $info) {
            if (!is_string($fqcn) || !is_array($info)) {
                continue;
            }
            $table = (string) ($info['tableName'] ?? '');
            if ($table === '') {
                continue;
            }
            $parts = explode('\\', trim($fqcn, '\\'));
            $basename = end($parts);
            if (!is_string($basename) || $basename === '') {
                continue;
            }
            $out[$table] = lcfirst($basename);
        }
        return $out;
    }

    /**
     * Backfill empty `targetEntryType` on column proposals whose source
     * `table` matches a known table → entryType lookup.
     *
     * @param  list<array<string, mixed>> $proposals
     * @param  array<string, string>      $tableToEntryType
     * @return array{0: list<array<string, mixed>>, 1: int}  [updated proposals, count assigned]
     */
    private function autoAssignTargetEntryType(array $proposals, array $tableToEntryType): array
    {
        $assigned = 0;
        foreach ($proposals as &$r) {
            if (!is_array($r)) {
                continue;
            }
            if (((string) ($r['kind'] ?? 'column')) !== 'column') {
                continue;
            }
            $current = (string) ($r['targetEntryType'] ?? '');
            if ($current !== '') {
                continue;
            }
            $table = (string) ($r['table'] ?? '');
            if (!isset($tableToEntryType[$table])) {
                continue;
            }
            $r['targetEntryType'] = $tableToEntryType[$table];
            $assigned++;
        }
        unset($r);
        return [$proposals, $assigned];
    }

    /**
     * Keep only accepted column proposals (kind=column, status=accepted).
     * Drops/needs-review/proposed and pagePart rows are excluded — the ETL
     * cares about column→field mappings only.
     *
     * @param  list<array<string, mixed>> $proposals
     * @return list<array<string, mixed>>
     */
    private function filterAccepted(array $proposals): array
    {
        $out = [];
        foreach ($proposals as $r) {
            if (!is_array($r)) {
                continue;
            }
            if (((string) ($r['kind'] ?? 'column')) !== 'column') {
                continue;
            }
            if (((string) ($r['status'] ?? '')) !== 'accepted') {
                continue;
            }
            $out[] = $r;
        }
        return $out;
    }

    /**
     * Group accepted column rows by targetEntryType. Empty targetEntryType
     * is dropped (it's a "no-section" decision — column accepted as
     * structurally-irrelevant metadata).
     *
     * @param  list<array<string, mixed>> $accepted
     * @return array<string, list<array<string, mixed>>>
     */
    private function groupByEntryType(array $accepted): array
    {
        $byEt = [];
        foreach ($accepted as $r) {
            $et = (string) ($r['targetEntryType'] ?? '');
            if ($et === '') {
                continue;
            }
            $byEt[$et][] = $r;
        }
        return $byEt;
    }

    /**
     * Return the key with the highest count; ties broken by alphabetical
     * ordering for stable output across runs.
     *
     * @param  array<string, int> $counts
     */
    private function majorityKey(array $counts): string
    {
        $max = 0;
        $winners = [];
        foreach ($counts as $k => $n) {
            if ($n > $max) {
                $max = $n;
                $winners = [$k];
            } elseif ($n === $max) {
                $winners[] = $k;
            }
        }
        sort($winners);
        return (string) ($winners[0] ?? '');
    }
}
