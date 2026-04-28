---
phase: 04
plan: 11
subsystem: console-controller
tags: [doctor, adapter-health, verify-baseline, info-badge, observability]
requires:
  - 04-09  # Plugin DI graph (verify services available before doctor reports baseline presence)
provides:
  - doctor-check-adapter-plugins
  - doctor-check-verify-baseline
  - doctor-info-badge-discipline
affects:
  - src/console/DoctorController.php
tech-stack:
  added: []
  patterns:
    - "Doctor 7th check (D-69) — adapter plugin presence via Craft::$app->plugins->getPlugin('seomatic'|'retour'); INFO on absence, never FAIL (ADP-01..03 optional adapters)"
    - "Doctor 8th check (D-69) — verify baseline presence via is_file(storage/migration/baseline.json); INFO on absence (operator may run doctor before capture-baseline)"
    - "INFO badge — yellow Console::FG_YELLOW with 5-char left-padded 'INFO ' prefix, distinguishing informational-only conditions from WARN (actionable) and FAIL (blocking)"
    - "Always-true return invariant — both new methods `return true` regardless of branch, preserving the $ok aggregator's pass-through behavior on adapter/baseline absence"
    - "Class docblock kept current — header lists all 8 checks with phase/plan provenance for archaeological clarity"
key-files:
  created: []
  modified:
    - src/console/DoctorController.php
decisions:
  - "INFO badge introduced as a third color-coded category alongside OK / WARN / FAIL. Same yellow as WARN (Console::FG_YELLOW) but distinguished by the literal 'INFO ' prefix label, so operators reading the report know the line is informational-only and never blocks a migrate run."
  - "Both new methods always return true even on absence. Adapter absence is by design (ADP-01..03 — optional); baseline absence is by operator workflow choice (doctor may legitimately run before capture-baseline). Treating either as FAIL would force false-positive failures on greenfield doctor runs."
  - "Extension only — no preserved-or-modified existing checks. The orchestration sequence in actionIndex appends two new lines after the 6th check; the announcement header (no explicit 'N checks' count is printed) was already accurate without modification, so only the class-level docblock was bumped from 6 → 8."
  - "Method placement at bottom of class file (after checkStateTable) rather than near checkApiKey/checkMappingFile. Phase 1 layout puts checks in execution order, and chronological/dependency order gives the freshest reader the clearest narrative."
metrics:
  duration: "00:02:30"
  completed: "2026-04-26T19:42:00Z"
  tasks_completed: 1
  files_modified: 1
  tests_passing: 60
  assertions: 137
---

# Phase 4 Plan 11: Doctor 7th + 8th checks Summary

Extended `DoctorController` with two informational-only preflight checks per D-69: adapter plugin health (SEOmatic + Retour optional-detect via `Craft::$app->plugins->getPlugin`) and verify baseline presence (`storage/migration/baseline.json` is_file probe). Both checks always return true — adapter absence is by design (ADP-01..03 optional adapters), and baseline absence reflects an operator workflow choice (doctor may legitimately precede `verify capture-baseline`). The INFO badge joins OK / WARN / FAIL as a yellow informational-only label, preserving Phase 1 / D-19 plain-text-with-ANSI-color discipline.

## Tasks Completed

| Task | Name | Commit | Files |
|------|------|--------|-------|
| 1 | Add checkAdapterPlugins + checkVerifyBaseline methods + actionIndex orchestration extension | afa61dd | src/console/DoctorController.php |

## Verification Evidence

- `composer test` — exits 0; 60 tests, 137 assertions passing (1 deprecation, pre-existing).
- `php -l src/console/DoctorController.php` — `No syntax errors detected`.
- `grep -c 'private function checkAdapterPlugins(' src/console/DoctorController.php` → 1 (acceptance pass).
- `grep -c 'private function checkVerifyBaseline(' src/console/DoctorController.php` → 1 (acceptance pass).
- `grep -c '\$this->checkAdapterPlugins()' src/console/DoctorController.php` → 1 (called from actionIndex, acceptance pass).
- `grep -c '\$this->checkVerifyBaseline()' src/console/DoctorController.php` → 1 (called from actionIndex, acceptance pass).
- `grep -E "getPlugin\('seomatic'\)" src/console/DoctorController.php` → 1 hit (acceptance pass).
- `grep -E "getPlugin\('retour'\)" src/console/DoctorController.php` → 1 hit (acceptance pass).
- `grep -c 'INFO seomatic not installed' src/console/DoctorController.php` → 1 (acceptance pass).
- `grep -c 'INFO retour not installed' src/console/DoctorController.php` → 1 (acceptance pass).
- `grep -c 'INFO baseline.json missing' src/console/DoctorController.php` → 1 (acceptance pass).
- `grep -c 'baseline.json' src/console/DoctorController.php` → 4 (acceptance pass — ≥1 required).
- `grep -c 'D-69' src/console/DoctorController.php` → 8 (acceptance pass — ≥1 required).
- `grep -c 'return true; // D-69' src/console/DoctorController.php` → 2 (always-OK invariant in both methods, acceptance pass).
- v1-only filter properties (`includeDeleted/Offline/Drafts`, `cutoffAfter/Before`, `maxPerEntity`) — none introduced; doctor still ignores `--entities`/`--locales`/`--since` (FILT-03 no-op surface preserved).
- D-21 invariant verified: `legacyDbService` registration in `Plugin::config()` untouched — no edits to `src/Plugin.php` in this plan.

## Deviations from Plan

None — plan executed exactly as written. Plan body and acceptance criteria matched the implementation 1:1, no auto-fixes triggered, no architectural decisions encountered.

## Requirements Addressed

- **ADP-01** (SEOmatic optional adapter): doctor now reports SEOmatic presence/absence as INFO line — operator-facing surface confirms runtime detection works, completing the operator visibility leg started in Plan 04-06 (service) + Plan 04-10 (action).
- **ADP-02** (Retour optional adapter): doctor now reports Retour presence/absence as INFO line — same operator visibility pattern as ADP-01.
- **ADP-03** (composer suggest): the doctor surface is the operator's confirmation that the optional-deps stance is working as designed; checked-in composer.json `suggest` block (Phase 1) is exercised by this report.
- **VER-01** (verify count gate): doctor reports baseline.json presence under `storage/migration/`, the same path BaselineCounterService writes to in `verify capture-baseline` — operator can pre-flight check before kicking off a verify run.

## RECONCILIATION

Pure extension of an existing v2 file. v1's DoctorController had different checks (queue worker, mapping file in different shape) that v2 already disposed of in earlier phases. No verbatim port — structural extension following the locked Phase 1 / D-19 + D-20 + D-69 disciplines.

| v1 / prior rule | v2 disposition |
|---|---|
| Phase 1 / D-19 plain-text OK/WARN/FAIL with ANSI color | **extended** — INFO joins the badge set (yellow, like WARN, distinguished by prefix). |
| Phase 1 / D-20 gate-first idiom | **preserved** — actionIndex still calls `enforceNeverProduction()` before any check runs. |
| Phase 02.1 / D-31 doctor 5th check (KunstmaanSourcePath) | **preserved** — sequence unchanged; new checks appended after 6th. |
| Phase 3 / D-13 doctor 6th check (state table) | **preserved** — sequence unchanged; new checks appended after 6th. |
| New 7th check (adapter health) — D-69 | **added** — INFO on absence, never FAIL (ADP-01..03 optional). |
| New 8th check (verify baseline) — D-69 | **added** — INFO on absence, never FAIL (operator may run doctor first). |

## Self-Check: PASSED

- File `src/console/DoctorController.php`: FOUND
- Commit `afa61dd`: FOUND
- All 13 acceptance grep checks: PASS
- `composer test`: exit 0, 60 tests, 137 assertions
- `php -l`: clean
