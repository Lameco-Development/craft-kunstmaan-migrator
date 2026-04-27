#!/usr/bin/env php
<?php
// tools/check-coverage.php
// Phase 5 / TST-01 / D-07 — per-module 70% line-coverage gate.
// Reads build/coverage/clover.xml; exits non-zero if ANY of the 5 named
// modules drops below 70%. Per-module, NOT aggregate (TST-01 wording).
//
// Invoked as the final step of `composer test-coverage`.
// Exit codes: 0 = all modules >= 70%, 1 = at least one module under threshold,
//             2 = clover.xml missing or unparseable.

declare(strict_types=1);

const THRESHOLD = 70.0;
const MODULES = [
    'src/filter/MigrationFilters.php',
    'src/mapping/MappingFile.php',
    'src/finalize/CkeditorRewriterService.php',
    'src/analyze/HeuristicProposer.php',
    // src/fields/handlers/ — every .php under this directory auto-enrolls
    // via the str_starts_with check below.
];
const HANDLERS_PREFIX = 'src/fields/handlers/';

$cloverPath = __DIR__ . '/../build/coverage/clover.xml';
if (!is_file($cloverPath)) {
    fwrite(STDERR, "FAIL: clover.xml not found at {$cloverPath}\n");
    fwrite(STDERR, "  Run `composer test-coverage` first; it generates the clover XML before invoking this script.\n");
    exit(2);
}
$xml = @simplexml_load_file($cloverPath);
if ($xml === false) {
    fwrite(STDERR, "FAIL: could not parse {$cloverPath}\n");
    exit(2);
}

$repoRoot = realpath(__DIR__ . '/..');
$failures = [];
$rowsPrinted = 0;

foreach ($xml->project->file as $file) {
    $absPath = (string) $file['name'];
    $rel = $absPath;
    if ($repoRoot !== false && str_starts_with($absPath, $repoRoot . '/')) {
        $rel = substr($absPath, strlen($repoRoot) + 1);
    }
    $isModule = in_array($rel, MODULES, true) || str_starts_with($rel, HANDLERS_PREFIX);
    if (!$isModule) {
        continue;
    }

    $metrics = $file->metrics;
    $statements = (int) $metrics['statements'];
    $covered    = (int) $metrics['coveredstatements'];
    $pct        = $statements === 0 ? 100.0 : ($covered / $statements) * 100.0;
    $marker     = $pct >= THRESHOLD ? 'OK  ' : 'FAIL';
    fwrite(STDOUT, sprintf("  %s %5.1f%%  %s\n", $marker, $pct, $rel));
    $rowsPrinted++;
    if ($pct < THRESHOLD) {
        $failures[] = sprintf('%s: %.1f%% < %.1f%%', $rel, $pct, THRESHOLD);
    }
}

if ($rowsPrinted === 0) {
    fwrite(STDERR, "FAIL: no TST-01 modules found in {$cloverPath}\n");
    fwrite(STDERR, "  Verify phpunit.xml.dist <source><include> matches the module paths in this script.\n");
    exit(2);
}

if ($failures !== []) {
    fwrite(STDERR, "\nCoverage gate FAILED:\n  - " . implode("\n  - ", $failures) . "\n");
    fwrite(STDERR, "  Per-module threshold (TST-01 / D-07): " . THRESHOLD . "%\n");
    exit(1);
}
fwrite(STDOUT, "\nCoverage gate OK — all modules >= " . THRESHOLD . "%\n");
exit(0);
