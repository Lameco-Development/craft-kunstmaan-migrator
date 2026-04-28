---
phase: 03-etl-pipeline-field-handlers
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - src/fields/FieldHandler.php
  - src/fields/FieldHandlerRegistry.php
  - src/fields/DeferredAssetToken.php
  - src/fields/ResolverContext.php
autonomous: true
requirements: [FH-01, FH-02]
must_haves:
  truths:
    - "FieldHandler interface declares id() + resolve(mixed, ResolverContext, array) and is the contract every Phase 3 handler implements (FH-01)."
    - "FieldHandlerRegistry hash-keys FieldHandler instances by id() and throws \\RuntimeException with the unknown-id + registered-ids list when get() misses (FH-02)."
    - "DeferredAssetToken::emit(int) returns 'asset:<id>' string — the format that AtomicMigrationService::ingestAndResolveAssets() consumes via the /^asset:\\d+$/ regex pair (the load-bearing token contract)."
    - "ResolverContext is an immutable 7-arg readonly VO carrying siteId, siteHandle, MigrationStateReader, CkeditorRewriterService, AssetPathResolver, siteMap, optional LegacyDbService — built once per (site, entry) tuple by TransformService."
  artifacts:
    - path: "src/fields/FieldHandler.php"
      provides: "FH-01 handler interface (verbatim port)"
      min_lines: 25
    - path: "src/fields/FieldHandlerRegistry.php"
      provides: "FH-02 hash-keyed registry"
      min_lines: 30
    - path: "src/fields/DeferredAssetToken.php"
      provides: "FH-04 'asset:N' token emitter (load-stage paired with AtomicMigrationService regex)"
      min_lines: 15
    - path: "src/fields/ResolverContext.php"
      provides: "Per-row immutable context; transform stage building block"
      min_lines: 25
  key_links:
    - from: "src/fields/FieldHandlerRegistry.php"
      to: "src/fields/FieldHandler.php"
      via: "register(FieldHandler) / get(string): FieldHandler"
      pattern: "FieldHandler"
    - from: "src/fields/ResolverContext.php"
      to: "src/load/MigrationStateReader.php"
      via: "public readonly MigrationStateReader \\$state"
      pattern: "MigrationStateReader"
---

<objective>
Land the four scaffold types under `src/fields/` that every other Phase 3 plan depends on. These are the foundational handler contracts: an interface, a registry, a token VO, and the per-row resolver context. All four are verbatim ports from v1 modulo namespace flatten + import retargeting.

This plan is Wave 1 — no dependencies. Wave 2 (extract/transform/load services) and Wave 3 (handlers) build against these contracts.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/PROJECT.md
@.planning/phases/03-etl-pipeline-field-handlers/03-CONTEXT.md
@.planning/phases/03-etl-pipeline-field-handlers/03-PATTERNS.md
@.planning/phases/02.1-source-introspection/02.1-PATTERNS.md

<interfaces>
<!-- These are the contracts THIS plan creates. Downstream Wave 2/3 plans consume them. -->

`src/fields/FieldHandler.php` (interface — verbatim from v1 lines 21-41):
```php
namespace lameco\kunstmaanmigrator\fields;

interface FieldHandler
{
    public function id(): string;
    public function resolve(mixed $legacyValue, ResolverContext $ctx, array $options = []): mixed;
}
```

`src/fields/FieldHandlerRegistry.php`:
```php
namespace lameco\kunstmaanmigrator\fields;

final class FieldHandlerRegistry
{
    /** @var array<string, FieldHandler> */
    private array $handlers = [];
    public function register(FieldHandler $handler): void;
    public function get(string $id): FieldHandler;            // throws \RuntimeException if not registered
    /** @return list<string> */
    public function ids(): array;
}
```

`src/fields/DeferredAssetToken.php`:
```php
namespace lameco\kunstmaanmigrator\fields;

final class DeferredAssetToken
{
    public static function emit(int $legacyId): string;       // returns 'asset:' . $legacyId
}
```

**Load-stage paired regex contract (load-bearing — NOT in this plan but constrained by it):**
- Match: `/^asset:\d+$/`
- Capture: `/^asset:(\d+)$/`
- Consumer: `src/load/AtomicMigrationService.php` (Wave 4 / Plan 03-13).

`src/fields/ResolverContext.php`:
```php
namespace lameco\kunstmaanmigrator\fields;

use lameco\kunstmaanmigrator\db\LegacyDbService;
use lameco\kunstmaanmigrator\finalize\CkeditorRewriterService;   // Wave 2 — Plan 03-06
use lameco\kunstmaanmigrator\load\AssetPathResolver;             // Wave 1 — Plan 03-02
use lameco\kunstmaanmigrator\load\MigrationStateReader;          // Wave 1 — Plan 03-02

final class ResolverContext
{
    /** @param array<string, int> $siteMap kuma_locale → Craft siteId */
    public function __construct(
        public readonly int $siteId,
        public readonly string $siteHandle,
        public readonly MigrationStateReader $state,
        public readonly CkeditorRewriterService $ck,
        public readonly AssetPathResolver $paths,
        public readonly array $siteMap,
        public readonly ?LegacyDbService $legacyDb = null,
    ) {}
}
```

**Forward-references not yet on disk:** `MigrationStateReader` and `AssetPathResolver` are in Plan 03-02 (same wave). `CkeditorRewriterService` is in Plan 03-06 (Wave 2). The `use` statements in `ResolverContext.php` reference these by FQCN — PHP autoload will resolve them once Wave 2 lands. **PHPUnit/test load of just this plan's files is NOT expected to pass standalone — verification via `php -l` (syntax) only at this plan's level.**
</interfaces>
</context>

<tasks>

<task type="auto">
  <name>Task 1: Port FieldHandler interface + DeferredAssetToken VO</name>
  <files>src/fields/FieldHandler.php, src/fields/DeferredAssetToken.php</files>
  <read_first>
    - ~/Sites/craft-kunstmaan-migrator/src/bridge/fields/FieldHandler.php (v1, 41 LOC — full interface)
    - ~/Sites/craft-kunstmaan-migrator/src/bridge/fields/DeferredAssetToken.php (v1, 27 LOC — full file)
    - .planning/phases/03-etl-pipeline-field-handlers/03-PATTERNS.md (sections 1 + 3 — reshape recipe)
    - .planning/phases/02.1-source-introspection/02.1-PATTERNS.md §3 (verbatim port reshape recipe — namespace flatten)
    - src/source/DoctrineEntityParser.php (lines 1-15 — example of Phase 02.1 verbatim-port file header for `declare(strict_types=1);` + namespace + imports convention)
  </read_first>
  <action>
    Copy `~/Sites/craft-kunstmaan-migrator/src/bridge/fields/FieldHandler.php` to `src/fields/FieldHandler.php`. Apply ONLY these mechanical edits:

    1. Change namespace from `lameco\kunstmaanmigrator\bridge\fields` to `lameco\kunstmaanmigrator\fields`.
    2. Change `use lameco\kunstmaanmigrator\bridge\fields\ResolverContext;` to `use lameco\kunstmaanmigrator\fields\ResolverContext;` (same namespace — drop the import or keep it; v1 has no import because they were the same namespace; do NOT add an import, the type lives in the same namespace).
    3. Add `declare(strict_types=1);` on line 3 (immediately after `<?php`) if v1 omits it. This is a v2 convention extension, not a v1 rule change.

    DO NOT change: the `id()` signature, the `resolve()` signature, the docblock contents (including the "Stable short identifier" docblock and the "Examples: 'plain', 'ckeditor', 'asset', 'relation', 'link', 'dropdown', 'seomatic', 'matrix'" comment — yes, retain `'seomatic'` in the docblock; it documents the v1 superset and Phase 4 will reinstate that mode), the docblock note about handlers being stateless.

    Copy `~/Sites/craft-kunstmaan-migrator/src/bridge/fields/DeferredAssetToken.php` to `src/fields/DeferredAssetToken.php`. Apply ONLY:
    1. Namespace `lameco\kunstmaanmigrator\bridge\fields` → `lameco\kunstmaanmigrator\fields`.
    2. Add `declare(strict_types=1);` if v1 omits it.

    DO NOT change: the `emit()` method body (`return 'asset:' . $legacyId;`), the `final class` modifier, the docblock.

    Add this comment block at the bottom of `DeferredAssetToken.php` AFTER the closing `}`:
    ```php
    // PAIRED REGEX CONTRACT (load-bearing): src/load/AtomicMigrationService.php (Plan 03-13)
    // matches the emitted 'asset:N' string with /^asset:\d+$/ and captures the legacy id with
    // /^asset:(\d+)$/. The format and the consumer regexes are tightly coupled — any change
    // here MUST update AtomicMigrationService::ingestAndResolveAssets() at the same time.
    ```
  </action>
  <verify>
    <automated>php -l src/fields/FieldHandler.php && php -l src/fields/DeferredAssetToken.php</automated>
  </verify>
  <done>
    - `src/fields/FieldHandler.php` exists, syntax valid.
    - `src/fields/DeferredAssetToken.php` exists, syntax valid.
    - `grep -c "namespace lameco\\\\kunstmaanmigrator\\\\fields;" src/fields/FieldHandler.php` returns 1.
    - `grep -c "namespace lameco\\\\kunstmaanmigrator\\\\fields;" src/fields/DeferredAssetToken.php` returns 1.
    - `grep -c "interface FieldHandler" src/fields/FieldHandler.php` returns 1.
    - `grep -c "function id(): string" src/fields/FieldHandler.php` returns 1.
    - `grep -c "function resolve(mixed \\$legacyValue, ResolverContext \\$ctx, array \\$options = \\[\\]): mixed" src/fields/FieldHandler.php` returns 1.
    - `grep -c "return 'asset:' . \\$legacyId;" src/fields/DeferredAssetToken.php` returns 1.
    - `grep -c "PAIRED REGEX CONTRACT" src/fields/DeferredAssetToken.php` returns 1.
  </done>
</task>

<task type="auto">
  <name>Task 2: Port FieldHandlerRegistry with MigrationConfigError → \RuntimeException reshape</name>
  <files>src/fields/FieldHandlerRegistry.php</files>
  <read_first>
    - ~/Sites/craft-kunstmaan-migrator/src/bridge/fields/FieldHandlerRegistry.php (v1, 48 LOC — full file)
    - .planning/phases/03-etl-pipeline-field-handlers/03-PATTERNS.md §2 (FieldHandlerRegistry reshape recipe — full)
    - src/fields/FieldHandler.php (Task 1 output — interface to import)
  </read_first>
  <action>
    Copy `~/Sites/craft-kunstmaan-migrator/src/bridge/fields/FieldHandlerRegistry.php` to `src/fields/FieldHandlerRegistry.php`. Apply these mechanical edits:

    1. Namespace `lameco\kunstmaanmigrator\bridge\fields` → `lameco\kunstmaanmigrator\fields`.
    2. **Drop** the line `use lameco\kunstmaanmigrator\models\MigrationConfigError;` (v1 line 6 — that class does not exist in v2's surface; PATTERNS §2 reshape rule).
    3. Replace the throw site `throw MigrationConfigError::unknownHandler($id, array_keys($this->handlers));` with EXACTLY:
       ```php
       throw new \RuntimeException(sprintf(
           "FieldHandlerRegistry: unknown handler '%s' — registered: [%s].",
           $id,
           implode(', ', array_keys($this->handlers)),
       ));
       ```
    4. Add `declare(strict_types=1);` after `<?php` if v1 omits it.

    DO NOT change: the `final class FieldHandlerRegistry` declaration, the `private array $handlers = []` property, the `register()` method body, the `get()` method's lookup-then-throw shape, the `ids()` method body, the docblocks.

    The hash-keyed registry pattern from PATTERNS §2 must be preserved verbatim aside from the throw replacement.
  </action>
  <verify>
    <automated>php -l src/fields/FieldHandlerRegistry.php</automated>
  </verify>
  <done>
    - `src/fields/FieldHandlerRegistry.php` exists, syntax valid.
    - `grep -c "final class FieldHandlerRegistry" src/fields/FieldHandlerRegistry.php` returns 1.
    - `grep -c "MigrationConfigError" src/fields/FieldHandlerRegistry.php` returns 0 (import dropped + throw replaced).
    - `grep -c "throw new \\\\RuntimeException" src/fields/FieldHandlerRegistry.php` returns 1.
    - `grep -c "FieldHandlerRegistry: unknown handler" src/fields/FieldHandlerRegistry.php` returns 1.
    - `grep -c "public function register(FieldHandler \\$handler): void" src/fields/FieldHandlerRegistry.php` returns 1.
    - `grep -c "public function get(string \\$id): FieldHandler" src/fields/FieldHandlerRegistry.php` returns 1.
    - `grep -c "public function ids(): array" src/fields/FieldHandlerRegistry.php` returns 1.
  </done>
</task>

<task type="auto">
  <name>Task 3: Port ResolverContext VO with import retargeting</name>
  <files>src/fields/ResolverContext.php</files>
  <read_first>
    - ~/Sites/craft-kunstmaan-migrator/src/bridge/fields/ResolverContext.php (v1, 40 LOC — full file)
    - .planning/phases/03-etl-pipeline-field-handlers/03-PATTERNS.md §4 (ResolverContext reshape recipe — full)
    - .planning/phases/02.1-source-introspection/02.1-PATTERNS.md §4 (immutable VO pattern — `final class` + `readonly` constructor-promoted properties)
    - src/db/LegacyDbService.php (lines 1-20 — confirm v2 namespace `lameco\kunstmaanmigrator\db`)
  </read_first>
  <action>
    Copy `~/Sites/craft-kunstmaan-migrator/src/bridge/fields/ResolverContext.php` to `src/fields/ResolverContext.php`. Apply these mechanical edits:

    1. Namespace `lameco\kunstmaanmigrator\bridge\fields` → `lameco\kunstmaanmigrator\fields`.
    2. Retarget the three imports:
       - `use lameco\kunstmaanmigrator\bridge\ckeditor\CkeditorRewriterService;` → `use lameco\kunstmaanmigrator\finalize\CkeditorRewriterService;`
       - `use lameco\kunstmaanmigrator\craft\load\AssetPathResolver;` → `use lameco\kunstmaanmigrator\load\AssetPathResolver;`
       - `use lameco\kunstmaanmigrator\kunstmaan\db\LegacyDbService;` → `use lameco\kunstmaanmigrator\db\LegacyDbService;`
       - `use lameco\kunstmaanmigrator\bridge\fields\MigrationStateReader;` → `use lameco\kunstmaanmigrator\load\MigrationStateReader;` (NOTE: MigrationStateReader moves from v1 `bridge/fields/` to v2 `src/load/` per PATTERNS §5; the file lives in Plan 03-02).
    3. Add `declare(strict_types=1);` if v1 omits it.

    DO NOT change: the `final class ResolverContext` declaration, the constructor's seven-arg shape (siteId, siteHandle, state, ck, paths, siteMap, legacyDb=null), the readonly modifiers on every constructor-promoted property, the docblock at the constructor (`@param array<string, int> $siteMap kuma_locale → Craft siteId`).

    The construction-site contract from PATTERNS §4 — TransformService builds one ResolverContext per (site, entry) tuple — is documented in Plan 03-12. This file just declares the shape.

    NOTE on forward references: This file imports MigrationStateReader (lands in Plan 03-02 same Wave 1), AssetPathResolver (Plan 03-02), CkeditorRewriterService (Plan 03-06 Wave 2), and LegacyDbService (already on disk from Phase 1). PHP autoload will resolve them once those files land. Verification at this plan is `php -l` syntax only — full class-load tests come in Wave 5.
  </action>
  <verify>
    <automated>php -l src/fields/ResolverContext.php</automated>
  </verify>
  <done>
    - `src/fields/ResolverContext.php` exists, syntax valid.
    - `grep -c "namespace lameco\\\\kunstmaanmigrator\\\\fields;" src/fields/ResolverContext.php` returns 1.
    - `grep -c "final class ResolverContext" src/fields/ResolverContext.php` returns 1.
    - `grep -c "use lameco\\\\kunstmaanmigrator\\\\finalize\\\\CkeditorRewriterService;" src/fields/ResolverContext.php` returns 1.
    - `grep -c "use lameco\\\\kunstmaanmigrator\\\\load\\\\AssetPathResolver;" src/fields/ResolverContext.php` returns 1.
    - `grep -c "use lameco\\\\kunstmaanmigrator\\\\db\\\\LegacyDbService;" src/fields/ResolverContext.php` returns 1.
    - `grep -c "use lameco\\\\kunstmaanmigrator\\\\load\\\\MigrationStateReader;" src/fields/ResolverContext.php` returns 1.
    - `grep -c "public readonly int \\$siteId" src/fields/ResolverContext.php` returns 1.
    - `grep -c "public readonly MigrationStateReader \\$state" src/fields/ResolverContext.php` returns 1.
    - `grep -c "public readonly CkeditorRewriterService \\$ck" src/fields/ResolverContext.php` returns 1.
    - `grep -c "public readonly AssetPathResolver \\$paths" src/fields/ResolverContext.php` returns 1.
    - `grep -c "public readonly ?LegacyDbService \\$legacyDb = null" src/fields/ResolverContext.php` returns 1.
    - `grep -c "bridge\\\\" src/fields/ResolverContext.php` returns 0 (no v1 namespace leakage).
  </done>
</task>

</tasks>

<reconciliation>
## FieldHandler reconciliation

**v1 file:** `~/Sites/craft-kunstmaan-migrator/src/bridge/fields/FieldHandler.php` (41 LOC)
**v2 file:** `src/fields/FieldHandler.php` (~25 LOC after `declare(strict_types=1);` add — interface body unchanged)

| v1 Rule (file:line) | Description | Disposition | v2 location / rationale |
|---|---|---|---|
| `FieldHandler.php:1-19` — file docblock | v1 docblock describes the stateless contract (handlers must not hold per-row state). | ported verbatim | Same file. |
| `FieldHandler.php:21-41` — interface body | `id(): string` + `resolve(mixed, ResolverContext, array): mixed` | ported verbatim | Same file. |

## FieldHandlerRegistry reconciliation

**v1 file:** `~/Sites/craft-kunstmaan-migrator/src/bridge/fields/FieldHandlerRegistry.php` (48 LOC)
**v2 file:** `src/fields/FieldHandlerRegistry.php` (~32 LOC)

| v1 Rule (file:line) | Description | Disposition | v2 location / rationale |
|---|---|---|---|
| `FieldHandlerRegistry.php:6` — `use ...MigrationConfigError;` | Imports the typed config-error class. | dropped intentionally | `MigrationConfigError` is not in v2's surface (per 02.1-PATTERNS.md §3 reshape recipe — replaces all such throws with `\RuntimeException`). v2 surfaces the same operator-facing message via `sprintf` literal. |
| `FieldHandlerRegistry.php:25-48` — `final class` + register/get/ids body | Hash-keyed registry. | ported verbatim (modulo throw replacement) | Same file; throw site replaces `MigrationConfigError::unknownHandler()` with `new \RuntimeException(sprintf(...))` carrying the same id + registered-ids context. |

## DeferredAssetToken reconciliation

**v1 file:** `~/Sites/craft-kunstmaan-migrator/src/bridge/fields/DeferredAssetToken.php` (27 LOC)
**v2 file:** `src/fields/DeferredAssetToken.php` (~18 LOC + paired-regex contract comment)

| v1 Rule (file:line) | Description | Disposition | v2 location / rationale |
|---|---|---|---|
| `DeferredAssetToken.php:21-27` — `emit(int): string` returning `'asset:' . $legacyId` | The asset-deferred token format. | ported verbatim | Same file. Paired-regex contract added as comment (no behavioral change). |

## ResolverContext reconciliation

**v1 file:** `~/Sites/craft-kunstmaan-migrator/src/bridge/fields/ResolverContext.php` (40 LOC)
**v2 file:** `src/fields/ResolverContext.php` (~35 LOC after import retargeting)

| v1 Rule (file:line) | Description | Disposition | v2 location / rationale |
|---|---|---|---|
| Imports lines 5-9 | 4 deps from v1's bridge/craft/kunstmaan namespace tree. | ported (retargeted) | All four imports retarget to v2 flat namespaces per D-41. MigrationStateReader specifically moves from `bridge/fields/` to `src/load/` (PATTERNS §5 — sole implementer is MigrationStateService in `src/load/`). |
| Lines 21-40 — 7-arg readonly constructor | siteId, siteHandle, state, ck, paths, siteMap, legacyDb=null. | ported verbatim | Same file, same arg order, same readonly modifiers. |

### Counts (Plan 03-01 only)
| Pair | ported | dropped intentionally | dropped accidentally |
|---|---:|---:|---:|
| FieldHandler | 2 | 0 | 0 |
| FieldHandlerRegistry | 1 | 1 (MigrationConfigError import) | 0 |
| DeferredAssetToken | 1 | 0 | 0 |
| ResolverContext | 2 | 0 | 0 |
| **Plan 03-01 totals** | **6** | **1** | **0** |
</reconciliation>

<verification>
- `php -l` exits 0 with "No syntax errors detected" for all 4 files.
- `grep -rn "lameco\\\\kunstmaanmigrator\\\\bridge" src/fields/` returns zero matches (no v1 namespace leakage).
- `grep -rn "MigrationConfigError" src/fields/` returns zero matches.
- All file-level acceptance greps in tasks 1-3 pass.
- Files compile in isolation (no missing imports — note: classes referenced from other Wave 1/2 plans don't need to exist for `php -l`; full autoload verification comes in Plan 03-14 Wave 5).
</verification>

<success_criteria>
- The 4 scaffold files exist with the verbatim-port contracts intact.
- FieldHandler interface contract preserved byte-for-byte (id + resolve method signatures).
- FieldHandlerRegistry throws \RuntimeException with v1-equivalent message.
- DeferredAssetToken emits 'asset:N' format with paired-regex documentation.
- ResolverContext is final + 7-arg readonly with all 4 imports retargeted to v2 flat namespaces.
- Reconciliation table records 6 ported / 1 dropped intentionally / 0 dropped accidentally.
</success_criteria>

<output>
After completion, create `.planning/phases/03-etl-pipeline-field-handlers/03-01-fields-scaffold-SUMMARY.md` summarizing files created, line counts, and the reconciliation result.
</output>
