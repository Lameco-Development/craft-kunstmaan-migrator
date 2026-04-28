---
phase: 11-relation-target-introspection-promotion
plan: 05
subsystem: graph-compatibility-validation
tags: [compile, validation, audit, promoted-targets]
key-files:
  - src/compile/GraphCompatibilityValidator.php
  - src/compile/MappingCompiler.php
  - src/console/CompileController.php
  - src/mapping/MappingAuditor.php
  - tests/unit/compile/GraphCompatibilityValidatorTest.php
  - tests/unit/compile/MappingCompilerPromotedTargetsTest.php
metrics:
  focused_tests: 6
  compile_tests: 41
---

# Plan 11-05 Summary

## Commits

| Commit | Description |
|---|---|
| 65bcee5 | Add graph compatibility validation, promoted target compile output, and compile/audit wiring. |

## Work Completed

- Added `GraphCompatibilityValidator` for graph version checks, unknown source/target refs, field ownership, Matrix block compatibility, Entries relation targets, asset volume compatibility, and required relation intent coverage.
- Added compile support for accepted promoted/shared relation target proposal rows, including `stateSource`, `sourceRef`, `targetRef`, `targetSection`, `targetEntryType`, `relationIntent`, and fields.
- Added a compile-time graph validation gate in `CompileController` before executable mapping writes.
- Added `promotedTargets` ordering/persistence in compiled mapping output.
- Added an auditor adapter for graph-compatibility findings so unresolved relation evidence can be surfaced operator-facing while intentional `drop`/`out_of_scope` decisions remain distinguishable.
- Added focused validator and compiler tests plus full compile-suite regression coverage.

## Deviations

`GraphCompatibilityValidator` is invoked by `CompileController` rather than directly by `MappingCompiler`; `MappingCompiler` stays a pure transform and compiles promoted target contracts once graph proposals pass validation.

## Verification

- `vendor/bin/phpunit tests/unit/compile/MappingCompilerPromotedTargetsTest.php tests/unit/compile/GraphCompatibilityValidatorTest.php --testdox`
- `vendor/bin/phpunit tests/unit/compile --testdox`
- `php -l` on the touched compile/controller/auditor files.
- Plan acceptance greps for validator codes, compile wiring, `stateSource`, `promotedTargets`, `ExitCode::CONFIG`, and relation intent/drop terminology.

## Self-Check: PASSED

Plan 11-05 success criteria are met.
