---
phase: 03-etl-pipeline-field-handlers
plan: 07
subsystem: load
tags: [load, multi-site, attach, project-config, port]
requires:
  - 03-03 # MigrationStateService (sibling-DI dependency)
provides:
  - AttachService::attachFieldToEntryType (idempotent project-config attach helper)
  - EntryMigrationService::saveEntryForSites (sole Craft saveElement consumer)
  - public ?MigrationStateService $stateService slot on EntryMigrationService
  - public array $sites map slot on EntryMigrationService
affects:
  - phase 03-14 (Plugin::init must populate $sites + sibling-DI wire $stateService)
  - phase 03-13 (phase-grep check: saveElement only inside EntryMigrationService)
  - phase 04 (CFG-01 reinstates AttachService::attachAllFromSettings)
tech-stack:
  added: []
  patterns:
    - sibling-DI (?Foo $bar = null)
    - propagateChanges=false discipline (Pitfall 2)
    - reload-before-save for non-primary sites (Pitfall 2)
    - matrix block UID persistence via state-table meta (Pitfall 3)
key-files:
  created:
    - src/load/AttachService.php
    - src/load/EntryMigrationService.php
  modified: []
decisions:
  - AttachService::attachAllFromSettings stubbed (throws RuntimeException) until Phase 4 / CFG-01 declares Settings::$entryTypeUids
  - Verbatim port (D-46 discipline): v1 attachFieldToEntryType returns bool (interface in plan said void; v1 source-of-truth wins)
  - $sites map starts empty; Plugin::init wires in Plan 03-14 from LocalePreflight + Settings::$localeMap
metrics:
  duration: ~10 minutes
  completed: 2026-04-26
  tasks-completed: 2
  files-created: 2
  total-loc: 154 + 663 = 817
---

# Phase 03 Plan 07: Attach and Entry Migration Summary

Verbatim ports of v1's AttachService (partial — attachAllFromSettings deferred to Phase 4) and EntryMigrationService (the sole Craft saveElement consumer in the entire codebase) into the v2 flat `lameco\kunstmaanmigrator\load` namespace.

## What was built

### `src/load/AttachService.php` (154 LOC)

Idempotent helper that attaches the `kunstmaanSourceId` tracking field to an operator-selected entry type's field layout. Two-phase check (in-memory layout walk → project-config tabs walk) before mutating project-config. Returns `bool`: `true` when attach occurred, `false` for idempotent no-op or unresolvable entry type.

`attachAllFromSettings()` is **stubbed** for Phase 3 — it throws `RuntimeException` with a "deferred to Phase 4 / CFG-01" message because v2 `Settings` does not yet declare `$entryTypeUids` (Phase 1 only shipped connection + AI fields per D-15). CFG-01 will reinstate the v1 body.

### `src/load/EntryMigrationService.php` (663 LOC)

The shared per-site entry save helper. **The only saveElement() call site permitted in the codebase** — every other migration service (AssetMigrationService, AtomicMigrationService, future controller wiring) routes through this class. Plan 03-13 enforces this with a phase-grep check.

Public surface (preserved verbatim from v1):

```php
public array $sites = [];                                  // kuma_locale → Craft site handle
public ?MigrationStateService $stateService = null;        // sibling-DI

public function saveEntryForSites(
    int $sectionId,
    int $typeId,
    string $stateSource,
    string|int $stateKey,
    array $perSite,
    bool $force = false,
): Entry;
```

Load-bearing invariants preserved:

- **propagateChanges=false** on every `saveElement()` call (Pitfall 2)
- **Reload-before-save** for non-primary sites — `Entry::find()->id($id)->siteId($targetSiteId)->one()` before each per-site save (Pitfall 2)
- **Matrix block UID persistence** — extract `_sourcePartRef` positions BEFORE `applyPerSiteData` strips them, then re-key block payloads via `threadBlockUidsIntoPageBuilder` on subsequent runs (Pitfall 3)
- **Idempotent re-run short-circuit** — when `$existingId` resolves and `$force=false`, return without re-saving
- **Single-section reuse** — auto-created singleton entry is reused on first run (avoids "Could not generate a unique URI" validation failure)
- **Site cache** — `getSiteByHandle()` per-instance memoization

## Reshape applied (D-46 verbatim discipline)

Both files are byte-for-byte ports modulo:

1. **Namespace flatten:**
   - `lameco\kunstmaanmigrator\craft\services` → `lameco\kunstmaanmigrator\load` (AttachService)
   - `lameco\kunstmaanmigrator\craft\load` → `lameco\kunstmaanmigrator\load` (EntryMigrationService)
2. **Import retargeting:** `use lameco\kunstmaanmigrator\craft\migrations\Install;` → `use lameco\kunstmaanmigrator\migrations\Install;`
3. **Removed unused import:** `use lameco\kunstmaanmigrator\Plugin;` from AttachService (was only referenced by the now-stubbed `attachAllFromSettings`)
4. **Added `declare(strict_types=1);`** to both files (v1 omitted)
5. **`$stateService` typing:** v1 had `public object $stateService;` (untyped on purpose so tests could swap it). v2 narrows to `public ?MigrationStateService $stateService = null;` per the sibling-DI pattern (PATTERNS §15) — Plan 03-14 will wire it in `Plugin::init()`.
6. **`attachAllFromSettings` stub-out** (AttachService) — body replaced with `throw new \RuntimeException(...)` containing the literal string `deferred to Phase 4 / CFG-01`.

No other logic changed. The fast-path layout walk (AttachService lines 50-62), project-config write (lines 67-130), saveEntryForSites flow (lines 95-345), and all 7 private helpers in EntryMigrationService were ported byte-for-byte.

## Reconciliation against v1

### AttachService

| v1 location | Description | Disposition | v2 location |
|---|---|---|---|
| Lines 37-137: `attachFieldToEntryType` | Idempotent project-config attach with two-phase fast-path | ported verbatim | `src/load/AttachService.php:37-137` |
| Lines 50-62: in-memory layout walk fast-path | Operator-observable Project-Config quietness on idempotent re-runs | ported verbatim | `src/load/AttachService.php:50-62` |
| Lines 147-177: `attachAllFromSettings` body | v1 reads `Settings::$entryTypeUids` and walks | **partially ported — Phase 4 follow-up** | Stubbed — throws RuntimeException; v2 `Settings` lacks `$entryTypeUids` (CFG-01 owns) |

### EntryMigrationService

| v1 location | Description | Disposition | v2 location |
|---|---|---|---|
| Lines 53-57 docblock: propagateChanges=false discipline | Sole saveElement consumer + multi-site reload-before-save | ported verbatim | `src/load/EntryMigrationService.php:54-58` |
| Lines 95-102: `saveEntryForSites` signature | Public API surface | ported verbatim | Same args (sectionId, typeId, stateSource, stateKey, perSite, force=false) |
| Line 73: `public array $sites = []` | v1's Plugin.php:292 hardcoded `['nl' => 'default', 'en' => 'en']` | reshape: empty default | Plan 03-14 will populate from LocalePreflight::detect() + Settings::$localeMap |
| Line 80: `public object $stateService` | v1 untyped object slot | reshape: typed `?MigrationStateService = null` | Sibling-DI per PATTERNS §15 — Plan 03-14 wires |
| Lines 144-345: full saveEntryForSites body | 9-step multi-site save with reload + block UID threading | ported verbatim | Same logic, same line numbering shift |
| All 7 private helpers (applyPerSiteData, stripSourcePartRefs, threadBlockUidsIntoPageBuilder, looksLikeMatrixPayload, extractSourceRefPositions, collectBlockUidsByPosition, getSiteByHandle) | Field-write + matrix-block UID persistence + site cache | ported verbatim | All preserved byte-for-byte |
| MigrationConfigError import | Typed errors | dropped intentionally (v1 already used \RuntimeException) | n/a — v1's craft/load variant already used `use RuntimeException;` |

### Counts (Plan 03-07)

| Pair | ported | dropped intentionally | dropped accidentally | partially ported |
|---|---:|---:|---:|---:|
| AttachService | 2 | 1 (Plugin import — now unused after stub) | 0 | 1 (attachAllFromSettings) |
| EntryMigrationService | 2 | 0 (v1 craft/load variant did not use MigrationConfigError) | 0 | 0 ($sites + $stateService are reshapes, not partial ports) |

## Done criteria (verified)

### AttachService
- [x] `php -l src/load/AttachService.php` → No syntax errors
- [x] `grep -c 'namespace lameco\\kunstmaanmigrator\\load;'` → 1
- [x] `grep -c 'use lameco\\kunstmaanmigrator\\migrations\\Install;'` → 1
- [x] `attachFieldToEntryType(string $entryTypeUid, string $fieldUid)` present (line 37)
- [x] `attachAllFromSettings` present (line 147)
- [x] `deferred to Phase 4 / CFG-01` literal present (line 150)
- [x] `MigrationConfigError` → 0 occurrences
- [x] Lines: 154 (≥ 130)

### EntryMigrationService
- [x] `php -l src/load/EntryMigrationService.php` → No syntax errors
- [x] `grep -c 'namespace lameco\\kunstmaanmigrator\\load;'` → 1
- [x] `function saveEntryForSites(` present
- [x] `public array $sites = [];` present (line 76)
- [x] `public ?MigrationStateService $stateService = null;` present (line 81)
- [x] `MigrationConfigError` → 0 occurrences
- [x] `propagate` → 5 occurrences (multi-site discipline preserved, ≥1)
- [x] `saveElement` → 7 occurrences (this IS the consumer, ≥1)
- [x] Lines: 663 (≥ 580)
- [x] No v1-namespace leakage (`bridge|craft|kunstmaan` nested namespaces) → 0

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Plan-source mismatch] AttachService::attachFieldToEntryType return type**
- **Found during:** Task 1 (reading v1 source)
- **Issue:** Plan's `<interfaces>` block declared `attachFieldToEntryType(...): void` but v1 source returns `bool` (line 37 of v1 file)
- **Fix:** Adopted v1 verbatim per D-46 (verbatim port discipline). Returns `bool`: `true` for attach-occurred, `false` for idempotent no-op / unresolvable entry type. Plan's done-criteria grep `function attachFieldToEntryType(string \$entryTypeUid, string \$fieldUid)` is a substring match that matches both signatures, so this preserves done-criteria conformance.
- **Files modified:** src/load/AttachService.php
- **Commit:** d9c8f7b

**2. [Rule 1 - Cleanup] Removed unused `Plugin` import from AttachService**
- **Found during:** Task 1 (post-write)
- **Issue:** v1 used `Plugin::getInstance()` inside `attachAllFromSettings`. Since that method body is stubbed, the `Plugin` import becomes dead code.
- **Fix:** Removed the `use lameco\kunstmaanmigrator\Plugin;` line. Phase 4 / CFG-01 will reinstate when reinstating the method body.
- **Files modified:** src/load/AttachService.php
- **Commit:** d9c8f7b

### Plan ambiguities resolved

**3. v1 craft/load variant did not use MigrationConfigError**
- **Plan said:** "Drop and replace MigrationConfigError: \RuntimeException"
- **Found:** v1's `craft/load/EntryMigrationService.php` already imports `use RuntimeException;` and throws `RuntimeException` directly. No reshape needed.
- **Action:** No change required. Reconciliation table marks "0 dropped intentionally" for EntryMigrationService.

## Authentication gates

None — pure file-creation work, no external services touched.

## Threat Flags

None — both files port verbatim from v1, no new security-relevant surface (no new endpoints, no new auth paths, no new file access patterns, no schema changes). The threat surface is identical to v1's.

## Known Stubs

**AttachService::attachAllFromSettings** — intentional stub. Throws `RuntimeException` with explicit `deferred to Phase 4 / CFG-01` message. Will be reinstated in Phase 4 when CFG-01 declares `Settings::$entryTypeUids`. Plan 03-14's sibling-DI wiring does NOT call this method — only `attachFieldToEntryType` is exercised in Phase 3.

## Commits

| Task | Commit | Files |
|------|--------|-------|
| 1 — AttachService partial port | d9c8f7b | src/load/AttachService.php (154 LOC) |
| 2 — EntryMigrationService verbatim port | 7f281c0 | src/load/EntryMigrationService.php (663 LOC) |

## Self-Check: PASSED

- [x] src/load/AttachService.php exists (154 LOC, php -l clean)
- [x] src/load/EntryMigrationService.php exists (663 LOC, php -l clean)
- [x] Commit d9c8f7b in `git log` (verified)
- [x] Commit 7f281c0 in `git log` (verified)
- [x] Both files have `declare(strict_types=1);`
- [x] Both files in flat `lameco\kunstmaanmigrator\load` namespace
- [x] No `MigrationConfigError` references remain (replaced/already absent)
- [x] No v1-namespace leakage (`bridge|craft|kunstmaan` as nested segments) in either file
- [x] EntryMigrationService is the sole `saveElement` consumer (Plan 03-13 will phase-grep enforce; this plan's scope ends at port)
- [x] AttachService::attachAllFromSettings stubbed with the required Phase 4 deferral marker
