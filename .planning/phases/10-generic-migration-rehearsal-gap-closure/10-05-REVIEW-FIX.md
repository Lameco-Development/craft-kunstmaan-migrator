---
phase: 10-generic-migration-rehearsal-gap-closure
plan: 10-05
fixed_at: 2026-04-28T17:41:25Z
review_path: /Users/macbook25/Sites/craft-kunstmaan-migrator-revisited/.planning/phases/10-generic-migration-rehearsal-gap-closure/10-05-REVIEW.md
iteration: 1
findings_in_scope: 2
fixed: 2
skipped: 0
status: all_fixed
---

# Phase 10: Code Review Fix Report

**Fixed at:** 2026-04-28T17:41:25Z  
**Source review:** `/Users/macbook25/Sites/craft-kunstmaan-migrator-revisited/.planning/phases/10-generic-migration-rehearsal-gap-closure/10-05-REVIEW.md`  
**Iteration:** 1

## Summary

- Findings in scope: 2 (`W-01`, `W-02`)
- Fixed: 2
- Skipped: 0
- Remaining review status: warning findings fixed; suggestions `S-01` and `S-02` remain suggestions only.

## Fixed Issues

### W-01: Localized taxonomy fallback save failures are ignored after reporting fallback success

**Files modified:** `src/load/TaxonomyMigrationService.php`, `tests/integration/load/TaxonomyMigrationTest.php`  
**Commit:** `dcf7497`  
**Applied fix:** localized taxonomy saves in both the empty-`ext_translations` fallback branch and Gedmo overlay branch now check `Craft::$app->elements->saveElement(...)`. Failed localized saves throw a `RuntimeException` with taxonomy slug, legacy id, site handle, locale, and element error summary. Fallback counters and warnings are emitted only after a successful localized save. Added a focused reflection test for the extracted localized-save error message formatting.

### W-02: Standalone `migrate/load --live` can bypass a prior transform relation/taxonomy block

**Files modified:** `src/console/MigrateController.php`, `tests/unit/console/MigrateControllerFailureExitTest.php`  
**Commit:** `5cadcca`  
**Applied fix:** transform flows now clear stale transform-block markers at the start of a fresh transform run and persist a machine-readable `transform-block.json` marker whenever relation/taxonomy transform warnings are blocking. Standalone `actionLoad --live` now refuses to proceed while that marker exists, records an operator-visible warning/failure in the normal migration report path, and returns non-zero. Dry-run load remains visible and non-failing. Added focused reflection/source-scan tests for marker path/message stability, marker clearing/persistence call sites, and live-load marker checks.

## Verification

Commands run from repository root:

- `vendor/bin/phpunit tests/integration/load/TaxonomyMigrationTest.php --filter 'Compiled|Locale|Fallback|MappingSite|SiteHandle|Save' --testdox`
  - Result: passed (5 tests, 5 assertions; expected 1 incomplete Craft-bootstrap fallback test; no-coverage runner warning).
- `vendor/bin/phpunit tests/unit/console/MigrateControllerFailureExitTest.php --filter 'Transform|Sentinel|Handler|Relation|Taxonomy|Marker|Load' --testdox`
  - Result: passed (7 tests, 32 assertions; no-coverage runner warning; existing deprecation notice).
- `php -l src/load/TaxonomyMigrationService.php`
  - Result: passed; no syntax errors detected.
- `php -l src/console/MigrateController.php`
  - Result: passed; no syntax errors detected.
- `composer test`
  - Result: passed with exit code 0.

## Remaining Blockers

None for the requested warning scope. The repository still has unrelated untracked `.claude/` content, which was not touched.

---

_Fixed: 2026-04-28T17:41:25Z_  
_Fixer: the agent (gsd-code-fixer)_  
_Iteration: 1_
