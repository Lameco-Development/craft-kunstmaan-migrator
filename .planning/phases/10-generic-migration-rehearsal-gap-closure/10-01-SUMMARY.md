---
phase: 10-generic-migration-rehearsal-gap-closure
plan: 10-01
subsystem: migration-compile-preflight
tags: [php, craft-cms, compile, migrate, pagebuilder, transform-fixtures, phpunit]

requires:
  - phase: 09-migration-workflow-hardening-page-rooted-introspection-audit
    provides: "compiled mapping preflight, target introspection, Page-rooted coverage, and failure-exit semantics"
provides:
  - "Load-fatal Craft target validation for invalid section/entry-type combinations"
  - "Compile and live-migrate preflight guards that block fatal compiled target states"
  - "PageBuilder Matrix ownership validation before pageBuilderHandle propagation"
  - "Synthetic structural regression coverage for Phase 10 rehearsal failure shapes"
affects: [10-02-load-fallback-hardening, 10-03-taxonomy-resolver, 10-04-verify-rehearsal-proof]

tech-stack:
  added: []
  patterns:
    - "CraftTargetIntrospector returns severity-classified validation while preserving legacy validate() callers"
    - "MigrateController preflight accepts an optional Craft target schema to block stale fatal mappings"
    - "MappingCompiler blocks invalid pageBuilderHandle propagation and preserves flat fallback visibility"

key-files:
  created:
    - ".planning/phases/10-generic-migration-rehearsal-gap-closure/10-01-SUMMARY.md"
  modified:
    - "src/compile/CraftTargetIntrospector.php"
    - "src/console/CompileController.php"
    - "src/console/MigrateController.php"
    - "src/compile/MappingCompiler.php"
    - "tests/unit/compile/CraftTargetIntrospectorTest.php"
    - "tests/unit/console/MigrateControllerCompilePreflightTest.php"
    - "tests/unit/console/MigrateControllerFailureExitTest.php"
    - "tests/unit/compile/MappingCompilerValidationTest.php"
    - "tests/integration/transform/TransformCharacterizationTest.php"

key-decisions:
  - "Section/entry-type incompatibility and missing target sections/entry types are load-fatal; missing fields/adapters remain warning-only advisory validation."
  - "Invalid PageBuilder ownership is blocked at compile propagation time; flat page-part fallback is preserved when present, otherwise an operator-visible warning identifies data-loss risk."
  - "Structural regression fixtures remain synthetic in code and do not commit proprietary rehearsal content."

patterns-established:
  - "Severity-classified target validation: compile prints fatal validation separately and exits CONFIG before writing mapping.yaml."
  - "Live migrate preflight: actionIndex/actionLoad validate compiled mapping against the live Craft target schema before extract/load."
  - "PageBuilder ownership gate: nodeClasses[].pageBuilderHandle is only set when the parent entry type owns the Matrix field."

requirements-completed: [PH10-03, PH10-05, PH10-07]

duration: 7min
completed: 2026-04-28
---

# Phase 10 Plan 10-01: Compile/Preflight Safety and PageBuilder Ownership Summary

**Load-fatal Craft target mismatches now stop at compile/live preflight, PageBuilder Matrix ownership is validated before propagation, and synthetic transform fixtures capture the rehearsal gap shapes.**

## Performance

- **Duration:** 7 min
- **Started:** 2026-04-28T15:51:14Z
- **Completed:** 2026-04-28T15:58:06Z
- **Tasks:** 3
- **Files modified:** 9 code/test files + 1 summary

## Accomplishments

- Classified invalid section/entry-type target states as load-fatal while preserving advisory warning-only behavior for missing fields, adapter availability, disallowed asset volumes, and disallowed relation sources.
- Made `kunstmaan-migrator/compile` print fatal target validation separately and return `ExitCode::CONFIG` before persisting invalid compiled mappings.
- Extended `MigrateController::preflightCompiledMapping(...)` so `migrate` and `migrate/load` block stale fatal compiled mappings against the live Craft target schema before extract/load.
- Added PageBuilder ownership validation in `MappingCompiler` so `pageBuilderHandle` is propagated only when the parent entry type owns the target Matrix field.
- Preserved source content when an invalid PageBuilder target has a flat fallback, and emitted visible operator-review warnings when no fallback exists.
- Added synthetic structural regression coverage for missing Matrix native title shape, sparse-locale primary-save shape, invalid section/entry-type target shape, and taxonomy relation-before-state shape.

## Task Commits

Each task was committed atomically:

1. **Task 1: Classify load-fatal Craft target validation** - `fc7ceaa` (feat)
2. **Task 2: Validate PageBuilder Matrix ownership before propagation** - `be3cd0b` (feat)
3. **Task 3: Add structural regression fixtures** - `80dd9e8` (test)

Additional corrective commit:

- `c91cce2` (test) - kept the Task 1 fatal-preflight fixture generic after the genericity scan flagged a rehearsal-specific class name in the test fixture.

**Plan metadata:** pending final metadata commit.

## Files Created/Modified

- `src/compile/CraftTargetIntrospector.php` - adds severity-classified validation via `validateWithSeverity(...)`; section/entry-type target failures are fatal.
- `src/console/CompileController.php` - prints fatal target validation separately and exits non-zero before writing mapping.yaml.
- `src/console/MigrateController.php` - runs fatal target validation in compiled mapping preflight for live migrate/load protection and adds a migrate-local Craft target schema builder.
- `src/compile/MappingCompiler.php` - validates PageBuilder Matrix ownership before propagating `pageBuilderHandle` for regular and implicit page-part paths.
- `tests/unit/compile/CraftTargetIntrospectorTest.php` - covers fatal target classification and preserved advisory warnings.
- `tests/unit/console/MigrateControllerCompilePreflightTest.php` - updates compiled preflight expectations for the new fatal result shape.
- `tests/unit/console/MigrateControllerFailureExitTest.php` - covers migrate preflight blocking load-fatal target validation.
- `tests/unit/compile/MappingCompilerValidationTest.php` - covers valid propagation, invalid ownership with flat fallback, and invalid ownership without fallback.
- `tests/integration/transform/TransformCharacterizationTest.php` - adds synthetic structural fixtures for the Phase 10 rehearsal failure categories.
- `.planning/phases/10-generic-migration-rehearsal-gap-closure/10-01-SUMMARY.md` - execution summary.

## Decisions Made

- **Fatal target boundary:** section/entry-type incompatibility, missing sections, and missing entry types are fatal because they can cause live `saveElement()` failure. Advisory target issues remain warning-only.
- **No broad fallback:** the implementation does not introduce default section/default entry-type routing. Invalid mappings are blocked or surfaced rather than silently rerouted.
- **Flat fallback semantics:** invalid PageBuilder ownership keeps `flatPagePartContent` when available; without a flat fallback, compile emits a visible operator-review warning rather than silently dropping content.
- **Synthetic fixtures only:** regression coverage uses structural placeholder entity names and values, not CQM content or proprietary rehearsal rows.

## Verification

Commands run:

```bash
vendor/bin/phpunit tests/unit/compile/CraftTargetIntrospectorTest.php tests/unit/console/MigrateControllerFailureExitTest.php --testdox
vendor/bin/phpunit tests/unit/compile/MappingCompilerValidationTest.php tests/unit/compile/MappingCompilerPageRelationClosureTest.php --testdox
vendor/bin/phpunit tests/integration/transform/TransformCharacterizationTest.php --testdox
```

Results:

- Target/preflight suite: **10 tests, 31 assertions, exit 0**; PHPUnit reported the pre-existing no-code-coverage warning and one existing deprecation.
- MappingCompiler suite: **9 tests, 37 assertions, exit 0**; PHPUnit reported the pre-existing no-code-coverage warning.
- Transform characterization suite: **5 tests, 15 assertions, exit 0**; one sentinel fixture test skipped because no private transform fixture corpus is committed, with the pre-existing no-code-coverage warning/deprecation.

Additional checks:

- PHP syntax checks passed for modified production files.
- Diff scan found no newly introduced CQM-specific page IDs, block handles, or class-name conditionals.
- `NeverProductionTrait` was not removed or bypassed.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Removed rehearsal-specific class name from a new unit fixture**
- **Found during:** Overall verification after Task 3
- **Issue:** The new `MigrateControllerFailureExitTest` initially used a rehearsal-specific page-class fixture name while the plan and project constraints require generic production/test coverage.
- **Fix:** Renamed the fixture to `App\\Entity\\StructuralFormPage` while preserving the same invalid `contentPages` + `formContentBlock` structural target shape.
- **Files modified:** `tests/unit/console/MigrateControllerFailureExitTest.php`
- **Verification:** `vendor/bin/phpunit tests/unit/console/MigrateControllerFailureExitTest.php --testdox`; final diff scan found no newly introduced rehearsal-specific class names.
- **Committed in:** `c91cce2`

---

**Total deviations:** 1 auto-fixed (1 Rule 1 bug).
**Impact on plan:** No scope creep; the fix enforces the plan's genericity constraint.

## Issues Encountered

- `gsd-sdk` is not installed in this environment, so STATE/ROADMAP updates and final metadata commit were performed manually with normal git commands.
- PHPUnit consistently reports "No code coverage driver available"; this is a test-runner environment warning and did not fail any verification command.
- The transform characterization corpus sentinel remains skipped outside `RELEASE_REHEARSAL=1`, which is existing behavior and unrelated to the new synthetic structural tests.

## Known Stubs

| File | Line(s) | Reason |
|---|---:|---|
| `src/compile/MappingCompiler.php` | 481-496 | Existing operator-curated compiled mapping placeholders (`pageBuilderHandle`, `bodyColumn`, `headerBlock`, `bodyWrapBlock`, `joins`) are intentionally emitted as empty values so operators can see unmapped layout slots. This plan added ownership validation around propagation but did not remove the established placeholder pattern. |

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- Plan 10-02 can now rely on invalid section/entry-type pairs being blocked before live load while it implements Matrix native-title fallback and sparse-locale primary-save fallback.
- Plan 10-03 can use the structural taxonomy-before-state transform fixture as a regression signal while implementing page-rooted taxonomy lazy resolution.
- Plan 10-04 can verify that count-domain/report semantics run on top of stricter compile/preflight safety.

## Self-Check: PASSED

- Summary exists at `.planning/phases/10-generic-migration-rehearsal-gap-closure/10-01-SUMMARY.md`.
- Task commits exist: `fc7ceaa`, `be3cd0b`, `80dd9e8`, `c91cce2`.
- Verification commands listed above were run after the final corrective commit and exited 0.
- Modified production code remains generic and does not remove/bypass `NeverProductionTrait`.

---
*Phase: 10-generic-migration-rehearsal-gap-closure*
*Completed: 2026-04-28*
