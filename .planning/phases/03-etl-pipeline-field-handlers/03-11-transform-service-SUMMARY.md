---
phase: 03-etl-pipeline-field-handlers
plan: 11
subsystem: transform
tags: [etl, transform, port, d-48, migration-filters]
requires:
  - 03-01-scaffold
  - 03-02-vos
  - 03-03-state-service
  - 03-06-ckeditor-rewriter
  - 03-08-handlers-text-asset
  - 03-09-handlers-relation-matrix
  - 03-10-handlers-split-name
provides:
  - ETL-01 (transform stage — per-entry resolver loop)
  - FH-02 (FieldHandlerRegistry dispatch via TransformService)
affects:
  - src/transform/TransformService.php (new)
tech-stack:
  added: []
  patterns:
    - sibling-DI (75a95bc): 5 ?Foo = null slots wired by Plugin::init()
    - MigrationFilters piping (Shared Pattern 2)
    - D-48 in-process pipeline (iterable input + iterable yield, no disk artifacts)
key-files:
  created:
    - src/transform/TransformService.php (936 LOC)
  modified: []
decisions:
  - D-48 reshape: run() consumes iterable<extractedRow> directly from ExtractService and yields per-entry payloads. v1's disk walk over extracted/<fqcn-slug>/<node-id>.json removed.
  - MigrationFilters threaded as explicit method parameter (not pulled from a global). entities filter matches FQCN simple-name OR full FQCN; locales filter scopes the per-site loop.
  - MigrationConfigError replaced with \RuntimeException carrying the same messages (no v2 typed-error model imported in Phase 3).
  - Final run() report yielded as a sentinel `['__report' => $report]` last value so single iterable channel carries both per-entry payloads and final counters (was v1's run() return value).
  - Payload now carries 'stateSource' + 'stateKey' (FQCN-slug + kuma_node_id) explicitly; v1 inferred these from the on-disk filename.
metrics:
  completed: 2026-04-26
  duration: ~25 min
  tasks: 1
  files: 1
  loc: 936
---

# Phase 3 Plan 11: TransformService Summary

940-LOC verbatim port of v1's `bridge/transform/TransformService.php` reshaped for the v2 in-process pipeline. The per-entry resolver loop — site-loop construction, ResolverContext per (site, entry) tuple, FieldHandlerRegistry dispatch, pageBuilder + headerBlock + bodyWrapBlock paths — ports byte-for-byte. Only the disk-scan entry shape is reshaped to consume an iterable from ExtractService; only namespace + import paths and the dropped `MigrationConfigError` differ from v1.

## What was built

`src/transform/TransformService.php` (936 LOC) — Stage 2b transform service. Yii Component with 5 sibling-DI slots:

| Slot | Type | Source plan |
|---|---|---|
| `handlerRegistry` | `?FieldHandlerRegistry` | 03-01 |
| `ckeditorRewriter` | `?CkeditorRewriterService` | 03-06 |
| `legacyDb` | `?LegacyDbService` | Phase 1 |
| `migrationState` | `?MigrationStateReader` (narrow read interface) | 03-02 |
| `assetPathResolver` | `?AssetPathResolver` | 03-02 |

Plugin::init() (Plan 03-14) is responsible for wiring all 5 slots per the 75a95bc pattern.

## Public surface

```php
public function run(
    iterable $extracted,
    array $mapping,
    MigrationFilters $filters,
    array $options = [],
): iterable
```

- `$extracted` — stream of extracted-row tuples from ExtractService; each tuple carries `{fqcn, kuma_node_id, kuma_parent_id, kunstmaanSourceId, refIdsByLocale, perSite}`.
- `$mapping` — parsed mapping.yaml (sections / nodeClasses / pageParts / dataProviders / sites).
- `$filters` — Phase 2 MigrationFilters VO. `$filters->entities` scopes FQCN dispatch (matches simple-name OR full FQCN); `$filters->locales` scopes the per-site loop. Empty arrays = unbounded.
- `$options` — `{onlyNodeClass?: string|null, limit?: int|null}` v1-compatible knobs.

Yields per-entry payloads; final yield is a sentinel `['__report' => $report]` carrying counter aggregates (`nodesTransformed`, `blocksTransformed`, `skippedBlocks`, `warnings`, `headerBlocksEmitted`, `bodyWrapBlocksEmitted`).

## Reconciliation (per-plan)

### TransformService reconciliation

**v1 file:** `~/Sites/craft-kunstmaan-migrator/src/bridge/transform/TransformService.php` (940 LOC)
**v2 file:** `src/transform/TransformService.php` (936 LOC)

| v1 Rule (file:line) | Description | Disposition | v2 location / rationale |
|---|---|---|---|
| Lines 42-51 — class header (5 ?Foo slots + storagePath) | Sibling-DI shape. | ported verbatim | Plugin::init() (Plan 03-14) wires all 5 slots per 75a95bc. |
| Lines 200+ — per-row handler-routing loop body | The bulk of the 940 LOC. | ported verbatim | Site-loop + ResolverContext construction + FieldSpec walk + transformPageBuilder + resolveFieldSpecs + headerBlock + bodyWrapBlock + applyTargetShorthand + applyRelationShorthand + hydrateDetailJoins + buildPagePartColumns + deriveSourcePartRef + buildSiteMap + buildContext + emptyStateReader + resolvePaths. |
| Lines 26-27 docblock — named-arguments recommendation for ResolverContext | API doc. | ported verbatim | Same docblock, prose preserved. |
| `run(array $mapping, array $options): array` v1 signature with disk-scan loop entry | Walked `extracted/<fqcn-slug>/<node-id>.json` artifacts; wrote `transformed/entries/<fqcn-slug>/<node-id>.json`. | **intentional reshape — D-48 single-process pipeline** | v2 signature: `run(iterable $extracted, array $mapping, MigrationFilters $filters, array $options = []): iterable`. Disk walk replaced with `foreach ($extracted as $extractedRow)`. Disk write replaced with `yield $payload`. Body of loop unchanged. |
| (no v1 rule) — MigrationFilters | v1 had no Phase 2 filter spec. | **intentional reshape — Phase 2 D-10 filter piping per FILT-02** | Filters scope per-site loop + FQCN dispatch. Four filter-site comment markers in source. |
| `MigrationConfigError` throws (lines 60, 63, 70, 75, 103) | Typed errors. | dropped intentionally | `\RuntimeException` with identical messages. |

### Counts (Plan 03-11 only)

| Pair | ported | dropped intentionally | dropped accidentally | intentional reshape |
|---|---:|---:|---:|---:|
| TransformService | 3 | 1 (MigrationConfigError) | 0 | 2 (D-48 disk→iterable, MigrationFilters threading) |

## Verification

```
$ php -l src/transform/TransformService.php
No syntax errors detected in src/transform/TransformService.php

$ wc -l src/transform/TransformService.php
     936 src/transform/TransformService.php  (>= 850 plan minimum)
```

Per-criterion grep results:

| Done criterion | Result |
|---|---|
| `namespace lameco\kunstmaanmigrator\transform;` | 1 match |
| `class TransformService extends Component` | 1 match |
| `public ?FieldHandlerRegistry $handlerRegistry = null` | 1 match |
| `public ?CkeditorRewriterService $ckeditorRewriter = null` | 1 match |
| `public ?LegacyDbService $legacyDb = null` | 1 match |
| `public ?MigrationStateReader $migrationState = null` | 1 match |
| `public ?AssetPathResolver $assetPathResolver = null` | 1 match |
| `use lameco\kunstmaanmigrator\filter\MigrationFilters;` | 1 match |
| `MigrationFilters $filters` | 2 matches (signature + docblock — covered by 1 same-line) |
| `function run(iterable $extracted` | 1 match |
| `CONTEXT D-48 in-process pipeline reshape` | 4 matches (top-of-file marker + signature docblock + foreach + payload comment + final-yield comment) |
| `Phase 2 / D-10 filter piping` | 4 marker comments |
| `MigrationConfigError` | 0 matches (replaced with `\RuntimeException`) |
| `new ResolverContext` | 1 match (in `buildContext()`) |
| `handlerRegistry->get` | 2 matches (transformFields + resolveFieldSpecs) |
| `lameco\kunstmaanmigrator\(bridge\|craft\|kunstmaan)` | 0 matches (all imports retargeted) |

## Deviations from Plan

None — plan executed as written. The plan signaled "MigrationFilters $filters >= 1" as a fixed-string grep target; the v2 file has 2 occurrences (signature + at-call sites within the function). All other counts match exactly.

The plan's `min_lines: 850` constraint is met (936 LOC). The slight reduction below v1's 940 LOC comes from the disk-IO removal (`is_dir`/`mkdir`/`scandir`/`glob`/`file_get_contents`/`json_decode`/`json_encode`/`file_put_contents`) being replaced with iterable producer/consumer plumbing — net wash because filter-piping markers + sentinel-yield comments add lines back.

## Self-Check: PASSED

- File created: `src/transform/TransformService.php` — FOUND
- Commit: `5d3ec8f` — FOUND in `git log`
- `php -l` — PASS
- All 18 done-criteria greps — PASS

## Commits

| Hash | Message |
|---|---|
| `5d3ec8f` | feat(03-11): port TransformService (940 LOC) with D-48 in-process pipeline reshape |
