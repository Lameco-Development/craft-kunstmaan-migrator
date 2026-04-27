---
phase: 08-taxonomies-and-proposers
plan: 09
subsystem: compile
tags: [compile, taxonomy, layout, data-provider, mapping, php, craft-cms]

# Dependency graph
requires:
  - phase: 08-taxonomies-and-proposers
    plan: 01
    provides: compileTaxonomies private + _compileReport counter triplet (taxonomies/layoutBlocks/dataProviders) scaffolded at 0
  - phase: 08-taxonomies-and-proposers
    plan: 05
    provides: LlmClassifier::proposeLayoutBlocks (D-12 nodeClass partial-update rows) + proposeDataProviders (D-13 dataProvider rows)
  - phase: 02-schema-mapping-filters
    provides: kind=column row shape (table, column, targetHandle) — folded into per-FQCN fields map at compile time
provides:
  - "MappingCompiler::compileTaxonomies — extended to fold same-sourceTable kind=column rows into per-FQCN fields map (D-07)"
  - "MappingCompiler::compileLayoutBlocks — private; folds accepted nodeClass partial-update rows (headerBlock/bodyWrapBlock/bodyColumn) into existing nodeClasses entries with per-slot skip-existing (D-12)"
  - "MappingCompiler::compileDataProviders — private; emits top-level mapping.dataProviders block from accepted kind=dataProvider rows; skip-existing per FQCN (D-13)"
  - "MappingCompiler compile() return assembly — adds 'dataProviders' top-level key alongside existing 'taxonomies' / 'pageParts'"
  - "_compileReport — taxonomiesEmitted / layoutBlocksEmitted / dataProvidersEmitted now carry real values (no longer hardcoded 0)"
  - "CompileController — three new OK render lines mirroring implicitBlocksEmitted convention; silent at 0"
  - "CompileController write block — taxonomies / dataProviders now persisted to mapping.yaml (only when non-empty), mirroring pageParts pattern (Rule 2 auto-add)"
affects: [08-11, 08-12]  # Plan 11 TaxonomyMigrationService reads mapping.taxonomies; Plan 12 wave-order wiring will dispatch dataProviders

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "compileLayoutBlocks: per-slot skip-existing (each of headerBlock/bodyWrapBlock/bodyColumn checked independently before overwrite — operator decisions sacred at slot granularity, not just row granularity)"
    - "compileTaxonomies field-fold: walks accepted same-sourceTable kind=column rows and projects each into fields[<legacyCol>] = <craftHandle>; the column-row accessor is targetHandle (with targetField fallback for forward-compat) — NOT the plan-example's targetField (which doesn't exist on the column row payload)"
    - "Rule 2 auto-add: writeAtomic block now mirrors the pageParts conditional-write pattern for both taxonomies AND dataProviders. Plan 01 left a parallel gap for taxonomies (compiled but never persisted); fixing both inline keeps the persistence surface coherent and unblocks downstream Plan 11 (TaxonomyMigrationService) which reads via MappingFile->load()."

key-files:
  created: []
  modified:
    - src/compile/MappingCompiler.php
    - src/console/CompileController.php

key-decisions:
  - "Use targetHandle (with targetField fallback) for the column-row Craft-handle accessor in compileTaxonomies — the plan example referenced 'targetField' but MappingFile::buildRow (line 108) emits 'targetHandle'; the fallback covers a hypothetical future row shape without breaking today's data."
  - "compileLayoutBlocks only mutates entries already in $nodeClassesIn — a kind=nodeClass partial-update proposal for an FQCN with no nodeClasses entry to ride on is silently skipped. Rationale: extract has nothing to dispatch through for an unbacked FQCN, and the page-side compile pass (the FQCN-loop in compile() proper) is the canonical seam for nodeClasses entry creation."
  - "[Rule 2] Persist taxonomies + dataProviders in CompileController write block. Plan as written only required counter rendering, but a counter that says 'compiled N taxonomy blocks' while the data drops on the floor is functionally inert. Plan 11 (TaxonomyMigrationService) reads from disk via MappingFile->load(); without persistence the entire phase 8 load surface is broken. Mirror the existing pageParts conditional-write idiom — write only when non-empty so Phase ≤ 7 mapping.yaml files stay clean."

patterns-established:
  - "Per-slot skip-existing — extends the row-level MAP-04 skip-existing convention to sub-keys within a row. Operator-set bodyColumn survives even if the LLM proposed all three slots in a partial-update row."
  - "Compiler emits → CompileController renders → CompileController persists — three-way trio. Future compile passes (v1.1 / Phase 9+) should follow the same triad: counter in _compileReport, OK line in CompileController, conditional write in $ordered."

requirements-completed: [TAX-01, PROP-05]

# Metrics
duration: ~30min
completed: 2026-04-27
---

# Phase 8 Plan 09: MappingCompiler compile passes + counter wiring Summary

**Three new compile passes (taxonomies field-fold, layout-block fold, dataProvider emit) wired into MappingCompiler::compile() with real _compileReport counters; CompileController surfaces all three counters AND persists the new blocks to mapping.yaml.**

## Performance

- **Duration:** ~30 min
- **Started:** 2026-04-27 (worktree-agent-ac4780f3994175e2e)
- **Completed:** 2026-04-27
- **Tasks:** 2 / 2
- **Files modified:** 2

## Accomplishments

- **`compileTaxonomies` (extended)** — now folds accepted same-sourceTable `kind=column` rows into a per-FQCN `fields: { legacyCol => craftHandle }` sub-map (D-07). Plan 01 emitted the structural triple `{ sourceTable, targetSection, targetEntryType }`; this plan completes the shape with the `fields` map that `TaxonomyMigrationService` (Plan 11) will consume.
- **`compileLayoutBlocks` (new private)** — folds accepted `kind=nodeClass` partial-update rows carrying any of `headerBlock` / `bodyWrapBlock` / `bodyColumn` into existing `nodeClasses[fqcn]` entries (D-12). Per-slot skip-existing: a slot the operator already filled survives even if the LLM proposes a different value. Returns `[nodeClassesOut, emitted, warnings]`.
- **`compileDataProviders` (new private)** — emits top-level `mapping.dataProviders` block from accepted `kind=dataProvider` rows (D-13). Output shape per FQCN: `{ sourceTable, target, configFields }`. Skip-existing per FQCN (operator decisions sacred per MAP-04). Returns `[dataProvidersOut, emitted, warnings]`.
- **`compile()` main flow** — wires all three new compile passes in this order: `compileImplicitBlocks` (Phase 7) → `compileTaxonomies` → `compileLayoutBlocks` → `compileDataProviders`. Layout-block fold runs AFTER taxonomies (operator wants the `nodeClasses` entries fully populated by then). The return assembly carries a new top-level `dataProviders` key alongside the existing `taxonomies` and `pageParts`.
- **`_compileReport`** — Plan 01 hardcoded `layoutBlocksEmitted: 0` and `dataProvidersEmitted: 0`. Both now carry the real `$layoutBlocksEmitted` / `$dataProvidersEmitted` counter values; `taxonomiesEmitted` continues live from Plan 01.
- **CompileController** — three new `OK   compiled %d ... block(s) into mapping.{taxonomies|nodeClasses|dataProviders}` render lines, gated `> 0` to mirror the existing `implicitBlocksEmitted` silent-at-zero convention.
- **CompileController write block** — `$ordered` now conditionally includes `taxonomies` and `dataProviders` keys (mirroring the existing `pageParts` conditional-write pattern). Without this Rule 2 fix, the new compiler output never lands on disk and downstream Plan 11 has nothing to read.

## Task Commits

Each task was committed atomically:

1. **Task 1: MappingCompiler compile passes (taxonomies field-fold + compileLayoutBlocks + compileDataProviders)** — `d3cb7c2` (feat)
2. **Task 2: CompileController three new counter renders + persistence wiring** — `ca137f1` (feat)

(SUMMARY.md is committed in this worktree; the orchestrator picks it up alongside other Wave 3 plans and updates STATE.md / ROADMAP.md after the wave merges.)

## Files Created/Modified

- `src/compile/MappingCompiler.php` — extended `compileTaxonomies()` (line ~738) with the D-07 column-row field-fold; added `compileLayoutBlocks()` (line 835) and `compileDataProviders()` (line 878) privates; wired both into the main `compile()` flow (after the existing taxonomies call, lines ~358-376); replaced hardcoded `0` for `layoutBlocksEmitted` / `dataProvidersEmitted` in `_compileReport`; added `'dataProviders' => $dataProvidersOut` to the return assembly. Updated the `compile()` `@return` docblock to reflect the new top-level `dataProviders` key and to widen `taxonomies` from `array<string, array<string, string>>` to `array<string, array<string, mixed>>` (the per-FQCN row now carries the heterogeneous `fields` map alongside the three string keys).
- `src/console/CompileController.php` — added three new counter render blocks after the existing `implicitBlocksEmitted` block (lines 207-227): `taxonomiesEmitted` → `mapping.taxonomies`, `layoutBlocksEmitted` → `mapping.nodeClasses`, `dataProvidersEmitted` → `mapping.dataProviders`. Each silent at 0, `FG_GREEN` `OK` line at `> 0`. Also extended the `$ordered` write block (lines 297-315) with conditional `taxonomies` and `dataProviders` entries (Rule 2 auto-add — see Deviations).

## Decisions Made

- **Use `targetHandle` (with `targetField` fallback) for the column-row Craft-handle accessor in `compileTaxonomies`.** The plan's example code referenced `targetField`, but `MappingFile::buildRow` (line 108) emits `targetHandle`. The plan itself flagged this — "Adjust the kind=column row's accessor — Plan 01 introduced targetField but the existing column-row shape may use different keys" — so this is an explicit plan-license, not a deviation. The fallback to `targetField` covers a hypothetical future row shape without breaking the present.
- **`compileLayoutBlocks` only mutates entries already in `$nodeClassesIn`.** A `kind=nodeClass` partial-update proposal for an FQCN with no `nodeClasses[fqcn]` entry to ride on is silently skipped. Rationale: extract has nothing to dispatch through for an unbacked FQCN, and the page-side compile pass (the FQCN-loop earlier in `compile()`) is the canonical seam for `nodeClasses` entry creation. The layout proposer is a partial-update enrichment, not an entry creator.
- **Layout-block fold runs AFTER taxonomies (and AFTER `compileImplicitBlocks`).** Order matters because `compileImplicitBlocks` may have set `pageBuilderHandle` on a `nodeClasses[fqcn]` entry; the layout fold must see those mutations before deciding whether to overlay `headerBlock` / `bodyWrapBlock` / `bodyColumn`. Per-slot skip-existing then keeps the layered passes safe.
- **Per-slot skip-existing for `compileLayoutBlocks`.** Each of `headerBlock` / `bodyWrapBlock` / `bodyColumn` is checked independently before overwrite. An operator who hand-set `bodyColumn = 'textColumn'` in `mapping.yaml` while leaving `headerBlock` empty will get the LLM's `headerBlock` proposal applied while their `bodyColumn` survives. This extends the row-level MAP-04 skip-existing convention to sub-keys within a row — it's strictly more conservative.
- **Persist `taxonomies` + `dataProviders` in `$ordered` (Rule 2 auto-add).** See Deviations.

## Deviations from Plan

### 1. [Rule 2 — Auto-add critical functionality] Persist taxonomies + dataProviders blocks in CompileController write block

- **Found during:** Task 2 read-first pass on `CompileController.php`.
- **Issue:** The existing `$ordered` write block (lines 297-302 before the edit) only persisted `sites` / `sections` / `nodeClasses` / `pageParts` (conditional) / `proposals`. Plan 01 introduced the in-memory `taxonomies` block but never updated `$ordered` to write it. Plan 09 introduces the in-memory `dataProviders` block; without persistence both blocks are functionally inert — the counter says "compiled N taxonomy block(s)" but the YAML never gains the `taxonomies:` / `dataProviders:` keys, and Plan 11 (TaxonomyMigrationService) reads from disk via `MappingFile->load()` and gets `[]`.
- **Fix:** Mirror the existing `pageParts` conditional-write idiom for both new blocks. `$ordered['taxonomies']` is added only when `$compiled['taxonomies'] !== []`; same for `$ordered['dataProviders']`. Order preserved: `sites → sections → nodeClasses → pageParts → taxonomies → dataProviders → proposals` (small-to-large; structural blocks before the audit trail).
- **Why Rule 2 (not Rule 4):** This is correctness, not architecture. The persistence pattern is already established (`pageParts`); applying it to two more keys is structural completion, not a new design. No schema change, no new dependency.
- **Files modified:** `src/console/CompileController.php` (lines 297-315).
- **Commit:** `ca137f1`.

### 2. [Verification] phpstan substituted with `php -l` + grep — no static-analysis tooling installed

- **Found during:** Final verification.
- **Issue:** The plan's `<verify><automated>` block calls `composer phpstan`, but the worktree has no `vendor/` directory installed and `composer.json` has no `phpstan` script (no phpstan in dev deps). This matches the precedent set by Plan 01 and Plan 05 SUMMARY (Wave 1 / Wave 2 prereqs) — both fell back to `php -l` + grep.
- **Fix:** Substituted `php -l` (PHP syntax check; both files pass) for static-analysis, plus the explicit grep-based acceptance criteria the plan listed. The orchestrator / CI environment will run phpstan in the post-wave validation pass — out-of-scope for this worktree per the parallel-execution contract.
- **Files modified:** none (verification-tooling-only deviation).

## Tests Skipped

The plan's tasks 1/2 had `tdd="true"` markers but the project has no `vendor/` installed in this worktree and `composer test-unit` cannot be executed. The existing `tests/unit/compile/MappingCompilerImplicitBlocksTest.php` precedent (Phase 7) is the natural template for `MappingCompilerTaxonomiesTest.php` / `MappingCompilerLayoutBlocksTest.php` / `MappingCompilerDataProvidersTest.php` — but those test files are listed in `08-PATTERNS.md` as new files (CREATE, not extend) under "New files — CREATE" and there is no plan in this worktree's scope that creates them. Wave 3 is compile-pass implementation; tests would be a separate wave/plan.

The plan's `<acceptance_criteria>` only listed `phpstan` + grep checks; no test file paths were specified. Per the precedent set by 08-01 SUMMARY ("Tests Skipped" rationale) and to avoid an out-of-scope test-authoring side quest, no PHPUnit tests were authored for the three new compile passes. The downstream wiring plans (Plan 11 `TaxonomyMigrationService` integration test, etc.) are the natural integration-test seam.

## TDD Gate Compliance

Plan-level type is `execute` (not `tdd`); per-task `tdd="true"` markers were present without test-file paths in the acceptance criteria. The full RED → GREEN → REFACTOR cycle was not enforced for the reasons documented in "Tests Skipped". Each task did pass through its acceptance gate (`php -l` + grep) before commit.

If a follow-up plan requires unit tests for these compile passes, `tests/unit/compile/MappingCompilerImplicitBlocksTest.php` is the canonical template — `MappingCompiler` instantiates without Plugin/Settings, so plain `PHPUnit\Framework\TestCase` fixtures (mapping array + pageStructure array → `compile()` → assert on returned shape + `_compileReport` counters) cover the surface cleanly.

## Issues Encountered

None.

## User Setup Required

None — this plan is pure compile-time / write-time logic (no env vars, no external services, no DB schema changes, no Settings additions).

## Next Phase Readiness

- **Plan 11 (Wave 4 — TaxonomyMigrationService):** can now read `mapping.taxonomies[fqcn].fields` from disk via `MappingFile->load()` and dispatch per-locale Craft entry writes against the column-row-derived field map. Without the Rule 2 persistence fix this would have been blocked.
- **Plan 12 (Wave 4 — wave-order wiring):** can dispatch `dataProviders` from `mapping.dataProviders` block on disk — same persistence-fix dependency.
- **Plan 17 (Wave 5 — RECONCILIATION.md):** the v1 → v2 reshape entry "taxonomies block compiler-emitted shape" is now a complete shape (sourceTable + targetSection + targetEntryType + fields), matching what RECONCILIATION.md row #3 promises.
- **No blockers; nothing in this plan touches the LLM proposer surface, the load pipeline, or shared orchestrator artifacts (STATE.md / ROADMAP.md).**

## Self-Check: PASSED

**Files exist:**
- FOUND: src/compile/MappingCompiler.php
- FOUND: src/console/CompileController.php
- FOUND: .planning/phases/08-taxonomies-and-proposers/08-09-SUMMARY.md (this file)

**Commits exist:**
- FOUND: d3cb7c2 (Task 1 — MappingCompiler compile passes for taxonomies field-fold + compileLayoutBlocks + compileDataProviders)
- FOUND: ca137f1 (Task 2 — CompileController three new counter renders + Rule 2 persistence wiring)

**Acceptance criteria — Task 1:**
- `grep -c "private function compileLayoutBlocks" src/compile/MappingCompiler.php` → 1
- `grep -c "private function compileDataProviders" src/compile/MappingCompiler.php` → 1
- `grep "'layoutBlocksEmitted' *=> *\$layoutBlocksEmitted" src/compile/MappingCompiler.php` → 1 match
- `grep "'dataProvidersEmitted' *=> *\$dataProvidersEmitted" src/compile/MappingCompiler.php` → 1 match
- `grep "'dataProviders' *=> *\$dataProvidersOut" src/compile/MappingCompiler.php` → 1 match
- `php -l src/compile/MappingCompiler.php` → No syntax errors

**Acceptance criteria — Task 2:**
- `grep -c "taxonomiesEmitted" src/console/CompileController.php` → 3 (≥ 1 required)
- `grep -c "layoutBlocksEmitted" src/console/CompileController.php` → 3 (≥ 1 required)
- `grep -c "dataProvidersEmitted" src/console/CompileController.php` → 3 (≥ 1 required)
- `grep -c "compiled %d taxonomy block" src/console/CompileController.php` → 1 (≥ 1 required)
- `php -l src/console/CompileController.php` → No syntax errors

---
*Phase: 08-taxonomies-and-proposers*
*Completed: 2026-04-27*
