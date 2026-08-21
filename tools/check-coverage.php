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

// Four of the five modules this gate originally named were deleted by the v2 rewrite —
// src/filter/MigrationFilters.php, src/mapping/MappingFile.php, src/analyze/HeuristicProposer.php
// and the whole src/fields/handlers/ tree. The gate went on "passing" them because a module it
// cannot find in the clover report was silently skipped, so it was really guarding one file.
// Nobody saw it: `composer validate --strict` aborted this job before the gate ever ran.
//
// A named module that no longer exists is now a hard failure. A stale gate is worse than no gate,
// because it reads as coverage that is not there.
const MODULES = [
    'src/payload/PayloadValidator.php',
    'src/payload/RefResolver.php',
    'src/payload/Payload.php',
    'src/finalize/CkeditorRewriterService.php',
];

/**
 * Reported, not gated — yet.
 *
 * These are the four largest and most defect-prone modules in the plugin, and
 * every defect AUDIT.md found lives in them. They were unreachable without
 * MySQL until the ElementWriter seam landed; the seam was built to buy exactly
 * this and the gate was never extended to spend it.
 *
 * They are not in MODULES because nobody has measured them, and a threshold
 * picked by guessing is how a gate ends up either red on arrival or set so low
 * it certifies nothing. CI prints these numbers on every run: read them once,
 * then move each module into MODULES at a threshold its real coverage supports
 * and raise it from there. A file listed here that CI cannot find is a hard
 * failure, same as one in MODULES — a stale list is worse than no list.
 */
const WATCHED = [
    'src/payload/PayloadEntrySaver.php',
    'src/load/EntryMigrationService.php',
    'src/load/AssetMigrationService.php',
    'src/load/NavigationMigrationService.php',
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
$seen = [];

// Clover XML can place <file> nodes either directly under <project> (top-
// level files outside namespaces) OR under <project><package> (grouped by
// namespace). PHPUnit 10/11 with strict source filtering tends to use the
// package-grouped form for namespaced classes, so iterating only the top-
// level <file> children misses the entire `lameco\…` tree and the script
// reports "no TST-01 modules found". Collect both.
$fileNodes = [];
foreach ($xml->project->file as $f) {
    $fileNodes[] = $f;
}
foreach ($xml->project->package as $pkg) {
    foreach ($pkg->file as $f) {
        $fileNodes[] = $f;
    }
}

foreach ($fileNodes as $file) {
    $absPath = (string) $file['name'];
    $rel = $absPath;
    if ($repoRoot !== false && str_starts_with($absPath, $repoRoot . '/')) {
        $rel = substr($absPath, strlen($repoRoot) + 1);
    }
    $isModule  = in_array($rel, MODULES, true) || str_starts_with($rel, HANDLERS_PREFIX);
    $isWatched = in_array($rel, WATCHED, true);

    if (!$isModule && !$isWatched) {
        continue;
    }

    $seen[$rel] = true;

    $metrics = $file->metrics;
    $statements = (int) $metrics['statements'];
    $covered    = (int) $metrics['coveredstatements'];
    if ($statements === 0) {
        // Interface-only / abstract / declaration-only files have no executable
        // statements. Reporting 100% would silently mask coverage gaps in the
        // MODULES allow-list (Phase 5 review MEDIUM #1). Skip with a visible
        // notice so CI logs make the omission auditable.
        fwrite(STDOUT, sprintf("  SKIP  ----   %s (zero statements)\n", $rel));
        $rowsPrinted++;
        continue;
    }
    $pct = ($covered / $statements) * 100.0;

    if ($isWatched) {
        // Reported so the number exists to act on, not gated: see WATCHED.
        fwrite(STDOUT, sprintf("  WATCH %5.1f%%  %s\n", $pct, $rel));
        $rowsPrinted++;
        continue;
    }

    $marker = $pct >= THRESHOLD ? 'OK  ' : 'FAIL';
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

// A module named here but absent from the report is a gate that silently guards nothing.
$missing = array_values(array_diff([...MODULES, ...WATCHED], array_keys($seen)));

if ($missing !== []) {
    fwrite(STDERR, "\nFAIL: named module(s) not present in the coverage report:\n  - "
        . implode("\n  - ", $missing) . "\n");
    fwrite(STDERR, "  Either the file was moved or deleted and MODULES is stale, or it is missing\n");
    fwrite(STDERR, "  from phpunit.xml.dist <source><include>. Fix the list — do not leave it rotting.\n");
    exit(1);
}

if ($failures !== []) {
    fwrite(STDERR, "\nCoverage gate FAILED:\n  - " . implode("\n  - ", $failures) . "\n");
    fwrite(STDERR, "  Per-module threshold (TST-01 / D-07): " . THRESHOLD . "%\n");
    exit(1);
}
fwrite(STDOUT, "\nCoverage gate OK — all modules >= " . THRESHOLD . "%\n");
exit(0);
