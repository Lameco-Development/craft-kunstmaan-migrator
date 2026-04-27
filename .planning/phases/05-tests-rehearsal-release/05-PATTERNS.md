# Phase 5: Tests, Rehearsal & Release — Pattern Map

**Mapped:** 2026-04-27
**Files analyzed:** 28 (22 NEW + 6 MODIFIED)
**Analogs found:** 22 / 28 (6 are greenfield artifacts — coverage gate, fixtures, rehearsal dirs, CHANGELOG)

## Layout note (corrections to CONTEXT.md prose)

CONTEXT.md uses some path shapes that don't exactly match the repo. The actual flat layout is:

| CONTEXT phrase | Real path |
|----------------|-----------|
| `src/console/RehearsalController.php` (option a) | NEW path is correct (`src/console/`) |
| `tests/Plugin/` (capitalized, mentioned in D-12) | Real path: `tests/Plugin/SettingsHtmlTest.php` (the capitalization is intentional — tests the `Plugin` class). After D-12 the move target is `tests/unit/Plugin/`. |
| `actionSyncRelations` | DOES NOT EXIST yet — only `actionSyncAssets()` ships in Phase 4.1. CONTEXT D-12 lists `tests/console/` files but the existing controller has only the assets sync action; no relations sync action is in scope for Phase 5 either (it's Phase 4.2). Plans referring to "sync relations test moves" can ignore — no such file exists. |

## Risk callouts (load-bearing for the planner)

1. **`tests/Plugin/` already exists with capital P** — `tests/Plugin/SettingsHtmlTest.php`. The D-12 `git mv` target should preserve case: `git mv tests/Plugin tests/unit/Plugin`. macOS APFS is case-insensitive but case-preserving — verify the move with `git ls-files tests/unit/Plugin/` after.

2. **`tests/integration/` does not exist yet.** D-12 creates this directory. The `tests/integration/transform/` subdirectory for the characterization fixtures (D-01..D-03) is NEW. No existing integration-tier tests in this repo — `PluginBootstrapTest.php` is the closest analog and explicitly defers full Craft-bootstrapped tests to Phase 5 (verbatim from its docblock at lines 13-22).

3. **PHPUnit 11 + PCOV.** PCOV is NOT in `composer.json` `require-dev` today (verified: only `phpunit/phpunit: ^11.0`). The planner should NOT add PCOV to require-dev — it's a system-level extension in CI. Locally devs install via `pecl install pcov` or skip coverage. CI installs it via `shivammathur/setup-php` `coverage: pcov`.

4. **`composer test` currently runs the entire `tests/` directory** with a single `Unit` suite. The D-12 reorg + D-13 split is a non-trivial rename: `phpunit.xml.dist`'s `<directory>tests</directory>` becomes `<directory>tests/unit</directory>` and `<directory>tests/integration</directory>` in two `<testsuite>` blocks. The `Unit` testsuite name stays for backwards-compat with anyone who scripted `phpunit --testsuite Unit`.

5. **`tools/` directory does not exist** — verified absent (`ls tools/` returns "No such file or directory"). Plans creating `tools/check-coverage.php` and `tools/capture-transform-fixtures.php` MUST create the directory. Mirrors how Phase 4.1 / Plan 04 had to create `config/`.

6. **`MigrateController` has hit 1700+ lines.** Adding `actionRehearsalCheck` to it would push it further. `RehearsalController` (new file) is the cleaner choice; D-22 explicitly notes the controller should NOT carry `NeverProductionTrait`, which is a deliberate departure from every other controller in `src/console/` — flagging in the controller's docblock so a future maintainer doesn't reflexively add it. CONTEXT.md surfaces this departure (D-22) and the planner should preserve the call-out.

7. **JSON canonicalization is required** for the characterization comparator (CONTEXT.md `## Risks` paragraph 3). PHP's `json_encode` does NOT sort keys by default — the comparator needs `JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE` plus a recursive ksort on associative arrays before encoding. Without this, every PHP version bump or array-key insertion order change becomes a fixture diff.

8. **The 5 coverage modules from D-08 — verified existence:**
   - `src/filter/MigrationFilters.php` — exists, 5 readonly props.
   - `src/mapping/MappingFile.php` — exists.
   - `src/fields/handlers/{AssetHandler,MatrixHandler,PlainTextHandler,RelationHandler,SplitNameHandler}.php` — all 5 exist.
   - `src/finalize/CkeditorRewriterService.php` — exists.
   - `src/analyze/HeuristicProposer.php` — exists.

   No mismatches against the TST-01 list. `MappingLoader` synonym in TST-01 → `MappingFile` per CONTEXT.md `## Specific Ideas` first bullet.

9. **`tests/Plugin/SettingsHtmlTest.php` is NOT a Craft-bootstrapped test** — verified (it's a TestCase subclass with reflection assertions). After D-12 it stays a unit test (`tests/unit/Plugin/`), not integration. CONTEXT D-12's split is correct for `PluginBootstrapTest.php` (integration) but the directory move for `tests/Plugin/` lands under `tests/unit/Plugin/`.

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|-------------------|------|-----------|----------------|---------------|
| `tests/integration/PluginBootstrapTest.php` | integration test | scaffold | `tests/PluginBootstrapTest.php` (literal `git mv`) | exact (self) |
| `tests/unit/ComposerSuggestTest.php` | unit test | static | `tests/ComposerSuggestTest.php` (literal `git mv`) | exact (self) |
| `tests/unit/NeverProductionTraitTest.php` | unit test | trait check | `tests/NeverProductionTraitTest.php` (literal `git mv`) | exact (self) |
| `tests/unit/<area>/...` (every area subdir) | unit tests | mixed | `tests/<area>/...` (literal `git mv` per subdir) | exact (self) |
| `tests/unit/analyze/HeuristicProposerTest.php` (NEW) | unit test | pure | `tests/load/SeoMigrationServiceGateTest.php` (Reflection-on-private-helper shape) | role-match |
| `tests/unit/finalize/CkeditorRewriterServiceTest.php` (NEW) | unit test | pure | `tests/load/AssetMigrationServiceRcaTest.php` (Reflection-on-private-classifier) | role-match |
| `tests/unit/fields/handlers/PlainTextHandlerTest.php` (NEW) | unit test | pure | `tests/locale/LocalePreflightTest.php` (static-helper escape hatch) | role-match |
| `tests/unit/fields/handlers/SplitNameHandlerTest.php` (NEW) | unit test | pure | `tests/locale/LocalePreflightTest.php` | role-match |
| `tests/unit/fields/handlers/RelationHandlerTest.php` (NEW) | unit test | pure | `tests/locale/LocalePreflightTest.php` | role-match |
| `tests/unit/fields/handlers/MatrixHandlerTest.php` (NEW) | unit test | pure | `tests/console/MigrateControllerSyncAssetsTest.php` (Reflection-on-static-helper) | role-match |
| `tests/unit/fields/handlers/AssetHandlerTest.php` (NEW) | unit test | pure | `tests/load/AssetMigrationServiceRcaTest.php` | role-match |
| `tests/integration/transform/TransformCharacterizationTest.php` (NEW) | integration test | fixture-driven | `tests/console/MigrateControllerSyncAssetsTest.php` (helper-extraction shape — but this is greenfield for fixture-driving) | role-match |
| `tests/fixtures/transform/input/<entity>/<id>.json` (NEW) | test fixture | data | _no analog — greenfield_ | none |
| `tests/fixtures/transform/golden/<entity>/<id>.json` (NEW) | test fixture | data | _no analog — greenfield_ | none |
| `tools/capture-transform-fixtures.php` (NEW) | dev tool | file-I/O write | `src/console/MigrateController.php::actionInstall` (NeverProductionTrait + console-stdout shape) **OR** `src/console/AnalyzeController.php` (full controller wrapping) — planner picks per D-05 | role-match |
| `tools/check-coverage.php` (NEW) | CI tool | XML parse → exit code | _no analog — greenfield_ | none |
| `src/console/RehearsalController.php` (NEW, planner-discretion) | console controller | request-response | `src/console/VerifyController.php` (multi-action controller, atomic-write artifact emission) | exact |
| `.planning/RELEASE-CHECKLIST.md` (NEW) | doc artifact | static | `CLAUDE.md` (existing root-level operator doc) | role-match |
| `.planning/rehearsal/v1.0/{cqm,simac,enreach}/` (NEW dirs) | artifact dirs | data | `.planning/phases/04.1-polish-recovery-and-env-defaults/` (per-phase artifact directory shape) | role-match |
| `CHANGELOG.md` (NEW for v1.0, may not exist yet) | doc artifact | static | _no analog — greenfield; verify Lameco convention_ | none |
| `phpunit.xml.dist` (MOD: split testsuites + coverage block) | config | static | self-extension of current single-suite shape | exact (self) |
| `composer.json` (MOD: add `test-unit` / `test-integration` / `test-coverage` scripts) | config | static | self-extension of current `scripts.test` | exact (self) |
| `.github/workflows/ci.yml` (MOD: split into `unit` + `smoke` jobs) | CI config | static | current single-job ci.yml — extend in place | exact (self) |
| `tests/bootstrap.php` (UNCHANGED) | test bootstrap | scaffold | n/a — stays put | n/a |

## Pattern Assignments

### Test reorganization (D-12, D-14) — every existing test path moves

**Mechanical move** — pure `git mv` per directory. The first plan in Phase 5 (D-14: "land it as its own plan early") executes:

```bash
git mv tests/console        tests/unit/console
git mv tests/filter         tests/unit/filter
git mv tests/load           tests/unit/load
git mv tests/locale         tests/unit/locale
git mv tests/mapping        tests/unit/mapping
git mv tests/models         tests/unit/models
git mv tests/Plugin         tests/unit/Plugin    # capital P preserved
git mv tests/source         tests/unit/source
git mv tests/verify         tests/unit/verify
git mv tests/ComposerSuggestTest.php       tests/unit/ComposerSuggestTest.php
git mv tests/NeverProductionTraitTest.php  tests/unit/NeverProductionTraitTest.php
mkdir -p tests/integration
git mv tests/PluginBootstrapTest.php       tests/integration/PluginBootstrapTest.php
```

**Namespace rewrite per file** — the moved files keep their PSR-4 namespaces matching the new directory:
```php
// before:
namespace lameco\kunstmaanmigrator\tests\console;
// after:
namespace lameco\kunstmaanmigrator\tests\unit\console;
```

`composer.json:autoload-dev.psr-4` already maps `lameco\kunstmaanmigrator\tests\` → `tests/` so PSR-4 resolves correctly without any composer change. Run `composer dump-autoload` after the move.

### MOD: `phpunit.xml.dist` — add `Integration` testsuite + `<coverage>` block (D-13)

**Read first:** `phpunit.xml.dist` whole file (12 lines).

**Current shape:**
```xml
<phpunit ... bootstrap="tests/bootstrap.php" ...>
    <testsuites>
        <testsuite name="Unit">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

**D-13 + D-08 extension:**
```xml
<phpunit ... bootstrap="tests/bootstrap.php" ...>
    <testsuites>
        <testsuite name="Unit">
            <directory>tests/unit</directory>
        </testsuite>
        <testsuite name="Integration">
            <directory>tests/integration</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <file>src/filter/MigrationFilters.php</file>
            <file>src/mapping/MappingFile.php</file>
            <file>src/finalize/CkeditorRewriterService.php</file>
            <file>src/analyze/HeuristicProposer.php</file>
            <directory>src/fields/handlers</directory>
        </include>
    </source>
    <coverage>
        <report>
            <clover outputFile="build/coverage/clover.xml"/>
        </report>
    </coverage>
</phpunit>
```

`<source>` is the PHPUnit 11 way to scope coverage (replaces `<filter><whitelist>` from PHPUnit 9). The `<coverage>` block writes clover XML; `tools/check-coverage.php` reads it. Bootstrap path stays at `tests/bootstrap.php` (D-12 keeps it at root).

### MOD: `composer.json` — add coverage + per-suite scripts (D-06, D-13)

**Read first:** `composer.json:34-36` (current `scripts` block).

**Current:**
```json
"scripts": {
    "test": "vendor/bin/phpunit"
}
```

**D-06 + D-13 + Risks #6 extension:**
```json
"scripts": {
    "test": "vendor/bin/phpunit",
    "test-unit": "vendor/bin/phpunit --testsuite Unit",
    "test-integration": "vendor/bin/phpunit --testsuite Integration",
    "test-coverage": [
        "@php -r \"if (!extension_loaded('pcov') && !extension_loaded('xdebug')) { fwrite(STDERR, \\\"install pcov or xdebug to run coverage\\n\\\"); exit(1); }\"",
        "vendor/bin/phpunit --coverage-clover=build/coverage/clover.xml",
        "@php tools/check-coverage.php"
    ]
}
```

The `@php -r ...` first step (CONTEXT.md `## Risks` PCOV-vs-Xdebug) fail-fasts when neither coverage driver is installed instead of silently producing wrong numbers. Composer chained-script syntax with `@php` runs subsequent steps only if the prior succeeds (zero-exit).

**No new packages required.** PCOV / Xdebug are system extensions; CI installs PCOV via `shivammathur/setup-php` (see `.github/workflows/ci.yml` patch below).

### MOD: `.github/workflows/ci.yml` — split into `unit` + `smoke` jobs (D-15..D-18)

**Read first:** `.github/workflows/ci.yml` whole file (15 lines, single `test` job).

**Current single-job shape:**
```yaml
name: CI
on: [push, pull_request]
jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
      - run: composer validate --strict --no-plugins
      - run: composer install --no-interaction --no-progress
      - run: composer test
```

**D-18 extension (existing `test` job becomes `unit`; new `smoke` job follows):**
```yaml
name: CI
on: [push, pull_request]
jobs:
  unit:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          coverage: pcov          # D-06: PCOV-in-CI
      - run: composer validate --strict --no-plugins
      - run: composer install --no-interaction --no-progress
      - run: composer test
      - run: composer test-coverage   # D-07 — fails build on per-module < 70%
      - uses: actions/upload-artifact@v4
        if: always()
        with:
          name: coverage-clover
          path: build/coverage/clover.xml
  smoke:
    runs-on: ubuntu-latest
    needs: unit
    steps:
      - uses: actions/checkout@v4
        with:
          path: plugin
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
      - name: Bootstrap scratch Craft
        run: composer create-project craftcms/craft scratch-craft --no-interaction
      - name: Register plugin as path repository
        working-directory: scratch-craft
        run: |
          composer config repositories.plugin path ../plugin
          composer require lameco/craft-kunstmaan-migrator @dev --no-interaction
      - name: Plugin-load smoke (D-17 — doctor exits 0)
        working-directory: scratch-craft
        env:
          CRAFT_ENVIRONMENT: dev
        run: ./craft kunstmaan-migrator/doctor
```

Composer-validate stays as the unit job's first step per D-18. The smoke job's `needs: unit` gates it on unit pass — saves CI minutes when unit is already broken. The smoke step is doctor-only (D-17): WARN exits 0 (no env source / locale config), only FAIL exits non-zero (DI broken / class autoload broken).

### NEW: `tests/integration/PluginBootstrapTest.php` (integration test, scaffold)

**Analog:** `tests/PluginBootstrapTest.php` — literal `git mv` move, namespace rewrite only.

**Move pattern:**
```php
// tests/PluginBootstrapTest.php (current)
namespace lameco\kunstmaanmigrator\tests;
// becomes:
namespace lameco\kunstmaanmigrator\tests\integration;
```

The class body, imports, and the three test methods (lines 25-52) stay verbatim. The docblock at lines 13-22 mentions Phase 5 / TST-03; once moved, that comment is satisfied — the file IS the integration-tier scaffold. After Phase 5 lands, an additional test method here may exercise the smoke-equivalent (CONTEXT.md `## Existing Code Insights` last bullet "the `tests/integration/` smoke job (D-15) can mirror this pattern").

### NEW: `tests/unit/analyze/HeuristicProposerTest.php` (unit test, pure)

**Analog:** `tests/load/SeoMigrationServiceGateTest.php` (Reflection-on-private-helper shape) and `tests/locale/LocalePreflightTest.php` (pure-helper, no Craft).

**Imports + class shell** (mirrors `LocalePreflightTest.php` lines 1-19):
```php
<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\analyze;

use lameco\kunstmaanmigrator\analyze\HeuristicProposer;
use lameco\kunstmaanmigrator\source\DoctrineEntityInfo;
use PHPUnit\Framework\TestCase;

/**
 * Phase 5 / TST-01 / D-10 — direct unit tests for the 9 deterministic
 * heuristics + 1.5 entity-aware heuristic + confidence routing.
 *
 * Pure-PHPUnit: HeuristicProposer is a Yii Component but its methods take
 * data structures (rows + craftFieldIndex) and return arrays — no Craft
 * container needed.
 */
final class HeuristicProposerTest extends TestCase
```

**Test method shape** — one test per heuristic (10 heuristics → ~12-15 tests):
```php
public function testHeuristic1FillRateZeroDropsColumn(): void
{
    $proposer = new HeuristicProposer();
    [$matched, $residual] = $proposer->propose(
        rows: [['column' => 'unused_col', 'fillRate' => 0.0, 'tableName' => 'kuma_news_page']],
        craftFieldIndex: [],
        entityInfos: [],
    );
    self::assertCount(1, $matched);
    self::assertSame('drop', $matched[0]['decision']);
    self::assertSame('high', $matched[0]['confidence']);
    self::assertSame([], $residual);
}
```

Add tests for: heuristic 1.5 (entity-aware fill), 3 (exact name), 4 (`*_id` → asset), 5 (TEXT + richtext field), 6 (`*_image` → asset), 7 (`*_date` → date), plus residual handoff and confidence-tier routing.

### NEW: `tests/unit/finalize/CkeditorRewriterServiceTest.php` (unit test, pure)

**Analog:** `tests/load/AssetMigrationServiceRcaTest.php` (Reflection-on-private-classifier).

The CkeditorRewriter has DB-touching paths (LegacyDbService + state-row lookups), so the unit-tier strategy mirrors AssetMigrationServiceRcaTest: pull a private regex / classifier method via Reflection and exercise the pure logic in isolation. End-to-end runs land in the Phase 5 / TST-02 characterization fixtures.

**Pattern from `AssetMigrationServiceRcaTest.php` lines 24-36:**
```php
final class CkeditorRewriterServiceTest extends TestCase
{
    private function rewriteToken(string $body): string
    {
        $svc = new CkeditorRewriterService(/* mock LegacyDbService + MigrationStateService */);
        $m = new ReflectionMethod($svc, 'rewriteAssetTokens');
        return (string) $m->invoke($svc, $body);
    }

    public function testRewritesImgSrcToAssetRefToken(): void { ... }
    public function testEmitsUnresolvedMarkerForMissingAsset(): void { ... }
    public function testStripsKmaClassTokens(): void { ... }
    public function testRemovesEmptyParagraphs(): void { ... }
}
```

If the rewrite methods can't be cleanly tested without DB, extract them into `public static` helpers per the Phase 4 / Plan 12 pattern (see `MigrateController::renderAssetRcaSection` which exposes static helpers for testability). Phase 5 / Plan #X for CkeditorRewriter may need a small refactor surface.

### NEW: `tests/unit/fields/handlers/{PlainText,SplitName,Relation,Matrix,Asset}HandlerTest.php` (unit tests, pure)

**Analog (general):** `tests/locale/LocalePreflightTest.php` — pure-helper escape hatch; no Craft.

**Per-handler analog:**

| Handler | Analog test | Why |
|---------|-------------|-----|
| `PlainTextHandler` (4 modes: plain/ckeditor/link/dropdown) | `LocalePreflightTest.php` (multiple input/output cases per mode) | Pure switch-on-mode; no DB |
| `SplitNameHandler` | `LocalePreflightTest.php` | Pure string split |
| `RelationHandler` | `MigrationStateServiceTerminalStateTest.php` (data-classifier-with-mock-state) | Needs mock MigrationStateService |
| `MatrixHandler` | `MigrateControllerSyncAssetsTest.php` (helper-extraction via Reflection) | Complex block-translation logic; helper-extract may be needed |
| `AssetHandler` | `MigrationStateServiceTerminalStateTest.php` | Needs mock MigrationStateService for legacy-id resolution |

**Class shell** (verbatim from `LocalePreflightTest.php` lines 1-19, namespace adjusted):
```php
<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\fields\handlers;

use lameco\kunstmaanmigrator\fields\handlers\PlainTextHandler;
use lameco\kunstmaanmigrator\fields\ResolverContext;
use PHPUnit\Framework\TestCase;

final class PlainTextHandlerTest extends TestCase
{
    public function testPlainModeCastsScalarToString(): void
    {
        $h = new PlainTextHandler('plain');
        $ctx = new ResolverContext(/* minimal stub args */);
        self::assertSame('hello', $h->resolve('hello', $ctx));
        self::assertSame('', $h->resolve(null, $ctx));
    }
    // ... one test per mode
}
```

For handlers that take mocked dependencies (Relation, Asset), use PHPUnit's `$this->createStub(MigrationStateService::class)` rather than full mocks — the unit-tier wants the smallest possible boundary.

### NEW: `tests/integration/transform/TransformCharacterizationTest.php` (integration test, fixture-driven)

**Analog:** `tests/console/MigrateControllerSyncAssetsTest.php` (Reflection + helper-extraction pattern, but the fixture-driving flavour is greenfield).

This is the canonical TST-02 deliverable. It iterates `tests/fixtures/transform/input/<entity>/*.json`, drives `TransformService` (instantiated directly per D-01), JSON-canonicalizes both sides, and diffs vs `tests/fixtures/transform/golden/<entity>/<id>.json`.

**Imports + class shell:**
```php
<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\integration\transform;

use lameco\kunstmaanmigrator\transform\TransformService;
use PHPUnit\Framework\TestCase;

/**
 * Phase 5 / TST-02 — Transform-stage characterization tests against
 * `tests/fixtures/transform/{input,golden}/<entity>/<id>.json` (D-01..D-04).
 *
 * Refresh mechanism (D-03): set `UPDATE_SNAPSHOTS=1` to rewrite missing or
 * differing goldens in place. Without it, missing goldens fail loudly —
 * never silent-create.
 *
 * Comparator: JSON-canonicalized (recursive ksort + JSON_PRETTY_PRINT |
 * JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) before string-diff to
 * survive PHP version bumps and array-key insertion-order shifts (CONTEXT
 * Risks paragraph 3).
 */
final class TransformCharacterizationTest extends TestCase
```

**Fixture-driver pattern (data provider):**
```php
/**
 * @return iterable<string, array{string, string}>  fixtureRel => [inputPath, goldenPath]
 */
public static function fixtureProvider(): iterable
{
    $base = __DIR__ . '/../../fixtures/transform';
    foreach (glob($base . '/input/*/*.json') as $inputPath) {
        $rel = substr($inputPath, strlen($base . '/input/'));
        $goldenPath = $base . '/golden/' . $rel;
        yield $rel => [$inputPath, $goldenPath];
    }
}

/**
 * @dataProvider fixtureProvider
 */
public function testTransformRowMatchesGolden(string $inputPath, string $goldenPath): void
{
    $input = json_decode((string) file_get_contents($inputPath), true);
    $service = new TransformService(/* DI stubs */);
    $actual = $service->transformRow($input);
    $actualJson = $this->canonicalize($actual);

    if (!file_exists($goldenPath) || (getenv('UPDATE_SNAPSHOTS') === '1')) {
        if (getenv('UPDATE_SNAPSHOTS') === '1') {
            @mkdir(dirname($goldenPath), 0755, true);
            file_put_contents($goldenPath, $actualJson);
            self::markTestSkipped("Golden refreshed: {$goldenPath}");
            return;
        }
        self::fail("Golden missing: {$goldenPath} (set UPDATE_SNAPSHOTS=1 to create)");
    }
    $expected = (string) file_get_contents($goldenPath);
    self::assertSame($expected, $actualJson);
}

/**
 * D-Risks #7 — recursive ksort + stable JSON encoding.
 */
private function canonicalize(mixed $value): string
{
    $recursiveSort = static function (mixed &$v) use (&$recursiveSort): void {
        if (is_array($v)) {
            // associative-only sort: preserve list ordering
            if (array_is_list($v)) {
                foreach ($v as &$item) { $recursiveSort($item); }
            } else {
                ksort($v);
                foreach ($v as &$item) { $recursiveSort($item); }
            }
        }
    };
    $recursiveSort($value);
    return json_encode(
        $value,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    ) . "\n";
}
```

The `array_is_list` check preserves list ordering (matrix block order, asset arrays) while sorting associative arrays. JSON_PRETTY_PRINT keeps diffs human-readable.

### NEW: `tools/capture-transform-fixtures.php` (dev tool — D-05; planner picks shape)

**Analog (script flavour):** None — `tools/` is greenfield. The closest existing script-pattern is the `composer scripts` `@php -r ...` one-liner, but a multi-line PHP script bootstrapping autoload + ExtractService is greenfield.

**Analog (Dev console controller flavour):** `src/console/AnalyzeController.php` — same shape as any controller, but registered via Plugin::config()'s `controllerMap` only when `CRAFT_ENVIRONMENT=dev`. CONTEXT D-05 leaves the choice to the planner.

**Recommended shape (script):** The script lives outside Craft DI to avoid pulling NeverProductionTrait into the capture path (the operator runs this against the live CQM dump on a dev host).

```php
#!/usr/bin/env php
<?php
// tools/capture-transform-fixtures.php
// Phase 5 / TST-02 / D-05 — one-time capture against live CQM dump.
// Re-run when the corpus needs to grow. NOT part of the test runner.

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// Operator supplies KUNSTMAAN_SOURCE_PATH + CRAFT_LEGACY_DB_* env.
// Minimal Craft bootstrap — see src/console/AnalyzeController.php for the
// kindred pattern (Plugin::getInstance()->extractService->extractAll(...)).

$plugin = /* bootstrap Craft + plugin per craft/cms docs */;
$rows = $plugin->extractService->extractAll(/* filters */);
foreach ($rows as $entity => $entityRows) {
    foreach ($entityRows as $id => $row) {
        $path = __DIR__ . "/../tests/fixtures/transform/input/{$entity}/{$id}.json";
        @mkdir(dirname($path), 0755, true);
        file_put_contents(
            $path,
            json_encode($row, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
    }
}
echo "Captured " . count($rows) . " entity rowsets to tests/fixtures/transform/input/\n";
```

### NEW: `tools/check-coverage.php` (CI tool — D-07)

**Analog:** None — pure greenfield. The closest pattern is the `tools/capture` script above (autoload + read input + exit code) but the XML-parsing logic is greenfield.

**Shape (~50 LOC per CONTEXT D-07):**

```php
#!/usr/bin/env php
<?php
// tools/check-coverage.php
// Phase 5 / TST-01 / D-07 — per-module 70% line-coverage gate.
// Reads build/coverage/clover.xml; exits non-zero if ANY of the 5 named
// modules drops below 70%. Per-module, NOT aggregate (TST-01 wording).

declare(strict_types=1);

const THRESHOLD = 70.0;
const MODULES = [
    'src/filter/MigrationFilters.php',
    'src/mapping/MappingFile.php',
    'src/finalize/CkeditorRewriterService.php',
    'src/analyze/HeuristicProposer.php',
    // src/fields/handlers/ — every file under this dir.
];

$cloverPath = __DIR__ . '/../build/coverage/clover.xml';
if (!is_file($cloverPath)) {
    fwrite(STDERR, "FAIL: clover.xml not found at {$cloverPath}\n");
    exit(2);
}
$xml = simplexml_load_file($cloverPath);
if ($xml === false) {
    fwrite(STDERR, "FAIL: could not parse clover.xml\n");
    exit(2);
}

$failures = [];
foreach ($xml->project->file as $file) {
    $path = (string) $file['name'];
    $rel = ltrim(str_replace(realpath(__DIR__ . '/..'), '', realpath($path) ?: $path), '/');
    $isModule = in_array($rel, MODULES, true) || str_starts_with($rel, 'src/fields/handlers/');
    if (!$isModule) {
        continue;
    }
    $metrics = $file->metrics;
    $statements = (int) $metrics['statements'];
    $covered    = (int) $metrics['coveredstatements'];
    $pct = $statements === 0 ? 100.0 : ($covered / $statements) * 100.0;
    $marker = $pct >= THRESHOLD ? 'OK  ' : 'FAIL';
    fwrite(STDOUT, sprintf("  %s %5.1f%%  %s\n", $marker, $pct, $rel));
    if ($pct < THRESHOLD) {
        $failures[] = sprintf('%s: %.1f%% < %.0f%%', $rel, $pct, THRESHOLD);
    }
}

if ($failures !== []) {
    fwrite(STDERR, "\nCoverage gate FAILED:\n  - " . implode("\n  - ", $failures) . "\n");
    exit(1);
}
fwrite(STDOUT, "\nCoverage gate OK — all modules ≥ " . THRESHOLD . "%\n");
exit(0);
```

Parsing-strategy note: `simplexml_load_file` is enough for clover XML; no need for a library. The module-membership check uses both the explicit list AND a directory prefix (`src/fields/handlers/`) so future handler additions auto-enroll.

### NEW: `src/console/RehearsalController.php` (console controller, request-response — planner-discretion per CONTEXT D-23)

**Analog:** `src/console/VerifyController.php` (multi-action controller, atomic-write artifact emission). VerifyController is the closest kin: same role (post-process committed/captured artifacts), same `$baseline` / `$output` / `$outputDir` flag shape, same multi-action layout.

**Imports + class shell** (`VerifyController.php` lines 1-50, adapted):
```php
<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\console;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use lameco\kunstmaanmigrator\Plugin;
use yii\console\ExitCode;

/**
 * `rehearsal-check` console controller — Phase 5 / TST-04 / D-21..D-23.
 *
 * Three mechanical gates over a committed rehearsal directory:
 *   1. Counts within tolerance — parses [1/2] Count-match block from VERIFY.md
 *   2. Zero unresolved CKEditor tokens — greps REPORT.md for [NT<id>] / [M<id>]
 *      / asset:<n> patterns (allow-list at <dir>/allow-tokens.txt opt-in)
 *   3. All assets RCA-tagged — every row in REPORT.md ## Asset RCA has a non-
 *      empty reason
 *
 * IMPORTANT: This controller does NOT carry NeverProductionTrait (D-22).
 * The command is read-only over committed artifacts on disk — never opens
 * the legacy DB connection, never touches kunstmaanmigrator_state. Applying
 * the trait would gate a CI step on CRAFT_ENVIRONMENT != production for no
 * purposeful reason. Deliberate departure from the "every CLI carries the
 * trait" pattern; documented here so a future maintainer doesn't reflexively
 * add it.
 */
class RehearsalController extends Controller
{
    // INTENTIONALLY NO `use NeverProductionTrait;` — see class docblock.

    public bool $verbose = false;
    public ?string $allowTokens = null;  // path to allow-tokens.txt opt-in

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['verbose', 'allowTokens']);
    }

    /**
     * `kunstmaan-migrator/rehearsal-check <dir>` — D-21..D-23.
     *
     * @param string $dir  Path to committed rehearsal directory (e.g.
     *                     `.planning/rehearsal/v1.0/cqm`).
     */
    public function actionCheck(string $dir): int
    {
        $this->stdout("Rehearsal check: {$dir}\n", Console::FG_CYAN);

        $failures = [];
        $failures = array_merge($failures, $this->checkCountsWithinTolerance($dir));
        $failures = array_merge($failures, $this->checkZeroUnresolvedCkeditorTokens($dir));
        $failures = array_merge($failures, $this->checkAllAssetsRcaTagged($dir));

        if ($failures !== []) {
            $this->stderr("\nFailures:\n", Console::FG_RED);
            foreach ($failures as $f) {
                $this->stderr("  - {$f}\n", Console::FG_RED);
            }
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $this->stdout("\nAll gates passed.\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    /** @return array<int, string> failures */
    private function checkCountsWithinTolerance(string $dir): array { /* parses VERIFY.md */ return []; }
    private function checkZeroUnresolvedCkeditorTokens(string $dir): array { /* greps REPORT.md */ return []; }
    private function checkAllAssetsRcaTagged(string $dir): array { /* parses ## Asset RCA */ return []; }
}
```

**Plugin::config() registration** (mirror `src/Plugin.php:130-132` shape — NO sibling-DI wiring needed since RehearsalController has no consumers):

The controller goes in Plugin's `controllerMap` (Yii's mechanism for registering console controllers), NOT in the `components` block — same as every other controller in `src/console/`. The plugin currently auto-discovers controllers via Craft's plugin scaffolding (verify with `grep -n "controllerMap\|controllerNamespace" src/Plugin.php`); RehearsalController auto-registers when placed in `src/console/`.

**Action-shell pattern (D-22 deliberate omission):**
- VerifyController: `if (($gate = $this->enforceNeverProduction()) !== null) { return $gate; }` — line 282.
- RehearsalController: NO such gate — first statement is the cyan stdout banner.

**Atomic-write seam (if RehearsalController emits its own report):** Use `Plugin::getInstance()->mappingFile->writeAtomic(...)` per `VerifyController.php:267` shape. CONTEXT D-23 leaves emit-vs-stream open; recommend stream-only for v1.0 (failure summary to stdout/stderr) — fewer files, simpler audit trail.

### NEW: `.planning/RELEASE-CHECKLIST.md` (doc artifact)

**Analog:** `CLAUDE.md` (root-level operator doc) — same shape: H1 + role description + numbered/bulleted operational steps. `.planning/PROJECT.md` is the closest planning-doc analog.

**Shape (D-25 verbatim 9 steps):**
```markdown
# v1.0 Release Checklist

Pre-tag gate. Every step must be green before pushing the v1.0 tag.

## Steps

1. [ ] `composer validate --strict` green
2. [ ] `composer test` green (unit + integration suites)
3. [ ] `composer test-coverage` green (every TST-01 module ≥ 70% line coverage)
4. [ ] CI smoke job green on a recent commit (HEAD-of-main last green)
5. [ ] CQM `rehearsal-check` exits 0 against `.planning/rehearsal/v1.0/cqm/`
6. [ ] Simac + enreach rehearsal logs captured under `.planning/rehearsal/v1.0/{simac,enreach}/`
       (advisory; failures don't block — see Phase 5 / D-19)
7. [ ] `CHANGELOG.md` rewritten for v1.0
8. [ ] `composer.json` version bumped to `1.0.0` (only if Lameco's release process
       pins versions in composer.json — most Craft plugins don't; verify before adding)
9. [ ] Tag pushed; `STATE.md` updated; milestone closed via `/gsd-complete-milestone`

## Pre-publish gate (NOT part of v1.0 ship)

- Anonymize `.planning/rehearsal/v1.0/cqm/` fixtures if repo goes public.
  CQM rehearsal uses verbatim NL-diacritic / image-reference data per Phase 5 / D-04;
  scrub before publishing under any non-`lameco/` namespace.
```

### NEW: `.planning/rehearsal/v1.0/{cqm,simac,enreach}/` (artifact directories)

**Analog:** `.planning/phases/04.1-polish-recovery-and-env-defaults/` — same per-phase artifact-directory shape. Files committed verbatim from operator-captured runs.

**Per-directory contents (D-20 verbatim):**
```
.planning/rehearsal/v1.0/cqm/
├── REPORT.md                # copied from storage/migration/REPORT.md
├── VERIFY.md                # copied from storage/migration/VERIFY-<ts>.md (timestamp dropped)
├── baseline.json            # copied from storage/migration/baseline.json
├── doctor-output.txt        # captured stdout/stderr of kunstmaan-migrator/doctor
├── mapping-summary.txt      # accepted/dropped/needs-review/proposed counts
└── allow-tokens.txt         # OPTIONAL — operator-curated allow-list for CK token gate
```

The committed artifacts are the contract — `RehearsalController::actionCheck` reads ONLY these files, never re-runs `verify` or `doctor` itself (CONTEXT D-22 + Risks paragraph 5).

### NEW: `CHANGELOG.md` (greenfield for v1.0 — verify Lameco convention first)

**Analog:** None in this repo (no existing CHANGELOG.md). CONTEXT.md `## Decisions` Claude's-discretion bullet 6 explicitly defers to "what other Lameco plugins ship with" — planner verifies against `~/Sites/craft-kunstmaan-migrator/CHANGELOG.md` (the v1.x reference) and any other `~/Sites/craft-*/CHANGELOG.md` first.

If Lameco follows Keep-a-Changelog (most Craft plugins do):
```markdown
# Changelog

All notable changes to this plugin will be documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-XX-XX

### Added
- Kunstmaan → Craft 5 migration plugin (clean rewrite of v1.x).
- Five-stage ETL pipeline: extract → transform → load → finalize → verify.
- AI-assisted mapping proposals (Anthropic Haiku) with 9 deterministic heuristics first.
- Optional SEOmatic + Retour adapters (runtime detection).
- ...

### Changed (vs v1.x)
- Single `mapping.yaml` with per-row status (replaces v1's three-file scheme).
- Plugin-owned legacy DB connection (no host `legacyDb` Yii component required).
- ...
```

## Shared Patterns

### Reflection-on-private-helper testability

**Source:** `tests/load/AssetMigrationServiceRcaTest.php:24-36`, `tests/load/SeoMigrationServiceGateTest.php:26-32`, `tests/load/MigrationStateServiceTerminalStateTest.php:33-49`.

**Apply to:** All 7 D-10 unit tests (HeuristicProposer, CkeditorRewriter, 5 handlers).

```php
private function classify(\Throwable $e, array $row = []): string
{
    $svc = new ServiceClass(/* minimal stubs */);
    $m = new ReflectionMethod($svc, 'privateHelperName');
    return (string) $m->invoke($svc, $e, $row);
}
```

PHP 8.1+ — `ReflectionMethod::setAccessible()` is no longer needed (default true since 8.1; explicitly deprecated in 8.5). Comment from `AssetMigrationServiceRcaTest.php:33-34` is the canonical note.

### Static-helper-extraction for Craft-DI-touching action bodies

**Source:** `src/console/MigrateController.php:1127` (`renderSyncAssetsReport`), `:1638` (`renderSkippedStagesSection`), `:1662` (`renderAssetRcaSection`); tested via Reflection in `tests/console/MigrateControllerSyncAssetsTest.php:199-201` and `tests/console/MigrateControllerReportEmptyStateTest.php:31-32`.

**Apply to:** Wherever a Phase 5 unit test needs to exercise logic that's currently inside an action body (e.g., RehearsalController gate parsers — `checkCountsWithinTolerance` / `checkZeroUnresolvedCkeditorTokens` / `checkAllAssetsRcaTagged` — should be `public static` so tests can drive them with fixture strings).

```php
// In RehearsalController:
public static function parseCountMatchBlock(string $verifyMd): array { /* pure */ }
// In RehearsalControllerTest (Phase 5):
$rm = new ReflectionMethod(RehearsalController::class, 'parseCountMatchBlock');
$result = $rm->invoke(null, file_get_contents($fixture));
```

### Pure-PHPUnit class shell (no Craft bootstrap)

**Source:** `tests/locale/LocalePreflightTest.php:1-19`, `tests/source/KunstmaanEnvReaderTest.php:1-29`, `tests/verify/CountGateServiceTest.php:1-23`.

**Apply to:** All Phase 5 unit-tier tests. Class shell:
```php
<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\<area>;

use lameco\kunstmaanmigrator\<source-namespace>\<Class>;
use PHPUnit\Framework\TestCase;

final class <Class>Test extends TestCase
{
    // tests
}
```

`final class` is the project convention (verified across 14 existing test files — every one is `final`).

### Atomic-write artifact emission (when controller writes a report)

**Source:** `src/console/VerifyController.php:265-270` (VERIFY-<ts>.md), `:295-300` (baseline.json via `writeAtomicJson`); `src/console/MigrateController.php:892-897` (SYNC-ASSETS-<ts>.md).

**Apply to:** RehearsalController if/when it emits its own summary report (recommend stream-only for v1.0 — see RehearsalController section above).

```php
$rendered = self::renderReport(/* ... */);
if (!$plugin->mappingFile->writeAtomic($reportPath, $rendered)) {
    $this->stderr("  FAIL could not write {$reportPath}\n", Console::FG_RED);
    return ExitCode::UNSPECIFIED_ERROR;
}
$this->stdout("  OK   report written → {$reportPath}\n", Console::FG_GREEN);
```

### NeverProductionTrait gate — DELIBERATELY OMITTED on RehearsalController (D-22)

**Source:** Every other controller carries it (`MigrateController.php:43`, `VerifyController.php:40`, `DoctorController.php`, `AnalyzeController.php`, `MapController.php`).

**Apply to:** **DO NOT** apply to `RehearsalController`. CONTEXT D-22 explicitly: "The command is read-only over committed artifacts on disk — it never opens the legacy DB connection, never reads `KUNSTMAAN_SOURCE_PATH`, never touches `kunstmaanmigrator_state`. Applying the trait would gate a CI step on `CRAFT_ENVIRONMENT != production` for no purposeful reason."

Document the deliberate departure in the class docblock (see RehearsalController shell above).

### CI step idiom (composer test → composer test-coverage → upload artifact)

**Source:** `.github/workflows/ci.yml` (current — composer validate → install → test).

**Apply to:** Phase 5 split (`unit` job adds coverage step; `smoke` job runs scratch-Craft).

The `coverage: pcov` line on the `shivammathur/setup-php` action installs PCOV at the system level — that's the only place PCOV needs to be specified. No `composer require` change needed (CONTEXT D-06: "Local devs aren't required to install PCOV").

## No Analog Found

| File | Role | Data Flow | Reason |
|------|------|-----------|--------|
| `tests/fixtures/transform/input/<entity>/<id>.json` | test data | static | First fixture corpus in repo. Operator-captured per D-01..D-05; planner specifies the directory layout but the file contents come from `tools/capture-transform-fixtures.php`. |
| `tests/fixtures/transform/golden/<entity>/<id>.json` | test data | static | Generated by `TransformCharacterizationTest.php` first run with `UPDATE_SNAPSHOTS=1` (D-03). |
| `tools/check-coverage.php` | CI tool | XML-parse → exit code | First `tools/` directory entry. ~50 LOC of clover XML parsing — no library needed (PHP's `simplexml_load_file` is sufficient). Pattern designed from scratch — see file shell above. |
| `tools/capture-transform-fixtures.php` | dev tool | DB-read → file-I/O | First operator dev tool. Closest pattern is the `composer scripts` `@php -r` form, but multi-line script is greenfield. |
| `.planning/rehearsal/v1.0/{cqm,simac,enreach}/` (the directory shape itself) | artifact dir | data | First rehearsal-corpus committed under `.planning/`. Closest organizational analog is `.planning/phases/04.1-.../` per-phase-artifact directories. |
| `CHANGELOG.md` | doc | static | First CHANGELOG.md in this repo. Planner verifies Lameco convention against `~/Sites/craft-kunstmaan-migrator/CHANGELOG.md` (v1.x) and other `~/Sites/craft-*/CHANGELOG.md` files BEFORE drafting. |
| `.planning/RELEASE-CHECKLIST.md` | doc | static | First release checklist; analog is `CLAUDE.md` shape (root-level operator doc) — different content but same H1+sections+steps shape. D-25 specifies the 9-step body verbatim. |

## Metadata

**Analog search scope:** `src/console/`, `src/load/`, `src/finalize/`, `src/analyze/`, `src/fields/handlers/`, `src/mapping/`, `src/filter/`, `tests/` (all subdirs), `.github/workflows/`, `composer.json`, `phpunit.xml.dist`, `.planning/phases/04*/`.
**Files scanned:** ~30 PHP files + 3 config files + 14 existing tests + recent phase artifacts.
**Pattern extraction date:** 2026-04-27.
