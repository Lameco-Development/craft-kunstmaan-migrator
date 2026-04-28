---
plan: 09
phase: 04
title: "VerifyController + Plugin DI wiring (config + init) for Phase 4 services"
wave: 4
depends_on: ["04-02", "04-03", "04-04", "04-06", "04-07", "04-08"]
files_modified:
  - src/console/VerifyController.php
  - src/Plugin.php
autonomous: true
requirements_addressed: [VER-01, VER-02, VER-03, ADP-01, ADP-02]
---

# Plan 04-09: VerifyController + Plugin DI wiring

## Objective

Two coupled deliverables landing in one plan because they together create the executable verify surface and the runtime wiring that makes every Wave 2/3 service usable:

1. **`src/console/VerifyController.php`** (NEW) — top-level console controller with three actions (`actionIndex`, `actionCaptureBaseline`, `actionCaptureBaselineHtml`), mirroring v1's surface and producing `storage/migration/VERIFY-<timestamp>.md` (D-61 markdown-only). Filter-aware (`--entities` / `--locales` / `--since`) per ROADMAP Phase 2 success criterion 5 (CLI-surface uniformity across all five top-level commands).
2. **`src/Plugin.php`** (MODIFY) — extend `config()` with 8 new component registrations and extend `init()` with the Phase 4 sibling-DI wiring graph (PATTERNS flag #7).

These two changes are coupled because VerifyController calls `Plugin::getInstance()->countGateService` etc. — the controller cannot work without the registrations, and the registrations have no consumer without the controller.

## Context

- D-54: VerifyController body is a verbatim port from v1 with controlled reshapes.
- D-58: three actions, B1 fix preserved (lives inside SpotCheckUrlFetcher per Plan 04-03), Gate 1 + Gate 2.
- D-60: tolerance from `Settings::$verifyCountTolerance` + CLI `--count-tolerance=` override (mirrors Phase 2 / D-10 Settings+CLI merge pattern).
- D-61: markdown-only `VERIFY-<ts>.md`. NO JSON sidecar.
- D-59: baseline.json at stable path `storage/migration/baseline.json` (NOT timestamped).
- Plugin::init() sibling-DI is the Phase 02.1 / commit 75a95bc pattern; Phase 4 dep graph is documented in PATTERNS.md flag #7.
- D-57: Settings table-name overrides are wired here (`$plugin->seoMigrationService->seoTableName = $settings->seoTableName ?: 'kuma_seo'`).
- **ROADMAP Phase 2 success criterion 5 — CLI surface uniformity:** "The five top-level CLI commands (`doctor`, `analyze`, `map`, `migrate`, `verify`) all accept `--entities=`, `--locales=`, `--since=`." `verify` is one of those five. Without the flags, an operator cannot scope a partial-scope rehearsal (e.g. counts for only `news` + `cases` since `2024-01-01`). The flag declaration + `FilterFactory::fromCli` parsing on the controller is the load-bearing surface — operators get a uniform CLI shape across all five commands. AnalyzeController (Phase 02.1) is the established template (`public ?string $entities = null`, `public ?string $locales = null`, `public ?string $since = null`, declared in `options()`, wired via `$plugin->filterFactory->fromCli(...)`).
- **Filter plumbing carve-out (pragmatic minimum):** `BaselineCounterService::capture()` and `CountGateService::run()` per Plan 04-04 currently take no `MigrationFilters` parameter. For v1.0 this is acceptable — `verify` Gate 2's URL list is already operator-curated (`storage/migration/spot-check-urls.txt` per D-58), so URL-gate scoping is operator-controlled by definition; Gate 1 reads counts of *all* entries / assets / redirects, which on a CQM-style rehearsal is also the operator-intended scope (verify the *whole* migration, not a slice). The flag declaration + `FilterFactory::fromCli` parsing satisfy criterion 5 (uniform CLI surface). Deep filter plumbing into `BaselineCounterService` / `CountGateService` is deferred — if rehearsal proves a real operator need (e.g. partial-scope re-runs after fixing one entity type), it lands as a follow-up in Phase 5 or later.

## Tasks

<task id="01">
  <action>
Extend `src/Plugin.php`'s `config()` method (currently around lines 98-137 — find the components array and append before the closing of the array). Add 8 new entries:

```php
// Phase 4 additions — adapter services + verify services.
'seoMigrationService'        => SeoMigrationService::class,
'seomaticPayloadBuilder'     => SeomaticPayloadBuilder::class,
'redirectMigrationService'   => RedirectMigrationService::class,
'baselineCounterService'     => BaselineCounterService::class,
'countGateService'           => CountGateService::class,
'snapshotDiffer'             => SnapshotDiffer::class,
'spotCheckUrlFetcher'        => SpotCheckUrlFetcher::class,
'captureBaselineHtmlService' => CaptureBaselineHtmlService::class,
```

Add corresponding `use` imports at the top of `src/Plugin.php`:
- `use lameco\kunstmaanmigrator\load\SeoMigrationService;`
- `use lameco\kunstmaanmigrator\load\SeomaticPayloadBuilder;`
- `use lameco\kunstmaanmigrator\load\RedirectMigrationService;`
- `use lameco\kunstmaanmigrator\verify\BaselineCounterService;`
- `use lameco\kunstmaanmigrator\verify\CountGateService;`
- `use lameco\kunstmaanmigrator\verify\SnapshotDiffer;`
- `use lameco\kunstmaanmigrator\verify\SpotCheckUrlFetcher;`
- `use lameco\kunstmaanmigrator\verify\CaptureBaselineHtmlService;`

Extend the `@property-read` PHPDoc block at the top of the class (currently around lines 53-87) with the 8 new components (mirrors Phase 3's expansion at lines 78-86) for IDE autocomplete.

Then extend `init()` (after the existing sibling-DI wiring block — search for the `EntryMigrationService` wiring around line 260 and append after). Append:

```php
// Phase 4 Adapter wiring — D-54 / D-56 / D-57 / PATTERNS flag #7.

// SeomaticPayloadBuilder needs migrationState for kuma_media → Craft asset id resolution.
$this->seomaticPayloadBuilder->migrationState = $this->migrationStateService;

// SeoMigrationService — 5 sibling deps + sites map from resolveSitesMap() (PATTERNS flag #3).
$this->seoMigrationService->legacyDb     = $this->legacyDbService;
$this->seoMigrationService->stateService = $this->migrationStateService;
$this->seoMigrationService->seoPayload   = $this->seomaticPayloadBuilder;
$this->seoMigrationService->sites        = $this->resolveSitesMap();
// $filters wired via FilterFactory at command-invocation time, not init() (Phase 02.1 pattern).

// RedirectMigrationService — 3 sibling deps + sites map.
$this->redirectMigrationService->legacyDb     = $this->legacyDbService;
$this->redirectMigrationService->stateService = $this->migrationStateService;
$this->redirectMigrationService->sites        = $this->resolveSitesMap();
// $filters wired at invocation time.

// CaptureBaselineHtmlService → SpotCheckUrlFetcher.
$this->captureBaselineHtmlService->fetcher = $this->spotCheckUrlFetcher;

// D-57: Settings table-name overrides wired here so adapter services pick them up.
$settings = $this->getSettings();
if (is_string($settings->seoTableName) && $settings->seoTableName !== '') {
    $this->seoMigrationService->seoTableName = $settings->seoTableName;
}
if (is_string($settings->redirectsTableName) && $settings->redirectsTableName !== '') {
    $this->redirectMigrationService->redirectsTableName = $settings->redirectsTableName;
}

// BaselineCounterService — pure-read; no sibling deps in v2 light shape (D-59).
// CountGateService — pure-read; no sibling deps (expectedCounts arg passed at call time).
// SnapshotDiffer — pure-function; zero deps. Ported but unused at v1.0 (deferred --deep flag).
// SpotCheckUrlFetcher — uses Craft::createGuzzleClient; zero plugin-internal deps.
```
  </action>
  <read_first>
    - src/Plugin.php (entire file — must understand `config()` array shape and `init()` existing sibling-DI block to append correctly)
    - .planning/phases/04-adapters-verify-settings/04-PATTERNS.md (Plugin.php section — exact append blocks, advisor flag #7 dep graph)
    - .planning/phases/02.1-source-introspection/02.1-CONTEXT.md (Phase 02.1 / D-33 + commit 75a95bc Plugin::init() sibling DI pattern)
    - src/load/SeoMigrationService.php (Plan 04-06 — confirm public DI surface matches the wiring assignments)
    - src/load/RedirectMigrationService.php (Plan 04-07 — confirm public DI surface)
    - src/load/SeomaticPayloadBuilder.php (Plan 04-02 — confirm `migrationState` public property)
    - src/verify/CaptureBaselineHtmlService.php (Plan 04-08 — confirm `fetcher` public property)
  </read_first>
  <acceptance_criteria>
    - `grep -c "'seoMigrationService'\\s*=>" src/Plugin.php` returns at least `1`
    - `grep -c "'redirectMigrationService'\\s*=>" src/Plugin.php` returns at least `1`
    - `grep -c "'seomaticPayloadBuilder'\\s*=>" src/Plugin.php` returns at least `1`
    - `grep -c "'baselineCounterService'\\s*=>" src/Plugin.php` returns at least `1`
    - `grep -c "'countGateService'\\s*=>" src/Plugin.php` returns at least `1`
    - `grep -c "'snapshotDiffer'\\s*=>" src/Plugin.php` returns at least `1`
    - `grep -c "'spotCheckUrlFetcher'\\s*=>" src/Plugin.php` returns at least `1`
    - `grep -c "'captureBaselineHtmlService'\\s*=>" src/Plugin.php` returns at least `1`
    - `grep -c 'use lameco\\\\kunstmaanmigrator\\\\load\\\\SeoMigrationService' src/Plugin.php` returns `1`
    - `grep -c 'use lameco\\\\kunstmaanmigrator\\\\load\\\\RedirectMigrationService' src/Plugin.php` returns `1`
    - `grep -c 'use lameco\\\\kunstmaanmigrator\\\\verify\\\\CountGateService' src/Plugin.php` returns `1`
    - `grep -c 'use lameco\\\\kunstmaanmigrator\\\\verify\\\\BaselineCounterService' src/Plugin.php` returns `1`
    - `grep -c '\$this->seoMigrationService->legacyDb' src/Plugin.php` returns at least `1`
    - `grep -c '\$this->seoMigrationService->stateService' src/Plugin.php` returns at least `1`
    - `grep -c '\$this->seoMigrationService->seoPayload' src/Plugin.php` returns at least `1`
    - `grep -c '\$this->seoMigrationService->sites' src/Plugin.php` returns at least `1`
    - `grep -c '\$this->redirectMigrationService->legacyDb' src/Plugin.php` returns at least `1`
    - `grep -c '\$this->redirectMigrationService->sites' src/Plugin.php` returns at least `1`
    - `grep -c '\$this->seomaticPayloadBuilder->migrationState' src/Plugin.php` returns at least `1`
    - `grep -c '\$this->captureBaselineHtmlService->fetcher' src/Plugin.php` returns at least `1`
    - `grep -c 'resolveSitesMap()' src/Plugin.php` returns at least `4` (existing 1 entry-migration call + 2 new wirings + the helper definition)
    - `grep -c '\$settings->seoTableName' src/Plugin.php` returns at least `1` (D-57 override applied)
    - `grep -c '\$settings->redirectsTableName' src/Plugin.php` returns at least `1` (D-57 override applied)
    - `composer test` exits `0` (PluginBootstrapTest must still load — verifies registrations are valid Yii component classes)
    - `php -l src/Plugin.php` outputs `No syntax errors detected`
  </acceptance_criteria>
</task>

<task id="02">
  <action>
Create `src/console/VerifyController.php`. Body is a verbatim port from `~/Sites/craft-kunstmaan-migrator/src/bridge/console/controllers/VerifyController.php` with controlled reshapes per PATTERNS.md "VerifyController" section.

Class signature — declare the three filter-flag properties alongside the existing tolerance / output / baseline properties (mirrors AnalyzeController template per Phase 02.1):

```php
<?php
declare(strict_types=1);

namespace lameco\kunstmaanmigrator\console;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use lameco\kunstmaanmigrator\NeverProductionTrait;
use lameco\kunstmaanmigrator\Plugin;
use yii\console\ExitCode;

class VerifyController extends Controller
{
    use NeverProductionTrait;

    public bool $verbose       = false;
    public ?string $baseline   = null;
    public ?string $output     = null;
    public ?string $outputDir  = null;
    public ?string $baselineDir = null;
    public ?float $countTolerance     = null;  // D-60: CLI override; null → use Settings default.
    public ?float $urlDiffThreshold   = null;  // D-60: CLI override; null → use Settings default.
    public ?string $urlSpotCheck      = null;  // path to spot-check-urls.txt

    // ROADMAP Phase 2 success criterion 5: five top-level commands all accept --entities / --locales / --since.
    // Mirrors AnalyzeController (Phase 02.1). The flags parse via FilterFactory::fromCli at action entry.
    // Deep plumbing into BaselineCounterService / CountGateService is deferred — see plan body "filter
    // plumbing carve-out". The load-bearing requirement is uniform CLI surface; the parsing happens here.
    public ?string $entities = null;
    public ?string $locales  = null;
    public ?string $since    = null;

    public function options($actionID): array
    {
        $base = ['verbose'];
        if ($actionID === 'index') {
            // index gate runs both Gate 1 (counts) and Gate 2 (URL diff). Filter flags scope reporting.
            $base = array_merge($base, [
                'baseline', 'urlSpotCheck', 'baselineDir', 'countTolerance', 'urlDiffThreshold',
                'entities', 'locales', 'since',
            ]);
        }
        if ($actionID === 'capture-baseline') {
            // capture-baseline writes the counts snapshot. Filter flags accepted for CLI uniformity
            // (criterion 5); current BaselineCounterService::capture() is filter-naive (carve-out documented).
            $base = array_merge($base, ['output', 'entities', 'locales', 'since']);
        }
        if ($actionID === 'capture-baseline-html') {
            // URL-list-driven; filter flags accepted for CLI uniformity (criterion 5) but not load-bearing
            // since spot-check-urls.txt is operator-curated.
            $base = array_merge($base, ['outputDir', 'urlSpotCheck', 'entities', 'locales', 'since']);
        }
        return array_merge(parent::options($actionID), $base);
    }

    public function optionAliases(): array
    {
        return array_merge(parent::optionAliases(), ['v' => 'verbose']);
    }
```

**Filter parsing at action entry — mirrors AnalyzeController (Phase 02.1) exactly:**

In each of the three actions, immediately after `enforceNeverProduction()`, parse the filter flags via `FilterFactory::fromCli`:

```php
$plugin = Plugin::getInstance();
// ROADMAP criterion 5: parse filter flags. Mirrors AnalyzeController step 2.
$filters = $plugin->filterFactory->fromCli($this->entities, $this->locales, $this->since);
```

The `$filters` object is then passed down to services that accept it (currently none in Phase 4 — see carve-out). Keep the variable in scope so the plumbing seam is in place; if `BaselineCounterService` / `CountGateService` later grow filter-aware overloads, the wire is already cut.

**actionCaptureBaseline (D-59 light counts — reshape v1):** the body of v1's `actionCaptureBaseline` (lines 233-255) ends with `BaselineSnapshotService::capture()` + JSON write. v2 reshape:

```php
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
    $plugin->mappingFile->writeAtomicJson($path, $snapshot);

    $this->stdout("  OK   baseline.json written to {$path}\n", Console::FG_GREEN);
    return ExitCode::OK;
}
```

**actionCaptureBaselineHtml (port verbatim with v2 path reshape):**

```php
public function actionCaptureBaselineHtml(): int
{
    if (($gate = $this->enforceNeverProduction()) !== null) {
        return $gate;
    }
    $this->stdout("Verify (capture-baseline-html): URL spot-check fetches\n", Console::FG_CYAN);

    $plugin = Plugin::getInstance();
    // Criterion 5: filter flags accepted for CLI uniformity. spot-check-urls.txt is operator-curated,
    // so URL-list scoping is already operator-controlled; $filters parsed but unused at v1.0.
    $filters = $plugin->filterFactory->fromCli($this->entities, $this->locales, $this->since);
    $urlList = $this->urlSpotCheck ?? Craft::$app->path->getStoragePath() . '/migration/spot-check-urls.txt';
    $outDir  = $this->outputDir   ?? Craft::$app->path->getStoragePath() . '/migration/baseline';
    try {
        $count = $plugin->captureBaselineHtmlService->capture($urlList, $outDir);
    } catch (\Throwable $e) {
        $this->stderr("  FAIL {$e->getMessage()}\n", Console::FG_RED);
        return ExitCode::UNSPECIFIED_ERROR;
    }
    $this->stdout("  OK   {$count} baseline HTML files written to {$outDir}\n", Console::FG_GREEN);
    return ExitCode::OK;
}
```

**actionIndex (port verbatim with D-60 + D-61 reshapes):** body mirrors v1 lines 84-226. Key reshapes:

1. **Filter parsing FIRST (criterion 5):** after `enforceNeverProduction()`, immediately parse `$filters = $plugin->filterFactory->fromCli($this->entities, $this->locales, $this->since);`. v1.0 `CountGateService::run()` and `BaselineCounterService::capture()` are filter-naive (Plan 04-04) — `$filters` is kept in scope so the plumbing seam exists for a future filter-aware overload, and so the operator's intent is recorded in the verify run's verbose log when verbose=true. Document this carve-out via a code comment at the parse site.
2. **Tolerance source (D-60):** `$tolerance = $this->countTolerance ?? $plugin->getSettings()->verifyCountTolerance ?? 0.01;` (CLI override → Settings → hardcoded default).
3. **URL diff threshold (D-60):** `$threshold = $this->urlDiffThreshold ?? $plugin->getSettings()->verifyUrlDiffThreshold ?? 0.05;`
4. **Baseline read (D-59):** read from `$this->baseline ?? Craft::$app->path->getStoragePath() . '/migration/baseline.json'` via `file_get_contents` + `json_decode(..., true)`. If file missing → emit `WARN no-baseline (run verify capture-baseline first)` + flip overall pass to false (D-58: `verify` Gate 2 emits warn + flips, doesn't blow up).
5. **CountGateService call:** `$plugin->countGateService->run($baseline, $tolerance)` (Plan 04-04's reshaped signature; filter-naive at v1.0 per carve-out).
6. **SpotCheckUrlFetcher Gate 2 loop:** for each URL in `$urlSpotCheck` list, fetch via `$plugin->spotCheckUrlFetcher->fetchAndNormalize($url)`, compare against `<baselineDir>/<slug>.html` via `$plugin->spotCheckUrlFetcher->diff($live, $baselineHtml)`. If `diff()` returns non-empty AND the diff ratio exceeds `$threshold`, gate fails. Use `urlToSlug()` (port from v1 lines 294-297).
7. **`renderReportMarkdown` (D-61):** port v1 lines 302-342 with two extensions — add rows for skipped optional-plugin gates (`SKIP seomatic (plugin not installed)`, `SKIP retour (plugin not installed)`) per D-58.
8. **Atomic write (Phase 2 / D-07):** the report writes via `$plugin->mappingFile->writeAtomic($path, $rendered)` — NEVER raw `file_put_contents` (v2 reshape from v1 line 222).
9. **Report path:** `Craft::$app->path->getStoragePath() . '/migration/VERIFY-' . gmdate('Y-m-d--H-i-s') . '.md'`.
10. **Stdout color discipline (Phase 1 / D-19):** `$this->stdout(sprintf("  %s %s: %d/%d (Delta=%.3f%%)\n", $pass ? 'PASS' : 'FAIL', $key, ...), $pass ? Console::FG_GREEN : Console::FG_RED);` — port v1 lines 119-134.
11. **NeverProduction first statement** in every action (Phase 1 / D-20).
12. **Exit code:** return `ExitCode::OK` (0) when overall pass, `ExitCode::UNSPECIFIED_ERROR` (1) when any gate fails. The report writes regardless (D-58 / VER-03 success criterion).

The private `urlToSlug` helper (v1 lines 294-297) is ported byte-for-byte:

```php
private function urlToSlug(string $url): string
{
    return preg_replace('/[^a-zA-Z0-9_-]+/', '_', $url) ?? 'baseline';
}
```
  </action>
  <read_first>
    - ~/Sites/craft-kunstmaan-migrator/src/bridge/console/controllers/VerifyController.php (entire file — verbatim source for body)
    - src/console/MigrateController.php (v2 console controller idiom — `enforceNeverProduction()` first, `Plugin::getInstance()`, `$plugin->mappingFile->writeAtomic`)
    - src/console/AnalyzeController.php (v2 multi-step actionIndex shape with stdout color discipline; **filter-flag declaration template** — `public ?string $entities/locales/since`, `options()` array entries, `$plugin->filterFactory->fromCli(...)` invocation pattern. Phase 02.1.)
    - src/console/DoctorController.php (Phase 1 / D-19 OK/WARN/FAIL plain-text + color discipline)
    - src/Plugin.php (the Task 01 changes are in place — confirm getInstance + getSettings access)
    - src/verify/CountGateService.php (Plan 04-04 — confirm `run(array $expectedCounts, float $tolerance)` signature; filter-naive at v1.0 per carve-out)
    - src/verify/BaselineCounterService.php (Plan 04-04 — confirm `capture()` returns the D-59 array shape; filter-naive at v1.0 per carve-out)
    - src/verify/SpotCheckUrlFetcher.php (Plan 04-03 — confirm `diff()` and `fetchAndNormalize` signatures)
    - src/verify/CaptureBaselineHtmlService.php (Plan 04-08 — confirm `capture()` signature)
    - src/mapping/MappingFile.php (confirm `writeAtomic` and `writeAtomicJson` are accessible via Plugin::getInstance())
    - src/filter/FilterFactory.php (confirm `fromCli(?string $entities, ?string $locales, ?string $since): MigrationFilters` signature — Phase 2 / Plan 01)
    - .planning/phases/04-adapters-verify-settings/04-PATTERNS.md (VerifyController section, Shared Patterns)
    - .planning/phases/04-adapters-verify-settings/04-CONTEXT.md (D-58, D-60, D-61, D-58's `WARN no-baseline` semantic)
    - .planning/phases/02-schema-mapping-filters/02-CONTEXT.md (Phase 2 / D-12 three filter flags only — `--entities`, `--locales`, `--since`)
    - .planning/phases/01-foundation-connectivity/01-CONTEXT.md (D-19 plain-text OK/WARN/FAIL, D-20 gate-first idiom)
    - src/NeverProductionTrait.php (confirm enforceNeverProduction signature)
    - .planning/ROADMAP.md (Phase 2 success criterion 5 — five top-level commands all accept --entities/--locales/--since)
  </read_first>
  <acceptance_criteria>
    - `test -f src/console/VerifyController.php` returns true
    - `grep -c '^namespace lameco\\\\kunstmaanmigrator\\\\console;' src/console/VerifyController.php` returns `1`
    - `grep -c 'class VerifyController extends Controller' src/console/VerifyController.php` returns `1`
    - `grep -c 'use NeverProductionTrait' src/console/VerifyController.php` returns at least `1`
    - `grep -c 'public function actionIndex(' src/console/VerifyController.php` returns `1`
    - `grep -c 'public function actionCaptureBaseline(' src/console/VerifyController.php` returns `1`
    - `grep -c 'public function actionCaptureBaselineHtml(' src/console/VerifyController.php` returns `1`
    - `grep -c 'enforceNeverProduction()' src/console/VerifyController.php` returns at least `3` (one per action — Phase 1 / D-20)
    - `grep -c '\$plugin->countGateService->run' src/console/VerifyController.php` returns at least `1` (D-58 Gate 1)
    - `grep -c '\$plugin->baselineCounterService->capture' src/console/VerifyController.php` returns at least `1` (D-59)
    - `grep -c '\$plugin->captureBaselineHtmlService->capture' src/console/VerifyController.php` returns at least `1` (D-58 Gate 2 capture)
    - `grep -c '\$plugin->spotCheckUrlFetcher->' src/console/VerifyController.php` returns at least `1` (D-58 Gate 2 diff)
    - `grep -c 'verifyCountTolerance' src/console/VerifyController.php` returns at least `1` (D-60 Settings default ladder)
    - `grep -c 'verifyUrlDiffThreshold' src/console/VerifyController.php` returns at least `1` (D-60)
    - `grep -c 'baseline.json' src/console/VerifyController.php` returns at least `1` (D-59 stable path)
    - `grep -c "VERIFY-' \\. gmdate" src/console/VerifyController.php` returns at least `1` (D-61 timestamped report path)
    - `grep -c 'writeAtomic' src/console/VerifyController.php` returns at least `1` (Phase 2 / D-07; NOT raw file_put_contents)
    - `grep -c "raw file_put_contents\|file_put_contents" src/console/VerifyController.php` returns `0` (only writeAtomic — v2 reshape vs v1 line 222)
    - `grep -c 'private function urlToSlug(' src/console/VerifyController.php` returns `1`
    - `grep -c 'WARN no-baseline\|no-baseline' src/console/VerifyController.php` returns at least `1` (D-58 missing-baseline semantic)
    - `grep -c 'SKIP seomatic\|SKIP retour' src/console/VerifyController.php` returns at least `1` (D-61 skipped-gate row)
    - `grep -c "Console::FG_GREEN\|Console::FG_RED\|Console::FG_YELLOW" src/console/VerifyController.php` returns at least `3` (D-19 color discipline)
    - `grep -c 'optionAliases' src/console/VerifyController.php` returns at least `1`
    - `grep -E "'v' => 'verbose'" src/console/VerifyController.php` returns at least `1`
    - **ROADMAP Phase 2 criterion 5 — filter-flag CLI surface (NEW):**
      - `grep -cE 'public[[:space:]]+\?string[[:space:]]+\$entities' src/console/VerifyController.php` returns at least `1`
      - `grep -cE 'public[[:space:]]+\?string[[:space:]]+\$locales' src/console/VerifyController.php` returns at least `1`
      - `grep -cE 'public[[:space:]]+\?string[[:space:]]+\$since' src/console/VerifyController.php` returns at least `1`
      - `grep -c "filterFactory->fromCli" src/console/VerifyController.php` returns at least `3` (one per action — actionIndex, actionCaptureBaseline, actionCaptureBaselineHtml)
      - `grep -c "'entities'" src/console/VerifyController.php` returns at least `3` (declared in options() for each of the three actions)
      - `grep -c "'locales'" src/console/VerifyController.php` returns at least `3`
      - `grep -c "'since'" src/console/VerifyController.php` returns at least `3`
    - `php -l src/console/VerifyController.php` outputs `No syntax errors detected`
    - `composer test` exits `0`
    - Yii console enumerates the verify action: `./craft kunstmaan-migrator/verify --help` does NOT error with "Unknown command" (manual smoke; the test infrastructure may not run Craft console — fall back to `grep -c "actionIndex\|actionCaptureBaseline\|actionCaptureBaselineHtml" src/console/VerifyController.php` returning `3` plus PluginBootstrapTest passing)
  </acceptance_criteria>
</task>

## Verification

- `composer test` exits 0 (PluginBootstrapTest loads new components without DI errors).
- Static load: `php -r 'require "vendor/autoload.php"; new \lameco\kunstmaanmigrator\console\VerifyController("verify", \Craft::$app);'` works under a Craft bootstrap (deferred to Plan 04-12 unit tests where a Craft test bootstrap is configured).
- `./craft kunstmaan-migrator/verify capture-baseline` (manual smoke during Phase 5 rehearsal) writes `storage/migration/baseline.json`.
- `./craft kunstmaan-migrator/verify --entities=news,cases --locales=nl --since=2024-01-01` (manual smoke) parses the flags without error (criterion 5 surface).

## must_haves

- `src/Plugin.php` registers all 8 Phase 4 components in `config()` and wires the Phase 4 sibling-DI graph in `init()`.
- D-57 Settings table-name overrides applied at init time.
- `src/console/VerifyController.php` exists with three actions, NeverProduction gate first, Plugin::getInstance DI, atomic-write seam, Settings+CLI tolerance ladder.
- `VerifyController` declares `--entities` / `--locales` / `--since` filter flags and parses them via `FilterFactory::fromCli` in every action (ROADMAP Phase 2 criterion 5: uniform CLI surface across the five top-level commands).
- `VERIFY-<timestamp>.md` is the only verify artifact (D-61 markdown-only).
- `baseline.json` lives at the stable `storage/migration/baseline.json` path (D-59).
- `composer test` stays green.

## RECONCILIATION

| v1 rule | v2 disposition |
|---|---|
| VerifyController namespace `lameco\kunstmaanmigrator\bridge\console\controllers` | **reshaped** — flattened to `lameco\kunstmaanmigrator\console`. |
| `actionIndex` body (Gate 1 + Gate 2 + report rendering) | **ported** — verbatim with controlled reshapes (tolerance source, atomic write, baseline-from-disk, filter-flag parsing at entry). |
| Tolerance read from `$mapping['verify']['tolerance']` (lines 91-96) | **dropped intentionally (D-60)** — replaced with `$this->countTolerance ?? $settings->verifyCountTolerance ?? 0.01`. mapping.yaml stays clean. |
| `BaselineSnapshotService::capture()` call inside actionCaptureBaseline | **reshaped (D-59)** — calls `BaselineCounterService::capture()` (Plan 04-04 light shape) instead. v1's full snapshot deferred. |
| Baseline path `.planning/phases/.../BASELINE-<date>.json` | **reshaped (D-59)** — moved to `storage/migration/baseline.json` (stable path, NOT timestamped — overwritten on each capture). |
| Report path `<some-other-path>/VERIFY-<ts>.md` | **reshaped (D-61)** — `storage/migration/VERIFY-<ts>.md` (storage canonical). |
| Raw `file_put_contents` write at v1 line 222 | **reshaped (Phase 2 / D-07)** — uses `Plugin::getInstance()->mappingFile->writeAtomic`. |
| `renderReportMarkdown` body (lines 302-342) | **ported with extension** — added rows for skipped optional-plugin gates (`SKIP seomatic` / `SKIP retour`) per D-58. |
| `actionCaptureBaselineHtml` body (lines 270-292) | **ported** — only the storage-path defaults reshape (`storage/migration/baseline/` and `storage/migration/spot-check-urls.txt`). |
| `urlToSlug` helper (lines 294-297) | **ported byte-for-byte**. |
| `enforceNeverProduction` first-statement gate in every action | **ported** — Phase 1 / D-20 idiom. |
| `optionAliases` `'v' => 'verbose'` | **ported** — VerifyController inherits the simple bool $verbose for now; multi-level `-v..-vvv` lives in MigrateController (Plan 04-10). VerifyController gets sibling log file when `verbose=true` (handled in Plan 04-10's logging plumbing). |
| Plugin component registrations + sibling-DI wiring | **derived (Phase 02.1 / commit 75a95bc pattern)** — Phase 4 dep graph in PATTERNS flag #7. |
| D-57 Settings → adapter table-name override | **added** — applied in Plugin::init after the sibling-DI block. |
| `--entities` / `--locales` / `--since` filter flags on VerifyController | **added intentionally** — v1 VerifyController did NOT declare these flags. v2 adds them per ROADMAP Phase 2 success criterion 5 ("five top-level CLI commands all accept --entities=, --locales=, --since="). Mirrors AnalyzeController template (Phase 02.1). v1.0 BaselineCounterService / CountGateService remain filter-naive (deep plumbing deferred to a follow-up phase if rehearsal proves a real operator need); the load-bearing requirement here is uniform CLI surface — flags are declared, parsed via `FilterFactory::fromCli`, and `$filters` is kept in scope so a future filter-aware service overload wires without touching the controller. URL Gate 2 scoping is operator-controlled by `spot-check-urls.txt` curation (D-58). |
