---
status: partial
phase: 02-schema-mapping-filters
source: ["02-VERIFICATION.md"]
started: "2026-04-25T22:30:00Z"
updated: "2026-04-25T23:45:00Z"
---

## Current Test

[UAT 1, 3, 5 blocked on `nl` locale mapping — UAT 4 PASSED]

## Tests

### 1. analyze against real Kunstmaan dump
expected: `./craft kunstmaan-migrator/analyze` against a configured legacy DB + valid Anthropic key emits `storage/migration/schema-dump.json` and `storage/migration/REPORT.md`, populates `mapping.yaml` with proposals carrying per-row `status:` (heuristic-high → `accepted`; LLM → `proposed` / `needs-review` per confidence tier).
result: blocked
note: |
  Round 1: crashed on first query — `SQLSTATE[3D000] 1046 No database selected`.
  Doctor said legacyDb reachable but `CRAFT_LEGACY_DB_DATABASE` was unset →
  DSN ended with `dbname=`. Plugin defect — doctor's SELECT 1 was too weak.
  Fixed in 5e6e1aa.
  Round 2 (env now set): crashed on locale preflight — `nl` is in the legacy
  Kunstmaan dump but isn't a Craft site handle on this host. Hard-FAIL is
  correct behavior (D-17 LOC-02). Dropped a workaround config at
  ~/Sites/cqm-craft-website/config/kunstmaan-migrator.php that sets
  defaultLocales: ['nl'] — couldn't re-run from this session (cross-project
  permission gate after prior crash). Operator action: re-run
  `./craft kunstmaan-migrator/analyze` to complete UAT 1.

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
result: passed
note: |
  First analyze round (with `nl` unmapped) emitted only the bare 'Add them
  to config/sites.php' message — the helpful renderLocales output was
  unreachable because REPORT.md is built only after preflight passes.
  Fixed in 46d54f1: ReportBuilder::renderLocales is now public and
  AnalyzeController calls it inline on the failure path. Verified live:
    Detected Kunstmaan locales: en, nl
    Currently mapped (Craft sites + Settings::defaultLocales): default, en
    Unmapped: nl
    ```php
    return [
        'en' => ['language' => 'en', 'baseUrl' => 'https://example.com/'],
        'nl' => ['language' => 'nl', 'baseUrl' => 'https://example.com/'],
        ...
    ];
    ```

### 5. --auto-accept-high non-interactive promotion (MAP-05)
expected: `./craft kunstmaan-migrator/map --auto-accept-high` non-interactively promotes every row with `confidence: high` AND `status: proposed` to `status: accepted`, prints a count, then exits without prompting.
result: blocked
note: Depends on UAT 1 (needs a populated mapping.yaml).

## Summary

total: 5
passed: 1 (UAT 4)
issues: 0
pending: 1 (UAT 2 — operator TTY)
skipped: 0
blocked: 3 (UAT 1, 3, 5 — re-run pending after `nl` workaround)

## Gaps

### UAT-01: doctor reported reachable but analyze crashed on no-default-schema
status: resolved
debug_session: inline
resolution: |
  Strengthened DoctorController::checkLegacyDb to verify `SELECT DATABASE()`
  returns a non-empty schema name in addition to the SELECT 1 connectivity
  probe. Fix committed as 5e6e1aa. Operator added CRAFT_LEGACY_DB_DATABASE
  to ~/Sites/cqm-craft-website/.env; second doctor run is green.

### UAT-04: paste-ready sites block was missing on locale FAIL
status: resolved
debug_session: inline
resolution: |
  ReportBuilder::renderLocales lived behind REPORT.md, which is never
  written when LocalePreflight blocks. Promoted to public; AnalyzeController
  now calls it on the failure path so the YAML prints inline. Fix in
  46d54f1, verified live against ~/Sites/cqm-craft-website (en + nl
  detected; nl unmapped → block listed both with suggested handles).
