---
phase: 09-migration-workflow-hardening-page-rooted-introspection-audit
plan: 07
subsystem: release-docs-ci-genericity
tags: [docs, ci, phpunit, source-shape-audit, release-gates]
requires:
  - "09-01 workflow safety and compile preflight"
  - "09-04 Page-rooted coverage artifact"
  - "09-05 truthful failure/preload semantics"
  - "09-06 CKEditor marker safety"
provides:
  - "Canonical doctor -> analyze -> map -> compile -> migrate --dry-run -> migrate --live -> verify docs"
  - "Structural-only source-shape audit CLI and regression guard"
  - "Release-mode transform fixture guard"
  - "Scratch-Craft CI smoke wording/behavior aligned with missing runtime config"
affects:
  - README.md
  - .planning/PROJECT.md
  - CHANGELOG.md
  - .planning/RELEASE-CHECKLIST.md
  - .github/workflows/ci.yml
  - tools/audit-source-shapes.php
  - tests/integration/GenericitySourceShapeAuditTest.php
  - tests/integration/transform/TransformCharacterizationTest.php
  - tests/integration/transform/TransformCharacterizationReleaseGuardTest.php
  - .planning/rehearsal/v1.0/cqm/README.md
  - .planning/rehearsal/v1.0/simac/README.md
  - .planning/rehearsal/v1.0/enreach/README.md
tech_stack:
  added: []
  patterns:
    - "Regex-only structural source-shape sampling with no source body output"
    - "Release-only fixture guard via RELEASE_REHEARSAL=1"
    - "CI smoke treats missing scratch runtime config as expected plugin-load evidence"
key_files:
  created:
    - tools/audit-source-shapes.php
    - tests/integration/GenericitySourceShapeAuditTest.php
    - tests/integration/transform/TransformCharacterizationReleaseGuardTest.php
  modified:
    - README.md
    - .planning/PROJECT.md
    - CHANGELOG.md
    - .planning/RELEASE-CHECKLIST.md
    - .github/workflows/ci.yml
    - tests/integration/transform/TransformCharacterizationTest.php
    - .planning/rehearsal/v1.0/cqm/README.md
    - .planning/rehearsal/v1.0/simac/README.md
    - .planning/rehearsal/v1.0/enreach/README.md
decisions:
  - "Keep Simac and Enreach as source-shape samples only for v1.0; no Craft target is required unless separately configured."
  - "Use a structural-only CLI audit instead of committing any proprietary source snippets or row/content samples."
  - "Keep normal developer transform characterization runs skippable when fixtures are absent, but fail release runs with RELEASE_REHEARSAL=1."
metrics:
  duration: "not recorded"
  completed_at: "2026-04-28T12:33:42Z"
  tasks_completed: 3
  files_changed: 13
---

# Phase 9 Plan 07: Docs, CI, Release, Fixture, and Genericity Evidence Summary

Release documentation, CI semantics, fixture gates, and genericity evidence now match the hardened Page-rooted migration workflow without exposing proprietary source data.

## Tasks Completed

| Task | Name | Status | Commit |
|---|---|---:|---|
| 1 | Update operator docs and release checklist | Complete | `0b90867` |
| 2 RED | Add structural genericity sampling coverage | Complete | `b0a1693` |
| 2 GREEN | Implement structural source-shape audit and rehearsal docs | Complete | `20821fd` |
| 3 RED | Add transform release fixture guard coverage | Complete | `10db911` |
| 3 GREEN | Align CI smoke and fixture/release gates | Complete | `70cdc74` |

## Implementation Notes

- Updated README, PROJECT, CHANGELOG, and release checklist to teach the canonical workflow: `doctor -> analyze -> map -> compile -> migrate --dry-run -> migrate --live -> verify`.
- Documented that Kunstmaan Page is the source root and Craft Entry is the result.
- Documented `PAGE-ROOTED-COVERAGE` categories: `migrated`, `dropped`, `out_of_scope`, `unsupported`, and `warning`.
- Clarified that `--preload-assets` is referenced-assets-only and does not import orphan media by default.
- Added `tools/audit-source-shapes.php`, a structural-only source-shape sampler for CQM/Simac/Enreach-style source trees.
- Added regression coverage proving the source-shape audit does not emit method bodies or property values from source files.
- Updated rehearsal docs so CQM is the executable Craft rehearsal target, while Simac and Enreach are structural source-shape samples only unless separately configured.
- Added a release-mode transform characterization guard: `RELEASE_REHEARSAL=1` fails loudly when the fixture corpus is empty, while normal developer runs still skip the empty sentinel.
- Updated CI smoke wording/commands so scratch-Craft CI proves plugin install/command-load behavior and treats missing migration runtime config as expected, rather than claiming a successful migration rehearsal.

## Files Changed

- `README.md`
- `.planning/PROJECT.md`
- `CHANGELOG.md`
- `.planning/RELEASE-CHECKLIST.md`
- `.github/workflows/ci.yml`
- `tools/audit-source-shapes.php`
- `tests/integration/GenericitySourceShapeAuditTest.php`
- `tests/integration/transform/TransformCharacterizationTest.php`
- `tests/integration/transform/TransformCharacterizationReleaseGuardTest.php`
- `.planning/rehearsal/v1.0/cqm/README.md`
- `.planning/rehearsal/v1.0/simac/README.md`
- `.planning/rehearsal/v1.0/enreach/README.md`

## Decisions Made

- CQM remains the only v1.0 executable rehearsal target because it has the configured Craft target.
- Simac and Enreach are used as read-only source-shape samples for genericity evidence; the docs do not require Simac/Enreach Craft targets.
- Structural genericity evidence may include counts, class names, table names, relation types, relation metadata presence, and risk flags only.
- The release fixture gate is opt-in through `RELEASE_REHEARSAL=1` so local contributor test runs do not require private CQM fixtures.

## Deviations from Plan

None - plan executed as written.

## Auth Gates

None.

## TDD Gate Compliance

- Task 2 RED commit: `b0a1693` added failing `GenericitySourceShapeAuditTest` coverage before `tools/audit-source-shapes.php` existed.
- Task 2 GREEN commit: `20821fd` implemented the structural-only audit tool and docs.
- Task 3 RED commit: `10db911` added failing release fixture guard coverage before `RELEASE_REHEARSAL` handling existed.
- Task 3 GREEN commit: `70cdc74` implemented the release-mode guard and aligned CI smoke semantics.

## Verification

| Command | Result |
|---|---|
| `vendor/bin/phpunit tests/integration/GenericitySourceShapeAuditTest.php --testdox` | Passed: 1 test / 15 assertions; PHPUnit warning: no code coverage driver |
| `vendor/bin/phpunit tests/integration/transform/TransformCharacterizationTest.php --testdox` | Passed with expected empty-corpus skip; PHPUnit warning: no code coverage driver; PHPUnit deprecation reported by the suite |
| `vendor/bin/phpunit tests/integration/transform/TransformCharacterizationTest.php tests/integration/GenericitySourceShapeAuditTest.php --testdox` | Passed: 2 tests / 15 assertions, 1 expected skip; PHPUnit warning: no code coverage driver; PHPUnit deprecation reported by the suite |
| `vendor/bin/phpunit tests/integration/transform/TransformCharacterizationReleaseGuardTest.php --testdox` | Passed: 1 test / 4 assertions; PHPUnit warning: no code coverage driver |
| `RELEASE_REHEARSAL=1 vendor/bin/phpunit tests/integration/transform/TransformCharacterizationTest.php --testdox` | Expected failure with empty fixture corpus; verified exit 1 and loud message |
| `grep -R "kunstmaan-migrator/compile" README.md .planning/PROJECT.md .planning/RELEASE-CHECKLIST.md` | Passed |
| `grep -R "PAGE-ROOTED-COVERAGE" README.md .planning/RELEASE-CHECKLIST.md` | Passed |
| `composer test` | Passed: 460 tests / 1506 assertions; PHPUnit warning: no code coverage driver; 1 deprecation; 1 PHPUnit deprecation; 1 expected skipped empty transform fixture; 1 pre-existing incomplete taxonomy test |

## Issues Encountered

- PHPUnit continues to report the environment's standard "No code coverage driver available" warning.
- The broader suite reports one deprecation, one PHPUnit deprecation, one expected empty transform fixture skip, and one pre-existing incomplete taxonomy integration test.
- `RELEASE_REHEARSAL=1` intentionally fails locally because no private CQM transform fixtures are committed in this checkout.

## Known Stubs

None introduced. Stub-pattern review found only intentional empty-array/null guards, fixture empty-corpus sentinels, and documentation references to future/out-of-scope work.

## Threat Flags

None. The new source-shape audit file-access surface is explicitly covered by this plan's threat model and emits structural metadata only.

## Deferred Issues

None.

## Self-Check: PASSED

- Found created files: `tools/audit-source-shapes.php`, `tests/integration/GenericitySourceShapeAuditTest.php`, `tests/integration/transform/TransformCharacterizationReleaseGuardTest.php`, and this summary.
- Found task commits: `0b90867`, `b0a1693`, `20821fd`, `10db911`, `70cdc74`.
