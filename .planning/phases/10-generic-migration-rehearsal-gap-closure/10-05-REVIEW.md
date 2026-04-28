---
phase: 10-generic-migration-rehearsal-gap-closure
plan: 10-05
status: warnings_found
review_type: focused-code-review
---

# Code Review Report - Phase 10 Plan 10-05 Implementation

## Scope reviewed

- `src/load/TaxonomyMigrationService.php`
- `tests/integration/load/TaxonomyMigrationTest.php`
- `src/console/MigrateController.php`
- `tests/unit/console/MigrateControllerFailureExitTest.php`
- `.planning/rehearsal/v1.0/cqm/README.md`

## Context reviewed

- `10-05-PLAN.md`
- `10-05-SUMMARY.md`
- `10-VERIFICATION.md`
- `10-REVIEW.md`
- `10-CONTEXT.md`

## Targeted verification run during review

- `vendor/bin/phpunit tests/integration/load/TaxonomyMigrationTest.php --filter 'Compiled|Locale|Fallback|MappingSite|SiteHandle' --testdox` passed, with expected incomplete Craft-bootstrap fallback test and no-coverage warning.
- `vendor/bin/phpunit tests/unit/console/MigrateControllerFailureExitTest.php --filter 'Transform|Sentinel|Handler|Relation|Taxonomy' --testdox` passed, with no-coverage/deprecation noise.
- `grep -n "kunstmaan-migrator/migrate --dry-run" .planning/rehearsal/v1.0/cqm/README.md` returned no matches.

## Summary

The core 10-05 implementation closes the originally verified gaps in the primary full `migrate` flow:

- Taxonomy fallback now resolves compiled `mapping.sites` values such as `['nl' => 'default', 'en' => 'enUs']` via `siteHandleFromMappingSite()`.
- Transform `__report` sentinel warnings are merged into the main `MigrationReport` with `Transform:` prefixes and `transform.warning` counts.
- Full live `actionIndex()` blocks after transform and before taxonomy/load/finalize/SEO/Retour when a relation/taxonomy transform handler failure is detected.
- Dry-run remains non-failing while keeping transform warnings visible.
- The CQM runbook now documents the supported default dry-run command and `--live` write mode.

No critical security issues were found. Two warning-level reliability gaps and two suggestion-level test coverage gaps were found.

## Critical Findings

None.

## Warning Findings

### W-01: Localized taxonomy fallback save failures are ignored after reporting fallback success

**File:** `src/load/TaxonomyMigrationService.php`

In the empty-`ext_translations` fallback branch, the code increments `fallback.taxonomy_locale` and writes an operator-visible fallback warning, then calls `saveElement()` without checking whether the localized save actually succeeded.

If Craft returns `false` for validation/save failure, the report still says the fallback happened, the fallback counter is incremented, and the migration can continue with stale or incomplete localized taxonomy values. This weakens the Phase 10 requirement that taxonomy fallback be both correct and honestly reported.

The same unchecked-save pattern exists in the Gedmo overlay branch, so translated/fallback per-site taxonomy writes can fail silently there too.

**Required fix:** Check the `saveElement()` return value in both localized taxonomy save branches. If saving fails, throw a `RuntimeException` containing the taxonomy slug, legacy id, site handle, locale, and element error summary. Only increment/report fallback success after a successful save.

### W-02: Standalone `migrate/load --live` can bypass a prior transform relation/taxonomy block

**File:** `src/console/MigrateController.php`

The full `actionIndex()` live flow correctly blocks after transform and before load when `mergeTransformReportSentinel()` detects a relation/taxonomy handler failure.

However, the standalone transform action writes transformed payloads to disk before it processes the final sentinel. If a blocking relation/taxonomy warning is detected, `actionTransform()` writes a failure report and returns non-zero in `--live` mode, but the already-written transformed JSON files remain on disk.

`actionLoad()` later loads every transformed file and does not check for a prior transform-blocking failure marker/report. A supported staged workflow can still persist entries with omitted relation/taxonomy fields if an operator runs `migrate/transform` and then runs `migrate/load --live` anyway.

**Required fix:** Persist a machine-readable transform block marker whenever a blocking transform relation/taxonomy warning is detected, clear the marker at the start of a fresh transform run, and make `actionLoad --live` refuse to proceed while the marker exists.

## Suggestions / Test Coverage Gaps

### S-01: Taxonomy fallback regression only tests the private helper, not the fallback branch using Craft site lookup

**File:** `tests/integration/load/TaxonomyMigrationTest.php`

The new tests prove that `siteHandleFromMappingSite()` returns the right strings for compiled and array-shaped mappings. They do not cover the actual fallback loop using Craft site lookup and localized save/report behavior.

**Suggested improvement:** Add a Craft app/sites/elements shim or extract the fallback loop into a testable collaborator so the regression can assert behavior, not only helper return values.

### S-02: Live transform blocking is tested through private helpers, not through `actionIndex()` stage ordering

**File:** `tests/unit/console/MigrateControllerFailureExitTest.php`

The new tests verify sentinel warning merge, blocking boolean detection, synthetic failure recording, and exit code after recording. They do not directly prove that the full live `actionIndex()` path stops before taxonomy/load/finalize/SEO/Retour after a relation/taxonomy transform handler failure.

**Suggested improvement:** Add a controller orchestration test with fake/stub services proving live mode stops before load and downstream stages after a relation/taxonomy transform handler failure.
