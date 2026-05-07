<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\console;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use lameco\kunstmaanmigrator\filter\FilterFactory;
use lameco\kunstmaanmigrator\filter\MappingFilterTranslator;
use lameco\kunstmaanmigrator\filter\MigrationFilters;
use lameco\kunstmaanmigrator\NeverProductionTrait;
use lameco\kunstmaanmigrator\Plugin;
use lameco\kunstmaanmigrator\verify\CountGateService;
use lameco\kunstmaanmigrator\workflow\VerifyWorkflow;
use Throwable;
use yii\console\ExitCode;

/**
 * `verify` console controller — D-58 hybrid parity gate.
 *
 * Three actions under the shared NeverProductionTrait gate (Phase 1 / D-20):
 *   verify/index                  — Gate 1 (counts) + Gate 2 (URL diff),
 *                                   emit storage/migration/VERIFY-<ts>.md (D-61)
 *   verify/capture-baseline       — light counts via BaselineCounterService → baseline.json (D-59)
 *   verify/capture-baseline-html  — fetch URLs into <baselineDir>/<slug>.html
 *
 * v2 reshapes from v1 VerifyController (~/Sites/craft-kunstmaan-migrator):
 *   - Tolerance source (D-60): $countTolerance ?? Settings::$verifyCountTolerance ?? 0.01;
 *     mapping.yaml stays clean of verify config.
 *   - Baseline path (D-59): storage/migration/baseline.json (stable, NOT timestamped).
 *   - Markdown-only artifact (D-61): VERIFY-<ts>.md, no JSON sidecar.
 *   - Atomic write (Phase 2 / D-07): MappingFile::writeAtomic / writeAtomicJson via tmp+rename.
 *   - --entities / --locales / --since: ROADMAP Phase 2 success criterion 5 — uniform CLI surface
 *     across the five top-level commands. Filters parsed via FilterFactory::fromCli at action entry;
 *     CountGateService / BaselineCounterService remain filter-naive at v1.0 (carve-out).
 *
 * Missing-baseline semantic (D-58): Gate 1 emits "WARN no-baseline" + flips overall pass to false
 * when baseline.json is absent. Optional-plugin gates (seomatic, retour) emit `SKIP` rows in the
 * markdown report when CountGateService returns `skip => true`.
 */
class VerifyController extends Controller
{
    use NeverProductionTrait;

    public bool $verbose          = false;
    public ?string $baseline      = null;
    public ?string $output        = null;
    public ?string $outputDir     = null;
    public ?string $baselineDir   = null;
    public ?float $countTolerance   = null;  // D-60: CLI override; null → use Settings default.
    public ?float $urlDiffThreshold = null;  // D-60: CLI override; null → use Settings default.
    public ?string $urlSpotCheck    = null;  // path to spot-check-urls.txt

    // ROADMAP Phase 2 success criterion 5: five top-level commands all accept --entities / --locales / --since.
    // Mirrors AnalyzeController (Phase 02.1). The flags parse via FilterFactory::fromCli at action entry.
    // Phase 4.1 / VER-04 closes the previous Phase-4 carve-out: $filters now flows into
    // CountGateService::run() and BaselineCounterService::capture() — the verify gate is
    // filter-aware (sections excluded by entities allow-list emit SKIP rows; entry counts
    // are scoped by Settings::$localeMap → siteId when locales are specified).
    public ?string $entities = null;
    public ?string $locales  = null;
    public ?string $since    = null;

    public function options($actionID): array
    {
        $base = ['verbose'];
        if ($actionID === 'index') {
            // index runs Gate 1 (counts) + Gate 2 (URL diff). Filter flags scope reporting.
            $base = array_merge($base, [
                'baseline', 'urlSpotCheck', 'baselineDir', 'countTolerance', 'urlDiffThreshold',
                'entities', 'locales', 'since',
            ]);
        }
        if ($actionID === 'capture-baseline') {
            // capture-baseline writes the counts snapshot. Filter flags accepted for CLI uniformity
            // (criterion 5); current BaselineCounterService::capture() is filter-naive.
            $base = array_merge($base, ['output', 'entities', 'locales', 'since']);
        }
        if ($actionID === 'capture-baseline-html') {
            // URL-list-driven; filter flags accepted for CLI uniformity but not load-bearing —
            // spot-check-urls.txt is operator-curated.
            $base = array_merge($base, ['outputDir', 'urlSpotCheck', 'entities', 'locales', 'since']);
        }
        return array_merge(parent::options($actionID), $base);
    }

    public function optionAliases(): array
    {
        return array_merge(parent::optionAliases(), ['v' => 'verbose']);
    }

    /**
     * D-58 hybrid gate:
     *   Gate 1 — count-match (±countTolerance) sections + assets + plugins + taxonomies
     *   Gate 2 — URL HTML diff against captured baseline (B1 fix lives in SpotCheckUrlFetcher)
     *
     * Exits non-zero if either gate fails. Always writes storage/migration/VERIFY-<ts>.md
     * (D-61, VER-03 success criterion: report writes regardless of pass/fail).
     */
    public function actionIndex(): int
    {
        if (($gate = $this->enforceNeverProduction()) !== null) {
            return $gate;
        }

        $result = (new VerifyWorkflow())->run([
            'baseline' => $this->baseline,
            'urlSpotCheck' => $this->urlSpotCheck,
            'baselineDir' => $this->baselineDir,
            'countTolerance' => $this->countTolerance,
            'urlDiffThreshold' => $this->urlDiffThreshold,
            'entities' => $this->entities,
            'locales' => $this->locales,
            'since' => $this->since,
            'captureBaseline' => false,
            'captureBaselineHtml' => false,
            'output' => $this->output,
            'outputDir' => $this->outputDir,
        ], function (array $event): void {
            $stream = (string) ($event['stream'] ?? 'stdout');
            $message = (string) ($event['message'] ?? '');
            if ($stream === 'stderr') {
                $this->stderr($message);
                return;
            }
            $this->stdout($message);
        });

        return (int) ($result['summary']['exitCode'] ?? ExitCode::UNSPECIFIED_ERROR);
    }

    /**
     * Load compiled mapping exactly once when source entity filters need a
     * Craft-query scope. Missing or unmapped compiled metadata is actionable
     * operator failure; guessing would reintroduce D-17 cross-domain compares.
     *
     * @return array{
     *   sectionHandles: list<string>,
     *   entryTypeHandles: list<string>,
     *   unmappedSourceEntities: list<string>
     * }
     */
    private function loadTranslatedScopeForEntityFilters(MigrationFilters $filters, Plugin $plugin): array
    {
        if ($filters->entities === []) {
            return [
                'sectionHandles' => [],
                'entryTypeHandles' => [],
                'unmappedSourceEntities' => [],
            ];
        }

        $mappingPath = $plugin->mappingFile->resolvePath();
        if (!is_file($mappingPath)) {
            throw new \RuntimeException(
                'Entity filters require compiled mapping for verify. Run `./craft kunstmaan-migrator/compile` first.',
            );
        }

        $compiledMapping = $plugin->mappingFile->load($mappingPath);
        if ((array) ($compiledMapping['nodeClasses'] ?? []) === [] || (array) ($compiledMapping['sections'] ?? []) === []) {
            throw new \RuntimeException(
                'Entity filters require compiled mapping nodeClasses/sections for verify. Run `./craft kunstmaan-migrator/compile` first.',
            );
        }

        $translatedScope = (new MappingFilterTranslator())->translate($compiledMapping, $filters);
        if ($translatedScope['unmappedSourceEntities'] !== []) {
            throw new \RuntimeException(
                'Entity filters are not present in compiled mapping: '
                . implode(', ', $translatedScope['unmappedSourceEntities'])
                . '. Run `./craft kunstmaan-migrator/analyze` and `./craft kunstmaan-migrator/compile`, or adjust --entities.',
            );
        }

        return $translatedScope;
    }

    private function buildRuntimeFilters(Plugin $plugin): MigrationFilters
    {
        return $plugin->filterFactory->fromCli(
            entitiesArg: $this->entities,
            localesArg: $this->locales,
            sinceArg: $this->since,
            relationGraph: $this->loadRuntimeRelationGraph(),
        );
    }

    /**
     * @return array<string, list<string>>
     */
    private function loadRuntimeRelationGraph(): array
    {
        $path = Craft::$app->path->getStoragePath() . '/migration/relation-graph.json';
        if (!is_file($path)) {
            return [];
        }

        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded)
            ? FilterFactory::relationGraphFromArtifact($decoded)
            : [];
    }

    /**
     * D-59: capture light counts of the current Craft DB state to baseline.json.
     * Used post-migration as the equivalence reference for `verify` Gate 1 re-runs.
     */
    public function actionCaptureBaseline(): int
    {
        if (($gate = $this->enforceNeverProduction()) !== null) {
            return $gate;
        }

        $result = (new VerifyWorkflow())->run([
            'baseline' => $this->baseline,
            'urlSpotCheck' => $this->urlSpotCheck,
            'baselineDir' => $this->baselineDir,
            'countTolerance' => $this->countTolerance,
            'urlDiffThreshold' => $this->urlDiffThreshold,
            'entities' => $this->entities,
            'locales' => $this->locales,
            'since' => $this->since,
            'captureBaseline' => true,
            'captureBaselineHtml' => false,
            'output' => $this->output,
            'outputDir' => $this->outputDir,
        ], function (array $event): void {
            $stream = (string) ($event['stream'] ?? 'stdout');
            $message = (string) ($event['message'] ?? '');
            if ($stream === 'stderr') {
                $this->stderr($message);
                return;
            }
            $this->stdout($message);
        });

        return (int) ($result['summary']['exitCode'] ?? ExitCode::UNSPECIFIED_ERROR);
    }

    /**
     * Capture baseline HTML snapshots from the legacy host (URLs in spot-check-urls.txt).
     * Operator runs this BEFORE the first migration rehearsal while the legacy host is
     * still the source of truth. Read by actionIndex Gate 2.
     */
    public function actionCaptureBaselineHtml(): int
    {
        if (($gate = $this->enforceNeverProduction()) !== null) {
            return $gate;
        }

        $result = (new VerifyWorkflow())->run([
            'baseline' => $this->baseline,
            'urlSpotCheck' => $this->urlSpotCheck,
            'baselineDir' => $this->baselineDir,
            'countTolerance' => $this->countTolerance,
            'urlDiffThreshold' => $this->urlDiffThreshold,
            'entities' => $this->entities,
            'locales' => $this->locales,
            'since' => $this->since,
            'captureBaseline' => false,
            'captureBaselineHtml' => true,
            'output' => $this->output,
            'outputDir' => $this->outputDir,
        ], function (array $event): void {
            $stream = (string) ($event['stream'] ?? 'stdout');
            $message = (string) ($event['message'] ?? '');
            if ($stream === 'stderr') {
                $this->stderr($message);
                return;
            }
            $this->stdout($message);
        });

        return (int) ($result['summary']['exitCode'] ?? ExitCode::UNSPECIFIED_ERROR);
    }

    /**
     * URL → safe filename slug. Ported byte-for-byte from v1 lines 294-297.
     */
    private function urlToSlug(string $url): string
    {
        return preg_replace('/[^a-zA-Z0-9_-]+/', '_', $url) ?? 'baseline';
    }

    /**
     * Source/transformed parity expectations are optional and must come from a
     * source-derived artifact shape. They are never inferred from Craft baseline
     * counts, because that mixes domains and caused false failures in Phase 10.
     *
     * @param array<string, mixed> $baseline
     * @return array<string, mixed>
     */
    private function sourceParityExpectedCounts(array $baseline): array
    {
        foreach (['sourceExpectedCounts', 'sourceParityExpectedCounts', 'expectedMigrationCounts'] as $key) {
            $candidate = $baseline[$key] ?? null;
            if (is_array($candidate) && $candidate !== []) {
                return $candidate;
            }
        }

        return [];
    }

    /**
     * @param array<string, array<string, mixed>> $gates
     */
    private function renderCountDomainToConsole(string $label, array $gates, bool $blocking): void
    {
        $suffix = $blocking ? 'blocking' : 'informational';
        $this->stdout("  Domain: {$label} ({$suffix})\n", Console::FG_CYAN);

        foreach ($gates as $key => $g) {
            if (!is_array($g)) {
                continue;
            }
            if (($g['skip'] ?? false) === true) {
                $note = (string) ($g['note'] ?? 'skipped');
                $this->stdout("    SKIP {$key}: {$note}\n", Console::FG_YELLOW);
                continue;
            }
            if (!isset($g['expected'])) {
                $note = (string) ($g['note'] ?? 'actual count only');
                $this->stdout(sprintf(
                    "    INFO %s: actual=%d (%s)\n",
                    $key,
                    (int) ($g['actual'] ?? 0),
                    $note,
                ), Console::FG_YELLOW);
                continue;
            }
            $pass = (bool) ($g['pass'] ?? false);
            $status = $blocking ? ($pass ? 'PASS' : 'FAIL') : ($pass ? 'MATCH' : 'DRIFT');
            $this->stdout(sprintf(
                "    %s %s: %d/%d (Delta=%.3f%%)\n",
                $status,
                $key,
                (int) ($g['actual'] ?? 0),
                (int) ($g['expected'] ?? 0),
                (float) ($g['delta'] ?? 0.0) * 100,
            ), $pass ? Console::FG_GREEN : Console::FG_YELLOW);
        }
    }

    /**
     * Render the verify report markdown (D-61). Includes count gate table, URL gate
     * table, and explicit SKIP rows for absent optional-plugin gates per D-58.
     *
     * @param array<string, mixed> $report
     */
    private function renderReportMarkdown(array $report): string
    {
        $lines = [];
        $lines[] = '# Verify Report';
        $lines[] = '';
        $lines[] = '_Generated ' . ($report['timestamp'] ?? '(unknown)') . '_';
        $lines[] = '';
        $lines[] = 'Overall: ' . (($report['pass'] ?? false) ? '**PASS**' : '**FAIL**');
        $lines[] = '';
        $lines[] = '## Count domains (tolerance: ' . ((float) ($report['tolerance'] ?? 0) * 100) . '%)';
        $lines[] = '';
        $countDomains = (array) ($report['countDomains'] ?? []);
        if ($countDomains === []) {
            $countDomains = [
                'legacy-count-gate' => [
                    'label' => 'Legacy count gate',
                    'blocking' => true,
                    'gates' => (array) ($report['countGate'] ?? []),
                ],
            ];
        }
        foreach ($countDomains as $domain) {
            if (!is_array($domain)) {
                continue;
            }
            $label = (string) ($domain['label'] ?? 'Count domain');
            $blocking = (bool) ($domain['blocking'] ?? true);
            $lines[] = '### ' . $label . ($blocking ? ' (blocking)' : ' (informational)');
            $lines[] = '';
            $lines[] = '| Key | Expected | Actual | Delta | Status |';
            $lines[] = '|-----|----------|--------|-------|--------|';
            foreach ((array) ($domain['gates'] ?? []) as $key => $row) {
                if (!is_array($row)) {
                    continue;
                }
                if (($row['skip'] ?? false) === true) {
                    $note = (string) ($row['note'] ?? 'skipped');
                    $lines[] = "| `{$key}` | - | - | - | SKIP ({$note}) |";
                    continue;
                }
                $expected = $row['expected'] ?? '-';
                $actual   = $row['actual']   ?? '-';
                $delta    = isset($row['delta']) ? sprintf('%.3f%%', (float) $row['delta'] * 100) : '-';
                if (!isset($row['expected'])) {
                    $status = 'INFO (' . (string) ($row['note'] ?? 'actual count only') . ')';
                } elseif ($blocking) {
                    $status = ((bool) ($row['pass'] ?? false)) ? 'PASS' : 'FAIL';
                } else {
                    $status = ((bool) ($row['pass'] ?? false)) ? 'MATCH' : 'DRIFT';
                }
                $lines[] = "| `{$key}` | {$expected} | {$actual} | {$delta} | {$status} |";
            }
            $lines[] = '';
        }

        $lines[] = '## URL gate (threshold: ' . ((float) ($report['urlDiffThreshold'] ?? 0) * 100) . '%)';
        $lines[] = '';
        $lines[] = '| URL | Status | Diff ratio |';
        $lines[] = '|-----|--------|------------|';
        foreach ((array) ($report['urlGate'] ?? []) as $url => $row) {
            if (!is_array($row)) {
                continue;
            }
            $status = (string) ($row['status'] ?? '?');
            $ratio  = isset($row['diffRatio']) ? sprintf('%.3f%%', (float) $row['diffRatio'] * 100) : '-';
            $lines[] = "| {$url} | {$status} | {$ratio} |";
        }

        $lines[] = '';
        return implode("\n", $lines);
    }
}
