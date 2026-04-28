---
phase: 09-migration-workflow-hardening-page-rooted-introspection-audit
plan: 03
subsystem: compile
tags: [php, phpunit, mapping-compiler, craft-schema, relation-validation]

requires:
  - phase: 08-taxonomies-and-proposers
    provides: "MappingCompiler runtime block emission, Matrix catalog, relation handler option conventions"
provides:
  - "Compile-time skip+warning validation for invalid handler/source/target combinations"
  - "Page-owned ManyToMany and OneToMany relation closure into relation handler options"
  - "CraftTargetIntrospector schema facade for compiled target validation"
affects: [09-04, migration-workflow-hardening, compile, target-schema-validation]

tech-stack:
  added: []
  patterns:
    - "Deterministic compile warnings for skipped invalid mappings"
    - "Schema-facade target introspection with fakeable test inputs"

key-files:
  created:
    - src/compile/CraftTargetIntrospector.php
    - tests/unit/compile/MappingCompilerValidationTest.php
    - tests/unit/compile/MappingCompilerPageRelationClosureTest.php
    - tests/unit/compile/CraftTargetIntrospectorTest.php
  modified:
    - src/compile/MappingCompiler.php
    - src/console/CompileController.php
    - src/Plugin.php

key-decisions:
  - "Keep compile hardening deterministic and schema-facade based; no project-specific Craft assumptions were added."
  - "Represent page-owned OneToMany relations as RelationHandler joinTable options over the child table keyed by the child back-reference."
  - "Keep Craft target validation warning-only at compile time so operators receive actionable feedback without schema mutation."

patterns-established:
  - "Invalid compile field specs return a warning string and are not emitted into nodeClasses fields."
  - "Craft target validation accepts normalized arrays so unit tests do not depend on a live CQM Craft install."

requirements-completed: [PH9-08, PH9-09, PH9-10]

duration: 5min
completed: 2026-04-28T11:26:24Z
---

# Phase 9 Plan 03: Compile Hardening and Craft Target Introspection Summary

**Compile now drops invalid field specs with deterministic warnings, closes page-owned relation options, and validates compiled targets against a fakeable Craft schema facade.**

## Performance

- **Duration:** 5 min
- **Started:** 2026-04-28T11:21:25Z
- **Completed:** 2026-04-28T11:26:24Z
- **Tasks:** 4/4 complete
- **Files modified:** 7

## Accomplishments

- Added TDD coverage for handler/source/target validation, page relation closure, and Craft target schema validation.
- Hardened `MappingCompiler` so scalar Matrix, arbitrary Dropdown, and under-specified Relation mappings warn and skip instead of emitting silent empty runtime fields.
- Added page-level relation closure for curated ManyToMany join metadata and OneToMany child back-reference metadata.
- Added `CraftTargetIntrospector` and wired it into the plugin/compile controller for deterministic post-compile target warnings.

## Task Commits

1. **Tasks 1-3 RED: compile hardening coverage** — `ce0f93b` (`test`)
2. **Tasks 1-3 GREEN: compile validation, relation closure, Craft target introspection** — `762eed6` (`feat`)
3. **Task 4: compile regression verification** — `64a945b` (`chore`, empty verification commit)

## Files Created/Modified

- `src/compile/MappingCompiler.php` — adds field-spec validation and page-owned relation option derivation.
- `src/compile/CraftTargetIntrospector.php` — new schema-facade validator for compiled sections, entry types, fields, Matrix targets, assets, Entries, SEOmatic, and Retour.
- `src/console/CompileController.php` — invokes target validation after compile and renders deterministic warnings.
- `src/Plugin.php` — registers and documents `craftTargetIntrospector`.
- `tests/unit/compile/MappingCompilerValidationTest.php` — invalid handler/source/target skip+warning tests.
- `tests/unit/compile/MappingCompilerPageRelationClosureTest.php` — page-level ManyToMany/OneToMany compile closure tests.
- `tests/unit/compile/CraftTargetIntrospectorTest.php` — fake-schema target introspection tests.

## Decisions Made

- Used warning-and-skip semantics for invalid field specs because emitting them into runtime blocks would recreate the silent empty-output failure mode.
- Used row-level `relation` metadata for page-owned ManyToMany/OneToMany closure, preserving existing explicit `handlerOptions` as operator-curated truth.
- Kept target introspection schema-array driven so tests remain generic and do not require a running Craft target.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Added plugin component registration for `CraftTargetIntrospector`**
- **Found during:** Task 3 (Validate compiled Craft targets against receiving schema)
- **Issue:** A new validator service would not be reachable from `CompileController` unless registered in the plugin component graph.
- **Fix:** Added import, `@property-read`, and component config entry in `src/Plugin.php`.
- **Files modified:** `src/Plugin.php`
- **Verification:** `composer test-unit` passed.
- **Committed in:** `762eed6`

**Total deviations:** 1 auto-fixed (Rule 3)
**Impact on plan:** Required for the planned controller wiring; no scope creep.

## Issues Encountered

- RED tests failed as expected before implementation.
- PHPUnit reports the repository's standard "No code coverage driver available" warning; this is non-blocking and pre-existing in local test runs.
- `composer test-unit` reports 1 deprecation while passing; unrelated to this plan.

## Verification

- `vendor/bin/phpunit tests/unit/compile/MappingCompilerValidationTest.php --testdox` — PASS
- `vendor/bin/phpunit tests/unit/compile/MappingCompilerPageRelationClosureTest.php --testdox` — PASS
- `vendor/bin/phpunit tests/unit/compile/CraftTargetIntrospectorTest.php --testdox` — PASS
- `vendor/bin/phpunit tests/unit/compile --testdox` — PASS (30 tests)
- `composer test-unit` — PASS (421 tests, 1222 assertions; 1 PHPUnit warning, 1 deprecation)

## Known Stubs

None introduced by this plan. Stub-pattern scan only found existing operator-visible compile placeholders and ordinary empty-array/null guard code.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

Plan 09-04 can consume deterministic compile warnings and the `CraftTargetIntrospector` seam when building downstream page-rooted audit evidence.

## Self-Check: PASSED

- Found created files: `src/compile/CraftTargetIntrospector.php`, `tests/unit/compile/MappingCompilerValidationTest.php`, `tests/unit/compile/MappingCompilerPageRelationClosureTest.php`, `tests/unit/compile/CraftTargetIntrospectorTest.php`, and this summary.
- Found task commits: `ce0f93b`, `762eed6`, `64a945b`.
