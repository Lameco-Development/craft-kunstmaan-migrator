---
phase: 12-cp-migration-console-queue-workflow
plan: 07
subsystem: plugin-wiring
tags: [craft-cms, control-panel, dependency-injection, queue-workflow, phpunit]

# Dependency graph
requires:
  - phase: 12-cp-migration-console-queue-workflow
    provides: durable run records, workflow services, CP/job safety gates, and queue/live settings from Plans 12-01 through 12-06
  - phase: 04-adapters-verify-settings
    provides: existing `Plugin::config()` component registration and sibling DI wiring pattern
provides:
  - Phase 12 run, safety, gate, analyze, compile, migrate, and verify services as plugin components
  - Plugin property docblocks for Phase 12 components
  - `MigrationGateService` sibling wiring to run records, mapping file, settings, and safety helpers
  - source-level CP registration tests proving Utility-only surface and no top-level CP nav registration
affects: [cp-console, queue-workflow, plugin-bootstrap, migration-safety]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - Phase 12 services are registered as bare plugin components in `Plugin::config()` and wired as siblings in `init()`.
    - CP surface remains Craft Utility-only; tests reject CP nav event registration.
    - Gate services prefer injected plugin siblings while preserving safe fallbacks for source-level/unit contexts.

key-files:
  created:
    - tests/integration/PluginConsoleRegistrationTest.php
  modified:
    - src/Plugin.php
    - src/safety/MigrationGateService.php
    - tests/integration/PluginBootstrapTest.php

key-decisions:
  - "Preserve the existing `KunstmaanMappingUtility` registration as the only CP entry point and explicitly test that no CP nav registration is introduced."
  - "Expose Phase 12 services as plugin components now so future CP controllers and queue jobs consume the same service graph as CLI adapters."
  - "Add injectable public sibling slots to `MigrationGateService` for run records, mapping file, settings, and safety instead of letting it rely only on detached fallback instances."

patterns-established:
  - "`PluginBootstrapTest` remains the source-level component/docblock contract test for plugin service registration."
  - "`PluginConsoleRegistrationTest` locks the Utility-only CP surface and Phase 12 gate sibling wiring without bootstrapping Craft."

requirements-completed: []

# Metrics
duration: 3min
completed: 2026-04-29
---

# Phase 12 Plan 07: Plugin wiring Summary

**Phase 12 service components wired into the Craft plugin graph while preserving the existing Utility-only Control Panel surface**

## Performance

- **Duration:** 3 min
- **Started:** 2026-04-29T10:44:57Z
- **Completed:** 2026-04-29T10:47:13Z
- **Tasks:** 2
- **Files modified:** 4 implementation/test files plus this summary and planning metadata

## Accomplishments

- Registered `migrationRunService`, `migrationSafety`, `migrationGateService`, `analyzeWorkflow`, `compileWorkflow`, `migrateWorkflow`, and `verifyWorkflow` in `Plugin::config()`.
- Added `@property-read` docblocks and imports for all Phase 12 service components.
- Wired `MigrationGateService` to the shared plugin instances of `MigrationRunService`, `MappingFile`, `Settings`, and `MigrationSafety` in `Plugin::init()`.
- Preserved `Utilities::EVENT_REGISTER_UTILITIES` registration of `KunstmaanMappingUtility` and added tests proving no top-level CP nav registration was introduced.
- Added source-level integration coverage for Phase 12 component registration, property docblocks, sibling wiring, and Utility-only CP registration.

## Task Commits

Each task was committed atomically:

1. **Task 1: Register Phase 12 service components**
   - `26c78a4` feat(12-07): register phase 12 plugin components
2. **Task 2: Wire sibling dependencies without adding a CP section**
   - `85e5ac6` feat(12-07): wire phase 12 gate dependencies

**Plan metadata:** committed separately after state/roadmap updates.

## Files Created/Modified

- `src/Plugin.php` - Registers Phase 12 services as plugin components, exposes property docblocks, keeps the Utility event registration, and wires migration gate dependencies in `init()`.
- `src/safety/MigrationGateService.php` - Adds injectable sibling slots for run records, mapping file, settings, and safety, and uses those shared components before falling back to source-level defaults.
- `tests/integration/PluginBootstrapTest.php` - Adds source-level assertions for Phase 12 component slots and plugin property docblocks.
- `tests/integration/PluginConsoleRegistrationTest.php` - New source-level CP registration contract covering Utility-only surface, no CP nav event, and migration gate sibling wiring.

## Decisions Made

- Kept the Phase 12 console under the existing Craft Utilities registration, matching the phase decision to defer a top-level CP section.
- Used source-level PHPUnit assertions rather than Craft-bootstrapped tests, consistent with existing plugin bootstrap test discipline.
- Added `MigrationGateService` public dependency slots so the gate service can share the same plugin components future CP controllers and queue jobs will use.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing Critical Functionality] Added injectable dependencies to `MigrationGateService`**
- **Found during:** Task 2 (Wire sibling dependencies without adding a CP section)
- **Issue:** The plan required `migrationGateService->migrationRunService`, `migrationGateService->mappingFile`, and `migrationGateService->settings` wiring, but `MigrationGateService` did not yet expose those public sibling slots.
- **Fix:** Added nullable public dependency properties for `MigrationRunService`, `MappingFile`, `Settings`, and `MigrationSafety`; updated the service to prefer injected dependencies while keeping existing source-level fallbacks.
- **Files modified:** `src/safety/MigrationGateService.php`, `src/Plugin.php`, `tests/integration/PluginConsoleRegistrationTest.php`
- **Verification:** `php -l src/safety/MigrationGateService.php`; `vendor/bin/phpunit tests/integration/PluginConsoleRegistrationTest.php --testdox`; final plan verification suite
- **Committed in:** `85e5ac6`

---

**Total deviations:** 1 auto-fixed (1 Rule 2 missing critical functionality)  
**Impact on plan:** The fix was required to make the planned sibling wiring real and shared across CP/queue runtime. No new CP surface or architecture change was introduced.

## Issues Encountered

- `gsd-sdk` was unavailable in this shell (`command not found`), so state and roadmap metadata updates were performed manually instead of through SDK query handlers.
- PHPUnit reported `No code coverage driver available`; this is a warning only and did not affect targeted test passes.

## User Setup Required

None - no external service configuration required.

## Verification

- `php -l src/Plugin.php`
- `php -l src/safety/MigrationGateService.php`
- `php -l tests/integration/PluginBootstrapTest.php`
- `php -l tests/integration/PluginConsoleRegistrationTest.php`
- `vendor/bin/phpunit tests/integration/PluginBootstrapTest.php --testdox`
- `vendor/bin/phpunit tests/integration/PluginConsoleRegistrationTest.php --testdox`
- Plan acceptance greps:
  - Phase 12 component/docblock IDs in `src/Plugin.php`: 18 matches
  - `@property-read.*MigrationRunService|@property-read.*MigrationGateService`: 2 matches
  - `migrationGateService->migrationRunService|KunstmaanMappingUtility::class`: 2 matches
  - `EVENT_REGISTER_CP_NAV_ITEMS|Cp::EVENT_REGISTER_CP_NAV_ITEMS`: 0 matches

## Known Stubs

None. Stub scan findings were existing comments or intentional nullable DI/fallback checks, not UI-rendered empty/mock data or incomplete Phase 12 wiring.

## Threat Flags

None - this plan changed plugin component registration and internal DI wiring only. It added no network endpoints, auth paths, file write paths, schema changes, or top-level CP navigation.

## Next Phase Readiness

- Future CP controllers and queue jobs can use `Plugin::getInstance()->migrationRunService`, `migrationGateService`, and workflow components directly.
- The Utility-only CP entry point is now guarded by tests before later plans add controllers, queue jobs, and templates.
- Gate evaluation now shares the plugin's run records, mapping file, settings, and safety helper.

## Self-Check: PASSED

- Found created files:
  - `tests/integration/PluginConsoleRegistrationTest.php`
  - `.planning/phases/12-cp-migration-console-queue-workflow/12-07-SUMMARY.md`
- Found modified files:
  - `src/Plugin.php`
  - `src/safety/MigrationGateService.php`
  - `tests/integration/PluginBootstrapTest.php`
- Found task commits: `26c78a4`, `85e5ac6`

---
*Phase: 12-cp-migration-console-queue-workflow*
*Completed: 2026-04-29*
