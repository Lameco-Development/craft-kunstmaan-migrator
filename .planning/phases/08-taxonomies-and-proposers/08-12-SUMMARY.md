---
phase: 08-taxonomies-and-proposers
plan: 12
subsystem: plugin DI / CLI controller wiring
tags: [taxonomy, plugin-di, migrate-controller, bolt-on, sub-action, d-03, d-04, d-12, d-20, never-production]

# Dependency graph
requires:
  - phase: 08-taxonomies-and-proposers
    plan: 11
    provides: "TaxonomyMigrationService class with public legacyDb / migrationState / mappingFile slots + migrateAll(MigrationOptions): MigrationReport entry point"
provides:
  - "Plugin component slot 'taxonomyMigrationService' => TaxonomyMigrationService::class — accessible via Plugin::getInstance()->taxonomyMigrationService"
  - "Plugin DI fanout in init(): legacyDb / migrationState / mappingFile sibling-wires the service after RedirectMigrationService block"
  - "MigrateController::actionIndex Step 4.5 bolt-on — runs taxonomies BETWEEN transform-complete and load-entries (D-03 — taxonomies migrate BEFORE pages)"
  - "MigrateController::actionTaxonomies sub-action — resume / debug entry point gated by NeverProductionTrait"
  - "Stage taxonomies: created=X updated=X skipped=X failed=X stdout line emitted from BOTH bolt-on and sub-action"
affects:
  - "Plan 08-15 (TaxonomyMigrationTest integration) — drives taxonomies through this wiring (analyze → compile → load), asserts a Craft entry exists end-to-end"
  - "Plan 08-17 (RECONCILIATION.md + REQUIREMENTS codification) — TAX-08 will be codified there alongside DOC-01/DOC-02"

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Bolt-on insertion convention: locate the analog (SEO/Retour bolt-ons in actionIndex Step 6.5/6.6); position taxonomies bolt-on at Step 4.5 BEFORE the load step (D-03 — taxonomy state rows must exist before any page's RelationHandler does the FK -> entryId lookup)."
    - "Sub-action shape mirrors actionRetour verbatim: enforceNeverProduction() FIRST (D-20) → openLogFile() / logLine() (D-67) → filterFactory->fromCli passing inert noSeo/noRetour through for filter-shape parity → !live dry-run early-return → MigrationOptions ctor → migrateAll → mergeReport(stage='taxonomies') → Stage stdout line."
    - "D-04 / D-12 three-flag cap preserved (--live / --confirm / --force only) — NO opt-out flag added for the taxonomies stage. The bolt-on is unconditional under --live; operator who wants to skip taxonomies on a particular run uses entity-level filters via --entities."
    - "Source-reflection style characterization tests for controller wiring (PluginBootstrapTest::testPluginDeclaresLegacyDbServiceComponent precedent) — assert literal config() declarations + grep-style invariants without a Craft bootstrap."

key-files:
  created:
    - tests/Unit/console/MigrateControllerTaxonomiesWiringTest.php  # 5 assertions: migrateAll-called-twice + actionTaxonomies-public + Stage-line-twice + no-opt-out-flag + enforceNeverProduction-FIRST
  modified:
    - src/Plugin.php                                                 # +14 LOC: use TaxonomyMigrationService, @property-read docblock (line 105), config() slot (line 169), init() DI fanout (lines 330-332)
    - src/console/MigrateController.php                              # +92 LOC: actionIndex Step 4.5 bolt-on (line 282 marker), actionTaxonomies sub-action (line 571 signature)
    - tests/integration/PluginBootstrapTest.php                      # +48 LOC: testPluginDeclaresTaxonomyMigrationServiceWiring (4 sub-assertions: slot, 3 fanout lines, property-read)

key-decisions:
  - "Bolt-on placement: Step 4.5 (between transform-complete and load-entries) NOT Step 6.7 (after retour). The plan's <interfaces> block was explicit (D-03: 'taxonomies migrate BEFORE pages'); reading actionIndex showed Step 5 = load and the natural insertion is right after the $opts MigrationOptions construction (line 280) which is hoisted above load specifically so SEO/Retour bolt-ons can reuse $opts. Taxonomies reuses the same $opts."
  - "actionTaxonomies docblock language: original drafts used 'NO --no-taxonomies flag' literally to make the D-04 invariant self-documenting, but that string broke the plan's grep-based <verify> step (`! grep -E 'noTaxonomies|--no-taxonomies'`). Reworded to 'NO opt-out flag for the taxonomies stage' — same semantic, no false-positive grep hit. Test fix-up was the alternative; chose source-reword because the test's job is to catch real flag introduction, not docstring mentions."
  - "Sub-action MigrationOptions construction: actionRetour writes `new MigrationOptions(dryRun: false, force: $this->force, skipAssets: false)` directly (no fromCli helper exists on MigrationOptions in v2). Mirrored verbatim — actionTaxonomies uses the same ctor shape. The plan's <action> Step B mentioned 'MigrationOptions::fromCli' but that method does not exist in the current codebase; the actionRetour-mirroring shape is the de facto standard."
  - "Sites map NOT wired into TaxonomyMigrationService at Plugin DI time (deviates from SeoMigrationService / RedirectMigrationService precedent which wire $sites = $this->resolveSitesMap()). Reason: TaxonomyMigrationService reads mapping.sites itself via mappingFile->load() inside its own D-09 fallback branch (verified by reading Plan 11's port at lines 80-85). Avoids dual-source-of-truth for the per-site overlay."
  - "TAX-08 deferred from REQUIREMENTS.md mark-complete: TAX-08 is referenced by ROADMAP.md Plan 08-12 row but has not been codified in `.planning/REQUIREMENTS.md` yet — Plan 08-17 owns DOC-01/DOC-02 codification. `requirements mark-complete` was therefore skipped (would error on missing requirement); the SUMMARY frontmatter `requirements-completed:` is also empty. Plan 08-17 will fill the requirement and tick it then."

requirements-completed: []

# Metrics
duration: ~12min
completed: 2026-04-27
---

# Phase 8 Plan 12: Plugin DI + MigrateController Bolt-on/Sub-action Summary

**Wires the Plan 11 TaxonomyMigrationService into the runtime: Plugin DI registers the component slot + sibling-DI fanout, MigrateController::actionIndex inserts a Step 4.5 'taxonomies' stage BEFORE the load step (D-03 — taxonomies migrate before pages), and a NeverProduction-gated `actionTaxonomies` sub-action provides the resume / debug entry point.**

## Performance

- **Duration:** ~12 min
- **Started:** 2026-04-27T15:29:26Z
- **Completed:** 2026-04-27
- **Tasks:** 2 / 2
- **Files created:** 1 (`tests/Unit/console/MigrateControllerTaxonomiesWiringTest.php`)
- **Files modified:** 3 (`src/Plugin.php`, `src/console/MigrateController.php`, `tests/integration/PluginBootstrapTest.php`)

## Wiring Locations

### Plugin.php

| Surface | Line | Content |
|---------|------|---------|
| `use` import | 35 | `use lameco\kunstmaanmigrator\load\TaxonomyMigrationService;` |
| `@property-read` docblock | 105 | `* @property-read TaxonomyMigrationService $taxonomyMigrationService` |
| `config() components` slot | 169 | `'taxonomyMigrationService' => TaxonomyMigrationService::class,` (after `redirectMigrationService` slot at line 168, before `baselineCounterService` at line 170) |
| `init()` DI fanout | 327-332 | `$this->taxonomyMigrationService->legacyDb / migrationState / mappingFile` (after `$this->redirectMigrationService->sites = ...` block at lines 314-318) |

### MigrateController.php

| Surface | Line | Content |
|---------|------|---------|
| `actionIndex` Step 4.5 bolt-on | 282-303 | `if ($this->live) { try { $taxonomyReport = $plugin->taxonomyMigrationService->migrateAll($opts); ... } catch (Throwable $e) { stderr "FAIL taxonomies: ..." } mergeReport(..., 'taxonomies') stdout "Stage taxonomies: created=X updated=X skipped=X failed=X" }` |
| `actionTaxonomies` sub-action | 553-625 | NeverProductionTrait gate FIRST → openLogFile/logLine (D-67) → filterFactory->fromCli (passes inert noSeo/noRetour through for shape parity) → !live early-return → MigrationOptions ctor → migrateAll → mergeReport → Stage stdout line |

**Insertion location relative to existing actionIndex steps:**

```
Step 1   (line 184)  locale preflight
Step 2   (line 195)  load mapping.yaml + coverage gate
Step 3   (line 209)  extract
Step 4   (line 226)  transform
        (line 276)  $opts = new MigrationOptions(...)              ← hoisted above load so all bolt-ons can reuse
Step 4.5 (line 282)  taxonomies bolt-on (D-03 — BEFORE pages)      ← INSERTED HERE
Step 5   (line 304)  load — per-entry atomic write
Step 6   (line 326)  finalize (CKEditor token resolution)
Step 6.5 (line 353)  SEO bolt-on (D-55)
Step 6.6 (line 391)  Retour bolt-on (D-55)
Step 7   (line 423)  REPORT.md
```

D-03 honored: taxonomies bolt-on appears in actionIndex BEFORE the load step. Page entries' `RelationHandler` will find taxonomy state rows ready by FK -> entryId lookup time.

## D-04 / D-12 Three-Flag Cap Confirmation

- `grep -cE "noTaxonomies|--no-taxonomies" src/console/MigrateController.php` → **0** ✓
- No new property added to the controller; `options($actionID)` declaration at lines 100-108 unchanged.
- The taxonomies bolt-on in actionIndex is unconditional under `--live`. Operators who want to skip taxonomies on a particular run use entity-level filters via `--entities`.

## Acceptance Criteria

### Plan 1 — Plugin.php

| Criterion | Result |
|-----------|--------|
| `grep -c "TaxonomyMigrationService::class" src/Plugin.php` returns 1 | **1** ✓ |
| `grep -c "taxonomyMigrationService->legacyDb" src/Plugin.php` returns ≥ 1 | **1** ✓ |
| `grep -c "taxonomyMigrationService->migrationState" src/Plugin.php` returns ≥ 1 | **1** ✓ |
| `grep -c "taxonomyMigrationService->mappingFile" src/Plugin.php` returns ≥ 1 | **1** ✓ |
| `grep -c "@property-read TaxonomyMigrationService" src/Plugin.php` returns 1 | **1** ✓ |
| Test: `PluginBootstrapTest::testPluginDeclaresTaxonomyMigrationServiceWiring` | PASS |

### Plan 2 — MigrateController.php

| Criterion | Result |
|-----------|--------|
| `grep -c "taxonomyMigrationService->migrateAll" src/console/MigrateController.php` ≥ 2 | **2** ✓ |
| `grep -c "public function actionTaxonomies" src/console/MigrateController.php` returns 1 | **1** ✓ |
| `grep -c "Stage taxonomies:" src/console/MigrateController.php` ≥ 2 | **2** ✓ |
| `grep -c "noTaxonomies\|--no-taxonomies" src/console/MigrateController.php` returns 0 | **0** ✓ |
| `actionTaxonomies` body starts with `enforceNeverProduction()` (D-20) | ✓ |
| Test: `MigrateControllerTaxonomiesWiringTest` (5 tests, 10 assertions) | ALL PASS |

## Test Discipline

Both tasks followed RED → GREEN. Two test commits (RED) followed by two implementation commits (GREEN).

| Phase | Commit | Surface |
|-------|--------|---------|
| RED 1 | 25c745c | `tests/integration/PluginBootstrapTest.php` (failing) |
| GREEN 1 | f466d5f | `src/Plugin.php` (test now passes) |
| RED 2 | 79a6927 | `tests/Unit/console/MigrateControllerTaxonomiesWiringTest.php` (failing) |
| GREEN 2 | 8c24cb3 | `src/console/MigrateController.php` (test now passes) |

Full test suite: **370 tests, 1030 assertions, all passing.** One pre-existing Craft framework deprecation noise from `craft\console\Controller::output()` parameter type — pre-dates this plan, scoped out per CLAUDE.md test-discipline note.

## Deviations from Plan

### Rule 3 — Blocking issue (verify command not available)

**1. `composer phpstan` is not a defined script in `composer.json`.**

- **Found during:** Task 1 baseline check.
- **Issue:** Plan's `<verify>` block specifies `composer phpstan && grep -c ...` but the project's `composer.json` `scripts` section only declares `test`, `test-unit`, `test-integration`, `test-coverage`. There is no PHPStan installation (`vendor/bin/phpstan` absent; no `phpstan.neon*` config file).
- **Fix:** Substituted `php -l` syntax check + `composer test` (PHPUnit full suite) for `composer phpstan` in the per-task verification. Both ran clean; full suite at 370/370 green. The grep-based acceptance criteria are unaffected and all pass.
- **Files modified:** none (no source change required — this is a verify-step substitution).
- **Tracking:** This is a project-wide gap — multiple Phase 8 PLANs likely cite `composer phpstan`. Out of scope for Plan 12 to add PHPStan to the build; logging here so a future tooling plan can pick it up. **Not adding to deferred-items.md** because the plan's success criteria + acceptance criteria are met without it.

### Rule 1 — Bug (docstring substring colliding with verify-grep)

**2. Original `actionTaxonomies` docblock contained the literal `--no-taxonomies` substring as self-documentation, breaking the plan's `<verify>` `! grep -E "noTaxonomies|--no-taxonomies"` invariant check.**

- **Found during:** Task 2 GREEN verification (test `testNoTaxonomiesFlagNotIntroduced` failed on substr_count=1 in source).
- **Issue:** `D-04 / D-12 invariant: NO --no-taxonomies flag — three-flag cap (--live / --confirm / --force) preserved.` — the literal flag string in a comment is a false positive for the regex.
- **Fix:** Reworded the docblock line to `NO opt-out flag for the taxonomies stage — the three-flag cap (--live / --confirm / --force) is preserved.` Same semantic, no substring collision. Test now passes.
- **Files modified:** `src/console/MigrateController.php` (docblock comment only, before commit `8c24cb3`).
- **Commit:** `8c24cb3` (the GREEN commit incorporates this rewording — the commit DAG shows a single GREEN commit per task, no separate fixup).

### Plan instruction adjusted (no rule, but recorded for traceability)

**3. Plan's `<action>` Step B template referenced `MigrationOptions::fromCli(...)`, which does not exist on the v2 `MigrationOptions` value object.**

- **Found during:** Task 2 implementation, while drafting `actionTaxonomies`.
- **Issue:** `actionRetour` (the documented analog) constructs `new MigrationOptions(dryRun: false, force: $this->force, skipAssets: false)` directly — there is no `fromCli` static helper on the class. The plan acknowledged the possibility ("If the existing sub-actions don't use a fromCli helper, use the same opts construction they use.") so this isn't a deviation per se — captured here for the record.
- **Fix:** Used the actionRetour-mirroring direct-ctor shape. No source ambiguity remains.

## TAX-08 REQUIREMENTS.md Codification — Deferred

`requirements mark-complete TAX-08` was **not** invoked. Reason: TAX-08 is referenced by ROADMAP.md Plan 08-12 row but the requirement itself has not yet been codified in `.planning/REQUIREMENTS.md`. Plan 08-17 (RECONCILIATION.md + CHANGELOG known-omissions + REQUIREMENTS codification) owns DOC-01/DOC-02 codification work — it will add TAX-08 to `### Taxonomies + AI proposers` and tick it as complete then.

`grep -n "TAX-08" .planning/REQUIREMENTS.md` returns 0 matches as of this commit (verified).

The SUMMARY frontmatter `requirements-completed:` field is empty for the same reason.

## Verification

- `composer test` — 370 tests, 1030 assertions, all passing (1 PHPUnit deprecation, 1 framework deprecation — pre-existing, scoped out).
- `php -l src/Plugin.php` — clean.
- `php -l src/console/MigrateController.php` — clean.
- D-03 run order honored — taxonomies bolt-on at line 282 in actionIndex, BEFORE the Step 5 load step at line 304. (Confirmed by line-number inspection.)
- D-04 / D-12 invariant — `grep -cE "noTaxonomies|--no-taxonomies"` returns 0.
- D-20 invariant — actionTaxonomies first non-trivial statement is `enforceNeverProduction()`. (Asserted by `testActionTaxonomiesGatesNeverProductionFirst`.)

## Self-Check: PASSED

- ✓ `src/Plugin.php` exists; modifications confirmed by grep-counts + integration test pass.
- ✓ `src/console/MigrateController.php` exists; bolt-on (line 282) + sub-action (line 553-625) confirmed by line lookup + 5 unit-test assertions.
- ✓ `tests/integration/PluginBootstrapTest.php` extended (was 53 LOC, now 101 LOC — confirmed via integration test pass).
- ✓ `tests/Unit/console/MigrateControllerTaxonomiesWiringTest.php` created (107 LOC, 5 tests).
- ✓ Commit `25c745c` (RED 1) found via `git log`.
- ✓ Commit `f466d5f` (GREEN 1) found via `git log`.
- ✓ Commit `79a6927` (RED 2) found via `git log`.
- ✓ Commit `8c24cb3` (GREEN 2) found via `git log`.
