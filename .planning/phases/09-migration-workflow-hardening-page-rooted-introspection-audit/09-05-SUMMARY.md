---
phase: 09-migration-workflow-hardening-page-rooted-introspection-audit
plan: 05
subsystem: migration-runtime-truthfulness-and-asset-preload
tags: [php, phpunit, migrate, reporting, assets, tdd]

requires:
  - phase: 09-migration-workflow-hardening-page-rooted-introspection-audit
    plan: 01
    provides: "Compile preflight and load continuation baseline"
  - phase: 09-migration-workflow-hardening-page-rooted-introspection-audit
    plan: 04
    provides: "Page-rooted payload and coverage posture"
provides:
  - "Central MigrationReport failure-state query"
  - "Truthful non-zero migrate/load exit after per-entry continuation"
  - "Referenced-only --preload-assets path from in-scope transformed payloads"
affects: [migrate, load, asset-preload, operator-reporting]

tech-stack:
  added: []
  patterns:
    - "TDD red/green commits for runtime behavior regressions"
    - "Pure private helper seams exercised via reflection for non-Craft unit tests"
    - "Payload token collector feeds explicit asset preload IDs"

key-files:
  created:
    - tests/unit/console/MigrateControllerFailureExitTest.php
    - tests/unit/load/AssetMigrationServiceReferencedOnlyPreloadTest.php
  modified:
    - src/load/MigrationReport.php
    - src/console/MigrateController.php
    - src/load/AssetMigrationService.php

key-decisions:
  - "Use MigrationReport::hasFailures() / failureCount() as the single final-outcome truth."
  - "Keep runLoadFromDisk() diagnostic continuation intact; only translate the completed report into a final non-zero exit."
  - "Make --preload-assets consume explicit in-scope payload references instead of scanning kuma_media for candidate IDs."

requirements-completed: [PH9-14, PH9-15]

metrics:
  duration: "00:06:17"
  completed_at: "2026-04-28T12:02:23Z"
  tasks_completed: 3
  files_changed: 5
---

# Phase 9 Plan 05: Migration Runtime Truthfulness and Asset Preload Summary

Partial load failures now remain diagnostic and continuing, but produce a truthful non-zero final migrate outcome; asset preloading now only ingests media IDs referenced by the current in-scope transformed payloads.

## Tasks Completed

| Task | Name | Status | Commit |
|---|---|---:|---|
| 1 RED | Failure-exit regression coverage | Complete | `6be5ddc` |
| 1 GREEN | Report failure-state query and final exit gate | Complete | `0b3ef6e` |
| 2 RED | Referenced-only preload regression coverage | Complete | `2d124db` |
| 2 GREEN | Payload-referenced preload implementation | Complete | `ced731e` |
| 3 | Runtime verification suite | Complete | `9060dd0` |
| Follow-up | Referenced preload PHPDoc cleanup | Complete | `7e3a6c2` |

## Implementation Notes

- Added `MigrationReport::hasFailures()` and `MigrationReport::failureCount()` so final command status no longer re-implements failure checks at controller call sites.
- Updated `MigrateController::actionIndex()` and `MigrateController::actionLoad()` to write `REPORT.md` first, then return `ExitCode::UNSPECIFIED_ERROR` when the completed report contains failure rows or a non-zero `failed` count.
- Preserved `runLoadFromDisk()` continuation: per-entry exceptions are still recorded and the loop continues for diagnostics.
- Added a report-exit helper that prints `Migrate: FAIL (<n> failures)` for partial failures and `Migrate: PASS` for clean reports.
- Changed `AssetMigrationService::ingestReferenced()` to accept an explicit `list<int>` of referenced media IDs and removed the full-table `SELECT id FROM kuma_media` prewalk.
- Added a controller-side collector for in-scope transformed payloads that discovers:
  - deferred asset tokens such as `asset:42`;
  - CKEditor/media tokens such as `[M99]`;
  - explicit payload ID lists such as `referencedMediaIds` / `referencedAssetIds`.
- `--entities` and `--since` scope are honored by using the current transformed payload set as the preload source of truth rather than walking all legacy media rows.

## Files Created/Modified

- `src/load/MigrationReport.php` — added central failure-state and failure-count helpers.
- `src/console/MigrateController.php` — added final report exit gate plus transformed-payload referenced asset ID collection and preload wiring.
- `src/load/AssetMigrationService.php` — removed full media ID prewalk from `ingestReferenced()` and normalized explicit referenced ID input.
- `tests/unit/console/MigrateControllerFailureExitTest.php` — new TDD regression for report failure-state and controller final exit semantics.
- `tests/unit/load/AssetMigrationServiceReferencedOnlyPreloadTest.php` — new TDD regression proving preload avoids `SELECT id FROM kuma_media` and only batches referenced IDs.

## Decisions Made

- `runLoadFromDisk()` continues to return `ExitCode::OK` after recording per-entry failures; this preserves continuation semantics and leaves final truthfulness to the post-report gate.
- `failureCount()` uses the maximum of `count(failures)` and `counts['failed']` so merged stage reports cannot under-report failures.
- `ingestReferenced()` keeps backward-compatible default behavior for direct callers by accepting an omitted ID list as "nothing known to preload" rather than falling back to a full legacy media walk.

## Deviations from Plan

### Auto-fixed Issues

None - plan executed as written. A small PHPDoc formatting cleanup was committed after implementation review; it did not change runtime behavior.

## Auth Gates

None.

## Known Stubs

None introduced by this plan. Stub-pattern scan found only existing empty-array/null guard patterns and pre-existing placeholder wording in comments outside the new behavior.

## Threat Flags

None. The modified trust-boundary surfaces are covered by the plan threat model:

- `MigrateController` final outcome mitigation for T-09-05-01.
- Referenced-only asset preload mitigation for T-09-05-02 and T-09-05-03.

## Verification

| Command | Result |
|---|---|
| `vendor/bin/phpunit tests/unit/console/MigrateControllerFailureExitTest.php --testdox` | Passed: 4 tests / 12 assertions; PHPUnit warning: no code coverage driver; 1 deprecation |
| `vendor/bin/phpunit tests/unit/load/AssetMigrationServiceReferencedOnlyPreloadTest.php --testdox` | Passed: 4 tests / 8 assertions; PHPUnit warning: no code coverage driver; 1 deprecation |
| `vendor/bin/phpunit tests/unit/console/MigrateControllerFailureExitTest.php tests/unit/load/AssetMigrationServiceReferencedOnlyPreloadTest.php tests/unit/load/AssetMigrationServiceRcaTest.php --testdox` | Passed: 12 tests / 30 assertions; PHPUnit warning: no code coverage driver; 1 deprecation |
| `vendor/bin/phpunit tests/unit/console/MigrateControllerFailureExitTest.php tests/unit/load/AssetMigrationServiceReferencedOnlyPreloadTest.php --testdox` | Passed: 8 tests / 20 assertions; PHPUnit warning: no code coverage driver; 1 deprecation |
| `composer test-unit` | Passed: 441 tests / 1402 assertions; PHPUnit warning: no code coverage driver; 1 deprecation |
| `php -l` changed production and test files | Passed: no syntax errors |

## Issues Encountered

- RED tests failed as expected before implementation for both TDD tasks.
- PHPUnit reports the repository's standard "No code coverage driver available" warning in this environment.
- PHPUnit reports 1 deprecation while passing; this is consistent with prior Phase 9 runs and unrelated to this plan.

## User Setup Required

None. No credentials, external services, or manual verification steps are required.

## Next Phase Readiness

Plan 09-02C and Plan 09-07 can rely on truthful migrate process status and on `--preload-assets` matching the page-driven referenced-assets-only contract.

## Self-Check: PASSED

- Found created/modified files:
  - `src/load/MigrationReport.php`
  - `src/console/MigrateController.php`
  - `src/load/AssetMigrationService.php`
  - `tests/unit/console/MigrateControllerFailureExitTest.php`
  - `tests/unit/load/AssetMigrationServiceReferencedOnlyPreloadTest.php`
  - `.planning/phases/09-migration-workflow-hardening-page-rooted-introspection-audit/09-05-SUMMARY.md`
- Found task commits: `6be5ddc`, `0b3ef6e`, `2d124db`, `ced731e`, `9060dd0`, `7e3a6c2`.
