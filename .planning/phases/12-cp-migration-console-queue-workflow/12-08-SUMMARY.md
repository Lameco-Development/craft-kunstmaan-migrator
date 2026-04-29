---
phase: 12-cp-migration-console-queue-workflow
plan: 08
subsystem: queue-workflow
tags: [craft-cms, queue, basejob, migration-runs, workflow-services, phpunit]

# Dependency graph
requires:
  - phase: 12-cp-migration-console-queue-workflow
    provides: durable run records, shared workflow services, production/job safety, live gates, and plugin service wiring from Plans 12-01 through 12-07
provides:
  - serialization-safe `MigrationStageJob` for analyze, compile, and verify stage execution
  - staged `MigrationPipelineJob` for dry-run/live migration batches with queue job chaining
  - queue boundary tests proving scalar/array payloads, no shell-outs, job production re-checks, and workflow-service dispatch
affects: [cp-console, queue-workflow, migration-safety, migration-observability]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - Craft `BaseJob` queue jobs carry only scalar/array public payload properties.
    - Queue jobs re-check `migrationSafety->assertNotProductionForJob()` inside `execute()` before workflow dispatch.
    - Queue jobs remain thin adapters over plugin workflow services and `MigrationRunService`.

key-files:
  created:
    - src/queue/jobs/MigrationStageJob.php
    - src/queue/jobs/MigrationPipelineJob.php
    - tests/unit/queue/MigrationStageJobTest.php
    - tests/unit/queue/MigrationPipelineJobTest.php
  modified: []

key-decisions:
  - "Keep queue jobs as thin Craft BaseJob adapters over shared workflow services; no shell-outs and no duplicated controller orchestration."
  - "Represent dry-run/live continuation with `batchOffset`, `batchLimit`, `nextBatchOffset`, and plural run queue job IDs via `appendQueueJobId()`."
  - "Treat live `gateSnapshot` entries with `blocked` or `unknown` status as hard job-level blockers even after controller-side gates pass."

patterns-established:
  - "Stage jobs dispatch `analyze`, `compile`, and `verify` through plugin workflow components and persist workflow artifact/failure data to run records."
  - "Pipeline jobs process one workflow batch per execution and only mark a run succeeded after no `nextBatchOffset` remains."
  - "Queue contract tests use source-level assertions to lock serialization safety, no shell-outs, and safety ordering without bootstrapping a Craft worker."

requirements-completed: []

# Metrics
duration: 4min
completed: 2026-04-29
---

# Phase 12 Plan 08: Queue jobs Summary

**Serialization-safe Craft queue jobs for safe stages and staged dry-run/live migration batches backed by shared workflows and durable run records**

## Performance

- **Duration:** 4 min
- **Started:** 2026-04-29T10:49:16Z
- **Completed:** 2026-04-29T10:53:35Z
- **Tasks:** 3
- **Files modified:** 4 implementation/test files plus this summary and planning metadata

## Accomplishments

- Added `MigrationStageJob extends BaseJob` with scalar/array payload properties for `runId`, `stage`, `mode`, `filters`, and `options`.
- Implemented stage execution for `analyze`, `compile`, and `verify` through `Plugin::getInstance()` workflow services, with `assertNotProductionForJob()` before dispatch, Craft queue progress, and `MigrationRunService` running/succeeded/failed updates.
- Added `MigrationPipelineJob extends BaseJob` for `dryRun` and `live` migration modes with `gateSnapshot`, `batchOffset`, `batchLimit`, `nextBatchOffset`, and queued next-batch chaining.
- Enforced live job gate snapshots so `blocked` or `unknown` gate statuses prevent processing even if a job was enqueued earlier.
- Added queue contract tests proving serialization-safe payload properties, no shell-outs, production re-check ordering, workflow service use, and staged batch queue chaining with `appendQueueJobId()`.

## Task Commits

Each task was committed atomically:

1. **Task 1: Create serialization-safe stage job**
   - `3609441` test(12-08): add failing stage job tests
   - `06d6ea6` feat(12-08): create serialization-safe stage job
2. **Task 2: Create staged dry-run and live pipeline job**
   - `751eba3` test(12-08): add failing pipeline job tests
   - `ca95e2f` feat(12-08): create staged migration pipeline job
3. **Task 3: Prove no inline CP execution contract at job boundary**
   - `c6eef26` test(12-08): prove queue job boundary contracts

**Plan metadata:** committed separately after state/roadmap updates.

_Note: Tasks 1 and 2 used the required RED → GREEN TDD commits._

## Files Created/Modified

- `src/queue/jobs/MigrationStageJob.php` - Safe-stage Craft queue job for analyze/compile/verify workflow dispatch with production re-checks, progress updates, run lifecycle updates, artifacts, summaries, and failures.
- `src/queue/jobs/MigrationPipelineJob.php` - Staged dry-run/live migration queue job with live gate snapshot enforcement, batch offsets/limits, `nextBatchOffset` chaining, and plural queue job ID tracking.
- `tests/unit/queue/MigrationStageJobTest.php` - Source-level tests for stage job payload serialization, safety ordering, workflow-service dispatch, no shell-outs, progress, artifacts, and run status updates.
- `tests/unit/queue/MigrationPipelineJobTest.php` - Source-level tests for pipeline payload serialization, live gate blocking, no shell-outs, production re-check ordering, and staged queue chaining.

## Decisions Made

- Kept the jobs as adapter shells and reused the shared workflow services (`analyzeWorkflow`, `compileWorkflow`, `verifyWorkflow`, `migrateWorkflow`) instead of invoking console commands or duplicating controller logic.
- Stored only public scalar/array payload fields on jobs; all runtime services are resolved inside `execute()` through the plugin component graph.
- Used `gateSnapshot` as an immutable queue-time live safety record and re-evaluated it inside the live pipeline job for `blocked`/`unknown` statuses.
- Chained batches by pushing a new `MigrationPipelineJob` from the current queue execution and recording the returned ID through `MigrationRunService::appendQueueJobId()`.

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered

- `gsd-sdk` was unavailable in this shell (`command not found`), so state and roadmap metadata updates were performed manually instead of through SDK query handlers.
- PHPUnit reported `No code coverage driver available`; this is a warning only and did not affect the targeted test pass.

## User Setup Required

None - no external service configuration required.

## Verification

- `php -l src/queue/jobs/MigrationStageJob.php`
- `php -l src/queue/jobs/MigrationPipelineJob.php`
- `php -l tests/unit/queue/MigrationStageJobTest.php`
- `php -l tests/unit/queue/MigrationPipelineJobTest.php`
- `vendor/bin/phpunit tests/unit/queue/MigrationStageJobTest.php --testdox`
- `vendor/bin/phpunit tests/unit/queue/MigrationPipelineJobTest.php --testdox`
- `vendor/bin/phpunit tests/unit/queue/MigrationStageJobTest.php tests/unit/queue/MigrationPipelineJobTest.php --testdox`
- Plan acceptance greps:
  - Stage job required symbols: 18 matches (`>= 8` required)
  - Pipeline job required symbols: 52 matches (`>= 11` required)

## Known Stubs

None. Empty array defaults on job payload properties (`filters`, `options`, `gateSnapshot`) are intentional serialization-safe defaults, not UI stubs or mock data.

## Threat Flags

None - the new queue execution surfaces are the surfaces explicitly covered by this plan's threat model. Jobs re-check production inside `execute()`, do not shell out, and carry scalar/array payloads only.

## TDD Gate Compliance

- Task 1 RED gate commit present: `3609441`; GREEN gate commit present after RED: `06d6ea6`
- Task 2 RED gate commit present: `751eba3`; GREEN gate commit present after RED: `ca95e2f`
- Task 3 was not marked `tdd="true"` and was committed as a test-only contract hardening task.
- Refactor gate: not needed

## Next Phase Readiness

- CP controllers can enqueue stage and pipeline jobs without running long operations inline in CP requests.
- Plan 12-09 can wire controller dispatch to these jobs and use `MigrationRunService` queue job IDs, progress, summaries, failures, and artifacts for operator feedback.
- The queue boundary now preserves Phase 12 safety invariants: non-production re-check in workers, strict live gate snapshots, no `php craft` shell-outs, and batched live/dry-run execution.

## Self-Check: PASSED

- Found created files:
  - `src/queue/jobs/MigrationStageJob.php`
  - `src/queue/jobs/MigrationPipelineJob.php`
  - `tests/unit/queue/MigrationStageJobTest.php`
  - `tests/unit/queue/MigrationPipelineJobTest.php`
  - `.planning/phases/12-cp-migration-console-queue-workflow/12-08-SUMMARY.md`
- Found task commits: `3609441`, `06d6ea6`, `751eba3`, `ca95e2f`, `c6eef26`

---
*Phase: 12-cp-migration-console-queue-workflow*
*Completed: 2026-04-29*
