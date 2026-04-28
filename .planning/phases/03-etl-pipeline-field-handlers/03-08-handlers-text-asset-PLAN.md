---
phase: 03-etl-pipeline-field-handlers
plan: 08
type: execute
wave: 3
depends_on: ['03-01', '03-02', '03-06']
files_modified:
  - src/fields/handlers/PlainTextHandler.php
  - src/fields/handlers/AssetHandler.php
autonomous: true
requirements: [FH-01, FH-04]
must_haves:
  truths:
    - "PlainTextHandler implements 4 modes: 'plain', 'ckeditor', 'link', 'dropdown'. The 'seomatic' mode is dropped per ADP-01 / Phase 4 deferral; valid-modes whitelist updated to 4 entries."
    - "PlainTextHandler 'ckeditor' mode is the FH-04 inline-rewrite path: writeCkeditor() calls $ctx->ck->rewrite($html, $ctx->siteId) at handler-resolve time."
    - "AssetHandler emits one of two deferred-token formats on state-lookup miss: as=imgTag returns string '[M{$legacyValue}]' (the CKEditor placeholder format consumed by CkeditorRewriterService); as=relation returns [DeferredAssetToken::emit($legacyValue)] (the 'asset:N' token format consumed by AtomicMigrationService)."
    - "Both deferred-token formats are FH-04 contract — finalize-pass resolves [M<id>] via CkeditorRewriterService; load-pass resolves 'asset:N' via AtomicMigrationService::ingestAndResolveAssets per the /^asset:\\d+$/ regex pair."
  artifacts:
    - path: "src/fields/handlers/PlainTextHandler.php"
      provides: "FH-01 PlainText handler with 4 modes (was 5 in v1; seomatic stripped)."
      min_lines: 150
    - path: "src/fields/handlers/AssetHandler.php"
      provides: "FH-01 + FH-04 Asset handler with dual deferred-token emission paths."
      min_lines: 80
  key_links:
    - from: "src/fields/handlers/PlainTextHandler.php"
      to: "src/finalize/CkeditorRewriterService.php"
      via: "$ctx->ck->rewrite() in writeCkeditor()"
      pattern: "ck->rewrite"
    - from: "src/fields/handlers/AssetHandler.php"
      to: "src/fields/DeferredAssetToken.php"
      via: "DeferredAssetToken::emit on state miss"
      pattern: "DeferredAssetToken::emit"
---

<objective>
Two handler ports — both verbatim except the PlainTextHandler 'seomatic' mode strip-down (Phase 4 / ADP-01 owns SEOmatic). Both contribute to FH-04 deferred-token contract: PlainTextHandler 'ckeditor' inline-rewrites via the Ckeditor service; AssetHandler emits `[M<id>]` or `asset:N` tokens on state miss.

Wave 3 — depends on Plan 03-01 (FieldHandler interface, ResolverContext, DeferredAssetToken) + 03-02 (MigrationStateReader via context) + 03-06 (CkeditorRewriterService for inline rewrite).
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/PROJECT.md
@.planning/phases/03-etl-pipeline-field-handlers/03-CONTEXT.md
@.planning/phases/03-etl-pipeline-field-handlers/03-PATTERNS.md
@.planning/phases/03-etl-pipeline-field-handlers/03-01-fields-scaffold-PLAN.md

<interfaces>
PlainTextHandler 4-mode dispatcher (after 'seomatic' strip):
```php
public function resolve(mixed $legacyValue, ResolverContext $ctx, array $options = []): mixed
{
    return match ($this->mode) {
        'plain'    => $this->writePlain($legacyValue),
        'ckeditor' => $this->writeCkeditor($legacyValue, $ctx),
        'link'     => $this->writeLink($legacyValue, $ctx),
        'dropdown' => $this->writeDropdown($legacyValue, $options),
    };
}
```

AssetHandler dual-token-emission:
```php
// On state-lookup miss:
return $as === 'imgTag'
    ? "[M{$legacyValue}]"                                    // CkeditorRewriterService consumes (FIN-01)
    : [DeferredAssetToken::emit((int) $legacyValue)];        // AtomicMigrationService consumes (load pass)
```
</interfaces>
</context>

<tasks>

<task type="auto">
  <name>Task 1: Verbatim port PlainTextHandler with seomatic mode strip</name>
  <files>src/fields/handlers/PlainTextHandler.php</files>
  <read_first>
    - ~/Sites/craft-kunstmaan-migrator/src/bridge/fields/handlers/PlainTextHandler.php (v1, 188 LOC — full file)
    - .planning/phases/03-etl-pipeline-field-handlers/03-PATTERNS.md §17 (PlainTextHandler — full reshape recipe)
    - src/fields/FieldHandler.php (Plan 03-01 — interface to implement)
    - src/fields/ResolverContext.php (Plan 03-01 — confirm $ctx->ck typing)
    - src/finalize/CkeditorRewriterService.php (Plan 03-06 — confirm public rewrite() signature)
  </read_first>
  <action>
    Copy `~/Sites/craft-kunstmaan-migrator/src/bridge/fields/handlers/PlainTextHandler.php` to `src/fields/handlers/PlainTextHandler.php`. Apply per PATTERNS §17:

    **1. Namespace flatten:** `lameco\kunstmaanmigrator\bridge\fields\handlers` → `lameco\kunstmaanmigrator\fields\handlers`.

    **2. Retarget imports:**
    - `use lameco\kunstmaanmigrator\bridge\fields\FieldHandler;` → `use lameco\kunstmaanmigrator\fields\FieldHandler;`
    - `use lameco\kunstmaanmigrator\bridge\fields\ResolverContext;` → `use lameco\kunstmaanmigrator\fields\ResolverContext;`

    **3. STRIP 'seomatic' mode** (PATTERNS §17 reshape #3):
    - Drop `'seomatic'` from the match block. After the strip, the match arms are:
      ```php
      return match ($this->mode) {
          'plain'    => $this->writePlain($legacyValue),
          'ckeditor' => $this->writeCkeditor($legacyValue, $ctx),
          'link'     => $this->writeLink($legacyValue, $ctx),
          'dropdown' => $this->writeDropdown($legacyValue, $options),
      };
      ```
    - Remove the `writeSeomatic()` private method entirely (v1 lines ~140-152).
    - Remove the constructor parameter for `SeomaticPayloadBuilder` if present, plus the `use` import for it.
    - Update the valid-modes whitelist (v1 line ~54 area — typically a `MODES = [...]` const or in-constructor check) to `['plain', 'ckeditor', 'link', 'dropdown']` — exactly 4 entries.

    **4. Drop and replace MigrationConfigError if present.**

    **5. Preserve byte-for-byte:**
    - The 4 remaining `write{Plain,Ckeditor,Link,Dropdown}` private method bodies.
    - The `id()` method (v1 lines 59-62): `$mode === 'plain' ? 'plain' : $mode` — registry binds 4 distinct ids.
    - The `writeLink` classify pattern (v1 lines 109-134) including the `state->getTargetId('page', $value, $siteId)` page-internal-link resolver call.
    - The `writeCkeditor` body — this is the FH-04 inline-rewrite path: `$ctx->ck->rewrite((string) $legacyValue, $ctx->siteId)`.

    **6. Add `declare(strict_types=1);` if v1 omits.**

    **7. Add a class-level docblock note:**
    ```php
    /**
     * v2 PlainTextHandler — 4 modes (plain | ckeditor | link | dropdown).
     * v1's 5th 'seomatic' mode dropped per Phase 3 / Plan 03-08 — Phase 4 / ADP-01 reinstates
     * SEOmatic mode + writeSeomatic() + the SeomaticPayloadBuilder constructor parameter.
     */
    ```
  </action>
  <verify>
    <automated>php -l src/fields/handlers/PlainTextHandler.php</automated>
  </verify>
  <done>
    - `src/fields/handlers/PlainTextHandler.php` exists; `php -l` returns "No syntax errors".
    - File has at least 150 lines (v1's 188 LOC minus ~25-LOC seomatic strip).
    - `grep -c "namespace lameco\\\\kunstmaanmigrator\\\\fields\\\\handlers;" src/fields/handlers/PlainTextHandler.php` returns 1.
    - `grep -c "implements FieldHandler" src/fields/handlers/PlainTextHandler.php` returns 1.
    - `grep -c "'seomatic'" src/fields/handlers/PlainTextHandler.php` returns 0 (mode stripped).
    - `grep -c "writeSeomatic" src/fields/handlers/PlainTextHandler.php` returns 0 (method removed).
    - `grep -c "SeomaticPayloadBuilder" src/fields/handlers/PlainTextHandler.php` returns 0 (import + ctor param removed).
    - `grep -c "writePlain\\|writeCkeditor\\|writeLink\\|writeDropdown" src/fields/handlers/PlainTextHandler.php` >= 4 (all 4 remaining mode methods present).
    - `grep -c "ck->rewrite" src/fields/handlers/PlainTextHandler.php` >= 1 (FH-04 inline-rewrite path preserved).
    - `grep -c "MigrationConfigError" src/fields/handlers/PlainTextHandler.php` returns 0.
    - `grep -rn "lameco\\\\kunstmaanmigrator\\\\\\(bridge\\|craft\\|kunstmaan\\)" src/fields/handlers/PlainTextHandler.php` returns zero matches.
  </done>
</task>

<task type="auto">
  <name>Task 2: Verbatim port AssetHandler with dual-token emission preserved</name>
  <files>src/fields/handlers/AssetHandler.php</files>
  <read_first>
    - ~/Sites/craft-kunstmaan-migrator/src/bridge/fields/handlers/AssetHandler.php (v1, 95 LOC — full file)
    - .planning/phases/03-etl-pipeline-field-handlers/03-PATTERNS.md §18 (AssetHandler — full reshape recipe)
    - src/fields/FieldHandler.php (Plan 03-01 — interface)
    - src/fields/ResolverContext.php (Plan 03-01 — confirm $ctx->state typing)
    - src/fields/DeferredAssetToken.php (Plan 03-01 — confirm emit(int): string signature)
  </read_first>
  <action>
    Copy `~/Sites/craft-kunstmaan-migrator/src/bridge/fields/handlers/AssetHandler.php` to `src/fields/handlers/AssetHandler.php`. Apply per PATTERNS §18:

    **1. Namespace flatten:** `lameco\kunstmaanmigrator\bridge\fields\handlers` → `lameco\kunstmaanmigrator\fields\handlers`.

    **2. Retarget imports:**
    - `FieldHandler` → `lameco\kunstmaanmigrator\fields\FieldHandler`
    - `ResolverContext` → `lameco\kunstmaanmigrator\fields\ResolverContext`
    - `DeferredAssetToken` → `lameco\kunstmaanmigrator\fields\DeferredAssetToken`
    - `Asset` (Craft Asset element) — preserve v1 import.

    **3. AssetResolver decision (advisor-locked).** v1 imports `AssetResolver` as a separate class. v2 folds resolver responsibility into `AssetMigrationService` (Plan 03-05). Apply the same reshape as Plan 03-06 CkeditorRewriterService:
    - Drop the `AssetResolver` import.
    - Replace the typed property `public ?AssetResolver $assetResolver = null;` with `public ?object $assetResolver = null;`.
    - Plugin::init() (Plan 03-14) wires `$this->assetHandler->assetResolver = $this->assetMigrationService;`.
    - Mark with: `// AssetResolver folded into AssetMigrationService per Phase 3 advisor decision; $assetResolver->resolveFromLegacyId(int): int is the consumed surface.`

    **4. PRESERVE BYTE-FOR-BYTE — the dual-token emission** (PATTERNS §18, v1 lines 47-94):
    The state-lookup-miss branch (v1 lines ~73-80):
    ```php
    if ($id === null) {
        return $as === 'imgTag' ? "[M{$legacyValue}]" : [DeferredAssetToken::emit((int) $legacyValue)];
    }
    ```
    Two deferred-token formats for two consumer paths:
    - `[M{$legacyValue}]` — CKEditor placeholder consumed by CkeditorRewriterService at finalize time (FIN-01).
    - `[DeferredAssetToken::emit($legacyValue)]` — `'asset:N'` token consumed by AtomicMigrationService::ingestAndResolveAssets at load time per the `/^asset:\d+$/` regex pair (Plan 03-13).

    **DO NOT modify a single character of either branch.** This is the FH-04 contract.

    **5. Preserve byte-for-byte — the JIT lazy-resolve path** (v1 lines ~66-71):
    ```php
    if ($id === null && $source === 'media' && $keyFormat === 'kuma_media:%d') {
        if ($this->assetResolver !== null) {
            $resolved = $this->assetResolver->resolveFromLegacyId((int) $legacyValue);
            if ($resolved > 0) { $id = $resolved; }
        }
    }
    ```
    This is the FH-03 JIT default path — when state-lookup misses, ask AssetMigrationService to materialize the asset on demand.

    **6. Drop and replace MigrationConfigError if present.**

    **7. Add `declare(strict_types=1);` if v1 omits.**

    DO NOT change: the resolve() method body (the entire ~50-line state-lookup → JIT-fallback → deferred-token branch chain), the imgTag rendering logic if present, the `$as` option dispatch, the early-empty-value guards.
  </action>
  <verify>
    <automated>php -l src/fields/handlers/AssetHandler.php</automated>
  </verify>
  <done>
    - `src/fields/handlers/AssetHandler.php` exists; `php -l` returns "No syntax errors".
    - File has at least 80 lines.
    - `grep -c "namespace lameco\\\\kunstmaanmigrator\\\\fields\\\\handlers;" src/fields/handlers/AssetHandler.php` returns 1.
    - `grep -c "implements FieldHandler" src/fields/handlers/AssetHandler.php` returns 1.
    - `grep -c "use lameco\\\\kunstmaanmigrator\\\\fields\\\\DeferredAssetToken;" src/fields/handlers/AssetHandler.php` returns 1.
    - `grep -c 'DeferredAssetToken::emit' src/fields/handlers/AssetHandler.php` >= 1 (asset:N path preserved).
    - `grep -c '\\[M{' src/fields/handlers/AssetHandler.php` >= 1 (imgTag M-format path preserved).
    - `grep -c "public ?object \\$assetResolver = null" src/fields/handlers/AssetHandler.php` returns 1.
    - `grep -c "resolveFromLegacyId" src/fields/handlers/AssetHandler.php` >= 1 (JIT call preserved).
    - `grep -c "kuma_media" src/fields/handlers/AssetHandler.php` >= 1 (state lookup key format).
    - `grep -c "MigrationConfigError" src/fields/handlers/AssetHandler.php` returns 0.
    - `grep -rn "lameco\\\\kunstmaanmigrator\\\\\\(bridge\\|craft\\|kunstmaan\\)" src/fields/handlers/AssetHandler.php` returns zero matches.
  </done>
</task>

</tasks>

<reconciliation>
## PlainTextHandler reconciliation

**v1 file:** `~/Sites/craft-kunstmaan-migrator/src/bridge/fields/handlers/PlainTextHandler.php` (188 LOC)
**v2 file:** `src/fields/handlers/PlainTextHandler.php` (~160 LOC after seomatic strip)

| v1 Rule (file:line) | Description | Disposition | v2 location / rationale |
|---|---|---|---|
| Lines 64-73 — match dispatcher with 5 arms | plain / ckeditor / link / dropdown / seomatic | ported (4 arms preserved) — `'seomatic'` arm dropped intentionally | Phase 4 / ADP-01 owns SEOmatic. Plan 03-08 ships 4 modes; Phase 4 reinstates the 5th. v2 valid-modes whitelist updated to ['plain','ckeditor','link','dropdown']. |
| Lines 140-152 — `writeSeomatic()` method | SEOmatic payload writer. | dropped intentionally | Same Phase 4 deferral. v2 SeomaticPayloadBuilder constructor param + import also dropped. |
| Lines 59-62 — `id()` method | `$mode === 'plain' ? 'plain' : $mode` — 4 distinct registry ids. | ported verbatim | Same file. Plugin::init() (Plan 03-14) registers 4 instances. |
| Lines 109-134 — `writeLink` classify pattern | Page-internal-link resolver via `state->getTargetId('page', ...)`. | ported verbatim | Same file. |
| `writeCkeditor` body | Inline `$ctx->ck->rewrite(...)` call. | ported verbatim | Same file. FH-04 inline-rewrite path. |
| MigrationConfigError throws | If present. | dropped intentionally | `\RuntimeException` replaces. |

## AssetHandler reconciliation

**v1 file:** `~/Sites/craft-kunstmaan-migrator/src/bridge/fields/handlers/AssetHandler.php` (95 LOC)
**v2 file:** `src/fields/handlers/AssetHandler.php` (~85 LOC)

| v1 Rule (file:line) | Description | Disposition | v2 location / rationale |
|---|---|---|---|
| Lines 47-94 — `resolve()` body (state-lookup → JIT-fallback → deferred-token) | Dual-token emission for two consumer paths. | ported byte-for-byte | Same file. FH-04 contract — finalize-pass + load-pass each consume their own token format. |
| Lines 73-80 — `[M{$legacyValue}]` vs `DeferredAssetToken::emit()` branch | Two formats for two consumers. | ported byte-for-byte | Load-bearing for FH-04. |
| Lines 66-71 — JIT lazy-resolve via `$this->assetResolver->resolveFromLegacyId()` | FH-03 JIT default path. | ported verbatim | Plan 03-14 wires assetResolver to AssetMigrationService. |
| `use AssetResolver` (v1 separate class) | v1 had dedicated AssetResolver. | reshape: typed `?object $assetResolver = null` | Advisor-locked — v2 folds into AssetMigrationService. Same reshape as Plan 03-06. |
| MigrationConfigError throws | If present. | dropped intentionally | `\RuntimeException` replaces. |

### Counts (Plan 03-08 only)
| Pair | ported | dropped intentionally | dropped accidentally |
|---|---:|---:|---:|
| PlainTextHandler | 4 | 3 (seomatic mode + writeSeomatic + SeomaticPayloadBuilder) | 0 |
| AssetHandler | 3 | 1 (MigrationConfigError if present) | 0 |
| **Plan 03-08 totals** | **7** | **4** | **0** |
</reconciliation>

<verification>
- `php -l` exits 0 for both files.
- PlainTextHandler ships exactly 4 modes; seomatic stripped.
- AssetHandler dual-token emission (`[M<id>]` + `asset:N`) preserved byte-for-byte (FH-04 contract).
- JIT lazy-resolve path preserved (FH-03).
- AssetResolver folded into AssetMigrationService via typed `?object` slot.
</verification>

<success_criteria>
- 2 handler files port verbatim with documented Phase 4 deferrals.
- FH-04 deferred-token contract preserved on both paths (CkeditorRewriterService consumer + AtomicMigrationService consumer).
- 7 ported / 4 dropped intentionally / 0 dropped accidentally.
</success_criteria>

<output>
Create `.planning/phases/03-etl-pipeline-field-handlers/03-08-handlers-text-asset-SUMMARY.md`.
</output>
