---
phase: 05-tests-rehearsal-release
fixed_at: 2026-04-27
review_path: .planning/phases/05-tests-rehearsal-release/05-REVIEW.md
iteration: 1
findings_in_scope: 3
fixed: 3
skipped: 0
status: all_fixed
---

# Phase 5: Code Review Fix Report

**Fixed at:** 2026-04-27
**Source review:** `.planning/phases/05-tests-rehearsal-release/05-REVIEW.md`
**Iteration:** 1

**Summary:**

- Findings in scope: 3 (HIGH + MEDIUM under `critical_warning` filter; HIGH count was 0, MEDIUM count was 3)
- Fixed: 3
- Skipped: 0
- LOW (4) and INFO (2) findings are out of scope for this iteration and remain documented in REVIEW.md.

The full PHPUnit suite (331 tests, 891 assertions) passed after each individual fix; no regressions introduced.

## Fixed Issues

### MEDIUM-1: Zero-statement files report 100% coverage

**File modified:** `tools/check-coverage.php`
**Commit:** `7dec5ee`
**Applied fix:** Replaced the `$statements === 0 ? 100.0 : ...` ternary with an explicit `SKIP` branch. Files with zero executable statements (interface-only, abstract, declaration-only) now print a `SKIP  ----   <path> (zero statements)` line and `continue` past the threshold check, instead of being silently coerced to 100% and counted toward the gate. The `$rowsPrinted` counter is still incremented so the "no TST-01 modules found" guard at line 64 keeps working when only zero-statement files match.

### MEDIUM-2: `craftcms/craft` not pinned in CI smoke job

**File modified:** `.github/workflows/ci.yml`
**Commit:** `16774cf`
**Applied fix:** Pinned `composer create-project` to `"craftcms/craft:^5.0"` (quoted to keep YAML happy). Added a comment explaining the constraint matches the plugin's `craftcms/cms ^5.0` requirement so the smoke job won't silently install Craft 6 the moment it ships. YAML parses cleanly (verified via Ruby YAML.safe_load).

### MEDIUM-3: `mkdir(0755)` without umask handling

**File modified:** `tools/capture-transform-fixtures.php`
**Commit:** `75e9da0`
**Applied fix:** Wrapped both `mkdir(..., 0755, true)` call sites (mapping-snapshot dir at line 90, per-entity input dir at line 162) with `umask(0022)` set/restore. The previous umask is captured into a local (`$prevUmask` / `$prevUmaskMk`) and restored on every exit path — success, the FAIL `exit(2)` branches, and the normal fall-through. This ensures the requested 0755 mode survives unchanged regardless of the operator's shell `umask` setting, so committed fixture directories stay non-group-writable. The script is operator-only and not exercised by `composer test`, so test coverage is unchanged; full suite still passes.

---

_Fixed: 2026-04-27_
_Fixer: Claude (gsd-code-fixer), iteration 1_
