---
phase: 12-cp-migration-console-queue-workflow
plan: 09
subsystem: cp-console
tags: [craft-cms, control-panel, utility, queue-workflow, phpunit]

# Dependency graph
requires:
  - phase: 12-cp-migration-console-queue-workflow
    provides: durable run records, CP/job safety gates, plugin service wiring, and queue jobs from Plans 12-01 through 12-08
provides:
  - Utility shell renamed to the Kunstmaan Migration Console
  - source-level `MigrationConsoleController::utilityVariables()` view model for Phase 12 tabs, gates, runs, reports, mapping, CLI commands, and copy
  - admin-only CP queue actions for analyze, compile, verify/report, dry-run, and live migration
  - controller enforcement of CP production hard-blocks, queue settings gates, elevated live sessions, typed phrase, backup acknowledgement, and warning acceptance
affects: [cp-console, queue-workflow, migration-safety, utility-ui]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - Existing Craft Utility remains the only CP entry point and renders a console shell through `View::TEMPLATE_MODE_CP`.
    - CP controller actions validate CP/admin/POST requests, create run records, enqueue queue jobs, and never execute workflow services inline.
    - Queue dispatch captures gate snapshots in run records and redirects operators back to the Utility Runs tab.

key-files:
  created:
    - src/controllers/MigrationConsoleController.php
    - templates/_console/index.twig
    - tests/unit/controllers/MigrationConsoleControllerTest.php
  modified:
    - src/utilities/KunstmaanMappingUtility.php
    - tests/integration/PluginConsoleRegistrationTest.php

key-decisions:
  - "Keep the Utility id `kunstmaan-mapping` and icon `shuffle` while changing only the display copy and rendered template."
  - "Implement the first console template as a minimal safe shell with an ignore-missing tab include so Plan 12-10 can expand partials without breaking Utility rendering."
  - "Queue actions create run records and push `MigrationStageJob`/`MigrationPipelineJob` instances; CP requests do not call workflow `run()` methods."

patterns-established:
  - "Source-level PHPUnit tests lock controller and Utility contracts without requiring a bootstrapped Craft CP request."
  - "Live CP dispatch passes the request-derived `warningsAccepted=1` state into `MigrationGateService::liveGates(... warningsAccepted: $warningsAccepted)`."
  - "Initial and chained queue IDs are tracked through `markQueued()` and `appendQueueJobId()` for a consistent run-record queue history."

requirements-completed: []

# Metrics
duration: 8min
completed: 2026-04-29
---

# Phase 12 Plan 09: CP Controller and Utility Shell Summary

**Utility-hosted Craft CP migration console with a source-level view model and admin-only queue actions for safe, non-inline migration dispatch**

## Performance

- **Duration:** 8 min
- **Started:** 2026-04-29T11:10:32Z
- **Completed:** 2026-04-29T11:18:48Z
- **Tasks:** 3
- **Files modified:** 5 implementation/test/template files plus this summary and planning metadata

## Accomplishments

- Renamed the existing Craft Utility to **Kunstmaan Migration Console**, preserved `id()` as `kunstmaan-mapping`, preserved icon `shuffle`, and switched rendering to `kunstmaan-migrator/_console/index` in CP template mode.
- Added a minimal `templates/_console/index.twig` shell containing the title, tab include hook, and safe empty-state copy so the Utility no longer points at a missing console template.
- Added `MigrationConsoleController::utilityVariables()` with the required tabs, gate data, latest/list run data, report metadata, mapping variables, compile summary, run action metadata, CLI commands, and exact UI-SPEC copy.
- Added admin-only queue actions for analyze, compile, verify/report, dry-run, and live migration that validate CP POST/admin requests, hard-block production through `migrationSafety->assertNotProductionForCp()`, enforce CP queue settings, and enqueue jobs rather than running workflows inline.
- Added source-level PHPUnit coverage for Utility rendering, no top-level CP nav registration, view-model keys/copy, queue endpoint contracts, safety ordering, warning acceptance, and destructive-action deferral.

## Task Commits

Each task was committed atomically:

1. **Task 1: Expand Utility to render migration console**
   - `ed7feea` feat(12-09): render migration console utility shell
2. **Task 2: Create console view model**
   - `c9ca2b6` test(12-09): add failing console view model tests
   - `7a20b2e` feat(12-09): create migration console view model
3. **Task 3: Add admin-only queue action endpoints**
   - `b9b3686` test(12-09): add failing queue action endpoint tests
   - `cf82770` feat(12-09): add admin-only CP queue endpoints

**Plan metadata:** committed separately after state/roadmap updates.

_Note: Tasks 2 and 3 used the required RED → GREEN TDD commits._

## Files Created/Modified

- `src/controllers/MigrationConsoleController.php` - New CP controller/view-model adapter with Utility variables, run/report/mapping metadata, and admin-only queue actions for analyze/compile/verify/dry-run/live dispatch.
- `src/utilities/KunstmaanMappingUtility.php` - Renamed Utility display copy and switched Utility rendering to the new console template and view model.
- `templates/_console/index.twig` - Minimal console shell with title, tab include hook, and safe empty state for Plan 12-10 partial expansion.
- `tests/unit/controllers/MigrationConsoleControllerTest.php` - Source-level controller contract tests for view-model keys/copy, queue endpoints, production-before-push ordering, no destructive reset/cleanup actions, and no inline workflow execution.
- `tests/integration/PluginConsoleRegistrationTest.php` - Extended Utility-only CP registration test coverage to include console template rendering details.

## Decisions Made

- Preserved the existing Utility URL assumptions by keeping `KunstmaanMappingUtility::id()` as `kunstmaan-mapping`.
- Used `ignore missing` on the `_console/_tabs` include so this plan can ship the shell before Plan 12-10 adds the tab partials.
- Used source-level tests for controller/action contracts because this repository's Phase 12 tests intentionally avoid bootstrapping a full Craft CP request.
- Redirected successful queue dispatches back to the Utility Runs tab with `runId` rather than inventing a new CP section or route surface.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing Critical Functionality] Added a minimal controller shell while wiring the Utility**
- **Found during:** Task 1 (Expand Utility to render migration console)
- **Issue:** The Utility had to call `MigrationConsoleController::utilityVariables()`, but that controller file did not exist yet. Leaving the Utility pointed at a missing class would make the new Utility shell fail at runtime until Task 2.
- **Fix:** Implemented the controller in Task 2 immediately after the Utility shell and locked the Task 1 Utility contract with source-level tests. No long-running CP execution was introduced.
- **Files modified:** `src/controllers/MigrationConsoleController.php`, `src/utilities/KunstmaanMappingUtility.php`, `tests/integration/PluginConsoleRegistrationTest.php`
- **Verification:** `vendor/bin/phpunit tests/integration/PluginConsoleRegistrationTest.php --testdox`; `vendor/bin/phpunit tests/unit/controllers/MigrationConsoleControllerTest.php --testdox`
- **Committed in:** `7a20b2e`

---

**Total deviations:** 1 auto-fixed (1 Rule 2 missing critical functionality)  
**Impact on plan:** The fix was required to make the Utility renderable and did not expand CP surface area or add inline execution.

## Issues Encountered

- `gsd-sdk` was unavailable in this shell (`command not found`), so state and roadmap metadata updates were performed manually instead of through SDK query handlers.
- PHPUnit reported `No code coverage driver available`; this is a warning only and did not affect targeted test passes.

## User Setup Required

None - no external service configuration required.

## Verification

- `php -l src/controllers/MigrationConsoleController.php`
- `vendor/bin/phpunit tests/unit/controllers/MigrationConsoleControllerTest.php --testdox`
- `vendor/bin/phpunit tests/integration/PluginConsoleRegistrationTest.php --testdox`
- Plan acceptance greps:
  - Utility console rendering strings: 6 matches (`>= 4` required)
  - Top-level CP nav registration: 0 matches
  - View-model tab/summary/action strings: 92 matches (`>= 9` required)
  - Exact UI copy strings: 18 matches (`>= 9` required)
  - Queue action method names: 5 matches (`>= 5` required)
  - Queue guard/dispatch strings: 18 matches (`>= 11` required)
  - Destructive reset/cleanup action methods: 0 matches
  - Inline workflow `->run(` calls in CP controller: 0 matches

## Known Stubs

- `templates/_console/index.twig` intentionally contains only the Plan 12-09 safe shell and an ignore-missing tab include hook. This is planned scaffolding for Plan 12-10 template partial expansion and does not block the Utility/controller/queue-action goals of this plan.

## Threat Flags

None - this plan introduced the CP queue action surface explicitly covered by the plan threat model. Actions are CP-only, POST-only, admin-only, production-hard-blocked before queue push, settings-gated, and queue-backed rather than inline workflow execution.

## TDD Gate Compliance

- Task 2 RED gate commit present: `c9ca2b6`; GREEN gate commit present after RED: `7a20b2e`
- Task 3 RED gate commit present: `b9b3686`; GREEN gate commit present after RED: `cf82770`
- Refactor gate: not needed

## Next Phase Readiness

- Plan 12-10 can add the tab partials on top of `templates/_console/index.twig` and consume the completed `utilityVariables()` view model.
- CP forms can target `kunstmaan-migrator/migration-console/queue-*` actions with CSRF/POST/action inputs.
- Queue jobs, run records, gate snapshots, and UI copy are now wired through one controller seam while preserving the Utility-only CP surface.

## Self-Check: PASSED

- Found created files:
  - `src/controllers/MigrationConsoleController.php`
  - `templates/_console/index.twig`
  - `tests/unit/controllers/MigrationConsoleControllerTest.php`
  - `.planning/phases/12-cp-migration-console-queue-workflow/12-09-SUMMARY.md`
- Found modified files:
  - `src/utilities/KunstmaanMappingUtility.php`
  - `tests/integration/PluginConsoleRegistrationTest.php`
- Found task commits: `ed7feea`, `c9ca2b6`, `7a20b2e`, `b9b3686`, `cf82770`

---
*Phase: 12-cp-migration-console-queue-workflow*
*Completed: 2026-04-29*
