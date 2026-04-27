---
phase: 08-taxonomies-and-proposers
plan: 01
subsystem: mapping
tags: [taxonomy, dataProvider, mapping, compile, audit, php, craft-cms]

# Dependency graph
requires:
  - phase: 02-schema-mapping-filters
    provides: kind=column row shape, status ladder, skip-existing merge (MAP-04)
  - phase: 06-llm-entity-proposer
    provides: kind=nodeClass row shape, identityKey() FQCN-only branch
  - phase: 07-implicit-content
    provides: compileImplicitBlocks structural template, _compileReport counter pattern
provides:
  - "MappingFile::buildTaxonomyRow() — kind=taxonomy row builder (FQCN-only identity)"
  - "MappingFile::buildDataProviderRow() — kind=dataProvider row builder (FQCN-only identity)"
  - "MappingAuditor kind=taxonomy audit branch — emits missing-section, missing-entry-type (reuse), taxonomy-no-column-rows"
  - "MappingCompiler::compileTaxonomies() private + mapping.taxonomies block in compile() output"
  - "_compileReport counters: taxonomiesEmitted, layoutBlocksEmitted, dataProvidersEmitted (last two scaffolded at 0 for Plan 09)"
affects: [08-02, 08-03, 08-04, 08-05, 08-06, 08-07, 08-08, 08-09, 09-load-wiring]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Two new mapping row kinds (taxonomy, dataProvider) — both follow nodeClass FQCN-only identity convention (no nested fields[]; field-level mapping inferred from same-sourceTable kind=column rows)"
    - "compileTaxonomies mirrors compileImplicitBlocks shape: filter accepted rows → build keyed output map → skip-existing per MAP-04 → return [out, emitted, warnings]"
    - "Phase 8 _compileReport counter triplet (taxonomies/layoutBlocks/dataProviders) scaffolded together; layout + dataProvider counters wait on Plan 09 emission logic"

key-files:
  created: []
  modified:
    - src/mapping/MappingFile.php
    - src/mapping/MappingAuditor.php
    - src/compile/MappingCompiler.php

key-decisions:
  - "Mirror buildNodeClassRow exactly for buildTaxonomyRow — D-07 confirms taxonomies have no nested fields[]; that pattern is reserved for kind=pagePart"
  - "Reuse existing 'missing-entry-type' finding kind for taxonomy entry-type miss instead of introducing 'missing-taxonomy-entry-type' — same diagnostic; same operator response"
  - "Scaffold layoutBlocksEmitted + dataProvidersEmitted counters at 0 in this plan even though emission lives in Plan 09 — establishes the report shape before downstream consumers (CompileController) are extended"

patterns-established:
  - "Pattern: identityKey() taxonomy/dataProvider branches — both structural FQCN-only, dedupe-on-empty-target rationale per W1 fix"
  - "Pattern: auditor kind-branch short-circuits past column-handler-classification — taxonomy rows are entity-level so block-availability validator + handler-mismatch detection do not apply"
  - "Pattern: compile() return assembly grows by one top-level key per phase (Phase 7 added pageParts; Phase 8 adds taxonomies; layout/dataProvider blocks land in Plan 09)"

requirements-completed: [TAX-01, PROP-03]

# Metrics
duration: ~25min
completed: 2026-04-27
---

# Phase 8 Plan 01: Taxonomy & DataProvider Mapping Scaffolding Summary

**Foundational kind=taxonomy + kind=dataProvider row machinery + mapping.taxonomies compile block + Phase 8 _compileReport counters wired across MappingFile / MappingAuditor / MappingCompiler.**

## Performance

- **Duration:** ~25 min
- **Started:** 2026-04-27 (worktree-agent-a35dd2397ee78c0d1)
- **Completed:** 2026-04-27
- **Tasks:** 3 / 3
- **Files modified:** 3

## Accomplishments

- `MappingFile` learned two new row kinds without disturbing the existing column / pagePart / nodeClass shapes — buildTaxonomyRow + buildDataProviderRow both deliver MAP-04 skip-existing semantics via FQCN-only identity keys.
- `MappingAuditor` recognises accepted kind=taxonomy rows and emits three findings (missing-section, missing-entry-type via reuse, taxonomy-no-column-rows) without invoking the column-handler-classification or block-availability validators that don't apply to taxonomies.
- `MappingCompiler::compile()` emits a top-level `taxonomies:` block keyed by FQCN with the D-07 shape `{ sourceTable, targetSection, targetEntryType }` (no nested fields[]). Operator-edited entries always win (skip-existing).
- `_compileReport` now surfaces all three Phase 8 counters: `taxonomiesEmitted` (live), `layoutBlocksEmitted` (scaffolded at 0 for Plan 09), `dataProvidersEmitted` (scaffolded at 0 for Plan 09).

## Task Commits

Each task was committed atomically:

1. **Task 1: MappingFile.buildTaxonomyRow + buildDataProviderRow + identityKey branches** — `ac3b8fc` (feat)
2. **Task 2: MappingAuditor kind=taxonomy audit branch** — `7793506` (feat)
3. **Task 3: MappingCompiler.compileTaxonomies scaffolding + counter wiring** — `e165e42` (feat)

(No SUMMARY metadata commit yet — orchestrator merges and commits SUMMARY.md alongside other Wave 1 plans.)

## Files Created/Modified

- `src/mapping/MappingFile.php` — added `buildTaxonomyRow()` (line 243) + `buildDataProviderRow()` (line 269); extended `identityKey()` with taxonomy branch (line 307) and dataProvider branch (line 310). Both branches use structural FQCN-only identity, mirroring the nodeClass dedupe-on-empty-target rationale (W1 fix from prior phases).
- `src/mapping/MappingAuditor.php` — added kind=taxonomy audit branch (line 121, ~85 LOC) handling missing-section / missing-entry-type / taxonomy-no-column-rows after the existing dropped-status short-circuit. Skips the column-handler-classification logic and the block-availability validator (neither applies to entity-level rows).
- `src/compile/MappingCompiler.php` — added private `compileTaxonomies()` at line 738; wired into `compile()` after `compileImplicitBlocks()` (line 348-352); added `'taxonomies' => $taxonomiesOut` to return assembly (line 360); added `taxonomiesEmitted` (line 372) plus `layoutBlocksEmitted` (line 373, value=0) and `dataProvidersEmitted` (line 374, value=0) counters to `_compileReport`. Updated `compile()` return-type docblock to reflect the new keys.

## Decisions Made

- **Reuse `missing-entry-type` finding kind for taxonomy entry-type miss.** The diagnostic (entry-type handle does not resolve in Craft) and the operator response (fix the handle in mapping.yaml or add the entry type to Craft) are identical to the column-row case. Introducing a separate `missing-taxonomy-entry-type` would split tooling that consumes audit findings without adding signal.
- **Scaffold `layoutBlocksEmitted` and `dataProvidersEmitted` counters at value 0 alongside the live `taxonomiesEmitted`.** The plan's must_haves explicitly require all three counters in `_compileReport` even though the emission logic for the latter two lands in Plan 09 / Wave 3. Wiring the keys now means Plan 09 only has to flip the values, not retrofit the report shape — and `CompileController` (extended in a later plan) can be coded against the final shape without conditional checks.
- **`buildTaxonomyRow` mirrors `buildNodeClassRow` shape exactly (no nested fields[]).** D-07 is unambiguous: nested fields[] is reserved for kind=pagePart. Field-level mapping for taxonomies is inferred from same-sourceTable kind=column rows at compile/transform time, the same convention nodeClasses already use. Adding a vestigial `fields` key would create a documentation-truth divergence.

## Deviations from Plan

None - plan executed exactly as written.

The plan's verification step references `composer phpstan`, but the worktree has no `vendor/` directory installed (no static-analysis binary available locally). Verification fell back to `php -l` syntax checks on each modified file (all green) plus the explicit grep-based acceptance criteria. The orchestrator / CI environment will run phpstan in the post-wave validation pass — out-of-scope for this worktree per the parallel-execution contract.

## Issues Encountered

None.

## User Setup Required

None - this plan is pure scaffolding (no env vars, no external services, no DB schema changes).

## Next Phase Readiness

- Wave 2 proposers (`proposeNonPageEntities`, `proposeLayoutBlocks`, `proposeDataProviders`) can now emit kind=taxonomy and kind=dataProvider rows into a structurally-valid mapping.yaml.
- Plan 09 / Wave 3 (compiler emission for layout-blocks + dataProviders) can populate `layoutBlocksEmitted` and `dataProvidersEmitted` without retrofitting `_compileReport`.
- Wave 4 load-wiring (`TaxonomyMigrationService`) will consume `mapping.taxonomies[fqcn]` directly — the shape is locked.
- No blockers; nothing in this plan touches the LLM proposer surface, the load pipeline, or shared orchestrator artifacts.

## Self-Check: PASSED

**Files exist:**
- FOUND: src/mapping/MappingFile.php
- FOUND: src/mapping/MappingAuditor.php
- FOUND: src/compile/MappingCompiler.php

**Commits exist:**
- FOUND: ac3b8fc (Task 1)
- FOUND: 7793506 (Task 2)
- FOUND: e165e42 (Task 3)

**Acceptance criteria:**
- `grep -c "public function buildTaxonomyRow(array \$proposal, string \$initialStatus): array" src/mapping/MappingFile.php` → 1
- `grep -c "'kind'            => 'taxonomy'" src/mapping/MappingFile.php` → 1
- `grep -c "'taxonomy|'" src/mapping/MappingFile.php` → 1
- `grep -c "public function buildDataProviderRow" src/mapping/MappingFile.php` → 1
- `grep -c "'dataProvider|'" src/mapping/MappingFile.php` → 1
- `grep -c "'kind'         => 'dataProvider'" src/mapping/MappingFile.php` → 1
- `grep -c "'taxonomy-no-column-rows'" src/mapping/MappingAuditor.php` → 1
- `grep -c "'missing-section'" src/mapping/MappingAuditor.php` → 1
- `grep '=== '\''taxonomy'\''' src/mapping/MappingAuditor.php` → 1 match
- `grep -c "private function compileTaxonomies" src/compile/MappingCompiler.php` → 1
- `grep -c "'taxonomiesEmitted'" src/compile/MappingCompiler.php` → 1
- `grep -c "'layoutBlocksEmitted'" src/compile/MappingCompiler.php` → 1
- `grep -c "'dataProvidersEmitted'" src/compile/MappingCompiler.php` → 1
- `grep "'taxonomies' *=> *\$taxonomiesOut" src/compile/MappingCompiler.php` → 1 match
- `php -l` on all three files → No syntax errors

---
*Phase: 08-taxonomies-and-proposers*
*Completed: 2026-04-27*
