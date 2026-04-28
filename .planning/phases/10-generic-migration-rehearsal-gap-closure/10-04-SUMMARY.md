---
phase: 10-generic-migration-rehearsal-gap-closure
plan: 10-04
subsystem: verify-and-rehearsal-proof
tags: [php, craft-cms, verify, cqm, rehearsal, counts, runbook, phpunit]

requires:
  - phase: 10-generic-migration-rehearsal-gap-closure
    provides: "10-01 compile guards, 10-02 load fallback reporting, 10-03 taxonomy resolver"
provides:
  - "Domain-labeled verify count reporting"
  - "Restored-backup CQM rehearsal runbook"
  - "Restored CQM closing proof evidence with zero entry failures"
affects: [v1-release-gate, verify, compile, load, cqm-rehearsal]

tech-stack:
  added: []
  patterns:
    - "Verify compares Craft baseline/current drift separately from source/transformed parity"
    - "Migration-created state counts are informational unless a source-derived expected-count artifact exists"
    - "Compile heuristic backfill is constrained by the actual Craft target entry-type set"

key-files:
  created:
    - ".planning/phases/10-generic-migration-rehearsal-gap-closure/10-SUMMARY.md"
    - ".planning/phases/10-generic-migration-rehearsal-gap-closure/10-04-SUMMARY.md"
  modified:
    - "src/verify/CountGateService.php"
    - "src/console/VerifyController.php"
    - "src/compile/MappingCompiler.php"
    - "src/load/AtomicMigrationService.php"
    - "tests/unit/verify/CountGateServiceTest.php"
    - "tests/unit/verify/CountGateServiceFiltersTest.php"
    - "tests/unit/compile/MappingCompilerValidationTest.php"
    - ".planning/rehearsal/v1.0/cqm/README.md"

key-decisions:
  - "Pre-migration Craft baseline counts are an informational drift domain, not source migration expectations."
  - "Source/transformed parity is blocking only when source-derived expected counts are present."
  - "Restored CQM proof used the CLI's actual dry-run/default mode and documented the unsupported `--dry-run` plan command as a runbook/CLI mismatch."

patterns-established:
  - "Count-domain labels are rendered as first-class VERIFY.md sections."
  - "Restored rehearsal proof records external CQM artifact paths and failure counts without committing customer content artifacts."

requirements-completed: [PH10-06, PH10-08]

duration: 44min
completed: 2026-04-28
---

# Phase 10 Plan 10-04: Verify Semantics and Restored-Backup Rehearsal Summary

**Verify reports now label Craft drift, migration-created state counts, and source parity separately; the restored CQM workflow reached zero entry failures after fixing two proof-blocking defects.**

## Performance

- **Duration:** 44 min
- **Started:** 2026-04-28T16:19:17Z
- **Completed:** 2026-04-28T17:02:54Z
- **Tasks:** 3
- **Files modified:** 9 repo files + 2 planning summaries

## Accomplishments

- Split verify count output into explicit domains:
  - Craft baseline/current drift.
  - Migration-created state counts.
  - Source/transformed parity when a source-derived expected-count artifact exists.
- Stopped converting `baseline.json` Craft counts into migration source expectations.
- Kept existing filter translation behavior and updated verify unit coverage.
- Updated the CQM runbook with the exact restored-backup path, canonical rerun steps, Page-rooted hard gate, and zero-failure acceptance bar.
- Ran the restored CQM workflow and captured closing evidence in `10-SUMMARY.md`.

## Task Commits

Each task was committed atomically where practical:

1. **Task 1: Split verify count domains and label report output** - `0e85fb4` (feat)
2. **Task 2: Update restored-backup CQM rehearsal runbook** - `48ed7a7` (docs)
3. **Task 3 corrective: Prevent invalid heuristic entry-type backfill** - `083a7a1` (fix)
4. **Task 3 corrective: Carry migration report into atomic entry saves** - `8f0a528` (fix)
5. **Task 3 evidence: Capture restored CQM closing proof** - `41b0892` (docs)

**Plan metadata:** pending final metadata commit.

## Files Created/Modified

- `src/verify/CountGateService.php` - Adds count-domain constants, flat Craft snapshot comparison, and migration-created state count reporting.
- `src/console/VerifyController.php` - Renders domain-labeled count report sections and source-parity skips.
- `tests/unit/verify/CountGateServiceTest.php` - Covers snapshot flattening and non-blocking Craft drift comparison.
- `tests/unit/verify/CountGateServiceFiltersTest.php` - Locks verify controller source-parity/filter wiring.
- `.planning/rehearsal/v1.0/cqm/README.md` - Documents restore path, full rerun, inspection gates, and zero-failure bar.
- `src/compile/MappingCompiler.php` - Fixes restored-proof compile blocker by constraining heuristic entry-type backfill to target Craft entry types.
- `tests/unit/compile/MappingCompilerValidationTest.php` - Covers unavailable entry-type backfill regression.
- `src/load/AtomicMigrationService.php` - Fixes `MigrationReport` closure capture for fallback reporting during atomic saves.
- `.planning/phases/10-generic-migration-rehearsal-gap-closure/10-SUMMARY.md` - Captures restored CQM proof evidence and caveats.
- `.planning/phases/10-generic-migration-rehearsal-gap-closure/10-04-SUMMARY.md` - This summary.

## Verification

Commands run:

```bash
vendor/bin/phpunit tests/unit/verify/CountGateServiceTest.php tests/unit/verify/CountGateServiceFiltersTest.php --testdox
vendor/bin/phpunit tests/unit/compile/MappingCompilerValidationTest.php tests/unit/verify/CountGateServiceTest.php tests/unit/verify/CountGateServiceFiltersTest.php --testdox
vendor/bin/phpunit tests/integration/load/EntryMigrationServiceTest.php tests/unit/console/MigrateControllerFailureExitTest.php --testdox
composer test
grep -n "craft-starter-kit--2026-04-28-131310--v5.9.20.sql\|kunstmaan-migrator/compile\|kunstmaan-migrator/migrate --live\|Page-rooted\|verify" .planning/rehearsal/v1.0/cqm/README.md
cd ~/Sites/cqm-craft-website
php craft db/restore storage/backups/craft-starter-kit--2026-04-28-131310--v5.9.20.sql --interactive=0
php craft kunstmaan-migrator/doctor
php craft kunstmaan-migrator/analyze
php craft kunstmaan-migrator/map --auto-accept-high=1
php craft kunstmaan-migrator/compile --overwrite
php craft kunstmaan-migrator/migrate
php craft kunstmaan-migrator/migrate --live --confirm=1
php craft kunstmaan-migrator/verify
```

Results:

- Verify unit suite: **14 tests, 45 assertions, exit 0**; one environment warning for no coverage driver.
- Compiler + verify suite: **21 tests, 73 assertions, exit 0**; one environment warning for no coverage driver.
- Load fallback/report suite: **10 tests, 49 assertions, exit 0**; one environment warning and one existing deprecation.
- Full `composer test`: **485 tests, 1623 assertions, exit 0**; one skipped and one incomplete existing test.
- CQM restored workflow: final `REPORT.md` shows `failed = 0`, `Total failed: 0`, and `_No per-entry failures._`
- Latest verify report: `storage/migration/VERIFY-2026-04-28--17-00-48.md`.

## Rehearsal Result

Restored backup:

`~/Sites/cqm-craft-website/storage/backups/craft-starter-kit--2026-04-28-131310--v5.9.20.sql`

Final CQM evidence:

- `REPORT.md` line 12: `| failed | 0 |`
- `REPORT.md` line 22: `- Total failed: 0`
- `REPORT.md` line 1416: `_No per-entry failures._`
- Verify labels:
  - `### Craft baseline/current drift (informational)`
  - `### Migration-created state counts (informational)`
  - `### Source/transformed parity (blocking)`
- Required domains inspected: entries, page parts, relations, assets, taxonomies, SEO, redirects, and CKEditor references.

## Decisions Made

- **Verify semantics:** Craft baseline/current drift is informational. It can show drift without failing the source-parity gate.
- **Source parity:** Blocking parity requires source-derived expected counts; absent source expected counts render an explicit `source-parity:unavailable` SKIP row.
- **CQM automation:** Used `map --auto-accept-high=1`, `compile --overwrite`, and default `migrate` dry-run because those are the actual non-interactive CLI shapes.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Compile heuristic backfill no longer invents unavailable target entry types**
- **Found during:** Task 3 (restored CQM compile)
- **Issue:** Existing accepted column rows with empty `targetEntryType` were backfilled from source page-class basenames even when those entry types did not exist in Craft, causing fatal sections such as `vacancyFormPage`.
- **Fix:** Constrained heuristic backfill to the actual Craft target entry-type handle set when available.
- **Files modified:** `src/compile/MappingCompiler.php`, `tests/unit/compile/MappingCompilerValidationTest.php`
- **Verification:** `vendor/bin/phpunit tests/unit/compile/MappingCompilerValidationTest.php ... --testdox`; restored CQM compile passed after invalid external mapping proposals were classified.
- **Committed in:** `083a7a1`

**2. [Rule 1 - Bug] Captured MigrationReport inside AtomicMigrationService transaction closure**
- **Found during:** Task 3 (restored CQM live migrate)
- **Issue:** Plan 10-02 fallback reporting passed `$report` into `EntryMigrationService`, but the transaction closure did not capture `$report`, producing `Undefined variable $report` for every entry.
- **Fix:** Added `$report` to the closure `use (...)` list.
- **Files modified:** `src/load/AtomicMigrationService.php`
- **Verification:** `vendor/bin/phpunit tests/integration/load/EntryMigrationServiceTest.php tests/unit/console/MigrateControllerFailureExitTest.php --testdox`; restored CQM live run reached zero failures.
- **Committed in:** `8f0a528`

**3. [Rule 3 - Blocking] Used actual non-interactive CQM CLI forms**
- **Found during:** Task 3 (restored CQM workflow)
- **Issue:** The planned `map` command blocks for interactive operator input; `compile` fails without `--overwrite` when compiled blocks already exist; `migrate --dry-run` is not a supported option because dry-run is the default.
- **Fix:** Used `map --auto-accept-high=1`, `compile --overwrite`, and `migrate` for the dry-run step.
- **Files modified:** `.planning/phases/10-generic-migration-rehearsal-gap-closure/10-SUMMARY.md`
- **Verification:** Restored CQM workflow completed with exit 0.
- **Committed in:** `41b0892`

---

**Total deviations:** 3 auto-fixed (2 Rule 3 blocking, 1 Rule 1 bug).
**Impact on plan:** All fixes were necessary to execute the restored-backup proof and preserve domain correctness. No production CQM-specific code paths were introduced.

## Issues Encountered

- `gsd-sdk` is not installed in this environment, so planning state updates and final metadata commit are performed manually with normal git commands.
- CQM `PAGE-ROOTED-COVERAGE.md` still contains visible `warning` / `unsupported` classifications. The restored workflow reached zero entry/stage failures, but strict release tagging should require release-owner acceptance or follow-up classification of those rows.
- PHPUnit reports the existing "No code coverage driver available" warning.

## Known Stubs

None introduced. Stub-pattern review found intentional empty arrays / nullable dependency injection defaults only; no UI or data-source stubs were added.

## Threat Flags

None. No new network endpoints, auth paths, file access surfaces, schema changes, or trust-boundary behavior were introduced.

## User Setup Required

None for repository execution. Release-owner review is still recommended for visible CQM coverage `warning` / `unsupported` classifications before tagging v1.0.

## Next Phase Readiness

- Phase 10 executable plans are complete.
- The restored CQM proof reaches zero entry failures and zero stage failures.
- Verify count-domain semantics are ready for release review.
- Remaining release decision: accept or further classify visible Page-rooted coverage warnings/unsupported rows.

## Self-Check: PASSED

- Summary exists at `.planning/phases/10-generic-migration-rehearsal-gap-closure/10-04-SUMMARY.md`.
- Phase proof exists at `.planning/phases/10-generic-migration-rehearsal-gap-closure/10-SUMMARY.md`.
- Task commits exist: `0e85fb4`, `48ed7a7`, `083a7a1`, `8f0a528`, `41b0892`.
- Restored CQM `REPORT.md` evidence recorded zero failures.

---
*Phase: 10-generic-migration-rehearsal-gap-closure*
*Completed: 2026-04-28*

