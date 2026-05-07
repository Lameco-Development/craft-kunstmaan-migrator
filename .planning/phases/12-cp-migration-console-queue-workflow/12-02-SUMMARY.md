---
phase: 12-cp-migration-console-queue-workflow
plan: 02
subsystem: workflow
tags: [craft-cms, yii-console, workflow-services, queue-ready, phpunit]

# Dependency graph
requires:
  - phase: 12-cp-migration-console-queue-workflow
    provides: durable migration run records and artifact tracking from Plan 12-01
  - phase: 02-schema-mapping-filters
    provides: canonical analyze orchestration, mapping.yaml, filters, and runtime-zero-AI contract
  - phase: 11-dual-schema-walkers-llm-first-mapping
    provides: graph artifacts and compile graph compatibility validation
provides:
  - shared `AnalyzeWorkflow::run()` service for CLI, CP, and queue adapters
  - shared `CompileWorkflow::run()` service with gate-usable artifact paths and warning/fatal counts
  - console controllers reduced to adapters over workflow services
  - source-level workflow contract tests for analyze/compile delegation, result shape, shell-out bans, and zero-AI compile
affects: [cp-console, queue-workflow, migration-orchestration, runtime-zero-ai, compile-gates]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - Console controllers perform production gating and then delegate to shared workflow services.
    - Workflow services expose structured result arrays for CP/queue run records while preserving CLI-compatible output through progress callbacks.
    - Source-level PHPUnit tests guard delegation and safety contracts without requiring a full Craft app bootstrap.

key-files:
  created:
    - src/workflow/AnalyzeWorkflow.php
    - src/workflow/CompileWorkflow.php
    - tests/unit/workflow/AnalyzeWorkflowTest.php
    - tests/unit/workflow/CompileWorkflowTest.php
  modified:
    - src/console/AnalyzeController.php
    - src/console/CompileController.php

key-decisions:
  - "Keep `NeverProductionTrait` enforcement in CLI controllers and workflows so both CLI adapters and future CP/queue consumers retain the non-production hard block."
  - "Expose workflow result arrays with `status`, artifacts, summaries, and failures instead of requiring CP/queue callers to parse console output."
  - "Keep Anthropic access only in `AnalyzeWorkflow`; `CompileWorkflow` contains no Anthropic/API-key/LLM surface."

patterns-established:
  - "CLI controllers adapt flags into workflow option arrays and stream workflow progress back through stdout/stderr callbacks."
  - "Analyze workflow accepts exactly `entities`, `locales`, `since`, `noAi`, `autoAcceptHigh`, `auditStrict`, `sourceStrict`, `noLayout`, and `noProviders`."
  - "Compile workflow accepts exactly `overwrite` and `dryRun` and returns fatal/warning counts for gate consumers."

requirements-completed: []

# Metrics
duration: 10min
completed: 2026-04-29
---

# Phase 12 Plan 02: Analyze and Compile workflow extraction Summary

**Reusable analyze and compile orchestration services for CLI adapters, future CP actions, and queue jobs without shell-outs or runtime AI outside analyze**

## Performance

- **Duration:** 10 min
- **Started:** 2026-04-29T09:57:37Z
- **Completed:** 2026-04-29T10:07:07Z
- **Tasks:** 2
- **Files modified:** 6 implementation/test files plus this summary and planning metadata

## Accomplishments

- Added `AnalyzeWorkflow::run(array $options, ?callable $progress = null): array` and moved analyze orchestration behind a reusable service result contract with `status`, `filters`, `artifactPaths`, `summary`, and `failure`.
- Updated `AnalyzeController::actionIndex()` to remain the CLI safety adapter: it applies `NeverProductionTrait`, adapts CLI flags to workflow options, and streams workflow progress to the operator.
- Added `CompileWorkflow::run(array $options, ?callable $progress = null): array` with structured `status`, `artifactPaths`, `summary`, `fatalWarningCount`, `warningCount`, `compiledAt`, and `failure` output for CP/queue gates.
- Updated `CompileController::actionIndex()` to delegate through `CompileWorkflow` while preserving graph compatibility blocking, overwrite/dry-run behavior, artifact writing, and operator output.
- Added PHPUnit source-level workflow contract tests covering option/result shape, controller delegation, shell-out bans, analyze-only Anthropic gating, and compile zero-AI behavior.

## Task Commits

Each task was committed atomically:

1. **Task 1: Extract analyze orchestration into AnalyzeWorkflow**
   - `24cd6eb` test(12-02): add failing analyze workflow tests
   - `7076b6f` feat(12-02): extract analyze workflow service
2. **Task 2: Extract compile orchestration into CompileWorkflow**
   - `0cc9d32` test(12-02): add failing compile workflow tests
   - `835da9a` feat(12-02): extract compile workflow service

**Plan metadata:** committed separately after state/roadmap updates.

_Note: TDD tasks used RED → GREEN commits as required._

## Files Created/Modified

- `src/workflow/AnalyzeWorkflow.php` - Shared analyze workflow service preserving the existing source scan, graph dump, heuristic/LLM proposal, mapping merge, audit, and report orchestration behind a structured result contract.
- `src/workflow/CompileWorkflow.php` - Shared compile workflow service preserving mapping load, overwrite refusal, graph compatibility fatal blocking, compile/report output, artifact path reporting, and warning/fatal counts.
- `src/console/AnalyzeController.php` - CLI adapter that keeps the production gate and delegates flags/progress to `AnalyzeWorkflow`.
- `src/console/CompileController.php` - CLI adapter that keeps the production gate and delegates flags/progress to `CompileWorkflow`.
- `tests/unit/workflow/AnalyzeWorkflowTest.php` - Source-level contract tests for analyze workflow existence, option/result shape, controller delegation, shell-out ban, and analyze-only Anthropic gating.
- `tests/unit/workflow/CompileWorkflowTest.php` - Source-level contract tests for compile workflow existence, gate result fields, controller delegation, shell-out ban, and absence of AI surfaces.

## Decisions Made

- Kept the controller-level production gate as the first operation in each CLI action, then duplicated the same trait availability inside workflows for future non-CLI consumers.
- Used progress callbacks instead of direct CP/queue output dependencies so future queue jobs can map workflow events to `setProgress()` and run records.
- Returned artifact-path and summary metadata directly from workflows so run records from Plan 12-01 can persist structured results without scraping console text.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Fixed workflow-delegation test position check**
- **Found during:** Task 1 and Task 2 TDD GREEN verification
- **Issue:** Initial tests compared the first `AnalyzeWorkflow`/`CompileWorkflow` occurrence in the file, which could be the `use` import rather than the `actionIndex()` body.
- **Fix:** Scoped assertions to the `actionIndex()` method slice and asserted the controller body contains `(new *Workflow())->run(...)` with no local `$plugin = Plugin::getInstance()` orchestration lookup.
- **Files modified:** `tests/unit/workflow/AnalyzeWorkflowTest.php`, `tests/unit/workflow/CompileWorkflowTest.php`
- **Verification:** `vendor/bin/phpunit tests/unit/workflow/AnalyzeWorkflowTest.php tests/unit/workflow/CompileWorkflowTest.php --testdox`
- **Committed in:** `7076b6f`, `835da9a`

**2. [Rule 1 - Bug] Removed inherited console `options()` methods from workflow services**
- **Found during:** Task 2 implementation review
- **Issue:** Generated workflows initially retained controller `options()` methods that called `parent::options()`, but workflows extend `yii\base\Component`, not `craft\console\Controller`.
- **Fix:** Removed the stale `options()` methods from both workflow services; workflow option support is enforced by explicit `run()` allow-lists.
- **Files modified:** `src/workflow/AnalyzeWorkflow.php`, `src/workflow/CompileWorkflow.php`
- **Verification:** `php -l src/workflow/AnalyzeWorkflow.php`; `php -l src/workflow/CompileWorkflow.php`; workflow PHPUnit suite
- **Committed in:** `835da9a`

---

**Total deviations:** 2 auto-fixed (2 Rule 1 bugs)  
**Impact on plan:** Both fixes strengthened the planned workflow contract without changing architecture or adding feature scope.

## Issues Encountered

- `gsd-sdk` was unavailable in this shell (`command not found`), so state/roadmap metadata updates were performed manually instead of through SDK query handlers.
- PHPUnit reported `No code coverage driver available`; this is a warning only and did not affect the targeted test pass.

## User Setup Required

None - no external service configuration required.

## Verification

- `vendor/bin/phpunit tests/unit/workflow/AnalyzeWorkflowTest.php --testdox`
- `vendor/bin/phpunit tests/unit/workflow/CompileWorkflowTest.php --testdox`
- `vendor/bin/phpunit tests/unit/workflow/AnalyzeWorkflowTest.php tests/unit/workflow/CompileWorkflowTest.php --testdox`
- `php -l src/workflow/AnalyzeWorkflow.php`
- `php -l src/workflow/CompileWorkflow.php`
- `php -l src/console/AnalyzeController.php`
- `php -l src/console/CompileController.php`
- Plan acceptance greps:
  - `AnalyzeWorkflow` contract grep count: 28
  - `AnalyzeController` delegation grep count: 2
  - `AnalyzeWorkflow` shell-out grep count: 0
  - `CompileWorkflow` contract grep count: 19
  - `CompileController` delegation grep count: 2
  - `CompileWorkflow` shell-out grep count: 0

## Known Stubs

None.

## Next Phase Readiness

- CP controllers and queue jobs can now call shared analyze/compile workflow services directly instead of shelling out to `php craft` or duplicating console controller bodies.
- Compile workflow results are ready to feed Plan 12 gate/run-record surfaces through `fatalWarningCount`, `warningCount`, `compiledAt`, `artifactPaths`, and `failure`.
- Analyze remains the only AI-capable workflow and retains the existing `noAi`/API-key gating behavior.

## Self-Check: PASSED

- Found created files:
  - `src/workflow/AnalyzeWorkflow.php`
  - `src/workflow/CompileWorkflow.php`
  - `tests/unit/workflow/AnalyzeWorkflowTest.php`
  - `tests/unit/workflow/CompileWorkflowTest.php`
  - `.planning/phases/12-cp-migration-console-queue-workflow/12-02-SUMMARY.md`
- Found modified files:
  - `src/console/AnalyzeController.php`
  - `src/console/CompileController.php`
- Found task commits: `24cd6eb`, `7076b6f`, `0cc9d32`, `835da9a`

---
*Phase: 12-cp-migration-console-queue-workflow*
*Completed: 2026-04-29*
