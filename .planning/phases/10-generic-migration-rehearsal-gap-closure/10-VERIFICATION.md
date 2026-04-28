---
phase: 10-generic-migration-rehearsal-gap-closure
verified: 2026-04-28T17:46:48Z
status: human_needed
score: 20/21 must-haves verified
overrides_applied: 0
re_verification:
  previous_status: gaps_found
  previous_score: 18/21
  gaps_closed:
    - "Taxonomy locale fallback now resolves Craft site handles from mapping.sites values, preserves array-shaped compatibility, checks localized save failures, and reports fallback site+locale honestly."
    - "Transform __report sentinel warnings now merge into MigrationReport/REPORT.md; live relation/taxonomy handler failures block before load; standalone staged live load refuses known-bad transformed artifacts via transform-block marker; dry-run remains visible/non-failing."
    - "CQM runbook now uses supported default dry-run command php craft kunstmaan-migrator/migrate and documents --live as write mode; unsupported --dry-run command is absent from the runbook."
  gaps_remaining: []
  regressions: []
human_verification:
  - test: "Release-owner review of Page-rooted coverage warning/unsupported classifications"
    expected: "Every warning/unsupported Page-rooted coverage row is either explicitly accepted for v1.0 release or reclassified/fixed before tagging."
    why_human: "The repository contains summary/count evidence, but product acceptance of visible warning/unsupported CQM coverage rows depends on release-owner intent and external rehearsal artifacts."
---

# Phase 10: Generic Migration Rehearsal Gap Closure Verification Report

**Phase Goal:** Convert the CQM release-rehearsal findings into generic migration hardening so a clean rerun can complete without the three known entry failures and without silent loss of page-owned relations or content.

**Verified:** 2026-04-28T17:46:48Z  
**Status:** human_needed  
**Re-verification:** Yes — after gap closure plan `10-05` plus review fixes/recheck

## Verification Context

- Previous verification checked: `.planning/phases/10-generic-migration-rehearsal-gap-closure/10-VERIFICATION.md` had `status: gaps_found`, `score: 18/21`, with three structured gaps.
- Gap closure artifacts reviewed:
  - `10-05-PLAN.md`
  - `10-05-SUMMARY.md`
  - `10-05-REVIEW.md`
  - `10-05-REVIEW-FIX.md`
  - `10-05-REVIEW-RECHECK.md`
- Relevant planning context reviewed:
  - `.planning/PROJECT.md`
  - `.planning/REQUIREMENTS.md`
  - `.planning/ROADMAP.md`
  - `.planning/STATE.md`
  - `10-CONTEXT.md`
  - `10-RESEARCH.md`
  - `10-PATTERNS.md`
  - `10-PLAN.md`
  - `10-01-SUMMARY.md` through `10-04-SUMMARY.md`
  - `10-SUMMARY.md`
  - `10-REVIEW.md`
- Relevant code/docs/tests inspected:
  - `src/load/TaxonomyMigrationService.php`
  - `tests/integration/load/TaxonomyMigrationTest.php`
  - `src/console/MigrateController.php`
  - `tests/unit/console/MigrateControllerFailureExitTest.php`
  - `.planning/rehearsal/v1.0/cqm/README.md`
- Project skills: no `.github/skills/` or `.agents/skills/` `SKILL.md` files found.
- Branch confirmed: `gsd/phase-9-migration-hardening-page-rooted-introspection`.
- Working tree note: only unrelated untracked `.claude/` content was present.

## Goal Achievement

### Observable Truths

| # | Truth | Status | Evidence |
|---|---|---|---|
| 1 | ContactPage-style Matrix payloads with required block titles save generically without losing block field content. | VERIFIED | Previously verified. `EntryMigrationService` fallback behavior and tests remain in place; no 10-05 regression found. |
| 2 | TextPage-style sparse-locale payloads save to Craft without blank primary-site title/slug failures and keep site enablement/source truth truthful. | VERIFIED | Previously verified. Sparse-locale fallback was not regressed by 10-05 changes. |
| 3 | VacancyFormPage-style invalid section/entry-type mappings are blocked or routed before load. | VERIFIED | Previously verified. `MigrateControllerFailureExitTest::testPreflightCompiledMappingBlocksLoadFatalTargetValidation()` still passes in targeted suite. |
| 4 | Advisory target warnings remain warning-only when they cannot cause live load failure. | VERIFIED | Previously verified; compile/preflight fatal handling remains scoped to load-fatal mismatches. |
| 5 | PageBuilder handles propagate only when the parent entry type owns the target Matrix field. | VERIFIED | Previously verified; no changes regressed `MappingCompiler` ownership behavior. |
| 6 | Invalid PageBuilder ownership is blocked or explicitly reported with source-preserving fallback. | VERIFIED | Previously verified; no 10-05 changes to this path. |
| 7 | Matrix/sparse fallback usage is visible in operator reports or logs. | VERIFIED | Previously verified; report fallback rendering remains. |
| 8 | Successful fallbacks do not increment failure counts. | VERIFIED | Previously verified; targeted and full tests reported in 10-05 artifacts remain green. |
| 9 | A page with a non-empty taxonomy FK creates/reuses the referenced Craft taxonomy entry during page migration. | VERIFIED | Normal resolver path was previously verified. The prior caveat around swallowed handler/resolver exceptions is now closed: transform handler warnings are merged into the main `MigrationReport`, and live relation/taxonomy transform failures block before load. |
| 10 | Default taxonomy migration is referenced-only. | VERIFIED | Previously verified: `MigrateController` computes referenced-only unless CLI/settings opt in. |
| 11 | Unreferenced taxonomy rows migrate only when explicit CLI/settings path is enabled. | VERIFIED | Previously verified: `includeUnreferencedTaxonomies` controls full pre-load taxonomy import. |
| 12 | Missing taxonomy locale values use default-language values and are visibly reported. | VERIFIED | Closed by 10-05. `TaxonomyMigrationService::siteHandleFromMappingSite()` resolves compiled scalar values such as `['nl' => 'default', 'en' => 'enUs']`, preserves array-shaped compatibility, and fallback warnings include both `site=<Craft handle>` and `locale=<legacy locale>`. Review fix also checks localized `saveElement()` results before reporting fallback success. |
| 13 | Dry-run creates no Craft entries and no state rows for lazy taxonomy creation. | VERIFIED | Previously verified; resolver dry-run tests remain in `TaxonomyMigrationTest`. |
| 14 | Verify output compares Craft baseline/current drift separately from migration-created/source-parity counts. | VERIFIED | Previously verified; `10-SUMMARY.md` records restored rehearsal verify labels. |
| 15 | Report labels make compared count domains explicit. | VERIFIED | Previously verified; `10-SUMMARY.md` records `Craft baseline/current drift`, `Migration-created state counts`, and `Source/transformed parity`. |
| 16 | Verify no longer reports false failures caused by mismatched baseline semantics. | VERIFIED | Previously verified; restored CQM proof reached zero failures with source parity honestly marked unavailable where no source-derived expected artifact exists. |
| 17 | Regression tests cover the known generic failure categories without proprietary source content. | VERIFIED | Targeted tests for 10-05 passed during this verification; `10-05-SUMMARY.md` and `10-05-REVIEW-FIX.md` also record full `composer test` passing. Tests use generic helper seams and source scans, not proprietary content values. |
| 18 | Restored-backup CQM full workflow reaches zero entry failures and zero stage failures. | VERIFIED | `10-SUMMARY.md` records restored backup path and workflow with `REPORT.md` evidence: `failed 0`, `Total failed: 0`, `_No per-entry failures._`. User explicitly directed that full restored CQM rehearsal need not be rerun unless absolutely necessary. |
| 19 | Page-owned referenced surfaces are migrated or classified as explicitly dropped/out-of-scope without counting as entry/stage failures. | HUMAN NEEDED | Automated code-level silent-loss gap is closed: relation/taxonomy transform handler failures are now visible/blocking in live flows, and staged live load refuses known-bad transformed artifacts. However, `10-SUMMARY.md` records visible `warning` and `unsupported` Page-rooted coverage rows requiring release-owner acceptance before tagging. |
| 20 | No production code contains new CQM-specific page IDs, block handles, or class-name conditionals. | VERIFIED | 10-05 changes are generic: taxonomy mapping shape helper, transform sentinel/marker handling, and runbook docs. No CQM-specific production conditionals found in reviewed 10-05 files. |
| 21 | Restored-backup rerun instructions include restore, full rerun, and inspection gates and are executable. | VERIFIED | Closed by 10-05. `.planning/rehearsal/v1.0/cqm/README.md` now uses `php craft kunstmaan-migrator/migrate` for default dry-run and `php craft kunstmaan-migrator/migrate --live` for write mode; unsupported `kunstmaan-migrator/migrate --dry-run` is absent from the runbook. |

**Score:** 20/21 automated truths verified; 1 release-owner human verification item remains.

## Previous Gaps Re-evaluated

| Previous Gap | Status | Evidence |
|---|---|---|
| Taxonomy locale fallback must resolve Craft site handles from mapping values such as `['nl' => 'default', 'en' => 'enUs']`, preserve array-shaped compatibility, save localized fallback honestly, and visibly report fallback site+locale. | CLOSED | `TaxonomyMigrationService.php` lines 542-583 iterate `$legacyLocale => $siteCfg`, call `siteHandleFromMappingSite()`, resolve Craft site by mapped handle, check localized `saveElement()`, and only then increment/report fallback. Helper at lines 671-686 handles scalar values, array `siteHandle`, legacy-locale fallback, null/unsupported shapes. Failure helper at lines 688-710 includes taxonomy slug, legacy id, site, locale, and element errors. Tests at `TaxonomyMigrationTest.php` lines 281-331 cover compiled values, array compatibility, and error-message context. |
| Transform `__report` sentinel warnings must reach main `MigrationReport`/`REPORT.md`; live relation/taxonomy transform handler failures must block before load; standalone staged live load must not consume known-bad transformed artifacts; dry-run must remain visible/non-failing. | CLOSED | `MigrateController::mergeTransformReportSentinel()` lines 1874-1887 prefixes warnings with `Transform:` and increments `transform.warning`. Blocking detection at lines 1889-1895 covers relation/taxonomy handler failures and `TaxonomyMigrationService` failures. Full flow writes marker and blocks live before taxonomy/load/finalize/SEO/Retour at lines 313-321. Standalone transform writes marker and blocks live at lines 781-789. `actionLoad()` reads marker at lines 848-864, records failure and returns non-zero in live mode, while dry-run only warns. Tests at `MigrateControllerFailureExitTest.php` lines 143-231 cover sentinel merge, dry-run non-failure, live synthetic failure, marker stability, and staged live-load check. |
| CQM runbook must use supported default dry-run command `php craft kunstmaan-migrator/migrate` and document `--live` as write mode, with no unsupported `--dry-run` command. | CLOSED | README lines 13-24 list restored workflow with `php craft kunstmaan-migrator/migrate` followed by `php craft kunstmaan-migrator/migrate --live`. README line 26 explicitly states dry-run is the default mode and `--live` is write mode. Verification grep found no `kunstmaan-migrator/migrate --dry-run` in the README. |

## Required Artifacts

| Artifact | Expected | Status | Details |
|---|---|---|---|
| `src/load/TaxonomyMigrationService.php` | Taxonomy locale fallback resolves Craft handles from mapping values; localized fallback saves are honest; warnings show site+locale. | VERIFIED | `applyGedmoTranslations()` uses `$legacyLocale => $siteCfg`, `siteHandleFromMappingSite()`, checks save result, and reports `site=%s locale=%s`. Both empty-translation and Gedmo overlay branches check localized `saveElement()` and throw on failure. |
| `tests/integration/load/TaxonomyMigrationTest.php` | Regression coverage for compiled mapping shape, array compatibility, and localized save-failure reporting. | VERIFIED | Targeted test run passed: 5 tests, 5 assertions, 1 known incomplete Craft-bootstrap fallback test, PHPUnit no-coverage warning. Tests include compiled scalar `['nl' => 'default', 'en' => 'enUs']`, array `siteHandle`, legacy-locale fallback, and error-message context. |
| `src/console/MigrateController.php` | Merge transform sentinel warnings into main report; block live relation/taxonomy handler failures before load; protect staged live load from known-bad transformed artifacts. | VERIFIED | `mergeTransformReportSentinel()`, `recordBlockingTransformFailure()`, and transform-block marker helpers are present and wired in full transform, standalone transform, and standalone load paths. |
| `tests/unit/console/MigrateControllerFailureExitTest.php` | Regression coverage for sentinel merge, dry-run visibility/non-failure, live blocking, and staged load marker behavior. | VERIFIED | Targeted test run passed: 7 tests, 32 assertions, one existing deprecation, PHPUnit no-coverage warning. |
| `.planning/rehearsal/v1.0/cqm/README.md` | Supported restored-backup runbook commands and inspection gates. | VERIFIED | Default dry-run command and `--live` write command are documented. Unsupported `migrate --dry-run` command is absent from the runbook. |
| `10-05-REVIEW-FIX.md` / `10-05-REVIEW-RECHECK.md` | Review findings fixed and rechecked. | VERIFIED | Fix report records W-01 and W-02 fixed; recheck reports `PASS. No Critical or Warning findings remain`. Code inspection corroborates both fixes. |

## Key Link Verification

| From | To | Via | Status | Details |
|---|---|---|---|---|
| `TaxonomyMigrationService::applyGedmoTranslations()` | Craft site handles from `mapping.sites` values | `siteHandleFromMappingSite((string) $legacyLocale, $siteCfg)` | WIRED | Compiled scalar mapping values now drive `getSiteByHandle($siteHandle)`, while `$legacyLocale` remains available for reporting. |
| `TaxonomyMigrationService::applyGedmoTranslations()` | Localized Craft save truthfulness | `if (!Craft::$app->elements->saveElement(...)) throw RuntimeException(...)` | WIRED | Both empty-translation fallback branch and Gedmo overlay branch check save result before fallback success counters/warnings. |
| `TaxonomyMigrationService` | Operator-visible fallback reporting | `MigrationReport::warn('fallback: taxonomy locale values ... site=... locale=...')` | WIRED | Warning includes resolved Craft site handle and legacy locale. |
| `TransformService` sentinel payload | `MigrationReport` / `REPORT.md` | `MigrateController::mergeTransformReportSentinel()` | WIRED | Sentinel `warnings` are prefixed with `Transform:` and merged into the run report with `transform.warning` count. |
| `MigrateController::actionIndex()` | Live pre-load block | `$hasBlockingTransformRelationFailure` -> marker + `recordBlockingTransformFailure()` + `writeReport()` + `reportExitCode()` | WIRED | Full live flow blocks immediately after transform and before taxonomy/load/finalize/SEO/Retour when relation/taxonomy transform warnings are blocking. |
| `MigrateController::actionTransform()` | Staged transform block marker | `writeTransformBlockMarker($storageDir, $report)` | WIRED | Standalone transform persists `transform-block.json` whenever relation/taxonomy transform warnings are blocking. |
| `MigrateController::actionLoad()` | Staged live load refusal | `readTransformBlockMarker()` -> live failure / dry-run warning | WIRED | Live load records warning/failure and exits non-zero; dry-run remains visible and non-failing. |
| CQM README | Current CLI surface | `php craft kunstmaan-migrator/migrate` and `php craft kunstmaan-migrator/migrate --live` | WIRED | Runbook commands match `MigrateController` options: `--live` exists; `--dry-run` is not registered. |

## Data-Flow Trace (Level 4)

| Artifact | Data Variable | Source | Produces Real Data | Status |
|---|---|---|---|---|
| `TaxonomyMigrationService.php` | `$siteHandle` for localized taxonomy fallback | `mapping['sites']` values via `siteHandleFromMappingSite()` | Yes | FLOWING — scalar compiled values become Craft handles; array-shaped mappings still work via `siteHandle` or legacy-locale fallback. |
| `TaxonomyMigrationService.php` | Fallback success warning/count | Localized `Entry::find()->siteId(...)` + successful `saveElement()` | Yes | FLOWING — report increments and warnings occur only after localized save succeeds. |
| `MigrateController.php` | Transform warnings in main report | `payload['__report']['warnings']` | Yes | FLOWING — warnings are merged into `MigrationReport::warnings` with `Transform:` prefix and `transform.warning` counter. |
| `MigrateController.php` | Live block decision | `isBlockingTransformRelationWarning()` over merged transform warnings | Yes | FLOWING — relation/taxonomy/TaxonomyMigrationService warning patterns set blocking boolean. |
| `MigrateController.php` | Staged transform-block marker | Blocking transform warning set + `writeTransformBlockMarker()` JSON payload | Yes | FLOWING — standalone and full transform write marker; load reads marker before processing transformed files. |
| `README.md` runbook commands | Operator command text | Checked-in runbook | Yes | FLOWING — restored workflow uses supported commands only. |

## Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|---|---|---|---|
| Taxonomy gap-closure targeted tests pass | `vendor/bin/phpunit tests/integration/load/TaxonomyMigrationTest.php --filter 'Compiled|Locale|Fallback|MappingSite|SiteHandle|Save' --testdox` | Exit 0. Output: 5 tests, 5 assertions, 1 known incomplete Craft-bootstrap fallback test, PHPUnit no-coverage warning. | PASS |
| Transform/report/marker targeted tests pass | `vendor/bin/phpunit tests/unit/console/MigrateControllerFailureExitTest.php --filter 'Transform|Sentinel|Handler|Relation|Taxonomy|Marker|Load' --testdox` | Exit 0. Output: 7 tests, 32 assertions, one existing deprecation, PHPUnit no-coverage warning. | PASS |
| Modified production files lint | `php -l src/load/TaxonomyMigrationService.php && php -l src/console/MigrateController.php` | No syntax errors detected in both files. | PASS |
| CQM README has no unsupported dry-run command | `grep -n "kunstmaan-migrator/migrate --dry-run" .planning/rehearsal/v1.0/cqm/README.md` | No matches. | PASS |
| CQM README documents supported commands | `grep -n "php craft kunstmaan-migrator/migrate" .planning/rehearsal/v1.0/cqm/README.md` | Lines 20, 21, and 26 show default migrate and `--live` write mode. | PASS |
| Full repository test evidence adequate | Reviewed `10-05-SUMMARY.md`, `10-05-REVIEW-FIX.md`, `10-05-REVIEW-RECHECK.md` | `10-05-SUMMARY.md` records full `composer test`: 491 tests, 1638 assertions, exit 0. Review-fix records `composer test` exit 0 after W-01/W-02 fixes. Recheck records targeted suites passing. Full restored CQM rerun was not required per user instruction. | PASS |

## Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|---|---|---|---|---|
| PH10-01 | `10-02-PLAN.md` / ROADMAP | Generic Matrix-block title fallback before Craft save, without CQM hardcoding. | SATISFIED | Previously verified; no regression in 10-05. |
| PH10-02 | `10-02-PLAN.md` / ROADMAP | Sparse-locale primary save fallback preserving locale truth. | SATISFIED | Previously verified; no regression in 10-05. |
| PH10-03 | `10-01-PLAN.md` / ROADMAP | Compile/load guard for section + entry-type compatibility. | SATISFIED | Preflight target validation test still passes. |
| PH10-04 | `10-03-PLAN.md` / `10-05-PLAN.md` / ROADMAP | Taxonomy-dependent relation resolution and no silent relation/taxonomy handler loss. | SATISFIED | Normal lazy resolver path exists; 10-05 closes handler-exception visibility/blocking by merging `__report` warnings, blocking live full flow, and refusing staged live load with marker. |
| PH10-05 | `10-01-PLAN.md` / ROADMAP | PageBuilder Matrix ownership validation and flat fallback preservation. | SATISFIED | Previously verified; no regression. |
| PH10-06 | `10-04-PLAN.md` / ROADMAP | Verify count semantics distinguish domains. | SATISFIED | Previously verified; restored proof summary records domain labels. |
| PH10-07 | `10-01/02/03/05-PLAN.md` / ROADMAP | Regression tests cover known failures generically. | SATISFIED | Targeted tests pass; `composer test` pass recorded after 10-05 fixes. |
| PH10-08 | `10-04-PLAN.md` / `10-05-PLAN.md` / ROADMAP | Rehearsal restore/rerun instructions and inspection gates. | SATISFIED WITH HUMAN RELEASE REVIEW | Runbook commands are executable against current CLI; inspection gates remain. Release-owner still needs to accept or resolve visible `warning`/`unsupported` Page-rooted coverage rows before tagging. |

No additional PH10 entries were found in `.planning/REQUIREMENTS.md`; Phase 10 requirement IDs are declared in ROADMAP and Phase 10 planning artifacts.

## Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|---|---:|---|---|---|
| `src/load/TaxonomyMigrationService.php` | 98, 109, 129, 140, 737 | `return null` | Info | Legitimate resolver miss / dry-run / no mapping branches; not stubs. |
| `src/console/MigrateController.php` | 1029, 1357, 2207, 2269, 2297, 2324 | `placeholder` in comments/report rendering | Info | Report-section placeholder copy for empty sync/RCA/skipped-stage sections; not user-facing implementation stubs. |
| `src/console/MigrateController.php` | 1443, 1448, 2372 | `return []` | Info | Empty arrays for helper/default paths; not hollow data rendered as success. |
| `src/console/MigrateController.php` | 1487, 1953 | `return null` | Info | Missing marker / no-result helper returns; expected control flow. |

No blocker anti-patterns remain in the 10-05 modified code/docs. The previous blocker patterns — taxonomy site-handle mismatch and discarded transform report sentinel — are fixed.

## Review Finding Disposition

| Review Artifact | Finding | Status | Verification |
|---|---|---|---|
| `10-05-REVIEW.md` | W-01: Localized taxonomy fallback save failures are ignored after reporting fallback success. | FIXED | `TaxonomyMigrationService` checks `saveElement()` in both localized branches and throws contextual `RuntimeException` before counters/warnings. `TaxonomyMigrationTest` covers the failure-message context. |
| `10-05-REVIEW.md` | W-02: Standalone `migrate/load --live` can bypass a prior transform relation/taxonomy block. | FIXED | `MigrateController` clears marker at fresh transform start, writes `transform-block.json` on blocking warnings, and makes live load refuse marker while dry-run warns. `MigrateControllerFailureExitTest` source-scan/reflective tests cover marker behavior. |
| `10-05-REVIEW-RECHECK.md` | Re-review result | PASS | Recheck states: `PASS. No Critical or Warning findings remain for the requested re-review scope.` Code inspection corroborates this. |
| `10-05-REVIEW.md` | S-01/S-02 suggestions | NON-BLOCKING | Suggestions remain test-depth improvements, not Critical/Warning blockers. The code paths are still adequate for Phase 10 verification given targeted tests plus prior restored CQM proof. |

## Human Verification Required

### 1. Release-owner review of Page-rooted `warning` / `unsupported` classifications

**Test:** Review the latest external CQM `PAGE-ROOTED-COVERAGE.md` and any related `REPORT.md` rows classified as `warning` or `unsupported`.

**Expected:** Each row is either explicitly accepted by the release owner with rationale, or reclassified/fixed before tagging v1.0.

**Why human:** Automated repository verification can confirm that omissions are visible and that code no longer silently drops relation/taxonomy failures. It cannot decide whether visible `warning` / `unsupported` product coverage classifications are acceptable for release.

## Gaps Summary

No implementation gaps remain from the previous `10-VERIFICATION.md`.

All three prior verifier gaps are closed:

1. Taxonomy locale fallback now resolves Craft handles from compiled `mapping.sites` values, preserves array-shaped compatibility, checks localized save failures, and reports resolved site plus legacy locale.
2. Transform sentinel warnings now reach the main migration report, live relation/taxonomy handler failures block before load, staged live load refuses known-bad transform artifacts, and dry-run remains visible/non-failing.
3. The CQM runbook no longer documents unsupported `migrate --dry-run`; it uses default `migrate` for dry-run and `migrate --live` for write mode.

The remaining `human_needed` status is limited to release-owner acceptance or remediation of visible Page-rooted `warning` / `unsupported` classifications before v1.0 tagging. Automated code/test/doc verification is otherwise complete.

---

_Verified: 2026-04-28T17:46:48Z_  
_Verifier: the agent (gsd-verifier)_
