<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\console;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use lameco\kunstmaanmigrator\NeverProductionTrait;
use lameco\kunstmaanmigrator\Plugin;
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
    // Deep plumbing into BaselineCounterService / CountGateService is deferred — see plan body
    // "filter plumbing carve-out". The load-bearing requirement is uniform CLI surface; the parsing
    // happens here and $filters stays in scope so a future filter-aware service overload wires
    // without touching the controller.
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

        $plugin = Plugin::getInstance();

        // ROADMAP criterion 5: parse filter flags. Mirrors AnalyzeController step 2.
        // CountGateService / BaselineCounterService are filter-naive at v1.0 (carve-out per
        // plan body); $filters retained so future filter-aware overloads wire without
        // touching the controller surface.
        $filters = $plugin->filterFactory->fromCli($this->entities, $this->locales, $this->since);

        $tolerance = $this->countTolerance ?? $plugin->getSettings()->verifyCountTolerance ?? 0.01;
        $threshold = $this->urlDiffThreshold ?? $plugin->getSettings()->verifyUrlDiffThreshold ?? 0.05;

        $report = [
            'timestamp'        => gmdate('c'),
            'tolerance'        => $tolerance,
            'urlDiffThreshold' => $threshold,
            'countGate'        => [],
            'urlGate'          => [],
            'pass'             => true,
        ];

        // ---------------------------------------------------------------
        // Gate 1 — count match (delegated to CountGateService)
        // ---------------------------------------------------------------
        $this->stdout("\n[1/2] Count-match gate (tolerance: " . ($tolerance * 100) . "%)\n", Console::FG_CYAN);

        $baselinePath = $this->baseline ?? Craft::$app->path->getStoragePath() . '/migration/baseline.json';
        $expectedCounts = null;
        if (!is_file($baselinePath)) {
            // D-58: missing-baseline semantic — WARN + flip overall pass.
            $this->stdout("  WARN no-baseline (run verify capture-baseline first): {$baselinePath}\n", Console::FG_YELLOW);
            $report['countGate'] = [
                'no-baseline' => ['skip' => true, 'note' => "no-baseline at {$baselinePath}"],
            ];
            $report['pass'] = false;
        } else {
            $rawBaseline = (string) file_get_contents($baselinePath);
            $baselineDecoded = json_decode($rawBaseline, true);
            if (!is_array($baselineDecoded)) {
                $this->stdout("  WARN no-baseline (could not decode {$baselinePath})\n", Console::FG_YELLOW);
                $report['countGate'] = [
                    'no-baseline' => ['skip' => true, 'note' => "decode-failed at {$baselinePath}"],
                ];
                $report['pass'] = false;
            } else {
                $expectedCounts = $this->baselineToExpectedCounts($baselineDecoded);
                $countResult = $plugin->countGateService->run($expectedCounts, (float) $tolerance);
                $report['countGate'] = $countResult['gates'];
                if (!$countResult['pass']) {
                    $report['pass'] = false;
                }

                foreach ($countResult['gates'] as $key => $g) {
                    if (!is_array($g)) {
                        continue;
                    }
                    if (($g['skip'] ?? false) === true) {
                        $note = (string) ($g['note'] ?? 'skipped');
                        $this->stdout("  SKIP {$key}: {$note}\n", Console::FG_YELLOW);
                        continue;
                    }
                    $note = $g['note'] ?? null;
                    if ($note !== null && !isset($g['expected'])) {
                        $this->stdout("  SKIP {$key}: {$note}\n", Console::FG_YELLOW);
                        continue;
                    }
                    $pass = (bool) ($g['pass'] ?? false);
                    $this->stdout(sprintf(
                        "  %s %s: %d/%d (Delta=%.3f%%)\n",
                        $pass ? 'PASS' : 'FAIL',
                        $key,
                        (int) ($g['actual'] ?? 0),
                        (int) ($g['expected'] ?? 0),
                        (float) ($g['delta'] ?? 0.0) * 100,
                    ), $pass ? Console::FG_GREEN : Console::FG_RED);
                }
            }
        }

        // D-58 / D-61: surface optional-plugin SKIP rows when CountGate didn't include them
        // (operator's baseline didn't carry expected counts for that plugin).
        if (!isset($report['countGate']['plugins:seomatic'])) {
            $this->stdout("  SKIP seomatic (plugin not installed or not in baseline)\n", Console::FG_YELLOW);
            $report['countGate']['plugins:seomatic'] = ['skip' => true, 'note' => 'seomatic plugin not installed or not in baseline'];
        }
        if (!isset($report['countGate']['plugins:retour'])) {
            $this->stdout("  SKIP retour (plugin not installed or not in baseline)\n", Console::FG_YELLOW);
            $report['countGate']['plugins:retour'] = ['skip' => true, 'note' => 'retour plugin not installed or not in baseline'];
        }

        // ---------------------------------------------------------------
        // Gate 2 — URL HTML diff against baseline (B1 fix in SpotCheckUrlFetcher)
        // ---------------------------------------------------------------
        $this->stdout("\n[2/2] URL diff gate (threshold: " . ($threshold * 100) . "%)\n", Console::FG_CYAN);

        $urlListPath = $this->urlSpotCheck ?? Craft::$app->path->getStoragePath() . '/migration/spot-check-urls.txt';
        $baselineDir = $this->baselineDir   ?? Craft::$app->path->getStoragePath() . '/migration/baseline';

        if (!is_file($urlListPath)) {
            $this->stdout("  WARN URL list missing: {$urlListPath}\n", Console::FG_YELLOW);
        } else {
            $fetcher = $plugin->spotCheckUrlFetcher;
            $lines = (array) file($urlListPath);
            $urls = array_filter(
                array_map('trim', $lines),
                static fn(string $l): bool => $l !== '' && !str_starts_with($l, '#'),
            );

            foreach ($urls as $url) {
                try {
                    $currentHtml = $fetcher->fetchAndNormalize($url);
                    $slug = $this->urlToSlug($url);
                    $localBaseline = $baselineDir . '/' . $slug . '.html';
                    if (!is_file($localBaseline)) {
                        $report['urlGate'][$url] = [
                            'status' => 'no-baseline',
                            'bytes'  => strlen($currentHtml),
                        ];
                        $report['pass'] = false;
                        $this->stdout(
                            "  WARN {$url} — no baseline at {$localBaseline} (run verify capture-baseline-html first)\n",
                            Console::FG_YELLOW,
                        );
                        continue;
                    }
                    $baselineHtml = (string) file_get_contents($localBaseline);

                    // B1 — real diff against the baseline (logic lives in SpotCheckUrlFetcher per Plan 04-03).
                    $diffResult = $fetcher->diff($currentHtml, $baselineHtml);
                    $diffLength = strlen($diffResult);
                    $diffRatio  = strlen($currentHtml) > 0
                        ? $diffLength / strlen($currentHtml)
                        : ($diffLength > 0 ? 1.0 : 0.0);

                    $pass = $diffRatio <= $threshold;
                    $report['urlGate'][$url] = [
                        'status'    => $pass ? 'pass' : 'fail',
                        'diffRatio' => $diffRatio,
                        'diffBytes' => $diffLength,
                    ];
                    if (!$pass) {
                        $report['pass'] = false;
                    }
                    $this->stdout(sprintf(
                        "  %s %s (diff=%.3f%%)\n",
                        $pass ? 'PASS' : 'FAIL',
                        $url,
                        $diffRatio * 100,
                    ), $pass ? Console::FG_GREEN : Console::FG_RED);
                } catch (Throwable $e) {
                    $report['urlGate'][$url] = [
                        'status' => 'error',
                        'error'  => $e->getMessage(),
                    ];
                    $report['pass'] = false;
                    $this->stdout("  FAIL {$url}: {$e->getMessage()}\n", Console::FG_RED);
                }
            }
        }

        // ---------------------------------------------------------------
        // Emit report (D-61 markdown-only; Phase 2 / D-07 atomic write)
        // ---------------------------------------------------------------
        $reportPath = Craft::$app->path->getStoragePath() . '/migration/VERIFY-' . gmdate('Y-m-d--H-i-s') . '.md';
        $rendered = $this->renderReportMarkdown($report);
        if (!$plugin->mappingFile->writeAtomic($reportPath, $rendered)) {
            $this->stderr("  FAIL could not write {$reportPath}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $this->stdout("\nReport: {$reportPath}\n", $report['pass'] ? Console::FG_GREEN : Console::FG_RED);

        return $report['pass'] ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
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
        $this->stdout("Verify (capture-baseline): light counts → baseline.json\n", Console::FG_CYAN);

        $plugin = Plugin::getInstance();

        // Criterion 5: parse filter flags for CLI uniformity. v1.0 BaselineCounterService is
        // filter-naive (carve-out — see plan body); $filters retained so future filter-aware
        // overload can be wired without touching the controller surface.
        $filters = $plugin->filterFactory->fromCli($this->entities, $this->locales, $this->since);
        $snapshot = $plugin->baselineCounterService->capture();

        $path = $this->output ?? Craft::$app->path->getStoragePath() . '/migration/baseline.json';
        // Phase 2 / D-07 atomic write seam.
        if (!$plugin->mappingFile->writeAtomicJson($path, $snapshot)) {
            $this->stderr("  FAIL could not write {$path}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("  OK   baseline.json written to {$path}\n", Console::FG_GREEN);
        return ExitCode::OK;
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
        $this->stdout("Verify (capture-baseline-html): URL spot-check fetches\n", Console::FG_CYAN);

        $plugin = Plugin::getInstance();

        // Criterion 5: filter flags accepted for CLI uniformity. spot-check-urls.txt is
        // operator-curated, so URL-list scoping is already operator-controlled; $filters parsed
        // but unused at v1.0.
        $filters = $plugin->filterFactory->fromCli($this->entities, $this->locales, $this->since);

        $urlList = $this->urlSpotCheck ?? Craft::$app->path->getStoragePath() . '/migration/spot-check-urls.txt';
        $outDir  = $this->outputDir   ?? Craft::$app->path->getStoragePath() . '/migration/baseline';
        try {
            $count = $plugin->captureBaselineHtmlService->capture($urlList, $outDir);
        } catch (Throwable $e) {
            $this->stderr("  FAIL {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $this->stdout("  OK   {$count} baseline HTML files written to {$outDir}\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    /**
     * URL → safe filename slug. Ported byte-for-byte from v1 lines 294-297.
     */
    private function urlToSlug(string $url): string
    {
        return preg_replace('/[^a-zA-Z0-9_-]+/', '_', $url) ?? 'baseline';
    }

    /**
     * Translate BaselineCounterService's `counts-v1` shape to CountGateService's
     * `expectedCounts` shape.
     *
     * BaselineCounterService output (D-59):
     *   ['format' => 'counts-v1', 'sections' => ['<handle>' => ['totalCount' => N, ...]],
     *    'assets' => ['totalCount' => N], 'taxonomies' => ['<handle>' => ['totalCount' => N]],
     *    'retour' => ['totalCount' => N], 'seomatic' => ['totalCount' => N]]
     *
     * CountGateService input:
     *   ['sections' => ['<handle>' => N, ...], 'assets' => ['<label>' => N, ...],
     *    'plugins' => ['seomatic' => N, 'retour' => N], 'taxonomies' => ['<handle>' => N, ...]]
     *
     * @param array<string, mixed> $baseline
     * @return array<string, mixed>
     */
    private function baselineToExpectedCounts(array $baseline): array
    {
        $sections = [];
        foreach ((array) ($baseline['sections'] ?? []) as $handle => $row) {
            if (is_array($row)) {
                $sections[(string) $handle] = (int) ($row['totalCount'] ?? 0);
            }
        }

        $assets = [];
        $assetTotal = (int) (($baseline['assets'] ?? [])['totalCount'] ?? 0);
        if ($assetTotal > 0) {
            $assets['migrated'] = $assetTotal;
        }

        $plugins = [];
        $seomaticTotal = (int) (($baseline['seomatic'] ?? [])['totalCount'] ?? 0);
        if ($seomaticTotal > 0) {
            $plugins['seomatic'] = $seomaticTotal;
        }
        $retourTotal = (int) (($baseline['retour'] ?? [])['totalCount'] ?? 0);
        if ($retourTotal > 0) {
            $plugins['retour'] = $retourTotal;
        }

        $taxonomies = [];
        foreach ((array) ($baseline['taxonomies'] ?? []) as $handle => $row) {
            if (is_array($row)) {
                $taxonomies[(string) $handle] = (int) ($row['totalCount'] ?? 0);
            }
        }

        return [
            'sections'   => $sections,
            'assets'     => $assets,
            'plugins'    => $plugins,
            'taxonomies' => $taxonomies,
        ];
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
        $lines[] = '## Count gate (tolerance: ' . ((float) ($report['tolerance'] ?? 0) * 100) . '%)';
        $lines[] = '';
        $lines[] = '| Key | Expected | Actual | Delta | Status |';
        $lines[] = '|-----|----------|--------|-------|--------|';
        foreach ((array) ($report['countGate'] ?? []) as $key => $row) {
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
            $status   = isset($row['pass']) ? (((bool) $row['pass']) ? 'PASS' : 'FAIL') : '-';
            $lines[] = "| `{$key}` | {$expected} | {$actual} | {$delta} | {$status} |";
        }

        $lines[] = '';
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
