#!/usr/bin/env php
<?php
// tools/capture-transform-fixtures.php
//
// Phase 5 / TST-02 / D-01..D-05 — one-time capture script for Transform-stage
// characterization fixtures.
//
// Operator usage (run on a dev host with the CQM dump and a configured Craft
// consumer site that has the kunstmaan-migrator plugin installed + booted):
//
//   ./craft kunstmaan-migrator/doctor             # confirm green first
//   CRAFT_BOOTSTRAP=/abs/path/to/craft/bootstrap.php \
//     php tools/capture-transform-fixtures.php
//
// Output:
//   tests/fixtures/transform/input/<EntitySimpleName>/<legacyNodeId>.json
//
// Mechanism (anchored on Phase 3 / Plan 04 ExtractService::run shape):
//   1. Bootstraps the consumer Craft site so Plugin::getInstance() resolves.
//   2. Loads the consumer's mapping.yaml via Plugin::getInstance()->mappingFile.
//   3. Drives ExtractService::run() with a MigrationFilters scoped to the
//      $TARGET_ENTITIES allow-list. ExtractService writes per-node JSON files
//      under @storage/migration/extracted/<fqcnSlug>/<id>.json.
//   4. Copies up-to-$PER_ENTITY_CAP of those files into
//      tests/fixtures/transform/input/<EntitySimpleName>/<id>.json so the
//      Transform-stage characterization test can consume them as
//      `iterable<extracted-row>` inputs to TransformService::run().
//
// NOT part of the test runner. NOT invoked by composer test / test-integration.
// NOT added to composer scripts. CI never invokes it (CONTEXT D-24 — no live
// CQM in CI). Re-run when the fixture corpus needs to grow.
//
// D-04: NO anonymization. CQM is internal Lameco rehearsal data; this repo is
// private under lameco/. If the repo ever goes public, scrub via a separate
// pre-publish pass (Phase 5 / Plan 05-08 RELEASE-CHECKLIST flags this).

declare(strict_types=1);

// --- Bootstrap Craft + plugin ---
$craftBootstrap = getenv('CRAFT_BOOTSTRAP') ?: __DIR__ . '/../../../bootstrap.php';
if (!is_file($craftBootstrap)) {
    fwrite(STDERR, "FAIL: Craft bootstrap not found at {$craftBootstrap}\n");
    fwrite(STDERR, "  Set CRAFT_BOOTSTRAP=/absolute/path/to/your/craft/site/bootstrap.php\n");
    fwrite(STDERR, "  (typically the same path craft's CLI binary uses).\n");
    exit(2);
}
require $craftBootstrap;

use lameco\kunstmaanmigrator\Plugin;
use lameco\kunstmaanmigrator\filter\MigrationFilters;

// --- Target entity types (D-02 — narrow corpus) ---
// Operator: edit this list to match the FQCN simple-names actually present in
// the CQM extract. The list maps to MigrationFilters::$entities, which
// ExtractService::run() applies as a basename allow-list across mapping.yaml's
// nodeClasses keys.
$TARGET_ENTITIES = [
    'PageNode',
    'NewsPage',
    'ContactPage',
    // Add 1-2 entities exercising MatrixHandler / pageBuilder once the CQM
    // extract is inspected: 'CaseStudyPage', 'EventPage', 'HomePage', etc.
];

// Per-entity row cap — keeps the corpus narrow (D-02 ~10-15 rows total
// across 4-5 entities).
$PER_ENTITY_CAP = 3;

// --- Capture loop ---
$plugin = Plugin::getInstance();
if ($plugin === null) {
    fwrite(STDERR, "FAIL: Kunstmaan Migrator plugin not loaded; verify config/app.php registers the plugin.\n");
    exit(2);
}

$mapping = $plugin->mappingFile->loadOrCreate();
if (!is_array($mapping) || $mapping === []) {
    fwrite(STDERR, "FAIL: mapping.yaml is empty or unreadable; run `./craft kunstmaan-migrator/analyze` first.\n");
    exit(2);
}

$filters = new MigrationFilters(
    entities: $TARGET_ENTITIES,
    locales: [],
    since: null,
    noSeo: false,
    noRetour: false,
);

echo "Phase 5 / TST-02 capture starting...\n";
echo "Target entities: " . implode(', ', $TARGET_ENTITIES) . "\n";
echo "Per-entity cap:  {$PER_ENTITY_CAP}\n\n";

// ExtractService::run() writes per-node JSON files under
// @storage/migration/extracted/<fqcnSlug>/<id>.json and returns a report.
// We consume that on-disk side-effect rather than re-querying the legacy DB.
$extractIter = $plugin->extractService->run($mapping, $filters, []);

// run() returns iterable; force evaluation (it's actually `return $report;`,
// not `yield`, in the current Phase 3 shape — but tolerate either).
$report = is_array($extractIter) ? $extractIter : iterator_to_array($extractIter, true);
echo "Extract report: " . json_encode($report, JSON_UNESCAPED_SLASHES) . "\n\n";

$extractedRoot = \Craft::getAlias('@storage/migration') . '/extracted';
if (!is_dir($extractedRoot)) {
    fwrite(STDERR, "FAIL: extracted dir not found at {$extractedRoot}; ExtractService::run produced no output.\n");
    exit(2);
}

$totalWritten = 0;
foreach ($TARGET_ENTITIES as $simpleName) {
    echo "Capturing {$simpleName}...\n";

    // ExtractService writes under <fqcnSlug> directories (e.g.
    // App_Entity_Pages_NewsPage). We don't know the bundle prefix at script
    // time, so glob for any directory whose tail matches `_{simpleName}`.
    $candidates = array_filter(
        glob($extractedRoot . '/*', GLOB_ONLYDIR) ?: [],
        static fn(string $dir): bool => str_ends_with(basename($dir), '_' . $simpleName)
            || basename($dir) === $simpleName,
    );
    if ($candidates === []) {
        echo "  -> no extracted directory matches *_{$simpleName}; skipped.\n";
        continue;
    }

    $written = 0;
    foreach ($candidates as $srcDir) {
        $files = glob($srcDir . '/*.json') ?: [];
        sort($files);
        foreach ($files as $srcFile) {
            if ($written >= $PER_ENTITY_CAP) {
                break 2;
            }
            $id = basename($srcFile, '.json');
            $destDir = __DIR__ . "/../tests/fixtures/transform/input/{$simpleName}";
            if (!is_dir($destDir) && !mkdir($destDir, 0755, true) && !is_dir($destDir)) {
                fwrite(STDERR, "  FAIL could not mkdir {$destDir}\n");
                exit(2);
            }
            $destFile = "{$destDir}/{$id}.json";

            $raw = file_get_contents($srcFile);
            if ($raw === false) {
                fwrite(STDERR, "  FAIL could not read {$srcFile}\n");
                continue;
            }

            // Re-encode through stable flags so the input fixture matches the
            // canonicalize() flag-set in TransformCharacterizationTest. This
            // keeps `git diff` quiet across captures on different PHP builds.
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                fwrite(STDERR, "  FAIL non-array JSON in {$srcFile}; skipped.\n");
                continue;
            }
            $json = json_encode(
                $decoded,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
            if ($json === false) {
                fwrite(STDERR, "  FAIL json_encode error for {$simpleName}/{$id}: " . json_last_error_msg() . "\n");
                continue;
            }
            file_put_contents($destFile, $json . "\n");
            $written++;
            $totalWritten++;
            echo "  OK   {$simpleName}/{$id}.json\n";
        }
    }
    echo "  -> {$written} rows captured for {$simpleName}\n";
}

echo "\nTotal: {$totalWritten} input fixtures written under tests/fixtures/transform/input/\n";
echo "\nNext step (operator):\n";
echo "  UPDATE_SNAPSHOTS=1 vendor/bin/phpunit \\\n";
echo "    --testsuite Integration \\\n";
echo "    --filter TransformCharacterizationTest\n";
echo "to generate the matching goldens, then commit input/ + golden/ together.\n";
exit(0);
