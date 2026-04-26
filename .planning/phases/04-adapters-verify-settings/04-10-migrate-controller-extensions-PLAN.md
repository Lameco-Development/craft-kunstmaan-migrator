---
plan: 10
phase: 04
title: "MigrateController extensions: actionSeo + actionRetour + verbosity + REPORT.md sections + asset RCA"
wave: 4
depends_on: ["04-06", "04-07", "04-09"]
files_modified:
  - src/console/MigrateController.php
  - src/load/AssetMigrationService.php
  - src/load/MigrationReport.php
autonomous: true
requirements_addressed: [ADP-01, ADP-02, CFG-02, CFG-03]
---

# Plan 04-10: MigrateController extensions + AssetMigrationService RCA

## Objective

Wire the adapter sub-actions and observability surface into the existing `MigrateController` (Phase 3 / Plan 13 base) and emit the structured asset-RCA line from `AssetMigrationService` per D-66:

1. **`actionSeo` + `actionRetour` sub-actions (D-55)** on MigrateController for resume / debug.
2. **In-process pipeline extension (D-55)** — `actionIndex` runs `extract → transform → load → finalize → seo → retour` in sequence when `--live`. Each adapter stage short-circuits inside its own service when the optional plugin is absent (D-56 lives in the services per Plan 04-06 / 04-07).
3. **`-v..-vvv` verbosity (D-65)** — count `-v` invocations on MigrateController; map to stage-timing / per-entry-detail / SQL-trace levels.
4. **Per-run log file (D-67)** — `storage/migration/migrate-<Y-m-d--H-i-s>.log` opened at run start.
5. **REPORT.md three new sections (D-68)** — extend `writeReport` with `## Rehearsal summary`, `## Skipped stages`, `## Asset RCA`.
6. **Asset RCA emission (D-66)** — `src/load/AssetMigrationService.php` emits `RCA asset=<id> reason=<closed-set> path=<rel>` line on failure paths.

## Context

- D-55: bolt-on after finalize; sub-actions also exist standalone (mirrors Phase 3 / ETL-02 pattern). PATTERNS.md MigrateController section spec'd the action body.
- D-56: optional-plugin detection lives in the services (Plan 04-06 / 04-07) — controller stays thin.
- D-65: `-v..-vvv` semantic ladder (stage timings → per-entry detail → SQL traces). Read via counting `-v` flag invocations in `options()`.
- D-66: closed-set reasons (`filesystem_404 | mime_mismatch | too_large | deferred_unresolved`).
- D-67: `storage/migration/migrate-<ts>.log` per run; sibling `verify-<ts>.log`/`analyze-<ts>.log` when verbose.
- D-68: extend Phase 3's REPORT.md, no new artifact. Section ordering per PATTERNS.md "Shared Patterns" (Migration counts → Rehearsal summary → Skipped stages → Warnings → Failures → Asset RCA).
- AssetMigrationService Phase 3 base already has `Craft::error('cqm-migrator:asset-failure', ...)` at lines ~234-249 — D-66 just adds a separate human-readable RCA line + classify helper.
- Phase 1 / D-20: NeverProduction gate first.
- Phase 2 / D-12: filter flags `--entities`, `--locales`, `--since` apply to the new sub-actions.

## Tasks

<task id="01">
  <action>
Extend `src/console/MigrateController.php` with the two new sub-actions and the in-process pipeline bolt-on. Mirror the existing `actionFinalize` shape (Phase 3 / Plan 13 base around lines 440-473):

```php
/**
 * Sub-action: write SEOmatic SEO MetaBundles per migrated entry per site.
 * D-55: runs LAST in the in-process pipeline so kuma_seo image refs resolve
 * via state lookup. Standalone for resume / debug after a partial migrate.
 *
 * D-56: short-circuits with WARN inside SeoMigrationService when SEOmatic absent.
 */
public function actionSeo(): int
{
    if (($gate = $this->enforceNeverProduction()) !== null) {
        return $gate;
    }
    $this->stdout("Migrate (seo): SEOmatic MetaBundles per migrated entry\n", Console::FG_CYAN);

    $plugin = Plugin::getInstance();
    $filters = $plugin->filterFactory->fromCli($this->entities, $this->locales, $this->since);

    if (!$this->live) {
        $this->stdout("  WARN seo skipped (dry-run; pass --live to write SEOmatic bundles)\n", Console::FG_YELLOW);
        return ExitCode::OK;
    }

    $plugin->seoMigrationService->filters = $filters;
    $opts = new MigrationOptions(dryRun: false, force: $this->force, skipAssets: false);

    try {
        $report = $plugin->seoMigrationService->migrateAll($opts);
    } catch (Throwable $e) {
        $this->stderr("  FAIL seo: {$e->getMessage()}\n", Console::FG_RED);
        return ExitCode::UNSPECIFIED_ERROR;
    }
    $this->stdout(sprintf(
        "  OK   seo complete (created=%d updated=%d skipped=%d failed=%d)\n",
        (int) ($report->counts['created'] ?? 0),
        (int) ($report->counts['updated'] ?? 0),
        (int) ($report->counts['skipped'] ?? 0),
        (int) ($report->counts['failed'] ?? 0),
    ), Console::FG_GREEN);
    return ExitCode::OK;
}

/**
 * Sub-action: write Retour redirects from kuma_redirects + section-move 301s.
 * D-55: standalone for resume / debug.
 * D-56: short-circuits with WARN inside RedirectMigrationService when Retour absent.
 */
public function actionRetour(): int
{
    if (($gate = $this->enforceNeverProduction()) !== null) {
        return $gate;
    }
    $this->stdout("Migrate (retour): redirects from kuma_redirects + section-move 301s\n", Console::FG_CYAN);

    $plugin = Plugin::getInstance();
    $filters = $plugin->filterFactory->fromCli($this->entities, $this->locales, $this->since);

    if (!$this->live) {
        $this->stdout("  WARN retour skipped (dry-run; pass --live to write redirects)\n", Console::FG_YELLOW);
        return ExitCode::OK;
    }

    $plugin->redirectMigrationService->filters = $filters;
    $opts = new MigrationOptions(dryRun: false, force: $this->force, skipAssets: false);

    try {
        $report = $plugin->redirectMigrationService->migrateAll($opts);
    } catch (Throwable $e) {
        $this->stderr("  FAIL retour: {$e->getMessage()}\n", Console::FG_RED);
        return ExitCode::UNSPECIFIED_ERROR;
    }
    $this->stdout(sprintf(
        "  OK   retour complete (created=%d updated=%d skipped=%d failed=%d)\n",
        (int) ($report->counts['created'] ?? 0),
        (int) ($report->counts['updated'] ?? 0),
        (int) ($report->counts['skipped'] ?? 0),
        (int) ($report->counts['failed'] ?? 0),
    ), Console::FG_GREEN);
    return ExitCode::OK;
}
```

Also add `'seo'`, `'retour'`, `'force'` (already exists), and verbosity flags (`'verbose'` and aliases) to `options()` for these new actions. Update `optionAliases()` so `'v' => 'verbose'` (single flag, count via repetition handled in Task 03).

Then extend `actionIndex` (Phase 3 / Plan 13 around lines 236-261) with the post-finalize bolt-ons (D-55):

```php
// Step 6.5 (D-55): SEO stage — runs AFTER finalize so all entries+assets exist.
if ($this->live) {
    $plugin->seoMigrationService->filters = $filters;
    $seoReport = $plugin->seoMigrationService->migrateAll($opts);
    $this->mergeReport($report, $seoReport, 'seo');
    $this->stdout(sprintf(
        "  Stage seo: created=%d updated=%d skipped=%d failed=%d\n",
        (int) ($seoReport->counts['created'] ?? 0),
        (int) ($seoReport->counts['updated'] ?? 0),
        (int) ($seoReport->counts['skipped'] ?? 0),
        (int) ($seoReport->counts['failed'] ?? 0),
    ), Console::FG_GREEN);
}

// Step 6.6 (D-55): Retour stage — same shape.
if ($this->live) {
    $plugin->redirectMigrationService->filters = $filters;
    $retourReport = $plugin->redirectMigrationService->migrateAll($opts);
    $this->mergeReport($report, $retourReport, 'retour');
    $this->stdout(sprintf(
        "  Stage retour: created=%d updated=%d skipped=%d failed=%d\n",
        (int) ($retourReport->counts['created'] ?? 0),
        (int) ($retourReport->counts['updated'] ?? 0),
        (int) ($retourReport->counts['skipped'] ?? 0),
        (int) ($retourReport->counts['failed'] ?? 0),
    ), Console::FG_GREEN);
}
```

If MigrationReport has a `merge()` method (Phase 3 / Plan 12), use it. If not, add a small `private function mergeReport(MigrationReport $into, MigrationReport $from, string $stage): void` helper that adds counts and pushes warnings/failures with a `stage:` tag.
  </action>
  <read_first>
    - src/console/MigrateController.php (entire file — must understand existing actionExtract/Transform/Load/Finalize shape, options() and optionAliases() current state, actionIndex orchestration order)
    - src/load/SeoMigrationService.php (Plan 04-06 — confirm migrateAll signature returns MigrationReport)
    - src/load/RedirectMigrationService.php (Plan 04-07 — confirm migrateAll signature)
    - src/load/MigrationReport.php (confirm `counts`, `warn`, `incr`, optional `merge` API)
    - src/load/MigrationOptions.php (confirm constructor)
    - src/filter/FilterFactory.php (confirm `fromCli` signature)
    - .planning/phases/04-adapters-verify-settings/04-PATTERNS.md (MigrateController section, exact action template)
    - .planning/phases/04-adapters-verify-settings/04-CONTEXT.md (D-55, D-56 service-level)
    - .planning/phases/03-etl-pipeline-field-handlers/03-CONTEXT.md (Phase 3 / D-52 REPORT.md base shape)
  </read_first>
  <acceptance_criteria>
    - `grep -c 'public function actionSeo(' src/console/MigrateController.php` returns `1`
    - `grep -c 'public function actionRetour(' src/console/MigrateController.php` returns `1`
    - `grep -c 'enforceNeverProduction()' src/console/MigrateController.php` returns at least `7` (existing 5 actions + 2 new — verify count grows by 2)
    - `grep -c 'seoMigrationService->migrateAll' src/console/MigrateController.php` returns at least `2` (sub-action + actionIndex bolt-on)
    - `grep -c 'redirectMigrationService->migrateAll' src/console/MigrateController.php` returns at least `2` (sub-action + actionIndex bolt-on)
    - `grep -c 'seoMigrationService->filters' src/console/MigrateController.php` returns at least `2`
    - `grep -c 'redirectMigrationService->filters' src/console/MigrateController.php` returns at least `2`
    - `grep -c 'D-55' src/console/MigrateController.php` returns at least `1`
    - `php -l src/console/MigrateController.php` outputs `No syntax errors detected`
    - `composer test` exits `0`
  </acceptance_criteria>
</task>

<task id="02">
  <action>
Extend `src/load/AssetMigrationService.php` with D-66 RCA emission. The existing `Craft::error('cqm-migrator:asset-failure', ...)` block at v2 lines ~234-249 stays — append a structured single-line RCA emission immediately after.

Add a new private method `classifyAssetFailureReason` and emit the RCA line:

```php
// D-66: structured single-line RCA emission. Closed-set reason taxonomy.
$reason = $this->classifyAssetFailureReason($e, $row);
$relativePath = (string) ($row['location'] ?? '');
Craft::info(
    sprintf(
        'RCA asset=%s reason=%s path=%s',
        $row['id'] ?? '?',
        $reason,
        $relativePath,
    ),
    'kunstmaanmigrator.rca',
);
```

```php
/**
 * D-66: closed-set reason taxonomy.
 * Reasons: filesystem_404 | mime_mismatch | too_large | deferred_unresolved
 */
private function classifyAssetFailureReason(\Throwable $e, array $row): string
{
    $msg = $e->getMessage();
    if (str_contains($msg, 'No such file') || str_contains($msg, 'not found')) {
        return 'filesystem_404';
    }
    if (str_contains($msg, 'mime') || str_contains($msg, 'content_type')) {
        return 'mime_mismatch';
    }
    if (str_contains($msg, 'too large') || str_contains($msg, 'PostMaxSize')) {
        return 'too_large';
    }
    return 'deferred_unresolved';
}
```

Find every catch block in `AssetMigrationService.php` that currently emits `Craft::error('cqm-migrator:asset-failure', ...)` — there should be at least one at the line ~234-249 region. Append the RCA emission at each such site so every asset failure path emits exactly one RCA line.

The RCA log category `'kunstmaanmigrator.rca'` is dedicated so REPORT.md aggregation (Task 04) can grep for it deterministically.
  </action>
  <read_first>
    - src/load/AssetMigrationService.php (entire file — locate all `Craft::error('cqm-migrator:asset-failure'` call sites; understand the surrounding catch-block structure to append RCA emission correctly)
    - .planning/phases/04-adapters-verify-settings/04-PATTERNS.md (AssetMigrationService section, D-66 emission template)
    - .planning/phases/04-adapters-verify-settings/04-CONTEXT.md (D-66 closed-set reason taxonomy)
  </read_first>
  <acceptance_criteria>
    - `grep -c 'private function classifyAssetFailureReason(' src/load/AssetMigrationService.php` returns `1`
    - `grep -c 'kunstmaanmigrator.rca' src/load/AssetMigrationService.php` returns at least `1`
    - `grep -c 'RCA asset=' src/load/AssetMigrationService.php` returns at least `1`
    - `grep -c 'filesystem_404' src/load/AssetMigrationService.php` returns at least `1`
    - `grep -c 'mime_mismatch' src/load/AssetMigrationService.php` returns at least `1`
    - `grep -c 'too_large' src/load/AssetMigrationService.php` returns at least `1`
    - `grep -c 'deferred_unresolved' src/load/AssetMigrationService.php` returns at least `1`
    - `grep -c 'D-66' src/load/AssetMigrationService.php` returns at least `1`
    - `php -l src/load/AssetMigrationService.php` outputs `No syntax errors detected`
    - `composer test` exits `0`
  </acceptance_criteria>
</task>

<task id="03">
  <action>
Add D-65 verbosity + D-67 per-run log file plumbing to `src/console/MigrateController.php`.

Replace the existing single `public bool $verbose` (or add if missing) with verbosity-counting infrastructure. Yii's CLI doesn't natively count `-v` flag repetitions like git/ssh/rsync, so implement via accepting a string flag plus an integer counter:

```php
// D-65: -v..-vvv verbosity. Pass `-v`, `-vv`, or `-vvv` (or `--verbose=1..3`).
public string|int $verbose = 0;

private function verbosityLevel(): int
{
    if (is_int($this->verbose)) {
        return max(0, min(3, $this->verbose));
    }
    if ($this->verbose === '' || $this->verbose === false) {
        return 0;
    }
    // String like 'v', 'vv', 'vvv' (when used as `-vvv`) — count chars.
    if (is_string($this->verbose)) {
        $trim = trim((string) $this->verbose);
        if ($trim === '' || $trim === '1') return 1;
        if (ctype_digit($trim)) return max(0, min(3, (int) $trim));
        return max(0, min(3, strlen($trim)));
    }
    return $this->verbose ? 1 : 0;
}
```

Open a per-run log file (D-67) at the top of `actionIndex`, `actionSeo`, `actionRetour` (and the existing extract/transform/load/finalize/truncate when verbosity > 0):

```php
// D-67: per-run timestamped log file under storage/migration/.
$timestamp = gmdate('Y-m-d--H-i-s');
$logPath   = Craft::$app->path->getStoragePath() . '/migration/migrate-' . $timestamp . '.log';
$this->logFilePath = $logPath;
$this->openLogFile($logPath);
```

Add a small private helper method:

```php
private ?string $logFilePath = null;
private $logFileHandle = null;

private function openLogFile(string $path): void
{
    if (!is_dir(dirname($path))) {
        @mkdir(dirname($path), 0755, true);
    }
    $this->logFileHandle = @fopen($path, 'a');
}

private function logLine(string $line, int $minVerbosity = 1): void
{
    if ($this->verbosityLevel() < $minVerbosity) return;
    if ($this->logFileHandle === null) return;
    fwrite($this->logFileHandle, '[' . gmdate('c') . '] ' . $line . "\n");
}
```

Use `logLine()` to record stage-level timing summaries at `-v` (e.g. "extract complete in 1.4s, 547 rows"), per-entry detail at `-vv`, and SQL trace at `-vvv` (latter is best-effort — Yii has its own SQL log channel that operators can enable separately).

NOTE: The verbose flag wires only on MigrateController in this plan. VerifyController (Plan 04-09) and AnalyzeController already accept `-v`/verbose → simple bool. D-65's three-level ladder is a MigrateController-specific affordance per the spec.
  </action>
  <read_first>
    - src/console/MigrateController.php (post-Task 01 state — confirm options() / optionAliases() + actionIndex existing flow)
    - .planning/phases/04-adapters-verify-settings/04-CONTEXT.md (D-65, D-67)
    - .planning/phases/04-adapters-verify-settings/04-PATTERNS.md (verbosity + log file sections)
  </read_first>
  <acceptance_criteria>
    - `grep -c 'private function verbosityLevel(' src/console/MigrateController.php` returns `1`
    - `grep -c 'private function openLogFile(' src/console/MigrateController.php` returns `1`
    - `grep -c 'private function logLine(' src/console/MigrateController.php` returns `1`
    - `grep -c 'migrate-.*\\.log' src/console/MigrateController.php` returns at least `1` (timestamped log path)
    - `grep -c "gmdate\\('Y-m-d--H-i-s'\\)" src/console/MigrateController.php` returns at least `1` (D-67 timestamp shape)
    - `grep -c 'D-65\|D-67' src/console/MigrateController.php` returns at least `1`
    - `php -l src/console/MigrateController.php` outputs `No syntax errors detected`
    - `composer test` exits `0`
  </acceptance_criteria>
</task>

<task id="04">
  <action>
Extend `writeReport` (Phase 3 / Plan 13 base around `src/console/MigrateController.php:715-774`) with the three new D-68 sections: `## Rehearsal summary`, `## Skipped stages`, `## Asset RCA`.

Per PATTERNS.md "REPORT.md three new sections" Shared Pattern, the section order in REPORT.md becomes:
1. `## Migration counts (D-52)` — existing.
2. `## Rehearsal summary` (NEW — D-68) — totals + wall-clock + filter scope + flag + log file path.
3. `## Skipped stages` (NEW — D-68) — adapter absence WARNs.
4. `## Warnings` — existing.
5. `## Failures (D-50)` — existing.
6. `## Asset RCA` (NEW — D-68) — per-asset failure rows.

`## Rehearsal summary` content:

```
## Rehearsal summary

- Total created: <N>
- Total updated: <N>
- Total skipped: <N>
- Total failed: <N>
- Wall-clock duration: <hh:mm:ss>
- Filter scope: entities=<list>, locales=<list>, since=<date|null>
- Flag: --live | --dry-run
- Log file: <storage/migration/migrate-<ts>.log>
```

`## Skipped stages` content (sourced from `MigrationReport->warnings` filtered for adapter-absence messages):

```
## Skipped stages

- seo: skipped (plugin not installed; <N> entries had kuma_seo rows)
- retour: skipped (plugin not installed)
```

If no stages were skipped, omit the section entirely (NOT a header with empty content).

`## Asset RCA` content (read from the `kunstmaanmigrator.rca` log channel — easiest implementation: have AssetMigrationService also push a structured `['legacyId', 'reason', 'path']` row into a list on `MigrationReport` (e.g. `$report->assetRcaRows[]`), and `writeReport` renders that list as a markdown table):

```
## Asset RCA

| legacy_id | reason | path |
|-----------|--------|------|
| 1234 | filesystem_404 | media/file.png |
| 1235 | mime_mismatch | media/other.bin |
```

If no RCA rows, omit the section. To wire this without re-reading log files, add a public `array $assetRcaRows = []` property to `MigrationReport` (Phase 3 / Plan 12) — or store via a dedicated method `pushAssetRca(int $legacyId, string $reason, string $path): void`. The AssetMigrationService catch block (Task 02) calls this method at the same site as the RCA Craft::info() emission.

Update task 02's AssetMigrationService changes to also call `$this->report->pushAssetRca(...)` (where `$this->report` is the existing MigrationReport reference; if AssetMigrationService doesn't currently hold a report ref, pass it through MigrationOptions or construct a transient list and merge in MigrateController — choose the path that touches fewer files; mention chosen approach in plan-execution).

The simplest seam: AssetMigrationService receives a `MigrationReport` reference (already used per Phase 3 / Plan 12 atomic loader). Add `$report->pushAssetRca(...)` adjacent to the Craft::info RCA emission.
  </action>
  <read_first>
    - src/console/MigrateController.php (post-Tasks 01+03 — locate `writeReport` and existing section emission)
    - src/load/MigrationReport.php (Phase 3 / Plan 12 — confirm public properties, identify the right place to add `assetRcaRows` or `pushAssetRca`)
    - src/load/AssetMigrationService.php (post-Task 02 — locate the RCA emission site to add `pushAssetRca` adjacent)
    - .planning/phases/04-adapters-verify-settings/04-PATTERNS.md (REPORT.md three new sections — exact section order)
    - .planning/phases/04-adapters-verify-settings/04-CONTEXT.md (D-68)
    - .planning/phases/03-etl-pipeline-field-handlers/03-CONTEXT.md (Phase 3 / D-50 + D-52 — existing REPORT.md sections)
  </read_first>
  <acceptance_criteria>
    - `grep -c '## Rehearsal summary' src/console/MigrateController.php` returns at least `1` (D-68 section header)
    - `grep -c '## Skipped stages' src/console/MigrateController.php` returns at least `1` (D-68 section header)
    - `grep -c '## Asset RCA' src/console/MigrateController.php` returns at least `1` (D-68 section header)
    - `grep -c 'Wall-clock duration' src/console/MigrateController.php` returns at least `1` (rehearsal summary content)
    - `grep -c 'Log file' src/console/MigrateController.php` returns at least `1` (rehearsal summary log-file row)
    - `grep -c 'D-68' src/console/MigrateController.php` returns at least `1`
    - `grep -c 'pushAssetRca\|assetRcaRows' src/load/MigrationReport.php` returns at least `1` (RCA collection seam added)
    - `grep -c 'pushAssetRca\|assetRcaRows' src/load/AssetMigrationService.php` returns at least `1` (RCA collection seam wired)
    - `grep -c '| legacy_id | reason | path |' src/console/MigrateController.php` returns at least `1` (RCA table header)
    - `grep -c 'pushAssetRca\|assetRcaRows' src/console/MigrateController.php` returns at least `1` (RCA section reads from report)
    - `php -l src/console/MigrateController.php` outputs `No syntax errors detected`
    - `php -l src/load/AssetMigrationService.php` outputs `No syntax errors detected`
    - `php -l src/load/MigrationReport.php` outputs `No syntax errors detected`
    - `composer test` exits `0`
  </acceptance_criteria>
</task>

## Verification

- `composer test` exits 0 — every previous test still loads with the extended controller and report.
- Manual smoke (deferred to Phase 5): `./craft kunstmaan-migrator/migrate --live -vv` produces a `migrate-<ts>.log` file plus a REPORT.md with the three new sections.

## must_haves

- `MigrateController::actionSeo` + `actionRetour` exist with NeverProduction gate, filter awareness, dry-run guard, MigrationReport handling.
- `actionIndex` runs SEO + Retour stages after finalize when `--live` (D-55).
- `-v..-vvv` verbosity counter implemented; per-run log file `migrate-<ts>.log` opens at run start.
- `MigrationReport` exposes a per-asset RCA collection (`pushAssetRca` or `assetRcaRows`).
- `AssetMigrationService` emits the structured `RCA asset=<id> reason=<reason> path=<rel>` line + pushes to the report on failure.
- REPORT.md gets three new sections (Rehearsal summary, Skipped stages, Asset RCA) in the documented order.
- `composer test` stays green.

## RECONCILIATION

| v1 rule | v2 disposition |
|---|---|
| MigrateController had separate `migrate/seo` + `migrate/retour` controller actions | **ported (D-55)** — sub-actions on existing MigrateController to keep the controller surface flat (D-Discretion). |
| Phase 3 / Plan 13 actionIndex pipeline shape (extract → transform → load → finalize) | **extended (D-55)** — adds seo + retour stages after finalize when --live. |
| AssetMigrationService Phase 3 `Craft::error('cqm-migrator:asset-failure', ...)` structured log | **ported + extended (D-66)** — keeps the structured Craft::error; appends the human-readable RCA single line + closed-set reason classifier. |
| v1 doctor's queue check | **dropped intentionally** — Phase 1 / D-19 already documents this drop; no async stages in v1.0. |
| Verbose flag — single boolean | **reshaped (D-65)** — three-level ladder (`-v`/`-vv`/`-vvv`) on MigrateController only. VerifyController + AnalyzeController retain simple bool $verbose. |
| Log file rotation (size or age) | **dropped intentionally (D-67)** — ships per-run timestamped only; operator manages disk. |
| REPORT.md base shape (Phase 3 / D-52) | **extended (D-68)** — three new sections (Rehearsal summary, Skipped stages, Asset RCA) + documented section order. |
