---
phase: 10-generic-migration-rehearsal-gap-closure
plan: 10-02
subsystem: migration-load-fallbacks
tags: [php, craft-cms, load, matrix, locales, migration-report, phpunit]

requires:
  - phase: 10-generic-migration-rehearsal-gap-closure
    provides: "10-01 load-fatal compile/preflight guards and structural regression fixtures"
provides:
  - "Generic Matrix native-title fallback before Craft save"
  - "Sparse-locale primary-site save fallback that preserves source per-site payloads"
  - "Operator-visible fallback warnings and REPORT.md fallback section"
affects: [10-03-taxonomy-resolver, 10-04-verify-rehearsal-proof]

tech-stack:
  added: []
  patterns:
    - "EntryMigrationService normalizes Matrix blocks with deterministic validation titles when source data lacks native title"
    - "Primary-site save can borrow native title/slug from the best available source locale without mutating perSite"
    - "MigrationReport fallback.* counters and warnings render in MigrateController REPORT.md without affecting failed counts"

key-files:
  created:
    - ".planning/phases/10-generic-migration-rehearsal-gap-closure/10-02-SUMMARY.md"
    - "tests/integration/load/EntryMigrationServiceTest.php"
  modified:
    - "src/load/EntryMigrationService.php"
    - "src/load/AtomicMigrationService.php"
    - "src/console/MigrateController.php"
    - "tests/unit/console/MigrateControllerFailureExitTest.php"

key-decisions:
  - "Matrix title fallback prefers existing peer title, then lifted fields.title/fields.heading, then a deterministic generic title from block type, position, and optional source ref."
  - "Sparse-locale fallback borrows native validation data for the first primary-site save only; it does not add fake primary-site source payloads or change source-driven enablement."
  - "Successful fallback usage is reported as fallback.* counts and warnings, not as entry/stage failures."

patterns-established:
  - "Load fallbacks are report-visible via MigrationReport::warn plus fallback.* counters; Craft::warning remains a log fallback."
  - "Localized saves iterate all configured non-primary sites by actual primary site id, not by configured array position."

requirements-completed: [PH10-01, PH10-02, PH10-07]

duration: 6min
completed: 2026-04-28
---

# Phase 10 Plan 10-02: Load Fallback Hardening Summary

**Generic Matrix block titles and sparse-locale primary saves now fall back visibly at the load boundary without incrementing failure counts when Craft saves succeed.**

## Performance

- **Duration:** 6 min
- **Started:** 2026-04-28T15:58:30Z
- **Completed:** 2026-04-28T16:04:50Z
- **Tasks:** 3
- **Files modified:** 5 code/test files + 1 summary

## Accomplishments

- Extended `EntryMigrationService` Matrix normalization so blocks missing native `title` receive a deterministic non-empty validation title before `setFieldValues()` / Craft save.
- Preserved source block field values while continuing established cleanup/lift behavior for `_sourcePartRef`, `fields.title`, and `fields.heading`.
- Added sparse-locale primary-site fallback that borrows native `title`/`slug` values from the best available non-primary source payload for the first primary save only.
- Preserved original `perSite` payloads and source-driven enabled map semantics; the fallback does not create a fake source locale.
- Fixed the localized-save loop to exclude Craft's actual primary site by id rather than assuming the primary site is array position 0.
- Passed the per-entry `MigrationReport` into `EntryMigrationService` from `AtomicMigrationService` so successful fallbacks are visible as warnings and `fallback.*` counters.
- Added an explicit `## Fallbacks` section to migrate `REPORT.md` rendering for operator review.

## Task Commits

Each task was committed atomically:

1. **Task 1: Add generic Matrix native-title fallback before Craft save** - `8efc0b2` (feat)
2. **Task 2: Add sparse-locale primary-save fallback** - `19b1701` (feat)
3. **Task 3: Ensure fallback reporting reaches operator artifacts** - `48b6cef` (feat)

**Plan metadata:** pending final metadata commit.

## Files Created/Modified

- `src/load/EntryMigrationService.php` - Matrix title synthesis, sparse-locale primary-save fallback, fallback reporting, and actual-primary-site localized save loop.
- `src/load/AtomicMigrationService.php` - passes the active `MigrationReport` into entry saves.
- `src/console/MigrateController.php` - renders `## Fallbacks` in `REPORT.md`.
- `tests/integration/load/EntryMigrationServiceTest.php` - covers Matrix title fallback and sparse-locale primary fallback with generic fixtures.
- `tests/unit/console/MigrateControllerFailureExitTest.php` - covers fallback report rendering and confirms fallback warnings do not count as failures.
- `.planning/phases/10-generic-migration-rehearsal-gap-closure/10-02-SUMMARY.md` - execution summary.

## Decisions Made

- **Matrix title format:** synthesized titles use `Migrated {blockType} block {position}` plus source ref when available, keeping the value deterministic and generic.
- **Fallback precedence:** existing peer title wins; lifted `fields.title` / `fields.heading` is next; synthesis only happens when no usable native title remains.
- **Sparse-locale source truth:** primary fallback data is computed in-memory for the first save only. The original `perSite` array and enablement map remain source-driven.
- **Operator visibility:** fallbacks are warnings/report rows and `fallback.*` counters. They are not failures when the save succeeds.

## Verification

Commands run:

```bash
vendor/bin/phpunit tests/integration/load/EntryMigrationServiceTest.php --filter 'Matrix|Title|Fallback' --testdox
vendor/bin/phpunit tests/integration/load/EntryMigrationServiceTest.php --filter 'Sparse|Locale|Primary|Fallback' --testdox
vendor/bin/phpunit tests/integration/load/EntryMigrationServiceTest.php tests/unit/console/MigrateControllerFailureExitTest.php --testdox
php -l src/load/EntryMigrationService.php
php -l src/load/AtomicMigrationService.php
php -l src/console/MigrateController.php
```

Results:

- EntryMigrationService filtered Matrix/Title/Fallback suite: **4 tests, 24 assertions, exit 0**; PHPUnit reported the pre-existing no-code-coverage warning.
- EntryMigrationService filtered Sparse/Locale/Primary/Fallback suite: **4 tests, 24 assertions, exit 0**; PHPUnit reported the pre-existing no-code-coverage warning.
- Combined load + console reporting suite: **10 tests, 49 assertions, exit 0**; PHPUnit reported the pre-existing no-code-coverage warning and one existing deprecation.
- PHP syntax checks passed for all modified production files.
- Genericity scan found no new CQM-specific production logic; matches were pre-existing comments or earlier 10-01 test fixtures.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Localized saves now skip the actual primary site instead of array position 0**
- **Found during:** Task 2
- **Issue:** `EntryMigrationService` selected Craft's actual primary site correctly, but the subsequent localized-save loop still used `array_slice($sites, 1)`. If configured site order did not put the primary site first, the loader could skip one localized site and re-save the primary site.
- **Fix:** Iterate all resolved sites and `continue` when the site id matches the actual primary site id.
- **Files modified:** `src/load/EntryMigrationService.php`
- **Verification:** `vendor/bin/phpunit tests/integration/load/EntryMigrationServiceTest.php --filter 'Sparse|Locale|Primary|Fallback' --testdox`
- **Committed in:** `19b1701`

---

**Total deviations:** 1 auto-fixed (1 Rule 1 bug).
**Impact on plan:** No scope creep; the fix is required for sparse-locale primary-save correctness when operator site-handle order differs from Craft primary-site order.

## Issues Encountered

- `gsd-sdk` is not installed in this environment, so STATE/ROADMAP updates and final metadata commit are performed manually with normal git commands.
- PHPUnit consistently reports "No code coverage driver available"; this is an environment warning and did not fail verification commands.
- The combined console test reports one existing deprecation and intentionally prints the pre-existing report-exit messages (`Migrate: FAIL`, `Migrate: PASS`) from the reflected helper test.

## Known Stubs

None introduced. Stub-pattern scan only found existing empty-array initialization and existing report placeholder-copy comments; no new empty/mock data source prevents this plan's goal.

## Threat Flags

None. This plan did not introduce new network endpoints, auth paths, file access patterns, or schema changes at trust boundaries.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- Plan 10-03 can rely on load-side Matrix title and sparse-locale primary-save fallbacks no longer contributing to entry/stage failures when Craft saves succeed.
- Plan 10-04 can verify fallback visibility through migrate `REPORT.md` and logs using `fallback.matrix_native_title` and `fallback.sparse_locale_primary` report counters.

## Self-Check: PASSED

- Summary exists at `.planning/phases/10-generic-migration-rehearsal-gap-closure/10-02-SUMMARY.md`.
- Task commits exist: `8efc0b2`, `19b1701`, `48b6cef`.
- Verification commands listed above were run after the final task commit and exited 0.
- Modified production code remains generic and does not add CQM-specific page IDs, block handles, or class-name conditionals.

---
*Phase: 10-generic-migration-rehearsal-gap-closure*
*Completed: 2026-04-28*
