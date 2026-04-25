---
phase: 01-foundation-connectivity
plan: 04
subsystem: foundation
tags: [doctor, preflight, diagnostics, never-production, anthropic, storage-dir, console-controller]

# Dependency graph
requires:
  - "01-01: NeverProductionTrait + Plugin entrypoint (PSR-4 autoload contract)"
  - "01-02: Settings.anthropicApiKey property + LegacyDbService::queryOne + Plugin::init() with console controllerNamespace switch"
  - "01-03: established pattern of NeverProduction-as-first-statement on controller actions (D-20)"
provides:
  - "DoctorController (lameco\\kunstmaanmigrator\\console\\DoctorController) — 3 preflight checks per D-17"
  - "kunstmaan-migrator/doctor CLI route — operator preflight before analyze (Phase 2)"
  - "checkLegacyDb / checkApiKey / checkStorageDir private methods — explicitly named contract for Plan 05 PHPUnit reflection tests"
  - "storage/migration/ auto-create behavior (D-18) on first doctor invocation — greenfield convenience"
affects: [01-05-tests-ci, all-phase-2-plus]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "&&-against-$ok orchestration: every check executes even after a prior failure ($ok = $this->checkX() && $ok), so operators get the full report rather than a short-circuited tail"
    - "Presence-only credential reporting: anthropicApiKey is converted to a $hasKey boolean via !== '' comparison and used ONLY in branch selection — value never appears in stdout/stderr (T-1-03 mitigation)"
    - "Auto-create-then-verify-writable for storage directories: !is_dir → @mkdir(0755, recursive) → is_writable, with operator-readable FAIL messages at each branch"
    - "Plain-text OK/FAIL output with two-space indent + ANSI colors (Console::FG_GREEN / FG_RED), summary line in Console::FG_CYAN header"

key-files:
  created:
    - src/console/DoctorController.php
  modified: []

key-decisions:
  - "D-17 honored: 3 checks ship in Phase 1 (legacyDb, anthropicApiKey, storageDir). The mapping-file check listed in REQUIREMENTS.md CONN-03 defers to Phase 2 alongside the mapping loader/validator. Plan 05 patches CONN-03 wording."
  - "D-18 honored: storage/migration/ is auto-created with mode 0755 if absent. v1.x had no equivalent — this is a greenfield convenience that removes one manual op step before the first analyze."
  - "D-19 honored: plain-text OK/FAIL output (two-space indent, ANSI colors) instead of v1's heavier output style. Exit code is 0 on full pass, 1 on any FAIL. Single FAIL doesn't short-circuit — every check runs."
  - "D-20 honored: actionIndex's first statement is the NeverProduction gate (early-return when env=production). Combined with Plan 03's MigrateController::actionInstall guard, FND-04 is satisfied across all Phase 1 controllers."
  - "T-1-01 mitigation verified: enforceNeverProduction() is the head of actionIndex; verified by acceptance-criteria ordering grep (awk first 8 lines of actionIndex match 'enforceNeverProduction')."
  - "T-1-03 mitigation verified: $fromSettings and $fromEnv collapse into a $hasKey boolean; OK/FAIL strings are static literals with no value interpolation. Verified by negative grep against echo/var_dump/print_r/stdout-of-fromSettings/stdout-of-fromEnv patterns."
  - "T-1-04 mitigation verified: storage path is constrained to Craft::\\$app->path->getStoragePath() . '/migration' — no user input feeds the path; mkdir cannot escape the storage tree because getStoragePath() returns an absolute Craft-owned path."
  - "T-1-08 accepted: $e->getMessage() from a failing legacyDb connection is echoed to stderr. Yii's Connection exceptions carry host/port/database but NOT password. Operators need to see WHAT broke; full opacity defeats the doctor's purpose. Revisit in Phase 5 hardening if Yii's exception shape changes."
  - "Drops from v1 enforced by negative greps: checkQueueWorker (PROJECT.md Key Decisions — v2 is CLI-inline by default) and checkMapping (D-17 — Phase 2 owns it). Imports for craft\\queue\\Queue and PingJob also dropped."

patterns-established:
  - "DoctorController shape: NeverProduction guard → header line → checks chained via &&-against-$ok → summary line → ExitCode return. Future preflight checks (e.g., Phase 2 mapping-file check) plug into this chain by adding one $ok = $this->checkX() && $ok line."
  - "Presence-only secret reporting: when reporting on the configured state of a credential, report PRESENCE not VALUE. The check function reads the value, computes a boolean, and discards the value — the OK/FAIL string is a static literal."
  - "Auto-create-then-verify-writable for plugin-owned directories: mkdir(0755, recursive) with @-suppression + is_dir() race-tolerance, followed by an explicit is_writable() check. Each branch emits an operator-readable FAIL message naming the offending path."

requirements-completed: [FND-04, CONN-03]

# Metrics
duration: 6min
completed: 2026-04-25
---

# Phase 1 Plan 4: Doctor Command Summary

**Three-check preflight CLI (legacy DB reachability, Anthropic key presence, storage/migration writability) gated on NeverProductionTrait, with auto-create-and-verify behavior for the storage directory and presence-only credential reporting.**

## Performance

- **Duration:** ~6 min
- **Started:** 2026-04-25T16:00:00Z (approx)
- **Completed:** 2026-04-25T16:06:16Z
- **Tasks:** 1
- **Files created:** 1

## Accomplishments

- `kunstmaan-migrator/doctor` CLI command lands and autoloads cleanly under PSR-4.
- All three preflight checks per D-17 implemented: `checkLegacyDb()`, `checkApiKey()`, `checkStorageDir()`.
- NeverProduction guard wired as the first statement of `actionIndex()` — FND-04 enforcement is now active across both Phase 1 controllers (DoctorController + MigrateController).
- D-18 auto-create-with-0755 behavior for `storage/migration/` removes one manual op step before the first `analyze` invocation.
- T-1-01, T-1-03, and T-1-04 STRIDE mitigations all verified by acceptance-criteria greps; T-1-08 accepted with documented rationale.
- v1's `checkQueueWorker()` (CLI-inline default per PROJECT.md Key Decisions) and `checkMapping()` (defers to Phase 2 per D-17) explicitly dropped — verified by negative greps in acceptance criteria.

## Task Commits

Each task was committed atomically:

1. **Task 1: Write DoctorController with three preflight checks + NeverProduction guard** - `ea24a39` (feat)

_Plan metadata commit (this SUMMARY + STATE + ROADMAP) follows separately._

## Files Created/Modified

- `src/console/DoctorController.php` — new console controller. `actionIndex` orchestrates three checks; each check is a private method returning bool. Exits 0 on full pass, 1 on any FAIL. Uses `Console::FG_GREEN` / `Console::FG_RED` / `Console::FG_CYAN` for ANSI styling.

## Decisions Made

None new — all decisions (D-14, D-17, D-18, D-19, D-20) were settled during Phase 1 context gathering and re-affirmed during planning. This plan executed them.

## Deviations from Plan

None — plan executed exactly as written. The paste-ready file content in the PLAN's `<action>` block was used verbatim; all 14 acceptance-criteria greps and the reflection probe pass on first try.

## Issues Encountered

- One Craft-internal PHP deprecation surfaces on every reflection probe (`craft\console\Controller::output()`: implicitly nullable `$string` parameter). This is upstream Craft 5 behavior under PHP 8.4 — not caused by this plan and not actionable here. Logged but not fixed (out of scope per the SCOPE BOUNDARY rule).

## TDD Gate Compliance

N/A — this plan is `type: execute` (not `type: tdd`). Behavioral verification is deferred to Plan 05's PHPUnit suite, which will cover the controller class shape via reflection and exercise the check methods against a fixture site.

## Verification Evidence

- **Lint:** `php -l src/console/DoctorController.php` → "No syntax errors detected".
- **Reflection probe:** all four expected methods present (`actionIndex`, `checkLegacyDb`, `checkApiKey`, `checkStorageDir`); both dropped methods (`checkQueueWorker`, `checkMapping`) absent.
- **All 14 acceptance-criteria greps:** OK (namespace, class, trait import, methods present, dropped methods absent, dropped imports absent, actionIndex gated, three-check chain, SELECT 1, getStoragePath path, mkdir 0755, T-1-03 secret-leak, exit codes, color counts).
- **Color-count verification:** `Console::FG_GREEN` appears 4 times (≥ 3 per verify clause), `Console::FG_RED` appears 6 times (≥ 3).
- **Visual ordering check:** in `actionIndex()`, the order is NeverProduction → header → checkLegacyDb → checkApiKey → checkStorageDir → summary, as required.

End-to-end functional verification (running `./craft kunstmaan-migrator/doctor` against a Craft-bootstrapped fixture site) is deferred to Plan 05 / phase verifier per the plan's verification clause.

## Self-Check: PASSED

- `src/console/DoctorController.php` — FOUND on disk
- `.planning/phases/01-foundation-connectivity/01-04-doctor-command-SUMMARY.md` — FOUND on disk
- Commit `ea24a39` (Task 1) — FOUND in git history

## Next Phase Readiness

- Phase 1 doctor command is complete; CONN-03 partially satisfied (3 checks ship per D-17; mapping check defers to Phase 2).
- FND-04 fully satisfied across Phase 1 — both legacy-reading controller actions (DoctorController::actionIndex + MigrateController::actionInstall) gate on `enforceNeverProduction()` first.
- Plan 05 (tests-ci-docs) is unblocked. It will (a) add PHPUnit coverage for the controller class shape via reflection, (b) wire the `composer test` script + GitHub Actions workflow, and (c) patch REQUIREMENTS.md CONN-03 wording to acknowledge the 3-vs-4 check delta.
- No blockers.

---
*Phase: 01-foundation-connectivity*
*Completed: 2026-04-25*
