---
phase: 03-etl-pipeline-field-handlers
plan: 06
subsystem: finalize
tags: [ckeditor, finalize, fin-01, fin-02, fh-04, verbatim-port, greenfield]
requires:
  - lameco\kunstmaanmigrator\load\MigrationStateService   # state-table reads (Plan 03-03)
  - lameco\kunstmaanmigrator\db\LegacyDbService           # NT→node_id legacy join (Phase 1)
  - lameco\kunstmaanmigrator\filter\MigrationFilters      # entity-type scope (Phase 2.1)
provides:
  - lameco\kunstmaanmigrator\finalize\CkeditorRewriterService  # FIN-01 + FIN-02 + FH-04 inline rewrite
  - lameco\kunstmaanmigrator\finalize\FinalizeWalker           # greenfield finalize-pass orchestrator
affects:
  - Plugin::init() (Plan 03-14) — sibling-DI wiring of $rewriter->migrationState/legacyDb/assetResolver and $finalizeWalker->rewriter
  - PlainTextHandler 'ckeditor' mode (Plan 03-08) — calls $ctx->ck->rewrite() inline during transform
  - MigrateController migrate/finalize action (Plan 03-13) — invokes FinalizeWalker->walk()
tech-stack:
  added: []   # No new composer deps in this plan; craftcms/ckeditor noted as deferred for runtime
  patterns:
    - "Verbatim port from v1 (PATTERNS §16) — namespace flatten + import retargeting only"
    - "Sibling-DI nullable property slots (CkeditorRewriterService->migrationState/legacyDb/assetResolver)"
    - "saveElement(propagate=false) — second documented consumer (the other is EntryMigrationService Plan 03-07)"
    - "Idempotent re-run via diff guard (rewritten === current → skip save)"
    - "class_exists guard for soft composer dep on craftcms/ckeditor"
key-files:
  created:
    - path: src/finalize/CkeditorRewriterService.php
      lines: 532
      purpose: "FIN-01 + FIN-02 + FH-04 inline-rewrite path. Verbatim port from v1 src/bridge/ckeditor/CkeditorRewriterService.php (529 LOC)."
    - path: src/finalize/FinalizeWalker.php
      lines: 140
      purpose: "Greenfield finalize-pass orchestrator. Walks Entry::find()->siteId('*')->each(50) per CKEditor field, rewrite + propagate=false save."
  modified: []
decisions:
  - "Verbatim port discipline (D-46) honored — only namespace + import edits applied; FIN-01 regexes and 6-step pipeline byte-identical to v1."
  - "AssetResolver responsibility folded into AssetMigrationService (Phase 3 advisor decision); typed as ?object slot, wired by Plugin::init() Plan 03-14."
  - "Greenfield FinalizeWalker chooses Entry::find()->siteId('*')->each(50) for memory and propagate=false for multi-site discipline (mirrors EntryMigrationService)."
  - "class_exists(\\craft\\ckeditor\\Field::class) guard avoids a hard composer dep at PHP-lint time — runtime-required when migrated entries actually carry CKEditor fields."
metrics:
  duration: "~12 min"
  tasks_completed: 2
  files_created: 2
  lines_of_code: 672
  completed: 2026-04-26
---

# Phase 3 Plan 06: CKEditor Rewriter + FinalizeWalker Summary

Verbatim 529-LOC port of v1's `CkeditorRewriterService` plus a 140-LOC greenfield `FinalizeWalker` that satisfies FIN-01's "walk every CKEditor field across every migrated entry" mandate.

## What Shipped

### `src/finalize/CkeditorRewriterService.php` (532 LOC)

A byte-for-byte port of `~/Sites/craft-kunstmaan-migrator/src/bridge/ckeditor/CkeditorRewriterService.php` (529 LOC) with only the mechanical edits the plan called out:

1. **Namespace flatten:** `lameco\kunstmaanmigrator\bridge\ckeditor` → `lameco\kunstmaanmigrator\finalize`.
2. **Imports retargeted:** `bridge\load\MigrationStateService` → `load\MigrationStateService`; `kunstmaan\db\LegacyDbService` → `db\LegacyDbService`.
3. **`declare(strict_types=1);` prepended** (v1 omitted it).
4. **Explanatory annotation** added above `public ?object $assetResolver = null;` documenting the Phase 3 advisor decision to fold AssetResolver into AssetMigrationService and the Plan 03-14 Plugin::init() wiring contract.
5. **MigrationConfigError step was a no-op:** v1 didn't import or throw it. Verified zero matches.

**Load-bearing FIN-01 regex constants — preserved byte-for-byte (v1 lines 50, 58):**

```php
public const KUMA_MEDIA_PLACEHOLDER_REGEX = '~(?:\[|%5B)M(\d+)(?:\]|%5D)~i';
public const KUMA_NT_PLACEHOLDER_REGEX    = '~(?:\[|%5B)NT(\d+)(?:\]|%5D)~i';
```

Both `[` and `%5B` (URL-encoded) variants matched, `i` flag for `%5b`/`%5d` lower-case. These are the canonical FIN-01 markers — any drift would silently break the URL-encoded path.

**6-step rewrite() pipeline:** rewriteAssetAttributes → rewriteMediaPlaceholders → rewriteNodeTranslationPlaceholders → rewriteEntryLinks → stripKumaClasses → removeEmptyParagraphs. Verbatim — every cache (`$urlIdCache`, `$kumaMediaIdCache`, `$ntToEntryCache`), every warm guard, every regex, every SQL fragment.

**FIN-02 strict policy:** Unresolvable tokens are left verbatim with adjacent `<!-- MIGRATION:UNRESOLVED source=... -->` HTML comments (4 distinct emission sites preserved).

**Sibling-DI slots:** `$migrationState`, `$legacyDb`, `$assetResolver` — all nullable, wired by Plugin::init() Plan 03-14.

### `src/finalize/FinalizeWalker.php` (140 LOC, greenfield)

No v1 analog at this orchestration layer — v1 did inline-only rewriting via PlainTextHandler 'ckeditor' mode. Designed at Phase 3 / Plan 03-06 per PATTERNS.md "No Analog Found" section.

**Public surface:**

```php
final class FinalizeWalker extends Component
{
    public ?CkeditorRewriterService $rewriter = null;

    /** @return array{processed: int, rewritten: int, unresolvable: int} */
    public function walk(MigrationFilters $filters): array;
}
```

**Mechanics:**

- `Entry::find()->siteId('*')->each(50)` — wildcard site walks per-(entry, siteId) pair, batched 50 for memory.
- Optional `->type([...])` scope when `$filters->entities` is non-empty.
- Per entry, walks the field layout, filters to `\craft\ckeditor\Field` instances (guarded by `class_exists` for soft composer dep).
- Casts CKEditor `FieldData` to string before passing to `$this->rewriter->rewrite($current, $entry->siteId)`.
- Diff guard: if `$rewritten === $current`, skip the save (idempotent re-run).
- `Craft::$app->elements->saveElement($entry, true, false)` — propagate=false to honor EntryMigrationService's per-(entry, siteId) discipline.
- Unresolvable count incremented when output contains `<!-- MIGRATION:UNRESOLVED`.
- `saveElement` returning false throws `\RuntimeException` with entry id + site id + first validation error.

**FinalizeWalker is the second of two documented `saveElement` consumers** — the other is EntryMigrationService (Plan 03-07). The propagate=false invariant is shared.

## Deviations from Plan

None. The plan's mechanical-edit list lined up almost exactly with what was needed; the only "delta" the advisor flagged was that v1 already had `public ?object $assetResolver = null;` (so step 4 of Task 1 reduced to adding the explanatory comment) and v1 had no `MigrationConfigError` import or throw (so step 3 was a no-op verified by `grep -c MigrationConfigError = 0`). Both alignments were anticipated by the plan's own done criteria.

## Deferred Items

- **`craftcms/ckeditor` is not yet listed in `composer.json` `require`.** Per the plan ("php -l does not check class existence — only syntax") this is intentionally deferred. FinalizeWalker uses `class_exists(\craft\ckeditor\Field::class, true)` as a soft guard, so the file lints and runs cleanly even without the dep. The dep becomes a runtime requirement only when migrated entries actually carry CKEditor fields. Recommend adding `"craftcms/ckeditor": "^4.0"` to `require` during Plan 03-13 or 03-14 wiring (whichever lands first), or earlier if a downstream plan needs the symbol resolved at static-analysis time.

## Threat Flags

None. CkeditorRewriterService is a pure-PHP HTML transformer with read-only legacy-DB queries (delegated through `LegacyDbService` which already enforces no-writes per D-13). FinalizeWalker writes to Craft entries via `saveElement` but only inside the `migrate/finalize` console action, which is gated by `NeverProductionTrait` upstream (CLI controller, not introduced here).

## Verification

| Check | Result |
| --- | --- |
| `php -l src/finalize/CkeditorRewriterService.php` | No syntax errors |
| `php -l src/finalize/FinalizeWalker.php` | No syntax errors |
| Lines (CkeditorRewriterService) | 532 (≥ 480 required) |
| Lines (FinalizeWalker) | 140 (≥ 100 required) |
| FIN-01 media regex grep | 1 match (byte-identical to v1) |
| FIN-01 NT regex grep | 1 match (byte-identical to v1) |
| FIN-02 `MIGRATION:UNRESOLVED` (CkeditorRewriterService) | 4 matches |
| FIN-02 `MIGRATION:UNRESOLVED` (FinalizeWalker) | 2 (1 docblock + 1 detection) |
| 6-step pipeline method tokens | 13 grep matches (covers all 6 method names + their internal call sites) |
| `siteId('*')` (FinalizeWalker) | 1 (call site) |
| `propagate` mentions (FinalizeWalker) | 4 (docblock + comment + invariant assertion + saveElement comment) |
| `MigrationConfigError` matches | 0 (intentionally absent) |
| Stale namespaces (`bridge\|craft\|kunstmaan` namespace prefix) | 0 |

## Reconciliation

### CkeditorRewriterService

| v1 Rule (file:line) | v2 Disposition | Notes |
| --- | --- | --- |
| Lines 50, 58 — KUMA_MEDIA / KUMA_NT placeholder regex constants | ported byte-for-byte | FIN-01 load-bearing |
| Lines 98–127 — 6-step `rewrite()` pipeline | ported verbatim | All 6 private methods, all 3 caches, all warm guards |
| Lines 23–25, 198, 222, 426 — `<!-- MIGRATION:UNRESOLVED -->` markers | ported verbatim | FIN-02 strict policy |
| Line 76 — `public ?object $assetResolver = null;` | ported + comment annotation | Already correct typing in v1; only comment added |
| `MigrationConfigError` import / throws | not applicable | v1 has no such reference |
| `declare(strict_types=1);` | added | v1 omitted |

### FinalizeWalker

| Rule | Disposition | Notes |
| --- | --- | --- |
| Greenfield orchestrator | new in v2 | No v1 analog at orchestration layer |

### Counts (Plan 03-06 only)

| Pair | ported | dropped intentionally | dropped accidentally | new in v2 |
| --- | ---: | ---: | ---: | ---: |
| CkeditorRewriterService | 4 | 0 | 0 | 0 |
| FinalizeWalker | 0 | 0 | 0 | 1 |

(Note: the plan's reconciliation projected `1 dropped intentionally (MigrationConfigError, if present)` for CkeditorRewriterService; verified absent in v1, so the actual count is 0.)

## Self-Check: PASSED

- `src/finalize/CkeditorRewriterService.php` exists (532 lines, php -l clean)
- `src/finalize/FinalizeWalker.php` exists (140 lines, php -l clean)
- Commit `76c9f1c` — `feat(03-06): port CkeditorRewriterService verbatim from v1`
- Commit `1332411` — `feat(03-06): greenfield FinalizeWalker — per-site CKEditor rewrite pass`
- All Task 1 done-criteria greps pass (NS=1, MEDIA=1, NT=1, UNRESOLVED=4, pipeline=13, MigrationStateService import=1, LegacyDbService import=1, assetResolver slot=1, MigrationConfigError=0, stale-NS=0)
- All Task 2 done-criteria greps pass (NS=1, class=1, rewriter prop=1, walk sig=1, siteId('\*')=1, UNRESOLVED=2, propagate=4)
