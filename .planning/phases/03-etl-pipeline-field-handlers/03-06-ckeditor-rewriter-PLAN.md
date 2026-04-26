---
phase: 03-etl-pipeline-field-handlers
plan: 06
type: execute
wave: 2
depends_on: ['03-03']
files_modified:
  - src/finalize/CkeditorRewriterService.php
  - src/finalize/FinalizeWalker.php
autonomous: true
requirements: [FIN-01, FIN-02, FH-04]
must_haves:
  truths:
    - "CkeditorRewriterService::rewrite(string $html, int $siteId): string executes the 6-step pipeline (asset attrs → [M<id>] → [NT<id>] → entry links → strip kuma classes → remove empty paragraphs) preserving v1 behavior verbatim."
    - "[M<id>] and [NT<id>] regex constants — including URL-encoded %5B/%5D variants with case-insensitive flag — are load-bearing for FIN-01 and ported byte-for-byte from v1 lines 50, 58."
    - "Unresolvable tokens (FIN-02 strict policy) are left verbatim in the HTML and annotated with <!-- MIGRATION:UNRESOLVED source=... --> HTML comments. No silent drops."
    - "FinalizeWalker (greenfield orchestrator) walks Entry::find()->siteId('*')->all(), filters fields to CKEditor type, calls $rewriter->rewrite() per field, persists via Craft::$app->elements->saveElement(propagate=false), collects unresolvables into the run REPORT.md."
  artifacts:
    - path: "src/finalize/CkeditorRewriterService.php"
      provides: "FIN-01 + FIN-02 + FH-04 inline-rewrite path. 529 LOC verbatim port."
      min_lines: 480
    - path: "src/finalize/FinalizeWalker.php"
      provides: "Greenfield finalize-pass orchestrator. Walks every CKEditor field across every migrated entry."
      min_lines: 100
  key_links:
    - from: "src/finalize/CkeditorRewriterService.php"
      to: "src/load/MigrationStateService.php"
      via: "public ?MigrationStateService $migrationState"
      pattern: "MigrationStateService"
    - from: "src/finalize/CkeditorRewriterService.php"
      to: "src/db/LegacyDbService.php"
      via: "public ?LegacyDbService $legacyDb"
      pattern: "LegacyDbService"
    - from: "src/finalize/FinalizeWalker.php"
      to: "src/finalize/CkeditorRewriterService.php"
      via: "public ?CkeditorRewriterService $rewriter"
      pattern: "CkeditorRewriterService"
---

<objective>
Two artifacts:

1. **CkeditorRewriterService** — verbatim port of v1's 529-LOC inline-rewrite service. The 6-step `rewrite()` pipeline plus the load-bearing `[M<id>]` / `[NT<id>]` regex constants (FIN-01) plus unresolvable-token annotation (FIN-02). FH-04 deferred-token format `[M<id>]` paired with v1 line 50 const + AssetHandler emission site (Plan 03-09).

2. **FinalizeWalker** — greenfield orchestrator (no v1 analog at this layer). Walks every CKEditor field across every migrated entry on `migrate/finalize`, calling `$rewriter->rewrite()` with cross-entry caches now warmed.

Wave 2 — depends on Plan 03-03 (MigrationStateService for the state-table reads inside the rewriter).
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/PROJECT.md
@.planning/phases/03-etl-pipeline-field-handlers/03-CONTEXT.md
@.planning/phases/03-etl-pipeline-field-handlers/03-PATTERNS.md

<interfaces>
`src/finalize/CkeditorRewriterService.php`:
```php
namespace lameco\kunstmaanmigrator\finalize;

use lameco\kunstmaanmigrator\db\LegacyDbService;
use lameco\kunstmaanmigrator\load\MigrationStateService;
use yii\base\Component;

class CkeditorRewriterService extends Component
{
    public const KUMA_MEDIA_PLACEHOLDER_REGEX = '~(?:\[|%5B)M(\d+)(?:\]|%5D)~i';
    public const KUMA_NT_PLACEHOLDER_REGEX    = '~(?:\[|%5B)NT(\d+)(?:\]|%5D)~i';

    public ?MigrationStateService $migrationState = null;
    public ?LegacyDbService       $legacyDb       = null;
    public ?object                $assetResolver  = null; // AssetMigrationService — Plugin::init() wires

    public function rewrite(string $html, int $siteId): string;   // 6-step pipeline
}
```

`src/finalize/FinalizeWalker.php` (greenfield):
```php
namespace lameco\kunstmaanmigrator\finalize;

use lameco\kunstmaanmigrator\filter\MigrationFilters;
use yii\base\Component;

class FinalizeWalker extends Component
{
    public ?CkeditorRewriterService $rewriter = null;

    /** @return array{processed: int, rewritten: int, unresolvable: int} */
    public function walk(MigrationFilters $filters): array;
}
```
</interfaces>
</context>

<tasks>

<task type="auto">
  <name>Task 1: Verbatim port CkeditorRewriterService (529 LOC) including the load-bearing regex constants</name>
  <files>src/finalize/CkeditorRewriterService.php</files>
  <read_first>
    - ~/Sites/craft-kunstmaan-migrator/src/bridge/ckeditor/CkeditorRewriterService.php (v1, 529 LOC — ENTIRE FILE)
    - .planning/phases/03-etl-pipeline-field-handlers/03-PATTERNS.md §16 (CkeditorRewriterService — full reshape recipe)
    - src/load/MigrationStateService.php (Plan 03-03 — confirm public CRUD surface)
    - src/db/LegacyDbService.php (lines 1-50 — confirm queryAll surface)
  </read_first>
  <action>
    Copy `~/Sites/craft-kunstmaan-migrator/src/bridge/ckeditor/CkeditorRewriterService.php` to `src/finalize/CkeditorRewriterService.php`. Apply these mechanical edits per PATTERNS §16:

    **1. Namespace flatten:** `lameco\kunstmaanmigrator\bridge\ckeditor` → `lameco\kunstmaanmigrator\finalize`.

    **2. Retarget imports:**
    - `use lameco\kunstmaanmigrator\bridge\load\MigrationStateService;` → `use lameco\kunstmaanmigrator\load\MigrationStateService;`.
    - `use lameco\kunstmaanmigrator\kunstmaan\db\LegacyDbService;` → `use lameco\kunstmaanmigrator\db\LegacyDbService;`.

    **3. Drop and replace MigrationConfigError if present:** replace throws with `new \RuntimeException(...)`.

    **4. AssetResolver decision (advisor-locked):** The class currently has a property like `public ?AssetResolver $assetResolver = null;` referencing v1's separate AssetResolver class. v2 folds asset resolution into AssetMigrationService (no separate AssetResolver class). **Apply this exact reshape:**
    - Drop the import for v1's AssetResolver.
    - Replace the typed property with `public ?object $assetResolver = null;` (untyped `?object`).
    - The runtime contract: Plugin::init() (Plan 03-14) wires `$this->ckeditorRewriterService->assetResolver = $this->assetMigrationService;`. Calls inside the rewriter use `$this->assetResolver->resolveFromLegacyId(...)` — that method exists on AssetMigrationService per Plan 03-05.
    - Mark the property with: `// AssetResolver responsibility folded into AssetMigrationService per Phase 3 advisor decision; typed ?object slot wired by Plugin::init() Plan 03-14.`

    **5. PRESERVE BYTE-FOR-BYTE — load-bearing for FIN-01:**
    The two const declarations (find via `grep -n "KUMA_MEDIA_PLACEHOLDER_REGEX\|KUMA_NT_PLACEHOLDER_REGEX" ~/Sites/craft-kunstmaan-migrator/src/bridge/ckeditor/CkeditorRewriterService.php`):
    ```php
    public const KUMA_MEDIA_PLACEHOLDER_REGEX = '~(?:\[|%5B)M(\d+)(?:\]|%5D)~i';
    public const KUMA_NT_PLACEHOLDER_REGEX    = '~(?:\[|%5B)NT(\d+)(?:\]|%5D)~i';
    ```
    These regexes are FIN-01-mandated — both `[` and `%5B` variants and the case-insensitive `i` flag. Do NOT modify a single character.

    **6. PRESERVE BYTE-FOR-BYTE — the 6-step rewrite() pipeline:**
    ```
    1.  rewriteAssetAttributes()      — <img src="/uploads/media/*"> → {asset:N@siteId:url}
    1b. rewriteMediaPlaceholders()    — [M<id>] → {asset:N@siteId:url}
    1c. rewriteNodeTranslationPlaceholders() — [NT<id>] → {entry:N@siteId:url}
    2.  rewriteEntryLinks()           — <a href="/internal/path"> → {entry:N@siteId:url}
    3.  stripKumaClasses()            — drop kma-* class tokens
    4.  removeEmptyParagraphs()       — empty <p>, <p>&nbsp;</p>, etc.
    ```
    Every private method body, every cache variable (`$urlIdCache`, `$kumaMediaIdCache`, `$ntToEntryCache`), every cache-warming guard (`$cacheNameWarm` bools), every regex pattern — preserve verbatim.

    **7. PRESERVE BYTE-FOR-BYTE — FIN-02 strict policy:**
    The `<!-- MIGRATION:UNRESOLVED source=... -->` HTML comment emission (locate via `grep -n "MIGRATION:UNRESOLVED" ~/Sites/craft-kunstmaan-migrator/src/bridge/ckeditor/CkeditorRewriterService.php`). Verbatim port — this is the canonical strict-policy marker mandated by REQUIREMENTS FIN-02.

    **8. Sibling-DI slots:**
    ```php
    public ?MigrationStateService $migrationState = null;
    public ?LegacyDbService       $legacyDb       = null;
    public ?object                $assetResolver  = null; // wired to AssetMigrationService
    ```

    **9. Add `declare(strict_types=1);` if v1 omits.**

    DO NOT change: any private helper, any cache key shape, any SQL fragment, any HTML output format, any return value shape.
  </action>
  <verify>
    <automated>php -l src/finalize/CkeditorRewriterService.php && grep -c "MIGRATION:UNRESOLVED" src/finalize/CkeditorRewriterService.php</automated>
  </verify>
  <done>
    - `src/finalize/CkeditorRewriterService.php` exists; `php -l` returns "No syntax errors".
    - File has at least 480 lines.
    - `grep -c "namespace lameco\\\\kunstmaanmigrator\\\\finalize;" src/finalize/CkeditorRewriterService.php` returns 1.
    - `grep -c "KUMA_MEDIA_PLACEHOLDER_REGEX = '~(?:\\\\\\[|%5B)M(\\\\d+)(?:\\\\\\]|%5D)~i'" src/finalize/CkeditorRewriterService.php` returns 1 (CRITICAL — FIN-01 regex preserved).
    - `grep -c "KUMA_NT_PLACEHOLDER_REGEX = '~(?:\\\\\\[|%5B)NT(\\\\d+)(?:\\\\\\]|%5D)~i'" src/finalize/CkeditorRewriterService.php` returns 1 (CRITICAL — FIN-01 regex preserved).
    - `grep -c "MIGRATION:UNRESOLVED" src/finalize/CkeditorRewriterService.php` >= 1 (FIN-02 annotation preserved).
    - `grep -c "rewriteAssetAttributes\\|rewriteMediaPlaceholders\\|rewriteNodeTranslationPlaceholders\\|rewriteEntryLinks\\|stripKumaClasses\\|removeEmptyParagraphs" src/finalize/CkeditorRewriterService.php` >= 6 (all 6 pipeline methods present).
    - `grep -c "use lameco\\\\kunstmaanmigrator\\\\load\\\\MigrationStateService;" src/finalize/CkeditorRewriterService.php` returns 1.
    - `grep -c "use lameco\\\\kunstmaanmigrator\\\\db\\\\LegacyDbService;" src/finalize/CkeditorRewriterService.php` returns 1.
    - `grep -c "public ?object \\$assetResolver = null" src/finalize/CkeditorRewriterService.php` returns 1.
    - `grep -c "MigrationConfigError" src/finalize/CkeditorRewriterService.php` returns 0.
    - `grep -rn "lameco\\\\kunstmaanmigrator\\\\\\(bridge\\|craft\\|kunstmaan\\)" src/finalize/CkeditorRewriterService.php` returns zero matches.
  </done>
</task>

<task type="auto">
  <name>Task 2: Greenfield FinalizeWalker — Entry::find()->siteId('*')->all() + per-CKEditor-field rewrite</name>
  <files>src/finalize/FinalizeWalker.php</files>
  <read_first>
    - src/finalize/CkeditorRewriterService.php (Task 1 output — confirm rewrite() public signature)
    - src/filter/MigrationFilters.php (lines 1-50 — confirm readonly fields)
    - src/load/MigrationStateService.php (lines 1-50 — confirm runOnce / get surface)
    - .planning/phases/03-etl-pipeline-field-handlers/03-PATTERNS.md "No Analog Found" section (the finalize walker greenfield design hints)
    - .planning/phases/03-etl-pipeline-field-handlers/03-PATTERNS.md "Shared Pattern 4" (plain-text OK/WARN/FAIL emit — applies to walker progress logs)
  </read_first>
  <action>
    Create greenfield file `src/finalize/FinalizeWalker.php`. There is no v1 analog at this orchestration layer — v1 did inline-only rewriting via PlainTextHandler 'ckeditor' mode. v2 adds the post-load walker to satisfy FIN-01's requirement that finalize walks every CKEditor field across every migrated entry.

    **PSR-4 path:** `src/finalize/FinalizeWalker.php`.
    **Namespace:** `lameco\kunstmaanmigrator\finalize`.
    **Class header:** `<?php declare(strict_types=1);` + `namespace ...;` + `final class FinalizeWalker extends Component`.

    **Public surface:**
    ```php
    public ?CkeditorRewriterService $rewriter = null;

    /**
     * Walk every migrated Craft entry, find CKEditor fields, call $rewriter->rewrite() with the
     * cross-entry caches now warmed (since all referenced entries exist after the load pass).
     * Persists via $entry->save() with propagate=false to honor EntryMigrationService's
     * multi-site discipline (saved per (entry, siteId) pair).
     *
     * Returns counts: processed = total CKEditor field reads; rewritten = fields whose value
     * changed; unresolvable = fields that emitted at least one <!-- MIGRATION:UNRESOLVED ... -->
     * comment after rewriting.
     *
     * @return array{processed: int, rewritten: int, unresolvable: int}
     */
    public function walk(MigrationFilters $filters): array;
    ```

    **Implementation skeleton (executor implements; reference behavior contract here):**

    1. Validate `$this->rewriter !== null` (throw `\RuntimeException` if null).
    2. Build entries query:
       ```php
       $query = \craft\elements\Entry::find()->siteId('*');
       ```
       If `$filters->entities` is non-empty, scope to those entry types via `->type([...])`.
    3. Iterate `$query->each(50)` (batched for memory).
    4. For each entry, walk the field layout:
       ```php
       $fieldLayout = $entry->getFieldLayout();
       if ($fieldLayout === null) continue;
       foreach ($fieldLayout->getCustomFields() as $field) {
           if ($field instanceof \craft\ckeditor\Field) {
               // process CKEditor field
           }
       }
       ```
    5. For each CKEditor field, read value, call `$rewritten = $this->rewriter->rewrite($value, $entry->siteId);`. If `$rewritten !== $value`, set the field, save the entry with `Craft::$app->elements->saveElement($entry, propagate: false)`.
    6. Detect unresolvables by `str_contains($rewritten, '<!-- MIGRATION:UNRESOLVED')` and increment counter.
    7. Return `['processed' => N, 'rewritten' => N, 'unresolvable' => N]`.

    **Behavioral guarantees:**
    - **Idempotent re-run.** Running `walk()` a second time should be a no-op when nothing has changed (rewriter output == current value → no save). This is implicit from step 5's diff guard.
    - **propagate=false.** Each save is scoped to the per-site entry — the same multi-site discipline EntryMigrationService preserves (Plan 03-08). The walker MUST NOT use Craft's auto-propagation.
    - **No swallowed exceptions.** If `Craft::$app->elements->saveElement()` returns false, throw `\RuntimeException` with the entry id + site id + first validation error. The MigrateController catches and emits to REPORT.md.

    **Forward reference note.** `\craft\ckeditor\Field` requires `vendor/craftcms/ckeditor` — verify it's in composer.json. If absent, the executor adds it during implementation (it's already a hard requirement for the migrated CKEditor fields). For Phase 3 plan validation, `php -l` does not check class existence — only syntax.

    Add this class-level docblock:
    ```php
    /**
     * Greenfield finalize-pass orchestrator. No v1 analog — v1 did inline rewriting only via
     * PlainTextHandler 'ckeditor' mode. FIN-01 mandates a finalize-time pass that walks every
     * CKEditor field across every migrated entry; this class is that pass.
     *
     * Designed at Phase 3 / Plan 03-06 per PATTERNS.md "No Analog Found" section.
     */
    ```
  </action>
  <verify>
    <automated>php -l src/finalize/FinalizeWalker.php</automated>
  </verify>
  <done>
    - `src/finalize/FinalizeWalker.php` exists; `php -l` returns "No syntax errors".
    - `grep -c "namespace lameco\\\\kunstmaanmigrator\\\\finalize;" src/finalize/FinalizeWalker.php` returns 1.
    - `grep -c "final class FinalizeWalker extends Component" src/finalize/FinalizeWalker.php` returns 1.
    - `grep -c "public ?CkeditorRewriterService \\$rewriter = null" src/finalize/FinalizeWalker.php` returns 1.
    - `grep -c "function walk(MigrationFilters \\$filters): array" src/finalize/FinalizeWalker.php` returns 1.
    - `grep -c "siteId('\\*')" src/finalize/FinalizeWalker.php` returns 1.
    - `grep -c "MIGRATION:UNRESOLVED" src/finalize/FinalizeWalker.php` >= 1 (unresolvable detection).
    - `grep -c "propagate" src/finalize/FinalizeWalker.php` >= 1 (propagate=false discipline documented).
    - File has at least 100 lines.
  </done>
</task>

</tasks>

<reconciliation>
## CkeditorRewriterService reconciliation

**v1 file:** `~/Sites/craft-kunstmaan-migrator/src/bridge/ckeditor/CkeditorRewriterService.php` (529 LOC)
**v2 file:** `src/finalize/CkeditorRewriterService.php` (~510 LOC after namespace flatten + AssetResolver typing reshape)

| v1 Rule (file:line) | Description | Disposition | v2 location / rationale |
|---|---|---|---|
| Lines 50, 58 — `KUMA_MEDIA_PLACEHOLDER_REGEX` + `KUMA_NT_PLACEHOLDER_REGEX` | FIN-01 mandated `[`/`%5B` + `]`/`%5D` + `i` flag. | ported byte-for-byte | Same file. Load-bearing — any change breaks the URL-encoded path. |
| Lines 98-127 — 6-step `rewrite()` pipeline | Asset attrs / media / NT / entry links / strip kuma classes / remove empty paragraphs. | ported verbatim | Same file. |
| Lines 23-25 — `<!-- MIGRATION:UNRESOLVED -->` strict-policy marker | FIN-02 strict-policy annotation. | ported verbatim | Same file. |
| Lines 78-91 — three caches (`$urlIdCache`, `$kumaMediaIdCache`, `$ntToEntryCache`) + warm guards | Cache warming. | ported verbatim | Same file. |
| `use AssetResolver` (separate v1 class) | v1 had a dedicated AssetResolver. | reshape: typed `?object $assetResolver = null` slot | Advisor-locked decision: v2 folds asset resolution into AssetMigrationService (no separate AssetResolver class). Plugin::init() Plan 03-14 wires `$this->ckeditorRewriterService->assetResolver = $this->assetMigrationService;`. Calls use `$this->assetResolver->resolveFromLegacyId(...)` which is on AssetMigrationService per Plan 03-05. |
| MigrationConfigError throws | If present. | dropped intentionally | Replaced with `\RuntimeException`. |

## FinalizeWalker reconciliation

**v1 analog:** None at the orchestration layer. v1 did inline rewriting only via PlainTextHandler 'ckeditor' mode.
**v2 file:** `src/finalize/FinalizeWalker.php` (greenfield ~110 LOC)

| Rule | Description | Disposition |
|---|---|---|
| Greenfield orchestrator | Walks every Entry across every site, finds CKEditor fields, calls rewriter, saves with propagate=false. | new in v2 |

The walker design is deliberately conservative — `Entry::find()->siteId('*')->each(50)` for memory, propagate=false for multi-site discipline, idempotent re-run via diff guard. PATTERNS.md "No Analog Found" section flagged this as the only Phase 3 greenfield orchestration.

### Counts (Plan 03-06 only)
| Pair | ported | dropped intentionally | dropped accidentally | new in v2 |
|---|---:|---:|---:|---:|
| CkeditorRewriterService | 4 | 1 (MigrationConfigError, if present) | 0 | 0 |
| FinalizeWalker | 0 | 0 | 0 | 1 |
</reconciliation>

<verification>
- `php -l` exits 0 for both files.
- FIN-01 regex constants preserved byte-for-byte (the URL-encoded path is the load-bearing test).
- FIN-02 unresolvable annotation preserved.
- 6-step pipeline + 3 caches verbatim.
- FinalizeWalker greenfield: walks Entry::find()->siteId('*'), per-CKEditor-field rewrite, propagate=false, idempotent.
</verification>

<success_criteria>
- CkeditorRewriterService ports verbatim with FIN-01 regex constants and FIN-02 annotation policy preserved.
- AssetResolver responsibility folded into AssetMigrationService via typed `?object` slot.
- FinalizeWalker is the greenfield finalize-pass orchestrator satisfying FIN-01's "walk every CKEditor field across every migrated entry" mandate.
- Reconciliation documents 4 ported / 1 dropped intentionally / 0 dropped accidentally / 1 greenfield.
</success_criteria>

<output>
Create `.planning/phases/03-etl-pipeline-field-handlers/03-06-ckeditor-rewriter-SUMMARY.md`.
</output>
