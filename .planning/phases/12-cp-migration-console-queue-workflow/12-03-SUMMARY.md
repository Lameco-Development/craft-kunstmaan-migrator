---
phase: 12-cp-migration-console-queue-workflow
plan: 03
subsystem: workflow
tags: [craft-cms, yii-console, workflow-services, queue-ready, phpunit, migration]

# Dependency graph
requires:
  - phase: 12-cp-migration-console-queue-workflow
    provides: durable run records from Plan 12-01 and analyze/compile workflow extraction from Plan 12-02
  - phase: 03-etl-pipeline-field-handlers
    provides: migrate controller orchestration, MigrationOptions, MigrationReport, atomic load behavior
  - phase: 04-adapters-verify-settings
    provides: verify controller orchestration, CountGateService, BaselineCounterService, VERIFY reports
provides:
  - shared `MigrateWorkflow::run()` service for CLI, future CP controllers, and queue jobs
  - shared `VerifyWorkflow::run()` service for verify/report and baseline-capture modes
  - CLI controllers reduced to adapters over shared workflows for migrate and verify entrypoints
  - planned queue batch result fields `batchOffset`, `batchLimit`, and `nextBatchOffset`
affects: [cp-console, queue-workflow, migration-orchestration, artifact-reporting, verification]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - Console controllers perform production gating and stream workflow progress while workflow services hold orchestration.
    - Workflow services expose structured result arrays with status, artifact paths, summary/failure metadata, and queue-ready batch markers.
    - Source-level PHPUnit tests guard delegation, result shape, shell-out bans, and artifact/log contracts.

key-files:
  created:
    - src/workflow/MigrateWorkflow.php
    - src/workflow/VerifyWorkflow.php
    - tests/unit/workflow/MigrateWorkflowTest.php
    - tests/unit/workflow/VerifyWorkflowTest.php
  modified:
    - src/console/MigrateController.php
    - src/console/VerifyController.php

key-decisions:
  - "Keep CLI `NeverProductionTrait` gates in controllers and workflows so CLI, CP, and queued jobs independently retain non-production safety."
  - "Expose migrate queue batch metadata through `batchOffset`, `batchLimit`, and `nextBatchOffset` without adding shell-outs or changing CLI full-run defaults."
  - "Route verify capture modes through `VerifyWorkflow` as explicit options so queue jobs can reuse report/baseline orchestration without invoking console commands."

patterns-established:
  - "CLI action adapters pass option arrays into workflow `run()` methods and stream `stdout`/`stderr` progress callbacks back to operators."
  - "Migrate workflow accepts exactly `live`, `confirm`, `preloadAssets`, `force`, `entities`, `locales`, `since`, `noSeo`, `noRetour`, `noRelJoin`, `includeUnreferencedTaxonomies`, `limit`, `onlyId`, `verbose`, `batchOffset`, and `batchLimit`."
  - "Verify workflow accepts exactly `baseline`, `urlSpotCheck`, `baselineDir`, `countTolerance`, `urlDiffThreshold`, `entities`, `locales`, `since`, `captureBaseline`, `captureBaselineHtml`, `output`, and `outputDir`."

requirements-completed: []

# Metrics
duration: 5min
completed: 2026-04-29
---

# Phase 12 Plan 03: Migrate and Verify workflow extraction Summary

**Reusable migrate and verify/report workflows with CLI-canonical dry-run/live safety, file artifacts, filters, and queue-ready batch metadata**

## Performance

- **Duration:** 5 min
- **Started:** 2026-04-29T10:09:51Z
- **Completed:** 2026-04-29T10:14:36Z
- **Tasks:** 2
- **Files modified:** 6 implementation/test files plus this summary and planning metadata

## Accomplishments

- Added `MigrateWorkflow::run(array $options, ?callable $progress = null): array` with structured `status`, `mode`, `filters`, `artifactPaths`, `logPath`, `summary`, `failure`, `processed`, `total`, and `nextBatchOffset` result data.
- Updated `MigrateController::actionIndex()` to remain the CLI safety adapter: it applies the production gate, passes CLI flags into `MigrateWorkflow`, and streams workflow progress to stdout/stderr.
- Added queue-batched migration metadata support through `batchOffset`, `batchLimit`, and `nextBatchOffset` while preserving null/default full-run CLI behavior.
- Added `VerifyWorkflow::run(array $options, ?callable $progress = null): array` with structured `status`, `artifactPaths`, `summary`, and `failure` output for verify/report, baseline capture, and baseline HTML capture modes.
- Updated `VerifyController` verify and capture actions to delegate through `VerifyWorkflow`, preserving `VERIFY-<timestamp>.md`, `baseline.json`, baseline HTML, filter, count tolerance, and URL diff threshold behavior.
- Added PHPUnit source-level workflow contract tests covering option/result shape, controller delegation, shell-out bans, safety/artifact contracts, and capture-mode support.

## Task Commits

Each task was committed atomically:

1. **Task 1: Extract migrate orchestration into MigrateWorkflow**
   - `0cdf39b` test(12-03): add failing migrate workflow tests
   - `1df0082` feat(12-03): extract migrate workflow service
2. **Task 2: Extract verify/report orchestration into VerifyWorkflow**
   - `4e6eca1` test(12-03): add failing verify workflow tests
   - `d661143` feat(12-03): extract verify workflow service
   - `fda5468` fix(12-03): delegate verify capture actions

**Plan metadata:** committed separately after state/roadmap updates.

_Note: TDD tasks used RED → GREEN commits as required._

## Files Created/Modified

- `src/workflow/MigrateWorkflow.php` - Shared migrate workflow service preserving dry-run/live migration orchestration, report/log writing, compiled mapping preflight, progress callbacks, and queue batch result metadata.
- `src/workflow/VerifyWorkflow.php` - Shared verify/report workflow service preserving count gates, URL spot-checks, baseline capture, baseline HTML capture, `VERIFY-<timestamp>.md` artifacts, and structured result metadata.
- `src/console/MigrateController.php` - CLI adapter that keeps the production gate and delegates the default migrate action to `MigrateWorkflow`.
- `src/console/VerifyController.php` - CLI adapter that keeps production gates and delegates verify/report and capture actions to `VerifyWorkflow`.
- `tests/unit/workflow/MigrateWorkflowTest.php` - Source-level contract tests for migrate workflow options/results, CLI delegation, shell-out ban, safety, logs, reports, and batch metadata.
- `tests/unit/workflow/VerifyWorkflowTest.php` - Source-level contract tests for verify workflow options/results, CLI delegation, shell-out ban, capture modes, and VERIFY artifact behavior.

## Decisions Made

- Kept the CLI production gate as the first operation in the controller adapter, while retaining workflow-level production safety for future CP/queue callers.
- Added migrate batch slicing/result metadata at the workflow load loop boundary so queued staged execution can process stable transformed payload batches without changing the existing full-run CLI default.
- Modeled verify baseline capture and baseline HTML capture as explicit workflow options rather than separate shell commands, keeping future queue jobs deterministic and runtime-zero-AI.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing Critical Functionality] Delegated verify capture actions through VerifyWorkflow**
- **Found during:** Task 2 (Extract verify/report orchestration into VerifyWorkflow)
- **Issue:** The initial GREEN implementation delegated `verify/index` but left `capture-baseline` and `capture-baseline-html` as controller-local orchestration, even though the workflow contract includes `captureBaseline` and `captureBaselineHtml` options for queue-safe reuse.
- **Fix:** Updated both capture actions to call `VerifyWorkflow::run()` with the corresponding capture-mode flags while preserving controller production gating and progress streaming.
- **Files modified:** `src/console/VerifyController.php`
- **Verification:** `php -l src/console/VerifyController.php`; `vendor/bin/phpunit tests/unit/workflow/VerifyWorkflowTest.php --testdox`; final plan verification suite.
- **Committed in:** `fda5468`

---

**Total deviations:** 1 auto-fixed (1 Rule 2 missing critical functionality)  
**Impact on plan:** Strengthened the planned workflow extraction by ensuring all supported verify workflow modes are reachable through the shared service. No architecture change or feature scope expansion.

## Issues Encountered

- `gsd-sdk` was unavailable in this shell (`command not found`), so state and roadmap metadata updates were performed manually instead of through SDK query handlers.
- PHPUnit reported `No code coverage driver available`; this is a warning only and did not affect the targeted test pass.

## User Setup Required

None - no external service configuration required.

## Verification

- `vendor/bin/phpunit tests/unit/workflow/MigrateWorkflowTest.php --testdox`
- `vendor/bin/phpunit tests/unit/workflow/VerifyWorkflowTest.php --testdox`
- `php -l src/workflow/MigrateWorkflow.php`
- `php -l src/workflow/VerifyWorkflow.php`
- `php -l src/console/MigrateController.php`
- `php -l src/console/VerifyController.php`
- Plan acceptance greps:
  - Migrate workflow contract grep count: 125
  - Migrate controller delegation grep count: 2
  - Migrate workflow shell-out grep count: 0
  - Verify workflow contract grep count: 24
  - Verify controller delegation grep count: 4
  - Verify workflow shell-out grep count: 0

## Known Stubs

None.

## Threat Flags

None - the new workflow services expose existing file-artifact and CLI orchestration surfaces for CP/queue reuse but do not add new network endpoints, auth paths, schema changes, or new trust-boundary file access patterns.

## Next Phase Readiness

- CP controllers and queue jobs can now call migrate and verify workflows directly, persist artifact paths into run records, and stream workflow progress without shelling out to `php craft`.
- Plan 12-08 staged/batched queue execution can use `batchOffset`, `batchLimit`, and `nextBatchOffset` to chain stable transformed-payload batches.
- Live/dry-run semantics, atomic-always-on behavior, filter options, report/log artifacts, and runtime-zero-AI constraints remain preserved for downstream CP and queue plans.

## Self-Check: PASSED

- Found created files:
  - `src/workflow/MigrateWorkflow.php`
  - `src/workflow/VerifyWorkflow.php`
  - `tests/unit/workflow/MigrateWorkflowTest.php`
  - `tests/unit/workflow/VerifyWorkflowTest.php`
  - `.planning/phases/12-cp-migration-console-queue-workflow/12-03-SUMMARY.md`
- Found modified files:
  - `src/console/MigrateController.php`
  - `src/console/VerifyController.php`
- Found task commits: `0cdf39b`, `1df0082`, `4e6eca1`, `d661143`, `fda5468`

---
*Phase: 12-cp-migration-console-queue-workflow*
*Completed: 2026-04-29*
