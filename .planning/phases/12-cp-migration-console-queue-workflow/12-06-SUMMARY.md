---
phase: 12-cp-migration-console-queue-workflow
plan: 06
subsystem: safety
tags: [craft-cms, control-panel, queue-workflow, production-guards, phpunit]

# Dependency graph
requires:
  - phase: 12-cp-migration-console-queue-workflow
    provides: durable migration run records, workflow extraction, and stable CP queue/live settings gates
  - phase: 01-foundation-connectivity
    provides: NeverProductionTrait production semantics
provides:
  - CP/job-safe `MigrationSafety` production hard-block helper
  - structured `GateResult` value object with deterministic UI/controller fields
  - readiness, analyze, compile, dry-run, and live gate evaluation contracts
  - strict CP live blocking for unverifiable queue readiness, warning/unsupported acceptance, settings gates, and job production hard-blocks
affects: [cp-console, queue-workflow, migration-safety, live-migration]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - CP controllers and queue jobs share `MigrationSafety` instead of console-only `NeverProductionTrait`.
    - Gate services return `GateResult::toArray()` data for consistent rendering and enforcement.
    - Live migration treats unknown queue readiness as blocking with CLI remediation copy.

key-files:
  created:
    - src/safety/MigrationSafety.php
    - src/safety/GateResult.php
    - src/safety/MigrationGateService.php
    - tests/unit/safety/MigrationSafetyTest.php
    - tests/unit/safety/MigrationGateServiceTest.php
  modified: []

key-decisions:
  - "Keep `NeverProductionTrait` as the console guard and add `MigrationSafety` as the CP/job-safe companion with the same `CRAFT_ENVIRONMENT === production` semantics."
  - "Represent gate output as deterministic arrays via `GateResult::toArray()` so CP rendering and dispatch enforcement consume the same contract."
  - "Treat unknown live queue-worker readiness as blocking and surface the exact CLI remediation copy rather than allowing a CP override."

patterns-established:
  - "Source-level/PHPUnit safety contract tests avoid bootstrapping a full Craft app, preserving the repository's unit-test discipline."
  - "MigrationGateService protected fact methods provide explicit seams for future CP controllers and queue adapters without shelling out."

requirements-completed: []

# Metrics
duration: 8min
completed: 2026-04-29
---

# Phase 12 Plan 06: Safety and Live Gates Summary

**CP/job-safe production guards plus structured readiness, dry-run, compile, analyze, and strict live migration gates for the Phase 12 console workflow**

## Performance

- **Duration:** 8 min
- **Started:** 2026-04-29T10:34:00Z
- **Completed:** 2026-04-29T10:41:33Z
- **Tasks:** 3
- **Files modified:** 5 implementation/test files plus this summary and planning metadata

## Accomplishments

- Added `MigrationSafety` with `isProduction()`, `environmentName()`, `assertNotProductionForCp()`, and `assertNotProductionForJob()` using NeverProduction-compatible `CRAFT_ENVIRONMENT === 'production'` semantics.
- Added `GateResult` with locked statuses (`passed`, `warning`, `blocked`, `unknown`), full deterministic `toArray()` output, and blocking behavior for unknown results.
- Added `MigrationGateService` with readiness IDs, analyze gates, compile summary/readiness fields, dry-run gates, and strict live gates matching the Phase 12 UI/spec safety contract.
- Enforced `allowCpQueueActions` and `allowCpLiveQueueAction` settings gates, warning/unsupported mapping acceptance, typed `MIGRATE LIVE` confirmation, successful dry-run history, compile no-fatals, queue readiness, and independent job production hard-blocks.
- Added PHPUnit coverage for production guard semantics, exact operator-facing refusal/remediation copy, gate IDs, settings blocks, compile summary fields, and live gate blocking.

## Task Commits

Each TDD task was committed atomically:

1. **Task 1: Create CP and job safe production helper**
   - `197c673` test(12-06): add failing migration safety tests
   - `539a1be` feat(12-06): add CP and job production guard
2. **Task 2: Create structured GateResult value object**
   - `122ea19` test(12-06): add failing gate result tests
   - `d2bf7d3` feat(12-06): add structured gate result value object
3. **Task 3: Implement readiness, dry-run, analyze, compile, and live gates**
   - `240fa81` test(12-06): add failing migration gate service tests
   - `df7e63a` feat(12-06): implement migration gate service

**Plan metadata:** committed separately after state/roadmap updates.

_Note: All three tasks used the required RED → GREEN TDD commits._

## Files Created/Modified

- `src/safety/MigrationSafety.php` - CP/job-safe production hard-block helper with exact operator refusal copy.
- `src/safety/GateResult.php` - Structured gate value object with locked statuses, `cliCommand`, remediation, severity, and blocking output.
- `src/safety/MigrationGateService.php` - Readiness, analyze, compile, dry-run, and live gate evaluation service with strict CP live semantics.
- `tests/unit/safety/MigrationSafetyTest.php` - Tests production/dev/staging semantics and exact CP/job refusal copy.
- `tests/unit/safety/MigrationGateServiceTest.php` - Tests GateResult serialization/statuses plus all locked readiness/dry-run/compile/live gate contracts.

## Decisions Made

- Kept console production behavior in `NeverProductionTrait` unchanged and introduced a separate CP/job helper to avoid forcing console `ExitCode`/stderr behavior into web and queue contexts.
- Returned gate arrays rather than GateResult objects from `MigrationGateService` methods so future Twig/controller code can consume deterministic data directly.
- Used protected fact methods in `MigrationGateService` for admin/elevated session, queue, run, compile, dry-run, and mapping facts so later CP controllers can provide/override verifiable context without broad catches or shelling out.
- Treated missing or unverifiable queue worker readiness as a blocking `unknown` live gate with the required CLI remediation copy.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Made safety gate tests compatible with source-level PHPUnit**
- **Found during:** Task 3 (Implement readiness, dry-run, analyze, compile, and live gates)
- **Issue:** Directly constructing `Settings` in the new source-level safety test caused Yii/Craft model initialization to require a fully bootstrapped `Yii` class, contrary to this repository's unit-test pattern.
- **Fix:** Adjusted the test helper to instantiate `Settings` via reflection without constructor side effects, matching existing source-level contract-test discipline.
- **Files modified:** `tests/unit/safety/MigrationGateServiceTest.php`
- **Verification:** `vendor/bin/phpunit tests/unit/safety/MigrationGateServiceTest.php --testdox`
- **Committed in:** `df7e63a`

**2. [Rule 3 - Blocking] Guarded optional Craft/Yii runtime lookups in gate service**
- **Found during:** Task 3 (Implement readiness, dry-run, analyze, compile, and live gates)
- **Issue:** Source-level PHPUnit does not bootstrap global `Craft`/`Yii` classes, so default runtime fact lookups for queue/run/storage state failed before tests could exercise the gate contract.
- **Fix:** Added explicit class-existence guards for runtime-only `Craft`/`Yii` lookups while preserving production behavior when Craft is bootstrapped.
- **Files modified:** `src/safety/MigrationGateService.php`
- **Verification:** `vendor/bin/phpunit tests/unit/safety/MigrationGateServiceTest.php --testdox`
- **Committed in:** `df7e63a`

---

**Total deviations:** 2 auto-fixed (2 Rule 3 blocking issues)  
**Impact on plan:** Preserved the planned safety functionality while keeping the repository's source-level PHPUnit approach green. No architecture or feature-scope change was introduced.

## Issues Encountered

- `gsd-sdk` was unavailable in this shell (`command not found`), so state and roadmap metadata updates were performed manually instead of through SDK query handlers.
- PHPUnit reported `No code coverage driver available`; this is a warning only and did not affect targeted test passes.

## User Setup Required

None - no external service configuration required.

## Verification

- `vendor/bin/phpunit tests/unit/safety/MigrationSafetyTest.php --testdox`
- `vendor/bin/phpunit tests/unit/safety/MigrationGateServiceTest.php --testdox`
- `php -l src/safety/MigrationSafety.php`
- `php -l src/safety/GateResult.php`
- `php -l src/safety/MigrationGateService.php`
- `php -l tests/unit/safety/MigrationSafetyTest.php`
- `php -l tests/unit/safety/MigrationGateServiceTest.php`
- Plan acceptance greps:
  - MigrationSafety required methods: 4
  - Production refusal copy matches: 2
  - GateResult status/serialization/CLI fields: 32
  - Live gate locked IDs: 50
  - Readiness/dry-run locked IDs: 22
  - CP settings and warning/unsupported gates: 15
  - `MIGRATE LIVE` / queue unknown remediation copy: 7

## Known Stubs

None. The stub scan found no TODO/FIXME markers, placeholder copy, generated mock UI data, or unwired empty data sources. Null checks and unknown gate states in `MigrationGateService` are intentional safety outcomes, not UI stubs.

## Threat Flags

None - this plan introduced safety evaluation helpers only. It added no new network endpoints, auth routes, file write paths, database schema changes, or trust-boundary persistence.

## TDD Gate Compliance

- Task 1 RED gate commit present: `197c673`; GREEN gate commit present after RED: `539a1be`
- Task 2 RED gate commit present: `122ea19`; GREEN gate commit present after RED: `d2bf7d3`
- Task 3 RED gate commit present: `240fa81`; GREEN gate commit present after RED: `df7e63a`
- Refactor gate: not needed

## Next Phase Readiness

- CP console controllers can now render/read the same readiness and action gates they enforce.
- Queue jobs can call `MigrationSafety::assertNotProductionForJob()` independently from CP controller checks.
- Future queue/CP plans should wire verifiable admin/elevated/queue worker/run-history facts into `MigrationGateService` rather than duplicating gate logic.

## Self-Check: PASSED

- Found created files:
  - `src/safety/MigrationSafety.php`
  - `src/safety/GateResult.php`
  - `src/safety/MigrationGateService.php`
  - `tests/unit/safety/MigrationSafetyTest.php`
  - `tests/unit/safety/MigrationGateServiceTest.php`
  - `.planning/phases/12-cp-migration-console-queue-workflow/12-06-SUMMARY.md`
- Found task commits: `197c673`, `539a1be`, `122ea19`, `d2bf7d3`, `240fa81`, `df7e63a`

---
*Phase: 12-cp-migration-console-queue-workflow*
*Completed: 2026-04-29*
