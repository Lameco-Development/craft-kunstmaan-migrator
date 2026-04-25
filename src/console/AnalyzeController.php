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
 * Analyze — single-action orchestration of the schema-dump + heuristic + LLM
 * proposal pipeline + report rendering. Collapses v1's 9-sub-action
 * AnalyzeController (2138 LOC) to one entrypoint per CONTEXT.md operator
 * workflow.
 *
 * Pipeline:
 *   1. NeverProduction gate (FIRST statement — Phase 1 / D-20)
 *   2. Build MigrationFilters from CLI flags + Settings::default*
 *   3. LocalePreflight::ensure — hard-fail on unmapped locales (D-17 LOC-02)
 *   4. SchemaDumper::dump → schema-dump.json
 *   5. HeuristicProposer::autoMatch → high-confidence rows status: accepted (D-02)
 *   6. LlmClassifier::batchPropose for residuals (skip when --no-ai or no API key)
 *   7. MappingFile::merge with skip-existing semantics (D-04)
 *   8. ReportBuilder::render → REPORT.md
 *
 * Exit:
 *   - ExitCode::OK on green
 *   - ExitCode::CONFIG on locale-preflight FAIL
 *   - ExitCode::UNSPECIFIED_ERROR on any other FAIL
 *
 * --audit-strict (D-16): the mapping-audit step (Step 7) elevates non-empty drift
 * findings to fail-state when this flag is set; warn-only by default. Drift findings
 * are persisted to MAPPING-AUDIT.md regardless so operators always have an audit trail.
 */
class AnalyzeController extends Controller
{
    use NeverProductionTrait;

    public bool $noAi = false;
    public bool $autoAcceptHigh = false;
    public bool $auditStrict = false;
    public ?string $entities = null;
    public ?string $locales = null;
    public ?string $since = null;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), [
            'noAi', 'autoAcceptHigh', 'auditStrict',
            'entities', 'locales', 'since',
        ]);
    }

    public function actionIndex(): int
    {
        // FND-04 / D-20: NeverProduction guard FIRST — before any legacy DB read.
        if (($gate = $this->enforceNeverProduction()) !== null) {
            return $gate;
        }

        $this->stdout("Analyze: scanning legacy schema\n", Console::FG_CYAN);

        $plugin = Plugin::getInstance();
        $filters = $plugin->filterFactory->fromCli($this->entities, $this->locales, $this->since);

        // Step 1: locale preflight (LOC-02 D-17 hard-fail).
        $unmapped = $plugin->localePreflight->ensure($filters);
        if ($unmapped !== null) {
            $this->stderr(
                "  FAIL unmapped Kunstmaan locales: " . implode(', ', $unmapped) . "\n"
                . "       Add them to config/sites.php or Settings::defaultLocales, then re-run.\n",
                Console::FG_RED,
            );
            return ExitCode::CONFIG;
        }
        $this->stdout("  OK   locale preflight\n", Console::FG_GREEN);

        // Step 2: schema dump.
        try {
            $schemaDump = $plugin->schemaDumper->dump($filters);
        } catch (Throwable $e) {
            $this->stderr("  FAIL schema dump: {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $tableCount = count($schemaDump['tables'] ?? []);
        $colCount   = array_sum(array_map('count', $schemaDump['columns'] ?? []));
        $storageDir = Craft::$app->path->getStoragePath() . '/migration';
        $schemaPath = $storageDir . '/schema-dump.json';
        if (!$plugin->mappingFile->writeAtomicJson($schemaPath, $schemaDump)) {
            $this->stderr("  FAIL could not write {$schemaPath}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $this->stdout("  OK   schema dumped ({$tableCount} tables, {$colCount} columns) → {$schemaPath}\n", Console::FG_GREEN);

        // Step 3: heuristic proposals.
        // HeuristicProposer::autoMatch returns [matched, residual]. The schema dump
        // alone does not carry violation rows; v1's coverage step transforms
        // schema-dump → violations. Phase 2 / Plan 03 ships only the orchestration
        // primitives — Plan 05 (CoverageAuditor) wires the schema → violations
        // transform and feeds it here. Until then, autoMatch sees an empty input
        // and returns ([], []) — heuristics produce zero proposals on a fresh run.
        $violations = $this->buildViolationsFromSchema($schemaDump);
        $craftFieldIndex = $this->buildCraftFieldIndex();
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

        // Step 4: LLM batch proposals for residuals (skip when --no-ai or key missing).
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
                // KB markdown is empty in Phase 2 / Plan 03 — Plan 05 (or later) wires
                // the Kunstmaan + Craft KB sources. The classifier tolerates empty
                // strings (truncate is a no-op).
                $llmProposals = $plugin->llmClassifier->batchPropose(
                    $residual,
                    $craftFieldIndex,
                    '',
                    '',
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

        // Step 5: build rows with D-02 confidence-tier → status assignment.
        $rows = [];
        foreach ($heuristicProposals as $p) {
            $rows[] = $plugin->mappingFile->buildRow($p, $this->statusForHeuristic($p));
        }
        foreach ($llmProposals as $p) {
            $rows[] = $plugin->mappingFile->buildRow($p, $this->statusForLlm($p, $skipLlm));
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

        // Step 6: skip-existing merge (D-04 — operator decisions sacred).
        $mappingPath = $plugin->mappingFile->resolvePath();
        $existing = $plugin->mappingFile->load($mappingPath);
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

        // Step 7: mapping-audit (D-16). Warn-only by default; --audit-strict elevates.
        $findings = $plugin->mappingAuditor->audit($merged['proposals']);
        $auditPath = $storageDir . '/MAPPING-AUDIT.md';
        $auditMd = $plugin->mappingAuditor->renderMarkdown($findings);
        if (!$plugin->mappingFile->writeAtomic($auditPath, $auditMd)) {
            $this->stderr("  FAIL could not write {$auditPath}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
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

        // Step 8: REPORT.md
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
}
