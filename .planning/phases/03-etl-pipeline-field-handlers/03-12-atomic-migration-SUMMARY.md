---
phase: 03-etl-pipeline-field-handlers
plan: 12
subsystem: load
tags: [atomic, transaction, jit-assets, deferred-token, etl-04, etl-05, fh-04, idempotency]
requires:
  - src/load/MigrationStateService.php (Plan 03-03)
  - src/load/AssetMigrationService.php (Plan 03-05) — resolveFromLegacyId
  - src/load/EntryMigrationService.php (Plan 03-07) — saveEntryForSites
  - src/load/MigrationOptions.php (Plan 03-02)
  - src/fields/DeferredAssetToken.php (Plan 03-01) — paired-regex contract
provides:
  - src/load/MigrationReport.php — per-run incr/warn/recordFailure VO
  - src/load/AtomicMigrationService.php — ETL-04 per-entry transactional driver
affects:
  - Plan 03-14 — wires sibling-DI slots in Plugin::init() (migrationStateService, entryMigrationService, assetMigrationService) + registers atomicMigrationService Yii component + integrates MigrateController; rebinds Plan 03-05's deferred markers in AssetMigrationService.
  - Phase 4 / ADP-01 — reinstates the SEOmatic closure call inside the existing transaction at the marker site.
tech-stack:
  added: []
  patterns:
    - "Phase A / Phase B split (file-I/O before DB transaction) — Pitfall 1 / PATTERNS §13."
    - "Atomic-always-on per-entry transaction (ETL-04) — no --atomic flag."
    - "Paired-regex contract /^asset:\\d+$/ + /^asset:(\\d+)$/ tightly coupled to DeferredAssetToken::emit (FH-04 load-pass)."
    - "Sibling-DI slots: public ?Foo \\$foo = null wired by Plugin::init in Plan 03-14."
key-files:
  created:
    - src/load/MigrationReport.php
    - src/load/AtomicMigrationService.php
  modified: []
decisions:
  - "Comment-marker reinstatement point chosen over an empty closure for Phase 4 / ADP-01 — keeps closure shape stable so Phase 4 only re-inserts the call without restructuring."
  - "AssetResolver folded into AssetMigrationService::resolveFromLegacyId at sibling-DI level (advisor decision); $resolver->... rewritten to $this->assetMigrationService->... inside ingestAndResolveAssets."
  - "Idempotency gate uses sibling-DI ($this->migrationStateService) instead of $module-> reference (per plan step 8 explicit instruction); transaction closure preserves $module->... references verbatim from v1 because Plugin::config (Plan 03-14) registers them as Yii components."
metrics:
  duration: ~12 minutes (single-pass, 2 files)
  completed: 2026-04-26
  tasks_total: 2
  tasks_completed: 2
  files_created: 2
  files_modified: 0
---

# Phase 3 Plan 12: Atomic Migration Summary

**One-liner:** Per-entry atomic load driver (ETL-04) with idempotency gate (ETL-05) and FH-04 deferred-asset-token resolver — verbatim port from v1's bridge/load/AtomicMigrationService.php modulo namespace flatten, AssetResolver fold-in, and SEOmatic closure-call deferred to Phase 4. MigrationReport VO greenfield with D-50 5-frame failure schema.

## Tasks completed

| Task | Name                                                      | Commit  | Files                                |
| ---- | --------------------------------------------------------- | ------- | ------------------------------------ |
| 1    | MigrationReport VO + back-bind markers                    | 7dc669c | src/load/MigrationReport.php         |
| 2    | Verbatim port AtomicMigrationService (atomic-always-on)   | ebc8567 | src/load/AtomicMigrationService.php  |

## Reconciliation (Plan 03-12)

### MigrationReport VO

| Rule                                                       | Description                                | Disposition                                                                                        |
| ---------------------------------------------------------- | ------------------------------------------ | -------------------------------------------------------------------------------------------------- |
| `incr(string, int=1)` + `warn(string)` API                 | v1 surface (in `models\MigrationReport`)   | ported (rebuilt as small VO; v1 may have been more elaborate but Phase 3 only needs incr/warn)     |
| `recordFailure(legacyId, slug, handler, Throwable)`        | v2 D-50 failure schema                     | new in v2 (D-50) — last-5-frames stack excerpt; auto-increments `'failed'` bucket                  |

### AtomicMigrationService

| v1 Rule (file:line)                                                          | Description                                       | Disposition                                                                                  | v2 location / rationale                                                                                                                                            |
| ---------------------------------------------------------------------------- | ------------------------------------------------- | -------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Lines 117–135 — Phase A file I/O before transaction                          | Asset materialisation outside tx                  | ported verbatim                                                                              | Same file. File copies aren't transactional — preserved discipline.                                                                                                |
| Lines 145–184 — Phase B Craft DB transaction wrapping saveEntryForSites + state record | Atomic-always-on                          | ported verbatim (modulo SEO closure drop)                                                    | ETL-04 satisfied; closure shape preserved for Phase 4.                                                                                                             |
| Line 182 — `$module->seoMigrationService->migrateForEntry(...)` inside closure | SEOmatic per-entry write                       | partially ported — Phase 4 / ADP-01 reinstates                                                | Comment marker at the call site (without literal `seoMigrationService->migrateForEntry` token, satisfying done-criterion grep) preserves the closure shape; Phase 4 re-inserts. |
| Lines 209–212 — deferred-token resolver `/^asset:\d+$/` + capture form       | FH-04 load-pass token resolution                  | ported byte-for-byte                                                                         | Tightly coupled to `DeferredAssetToken::emit()` (Plan 03-01) — any change breaks the FH-04 contract.                                                               |
| Lines 107–116 — idempotency gate (`existingId !== null && !$overwrite`)      | ETL-05 state-table-presence skip                  | ported (rewired through sibling-DI: `$this->migrationStateService->getTargetId`)             | Same gate semantics; sibling-DI swap per plan step 8.                                                                                                              |
| `use AssetResolver` (separate v1 class)                                      | v1 had a dedicated AssetResolver                  | dropped intentionally                                                                        | Advisor-locked: AssetMigrationService implements the resolver surface directly. `$this->assetMigrationService->resolveFromLegacyId()` replaces `$resolver->...`.   |
| `use MigrationReport` (v1 namespace `models\MigrationReport`)                | Per-run counters                                  | retargeted to local namespace                                                                | Same namespace as this file — no import needed.                                                                                                                    |
| `MigrationConfigError` typed throws                                          | Typed error class                                 | dropped intentionally                                                                        | `\RuntimeException` (already what v1 used here too — no rewrite needed).                                                                                           |
| `static` `ingestAndResolveAssets(array, AssetResolver)`                      | Two-arg static helper                             | ported as instance method (`private function ingestAndResolveAssets(array)`)                  | AssetResolver folded; method now reads `$this->assetMigrationService` and captures it via local var into the static recursive closure for self-reference.          |

### Counts (Plan 03-12 only)

| Pair                       | ported | dropped intentionally | dropped accidentally | partially ported | new in v2 |
| -------------------------- | -----: | --------------------: | -------------------: | ---------------: | --------: |
| MigrationReport            |      1 |                     0 |                    0 |                0 |         1 (recordFailure D-50) |
| AtomicMigrationService     |      4 |                     2 (AssetResolver, MigrationConfigError) | 0 | 1 (SEOmatic closure call — Phase 4) | 0 |

## Verification results

- `php -l src/load/MigrationReport.php` → No syntax errors.
- `php -l src/load/AtomicMigrationService.php` → No syntax errors.
- LOC: MigrationReport.php = 96 (>= 40 required); AtomicMigrationService.php = 256 (>= 220 required).
- `grep -c "namespace lameco\\kunstmaanmigrator\\load;" src/load/AtomicMigrationService.php` → 1.
- `grep -c "Craft::\$app->db->transaction"` → 1 (atomic-always-on transaction preserved).
- `grep -c "saveEntryForSites"` → 4 (entry save called inside closure, plus docblock refs).
- Asset-token match regex `/^asset:\d+$/` present (>= 1).
- Asset-token capture regex `/^asset:(\d+)$/` present (>= 1).
- `grep -c "resolveFromLegacyId"` → 4 (>= 1).
- `grep -c "ETL-05 idempotency"` → 1 (gate comment present).
- `grep -c "PHASE 4 / ADP-01"` → 2 (>= 1; SEOmatic deferral marker + reinstatement comment).
- `grep -c "seoMigrationService->migrateForEntry"` → 0 (call dropped — comment text reworded to avoid the literal token).
- `grep -c "AssetResolver"` → 0 (v1 import dropped).
- `grep -c "MigrationConfigError"` → 0.
- `grep -c "public ?MigrationStateService"` → 1.
- `grep -c "public ?EntryMigrationService"` → 1.
- `grep -c "public ?AssetMigrationService"` → 1.
- `grep -rEn 'lameco\\kunstmaanmigrator\\(bridge|craft|kunstmaan)' src/load/AtomicMigrationService.php` → 0 matches.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Tension between plan action body and done criterion] SEOmatic comment-marker phrasing**

- **Found during:** Task 2 verification.
- **Issue:** The plan's `<action>` step 6 instructed embedding the v1 line `$module->seoMigrationService->migrateForEntry((int) $entry->id, $opts, $refIdsByLocale);` as a literal-text comment marker for Phase 4. The plan's `<done>` criterion immediately below required `grep -c "seoMigrationService->migrateForEntry"` to return 0. These two instructions contradict.
- **Resolution:** Followed the spirit of both. The closure-shape and reinstatement intent are preserved via a prose comment marker ("SEOmatic per-entry write goes here. v1 invoked the SEOmatic service from this site with the saved entry id, $opts, and $refIdsByLocale...") which describes the omitted call without containing the literal `seoMigrationService->migrateForEntry` token, so the grep returns 0 as required while Phase 4 still has unambiguous reinstatement guidance.
- **Files modified:** src/load/AtomicMigrationService.php (only — applied during initial Write).
- **Commit:** ebc8567.

### Auto-added Critical Functionality

None — the plan covered every needed surface.

### Authentication Gates

None.

## Known Stubs

None — both files are functionally complete for their Phase 3 scope.

The Phase 4 / ADP-01 reinstatement point is **not** a stub: the closure shape is in place, the transaction wraps a real saveEntryForSites call, and the comment marks where Phase 4's SEOmatic call slots in. The only thing missing is SEOmatic itself, which is Phase 4's plan-of-record.

## Threat Flags

None — no new network endpoints, auth paths, file access patterns, or schema changes at trust boundaries are introduced. Phase A asset materialisation already existed in v1 and is governed by the same `--skipAssets` gate.

## TDD Gate Compliance

Plan type is `execute` (not `tdd`). The transform-stage characterization-test gate (PROJECT.md test discipline) applies to Phase 3 as a whole and is not a per-plan gate. No deviation.

## Self-Check: PASSED

- FOUND: src/load/MigrationReport.php
- FOUND: src/load/AtomicMigrationService.php
- FOUND: 7dc669c (commit "feat(03-12): add MigrationReport VO with D-50 failure schema")
- FOUND: ebc8567 (commit "feat(03-12): port AtomicMigrationService verbatim with SEOmatic call dropped")
- All `<done>` criteria for both tasks pass per the verification results above.
