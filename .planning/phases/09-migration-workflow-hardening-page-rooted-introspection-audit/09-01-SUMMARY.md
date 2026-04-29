---
phase: 09-migration-workflow-hardening-page-rooted-introspection-audit
plan: 01
subsystem: workflow-safety
tags: [mapping, migrate, compile, preflight, tdd]
requires: []
provides:
  - compiled mapping blocks survive analyze/proposal merges
  - migrate/load structural preflight for compiled runtime blocks
affects:
  - src/mapping/MappingFile.php
  - src/console/MigrateController.php
tech_stack:
  added: []
  patterns:
    - TDD red/green commits for regression coverage
    - private pure helper exercised through reflection
key_files:
  created:
    - tests/unit/console/MigrateControllerCompilePreflightTest.php
  modified:
    - src/mapping/MappingFile.php
    - tests/unit/mapping/MappingFileTest.php
    - src/console/MigrateController.php
decisions:
  - "Compiled-block preflight is structural and deterministic; migrate tells operators to run compile explicitly instead of auto-compiling."
  - "Optional compiled blocks are required only when accepted proposal rows imply their runtime use."
metrics:
  duration: "00:04:10"
  completed_at: "2026-04-28T11:18:00Z"
  tasks_completed: 3
  files_changed: 4
---

# Phase 9 Plan 01: Workflow Safety Summary

Preserved compiled mapping state across analyze reruns and added a compile-before-migrate guard so incomplete runtime mappings fail with actionable operator guidance.

## Tasks Completed

| Task | Name | Status | Commit |
|---|---|---:|---|
| 1 | Preserve compiled mapping blocks during proposal merge | Complete | `088432c`, `02b892b` |
| 2 | Add migrate compiled-block preflight | Complete | `ef2b59a`, `25a7ce9`, `5991ebe` |
| 3 | Run focused workflow regression checks | Complete | `a7b2520` |

## Implementation Notes

- `MappingFile::merge()` now starts from the full existing mapping array, replaces only `proposals`, and returns all sibling top-level blocks unchanged.
- The merge regression covers `nodeClasses`, `sections`, `sites`, `pageParts`, `taxonomies`, `dataProviders`, `_compileReport`, and an unknown audit block.
- `MigrateController` now checks loaded `mapping.yaml` structures before `actionIndex()` extracts and before `actionLoad()` loads transformed payloads.
- Missing base compiled blocks (`nodeClasses`, `sections`, `sites`) fail with `ExitCode::CONFIG`.
- Accepted `kind=pagePart`, `kind=taxonomy`, and `kind=dataProvider` rows require corresponding `pageParts`, `taxonomies`, and `dataProviders` compiled blocks.
- Failure copy names the missing blocks and tells the operator to run `./craft kunstmaan-migrator/compile`.

## Verification

| Command | Result |
|---|---|
| `vendor/bin/phpunit tests/unit/mapping/MappingFileTest.php --filter 'Merge' --testdox` | Passed: 4 tests / 18 assertions; PHPUnit warning: no code coverage driver |
| `vendor/bin/phpunit tests/unit/console/MigrateControllerCompilePreflightTest.php --testdox` | Passed: 4 tests / 13 assertions; PHPUnit warning: no code coverage driver; 1 PHPUnit deprecation |
| `composer test-unit` | Passed: 411 tests / 1184 assertions; PHPUnit warning: no code coverage driver; 1 PHPUnit deprecation |

## Deviations from Plan

None - plan executed as written.

## Auth Gates

None.

## Known Stubs

None found in files created or modified by this plan.

## Threat Flags

None. The modified trust-boundary surfaces (`MappingFile::merge()` and `MigrateController` mapping preflight) were already covered by the plan threat model.

## Deferred Issues

- PHPUnit reports that no code coverage driver is available in this environment.
- PHPUnit reports 1 deprecation in the focused/full unit runs; it does not block the suite.

## Commits

- `088432c` — `test(09-01): add failing merge preservation coverage`
- `02b892b` — `feat(09-01): preserve mapping blocks during merge`
- `ef2b59a` — `test(09-01): add failing migrate compile preflight coverage`
- `25a7ce9` — `feat(09-01): fail migrate on missing compiled blocks`
- `a7b2520` — `chore(09-01): verify workflow hardening regression suite`
- `5991ebe` — `refactor(09-01): avoid deprecated reflection accessibility call`

## Self-Check: PASSED

- Created/modified files exist:
  - `src/mapping/MappingFile.php`
  - `tests/unit/mapping/MappingFileTest.php`
  - `src/console/MigrateController.php`
  - `tests/unit/console/MigrateControllerCompilePreflightTest.php`
- Commits exist in git history:
  - `088432c`
  - `02b892b`
  - `ef2b59a`
  - `25a7ce9`
  - `a7b2520`
  - `5991ebe`
