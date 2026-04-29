---
phase: 12-cp-migration-console-queue-workflow
plan: 01
subsystem: database
tags: [craft-cms, yii-active-record, queue, migration-runs, phpunit]

# Dependency graph
requires:
  - phase: 01-foundation-connectivity
    provides: Craft migration style, idempotent install migration, PHPUnit baseline
  - phase: 03-etl-pipeline-field-handlers
    provides: existing migration state service/repository patterns
provides:
  - durable `{{%kunstmaanmigrator_runs}}` schema for CP and queued migration status
  - `MigrationRunRecord` ActiveRecord for run rows
  - `MigrationRunService` lifecycle, progress, failure, queue job id, and artifact APIs
  - source-level contract tests for run table fields, lifecycle methods, statuses, and file-backed artifacts
affects: [cp-console, queue-workflow, migration-observability, artifact-reporting]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - Craft `Migration` idempotent table creation with no-op `safeDown()` for audit data preservation
    - Service/repository wrapper around ActiveRecord run mutation logic
    - PHPUnit source-level contract tests for DB-dependent Craft surfaces

key-files:
  created:
    - src/migrations/m260429_000001_create_migration_runs.php
    - src/records/MigrationRunRecord.php
    - src/runs/MigrationRunService.php
    - tests/unit/runs/MigrationRunServiceTest.php
  modified: []

key-decisions:
  - "Preserve migration run records on rollback/uninstall; operators must reset run history explicitly."
  - "Store the first/current queue job id in `queueJobId` and all chained/batched ids in `queueJobIds`."
  - "Default run logs and accepted artifact paths to `storage/migration` for file-backed operator auditability."

patterns-established:
  - "Run lifecycle mutations live in `MigrationRunService`, keeping CP controllers and queue jobs as adapters."
  - "Run-record tests use source assertions/reflection to avoid requiring a full Craft DB bootstrap."

requirements-completed: []

# Metrics
duration: 3min
completed: 2026-04-29
---

# Phase 12 Plan 01: Run records and file-backed artifacts Summary

**Durable Craft migration run records with lifecycle status, queue job tracking, progress, logs, artifacts, summaries, and failure storage**

## Performance

- **Duration:** 3 min
- **Started:** 2026-04-29T07:44:19Z
- **Completed:** 2026-04-29T07:47:11Z
- **Tasks:** 3
- **Files modified:** 4 implementation/test files plus this summary

## Accomplishments

- Added an idempotent Craft migration for `{{%kunstmaanmigrator_runs}}` with stage/mode/status, filters/options, gate snapshots, initiating admin id, queue job ids, progress, log/artifact paths, summaries, failure details, and timestamps.
- Added `MigrationRunRecord` as the ActiveRecord seam for CP/queue code to read and write durable run rows.
- Added `MigrationRunService` with creation, queued/running/succeeded/failed transitions, progress updates, latest/find/list queries, plural queue job id tracking, and artifact appending.
- Added PHPUnit source-level contract tests for schema fields, table name, lifecycle methods, status strings, `queueJobIds`, `logPath`, `artifactPaths`, `gateSnapshot`, and `storage/migration` artifact rooting.

## Task Commits

Each task was committed atomically:

1. **Task 1: Create the migration run schema and record**
   - `13dc3b2` test(12-01): add failing run record contract tests
   - `6edd293` feat(12-01): add migration run schema and record
2. **Task 2: Create MigrationRunService lifecycle APIs**
   - `4e53e9c` test(12-01): add failing run service lifecycle tests
   - `84142f8` feat(12-01): implement migration run lifecycle service
3. **Task 3: Add lifecycle and artifact contract tests**
   - `9c9ab82` test(12-01): add run artifact contract coverage

**Plan metadata:** committed separately after state/roadmap updates.

_Note: TDD tasks used RED → GREEN commits as required._

## Files Created/Modified

- `src/migrations/m260429_000001_create_migration_runs.php` - Creates the durable `kunstmaanmigrator_runs` table, indexes status/stage-mode/queue job/date columns, and preserves run rows in `safeDown()`.
- `src/records/MigrationRunRecord.php` - ActiveRecord class returning `{{%kunstmaanmigrator_runs}}`.
- `src/runs/MigrationRunService.php` - Service/repository API for run creation, lifecycle transitions, progress, summaries, failures, queue job ids, latest/find/list reads, and artifact registration.
- `tests/unit/runs/MigrationRunServiceTest.php` - Source-level PHPUnit contract tests covering schema, record, lifecycle, statuses, JSON fields, artifact/log fields, and `storage/migration` rooting.

## Decisions Made

- Preserve run records on rollback/uninstall, matching the threat-model mitigation that run history must not become destructive uninstall collateral.
- Keep behavior out of the ActiveRecord and in `MigrationRunService`, following the existing `MigrationStateService` service/repository pattern.
- Treat `queueJobId` as the first/current job id and `queueJobIds` as the durable list of staged/chained/batched job ids.
- Root run log paths and artifact paths under `storage/migration` so operators can inspect artifacts outside the CP.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing Critical Functionality] Guarded artifact paths under `storage/migration`**
- **Found during:** Task 3 (Add lifecycle and artifact contract tests)
- **Issue:** The initial lifecycle service could append arbitrary artifact paths, but the phase truth requires run logs and artifacts to remain inspectable under `storage/migration`.
- **Fix:** Added `ARTIFACT_ROOT = 'storage/migration'`, defaulted new run `logPath` under that root, and validated `appendArtifact()` paths before persisting them.
- **Files modified:** `src/runs/MigrationRunService.php`, `tests/unit/runs/MigrationRunServiceTest.php`
- **Verification:** `vendor/bin/phpunit tests/unit/runs/MigrationRunServiceTest.php --testdox`; acceptance grep count for `storage/migration|artifactPaths|logPath|gateSnapshot|queueJobIds` returned 10.
- **Committed in:** `9c9ab82`

---

**Total deviations:** 1 auto-fixed (1 Rule 2 missing critical functionality)  
**Impact on plan:** Strengthened the planned artifact contract without adding new architecture or scope.

## Issues Encountered

- `gsd-sdk` was unavailable in this shell (`command not found`), so state/roadmap metadata updates were performed manually instead of through SDK query handlers.
- PHPUnit reported `No code coverage driver available`; this is a warning only and did not affect the targeted test pass.

## User Setup Required

None - no external service configuration required.

## Verification

- `php -l src/migrations/m260429_000001_create_migration_runs.php`
- `php -l src/records/MigrationRunRecord.php`
- `php -l src/runs/MigrationRunService.php`
- `php -l tests/unit/runs/MigrationRunServiceTest.php`
- `vendor/bin/phpunit tests/unit/runs/MigrationRunServiceTest.php --testdox`
- Plan acceptance greps:
  - `kunstmaanmigrator_runs` count: 2
  - `gateSnapshot|artifactPaths|queueJobId|queueJobIds|dateFinished` count: 11
  - `function safeDown` count: 1
  - lifecycle method count: 8
  - lifecycle status count: 14
  - test artifact/log field count: 10

## Known Stubs

None.

## Next Phase Readiness

- CP controllers and queue jobs can now create durable run records before dispatching work, update progress and status during execution, attach file-backed artifacts, and display latest/list/detail run views.
- Later Phase 12 plans should wire this service into plugin components and use the service rather than writing `MigrationRunRecord` rows directly.

## Self-Check: PASSED

- Found created files:
  - `src/migrations/m260429_000001_create_migration_runs.php`
  - `src/records/MigrationRunRecord.php`
  - `src/runs/MigrationRunService.php`
  - `tests/unit/runs/MigrationRunServiceTest.php`
  - `.planning/phases/12-cp-migration-console-queue-workflow/12-01-SUMMARY.md`
- Found task commits: `13dc3b2`, `6edd293`, `4e53e9c`, `84142f8`, `9c9ab82`

---
*Phase: 12-cp-migration-console-queue-workflow*
*Completed: 2026-04-29*
