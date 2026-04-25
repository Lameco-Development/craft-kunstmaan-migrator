---
status: passed
phase: 02-schema-mapping-filters
source: ["02-VERIFICATION.md"]
started: "2026-04-25T22:30:00Z"
updated: "2026-04-26T00:15:00Z"
---

## Current Test

[UAT 1, 3, 4, 5 closed; UAT 2 still operator-driven (interactive TTY)]

## Tests

### 1. analyze against real Kunstmaan dump
expected: `./craft kunstmaan-migrator/analyze` against a configured legacy DB + valid Anthropic key emits `storage/migration/schema-dump.json` and `storage/migration/REPORT.md`, populates `mapping.yaml` with proposals carrying per-row `status:` (heuristic-high → `accepted`; LLM → `proposed` / `needs-review` per confidence tier).
result: passed
note: |
  Three UAT rounds; two real defects surfaced and fixed before this passed.
  - Round 1: SQLSTATE 1046 — doctor's SELECT 1 was too weak. Fixed in 5e6e1aa.
  - Round 2: locale preflight FAIL because Craft's site handle is `default`
    while its `language` is `nl-NL`; preflight only compared against
    `$site->handle`. Fixed in 9af0381 (matching ladder + read both handle
    and language).
  - Round 3: end-to-end PASS against ~/Sites/cqm-craft-website.
    48 tables / 278 columns scanned; 113 heuristic + 165 LLM proposals;
    mapping.yaml + schema-dump.json + REPORT.md + MAPPING-AUDIT.md emitted;
    mapping audit clean.

### 2. map interactive rubber-stamp loop
expected: `./craft kunstmaan-migrator/map` walks every mapping.yaml row with status in {proposed, needs-review}, renders a one-screen block per row (D-05), accepts a/d/r/s/q keys; [r]emap is a two-step picker (handler enum → numbered handle list); [s]kip leaves the row untouched; [q]uit exits cleanly. Each accept/drop/remap triggers an atomic per-keypress write (D-07) — interrupting mid-loop and re-running resumes from the next un-resolved row (D-08 stateless resume).
result: pending
note: |
  Cannot drive interactive TTY from a non-interactive shell session.
  mapping.yaml is now populated (22 rows in `status: needs-review` from
  the LLM medium-confidence tier) — operator can drive at any time.

### 3. 9-heuristic 60% coverage threshold (ROADMAP success criterion 2)
expected: Against the CQM rehearsal dump, the 9 deterministic heuristics resolve at least 60% of columns before any LLM call is made.
result: deferred
note: |
  Measured: 113/278 = 40.6%. BUT all 113 were heuristic 1 (`fill-rate=0
  → drop`). Heuristics 2-8 produced zero matches because the cqm-craft-website
  Craft target hasn't yet provisioned the entry types and fields the migration
  will land into — `buildCraftFieldIndex` returns a thin index, so name-based
  heuristics have nothing to match against. The 60% threshold is meaningful
  only after the Craft target is structurally provisioned. Re-measure during
  Phase 5 rehearsal once the cqm-craft-website entry types are in.

### 4. paste-ready `sites:` block on unmapped locales (LOC-01)
expected: When `LocalePreflight::ensure()` returns unmapped legacy locales, `REPORT.md` (and `analyze` console output) contains a paste-ready Craft `sites:` block listing the unmapped locales so the operator can copy it into `config/sites.php`.
result: passed
note: |
  Round 1 finding: bare 'Add them to config/sites.php' message — the helpful
  renderLocales output lived behind REPORT.md (unreachable on FAIL path).
  Fixed in 46d54f1.
  Round 2 enhancement (9af0381): the suggestion block now offers two paths —
  Settings::localeMap (recommended for handle-vs-language mismatches like
  nl ↔ nl-NL) OR fresh Craft sites (for genuinely new locales). REPORT.md
  also enumerates how each detected locale was resolved (exact / prefix /
  localeMap). Live verified: REPORT.md shows `Resolved by language-prefix
  (loose match): nl → nl-NL`.

### 5. --auto-accept-high non-interactive promotion (MAP-05)
expected: `./craft kunstmaan-migrator/map --auto-accept-high` non-interactively promotes every row with `confidence: high` AND `status: proposed` to `status: accepted`, prints a count, then exits without prompting.
result: passed
note: |
  Live run output: `Map: promoted 143 high-confidence proposals → accepted`.
  Verified status distribution after run: 143 accepted, 113 dropped (fill-rate
  zero from analyze), 22 needs-review (LLM medium-confidence) — consistent
  with the pre-promotion counts (143 proposed/high → accepted; the 113
  dropped + 22 needs-review rows are correctly untouched).

## Summary

total: 5
passed: 3 (UAT 1, 4, 5)
deferred: 1 (UAT 3 — re-measure in Phase 5 rehearsal)
pending: 1 (UAT 2 — operator TTY)
issues: 0 outstanding
skipped: 0
blocked: 0

## Gaps

### UAT-01: doctor reported reachable but analyze crashed on no-default-schema
status: resolved
debug_session: inline
resolution: |
  Strengthened DoctorController::checkLegacyDb to verify `SELECT DATABASE()`
  returns a non-empty schema name in addition to the SELECT 1 connectivity
  probe. Fix committed as 5e6e1aa.

### UAT-04: paste-ready sites block was missing on locale FAIL
status: resolved
debug_session: inline
resolution: |
  ReportBuilder::renderLocales lived behind REPORT.md, which is never
  written when LocalePreflight blocks. Promoted to public; AnalyzeController
  now calls it on the failure path so the YAML prints inline. Fix in 46d54f1.

### UAT-LOC-01: locale matching too strict (handle vs language; no override map)
status: resolved
debug_session: inline
resolution: |
  Craft's Site::$handle and Site::$language are distinct. The cqm-craft-website
  target uses handle `default` and language `nl-NL`; legacy Kunstmaan stores
  `nl` — no exact match against the handle.

  Fix in 9af0381 lands a 3-rung matching ladder:
    1. Settings::localeMap[$legacy] — explicit operator override
    2. Exact match against (Craft site handle | language) + defaultLocales
    3. Language-prefix match — split on `-` or `_`, compare prefixes

  Plus Settings::localeMap is now declared as Phase 2-4 surface for operator
  control on more complex multilingual setups (the user-requested future
  flexibility).

  Test: tests/locale/LocalePreflightTest.php (5 tests for the languagePrefix
  helper). Suite: 39 tests / 84 assertions, all green.
