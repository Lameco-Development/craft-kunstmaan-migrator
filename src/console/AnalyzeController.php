<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\console;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use lameco\kunstmaanmigrator\NeverProductionTrait;
use lameco\kunstmaanmigrator\Plugin;
use Symfony\Component\Yaml\Yaml;
use Throwable;
use yii\console\ExitCode;

/**
 * Analyze — single-action orchestration of the source-introspection + schema-dump +
 * heuristic + LLM proposal pipeline + report rendering. Collapses v1's 9-sub-action
 * AnalyzeController (2138 LOC) to one entrypoint per CONTEXT.md operator workflow.
 *
 * Pipeline (Phase 02.1 / D-42 — 11 steps; was 8 in Phase 2 / Plan 03):
 *   1.   NeverProduction gate (FIRST statement — Phase 1 / D-20)
 *   1.5  KUNSTMAAN_SOURCE_PATH gate (Phase 02.1 / D-31)
 *   2.   FilterFactory.fromCli + LocalePreflight::ensure (D-17 LOC-02)
 *   3.   KunstmaanSourceScanner::scan → entity index + table list (D-40 left side)
 *   4.   KunstmaanPageStructureScanner::scan → page-structure FQCN map (D-40 right)
 *   4.5  Page-part proposal emitter — pageStructure → mapping rows (D-35, locked here per advisor)
 *   5.   pageStructure.json write (storageDir/pageStructure.json — atomic JSON)
 *   6.   SchemaDumper::dump (consumes scan['tables']) → schema-dump.json
 *   7.   HeuristicProposer::autoMatch with heuristic 1.5 entity context (D-44)
 *   8.   LlmClassifier::batchPropose — KB markdown via v1-shaped adapter (B2 fix)
 *   9.   MappingFile::merge with skip-existing semantics (D-04 + D-34 kind-prefixed tuple)
 *   10.  MappingAuditor::audit (Plan 8 extends with BlockAvailabilityValidator)
 *   11.  ReportBuilder::render → REPORT.md
 *
 * Exit:
 *   - ExitCode::OK on green
 *   - ExitCode::CONFIG on locale-preflight FAIL or KUNSTMAAN_SOURCE_PATH unset/invalid
 *   - ExitCode::UNSPECIFIED_ERROR on any other FAIL
 *
 * --audit-strict (D-16): step 10's mapping-audit elevates non-empty drift findings
 * to fail-state when this flag is set; warn-only by default. Drift findings persist
 * to MAPPING-AUDIT.md regardless so operators always have an audit trail.
 */
class AnalyzeController extends Controller
{
    use NeverProductionTrait;

    public bool $noAi = false;
    public bool $autoAcceptHigh = false;
    public bool $auditStrict = false;
    // Phase 02.1 / D-32: declared-only here. Plan 5 wires KunstmaanSourceScanner to
    // consume this flag for drift-strict elevation (DB↔scan mismatch findings go
    // from WARN → fail when --source-strict is set; default is WARN).
    public bool $sourceStrict = false;
    public ?string $entities = null;
    public ?string $locales = null;
    public ?string $since = null;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), [
            'noAi', 'autoAcceptHigh', 'auditStrict', 'sourceStrict',
            'entities', 'locales', 'since',
        ]);
    }

    public function actionIndex(): int
    {
        // FND-04 / D-20: NeverProduction guard FIRST — before any legacy DB read.
        if (($gate = $this->enforceNeverProduction()) !== null) {
            return $gate;
        }

        $this->stdout("Analyze: scanning legacy source + schema\n", Console::FG_CYAN);

        $plugin = Plugin::getInstance();
        $filters = $plugin->filterFactory->fromCli($this->entities, $this->locales, $this->since);

        // Step 1.5 (Phase 02.1 / D-31): KUNSTMAAN_SOURCE_PATH gate. Second gate after
        // enforceNeverProduction. Greenfield-fallback was dropped per D-31; without a
        // valid source path the source-scanner cannot read entity files and analyze
        // cannot proceed.
        $sourcePath = $plugin->kunstmaanSourcePathResolver->resolve();
        if ($sourcePath === null) {
            $this->stderr(
                "  FAIL KUNSTMAAN_SOURCE_PATH unset or invalid — analyze cannot proceed.\n"
                . "       Set KUNSTMAAN_SOURCE_PATH in .env (or kunstmaanSourcePath in plugin settings).\n",
                Console::FG_RED,
            );
            return ExitCode::CONFIG;
        }
        $this->stdout("  OK   source path resolved → {$sourcePath}\n", Console::FG_GREEN);

        // Step 2: locale preflight (LOC-02 D-17 hard-fail).
        $unmapped = $plugin->localePreflight->ensure($filters);
        if ($unmapped !== null) {
            $this->stderr(
                "  FAIL unmapped Kunstmaan locales: " . implode(', ', $unmapped) . "\n",
                Console::FG_RED,
            );
            // LOC-01: surface the paste-ready Craft `sites:` block inline. REPORT.md
            // is never written on this path, so without this the operator has no
            // concrete YAML to copy.
            $detected = $plugin->localePreflight->detect();
            $this->stdout("\n" . $plugin->reportBuilder->renderLocales($detected) . "\n");
            return ExitCode::CONFIG;
        }
        $this->stdout("  OK   locale preflight\n", Console::FG_GREEN);

        $storageDir = Craft::$app->path->getStoragePath() . '/migration';

        // Step 3 (Phase 02.1 / D-42): KunstmaanSourceScanner — entity index + table list.
        try {
            $sourceScan = $plugin->kunstmaanSourceScanner->scan();
        } catch (Throwable $e) {
            $this->stderr("  FAIL source scanner: {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $entityCount = count($sourceScan['entities'] ?? []);
        $tableScanCount = count((array) ($sourceScan['tables'] ?? []));
        $this->stdout(
            "  OK   source scanned ({$entityCount} entities, {$tableScanCount} tables)\n",
            Console::FG_GREEN,
        );

        // Step 4 (Phase 02.1 / D-42): KunstmaanPageStructureScanner — pageStructure FQCN map.
        try {
            $pageStructure = $plugin->kunstmaanPageStructureScanner->scan();
        } catch (Throwable $e) {
            $this->stderr("  FAIL page-structure scanner: {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $this->stdout(
            "  OK   page structure scanned (" . count($pageStructure) . " page entities)\n",
            Console::FG_GREEN,
        );

        // Step 4.5 (Phase 02.1 / D-35, locked here per advisor): walk pageStructure and
        // emit a kind=pagePart mapping row per (pagePartClass × parentPageClass × context)
        // tuple. Plan 06's structural-only merge identity tuple ensures re-runs dedupe
        // even when the operator has filled in targetEntryType.
        $pagePartProposals = [];
        foreach ($pageStructure as $pageFqcn => $pageRecord) {
            $parentPageClass = $this->shortClassName((string) $pageFqcn);
            foreach ((array) ($pageRecord['contexts'] ?? []) as $contextRecord) {
                $contextName = (string) ($contextRecord['name'] ?? '');
                foreach ((array) ($contextRecord['allowedPagePartClasses'] ?? []) as $allowedClass) {
                    $pagePartClass = (string) ($allowedClass['class'] ?? '');
                    if ($pagePartClass === '') { continue; }
                    $shortClass = $this->shortClassName($pagePartClass);
                    $sourceTable = (string) ($allowedClass['table'] ?? '');
                    $rationale = "page-part class {$shortClass} in {$parentPageClass} context '{$contextName}'";
                    $pagePartProposals[] = $plugin->mappingFile->buildPagePartRow(
                        pagePartClass: $pagePartClass,
                        sourceTable: $sourceTable,
                        parentPageClass: $parentPageClass,
                        context: $contextName,
                        targetEntryType: '', // operator fills via map loop; structural-only merge tuple dedupes re-runs
                        confidence: 'medium',
                        rationale: $rationale,
                    );
                }
            }
        }
        $this->stdout(
            "  OK   page-part emitter produced " . count($pagePartProposals) . " proposals\n",
            Console::FG_GREEN,
        );

        // Step 5: pageStructure.json write (atomic JSON; sibling of schema-dump.json).
        $pageStructurePath = $storageDir . '/pageStructure.json';
        if (!$plugin->mappingFile->writeAtomicJson($pageStructurePath, $pageStructure)) {
            $this->stderr("  FAIL could not write {$pageStructurePath}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $this->stdout("  OK   pageStructure.json written → {$pageStructurePath}\n", Console::FG_GREEN);

        // Step 6: schema dump (consumes Phase 02.1 source-scanner table list).
        try {
            $schemaDump = $plugin->schemaDumper->dump($filters, (array) ($sourceScan['tables'] ?? []));
        } catch (Throwable $e) {
            $this->stderr("  FAIL schema dump: {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $tableCount = count($schemaDump['tables'] ?? []);
        $colCount   = array_sum(array_map('count', $schemaDump['columns'] ?? []));
        $schemaPath = $storageDir . '/schema-dump.json';
        if (!$plugin->mappingFile->writeAtomicJson($schemaPath, $schemaDump)) {
            $this->stderr("  FAIL could not write {$schemaPath}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $this->stdout("  OK   schema dumped ({$tableCount} tables, {$colCount} columns) → {$schemaPath}\n", Console::FG_GREEN);

        // Step 7: heuristic proposals.
        // HeuristicProposer::autoMatch returns [matched, residual]. The schema dump
        // alone does not carry violation rows; v1's coverage step transforms
        // schema-dump → violations. Phase 2 / Plan 03 ships only the orchestration
        // primitives — Plan 05 (CoverageAuditor) wires the schema → violations
        // transform and feeds it here. Until then, autoMatch sees an empty input
        // and returns ([], []) — heuristics produce zero proposals on a fresh run.
        $violations = $this->buildViolationsFromSchema($schemaDump);
        $craftFieldIndex = $this->buildCraftFieldIndex();

        // Phase 02.1 / D-44: wire heuristic 1.5 entity context. Load the existing
        // mapping.yaml HERE (early — same payload is reused at the merge step) so
        // accepted column rows can resolve table → targetEntryType for the entity-
        // aware match. On a fresh run with no mapping.yaml, acceptedRows is empty
        // and heuristic 1.5 falls through silently (heuristics 3-9 fire as before).
        $mappingPath = $plugin->mappingFile->resolvePath();
        $existing = $plugin->mappingFile->load($mappingPath);
        $plugin->heuristicProposer->entityIndex = (array) ($sourceScan['entities'] ?? []);
        $plugin->heuristicProposer->acceptedRows = array_values(array_filter(
            $existing['proposals'] ?? [],
            static fn(array $r): bool =>
                ((string) ($r['status'] ?? '')) === 'accepted'
                && ((string) ($r['kind'] ?? 'column')) === 'column',
        ));

        try {
            [$heuristicProposals, $residual] = $plugin->heuristicProposer->autoMatch(
                $violations,
                $craftFieldIndex,
            );
        } catch (Throwable $e) {
            $this->stderr("  FAIL heuristic proposer: {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $this->stdout(
            "  OK   heuristics produced " . count($heuristicProposals) . " proposals "
            . "(" . count($residual) . " residual for LLM)\n",
            Console::FG_GREEN,
        );

        // Step 8: LLM batch proposals for residuals (skip when --no-ai or key missing).
        // Phase 02.1 (B2 fix): KB markdown placeholders replaced with rendered Pages +
        // PageParts markdown — but only via a v1-shaped MAPPING ADAPTER built from v2's
        // flat proposals[] array. KnowledgeBase::renderPagesMarkdown / renderPagePartsMarkdown
        // read v1's nested keys ($mapping['pageParts'] / ['nodeClasses'] / ['sections']);
        // without the adapter, every per-row annotation would be silently lost (placeholders
        // would be replaced but the mapping overlay would render empty).
        $llmProposals = [];
        $apiKey = (string) ($plugin->getSettings()->anthropicApiKey ?? '');
        $skipLlm = $this->noAi || $apiKey === '' || $residual === [];
        if ($skipLlm) {
            $reason = $this->noAi
                ? '--no-ai set'
                : ($apiKey === '' ? 'ANTHROPIC_API_KEY not set' : 'no residual columns');
            $this->stdout(
                "  WARN LLM call skipped ({$reason}) — residual columns land status: needs-review\n",
                Console::FG_YELLOW,
            );
        } else {
            try {
                // Build v1-shaped KB mapping adapter from v2's flat proposals[] (B2 fix).
                // Pre-LLM proposals = heuristic-matched rows + emitted page-part rows.
                // Heuristic rows lack an explicit `kind` discriminator and default to
                // 'column' inside the adapter bucketer.
                $preLlmProposals = array_merge($heuristicProposals, $pagePartProposals);
                $adapter = self::buildKbMappingAdapter($preLlmProposals);
                $now = new \DateTimeImmutable();
                $kbPages     = $plugin->knowledgeBase->renderPagesMarkdown($adapter, $now);
                $kbPageParts = $plugin->knowledgeBase->renderPagePartsMarkdown($adapter, $now);
                $llmProposals = $plugin->llmClassifier->batchPropose(
                    $residual,
                    $craftFieldIndex,
                    $kbPages . "\n\n" . $kbPageParts,
                    '', // Craft-side KB stays empty until Phase 4 / verify
                );
                $this->stdout(
                    "  OK   LLM produced " . count($llmProposals) . " proposals\n",
                    Console::FG_GREEN,
                );
            } catch (Throwable $e) {
                $this->stderr("  FAIL LLM classifier: {$e->getMessage()}\n", Console::FG_RED);
                return ExitCode::UNSPECIFIED_ERROR;
            }
        }

        // Step 9 (build rows): D-02 confidence-tier → status assignment + page-part rows.
        // Page-part rows produced by step 4.5 are already row-shaped (buildPagePartRow
        // returns a status:needs-review row per D-35); they merge in alongside column rows.
        $rows = [];
        foreach ($heuristicProposals as $p) {
            $rows[] = $plugin->mappingFile->buildRow($p, $this->statusForHeuristic($p));
        }
        foreach ($llmProposals as $p) {
            $rows[] = $plugin->mappingFile->buildRow($p, $this->statusForLlm($p, $skipLlm));
        }
        foreach ($pagePartProposals as $p) {
            $rows[] = $p;
        }
        // For LLM-skipped residuals (no LLM call, no proposals returned), still
        // emit a row with status: needs-review so the operator sees the gap.
        if ($skipLlm && $residual !== []) {
            foreach ($residual as $v) {
                $stub = [
                    'table'           => (string) ($v['table'] ?? ''),
                    'column'          => (string) ($v['column'] ?? ''),
                    'targetEntryType' => (string) ($v['targetEntryType'] ?? ''),
                    'targetHandle'    => '',
                    'handler'         => '',
                    'confidence'      => 'low',
                    'rationale'       => 'LLM call skipped (--no-ai or no API key)',
                    'fillRate'        => (float) ($v['fillRate'] ?? 0),
                    'sqlType'         => (string) ($v['sqlType'] ?? ''),
                    'samples'         => (array) ($v['samples'] ?? []),
                ];
                $rows[] = $plugin->mappingFile->buildRow($stub, 'needs-review');
            }
        }

        // Apply --auto-accept-high: promote LLM-high proposed → accepted (MAP-05).
        if ($this->autoAcceptHigh) {
            foreach ($rows as &$r) {
                if (($r['status'] ?? '') === 'proposed' && ($r['confidence'] ?? '') === 'high') {
                    $r['status'] = 'accepted';
                }
            }
            unset($r);
        }

        // Step 9 cont. (skip-existing merge — D-04 + D-34 kind-prefixed identity tuple).
        // $mappingPath / $existing were loaded earlier (step 7) so heuristic 1.5 could
        // populate acceptedRows; reuse the same payload here without a second disk read.
        $merged = $plugin->mappingFile->merge($existing, $rows);

        $yaml = Yaml::dump($merged, 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
        if (!$plugin->mappingFile->writeAtomic($mappingPath, $yaml)) {
            $this->stderr("  FAIL could not write {$mappingPath}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $this->stdout(
            "  OK   mapping.yaml updated (" . count($merged['proposals']) . " rows total) → {$mappingPath}\n",
            Console::FG_GREEN,
        );

        // Step 10: mapping-audit (D-16) + D-32 drift section + D-36 block-availability.
        // Warn-only by default; --audit-strict elevates FieldLayout findings; --source-strict
        // elevates non-empty drift findings. Either flag alone elevates only its own kind.
        // T-2-21 mitigation: MAPPING-AUDIT.md is written via writeAtomic BEFORE any
        // strict-mode short-circuit return so operators always have the on-disk trail
        // regardless of exit code.
        $findings = $plugin->mappingAuditor->audit($merged['proposals']);
        $driftFindings = (array) ($sourceScan['drift'] ?? []);
        $auditPath = $storageDir . '/MAPPING-AUDIT.md';
        $auditMd = $plugin->mappingAuditor->renderMarkdown($findings, $driftFindings);
        if (!$plugin->mappingFile->writeAtomic($auditPath, $auditMd)) {
            $this->stderr("  FAIL could not write {$auditPath}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        // FieldLayout findings outcome.
        if ($findings === []) {
            $this->stdout("  OK   mapping audit clean → {$auditPath}\n", Console::FG_GREEN);
        } else {
            $n = count($findings);
            if ($this->auditStrict) {
                $this->stderr(
                    "  FAIL mapping audit: {$n} drift finding(s) (--audit-strict) → {$auditPath}\n",
                    Console::FG_RED,
                );
                return ExitCode::UNSPECIFIED_ERROR;
            }
            $this->stdout(
                "  WARN mapping audit: {$n} drift finding(s) → {$auditPath}\n",
                Console::FG_YELLOW,
            );
        }
        // D-32 drift outcome (DB↔scan mismatch from KunstmaanSourceScanner).
        $dbExtra = count((array) ($driftFindings['dbHasButScanMissing'] ?? []));
        $scanExtra = count((array) ($driftFindings['scanHasButDbMissing'] ?? []));
        if ($dbExtra > 0 || $scanExtra > 0) {
            if ($this->sourceStrict) {
                $this->stderr(
                    "  FAIL source drift: {$dbExtra} DB-extra / {$scanExtra} scan-extra table(s) (--source-strict) → {$auditPath}\n",
                    Console::FG_RED,
                );
                return ExitCode::UNSPECIFIED_ERROR;
            }
            $this->stdout(
                "  WARN source drift: {$dbExtra} DB-extra / {$scanExtra} scan-extra table(s) → {$auditPath}\n",
                Console::FG_YELLOW,
            );
        }

        // Step 11: REPORT.md
        $reportPath = $storageDir . '/REPORT.md';
        $report = $plugin->reportBuilder->render($schemaDump, $merged['proposals']);
        if (!$plugin->mappingFile->writeAtomic($reportPath, $report)) {
            $this->stderr("  FAIL could not write {$reportPath}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $this->stdout("  OK   REPORT.md written → {$reportPath}\n", Console::FG_GREEN);

        $this->stdout("\nAnalyze: PASS\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    /**
     * D-02: heuristic confidence → initial status.
     * @param array<string, mixed> $proposal
     */
    private function statusForHeuristic(array $proposal): string
    {
        $confidence = (string) ($proposal['confidence'] ?? '');
        $decision = (string) ($proposal['decision'] ?? '');
        if ($decision === 'drop') {
            return 'dropped'; // zero-fill heuristic auto-drop (D-02 last row)
        }
        return $confidence === 'high' ? 'accepted' : 'proposed';
    }

    /**
     * D-02 + --no-ai semantics: LLM confidence → initial status; skip-LLM rows get needs-review.
     * @param array<string, mixed> $proposal
     */
    private function statusForLlm(array $proposal, bool $skipped): string
    {
        if ($skipped) {
            return 'needs-review';
        }
        $confidence = (string) ($proposal['confidence'] ?? 'low');
        return $confidence === 'high' ? 'proposed' : 'needs-review';
    }

    /**
     * Build heuristic-input violation rows from the schema dump.
     *
     * Phase 2 / Plan 03 ships only the orchestration primitives — Plan 05's
     * CoverageAuditor will produce a more accurate violation set by cross-
     * referencing existing mapping.yaml coverage and Craft FieldLayouts.
     *
     * For now, every column in every dumped table becomes a candidate
     * violation. The heuristic auto-drops zero-fill columns and matches the
     * rest against the (currently-empty) Craft field index.
     *
     * @param array<string, mixed> $schemaDump
     * @return list<array<string, mixed>>
     */
    private function buildViolationsFromSchema(array $schemaDump): array
    {
        $out = [];
        $columnsByTable = (array) ($schemaDump['columns'] ?? []);
        foreach ($columnsByTable as $table => $cols) {
            if (!is_array($cols)) { continue; }
            foreach ($cols as $c) {
                if (!is_array($c)) { continue; }
                $out[] = [
                    'table'           => (string) $table,
                    'column'          => (string) ($c['column'] ?? ''),
                    'targetEntryType' => '', // populated by Plan 05 CoverageAuditor
                    'fillRate'        => (float) ($c['fillRate'] ?? 0),
                    'sqlType'         => (string) ($c['sqlType'] ?? ''),
                    'samples'         => (array) ($c['samples'] ?? []),
                ];
            }
        }
        return $out;
    }

    /**
     * Build the Craft field index keyed by entry type.
     *
     * Phase 2 / Plan 03 ships an empty index — Plan 05 wires the live
     * Craft FieldLayout walk per CONTEXT.md MappingAuditor pattern. With an
     * empty index, the heuristic only fires the zero-fill auto-drop rule
     * (heuristic 1) and routes everything else to LLM residual (or to the
     * needs-review skip-stub when LLM is disabled).
     *
     * @return array<string, list<array{handle: string, type: string, classification?: string}>>
     */
    private function buildCraftFieldIndex(): array
    {
        return [];
    }

    /**
     * Resolve a class FQCN to its short name. Tries ReflectionClass first (handles
     * autoloaded classes correctly); falls back to a string split on the last `\`
     * when the class is not loadable (operator's source file may reference a class
     * that doesn't exist on the consumer's autoload path — T-02.1-07-01 mitigation).
     *
     * ReflectionClass does NOT autoload via include/require for getShortName(); it
     * only consults the loaded class table. Safe to call on operator-supplied FQCNs.
     */
    private function shortClassName(string $fqcn): string
    {
        try {
            return (new \ReflectionClass($fqcn))->getShortName();
        } catch (\ReflectionException) {
            $tail = strrchr($fqcn, '\\');
            return $tail !== false ? substr($tail, 1) : $fqcn;
        }
    }

    /**
     * Build a v1-shaped KB mapping adapter from v2's flat `proposals[]` array (B2 fix).
     *
     * KnowledgeBase::renderPagesMarkdown / renderPagePartsMarkdown read v1's nested
     * mapping shape:
     *   - $mapping['pageParts']   — keyed by pagePart FQCN
     *   - $mapping['nodeClasses'] — keyed by Page entity FQCN
     *   - $mapping['sections']    — keyed by section handle (column rows grouped)
     *
     * v2 stores rows in a FLAT `proposals[]` array — without this adapter, every
     * per-row annotation is silently lost (the placeholders would be replaced but
     * the mapping overlay would render empty).
     *
     * Buckets:
     *   - kind=pagePart rows → 'pageParts' keyed by pagePartClass FQCN
     *   - kind=column rows   → 'sections' keyed by targetEntryType
     *                          ('_unmapped' bucket for column rows lacking targetEntryType)
     *
     * 'nodeClasses' is reserved for Page entity FQCN annotations (v1 sources this
     * from a dedicated YAML key v2 doesn't ship). Leaving it empty is correct —
     * KnowledgeBase simply renders no per-Page annotations. Future work: derive
     * from accepted column rows whose table maps to a Page entity via heuristic 1.5.
     *
     * Public + static so the unit test (AnalyzeControllerKbAdapterTest) can call
     * it without instantiating the controller (which requires Craft's Yii base
     * wiring not available in the unit-test bootstrap).
     *
     * @param list<array<string, mixed>> $proposals  Pre-LLM proposals (heuristic-matched + emitted page-part rows)
     * @return array{pageParts: array<string, array<string, mixed>>, nodeClasses: array<string, mixed>, sections: array<string, list<array<string, mixed>>>}
     */
    public static function buildKbMappingAdapter(array $proposals): array
    {
        $adapter = [
            'pageParts'   => [],
            'nodeClasses' => [],
            'sections'    => [],
        ];
        foreach ($proposals as $row) {
            if (!is_array($row)) { continue; }
            $kind = (string) ($row['kind'] ?? 'column');
            if ($kind === 'pagePart') {
                $key = (string) ($row['pagePartClass'] ?? '');
                if ($key !== '') {
                    $adapter['pageParts'][$key] = $row;
                }
                continue;
            }
            // Column rows (default when 'kind' is unset — heuristic-matched proposals
            // come from autoMatch() which doesn't carry an explicit kind).
            $section = (string) ($row['targetEntryType'] ?? '');
            if ($section === '') { $section = '_unmapped'; }
            $adapter['sections'][$section][] = $row;
        }
        return $adapter;
    }
}
