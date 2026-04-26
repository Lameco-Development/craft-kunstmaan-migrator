---
phase: 04-adapters-verify-settings
plan: 09
subsystem: console + DI
tags: [verify, plugin-di, console-controller, filter-cli-uniformity, wave-3]

# Dependency graph
requires:
  - phase: 04-adapters-verify-settings
    provides: Plan 04-02 SeomaticPayloadBuilder, 04-03 SnapshotDiffer + SpotCheckUrlFetcher, 04-04 CountGateService + BaselineCounterService, 04-06 SeoMigrationService, 04-07 RedirectMigrationService, 04-08 CaptureBaselineHtmlService — all 8 service bodies in src/{load,verify}/
  - phase: 02-schema-mapping-filters
    provides: FilterFactory::fromCli(?string, ?string, ?string): MigrationFilters (Plan 01), MappingFile::writeAtomic / writeAtomicJson (Plan 02 / D-07)
  - phase: 02.1-source-introspection
    provides: Plugin::init() sibling-DI append idiom + commit 75a95bc pattern
provides:
  - src/console/VerifyController.php (453 LOC) — three actions (actionIndex, actionCaptureBaseline, actionCaptureBaselineHtml) with shared NeverProductionTrait gate, Phase 2/D-07 atomic-write seam, D-60 tolerance ladder, D-61 markdown-only artifact, D-58 missing-baseline + optional-plugin SKIP semantics, D-59 stable baseline.json path
  - src/Plugin.php — 8 component registrations + Phase 4 sibling-DI wiring graph (PATTERNS flag #7) + D-57 Settings table-name overrides
  - ROADMAP Phase 2 success criterion 5 surface for verify: --entities / --locales / --since flags declared on every action and parsed via FilterFactory::fromCli (uniform CLI surface across the five top-level commands)
  - baselineToExpectedCounts() shape translator from BaselineCounterService 'counts-v1' to CountGateService expectedCounts shape
affects:
  - 04-10-migrate-controller-extensions (uses Plugin::seoMigrationService / redirectMigrationService for actionSeo / actionRetour)
  - 04-11-doctor-7th-8th-checks (uses Plugin::baselineCounterService presence for verify-baseline-presence check)
  - 04-12-tests-and-reconciliation (Phase 4 PHPUnit corpus + RECONCILIATION.md)
  - Phase 5 rehearsal (operator runs `verify capture-baseline` post-migration → `verify` parity gate)

# Tech tracking
tech-stack:
  added: []
  patterns:
    - Phase 02.1 / commit 75a95bc append-block sibling-DI idiom extended with 4 new sibling wires
    - D-57 Settings table-name override: `is_string && !== ''` guard before assignment so empty Settings values don't overwrite service defaults
    - AnalyzeController filter-flag template (Phase 02.1) replicated on VerifyController: declare `?string $entities/$locales/$since`, list in `options()`, parse via `$plugin->filterFactory->fromCli(...)` at action entry
    - Filter-naive carve-out: `$filters` retained in scope even when service overload is filter-naive (criterion 5 surface intact, plumbing seam preserved)
    - Shape translator pattern: VerifyController::baselineToExpectedCounts() bridges incompatible service contracts without coupling either side
    - D-58 missing-baseline semantic: WARN + flip overall pass to false (NOT a hard error) when baseline.json absent
    - D-61 markdown-only artifact via MappingFile::writeAtomic (no JSON sidecar, no raw file_put_contents)

key-files:
  created:
    - src/console/VerifyController.php
  modified:
    - src/Plugin.php

key-decisions:
  - "Translator helper baselineToExpectedCounts() needed: the plan body line 284 implied passing the baseline directly to CountGateService::run(), but BaselineCounterService emits 'counts-v1' shape (sections.<handle>.totalCount nested) while CountGateService expects flat ('sections' => ['<handle>' => int]). Without the translator, '(int) $expected' on the nested sub-array yields 1 and Gate 1 silently passes for every section. Translator is a Rule 3 fix (blocking — without it, the gate is broken)."
  - "Optional-plugin SKIP rows synthesized at the controller seam, not inside CountGateService. CountGateService omits the gate when the operator's baseline lacks expectedPlugins[seomatic|retour]; the markdown report still needs an explicit SKIP row per D-58/D-61. Synthesizing in the controller keeps CountGateService pure (no concern about report rendering) and surfaces both 'plugin not installed' and 'plugin not in baseline' as the same operator-actionable note."
  - "$filters parsed in every action even when downstream services are filter-naive. Criterion 5's load-bearing requirement is uniform CLI surface across the five top-level commands; the flag declaration + FilterFactory::fromCli call constitutes that surface. $filters stays in scope so a future filter-aware overload of BaselineCounterService / CountGateService wires without touching the controller body — a deliberate plumbing seam, not dead code."
  - "Tolerance read order: $countTolerance (CLI override) ?? Settings::$verifyCountTolerance ?? 0.01. mapping.yaml stays clean of verify config per D-60 — no fallback to mapping.yaml runtime.countTolerance like v1 had."
  - "Atomic-write everywhere: MappingFile::writeAtomic for the markdown report, MappingFile::writeAtomicJson for baseline.json. No raw file_put_contents calls in the controller (verified by grep — count 0). file_get_contents IS used to READ baseline.json + per-URL baseline HTML (Phase 2 / D-07 protects writes, not reads)."

patterns-established:
  - "Console controller filter-flag template: `public ?string $entities/$locales/$since`, list flags in options() per-action, parse via $plugin->filterFactory->fromCli at action entry (mirrors AnalyzeController + MigrateController)."
  - "Shape-translator pattern at controller seam: when v1.0 services have incompatible contracts that cannot be reshaped without breaking other consumers, bridge them with a private helper inside the controller. Keeps the services pure + future-refactorable."
  - "D-57 Settings override guard idiom: `if (is_string($settings->X) && $settings->X !== '') { $service->X = $settings->X; }` — empty Settings values never overwrite service defaults."

requirements-completed: [VER-01, VER-02, VER-03, ADP-01, ADP-02]

# Metrics
duration: ~25 minutes
completed: 2026-04-26
---

# Phase 4 Plan 09: VerifyController + Plugin DI wiring Summary

## One-liner

Lands the executable verify operator surface (three console actions) and the runtime DI graph that makes every Phase 4 service callable — Plugin::config() registers 8 new components, Plugin::init() wires Phase 4's sibling-DI dependency graph + applies D-57 Settings table-name overrides, and src/console/VerifyController.php ports v1's three actions (actionIndex, actionCaptureBaseline, actionCaptureBaselineHtml) with controlled reshapes (D-60 tolerance ladder, D-61 markdown-only artifact, D-59 stable baseline.json path, ROADMAP criterion 5 filter-flag CLI surface).

## Tasks Completed

| Task | Name                                                       | Commit  | Files                            |
| ---- | ---------------------------------------------------------- | ------- | -------------------------------- |
| 01   | Wire Phase 4 services into Plugin DI graph                 | 0ed59c9 | src/Plugin.php                   |
| 02   | Add VerifyController with three actions and CLI filter surface | 3d877be | src/console/VerifyController.php |

## What Shipped

### Plugin.php DI wiring (Task 01, commit 0ed59c9)

8 new component registrations appended to `Plugin::config()` under the existing components array:

```
'seoMigrationService'        => SeoMigrationService::class,
'seomaticPayloadBuilder'     => SeomaticPayloadBuilder::class,
'redirectMigrationService'   => RedirectMigrationService::class,
'baselineCounterService'     => BaselineCounterService::class,
'countGateService'           => CountGateService::class,
'snapshotDiffer'             => SnapshotDiffer::class,
'spotCheckUrlFetcher'        => SpotCheckUrlFetcher::class,
'captureBaselineHtmlService' => CaptureBaselineHtmlService::class,
```

8 corresponding `use` imports + 8 `@property-read` PHPDoc lines for IDE autocomplete.

`Plugin::init()` extended with the Phase 4 sibling-DI block (PATTERNS flag #7) appended after the Phase 3 EntryMigrationService wiring:

- `seomaticPayloadBuilder->migrationState` ← migrationStateService (kuma_media → Craft asset id resolution)
- `seoMigrationService` 4-slot wire: legacyDb / stateService / seoPayload / sites (resolveSitesMap())
- `redirectMigrationService` 3-slot wire: legacyDb / stateService / sites
- `captureBaselineHtmlService->fetcher` ← spotCheckUrlFetcher
- D-57 Settings table-name overrides (`seoTableName`, `redirectsTableName`) wired with `is_string && !== ''` guard so empty Settings values never overwrite service defaults

`baselineCounterService` / `countGateService` / `snapshotDiffer` / `spotCheckUrlFetcher` documented as zero-sibling-dep (no init wiring needed — pure-read services).

`resolveSitesMap()` count: 5 (helper definition + 1 entryMigrationService call + 2 new Phase 4 calls + the helper itself counted once).

### VerifyController.php (Task 02, commit 3d877be)

453 LOC, three actions under shared `NeverProductionTrait` gate:

- **actionIndex** — Gate 1 (count-match via `CountGateService::run`) + Gate 2 (URL diff via `SpotCheckUrlFetcher::diff`) + markdown-only `VERIFY-<gmdate>.md` report via `MappingFile::writeAtomic`. Tolerance ladder per D-60: `$countTolerance ?? Settings::$verifyCountTolerance ?? 0.01`. URL diff threshold: `$urlDiffThreshold ?? Settings::$verifyUrlDiffThreshold ?? 0.05`. Missing-baseline semantic per D-58: WARN + flip overall pass to false. Optional-plugin SKIP rows synthesized at the controller seam when CountGateService omits the gate (operator's baseline lacked expectedPlugins for that plugin).
- **actionCaptureBaseline** — Light counts via `BaselineCounterService::capture()` written to `storage/migration/baseline.json` (D-59 stable path, NOT timestamped) via `MappingFile::writeAtomicJson`.
- **actionCaptureBaselineHtml** — URL spot-check fetches via `CaptureBaselineHtmlService::capture()` writing `<baselineDir>/<slug>.html` files; try/catch surfaces fetch failures as FAIL rows.

Filter-flag CLI surface (ROADMAP Phase 2 success criterion 5): every action declares `--entities`, `--locales`, `--since` and parses via `$plugin->filterFactory->fromCli(...)`. Carve-out documented: v1.0 BaselineCounterService / CountGateService remain filter-naive; `$filters` retained in scope as plumbing seam for future filter-aware overload.

`baselineToExpectedCounts()` private helper translates the BaselineCounterService `counts-v1` shape (`sections.<handle>.totalCount`, `assets.totalCount`, `taxonomies.<handle>.totalCount`, `retour.totalCount`, `seomatic.totalCount`) to the CountGateService `expectedCounts` shape (`['sections' => ['<handle>' => int], 'assets' => ['migrated' => int], 'plugins' => ['seomatic' => int, 'retour' => int], 'taxonomies' => ['<handle>' => int]]`).

`urlToSlug()` helper ported byte-for-byte from v1 lines 294-297: `preg_replace('/[^a-zA-Z0-9_-]+/', '_', $url) ?? 'baseline'`.

`renderReportMarkdown()` produces the D-61 markdown report with two tables (count gate, URL gate) and explicit SKIP rows for absent optional-plugin gates.

`optionAliases` preserves `'v' => 'verbose'`. Multi-level `-v..-vvv` lives in MigrateController per Plan 04-10 reshape note.

## Verification

- `php -l src/Plugin.php` → No syntax errors.
- `php -l src/console/VerifyController.php` → No syntax errors.
- `composer test` → 60 tests / 137 assertions passing (unchanged baseline; PluginBootstrapTest validates the 8 new component class registrations are loadable).
- All 30+ Task 01 + Task 02 acceptance grep checks green:
  - 8 component registrations: each `'<name>' =>` count 1 in src/Plugin.php
  - 5 use-imports: SeoMigrationService / RedirectMigrationService / CountGateService / BaselineCounterService / others count 1 each
  - 4 sibling-DI wires: `seoMigrationService->legacyDb / stateService / seoPayload / sites` count 1 each, `redirectMigrationService->legacyDb / sites` count 1 each, `seomaticPayloadBuilder->migrationState` count 1, `captureBaselineHtmlService->fetcher` count 1
  - `resolveSitesMap()` count 5 (≥4 required)
  - D-57: `$settings->seoTableName` count 1, `$settings->redirectsTableName` count 1
  - VerifyController namespace count 1, class extends Controller count 1, `use NeverProductionTrait` count 1
  - Three actions: actionIndex / actionCaptureBaseline / actionCaptureBaselineHtml count 1 each
  - `enforceNeverProduction()` count 3 (one per action — D-20 gate-first)
  - Service calls: `$plugin->countGateService->run` count 1, `$plugin->baselineCounterService->capture` count 1, `$plugin->captureBaselineHtmlService->capture` count 1, `$plugin->spotCheckUrlFetcher->` count 3
  - Settings ladder: `verifyCountTolerance` count 2, `verifyUrlDiffThreshold` count 1
  - D-59: `baseline.json` count 8
  - D-61: `VERIFY-' . gmdate` count 1
  - Atomic writes: `writeAtomic` count 3 (writeAtomic + writeAtomicJson + reference); raw `file_put_contents` count 0
  - urlToSlug helper count 1; no-baseline count 6; SKIP seomatic|retour count 2
  - Console FG colors count 17 (≥3 required, D-19 color discipline)
  - optionAliases count 2 (method + parent::optionAliases call); `'v' => 'verbose'` count 1
  - Filter-flag surface (criterion 5): `?string $entities/$locales/$since` count 1 each; `filterFactory->fromCli` count 3 (one per action); `'entities' / 'locales' / 'since'` strings count 3 each (declared in options() for each of three actions)
- `git log --oneline` shows both commits in the expected per-task order: 0ed59c9 (DI wiring) → 3d877be (controller).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 — Blocking] Added `baselineToExpectedCounts()` shape translator inside VerifyController**

- **Found during:** Task 02 implementation (actionIndex)
- **Issue:** The plan body line 284 implies a direct call: `$plugin->countGateService->run($baseline, $tolerance)`. But `BaselineCounterService::capture()` emits `counts-v1` shape with nested rows (`'sections' => ['<handle>' => ['totalCount' => N, 'countsBySite' => [...]]]`) while `CountGateService::run()` (Plan 04-04) expects a flat shape (`'sections' => ['<handle>' => N]`). Passing the baseline directly would cause `(int) $expected` to coerce the nested sub-array to `1`, silently passing every section gate at delta 100% (or failing every section at delta 100% depending on actual values). Without the translator, Gate 1 is broken.
- **Fix:** Added private `baselineToExpectedCounts(array $baseline): array` helper that walks `sections`, `assets`, `taxonomies`, `retour`, `seomatic` keys and produces the flat `expectedCounts` shape CountGateService expects. Translator runs once per actionIndex invocation after the baseline.json read + decode.
- **Files modified:** src/console/VerifyController.php (helper added; actionIndex calls it before passing to countGateService->run)
- **Commit:** 3d877be

**2. [Rule 3 — Blocking] Synthesized SKIP rows for missing optional-plugin gates at controller seam**

- **Found during:** Task 02 implementation (actionIndex Gate 1 stdout + report rendering)
- **Issue:** Per the plan acceptance criterion `grep -c 'SKIP seomatic\|SKIP retour' src/console/VerifyController.php returns at least 1`, AND per D-58/D-61 the markdown report should include explicit SKIP rows for absent optional-plugin gates. But CountGateService only emits a `plugins:seomatic` / `plugins:retour` gate row when the operator's baseline carries an explicit `expectedPlugins[seomatic|retour]` count. On a baseline captured against a host with neither plugin installed, both gate rows are simply absent — the markdown report and stdout would silently drop them.
- **Fix:** Synthesize SKIP rows at the controller seam after the CountGateService call. If `plugins:seomatic` or `plugins:retour` is missing from the gates output, append `['skip' => true, 'note' => 'plugin not installed or not in baseline']` to the countGate report and emit a yellow SKIP line on stdout. This keeps CountGateService pure (it has no concern about report rendering) and surfaces both "plugin not installed" and "plugin absent from baseline" as the same operator-actionable note.
- **Files modified:** src/console/VerifyController.php (actionIndex post-CountGate synthesis block)
- **Commit:** 3d877be

### Auth Gates

None — verify operates on local Craft + filesystem state. No external services touched at v1.0 (the Anthropic key is only consumed during analyze).

## Known Stubs

- `$filters` parsed via `FilterFactory::fromCli` in all three VerifyController actions but unused at v1.0 because `BaselineCounterService::capture()` and `CountGateService::run()` are filter-naive (carve-out documented in plan body). This is **not** a stub in the user-facing sense (no UI, no data) but a deliberate plumbing seam: ROADMAP Phase 2 success criterion 5 mandates uniform CLI surface across the five top-level commands; the flag declaration + parse satisfies that surface; the variable stays in scope so a future filter-aware overload of either service wires without touching the controller body. URL Gate 2 scoping is operator-controlled by `spot-check-urls.txt` curation per D-58, so URL-list scoping doesn't need filter integration. **Resolution:** when (and if) Phase 5 rehearsal proves a real operator need for partial-scope verify re-runs, add filter-aware overloads to `BaselineCounterService::capture(MigrationFilters $filters)` and `CountGateService::run(array $expected, float $tol, MigrationFilters $filters)` and update VerifyController to pass `$filters` — controller surface unchanged.

## Wave 3 status

Plan 04-09 is the first plan of Wave 3. With this commit:

- **VER-01** (`verify capture-baseline` snapshots pre-migration counts into JSON) — **complete**: actionCaptureBaseline writes `storage/migration/baseline.json` via `BaselineCounterService::capture()` + `MappingFile::writeAtomicJson` (D-59 stable path).
- **VER-02** (Optional `verify capture-baseline-html` snapshots rendered HTML) — **complete**: actionCaptureBaselineHtml invokes `CaptureBaselineHtmlService::capture()` via the `$fetcher` DI seam wired in Plugin::init().
- **VER-03** (`verify` runs parity gate + writes VERIFY-<timestamp>.md regardless of outcome) — **complete**: actionIndex runs Gate 1 (CountGateService) + Gate 2 (SpotCheckUrlFetcher.diff against captured baseline HTML) + writes the markdown report via `MappingFile::writeAtomic` regardless of pass/fail; exit code reflects gate outcome but report writes always.
- **ADP-01** (SEOmatic adapter — runtime detection + WARN-on-absent) — **service+wiring complete**: `seoMigrationService` registered + 4-slot DI-wired in Plugin::init(); D-57 Settings table-name override applied. Final closure waits on Plan 04-10's `migrate/seo` sub-action.
- **ADP-02** (Retour adapter — same shape) — **service+wiring complete**: `redirectMigrationService` registered + 3-slot DI-wired; D-57 Settings table-name override applied. Final closure waits on Plan 04-10's `migrate/retour` sub-action.

Wave 3 advances to: Plan 04-10 (MigrateController extensions — `actionSeo`/`actionRetour` operator commands + `-v..-vvv` verbosity + REPORT.md sections + asset RCA), Plan 04-11 (doctor 7th + 8th checks — adapter health + verify baseline presence), Plan 04-12 (PHPUnit unit tests + Phase 4 RECONCILIATION.md + ADP-03 composer-suggest guard).

## Self-Check: PASSED

- File `src/Plugin.php` — FOUND (modified)
- File `src/console/VerifyController.php` — FOUND (created)
- Commit `0ed59c9` (Task 01) — FOUND in `git log --oneline`
- Commit `3d877be` (Task 02) — FOUND in `git log --oneline`
- `composer test` exit code 0 — VERIFIED (60 tests / 137 assertions)
- `php -l src/Plugin.php` clean — VERIFIED
- `php -l src/console/VerifyController.php` clean — VERIFIED
- All 30+ acceptance grep checks pass — VERIFIED
