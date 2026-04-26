---
phase: 03-etl-pipeline-field-handlers
plan: 03
subsystem: load
tags: [state-table, idempotency, verbatim-port, firewall-pattern]
requires:
  - src/load/MigrationStateReader.php (Plan 03-02)
  - src/migrations/Install.php (Phase 1 / FND-02 — kunstmaanmigrator_state DDL)
provides:
  - lameco\kunstmaanmigrator\load\MigrationStateService (idempotency CRUD)
  - 8 CRUD methods (has/get/getTargetId/getTargetUid/record/updateMeta/forget/runOnce)
  - 3 helpers (getLastRunMeta/countBySource/all)
affects:
  - downstream handlers (consume MigrationStateReader narrow interface)
  - migrate/check D-17 drift detection (consumes getLastRunMeta)
  - migrate/report (consumes countBySource + all)
tech-stack:
  added:
    - none (uses existing yii\base\Component, craft\db\Query, craft\helpers\Db)
  patterns:
    - Yii Component CRUD over Craft DB (D-33)
    - Read-surface firewall via narrow interface (D-11)
    - Schema-sync invariant ($statePrefix ↔ Install.php STATE_TABLE)
key-files:
  created:
    - src/load/MigrationStateService.php (334 LOC)
  modified: []
decisions:
  - "Port MigrationStateService verbatim from v1 (356 LOC → 334 LOC after namespace flatten + docblock hedge dropped + declare(strict_types=1) added)."
  - "Drop v1 docblock hedge about kunstmaanSourceId custom-field replacing state lookups (CONTEXT D-48: v2 commits to state-table-only resume model for entries too)."
  - "Class stays non-final per v1 (allows test subclassing — verbatim discipline)."
  - "$targetUidSafe = $targetUid ?? '' coercion preserved verbatim — load-bearing for Craft uid() helper (char(36) NOT NULL DEFAULT '0' on MySQL)."
  - "$statePrefix default 'kunstmaanmigrator_state' verified against Install.php::STATE_TABLE constant (schema-sync invariant)."
metrics:
  duration: ~5 minutes
  completed: 2026-04-26
  tasks-completed: 1
  files-created: 1
  loc-added: 334
---

# Phase 3 Plan 03: State Service Summary

Verbatim port of `MigrationStateService` (the idempotency CRUD over `kunstmaanmigrator_state`) from v1's `bridge/load/` into v2's flat `load/` namespace, implementing the `MigrationStateReader` narrow interface from Plan 03-02 to preserve the write-surface firewall pattern (D-11).

## What was built

Single new file: `src/load/MigrationStateService.php` (334 LOC).

The service is a Yii Component that wraps the Phase 1-shipped `kunstmaanmigrator_state` table with:

- **Read API** (also satisfies `MigrationStateReader`): `get`, `getTargetId`, `getTargetUid`, `has`.
- **Write API** (NOT in `MigrationStateReader` — handlers must not see this): `record`, `updateMeta`, `forget`, `runOnce`.
- **Reporting helpers**: `getLastRunMeta` (for migrate/check D-17 drift detection), `countBySource` (for migrate/report), `all` (Generator-streamed full row dump for a source).

Implementing the narrow reader interface in-place means handlers receive only the read surface via `ResolverContext::$state`, while the controller layer that constructs the service holds the full write API. Same class, two views.

## Tasks completed

| # | Task | Commit | Files |
|---|------|--------|-------|
| 1 | Verbatim port MigrationStateService | `e253d8b` | `src/load/MigrationStateService.php` |

## Reshape applied (vs v1)

| v1 (file:line) | Disposition | v2 location / rationale |
|---|---|---|
| Namespace `lameco\kunstmaanmigrator\bridge\load` | flattened | `lameco\kunstmaanmigrator\load` per D-41 |
| `use lameco\kunstmaanmigrator\bridge\fields\MigrationStateReader;` | retargeted | `use lameco\kunstmaanmigrator\load\MigrationStateReader;` (Plan 03-02 lands the interface here) |
| Lines 16-47 (class docblock with kunstmaanSourceId custom-field hedge) | dropped intentionally | CONTEXT D-48: v2 state-table-only resume model — hedge no longer applies. Replaced with minimal D-48 docblock. |
| Lines 48-67 (class header + statePrefix + table()/db() helpers) | ported verbatim (modulo namespace) | Schema-sync chokepoint preserved. |
| Lines 74-356 (entire CRUD surface + helpers) | ported verbatim | All method bodies, variable names, SQL fragments byte-for-byte. |
| Line 134 area (`$targetUidSafe = $targetUid ?? '';`) | ported verbatim | Load-bearing — Craft uid() helper NOT NULL constraint compatibility. |
| (v2 addition) `declare(strict_types=1);` | added | v2 convention. |
| (v2 addition) Schema-sync invariant docblock above `$statePrefix` | added | Per task instructions — pins the invariant in source so future renames see the warning. |

### Reconciliation counts (Plan 03-03 only)

| Pair | ported | dropped intentionally | dropped accidentally |
|---|---:|---:|---:|
| MigrationStateService | 4 | 1 (kunstmaanSourceId hedge docblock) | 0 |

## Schema-sync invariant verification

`grep -c "STATE_TABLE.*=.*'{{%kunstmaanmigrator_state}}'" src/migrations/Install.php` → **1** ✓

`MigrationStateService::$statePrefix` default `'kunstmaanmigrator_state'` matches `Install.php::STATE_TABLE = '{{%kunstmaanmigrator_state}}'`. The DDL (Phase 1 / FND-02) and CRUD (Phase 3 / Plan 03-03) address the same physical table.

## Acceptance criteria

All 16 acceptance greps from PLAN.md `<done>` block passed:

| Criterion | Result |
|---|---|
| `php -l src/load/MigrationStateService.php` | No syntax errors detected |
| `class MigrationStateService extends Component implements MigrationStateReader` | 1 |
| `public string $statePrefix = 'kunstmaanmigrator_state';` | 1 (verified with `grep -F`) |
| `use lameco\kunstmaanmigrator\load\MigrationStateReader;` | 1 |
| `MigrationConfigError` (none expected) | 0 |
| `function record(` | 1 |
| `function get(` | 1 |
| `function has(` | 1 |
| `function forget(` | 1 |
| `function updateMeta(` | 1 |
| `function runOnce(` | 1 |
| `function getTargetId(` | 1 |
| `function getTargetUid(` | 1 |
| `?? ''` (targetUid null coercion preserved) | 1 |
| `lameco\kunstmaanmigrator\bridge` (none expected) | 0 |
| File line count >= 300 | 334 |
| Schema-sync invariant grep on Install.php | 1 |

## Deviations from Plan

None — plan executed exactly as written.

## Self-Check: PASSED

- File exists: `/Users/macbook25/Sites/craft-kunstmaan-migrator-revisited/src/load/MigrationStateService.php` ✓
- Commit exists: `e253d8b` (`git log --oneline | grep e253d8b`) ✓
- All acceptance greps return expected counts ✓
- Schema-sync invariant holds ✓
