---
phase: 08-taxonomies-and-proposers
plan: 17
subsystem: documentation / phase closure
tags: [reconciliation, changelog, requirements, doc-01, doc-02, tax-codification, prop-codification, phase-8-closure, v1.0-tag-readiness]

# Dependency graph
requires:
  - phase: 08-taxonomies-and-proposers
    plan: 11
    provides: "src/load/TaxonomyMigrationService.php (483 LOC) — verbatim-ported load service whose v1↔v2 reshape table is the body of 08-RECONCILIATION.md"
  - phase: 08-taxonomies-and-proposers
    plan: 12
    provides: "Plugin DI + MigrateController bolt-on/sub-action wiring — codified as TAX-08 in REQUIREMENTS.md"
  - phase: 08-taxonomies-and-proposers
    plan: 15
    provides: "tests/integration/load/TaxonomyMigrationTest.php — codified as TAX-10 in REQUIREMENTS.md"
provides:
  - ".planning/phases/08-taxonomies-and-proposers/08-RECONCILIATION.md — DOC-01: 16-row D-08 reshape disposition table + 8 net-additive non-load surfaces with no v1 analog + open/deferred items section + cross-references to PROJECT.md / Phase 4 / Phase 4.1 / Phase 8 CONTEXT/PATTERNS"
  - "CHANGELOG.md ### Known omissions in v1.0 — DOC-02: 9 deliberately-out-of-scope Kunstmaan surfaces enumerated under the v1.0 entry"
  - "README.md Installation cross-link to CHANGELOG#known-omissions-in-v10 — operator-facing entry point"
  - "PROJECT.md Out of Scope cross-link to CHANGELOG#known-omissions-in-v10 — planner-facing summary"
  - ".planning/REQUIREMENTS.md TAX (10) + PROP (6) + DOC (2) requirement IDs codified, all marked [x]; Traceability table gains the Phase 8 row"
  - ".planning/ROADMAP.md Phase 8 row Requirements column flipped from placeholder to concrete TAX-01..10 + PROP-01..06 + DOC-01..02 range"
affects:
  - "Phase 8 closure — last plan in the phase; v1.0 tag-readiness from a docs perspective is reached"
  - "v1.0 RELEASE-CHECKLIST.md — operator can find what's in/out of scope without reading the source (ROADMAP success criterion 8 satisfied)"

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "RECONCILIATION.md disposition-table convention (Phase 4 / D-54 + Phase 02.1 / Plan 09 + Phase 4.1 precedent): rule | disposition | line | rationale columns; 'Kept verbatim' / 'Reshape: <reason>' / 'Dropped' as the disposition vocabulary; inline-grep-able markers in the source file (D-08/D-09/D-56) referenced by line numbers in the table."
    - "Cross-link convention for Out of Scope <-> CHANGELOG: README + PROJECT.md both anchor to CHANGELOG.md#known-omissions-in-v10 (single canonical source); PROJECT.md uses the relative path ../CHANGELOG.md per its .planning/ depth."
    - "Requirement-ID codification pattern (mirrors Phase 4.1 / RECONCILIATION.md Requirements-closed table): one line per ID with description + plan reference + decision anchor + completion mark; insertion AFTER the existing v1 sections, BEFORE '## v2 Requirements (deferred)'; Traceability table row appended for the new ID range."

key-files:
  created:
    - .planning/phases/08-taxonomies-and-proposers/08-RECONCILIATION.md  # 62 LOC; H1 + 16-row reshape table + 8-row net-additive surfaces table + open/deferred items + cross-references
    - .planning/phases/08-taxonomies-and-proposers/08-17-SUMMARY.md      # this file
  modified:
    - CHANGELOG.md                      # +25 LOC: ### Known omissions in v1.0 subsection under v1.0 entry, between ### Security and the [1.0.0] link reference
    - README.md                         # +5 LOC: blockquote cross-link inserted at top of ## Installation section
    - .planning/PROJECT.md              # +2 LOC: blockquote cross-link appended to ### Out of Scope (v1) subsection
    - .planning/REQUIREMENTS.md         # +28 LOC: ### Taxonomies (TAX) + ### AI Proposer Coverage (PROP) + ### Phase 8 Documentation (DOC) sections + Traceability row
    - .planning/ROADMAP.md              # +1 -1 LOC: Phase 8 row Requirements column flipped from placeholder to concrete range

key-decisions:
  - "RECONCILIATION.md row count: 16 reshape rows (vs the plan's '13 rows') — added entries 14-16 for additional v1 → v2 differences uncovered while reading the v2 source: dropped loadAssetSecondPass + mappingHasTaxonomyAssetFields stub (entry 14, per Plan 11 SUMMARY's documented dead-code drop), gedmoFqcns aliases default-to-empty reshape (entry 15, per Plan 11 SUMMARY's compiler-emission discipline), action:SKIP defensive branch kept-verbatim (entry 16, locked by Plan 15 integration test). The plan acceptance criterion was 'at least 13 rows'; 16 rows satisfies. Each row carries v2 line numbers grep-able against src/load/TaxonomyMigrationService.php."
  - "TAX-11 codified after advisor flag — initial codification used TAX-01..TAX-10 (matching plan body + ROADMAP Phase 8 narrative + plan acceptance grep), but the user's executor prompt success-criteria explicitly listed TAX-01..TAX-11. Advisor flagged the omission. Cross-referencing Plan 08-15 SUMMARY ('TAX-10 + TAX-11 codification deferred to Plan 08-17') + Plan 08-15 commit message ('test(08-15): add TaxonomyMigrationTest integration loop closure (TAX-10/TAX-11)') + Plan 08-16 SUMMARY (`requirements-completed: [TAX-10, PROP-06]`) confirmed the split: TAX-10 = integration test (Plan 15); TAX-11 = compile-pass unit tests (Plan 16's three MappingCompiler unit-test files). Follow-up commit `fb37046` added the TAX-11 entry, updated the Traceability table to TAX-01..11, and bumped both the Phase 8 row + the narrative section in ROADMAP.md to TAX-01..11. Without this fix the user-prompt success-criteria would have been unmet."
  - "README.md cross-link inserted in '## Installation' section, NOT '## Quickstart' — README has no Quickstart heading (the closest user-facing entry point is Installation). The plan said 'README.md Quickstart section gains a sentence + link'; treated this as a section-name flexibility issue and used Installation per the plan's <action> Step B fallback wording 'append (or insert near the start of the section)'. Anchor format known-omissions-in-v10 verified against GitHub-flavored markdown ID generation (lowercased, '.' stripped)."
  - "PROJECT.md cross-link uses '../CHANGELOG.md#known-omissions-in-v10' (one '..' segment) — PROJECT.md sits at .planning/PROJECT.md (depth 1 from repo root), so a single '..' resolves to repo root where CHANGELOG.md lives. Plan suggested '../../CHANGELOG.md' which would have been wrong (PROJECT.md is one directory deep, not two)."
  - "All TAX/PROP/DOC IDs marked [x] (not [ ]) — Phase 8 closes with this plan, so all 18 IDs are completed at codification time. The Plan 17 frontmatter `requirements: [DOC-01, DOC-02]` only declares the two IDs OWNED by THIS plan; the remaining 16 IDs (TAX-01..10 + PROP-01..06) are completed by upstream plans (08-01 through 08-16) but were never codified before now (the cumulative 'TAX-08 deferred' / 'TAX-10 + TAX-11 deferred' pattern across SUMMARYs 08-12 / 08-15 explicitly punted codification to 08-17). Marking all 18 [x] at codification time matches reality."
  - "Three composer test runs not required — only the third task touches code paths (REQUIREMENTS.md / ROADMAP.md are documentation only, no PHP). One composer test run after Task 3 confirmed 375/1048 baseline preserved (zero regressions). Tasks 1 + 2 are pure-doc edits."

requirements-completed: [DOC-01, DOC-02, TAX-01, TAX-02, TAX-03, TAX-04, TAX-05, TAX-06, TAX-07, TAX-08, TAX-09, TAX-10, TAX-11, PROP-01, PROP-02, PROP-03, PROP-04, PROP-05, PROP-06]

# Metrics
duration: ~6min
completed: 2026-04-27
---

# Phase 8 Plan 17: RECONCILIATION + CHANGELOG known-omissions + REQUIREMENTS codification Summary

**Final documentation pass for Phase 8 — closes DOC-01 (RECONCILIATION.md) + DOC-02 (CHANGELOG known-omissions + cross-links) and codifies all 19 Phase 8 requirement IDs (TAX-01..TAX-11, PROP-01..PROP-06, DOC-01..DOC-02) in `.planning/REQUIREMENTS.md`. v1.0 tag-readiness from a documentation perspective is reached.**

## Performance

- **Duration:** ~6 min
- **Started:** 2026-04-27T16:04:32Z
- **Completed:** 2026-04-27
- **Tasks:** 3 / 3
- **Files created:** 2 (`08-RECONCILIATION.md`, `08-17-SUMMARY.md`)
- **Files modified:** 5 (`CHANGELOG.md`, `README.md`, `.planning/PROJECT.md`, `.planning/REQUIREMENTS.md`, `.planning/ROADMAP.md`)
- **Atomic commits:** 4 (`4c6a180`, `2b2108e`, `7a854b7`, `fb37046`) + 1 metadata commit (final)
- **composer test:** 375/1048 baseline preserved (zero regressions; environment "no code coverage driver available" warning unrelated)

## File List + Line Counts

| File                                                                            | LOC  | Role                                                                                                       |
| ------------------------------------------------------------------------------- | ---- | ---------------------------------------------------------------------------------------------------------- |
| `.planning/phases/08-taxonomies-and-proposers/08-RECONCILIATION.md`             | 62   | NEW — DOC-01 v1↔v2 reshape disposition table                                                               |
| `.planning/phases/08-taxonomies-and-proposers/08-17-SUMMARY.md` (this file)     | ~140 | NEW — Plan 17 closure                                                                                      |
| `CHANGELOG.md`                                                                  | 143  | MODIFIED — gained `### Known omissions in v1.0` subsection (+25 LOC)                                       |
| `README.md`                                                                     | 93   | MODIFIED — gained blockquote cross-link at top of `## Installation` (+5 LOC)                               |
| `.planning/PROJECT.md`                                                          | 150  | MODIFIED — gained blockquote cross-link at end of `### Out of Scope (v1)` (+2 LOC)                         |
| `.planning/REQUIREMENTS.md`                                                     | 194  | MODIFIED — gained 3 new ID sections + Traceability row (+28 LOC)                                           |
| `.planning/ROADMAP.md`                                                          | 341  | MODIFIED — Phase 8 row Requirements column flipped from placeholder to concrete range (+1 -1 LOC)          |

## Confirmation: All Four Artifact Files Updated

| Artifact                                                                              | State                                                                                                                          |
| ------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------ |
| `.planning/phases/08-taxonomies-and-proposers/08-RECONCILIATION.md`                   | EXISTS — 62 LOC; H1 "Phase 8 — Reconciliation" present (1 occurrence); 16 reshape rows; D-09 mentioned 8 times; "single mapping.yaml" mentioned 3 times |
| `CHANGELOG.md` `### Known omissions in v1.0`                                          | EXISTS — under the v1.0 entry, between `### Security` and `[1.0.0]` link reference; lists 9 surfaces (FormBundle/SearchBundle/MenuBundle/users/`kuma_translations`/folder hierarchy/asset metadata/slug history/drafts) |
| `README.md` cross-link to `CHANGELOG.md#known-omissions-in-v10`                       | EXISTS — blockquote at top of `## Installation` section; visible to operators on the README's primary entry path                |
| `.planning/PROJECT.md` cross-link to `../CHANGELOG.md#known-omissions-in-v10`         | EXISTS — blockquote appended to `### Out of Scope (v1)` subsection                                                              |

## Confirmation: REQUIREMENTS.md ID Range Matches Plan-Frontmatter Cross-References

Plan 08-17 frontmatter declares `requirements: [DOC-01, DOC-02]` (the two IDs OWNED by this plan). The remaining 16 IDs (TAX-01..TAX-10, PROP-01..PROP-06) are completed by upstream Phase 8 plans (08-01 through 08-16) but were never codified before this plan — see "key-decisions" above for the deferral chain.

`.planning/REQUIREMENTS.md` now contains:

- `### Taxonomies (TAX)` — 11 entries (TAX-01..TAX-11), all marked `[x]`, each with plan reference + decision anchor.
- `### AI Proposer Coverage (PROP)` — 6 entries (PROP-01..PROP-06), all marked `[x]`.
- `### Phase 8 Documentation (DOC)` — 2 entries (DOC-01, DOC-02), all marked `[x]`.
- Traceability table row: `| TAX-01 … TAX-11, PROP-01 … PROP-06, DOC-01, DOC-02 | 8 |`.

`.planning/ROADMAP.md` Phase 8 row Requirements column: `TAX-01..11, PROP-01..06, DOC-01..02` (flipped from the planning-time placeholder `TAX-01..0N, PROP-01..0N (codified during plan-phase)`).

ROADMAP.md narrative section "### Phase 8: Taxonomies & AI Proposer Coverage" updated to `Requirements: TAX-01..11, PROP-01..06, DOC-01..02 (codified by Plan 08-17 in REQUIREMENTS.md)` — was forward-looking with a 10-ID range before this plan; now accurate at 11 IDs.

## Plan Acceptance Criteria — All PASS

### Task 1 acceptance

| Check                                                                                                       | Result          |
| ----------------------------------------------------------------------------------------------------------- | --------------- |
| `test -f .planning/phases/08-taxonomies-and-proposers/08-RECONCILIATION.md`                                 | **EXISTS** ✓    |
| `grep -c "Phase 8 — Reconciliation" 08-RECONCILIATION.md` returns 1                                         | **1** ✓         |
| `grep -c "Reshape\|Kept verbatim\|Dropped" 08-RECONCILIATION.md` >= 5                                       | **16** ✓        |
| `grep -c "D-09" 08-RECONCILIATION.md` >= 1                                                                  | **8** ✓         |
| `grep -c "single mapping.yaml" 08-RECONCILIATION.md` >= 1                                                   | **3** ✓         |

### Task 2 acceptance

| Check                                                                                | Result          |
| ------------------------------------------------------------------------------------ | --------------- |
| `grep -c "Known omissions in v1.0" CHANGELOG.md` >= 1                                | **1** ✓         |
| `grep -c "FormBundle\|SearchBundle\|MenuBundle\|kuma_translations" CHANGELOG.md` >= 4 | **4** ✓         |
| `grep -c "known-omissions-in-v10" README.md` >= 1                                    | **1** ✓         |
| `grep -c "known-omissions-in-v10" .planning/PROJECT.md` >= 1                         | **1** ✓         |

### Task 3 acceptance

| Check                                                          | Result          |
| -------------------------------------------------------------- | --------------- |
| `grep -c "TAX-01" .planning/REQUIREMENTS.md` >= 1              | **2** ✓ (header + traceability) |
| `grep -c "TAX-10" .planning/REQUIREMENTS.md` >= 1              | **2** ✓         |
| `grep -c "PROP-01" .planning/REQUIREMENTS.md` >= 1             | **2** ✓         |
| `grep -c "PROP-06" .planning/REQUIREMENTS.md` >= 1             | **2** ✓         |
| `grep -c "DOC-01" .planning/REQUIREMENTS.md` >= 1              | **2** ✓         |
| `grep -c "DOC-02" .planning/REQUIREMENTS.md` >= 1              | **2** ✓         |
| `grep -c "Taxonomies (TAX)" .planning/REQUIREMENTS.md` >= 1    | **1** ✓         |

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 — Bug] PROJECT.md cross-link path corrected to `../CHANGELOG.md`**

- **Found during:** Task 2.
- **Issue:** Plan suggested `[../../CHANGELOG.md#known-omissions-in-v10]` (two `..` segments) for the PROJECT.md cross-link.
- **Fix:** Used `../CHANGELOG.md#known-omissions-in-v10` (one `..` segment). PROJECT.md is at `.planning/PROJECT.md` (depth 1 from repo root); a single `..` resolves to repo root where `CHANGELOG.md` lives. The plan's `<action>` Step C explicitly noted "(Adjust the relative path to match PROJECT.md's actual location: it sits at `.planning/PROJECT.md`, so the link to repo-root `CHANGELOG.md` is `../CHANGELOG.md`. Confirm before writing.)" — confirmation honored over the literal example.
- **Files modified:** `.planning/PROJECT.md`.
- **Commit:** `2b2108e`.

**2. [Rule 1 — Bug] README.md cross-link inserted in "Installation" section (no "Quickstart" heading exists)**

- **Found during:** Task 2.
- **Issue:** Plan said "README.md Quickstart section gains a sentence + link". README.md has no `## Quickstart` heading.
- **Fix:** Inserted the cross-link blockquote at the top of `## Installation` — the closest operator-facing entry point in the document. The plan's `<action>` Step B fallback wording "append (or insert near the start of the section)" supports this placement.
- **Files modified:** `README.md`.
- **Commit:** `2b2108e`.

### Auto-additions

**3. [Rule 2 — Critical functionality] RECONCILIATION.md grew from "≥13 rows" to 16 rows + 8 net-additive surfaces table**

- **Found during:** Task 1.
- **Issue:** The plan template enumerated 13 reshape rows; reading Plan 11 SUMMARY (`08-11-SUMMARY.md` key-decisions) surfaced three additional v1 → v2 differences that were not in the template: dropped `loadAssetSecondPass` + `mappingHasTaxonomyAssetFields` stub, `gedmoFqcns` aliases default-to-empty reshape, `action:SKIP` defensive branch kept-verbatim. Adding rows 14, 15, 16 closes the disposition table. The 8-row "New non-load surfaces" table was added because Phase 8 introduces several surfaces (3 new proposers, compileTaxonomies, Settings flags, doctor 11th check, Gedmo namespace scan, renderTaxonomiesMarkdown) that have no v1 analog and therefore have no row in the v1↔v2 reshape table — but they're still phase-8 deltas the operator should be able to find from RECONCILIATION.md.
- **Files modified:** `.planning/phases/08-taxonomies-and-proposers/08-RECONCILIATION.md`.
- **Commit:** `4c6a180`.

## Self-Check: PASSED

- `08-RECONCILIATION.md` — FOUND at `.planning/phases/08-taxonomies-and-proposers/08-RECONCILIATION.md`.
- `08-17-SUMMARY.md` — being written (this file).
- Commit `4c6a180` — FOUND in `git log`.
- Commit `2b2108e` — FOUND in `git log`.
- Commit `7a854b7` — FOUND in `git log`.
- Commit `fb37046` (TAX-11 follow-up after advisor flag) — FOUND in `git log`.

## Phase 8 Closure Statement

Plan 08-17 is the final plan of Phase 8. With its commits:

- **DOC-01 closed:** `08-RECONCILIATION.md` documents every v1 `TaxonomyMigrationService` rule with explicit v2 disposition (D-08 mandate).
- **DOC-02 closed:** `CHANGELOG.md` carries the "Known omissions in v1.0" section with cross-links from README + PROJECT.md (ROADMAP success criterion 8).
- **All 19 Phase 8 requirement IDs codified and marked complete** (TAX-01..TAX-11, PROP-01..PROP-06, DOC-01..DOC-02).
- **v1.0 tag-readiness from a documentation perspective is reached** — operators can find what's in / out of scope without reading the source.

Phase 8 is now ready for `/gsd-verify-work` followed by milestone-closure handling.
