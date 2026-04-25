---
status: partial
phase: 02-schema-mapping-filters
source: ["02-VERIFICATION.md"]
started: "2026-04-25T22:30:00Z"
updated: "2026-04-25T23:15:00Z"
---

## Current Test

[1, 3, 4, 5 blocked on env config — see Gaps]

## Tests

### 1. analyze against real Kunstmaan dump
expected: `./craft kunstmaan-migrator/analyze` against a configured legacy DB + valid Anthropic key emits `storage/migration/schema-dump.json` and `storage/migration/REPORT.md`, populates `mapping.yaml` with proposals carrying per-row `status:` (heuristic-high → `accepted`; LLM → `proposed` / `needs-review` per confidence tier).
result: blocked
note: Ran against ~/Sites/cqm-craft-website. Crashed on first query — `SQLSTATE[3D000] 1046 No database selected`. Doctor reported `legacyDb reachable` (connection opened) but `CRAFT_LEGACY_DB_DATABASE` is unset in the host's .env, so DSN ends with `dbname=` and no default schema is selected. Plugin defect: doctor's SELECT 1 was too weak. Fixed in 5e6e1aa (DoctorController now checks `SELECT DATABASE()` and points operator at the env var). Re-running doctor now correctly fails with a clear message. Re-test pending operator setting CRAFT_LEGACY_DB_DATABASE.

### 2. map interactive rubber-stamp loop
expected: `./craft kunstmaan-migrator/map` walks every mapping.yaml row with status in {proposed, needs-review}, renders a one-screen block per row (D-05), accepts a/d/r/s/q keys; [r]emap is a two-step picker (handler enum → numbered handle list); [s]kip leaves the row untouched; [q]uit exits cleanly. Each accept/drop/remap triggers an atomic per-keypress write (D-07) — interrupting mid-loop and re-running resumes from the next un-resolved row (D-08 stateless resume).
result: pending
note: Cannot drive interactive TTY from a non-interactive shell session. Operator-driven test.

### 3. 9-heuristic 60% coverage threshold (ROADMAP success criterion 2)
expected: Against the CQM rehearsal dump, the 9 deterministic heuristics resolve at least 60% of columns before any LLM call is made.
result: blocked
note: Depends on UAT 1. Best validated as part of the Phase 5 rehearsal pass against the CQM dump.

### 4. paste-ready `sites:` block on unmapped locales (LOC-01)
expected: When `LocalePreflight::ensure()` returns unmapped legacy locales, `REPORT.md` (and `analyze` console output) contains a paste-ready Craft `sites:` block listing the unmapped locales so the operator can copy it into `config/sites.php`.
result: blocked
note: Depends on UAT 1.

### 5. --auto-accept-high non-interactive promotion (MAP-05)
expected: `./craft kunstmaan-migrator/map --auto-accept-high` non-interactively promotes every row with `confidence: high` AND `status: proposed` to `status: accepted`, prints a count, then exits without prompting.
result: blocked
note: Depends on UAT 1 (needs a populated mapping.yaml).

## Summary

total: 5
passed: 0
issues: 1 (UAT-01 blocked — DB env config + plugin defect both surfaced; defect fixed)
pending: 1 (UAT 2 — operator TTY)
skipped: 0
blocked: 3 (UAT 1, 3, 4, 5 — wait on env)

## Gaps

### UAT-01: doctor reported reachable but analyze crashed on no-default-schema
status: resolved
debug_session: inline
resolution: |
  Strengthened DoctorController::checkLegacyDb to verify `SELECT DATABASE()`
  returns a non-empty schema name in addition to the SELECT 1 connectivity
  probe. Fix committed as 5e6e1aa. The remaining work is operator-side:
  set `CRAFT_LEGACY_DB_DATABASE` in `~/Sites/cqm-craft-website/.env` (and
  optionally `CRAFT_LEGACY_DB_TABLE_PREFIX` if the legacy tables aren't bare
  `kuma_*`), then re-run `./craft kunstmaan-migrator/doctor` to confirm and
  retry UAT 1, 3, 4, 5.
