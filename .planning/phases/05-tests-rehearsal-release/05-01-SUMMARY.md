---
phase: 05-tests-rehearsal-release
plan: 01
subsystem: testing
tags: [phpunit, psr-4, git-mv, test-tiers]

# Dependency graph
requires:
  - phase: 01-foundation-connectivity
    provides: PHPUnit 11 wired (D-21) — single Unit suite with 179 tests at end of Phase 4.1
provides:
  - tests/unit/ tier (every previously-rooted test except PluginBootstrapTest)
  - tests/integration/ tier (currently just PluginBootstrapTest, unrun until 05-02 lands the Integration testsuite)
  - phpunit.xml.dist retargeted to tests/unit (single Unit suite preserved)
  - Inline gap comment in phpunit.xml.dist signalling 05-02 will add the Integration suite
affects: [05-02-phpunit-infrastructure, 05-03-transform-characterization-fixtures, 05-04-rehearsal-controller-and-dirs, 05-05-unit-tests-analyze-finalize, 05-06-unit-tests-field-handlers, 05-07-ci-smoke-job, 05-08-release-checklist-changelog-reconciliation]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Two-tier test layout: tests/unit/ (no Craft boot) + tests/integration/ (Craft-bootstrapped)"
    - "PSR-4 namespace mirrors directory: lameco\\kunstmaanmigrator\\tests\\unit\\<area> ↔ tests/unit/<area>"
    - "Capital-P preservation on tests/unit/Plugin/ (case-preserving git mv on case-insensitive APFS)"

key-files:
  created:
    - tests/unit/ (directory tree — 9 area subdirs + 2 top-level files moved in)
    - tests/integration/ (directory — PluginBootstrapTest moved in)
  modified:
    - phpunit.xml.dist (retarget <directory> + add D-13 gap comment)
    - tests/unit/**/*.php (30 files — namespace rewrites)
    - tests/integration/PluginBootstrapTest.php (namespace rewrite)
    - tests/unit/ComposerSuggestTest.php (Rule 1 path-relative fix)
    - tests/unit/Plugin/SettingsHtmlTest.php (Rule 1 path-relative fix)

key-decisions:
  - "D-12 + D-14 honored: this is the only plan in Wave 1; every other Phase 5 plan branches off after this lands"
  - "Single Unit testsuite preserved this plan; Integration suite deferred to 05-02 (D-13)"
  - "tests/bootstrap.php stays at root — shared by both tiers when 05-02 wires the Integration suite"
  - "Inline gap comment in phpunit.xml.dist makes the temporary integration-tier mismatch explicit"

patterns-established:
  - "Test reorganization protocol (D-12, D-14): one plan per wave for layout-changing moves; future moves follow the same git-mv-then-namespace-rewrite pattern from 05-PATTERNS.md"
  - "When relocating test files that read sibling repo files via __DIR__, the relative-path depth must be re-counted post-move (Rule 1 deviation pattern)"

requirements-completed: [TST-01]

# Metrics
duration: ~7 min
completed: 2026-04-27
---

# Phase 05 Plan 01: Tests Reorganization Summary

**Reorganized 179-test corpus into tests/unit/ + tests/integration/ tiers via 12 history-preserving git-mv operations, retargeted phpunit.xml.dist to tests/unit, and unblocked every downstream Phase 5 plan from a future merge-churn `git mv`.**

## Performance

- **Duration:** ~7 min
- **Started:** 2026-04-27T08:24:00Z (approximate)
- **Completed:** 2026-04-27T08:31:00Z
- **Tasks:** 2
- **Files modified:** 31 (1 phpunit.xml.dist + 30 test files with namespace rewrites; plus 30 file renames recorded as R-prefix)

## Accomplishments

- **D-12 layout shipped:** Every previously-rooted test now lives under tests/unit/ (9 area subdirs + 2 top-level files) or tests/integration/ (1 file: PluginBootstrapTest). tests/bootstrap.php stayed at the root, shared by both tiers.
- **D-14 invariant honored:** This was the sole plan in Wave 1. No other Phase 5 plan can branch off until this lands on main, eliminating the merge churn from a late `git mv`.
- **PSR-4 invariant preserved:** All 30 moved test files carry namespace declarations that match their new directories. Capital P preserved on `tests\unit\Plugin` (PATTERNS risk callout #1).
- **phpunit.xml.dist retargeted:** Single `<directory>tests/unit</directory>` element under the still-singular Unit testsuite, with an inline gap comment documenting that 05-02 (D-13) lands the Integration suite.
- **Test corpus green:** `composer test` exits 0 with 176 tests / 485 assertions (down from 179 / 488 by exactly the 3 PluginBootstrapTest cases that moved to tests/integration/ and are unrun until 05-02 wires the Integration suite — exactly as predicted).
- **History continuity verified:** `git log --follow tests/unit/console/MigrateControllerSyncAssetsTest.php` resolves through the rename and continues into pre-Phase-5 history.

## Task Commits

Each task was committed atomically:

1. **Task 1: git mv every tests/<area>/ → tests/unit/<area> + 3 top-level files; create tests/integration/** — `f34619c` (chore)
2. **Task 2: Rewrite PSR-4 namespaces + retarget phpunit.xml.dist + composer dump-autoload + run tests** — `a425866` (chore)

The plan-metadata commit (this SUMMARY.md) is created by the orchestrator after the worktree merges.

## Files Created/Modified

- `tests/unit/` — new directory tree containing 9 area subdirs (`console/`, `filter/`, `load/`, `locale/`, `mapping/`, `models/`, `Plugin/`, `source/`, `verify/`) plus 2 top-level test files (`ComposerSuggestTest.php`, `NeverProductionTraitTest.php`)
- `tests/integration/PluginBootstrapTest.php` — only file in the new integration tier; unrun until 05-02 adds the Integration testsuite
- `phpunit.xml.dist` — `<directory>tests</directory>` retargeted to `<directory>tests/unit</directory>`; inline comment `<!-- Phase 5 / Plan 05-01: Integration testsuite added in Plan 05-02 (D-13). -->` added immediately above `<testsuites>`
- `tests/unit/**/*.php` — 30 files; namespace declarations rewritten to mirror new directory paths
- `tests/integration/PluginBootstrapTest.php` — namespace rewritten from `lameco\kunstmaanmigrator\tests` to `lameco\kunstmaanmigrator\tests\integration`
- `tests/unit/ComposerSuggestTest.php` — `__DIR__ . '/../composer.json'` → `__DIR__ . '/../../composer.json'` (one-level-deeper move broke the original relative path; Rule 1 auto-fix)
- `tests/unit/Plugin/SettingsHtmlTest.php` — `__DIR__ . '/../../src/...'` → `__DIR__ . '/../../../src/...'` (same Rule 1 cause; both `TWIG_PATH` and `PLUGIN_PATH` constants updated)
- `tests/bootstrap.php` — **unchanged** (stayed at root)
- `composer.json` — **unchanged** (PSR-4 prefix `lameco\kunstmaanmigrator\tests\` ⇒ `tests/` is recursive; `composer dump-autoload` refreshed the classmap with no warnings)
- `src/**` — **unchanged** (`git diff src/` returns empty; this plan touched zero source files)

## Decisions Made

- **No new tests added.** Plan 05-01 is a pure mechanical reorganization. Every Phase 5 unit/integration test addition lands in plans 05-02 through 05-08 under the new layout.
- **No second testsuite added in this plan.** D-13 hands the Integration suite addition to 05-02; the inline gap comment in `phpunit.xml.dist` flags the temporary mismatch.
- **Path-relative fixes treated as Rule 1 (Bug) deviations.** Both broken `__DIR__` paths were direct consequences of the move (the file's location changed; the relative path depth had to follow). Fixing them was in-scope for this plan.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Re-anchored two `__DIR__`-relative path constants whose depth changed with the move**
- **Found during:** Task 2 (post-move `composer test` run after the namespace rewrite)
- **Issue:** Two moved test files reached for sibling repo files via `__DIR__`-relative paths whose depth assumed the old `tests/<file>.php` or `tests/Plugin/<file>.php` location. After moving one level deeper into `tests/unit/...`, the paths resolved one directory above the repo root and `file_get_contents` returned `false`, cascading into 6 errors + 2 failures across `SettingsHtmlTest` (7 tests) and `ComposerSuggestTest` (1 test).
- **Fix:**
  - `tests/unit/ComposerSuggestTest.php`: `__DIR__ . '/../composer.json'` → `__DIR__ . '/../../composer.json'`
  - `tests/unit/Plugin/SettingsHtmlTest.php`: `TWIG_PATH` and `PLUGIN_PATH` constants gained one extra `../` segment
- **Files modified:** `tests/unit/ComposerSuggestTest.php`, `tests/unit/Plugin/SettingsHtmlTest.php`
- **Verification:** `composer test` exits 0 with 176/485 (was failing with 6 errors + 2 failures before the fix). `rg -n '__DIR__' tests/` audit confirmed no other moved files carry move-depth-sensitive paths (other `__DIR__` references either use ReflectionClass or build temp paths inside the test).
- **Committed in:** `a425866` (Task 2 commit, alongside the namespace rewrites)

---

**Total deviations:** 1 auto-fixed (1 bug)
**Impact on plan:** The move-induced path bug was a direct consequence of relocating the files; fixing it was strictly necessary to satisfy the plan's "all 179 existing tests pass post-move" success criterion (modulo the 3 PluginBootstrapTest cases that intentionally moved out of the Unit suite). No scope creep — both fixes are one-line `../` depth corrections, with no behavioral change to what the tests assert.

## Issues Encountered

- **`composer test` initially missing `vendor/bin/phpunit`.** The worktree's `vendor/` was not populated. Resolved by running `composer install` once at the start of Task 1's verification phase. Not a deviation — `composer install` was implicit in the plan's "run tests" step; just the worktree's first invocation.

## User Setup Required

None — no external service configuration required.

## Next Phase Readiness

Wave 2 (05-02 phpunit-infrastructure) is unblocked and can now:
- Add the second `<testsuite name="Integration"><directory>tests/integration</directory></testsuite>` element
- Remove the temporary gap comment
- Wire `<source>` and `<coverage>` blocks under the new layout

Waves 3 and 4 (characterization fixtures, unit-test gap-fill, rehearsal controller, CI smoke, release checklist) all branch off after 05-02 lands and write directly into `tests/unit/...` or `tests/integration/...` per the new convention. No further `git mv` operations are anticipated for the rest of Phase 5.

## Self-Check

- `git ls-files tests/ | grep -vE '^tests/(unit/|integration/|bootstrap\.php$)' | wc -l` = `0` (every test file lives under tests/unit/ or tests/integration/, plus the unchanged tests/bootstrap.php)
- `git ls-files tests/unit/ | wc -l` = `29` (≥ 14 required)
- `git ls-files tests/integration/` = `tests/integration/PluginBootstrapTest.php` (exactly 1)
- `git ls-files tests/unit/Plugin/` = `tests/unit/Plugin/SettingsHtmlTest.php` (capital-P preserved)
- `test -f tests/bootstrap.php` → 0 (root bootstrap untouched)
- `git status` showed every move as `R` (rename), confirming `git mv` was used
- `grep -E '^namespace ' tests/unit/Plugin/SettingsHtmlTest.php` = `namespace lameco\kunstmaanmigrator\tests\unit\Plugin;` (capital-P preserved in namespace)
- `grep -E '^namespace ' tests/integration/PluginBootstrapTest.php` = `namespace lameco\kunstmaanmigrator\tests\integration;`
- `grep -c '<directory>tests/unit</directory>' phpunit.xml.dist` = `1`
- `grep -c '<directory>tests</directory>' phpunit.xml.dist` = `0`
- `grep -c '<testsuite name=' phpunit.xml.dist` = `1` (single Unit suite preserved)
- `grep -cE '<source>|<coverage>' phpunit.xml.dist` = `0` (coverage scoping deferred to 05-02)
- `git log --oneline --follow tests/unit/console/MigrateControllerSyncAssetsTest.php` resolves through the rename into pre-Phase-5 history (proves rename detection)
- `git diff src/` is empty (no source modifications)
- `composer test` exits 0 with 176 tests / 485 assertions
- Commits exist: `f34619c` (Task 1) and `a425866` (Task 2) verified via `git log --oneline -3`

## Self-Check: PASSED

---
*Phase: 05-tests-rehearsal-release*
*Completed: 2026-04-27*
