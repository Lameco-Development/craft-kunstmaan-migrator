---
status: partial
phase: 02-schema-mapping-filters
source: ["02-VERIFICATION.md"]
started: "2026-04-25T22:30:00Z"
updated: "2026-04-25T22:30:00Z"
---

## Current Test

[awaiting human testing]

## Tests

### 1. analyze against real Kunstmaan dump
expected: `./craft kunstmaan-migrator/analyze` against a configured legacy DB + valid Anthropic key emits `storage/migration/schema-dump.json` and `storage/migration/REPORT.md`, populates `mapping.yaml` with proposals carrying per-row `status:` (heuristic-high → `accepted`; LLM → `proposed` / `needs-review` per confidence tier).
result: [pending]

### 2. map interactive rubber-stamp loop
expected: `./craft kunstmaan-migrator/map` walks every mapping.yaml row with status in {proposed, needs-review}, renders a one-screen block per row (D-05), accepts a/d/r/s/q keys; [r]emap is a two-step picker (handler enum → numbered handle list); [s]kip leaves the row untouched; [q]uit exits cleanly. Each accept/drop/remap triggers an atomic per-keypress write (D-07) — interrupting mid-loop and re-running resumes from the next un-resolved row (D-08 stateless resume).
result: [pending]

### 3. 9-heuristic 60% coverage threshold (ROADMAP success criterion 2)
expected: Against the CQM rehearsal dump, the 9 deterministic heuristics resolve at least 60% of columns before any LLM call is made.
result: [pending]
note: Empirical measurement — best validated as part of the Phase 5 rehearsal pass against the CQM dump.

### 4. paste-ready `sites:` block on unmapped locales (LOC-01)
expected: When `LocalePreflight::ensure()` returns unmapped legacy locales, `REPORT.md` (and `analyze` console output) contains a paste-ready Craft `sites:` block listing the unmapped locales so the operator can copy it into `config/sites.php`.
result: [pending]

### 5. --auto-accept-high non-interactive promotion (MAP-05)
expected: `./craft kunstmaan-migrator/map --auto-accept-high` non-interactively promotes every row with `confidence: high` AND `status: proposed` to `status: accepted`, prints a count, then exits without prompting.
result: [pending]

## Summary

total: 5
passed: 0
issues: 0
pending: 5
skipped: 0
blocked: 0

## Gaps
