---
phase: 05
plan: 04
subsystem: console / rehearsal
tags: [rehearsal, console-controller, mechanical-gate, ship-gate, TST-04, D-19, D-20, D-21, D-22, D-23, D-24]
dependency_graph:
  requires:
    - "src/Plugin.php controllerNamespace = lameco\\kunstmaanmigrator\\console (Phase 1 / D-03)"
    - ".planning/phases/05-tests-rehearsal-release/05-01-SUMMARY.md (tests/unit/console/ directory exists)"
  provides:
    - "kunstmaan-migrator/rehearsal/check <dir> CLI action — exit 0/1/2 mechanical gate over committed rehearsal artifacts"
    - "RehearsalController public static helpers (parseCountMatchBlock, findUnresolvedCkeditorTokens, parseAssetRcaTable, parseAllowTokens) — directly testable, no Reflection"
    - ".planning/rehearsal/v1.0/{cqm,simac,enreach}/ directory shape — operator capture target"
  affects:
    - "Plan 05-08 RELEASE-CHECKLIST step 5 (CQM rehearsal-check exit 0 = v1.0 tag gate)"
    - "Operator workflow: capture artifacts on dev host, commit alongside v1.0 tag PR"
tech_stack:
  added: []
  patterns:
    - "static-helper-extraction (PATTERNS Shared Patterns) — gate parsers as public static so tests skip Reflection"
    - "deliberate-trait-omission with prominent class-docblock disclaimer (D-22)"
    - "auto-discovery via Plugin's controllerNamespace (mirrors every other src/console/ controller; no controllerMap edit)"
key_files:
  created:
    - "src/console/RehearsalController.php (282 lines)"
    - "tests/unit/console/RehearsalControllerTest.php (235 lines, 17 tests)"
    - ".planning/rehearsal/v1.0/cqm/README.md (BLOCKING — v1.0 ship gate)"
    - ".planning/rehearsal/v1.0/simac/README.md (ADVISORY)"
    - ".planning/rehearsal/v1.0/enreach/README.md (ADVISORY)"
  modified: []
decisions:
  - "D-19 / D-23: CQM is the v1.0 ship gate; simac + enreach are advisory — codified in the BLOCKING vs ADVISORY headings of the three READMEs."
  - "D-21: Gates are mechanical — parsers operate over committed VERIFY.md / REPORT.md text; no live Craft state re-run inside rehearsal-check (CI-safe)."
  - "D-22: NeverProductionTrait deliberately OMITTED from RehearsalController; the class docblock prominently documents the omission. The controller is read-only over committed artifacts on disk and never opens the legacy DB / source path / state table."
  - "D-23: New controller (RehearsalController), not added to MigrateController (1700+ LOC — PATTERNS callout 6)."
  - "D-24: Real rehearsal artifacts (REPORT.md / VERIFY.md / baseline.json / doctor-output.txt / mapping-summary.txt) are NOT committed in this plan — operator captures on a dev host and commits separately, typically alongside the v1.0 tag PR."
  - "Auto-discovery: Plugin.php already sets controllerNamespace at line 203 (Phase 1 / D-03); RehearsalController in src/console/ registers automatically. No Plugin.php diff required (verified: git diff src/Plugin.php empty)."
  - "Test count: 17 (target was ~14, ±2 acceptable per acceptance criteria; came in at the high end after adding the distinct-sorted contract test for findUnresolvedCkeditorTokens)."
metrics:
  duration: "~25 minutes"
  completed: 2026-04-27
  tests_added: 17
  files_created: 5
  test_corpus_total: "193 tests / 508 assertions (was 176 / 485 — +17 / +23)"
---

# Phase 5 Plan 04: Rehearsal Controller and Dirs Summary

**One-liner:** `kunstmaan-migrator/rehearsal/check <dir>` ships as a deliberately-trait-omitted read-only console controller that mechanically asserts the three v1.0 ship gates (count tolerance, zero CKEditor tokens, full asset RCA) over committed rehearsal artifacts under `.planning/rehearsal/v1.0/{cqm,simac,enreach}/`.

## What Shipped

### 1. `src/console/RehearsalController.php` (Task 1, commit `fa00a2e`)

`actionCheck(string $dir)` reads `<dir>/VERIFY.md`, `<dir>/REPORT.md`, and the optional `<dir>/allow-tokens.txt`, runs the three gates, prints per-gate OK/FAIL with cyan/green/red coloring, and returns:

- `0` (`ExitCode::OK`) when all three gates pass
- `1` (`ExitCode::UNSPECIFIED_ERROR`) when any gate fails (per-gate failure list emitted on stderr)
- `2` when the directory or any required file is missing

Three public static gate parsers + one allow-list parser:

| Helper | Returns | Notes |
|---|---|---|
| `parseCountMatchBlock(string $verifyMd): list<string>` | failure messages | Tolerates `[1/2] Count-match gate` with or without `## ` prefix; block ends at next `#`-heading or `[2/2]` marker; OK/SKIP lines are passes; WARN/FAIL lines become `Count gate WARN: …` / `Count gate FAIL: …` strings; missing-section emits `Count gate section not found in VERIFY.md`. |
| `findUnresolvedCkeditorTokens(string $reportMd, array $allowedTokens = []): list<string>` | distinct sorted unallowed tokens | Patterns: `[NT<id>]`, `[M<id>]`, `asset:<n>` (\b boundary). |
| `parseAssetRcaTable(string $reportMd): list<string>` | failure messages | Empty section (CFG-07 italic placeholder copy starting with `_`) passes; rows with empty reason cell emit `Asset RCA row missing reason: <asset>`; missing-section emits `Asset RCA section not found in REPORT.md`. |
| `parseAllowTokens(string $contents): list<string>` | tokens | One-per-line; `#`-prefixed lines and blank lines skipped. |

**D-22 omission verified:** `grep -c "use NeverProductionTrait;" src/console/RehearsalController.php` returns `0`. The single occurrence of the string `NeverProductionTrait` is in the class docblock paragraph that explains why the trait is intentionally absent.

### 2. `tests/unit/console/RehearsalControllerTest.php` (Task 1, commits `cc44096` RED + `fa00a2e` GREEN)

17 tests, all passing, no Reflection, no Craft bootstrap:

- Gate 1 (parseCountMatchBlock): 5 cases — accepts all-OK, accepts SKIP, fails on WARN, fails on FAIL, missing section diagnostic
- Gate 2 (findUnresolvedCkeditorTokens): 7 cases — zero tokens, finds [NT…], finds [M…], finds asset:…, allow-list respected, allow-list comment-line skip, distinct-sorted contract
- Gate 3 (parseAssetRcaTable): 4 cases — populated table all-reasons-present, empty placeholder section passes, empty reason fails, missing section diagnostic
- parseAllowTokens: 1 case — blank/comment-line filtering

### 3. `.planning/rehearsal/v1.0/{cqm,simac,enreach}/README.md` (Task 2, commit `c9d5f63`)

Three operator-facing READMEs documenting the directory shape per D-19 / D-23 / D-24:

- `cqm/README.md` — **BLOCKING**: CQM rehearsal-check exit 0 is the v1.0 tag gate. Documents the six file sources (REPORT.md, VERIFY.md, baseline.json, doctor-output.txt, mapping-summary.txt, optional allow-tokens.txt), the gate command, exit codes, and the D-04 privacy note (private-repo-OK; scrub-pre-publish if the repo ever opens up).
- `simac/README.md` — **ADVISORY**: failures inform Phase 5.1 / NEXT-04, do not block tag.
- `enreach/README.md` — **ADVISORY**: 7-locale stress target; same advisory disposition.

No ghost placeholder REPORT.md / VERIFY.md / baseline.json files committed (would mis-trip Gate 1 with "Count gate section not found"). Operator captures and commits real artifacts on a dev host alongside the v1.0 tag PR.

## TDD Gate Compliance

Plan 05-04 Task 1 followed the TDD cycle:

- **RED gate:** commit `cc44096` — `test(05-04): add failing tests for RehearsalController gate parsers`. Verified failing: 17/17 errors with "Class … RehearsalController not found" before implementation.
- **GREEN gate:** commit `fa00a2e` — `feat(05-04): add RehearsalController with three mechanical gate parsers`. All 17 tests green; full corpus 193/193 passing.
- **REFACTOR gate:** none required — initial implementation cleared all assertions on the first run.

## Verification Results

| Check | Result |
|---|---|
| `php -l src/console/RehearsalController.php` | No syntax errors detected |
| `composer test` | 193 tests, 508 assertions, all OK (was 176 / 485 = +17 tests / +23 assertions) |
| `composer test --filter RehearsalControllerTest` | 17/17 OK (23 assertions) |
| `grep -c "use NeverProductionTrait;" src/console/RehearsalController.php` | 0 (D-22 verified) |
| `grep -c "NeverProductionTrait" src/console/RehearsalController.php` | 1 (docblock mention only) |
| `grep -c "enforceNeverProduction" src/console/RehearsalController.php` | 0 |
| `grep -c "public function actionCheck" src/console/RehearsalController.php` | 1 |
| `grep -c "public static function parseCountMatchBlock" src/console/RehearsalController.php` | 1 |
| `grep -c "public static function findUnresolvedCkeditorTokens" src/console/RehearsalController.php` | 1 |
| `grep -c "public static function parseAssetRcaTable" src/console/RehearsalController.php` | 1 |
| `grep -c "public function test" tests/unit/console/RehearsalControllerTest.php` | 17 (≥12 ✓) |
| `find .planning/rehearsal/ -type f -name 'README.md' \| wc -l` | 3 |
| `git diff src/Plugin.php` | empty (auto-discovery works) |
| `find .planning/rehearsal/v1.0/ -name 'REPORT.md' -o -name 'VERIFY.md' -o -name 'baseline.json' \| wc -l` | 0 (no ghost files) |

## Acceptance Smoke Test (Auto-Discovery)

The plan suggested running `./craft kunstmaan-migrator/rehearsal/check /tmp/no-such-dir` to confirm the action registers without a manual controllerMap edit. **This worktree has no Craft scratch site available** (the worktree contains only the plugin source), so the smoke is not executable here. The plan explicitly allows documenting this in the SUMMARY in lieu of running it.

What was verified instead:

- `src/Plugin.php` already declares `$this->controllerNamespace = 'lameco\\kunstmaanmigrator\\console';` at line 203 (Phase 1 / D-03), inside the `Craft::$app->request->getIsConsoleRequest()` guard.
- Every other controller in `src/console/` (AnalyzeController, DoctorController, MapController, MigrateController, VerifyController) auto-discovers via this same mechanism — they have no manual controllerMap registration anywhere in `src/Plugin.php`.
- `RehearsalController` lives at `src/console/RehearsalController.php` with namespace `lameco\kunstmaanmigrator\console`. By the same convention, Yii will discover it as `kunstmaan-migrator/rehearsal/...` and Yii's action-name convention maps `actionCheck` → `check`, with the `$dir` parameter binding positionally.
- `git diff src/Plugin.php` is empty, confirming no manual edit was needed.

The end-to-end smoke against a real Craft host is captured by Plan 05-08 RELEASE-CHECKLIST step 5 (CQM rehearsal-check on the operator's dev host).

## Carry-Over

- **Plan 05-08 (RELEASE-CHECKLIST):** Operator must capture CQM artifacts (REPORT.md, VERIFY.md, baseline.json, doctor-output.txt, mapping-summary.txt) on a dev host, commit them under `.planning/rehearsal/v1.0/cqm/`, and run `./craft kunstmaan-migrator/rehearsal/check .planning/rehearsal/v1.0/cqm` — exit 0 is the v1.0 tag gate. Same procedure (advisory) for simac + enreach.
- **`composer test-unit` script:** The plan referenced `composer test-unit -- --filter RehearsalControllerTest`. The composer.json today only has `composer test`, which runs the Unit testsuite (the only one wired in phpunit.xml.dist). Plan 05-02 (Wave 2 sibling) introduces the split scripts; this plan stayed Wave-2-parallel-clean by using `composer test` with PHPUnit's `--filter` flag instead. No coordination diff needed.

## Deviations from Plan

None — plan executed exactly as written. The plan's own `<acceptance_criteria>` granted SUMMARY-documenting the auto-discovery smoke (rather than running it) when no Craft scratch site is available, which is the exact path taken here.

## Self-Check: PASSED

Verified files exist:

- `src/console/RehearsalController.php` — FOUND
- `tests/unit/console/RehearsalControllerTest.php` — FOUND
- `.planning/rehearsal/v1.0/cqm/README.md` — FOUND
- `.planning/rehearsal/v1.0/simac/README.md` — FOUND
- `.planning/rehearsal/v1.0/enreach/README.md` — FOUND

Verified commits exist:

- `cc44096` (RED — test commit) — FOUND
- `fa00a2e` (GREEN — feat commit) — FOUND
- `c9d5f63` (Task 2 — docs commit) — FOUND
