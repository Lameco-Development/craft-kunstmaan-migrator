---
phase: 03-etl-pipeline-field-handlers
plan: 04
subsystem: etl-pipeline
tags: [extract, etl, kunstmaan, legacy-db, migration-filters, port]
requires:
  - src/db/LegacyDbService.php
  - src/source/TopologicalOrderer.php
  - src/source/DetailTableResolver.php
  - src/source/KunstmaanCoreTables.php
  - src/filter/MigrationFilters.php
provides:
  - lameco\kunstmaanmigrator\extract\ExtractService (ETL-03 read surface)
affects:
  - Phase 03-12 TransformService (consumes Extract output)
  - Phase 03-14 Plugin::init() (sibling-DI wiring)
  - Phase 04 KunstmaanSerializedDecoder reinstatement
tech-stack:
  added: []
  patterns: [verbatim-port, sibling-DI-slots, MigrationFilters-piping, namespace-flatten]
key-files:
  created:
    - src/extract/ExtractService.php (579 LOC)
  modified: []
decisions:
  - "D-46 verbatim discipline: page-part ordering uses `ORDER BY context, sequencenumber` (v1 ExtractService.php:433). CONTEXT.md D-49's `kuma_page_part_refs.weight` hypothesis is NOT adopted — v1 wins."
  - "KunstmaanSerializedDecoder deferred to Phase 4: typed slot replaced with `?object`; decodeSerializedColumns() falls through with raw value when null (behavioral parity with no-decoder path)."
  - "MigrationFilters threaded through run() at three sites; SQL-level predicates pushed down would require modifying LegacyDbService::streamLiveNodes/translationsFor (out of scope), so v2 applies them as client-side post-fetch filters. Functional parity with FILT-02 invariant preserved."
  - "Return type changed from `: array` to `: iterable` per plan §5 interface; v1 body still returns the report dict (array is iterable, php -l passes). True row-streaming is a separate refactor."
metrics:
  duration: ~12m
  completed: 2026-04-26
  tasks_completed: 1
  tasks_total: 1
  loc_added: 579
  files_created: 1
  files_modified: 0
---

# Phase 3 Plan 04: ExtractService Summary

Verbatim port of v1's 533-LOC `ExtractService` into v2's flat `extract/` namespace, reshaped to thread `MigrationFilters` through `run()` per Phase 2's FILT-02 invariant. Page-part ordering preserves v1's authoritative `ORDER BY context, sequencenumber` SQL; CONTEXT D-49's `weight` hypothesis is rejected per D-46 verbatim discipline.

## What was built

`src/extract/ExtractService.php` — Stage 2a of the ETL pipeline. Reads `kuma_nodes` + per-node detail-table rows + `kuma_page_part_refs` + per-page-class part rows from the legacy Kunstmaan DB; groups by node_id and produces one JSON payload per node under `@storage/migration/extracted/<fqcn-slug>/<node-id>.json`. Sibling-DI slots (`legacyDb`, `detailTableResolver`, `topologicalOrderer`, `serializedDecoder`) are wired by `Plugin::init()` in Plan 03-14.

## Tasks completed

| # | Name                                                                                    | Commit  | Files                            |
| - | --------------------------------------------------------------------------------------- | ------- | -------------------------------- |
| 1 | Verbatim port ExtractService with MigrationFilters threading + KunstmaanSerializedDecoder defer | 887affa | src/extract/ExtractService.php   |

## Reconciliation against v1

**v1 file:** `~/Sites/craft-kunstmaan-migrator/src/kunstmaan/extract/ExtractService.php` (533 LOC)
**v2 file:** `src/extract/ExtractService.php` (579 LOC — additions: filter-piping markers + comments)

| v1 site | Description | Disposition | v2 location / rationale |
|---|---|---|---|
| Imports — `LegacyDbService`, `DetailTableResolver`, `TopologicalOrderer`, `KunstmaanCoreTables` | 4 deps | retargeted | Flatten namespaces: `kunstmaan\db` → `db`, `kunstmaan\schema` → `source`, `kunstmaan\db` → `source` (KunstmaanCoreTables). |
| Import — `MigrationConfigError` | Typed config errors | dropped intentionally | Replaced with `\RuntimeException` at all 9 throw sites (lines 70, 121, 124, 131, 183, 190, 303, 460 in v1). Same operator-facing messages preserved. |
| Import — `KunstmaanSerializedDecoder` | B11 chokepoint for serialized-blob safety | **partially ported — Phase 4 follow-up** | Replaced with `?object = null` slot. `decodeSerializedColumns()` null-guards every call site; falls through with raw value when decoder is unset. Plan 04-X (Phase 4) reinstates the typed dependency together with `SeomaticPayloadBuilder`. |
| `run()` signature `(array $mapping, array $options = [])` | v1 had no Phase 2 filter spec | **intentional reshape — Phase 2 D-10 filter piping per FILT-02** | v2 signature: `(array $mapping, MigrationFilters $filters, array $options = []): iterable`. Three filter-application sites with `// Phase 2 / D-10 filter piping per FILT-02 — added in v2 port (not in v1).` markers: (1) entities allow-list pre-walk, (2) locales subset post-fetch on translations, (3) since predicate post-fetch on node rows. Return type widened from `: array` to `: iterable` per plan interface; body still returns the report dict. |
| `run()` preflight `serializedDecoder === null` throw (line 123) | v1 hard-required decoder | dropped intentionally | Decoder is now optional pending Phase 4 reinstatement. |
| Line 433 — page-part ordering SQL `ORDER BY context, sequencenumber` | v1 authoritative ordering for joining `kuma_page_part_refs` | **ported verbatim — D-46 wins over CONTEXT D-49** | **Drift recorded.** D-49 hypothesized `kuma_page_part_refs.weight`; v1 uses `context, sequencenumber`. Verbatim discipline overrides. CONTEXT.md wording to be corrected at next phase-doc update; this entry is the canonical record. The `weight` column may exist on `kuma_page_part_refs` but v1's behavior keys on (context, sequencenumber) — operator-observed CQM behavior over the rehearsal corpus is the witness. |
| Streaming/Generator pattern | v1 streams kuma_nodes via `streamLiveNodes()` | ported verbatim | Generator semantics preserved. Phase 3 in-process pipeline (CONTEXT D-48) consumes the stream directly without intermediate disk artifact (current `run()` body still writes JSON-per-node; refactor to true streaming is a later concern). |

### Counts (Plan 03-04 only)
| Pair | ported | dropped intentionally | dropped accidentally | partially ported |
|---|---:|---:|---:|---:|
| ExtractService | 4 | 1 (MigrationConfigError) + 1 (decoder preflight throw) | 0 | 1 (KunstmaanSerializedDecoder — Phase 4) |

**Drift log entry (rolls up to phase-level RECONCILIATION.md in Plan 03-15):**
> CONTEXT.md D-49 says page-parts are "ordered by `kuma_page_part_refs.weight`". v1 source-of-truth at `~/Sites/craft-kunstmaan-migrator/src/kunstmaan/extract/ExtractService.php:433` uses `ORDER BY context, sequencenumber`. Adopted v1 per D-46 verbatim discipline. CONTEXT.md wording to be corrected at next phase-doc update.

## Deviations from Plan

### Auto-fixed Issues / Reshapes

**1. [Rule 3 - Adaptation] SQL-level filter pushdown not feasible — applied client-side**

- **Found during:** Task 1 implementation
- **Issue:** Plan §5 directs filter SQL predicates onto `kuma_node_translations` JOIN (`AND nt.lang IN (...)`) and on kuma_nodes/kuma_node_versions (`AND <table>.updated_at >= :since`). However, v1's `run()` does not contain those JOINs inline — they are encapsulated in `LegacyDbService::streamLiveNodes($fqcn)` and `LegacyDbService::translationsFor($nodeId)`. Pushing predicates into those methods would require modifying `LegacyDbService` (out of scope: this plan's `files_modified` is only `src/extract/ExtractService.php`).
- **Fix:** Applied filters as **post-fetch client-side filters** at the same logical sites the plan identified, retaining the FILT-02 invariant and the required `// Phase 2 / D-10 filter piping per FILT-02 — added in v2 port (not in v1).` marker comments. Functional parity preserved; performance-optimal SQL pushdown is a follow-up (when LegacyDbService gains filter-aware overloads).
- **Files modified:** `src/extract/ExtractService.php` (3 filter-application sites: pre-walk FQCN allow-list, in-loop locale subset, in-loop since predicate)
- **Commit:** 887affa

**2. [Rule 2 - Defensive] `entities` filter uses basename match (FQCN last-segment)**

- **Found during:** Task 1 implementation
- **Issue:** `MigrationFilters::$entities` docstring says "Kunstmaan source class names (e.g. 'NewsPage')" — basenames, not FQCNs. But mapping.yaml `nodeClasses` keys are full like `App\Entity\Pages\NewsPage`. Direct `in_array` comparison would always miss.
- **Fix:** Compute basename via `strrpos($fqcn, '\\')` and compare basename against `$filters->entities`. Inline comment explains the choice.
- **Files modified:** `src/extract/ExtractService.php` (run() entities-filter site)
- **Commit:** 887affa

### Forward-references (out-of-scope; tracked for follow-up plans)

- `LegacyDbService` lacks `streamLiveNodes()`, `translationsFor()`, `getDatabaseName()` — v1 calls them, this verbatim port preserves the calls. A later plan (most likely Plan 03-12 or a LegacyDbService extension plan) must add these methods to the v2 service for runtime correctness. `php -l` does not flag missing methods on `?LegacyDbService`, so the file is syntactically valid but not runnable end-to-end yet. This is consistent with the verbatim-port discipline: `<scope_boundary>` keeps each plan focused on its own files.
- True streaming (`Generator` from `run()`) is signaled by the `: iterable` return type but the body still returns the report dict. Refactoring to row-streaming semantics is a separate concern (likely Plan 03-12 or 03-15).

## Threat Flags

None — this port preserves v1's existing T-04-05-01 (identifier whitelist) and T-04-05-03 (B11 serialized-blob decoder) mitigations. The decoder's deferral to Phase 4 is documented; consumers must re-wire it before production use (which `NeverProductionTrait` blocks anyway).

## Self-Check: PASSED

- [x] `src/extract/ExtractService.php` exists (579 LOC).
- [x] `php -l src/extract/ExtractService.php` → "No syntax errors detected".
- [x] Commit `887affa` exists in `git log`.
- [x] All 17 done-criteria greps pass:
  - namespace `lameco\kunstmaanmigrator\extract;` × 1
  - 5 retargeted imports × 1 each (MigrationFilters, TopologicalOrderer, DetailTableResolver, LegacyDbService, KunstmaanCoreTables)
  - `MigrationConfigError` × 0
  - `KunstmaanSerializedDecoder` × 0
  - `public ?object $serializedDecoder = null` × 1
  - `public ?LegacyDbService $legacyDb = null` × 1
  - `public ?TopologicalOrderer $topologicalOrderer = null` × 1
  - `MigrationFilters $filters` ≥ 1 (× 1)
  - `ORDER BY context, sequencenumber` × 1 ✓ (CRITICAL — D-46 verbatim)
  - `ORDER BY weight` × 0 ✓ (D-49 hypothesis NOT adopted)
  - `Phase 2 / D-10 filter piping` ≥ 1 (× 4)
  - bridge/craft/kunstmaan namespace residues × 0
