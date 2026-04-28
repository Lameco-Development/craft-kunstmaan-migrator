---
phase: 03-etl-pipeline-field-handlers
plan: 05
subsystem: load / asset-ingest
tags: [verbatim-port, fh-03, jit-default, preload-opt-in, etl-05, filt-02]
requires: [03-02, 03-03]
provides:
  - "FH-03 JIT asset materialiser (resolveFromLegacyId)"
  - "FH-03 --preload-assets opt-in batch (ingestReferenced)"
  - "Media state-row writer (source='media', sourceKey='kuma_media:{id}')"
affects:
  - src/load/AssetMigrationService.php
tech-stack:
  added: []
  patterns:
    - "Yii Component sibling-DI slots (LegacyDbService, MigrationStateService, ?object serializedDecoder)"
    - "Local $counts accumulator standing in for v1 MigrationReport VO (re-bound by Plan 03-14)"
    - "JIT entry point + opt-in batch path (FH-03)"
key-files:
  created:
    - src/load/AssetMigrationService.php
  modified: []
decisions:
  - "v1 mediaById() helper (not yet in v2 LegacyDbService) replaced with inline queryOne SQL — preserves the lookup contract without expanding the LegacyDbService surface."
  - "MigrationFilters threading limited to --since on kuma_media.created_at; locale scoping is best-effort because kuma_media has no locale FK (locale narrowing happens at entry-discovery layer per PROJECT.md)."
  - "serializedDecoder null-slot lets remote-video metadata extraction be a no-op until Phase 4 wires the decoder; existing v1 video-id flow is preserved behind a null-check."
metrics:
  duration: "~25 minutes"
  completed: "2026-04-26"
  loc: 690
  v1-loc: 617
---

# Phase 03 Plan 05: Asset Migration Summary

Verbatim port of `~/Sites/craft-kunstmaan-migrator/src/bridge/load/AssetMigrationService.php` (617 LOC) into `src/load/AssetMigrationService.php` (690 LOC) with the FH-03 JIT-default + `--preload-assets` opt-in reshape, MigrationFilters threading per FILT-02, and four documented import drops (pre-scan service, queue job, serialized decoder, report VO) plus typed-error → \RuntimeException replacement. State-row contract for media (source='media', sourceKey='kuma_media:{id}', targetType='asset'|'video') and ETL-05 idempotency gate preserved byte-for-byte.

## Tasks Completed

| # | Name | Commit | Files |
|---|------|--------|-------|
| 1 | Verbatim port AssetMigrationService with JIT-vs-preload reshape | 972c3ee | src/load/AssetMigrationService.php |

## What Landed

- **JIT entry point (FH-03 default):** `public function resolveFromLegacyId(int $legacyId): int`. Fast-path on state hit; otherwise calls `ingestOne` and re-reads state to handle remote-video case (state row written, no Asset element).
- **--preload-assets opt-in (FH-03):** `public function ingestReferenced(MigrationOptions $opts, MigrationFilters $filters): void`. Repurposed from v1's batch-by-default; queries `kuma_media` directly via `LegacyDbService::queryAll` instead of pre-scanning. Threads `MigrationFilters->since` to scope by `created_at`.
- **`ingestOne(int, MigrationOptions): ?Asset`:** preserved as the per-id helper used by JIT and CKEditor fallbacks. v1's `mediaById` call replaced with inline `queryOne` (LegacyDbService surface unchanged).
- **`ingestBatch(array, MigrationOptions): void`:** preserved for tests / programmatic use. v1 served the queue job; v2 keeps the synchronous loop.
- **State-row contract (lines 30–35 area in v1) preserved verbatim:** `record('media', 'kuma_media:{id}', null, 'asset'|'video', $id, $uid, [...])`.
- **Re-run idempotency (ETL-05):** state-presence skip with `--force` size-fingerprint override unchanged.
- **Content fingerprint dedup:** `findExistingCraftAsset(folderId, filename, fileSize)` preserved.
- **--skip-assets fast path** (D-08-20) preserved.
- **--allowedFileExtensions skip** preserved.
- **D-08-23b verbose RCA error log** preserved.
- **`truncate()`** preserved.
- **`orphanReport()`** ported as a stub returning `[]` — the v1 referenced-id source (pre-scan) is gone; orphan tracking is deferred (NEXT-05).

## Reshape Disposition

| v1 Surface | Disposition | Notes |
|---|---|---|
| `bridge\load` namespace | flattened | → `load` |
| `resolveFromLegacyId(int): int` | added (FH-03 entry) | New public surface mandated by plan must_haves. Wraps `ingestOne` + state re-read to handle remote-video case. |
| `ingestReferenced` body | repurposed | v1 batch-by-default → v2 opt-in via `--preload-assets`. Method header carries an FH-03 docblock. |
| `ingestOne` body | preserved | `mediaById()` call replaced with inline `queryOne` SQL. |
| `ingestRow` body | preserved verbatim | The full file-copy + Asset element creation + state-row write flow is byte-identical except for `MigrationReport->incr/warn` → local `$counts` + `Craft::warning`. |
| `dispatchBatchedToQueue` | dropped intentionally | Queue out of scope per PROJECT.md / D-46. |
| `ingestBatch` | preserved | Synchronous-only (no queue). |
| `orphanReport` | partially ported (stub) | Returns `[]`. Deferred (NEXT-05). |
| `truncate` | preserved verbatim | — |
| Pre-scan service import | dropped | Page-driven JIT default per FH-03; ingestReferenced queries `kuma_media` directly when --preload-assets is set. |
| Queue-job import | dropped | Out of scope per PROJECT.md. |
| Serialized-decoder import | partially ported | `?object $serializedDecoder = null` slot; null-checked at remote-video parse site. Deferred to Phase 4. |
| Report VO import | partially ported | Local `$counts` accumulator + `Craft::warning(...)`. 19 sites marked `// MigrationReport VO deferred to Plan 03-13 — Phase 3 wiring lands in 03-14.` Plan 03-13 introduces the VO; Plan 03-14 re-wires consumers. |
| Typed-config-error import | dropped | Replaced with `\RuntimeException` per 02.1 reshape recipe. |
| `MigrationFilters` threading | added | `ingestReferenced` signature per FILT-02. `--since` scopes the kuma_media query. |
| `declare(strict_types=1);` | added | v1 omits it. |

### Counts (Plan 03-05)
| Pair | ported | dropped intentionally | dropped accidentally | partially ported |
|---|---:|---:|---:|---:|
| AssetMigrationService | 4 | 3 | 0 | 2 |

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 — Blocking dep] v1 `LegacyDbService::mediaById()` not in v2 surface**
- **Found during:** Task 1 / `ingestOne` port.
- **Issue:** v1 calls `$this->legacyDb->mediaById($kumaMediaId)`; the v2 `LegacyDbService` (Plan 02.1) surface is `db()/queryOne/queryAll/queryScalar/streamQuery` — no `mediaById`.
- **Fix:** Replaced with inline `queryOne('SELECT * FROM kuma_media WHERE id = :id LIMIT 1', [':id' => $id])`. Preserves the lookup semantics (single row or null) without expanding the LegacyDbService public surface.
- **Files modified:** src/load/AssetMigrationService.php
- **Commit:** 972c3ee

**2. [Rule 3 — Blocking dep] Plan must_haves require `resolveFromLegacyId(int): int` but v1 has no such method**
- **Found during:** Task 1 design.
- **Issue:** Plan must_haves and `<interfaces>` mandate `resolveFromLegacyId(int $legacyId): int`. v1's nearest analog is `ingestOne(int, MigrationOptions): ?Asset` — different signature; returns `?Asset`, takes options, can return null both for "missing" and for "successfully recorded as remote video".
- **Fix:** Added `resolveFromLegacyId` as a thin public wrapper that:
  1. Fast-paths via `migrationState->getTargetId(...)` (no re-ingest if already known).
  2. Calls `ingestOne(legacyId, new MigrationOptions())` for the cold path.
  3. Re-reads state after the call to surface the remote-video case (state row written, no Asset element).
  4. Returns 0 on miss; the Craft asset id otherwise. Matches the must_haves contract.
- **Files modified:** src/load/AssetMigrationService.php
- **Commit:** 972c3ee

**3. [Rule 3 — Blocking dep] `dispatchBatchedToQueue` dropped (queue out of scope)**
- **Found during:** Task 1 port.
- **Issue:** v1 had `dispatchBatchedToQueue(int $batchSize, bool $force): int` that constructed `AssetBatchJob` and pushed to queue.
- **Fix:** Method dropped entirely. PROJECT.md / D-46 takes the queue out of scope; no v2 caller needs this method (the only caller in v1 was a CLI command's queue dispatcher).
- **Files modified:** src/load/AssetMigrationService.php
- **Commit:** 972c3ee

**4. [Rule 2 — Missing/changed signature] `ingestBatch` lost the third `MigrationReport` parameter**
- **Found during:** Task 1 port.
- **Issue:** v1 signature was `ingestBatch(array, MigrationOptions, MigrationReport): void`. With MigrationReport VO deferred to Plan 03-13, the third parameter has no type to refer to.
- **Fix:** Method signature is now `ingestBatch(array $ids, MigrationOptions $opts): void`. A local `$counts` accumulator replaces the VO. Marked with `// MigrationReport VO deferred to Plan 03-13 — Phase 3 wiring lands in 03-14.` Plan 03-14 will re-add the VO parameter when the type lands.
- **Files modified:** src/load/AssetMigrationService.php
- **Commit:** 972c3ee

**5. [Rule 2 — Best-effort filter scoping]**
- **Found during:** Task 1 / `ingestReferenced` MigrationFilters threading.
- **Issue:** `kuma_media` has no direct locale foreign key — entries reference media via the page-part graph, not a locale column. So `MigrationFilters->locales` cannot scope the kuma_media SELECT directly.
- **Fix:** Threaded the `MigrationFilters` argument as documented; applied `$filters->since` to `created_at` (the only direct match). Locale scoping happens at the entry-discovery layer in v2 — when the entry set narrows to a locale, the JIT path discovers the asset subset. Documented in the inline comment.
- **Files modified:** src/load/AssetMigrationService.php
- **Commit:** 972c3ee

### Auth Gates
None.

### Out-of-scope discoveries
None.

## Threat Flags

| Flag | File | Description |
|------|------|-------------|
| (none) | — | All security-relevant surface (path traversal via AssetPathResolver::resolveLocal, filename sanitisation, allowedFileExtensions enforcement, --skip-assets fast path) was already in the plan threat-model and is preserved verbatim. |

## TDD Gate Compliance

Plan type is `execute` (not `tdd`); no TDD gates expected. No characterization tests written in this plan (Phase 3 transform-stage tests are a Plan 03-14 / phase-end requirement per CLAUDE.md test discipline).

## Verification

- `php -l src/load/AssetMigrationService.php` → "No syntax errors detected".
- `wc -l` → 690 lines (≥500 required, v1 was 617).
- All file-level acceptance greps pass:
  - `namespace lameco\kunstmaanmigrator\load;` count = 1
  - `function resolveFromLegacyId(int $legacyId): int` count = 1
  - `function ingestReferenced(MigrationOptions` count = 1
  - `MigrationFilters $filters` count = 1
  - `use lameco\kunstmaanmigrator\filter\MigrationFilters;` count = 1
  - `use lameco\kunstmaanmigrator\db\LegacyDbService;` count = 1
  - `AssetScanService` count = 0
  - `AssetBatchJob` count = 0
  - `MigrationConfigError` count = 0
  - `KunstmaanSerializedDecoder` count = 0
  - `public ?object $serializedDecoder = null` count = 1
  - `MigrationReport VO deferred` count = 19 (replacement-site markers)
  - `kuma_media:` count = 10 (state-row sourceKey format preserved)
  - `FH-03` count = 8
  - Old-namespace remnants: 0 matches.

## Self-Check: PASSED

- src/load/AssetMigrationService.php: FOUND
- Commit 972c3ee: FOUND in `git log`
- All done-criteria greps from PLAN.md verified above.
