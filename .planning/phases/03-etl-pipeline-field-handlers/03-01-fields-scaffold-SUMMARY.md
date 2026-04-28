---
phase: 03-etl-pipeline-field-handlers
plan: 01
title: fields-scaffold
wave: 1
status: complete
requirements_completed: [FH-01, FH-02]
files_created:
  - src/fields/FieldHandler.php
  - src/fields/FieldHandlerRegistry.php
  - src/fields/DeferredAssetToken.php
  - src/fields/ResolverContext.php
files_modified: []
commits:
  - 000189f feat(03-01): port FieldHandler interface + DeferredAssetToken VO
  - 4808be9 feat(03-01): port FieldHandlerRegistry with \RuntimeException reshape
  - 86a6236 feat(03-01): port ResolverContext VO with import retargeting
tasks_completed: 3
tasks_total: 3
deviations: 1
---

# Phase 03 Plan 01: fields-scaffold Summary

Verbatim port (modulo namespace flatten + import retargeting) of the four `src/fields/`
scaffold types every other Phase 3 plan depends on: the `FieldHandler` interface, the
hash-keyed `FieldHandlerRegistry`, the `DeferredAssetToken::emit()` token emitter, and
the immutable 7-arg `ResolverContext` VO.

## Tasks completed

| # | Task | Files | LOC | Commit |
|---|------|-------|----:|--------|
| 1 | Port FieldHandler interface + DeferredAssetToken VO | `FieldHandler.php`, `DeferredAssetToken.php` | 41 + 34 | `000189f` |
| 2 | Port FieldHandlerRegistry (MigrationConfigError → \RuntimeException reshape) | `FieldHandlerRegistry.php` | 52 | `4808be9` |
| 3 | Port ResolverContext VO with import retargeting | `ResolverContext.php` | 42 | `86a6236` |

All four files: `php -l` clean.

## Reconciliation result

| Pair | ported | dropped intentionally | dropped accidentally |
|---|---:|---:|---:|
| FieldHandler          | 2 | 0 | 0 |
| FieldHandlerRegistry  | 1 | 1 (MigrationConfigError import) | 0 |
| DeferredAssetToken    | 1 | 0 | 0 |
| ResolverContext       | 2 | 0 | 0 |
| **Plan 03-01 totals** | **6** | **1** | **0** |

Matches plan's `<reconciliation>` count expectation exactly.

## Mechanical edits applied

- **Namespace flatten** on all 4 files: `lameco\kunstmaanmigrator\bridge\fields\*` → `lameco\kunstmaanmigrator\fields\*`.
- **`declare(strict_types=1);`** added on line 3 of all 4 files (v1 omitted them; v2 convention per Phase 02.1 patterns).
- **`FieldHandlerRegistry` throw reshape:**
  - Dropped `use lameco\kunstmaanmigrator\models\MigrationConfigError;`
  - Replaced `throw MigrationConfigError::unknownHandler($id, array_keys($this->handlers));` with `throw new \RuntimeException(sprintf("FieldHandlerRegistry: unknown handler '%s' — registered: [%s].", $id, implode(', ', array_keys($this->handlers))));`
- **`ResolverContext` import retargeting (4 imports):**
  - `bridge\ckeditor\CkeditorRewriterService` → `finalize\CkeditorRewriterService`
  - `craft\load\AssetPathResolver` → `load\AssetPathResolver`
  - `kunstmaan\db\LegacyDbService` → `db\LegacyDbService`
  - `bridge\fields\MigrationStateReader` → `load\MigrationStateReader`
- **`DeferredAssetToken` paired-regex contract comment** appended after the closing `}` (verbatim from plan task 1) — documents the load-bearing coupling with `AtomicMigrationService::ingestAndResolveAssets()` (Plan 03-13).

## Behavioral preservation (zero changes)

- `FieldHandler::id(): string` and `FieldHandler::resolve(mixed, ResolverContext, array): mixed` signatures byte-identical to v1.
- `DeferredAssetToken::emit()` body: `return 'asset:' . $legacyId;` byte-identical.
- `FieldHandlerRegistry::register/get/ids` lookup-then-throw shape preserved; only the throw type changed (operator-facing `id` + `registered ids` context kept verbatim in the `\RuntimeException` message).
- `ResolverContext` 7-arg readonly constructor, arg order, types, `?LegacyDbService $legacyDb = null` default — all byte-identical to v1.

## Deviations from plan

### 1. [Rule 1 — Stale-docblock fix] FieldHandlerRegistry docblock updated to remove dangling `MigrationConfigError` references

- **Found during:** Task 2 verification.
- **Tension:** The plan's task action says *"DO NOT change ... the docblocks"*, but the explicit acceptance criterion `grep -c "MigrationConfigError" src/fields/FieldHandlerRegistry.php returns 0` requires zero mentions. After the import drop + throw replacement, two docblock mentions of `MigrationConfigError` remained (one describing the throw, one in a "Plan 06 swapped from RuntimeException to MigrationConfigError" history note that is *backwards* in v2 — v2 went *back* to `\RuntimeException`).
- **Resolution:** Updated the class docblock to describe the v2 behavior (`\RuntimeException` directly) plus a 3-line "v2 reshape" note explaining the historical context without naming the dropped class. The operator-facing message wording in the actual `throw` statement is unchanged — only the surrounding prose docblock was updated to reflect the v2 reality.
- **Files modified:** `src/fields/FieldHandlerRegistry.php` (docblock only — no behavior change).
- **Commit:** `4808be9`.
- **Why this is correct:** The acceptance grep is the contract; preserving stale historical references to a class that v2 explicitly does not have would leak the v1 design into v2 and confuse future readers. PATTERNS §2 reshape-recipe intent was that `MigrationConfigError` is excised from v2's surface — extending that to dependent prose is consistent with the recipe.

## Forward references

`ResolverContext` imports four classes; status of each:

| Import | Plan | Wave | On disk now? |
|--------|------|-----:|---|
| `lameco\kunstmaanmigrator\load\MigrationStateReader` | 03-02 | 1 | No (same-wave sibling) |
| `lameco\kunstmaanmigrator\load\AssetPathResolver` | 03-02 | 1 | No (same-wave sibling) |
| `lameco\kunstmaanmigrator\finalize\CkeditorRewriterService` | 03-06 | 2 | No |
| `lameco\kunstmaanmigrator\db\LegacyDbService` | (Phase 1) | — | Yes |

`php -l` does not require the imported classes to exist — it only checks syntax. Full
autoload-resolution validation lands in Plan 03-14 (Wave 5).

## Verification results

- `php -l src/fields/*.php` — 4/4 "No syntax errors detected".
- `grep -rF 'lameco\kunstmaanmigrator\bridge' src/fields/` — 0 matches (no v1 namespace leakage).
- `grep -rF 'MigrationConfigError' src/fields/` — 0 matches.
- All 21 file-level acceptance greps from tasks 1-3 pass.

## Self-Check: PASSED

**Files exist:**
- FOUND: `/Users/macbook25/Sites/craft-kunstmaan-migrator-revisited/src/fields/FieldHandler.php`
- FOUND: `/Users/macbook25/Sites/craft-kunstmaan-migrator-revisited/src/fields/FieldHandlerRegistry.php`
- FOUND: `/Users/macbook25/Sites/craft-kunstmaan-migrator-revisited/src/fields/DeferredAssetToken.php`
- FOUND: `/Users/macbook25/Sites/craft-kunstmaan-migrator-revisited/src/fields/ResolverContext.php`

**Commits exist:**
- FOUND: 000189f (FieldHandler + DeferredAssetToken)
- FOUND: 4808be9 (FieldHandlerRegistry reshape)
- FOUND: 86a6236 (ResolverContext)
