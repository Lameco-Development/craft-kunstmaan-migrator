---
phase: 03-etl-pipeline-field-handlers
plan: 11
type: execute
wave: 4
depends_on: ['03-01', '03-02', '03-03', '03-06', '03-08', '03-09', '03-10']
files_modified:
  - src/transform/TransformService.php
autonomous: true
requirements: [ETL-01, FH-02]
must_haves:
  truths:
    - "TransformService::run(iterable $extracted, array $mapping, MigrationFilters $filters, array $options = []) is the per-entry resolver loop. Builds one ResolverContext per (site, entry) tuple and dispatches each FieldSpec to the registered FieldHandler."
    - "Per CONTEXT D-48 in-process pipeline, run() consumes an iterable of extracted-row tuples directly (NOT scanning disk). v1's disk-scan entry shape is reshaped — handler-routing logic inside the loop ports verbatim."
    - "5 sibling-DI slots: handlerRegistry, ckeditorRewriter, legacyDb, migrationState (typed MigrationStateReader), assetPathResolver. Plugin::init() (Plan 03-14) wires all 5 per the 75a95bc pattern."
    - "MigrationFilters threaded through: locales scope the per-site loop in ResolverContext construction; entities allow-list scopes the FQCN dispatch."
  artifacts:
    - path: "src/transform/TransformService.php"
      provides: "ETL-01 transform stage. 940 LOC verbatim port modulo D-48 in-process pipeline reshape."
      min_lines: 850
  key_links:
    - from: "src/transform/TransformService.php"
      to: "src/fields/FieldHandlerRegistry.php"
      via: "public ?FieldHandlerRegistry $handlerRegistry"
      pattern: "FieldHandlerRegistry"
    - from: "src/transform/TransformService.php"
      to: "src/finalize/CkeditorRewriterService.php"
      via: "public ?CkeditorRewriterService $ckeditorRewriter"
      pattern: "CkeditorRewriterService"
    - from: "src/transform/TransformService.php"
      to: "src/load/MigrationStateReader.php"
      via: "public ?MigrationStateReader $migrationState (narrow read interface)"
      pattern: "MigrationStateReader"
---

<objective>
Port v1's 940-LOC TransformService — the per-entry resolver loop. Walks extracted legacy rows, builds ResolverContext per (site, entry) tuple, dispatches each FieldSpec to the registered FieldHandler, emits Craft entry payload. The handler-routing logic inside the loop is the bulk of the 940 LOC; it ports verbatim. Only the disk-scan entry shape is reshaped per CONTEXT D-48 (in-process iterable input instead of `extracted/<fqcn-slug>/<node-id>.json` walks).

Wave 4 — depends on Wave 1 + 2 + 3: scaffold (Plan 03-01), VOs (03-02), state service (03-03), CKEditor rewriter (03-06), all 5 handlers (03-08, 03-09, 03-10). The largest single file in Phase 3.
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
`src/transform/TransformService.php`:
```php
namespace lameco\kunstmaanmigrator\transform;

use lameco\kunstmaanmigrator\db\LegacyDbService;
use lameco\kunstmaanmigrator\fields\FieldHandlerRegistry;
use lameco\kunstmaanmigrator\fields\ResolverContext;
use lameco\kunstmaanmigrator\filter\MigrationFilters;
use lameco\kunstmaanmigrator\finalize\CkeditorRewriterService;
use lameco\kunstmaanmigrator\load\AssetPathResolver;
use lameco\kunstmaanmigrator\load\MigrationStateReader;
use yii\base\Component;

class TransformService extends Component
{
    public ?FieldHandlerRegistry      $handlerRegistry    = null;
    public ?CkeditorRewriterService   $ckeditorRewriter   = null;
    public ?LegacyDbService           $legacyDb           = null;
    public ?MigrationStateReader      $migrationState     = null;   // narrow read interface
    public ?AssetPathResolver         $assetPathResolver  = null;   // typed (final class — direct property)

    public string $storagePath = '@storage/migration';

    /**
     * @param iterable<array> $extracted stream of extracted-row tuples from ExtractService
     * @return iterable<array{stateSource:string, stateKey:string|int, perSite:array, ...}> transform output
     */
    public function run(iterable $extracted, array $mapping, MigrationFilters $filters, array $options = []): iterable;
}
```
</interfaces>
</context>

<tasks>

<task type="auto">
  <name>Task 1: Verbatim port TransformService (940 LOC) with D-48 in-process pipeline reshape + MigrationFilters threading</name>
  <files>src/transform/TransformService.php</files>
  <read_first>
    - ~/Sites/craft-kunstmaan-migrator/src/bridge/transform/TransformService.php (v1, 940 LOC — ENTIRE FILE; this is the verbatim source-of-truth)
    - .planning/phases/03-etl-pipeline-field-handlers/03-PATTERNS.md §12 (TransformService — full reshape recipe)
    - .planning/phases/03-etl-pipeline-field-handlers/03-PATTERNS.md "Shared Pattern 2" (MigrationFilters piping)
    - .planning/phases/03-etl-pipeline-field-handlers/03-PATTERNS.md "Shared Pattern 1" (Plugin::init() sibling-DI 75a95bc pattern)
    - src/fields/FieldHandlerRegistry.php (Plan 03-01 — confirm get(string) + ids() public surface)
    - src/fields/ResolverContext.php (Plan 03-01 — confirm 7-arg readonly constructor)
    - src/finalize/CkeditorRewriterService.php (Plan 03-06 — confirm public rewrite() signature)
    - src/load/MigrationStateReader.php (Plan 03-02 — confirm narrow 3-method read surface)
    - src/load/AssetPathResolver.php (Plan 03-02 — confirm static helper)
    - src/db/LegacyDbService.php (lines 1-50 — confirm public surface)
    - src/filter/MigrationFilters.php (lines 1-50)
  </read_first>
  <action>
    Copy `~/Sites/craft-kunstmaan-migrator/src/bridge/transform/TransformService.php` to `src/transform/TransformService.php`. Apply per PATTERNS §12:

    **1. Namespace flatten:** `lameco\kunstmaanmigrator\bridge\transform` → `lameco\kunstmaanmigrator\transform`.

    **2. Retarget imports** (PATTERNS §12 #2 — all 5 deps):
    - `use lameco\kunstmaanmigrator\bridge\fields\FieldHandlerRegistry;` → `use lameco\kunstmaanmigrator\fields\FieldHandlerRegistry;`
    - `use lameco\kunstmaanmigrator\bridge\ckeditor\CkeditorRewriterService;` → `use lameco\kunstmaanmigrator\finalize\CkeditorRewriterService;`
    - `use lameco\kunstmaanmigrator\kunstmaan\db\LegacyDbService;` → `use lameco\kunstmaanmigrator\db\LegacyDbService;`
    - `use lameco\kunstmaanmigrator\bridge\fields\MigrationStateReader;` → `use lameco\kunstmaanmigrator\load\MigrationStateReader;`
    - `use lameco\kunstmaanmigrator\craft\load\AssetPathResolver;` → `use lameco\kunstmaanmigrator\load\AssetPathResolver;`
    - `use lameco\kunstmaanmigrator\bridge\fields\ResolverContext;` → `use lameco\kunstmaanmigrator\fields\ResolverContext;`

    Add: `use lameco\kunstmaanmigrator\filter\MigrationFilters;`.

    **3. Drop and replace MigrationConfigError:** all throws become `new \RuntimeException(...)` carrying same messages.

    **4. Class header — preserve verbatim** (PATTERNS §12, v1 lines 42-51):
    ```php
    class TransformService extends Component
    {
        public ?FieldHandlerRegistry $handlerRegistry = null;
        public ?CkeditorRewriterService $ckeditorRewriter = null;
        public ?LegacyDbService $legacyDb = null;
        public ?MigrationStateReader $migrationState = null;
        public ?AssetPathResolver $assetPathResolver = null;

        public string $storagePath = '@storage/migration';
    ```
    Sibling-DI required — Plugin::init() (Plan 03-14) wires all 5 ?Foo slots.

    **5. D-48 IN-PROCESS PIPELINE RESHAPE — `run()` entry point.**

    v1's `run(array $mapping, array $options = [])` walks disk artifacts at `extracted/<fqcn-slug>/<node-id>.json`. Per CONTEXT D-48, v2 has no intermediate disk artifacts — TransformService receives the extracted iterable directly from ExtractService.

    Reshape `run()` to:
    ```php
    public function run(iterable $extracted, array $mapping, MigrationFilters $filters, array $options = []): iterable
    {
        // ... per-entry loop body — see step 6 ...
    }
    ```

    **Reshape strategy.** Find v1's disk-walk loop (typically a `foreach (glob(...) as $file) { $row = json_decode(...); }` pattern at the top of `run()`). Replace the disk walk with `foreach ($extracted as $row) { ... }`. The body of the loop — the entire handler-routing logic — ports verbatim.

    Mark the reshape with: `// CONTEXT D-48 in-process pipeline reshape — extracted rows arrive via iterable instead of disk artifacts.`

    Document this reshape in RECONCILIATION as `intentional reshape — D-48 single-process pipeline`.

    **6. PRESERVE BYTE-FOR-BYTE — handler-routing logic inside the loop body** (v1 lines 200+).

    The per-row body of the loop includes:
    - Site-loop construction (`foreach ($sites as $kumaLocale => $craftSiteHandle) { ... }`).
    - ResolverContext construction per (site, entry) tuple — use the 7-arg shape verbatim:
      ```php
      $ctx = new ResolverContext(
          siteId: $craftSiteId,
          siteHandle: $craftSiteHandle,
          state: $this->migrationState,
          ck: $this->ckeditorRewriter,
          paths: $this->assetPathResolver,
          siteMap: $siteMap,
          legacyDb: $this->legacyDb,
      );
      ```
      (Per PATTERNS §4 named arguments are preferred — preserve.)
    - Per-FieldSpec walk: look up handler via `$this->handlerRegistry->get($fieldSpec['handler'])`, call `$handler->resolve($legacyValue, $ctx, $options)`.
    - The pageParts pre-resolution path: when the mapping row has `kind: pagePart`, walk the pre-fetched page-part rows from extract output and pre-resolve each block's fields hash before passing to MatrixHandler (Plan 03-09 D-49 page-part path).
    - Output assembly: per-site value hashes, stateSource/stateKey assignment, refIdsByLocale tracking.

    **DO NOT change** any of these — port verbatim.

    **7. MIGRATIONFILTERS THREADING** (Shared Pattern 2):
    - At the top of `run()` (after the gate-first / null-check guards), apply `$filters->locales` to scope the per-site loop: skip site iterations where `$craftSiteHandle` is not in the filter set.
    - Apply `$filters->entities` to scope the FQCN dispatch: skip mapping rows whose `targetEntryType` is not in `$filters->entities`.
    - Mark each filter site with: `// Phase 2 / D-10 filter piping per FILT-02 — added in v2 port.`

    **8. Add `declare(strict_types=1);` if v1 omits.**

    DO NOT change: any private helper, any FieldSpec walk logic, any per-site value hash assembly, any state-source/state-key formula, any refIdsByLocale tracking, any pageParts pre-resolution loop.
  </action>
  <verify>
    <automated>php -l src/transform/TransformService.php</automated>
  </verify>
  <done>
    - `src/transform/TransformService.php` exists; `php -l` returns "No syntax errors".
    - File has at least 850 lines (verbatim from 940 LOC v1 modulo reshape).
    - `grep -c "namespace lameco\\\\kunstmaanmigrator\\\\transform;" src/transform/TransformService.php` returns 1.
    - `grep -c "class TransformService extends Component" src/transform/TransformService.php` returns 1.
    - `grep -c "public ?FieldHandlerRegistry \\$handlerRegistry = null" src/transform/TransformService.php` returns 1.
    - `grep -c "public ?CkeditorRewriterService \\$ckeditorRewriter = null" src/transform/TransformService.php` returns 1.
    - `grep -c "public ?LegacyDbService \\$legacyDb = null" src/transform/TransformService.php` returns 1.
    - `grep -c "public ?MigrationStateReader \\$migrationState = null" src/transform/TransformService.php` returns 1.
    - `grep -c "public ?AssetPathResolver \\$assetPathResolver = null" src/transform/TransformService.php` returns 1.
    - `grep -c "use lameco\\\\kunstmaanmigrator\\\\filter\\\\MigrationFilters;" src/transform/TransformService.php` returns 1.
    - `grep -c "MigrationFilters \\$filters" src/transform/TransformService.php` >= 1 (signature threading).
    - `grep -c "function run(iterable \\$extracted" src/transform/TransformService.php` returns 1 (D-48 reshape applied).
    - `grep -c "CONTEXT D-48 in-process pipeline reshape" src/transform/TransformService.php` >= 1.
    - `grep -c "Phase 2 / D-10 filter piping" src/transform/TransformService.php` >= 1.
    - `grep -c "MigrationConfigError" src/transform/TransformService.php` returns 0.
    - `grep -c "new ResolverContext" src/transform/TransformService.php` >= 1 (per-tuple ctx construction preserved).
    - `grep -c "handlerRegistry->get" src/transform/TransformService.php` >= 1 (handler dispatch preserved).
    - `grep -rn "lameco\\\\kunstmaanmigrator\\\\\\(bridge\\|craft\\|kunstmaan\\)" src/transform/TransformService.php` returns zero matches.
  </done>
</task>

</tasks>

<reconciliation>
## TransformService reconciliation

**v1 file:** `~/Sites/craft-kunstmaan-migrator/src/bridge/transform/TransformService.php` (940 LOC)
**v2 file:** `src/transform/TransformService.php` (~890 LOC after namespace flatten + D-48 reshape + filter piping)

| v1 Rule (file:line) | Description | Disposition | v2 location / rationale |
|---|---|---|---|
| Lines 42-51 — class header (5 ?Foo slots + storagePath) | Sibling-DI shape. | ported verbatim | Plugin::init() (Plan 03-14) wires all 5 slots per 75a95bc. |
| Lines 200+ — per-row handler-routing loop body | The bulk of the 940 LOC. | ported verbatim | Site-loop + ResolverContext construction + FieldSpec walk + pageParts pre-resolution. |
| Lines 26-27 docblock — named-arguments recommendation for ResolverContext | API doc. | ported verbatim | Same file. |
| `run(array $mapping, array $options)` v1 signature with disk-scan loop entry | Walked `extracted/<fqcn-slug>/<node-id>.json` artifacts. | **intentional reshape — D-48 single-process pipeline** | v2 signature: `run(iterable $extracted, array $mapping, MigrationFilters $filters, array $options = [])`. Disk walk replaced with `foreach ($extracted as $row)` consuming ExtractService stream directly. Body of loop unchanged. |
| (no v1 rule) — MigrationFilters | v1 had no Phase 2 filter spec. | **intentional reshape — Phase 2 D-10 filter piping per FILT-02** | Filters scope per-site loop + FQCN dispatch. Two filter-site comment markers in source. |
| MigrationConfigError throws | Typed errors. | dropped intentionally | `\RuntimeException`. |

### Counts (Plan 03-11 only)
| Pair | ported | dropped intentionally | dropped accidentally | intentional reshape |
|---|---:|---:|---:|---:|
| TransformService | 3 | 1 (MigrationConfigError) | 0 | 2 (D-48 disk→iterable, MigrationFilters threading) |
</reconciliation>

<verification>
- `php -l src/transform/TransformService.php` returns "No syntax errors detected".
- All 5 sibling-DI slots present.
- D-48 in-process pipeline reshape applied (iterable input instead of disk walk).
- MigrationFilters threaded with documented per-stage application.
- Handler-routing loop body ports verbatim.
</verification>

<success_criteria>
- TransformService 940 LOC ports verbatim modulo D-48 reshape + MigrationFilters threading + namespace flatten.
- Plugin::init() (Plan 03-14) sibling-DI wiring confirmed by 5-slot grep.
- Reconciliation documents 3 ported / 1 dropped intentionally / 2 intentional reshapes.
</success_criteria>

<output>
Create `.planning/phases/03-etl-pipeline-field-handlers/03-11-transform-service-SUMMARY.md`.
</output>
