---
phase: 12-cp-migration-console-queue-workflow
plan: 10
subsystem: cp-console
tags: [craft-cms, control-panel, twig, queue-workflow, safety-gates, phpunit]

# Dependency graph
requires:
  - phase: 12-cp-migration-console-queue-workflow
    provides: CP Utility shell, controller view model, queue actions, run records, gate services, and improved mapping review from Plans 12-04 and 12-09
provides:
  - Craft CP-native tabbed migration console templates for readiness, analyze, mapping, compile, runs, reports, and Danger Zone
  - CSRF/actionInput queue forms for analyze, compile, verify/report, dry-run, and gated live migration
  - Disabled/deferred reset and cleanup Danger Zone panels pending exact deletion contracts
affects: [cp-console, utility-ui, queue-workflow, migration-safety, operator-workflow]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - Craft CP-native Twig partials under `templates/_console` with local `km-console-` CSS only.
    - Mutation forms use Craft POST, `csrfInput()`, and `actionInput(...)`; filters/navigation remain URL-driven.
    - Destructive live controls are visually separated and disabled on failed/unknown gates; reset/cleanup controls are non-submitting deferred panels.

key-files:
  created:
    - templates/_console/_tabs.twig
    - templates/_console/_readiness.twig
    - templates/_console/_analyze.twig
    - templates/_console/_mapping.twig
    - templates/_console/_compile.twig
    - templates/_console/_runs.twig
    - templates/_console/_run-detail.twig
    - templates/_console/_reports.twig
    - templates/_console/_danger-zone.twig
    - tests/unit/templates/ConsoleTemplateContractTest.php
  modified:
    - templates/_console/index.twig
    - src/controllers/MigrationConsoleController.php

key-decisions:
  - "Keep Phase 12 UI as Craft CP-native Twig partials with local km-console-* layout glue and no frontend build system."
  - "Wrap the existing mapping review inside the Mapping tab so canonical mapping.yaml editing remains owned by the established MappingController forms."
  - "Render Danger Zone reset/cleanup as disabled deferred panels only; no active destructive reset or cleanup forms ship in Plan 12-10."

patterns-established:
  - "Console tab routing happens in `templates/_console/index.twig` and delegates each tab to a focused `_console/*` partial."
  - "Source-level PHPUnit template contracts lock exact UI-SPEC copy, queue action routes, empty states, and destructive-action deferral."
  - "Run detail routing resolves the requested run ID from the provided run list with a latest-run fallback for sparse view-model data."

requirements-completed: []

# Metrics
duration: 8min
completed: 2026-04-29
---

# Phase 12 Plan 10: CP-native console templates Summary

**Craft CP-native tabbed migration console with readiness cards, queued action forms, run/report surfaces, and gated live/Danger Zone safety copy**

## Performance

- **Duration:** 8 min
- **Started:** 2026-04-29T11:21:54Z
- **Completed:** 2026-04-29T11:29:54Z
- **Tasks:** 3
- **Files modified:** 12 implementation/test files plus this summary and planning metadata

## Accomplishments

- Expanded the Plan 12-09 console shell into a tab-routed Craft Utility surface with the required tab order: Readiness, Analyze, Mapping, Compile, Runs, Reports, and Danger Zone.
- Added readiness cards and dry-run controls for Environment, Connectivity, Mapping & Compile, Queue, and Latest run, including Passed/Warning/Blocked/Unknown status labels and visible dry-run gate IDs.
- Added queued Analyze, Compile, Verify/report, Dry-run, and Live forms using `method="post"`, `csrfInput()`, and `actionInput(...)` routes.
- Added Mapping, Runs, Run detail, and Reports tab partials with the exact required empty states, run-table columns, row actions, artifact names, and report queue copy.
- Added a visually separated live migration panel with exact failure, queue-unavailable, backup acknowledgement, `warningsAccepted`, warning/unsupported acceptance, and `MIGRATE LIVE` copy.
- Added Danger Zone panels for `RESET MIGRATION STATE` and `DELETE ARTIFACTS` that are explicitly disabled/deferred and do not render active destructive submit forms.
- Added source-level PHPUnit contracts covering all required UI strings, routes, CSRF/action patterns, deferred destructive controls, and frontend-build prohibition.

## Task Commits

Each task was committed atomically:

1. **Task 1: Build console shell, tabs, readiness, analyze, and compile templates**
   - `9fde9fa` feat(12-10): build console shell readiness analyze compile templates
2. **Task 2: Build mapping, runs, reports, and run-detail templates**
   - `231b593` feat(12-10): build mapping runs and reports templates
3. **Task 3: Build dry-run/live controls and Danger Zone**
   - `f781c51` feat(12-10): build dry-run live controls and danger zone

Additional correctness fix:

- `d7cfb73` fix(12-10): select run detail from run list

**Plan metadata:** committed separately after state/roadmap updates.

## Files Created/Modified

- `src/controllers/MigrationConsoleController.php` - Exposes default `filters` and `options` to Twig so queue forms can preserve the controller view-model defaults.
- `templates/_console/index.twig` - Main console shell, local `km-console-` CSS, exact title/subtitle, CLI fallback notice, tab routing, and partial delegation.
- `templates/_console/_tabs.twig` - Keyboard-reachable tab navigation with required tab order and active state.
- `templates/_console/_readiness.twig` - Readiness dashboard cards, dry-run CTA, and visible dry-run gates.
- `templates/_console/_analyze.twig` - Analyze queue form with entity/locale/since filters, Anthropic confirmation checkbox, and exact missing-key disabled copy.
- `templates/_console/_mapping.twig` - Mapping tab wrapper around the existing CP mapping review with exact empty-state copy.
- `templates/_console/_compile.twig` - Compile summary, compile readiness, artifact/log fields, equivalent CLI command, and queue-compile form.
- `templates/_console/_runs.twig` - Run table, run-detail routing, row actions, and separated live migration panel.
- `templates/_console/_run-detail.twig` - Run detail surface with gate snapshot, filters/options, queue job IDs, logs, artifacts, failure details, readable summary, and collapsed raw JSON.
- `templates/_console/_reports.twig` - Artifact list for `REPORT.md`, `VERIFY-`, `PAGE-ROOTED-COVERAGE.md`, `MAPPING-AUDIT.md`, schema JSON files, and queue verify/report form.
- `templates/_console/_danger-zone.twig` - Disabled/deferred reset and cleanup panels; no active destructive POST forms.
- `tests/unit/templates/ConsoleTemplateContractTest.php` - Source-level template contract tests for UI-SPEC strings, form routes, safety copy, and deferred destructive controls.

## Decisions Made

- Kept the UI implementation entirely in Twig/Craft CP primitives; no shadcn, Vite, npm, React, or CSS build pipeline was introduced.
- Used a Mapping tab wrapper instead of duplicating mapping write forms so canonical `mapping.yaml` mutations continue through the existing MappingController and atomic MappingFile flow.
- Kept reset/cleanup out of controller/action scope in this plan; the Danger Zone shows operator-facing deferred panels until deletion boundaries are specified.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing Critical Functionality] Exposed default filters/options to templates**
- **Found during:** Task 1 (Build console shell, tabs, readiness, analyze, and compile templates)
- **Issue:** The console controller computed default filters/options for gate evaluation, but the expanded Analyze template needed those values to render filter fields without relying on undefined Twig variables.
- **Fix:** Added `filters` and `options` to `MigrationConsoleController::utilityVariables()`.
- **Files modified:** `src/controllers/MigrationConsoleController.php`, `templates/_console/_analyze.twig`
- **Verification:** `php -l src/controllers/MigrationConsoleController.php`; `vendor/bin/phpunit tests/unit/templates/ConsoleTemplateContractTest.php --testdox`
- **Committed in:** `9fde9fa`

**2. [Rule 1 - Bug] Selected run detail by requested run ID**
- **Found during:** Final review after Task 3
- **Issue:** The Runs tab initially rendered run detail only when `runId` matched `latestRun`, which would prevent operators from viewing older run records from the table.
- **Fix:** Resolve the requested run ID from the provided `runs` list, with the latest-run branch retained as a sparse-view-model fallback.
- **Files modified:** `templates/_console/_runs.twig`
- **Verification:** `vendor/bin/phpunit tests/unit/templates/ConsoleTemplateContractTest.php --testdox`; final plan acceptance greps
- **Committed in:** `d7cfb73`

---

**Total deviations:** 2 auto-fixed (1 Rule 2 missing critical functionality, 1 Rule 1 bug)  
**Impact on plan:** Both fixes were necessary for the planned UI to render and navigate correctly; no new architecture or unsafe destructive surface was introduced.

## Issues Encountered

- `gsd-sdk` was unavailable in this shell (`command not found`), so state and roadmap metadata updates were performed manually instead of through SDK query handlers.
- PHPUnit reported `No code coverage driver available`; this is a warning only and did not affect the targeted test passes.

## User Setup Required

None - no external service configuration required.

## Verification

- `php -l src/controllers/MigrationConsoleController.php`
- `vendor/bin/phpunit tests/unit/templates/ConsoleTemplateContractTest.php --testdox`
- Plan acceptance greps:
  - Task 1 console shell/analyze/compile strings: 56 matches (`>= 14` required)
  - Task 1 readiness/compile field strings: 41 matches (`>= 9` required)
  - Task 2 run/report/detail strings: 79 matches (`>= 27` required)
  - Task 2 mapping/log/artifact empty strings: 10 matches (`>= 5` required)
  - Task 3 dry-run/live/Danger Zone strings: 95 matches (`>= 9` required)
  - Task 3 gate/failure/deferred strings: 13 matches (`>= 8` required)
  - Forbidden `shadcn|vite|npm` in `templates/_console`: 0 matches

## Known Stubs

None. The stub scan found fallback empty arrays/default copy in Twig only as defensive rendering guards; no hardcoded empty data source prevents the console from consuming the controller view model.

## Threat Flags

None - the plan's security-relevant surface is limited to CP mutation forms already covered by the threat model. All live mutation forms use Craft CSRF/action routing, and reset/cleanup destructive operations remain disabled/deferred.

## Next Phase Readiness

- Phase 12 now has its final CP Utility template layer on top of run records, queue actions, gate services, and queue jobs.
- A verifier can inspect the Control Panel console copy and source-level contracts without needing a frontend build step.
- Future work can add richer run-detail data and active cleanup/reset only after exact deletion contracts and controller tests are planned.

## Self-Check: PASSED

- Found created files:
  - `templates/_console/_tabs.twig`
  - `templates/_console/_readiness.twig`
  - `templates/_console/_analyze.twig`
  - `templates/_console/_mapping.twig`
  - `templates/_console/_compile.twig`
  - `templates/_console/_runs.twig`
  - `templates/_console/_run-detail.twig`
  - `templates/_console/_reports.twig`
  - `templates/_console/_danger-zone.twig`
  - `tests/unit/templates/ConsoleTemplateContractTest.php`
- Found modified files:
  - `src/controllers/MigrationConsoleController.php`
  - `templates/_console/index.twig`
- Found task/fix commits: `9fde9fa`, `231b593`, `f781c51`, `d7cfb73`

---
*Phase: 12-cp-migration-console-queue-workflow*
*Completed: 2026-04-29*
