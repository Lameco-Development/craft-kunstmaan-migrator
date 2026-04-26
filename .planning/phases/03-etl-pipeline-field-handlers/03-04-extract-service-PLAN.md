---
phase: 03-etl-pipeline-field-handlers
plan: 04
type: execute
wave: 2
depends_on: []
files_modified:
  - src/extract/ExtractService.php
autonomous: true
requirements: [ETL-03]
must_haves:
  truths:
    - "ExtractService::run(array $mapping, MigrationFilters $filters, array $options = []) is the only legacy DB read entry point in Phase 3 — extracts kuma_nodes + kuma_node_versions + kuma_main_pageparts + per-page-class detail rows in topologically-ordered batches."
    - "Topological ordering of legacy tables is delegated to TopologicalOrderer (Phase 02.1) — kuma_nodes parent-first hierarchical insertion (ETL-03)."
    - "Page-part rows are JOINed via kuma_page_part_refs and ordered by 'context, sequencenumber' per v1's authoritative SQL (NOT by 'weight' as CONTEXT D-49 hypothesized — D-46 verbatim discipline → v1 wins; documented in RECONCILIATION)."
    - "MigrationFilters threads through run(): entities → FQCN allow-list scopes the walk; locales → kuma_node_translations JOIN scope; since → WHERE updated_at >= :since predicate."
  artifacts:
    - path: "src/extract/ExtractService.php"
      provides: "ETL-03 topologically-ordered legacy DB extract surface."
      min_lines: 450
  key_links:
    - from: "src/extract/ExtractService.php"
      to: "src/db/LegacyDbService.php"
      via: "public ?LegacyDbService $legacyDb (Plugin::init() sibling-DI)"
      pattern: "LegacyDbService"
    - from: "src/extract/ExtractService.php"
      to: "src/source/TopologicalOrderer.php"
      via: "public ?TopologicalOrderer $topologicalOrderer"
      pattern: "TopologicalOrderer"
    - from: "src/extract/ExtractService.php"
      to: "src/source/DetailTableResolver.php"
      via: "public ?DetailTableResolver $detailTableResolver"
      pattern: "DetailTableResolver"
---

<objective>
Port the 533-LOC ExtractService verbatim from v1 — the legacy DB read surface that streams Kunstmaan rows into Phase 3's transform stage. Reshape: thread `MigrationFilters` through the run() entry point (Phase 2 / D-10 piping pattern). Drop `KunstmaanSerializedDecoder` import; surface as a `?object = null` slot (deferred to Phase 4).

**Critical RECONCILIATION entry: page-part row ordering.** CONTEXT D-49 hypothesized `kuma_page_part_refs.weight`; v1 authoritative source uses `ORDER BY context, sequencenumber`. D-46 verbatim discipline → v1 wins. This plan's RECONCILIATION section MUST document the drift.

Wave 2 — depends on no other Phase 3 plans (consumes only Phase 1 + 02.1 priors). Plan 03-12 (TransformService) consumes ExtractService output.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/PROJECT.md
@.planning/phases/03-etl-pipeline-field-handlers/03-CONTEXT.md
@.planning/phases/03-etl-pipeline-field-handlers/03-PATTERNS.md
@.planning/phases/02-schema-mapping-filters/02-CONTEXT.md

<interfaces>
`src/extract/ExtractService.php`:
```php
namespace lameco\kunstmaanmigrator\extract;

use lameco\kunstmaanmigrator\db\LegacyDbService;
use lameco\kunstmaanmigrator\filter\MigrationFilters;        // Phase 2
use lameco\kunstmaanmigrator\source\DetailTableResolver;     // Phase 02.1
use lameco\kunstmaanmigrator\source\KunstmaanCoreTables;     // Phase 02.1 (already on disk at src/source/KunstmaanCoreTables.php)
use lameco\kunstmaanmigrator\source\TopologicalOrderer;      // Phase 02.1
use yii\base\Component;

class ExtractService extends Component
{
    public ?LegacyDbService     $legacyDb            = null;
    public ?DetailTableResolver $detailTableResolver = null;
    public ?TopologicalOrderer  $topologicalOrderer  = null;
    public ?object              $serializedDecoder   = null; // KunstmaanSerializedDecoder — deferred to Phase 4

    /**
     * @param array $mapping       parsed mapping.yaml (proposals + meta)
     * @param MigrationFilters $filters Phase 2 D-10 filter spec (entities/locales/since)
     * @param array $options      per-run flags
     * @return iterable<array> stream of extracted-row tuples to be consumed by TransformService
     */
    public function run(array $mapping, MigrationFilters $filters, array $options = []): iterable;
}
```

**Phase 02.1 priors already on disk:**
- `src/source/TopologicalOrderer.php` (130 LOC)
- `src/source/DetailTableResolver.php` (verified Phase 02.1 / Plan 02)
- `src/source/KunstmaanCoreTables.php`
- `src/db/LegacyDbService.php`

**Page-part ordering authoritative SQL (from v1 ExtractService.php:433):**
```sql
SELECT pp.* FROM kuma_page_part_refs ppr
JOIN <pagePartTable> pp ON pp.id = ppr.pagepart_id
WHERE ppr.pagepartable_id = :nv AND ppr.pagepartable_type = :ptype
ORDER BY context, sequencenumber
```
</interfaces>
</context>

<tasks>

<task type="auto">
  <name>Task 1: Verbatim port ExtractService with MigrationFilters threading + KunstmaanSerializedDecoder defer</name>
  <files>src/extract/ExtractService.php</files>
  <read_first>
    - ~/Sites/craft-kunstmaan-migrator/src/kunstmaan/extract/ExtractService.php (v1, 533 LOC — ENTIRE FILE; this is the verbatim source-of-truth)
    - .planning/phases/03-etl-pipeline-field-handlers/03-PATTERNS.md §11 (ExtractService reshape recipe — full)
    - .planning/phases/03-etl-pipeline-field-handlers/03-PATTERNS.md "Shared Pattern 2" (MigrationFilters piping)
    - src/source/TopologicalOrderer.php (lines 1-30 — confirm v2 namespace + public API)
    - src/source/DetailTableResolver.php (lines 1-30 — confirm v2 namespace + public resolve() method)
    - src/source/KunstmaanCoreTables.php (lines 1-30 — confirm v2 location)
    - src/db/LegacyDbService.php (lines 1-50 — confirm public API for SELECT queries)
    - src/filter/MigrationFilters.php (lines 1-50 — confirm readonly VO shape: entities/locales/since)
  </read_first>
  <action>
    Copy `~/Sites/craft-kunstmaan-migrator/src/kunstmaan/extract/ExtractService.php` to `src/extract/ExtractService.php`. Apply these mechanical edits per PATTERNS §11:

    **1. Namespace flatten:** `lameco\kunstmaanmigrator\kunstmaan\extract` → `lameco\kunstmaanmigrator\extract`.

    **2. Retarget imports:**
    - `use lameco\kunstmaanmigrator\kunstmaan\db\LegacyDbService;` → `use lameco\kunstmaanmigrator\db\LegacyDbService;`
    - `use lameco\kunstmaanmigrator\kunstmaan\schema\DetailTableResolver;` → `use lameco\kunstmaanmigrator\source\DetailTableResolver;`
    - `use lameco\kunstmaanmigrator\kunstmaan\schema\TopologicalOrderer;` → `use lameco\kunstmaanmigrator\source\TopologicalOrderer;`
    - `use lameco\kunstmaanmigrator\kunstmaan\db\KunstmaanCoreTables;` → `use lameco\kunstmaanmigrator\source\KunstmaanCoreTables;`

    **3. Drop and replace MigrationConfigError:**
    - Drop `use lameco\kunstmaanmigrator\models\MigrationConfigError;`.
    - Replace every `throw MigrationConfigError::xxx(...)` site with `throw new \RuntimeException($msg)` carrying the same operator-facing message.

    **4. KunstmaanSerializedDecoder defer:**
    - Drop `use lameco\kunstmaanmigrator\...\KunstmaanSerializedDecoder;` import.
    - Replace the typed property slot (e.g. `public ?KunstmaanSerializedDecoder $serializedDecoder = null;`) with `public ?object $serializedDecoder = null;`.
    - Find every call site `$this->serializedDecoder->...`. Wrap each call in a null-check; if `serializedDecoder === null`, fall through with the raw value (behavioral equivalence with no-decoder path). Document this in RECONCILIATION as `partially ported — KunstmaanSerializedDecoder deferred to Phase 4`.

    **5. Add `MigrationFilters` import + thread through public method signatures:**
    - Add `use lameco\kunstmaanmigrator\filter\MigrationFilters;` import.
    - Change the public `run()` signature from v1's shape to: `public function run(array $mapping, MigrationFilters $filters, array $options = []): iterable`. (v1's run() signature is on or near line 60-90 — locate via `function run(`.)
    - Inside `run()`, apply filters at three sites:
      - **entities allow-list:** before iterating mapping FQCNs, skip any FQCN whose target entry type is not in `$filters->entities` (when `$filters->entities` is non-empty).
      - **locales subset:** when JOINing `kuma_node_translations`, add `AND nt.lang IN (:l0, :l1, ...)` predicate when `$filters->locales` is non-empty (use bound parameters; build the placeholder list from filter array).
      - **since predicate:** when SELECTing kuma_nodes/kuma_node_versions, add `AND <table>.updated_at >= :since` when `$filters->since !== null`. (Researcher decision — column name confirmed against v1: kuma_node_versions.updated has timestamps; use `nv.updated >= :since` if v1's table aliases are `nv`.)

    Mark each filter application site with a comment: `// Phase 2 / D-10 filter piping per FILT-02 — added in v2 port (not in v1).`

    **6. Page-part row ordering — VERBATIM PORT — DO NOT REORDER.** v1 line 433 (find via `grep -n "ORDER BY context, sequencenumber" ~/Sites/craft-kunstmaan-migrator/src/kunstmaan/extract/ExtractService.php`) uses `ORDER BY context, sequencenumber`. **Preserve this verbatim.** CONTEXT D-49 hypothesized `kuma_page_part_refs.weight` — D-46 verbatim discipline overrides; v1 wins. The full SQL fragment to preserve verbatim is:
    ```sql
    SELECT pp.* FROM kuma_page_part_refs ppr
    JOIN <pagePartTable> pp ON pp.id = ppr.pagepart_id
    WHERE ppr.pagepartable_id = :nv AND ppr.pagepartable_type = :ptype
    ORDER BY context, sequencenumber
    ```

    **7. Add `declare(strict_types=1);` if v1 omits.**

    DO NOT change: any private helper method bodies, any SQL fragment apart from the optional locales-IN and since-WHERE additions, the topological ordering call site, the streaming/Generator return semantics, the KunstmaanCoreTables consumption.

    **Sibling-DI slots (75a95bc pattern).** Confirm the class header has these public typed properties (set null defaults if v1 lacks them):
    ```php
    public ?LegacyDbService     $legacyDb            = null;
    public ?DetailTableResolver $detailTableResolver = null;
    public ?TopologicalOrderer  $topologicalOrderer  = null;
    public ?object              $serializedDecoder   = null; // Phase 4 reinstates typed
    ```
    These are wired by `Plugin::init()` in Plan 03-14.
  </action>
  <verify>
    <automated>php -l src/extract/ExtractService.php && grep -c "ORDER BY context, sequencenumber" src/extract/ExtractService.php</automated>
  </verify>
  <done>
    - `src/extract/ExtractService.php` exists; `php -l` returns "No syntax errors".
    - File has at least 450 lines (verbatim port from 533 LOC v1, allowing for filter-piping additions).
    - `grep -c "namespace lameco\\\\kunstmaanmigrator\\\\extract;" src/extract/ExtractService.php` returns 1.
    - `grep -c "use lameco\\\\kunstmaanmigrator\\\\filter\\\\MigrationFilters;" src/extract/ExtractService.php` returns 1.
    - `grep -c "use lameco\\\\kunstmaanmigrator\\\\source\\\\TopologicalOrderer;" src/extract/ExtractService.php` returns 1.
    - `grep -c "use lameco\\\\kunstmaanmigrator\\\\source\\\\DetailTableResolver;" src/extract/ExtractService.php` returns 1.
    - `grep -c "use lameco\\\\kunstmaanmigrator\\\\db\\\\LegacyDbService;" src/extract/ExtractService.php` returns 1.
    - `grep -c "use lameco\\\\kunstmaanmigrator\\\\source\\\\KunstmaanCoreTables;" src/extract/ExtractService.php` returns 1.
    - `grep -c "MigrationConfigError" src/extract/ExtractService.php` returns 0.
    - `grep -c "KunstmaanSerializedDecoder" src/extract/ExtractService.php` returns 0 (import dropped).
    - `grep -c "public ?object \\$serializedDecoder = null" src/extract/ExtractService.php` returns 1 (untyped slot).
    - `grep -c "public ?LegacyDbService \\$legacyDb = null" src/extract/ExtractService.php` returns 1.
    - `grep -c "public ?TopologicalOrderer \\$topologicalOrderer = null" src/extract/ExtractService.php` returns 1.
    - `grep -c "MigrationFilters \\$filters" src/extract/ExtractService.php` >= 1 (signature threading).
    - `grep -c "ORDER BY context, sequencenumber" src/extract/ExtractService.php` returns 1 (CRITICAL — verbatim port from v1:433).
    - `grep -c "ORDER BY weight" src/extract/ExtractService.php` returns 0 (D-49 hypothesis NOT adopted).
    - `grep -c "Phase 2 / D-10 filter piping" src/extract/ExtractService.php` >= 1 (filter-application comments present).
    - `grep -rn "lameco\\\\kunstmaanmigrator\\\\\\(bridge\\|craft\\|kunstmaan\\)" src/extract/ExtractService.php` returns zero matches.
  </done>
</task>

</tasks>

<reconciliation>
## ExtractService reconciliation

**v1 file:** `~/Sites/craft-kunstmaan-migrator/src/kunstmaan/extract/ExtractService.php` (533 LOC)
**v2 file:** `src/extract/ExtractService.php` (~510 LOC after namespace flatten + filter-piping additions)

| v1 Rule (file:line) | Description | Disposition | v2 location / rationale |
|---|---|---|---|
| Imports — bridge/kunstmaan/craft namespace tree | 5 deps. | retargeted | All 4 retain (LegacyDbService, DetailTableResolver, TopologicalOrderer, KunstmaanCoreTables); MigrationConfigError dropped. |
| Imports — `KunstmaanSerializedDecoder` | v1 chokepoint for serialized-blob safety. | **partially ported — Phase 4 follow-up** | Replaced with `?object = null` slot; null-check guards every call site so v2 falls through with raw value when decoder is unset. Phase 4's SeomaticPayloadBuilder + ExtractService share this dependency; ported together at that time. |
| MigrationConfigError throws | Typed config errors. | dropped intentionally | Replaced with `\RuntimeException` per 02.1 reshape recipe. Same operator-facing messages. |
| `run()` public signature — `(array $mapping, array $options = [])` | v1 had no Phase 2 filter spec. | **intentional reshape — Phase 2 D-10 filter piping per FILT-02** | v2 signature: `(array $mapping, MigrationFilters $filters, array $options = [])`. Three filter sites: (1) entities allow-list scopes FQCN walk, (2) locales scope kuma_node_translations JOIN, (3) since adds `updated_at >= :since` predicate. FILT-02 invariant: filters apply uniformly through every stage. |
| **Line 433 — page-part ordering SQL** — `ORDER BY context, sequencenumber` | v1's authoritative ordering for joining kuma_page_part_refs to per-class detail tables. | **ported verbatim** — D-46 wins over CONTEXT D-49 | **CONTEXT.md D-49 drift recorded.** D-49 hypothesized `kuma_page_part_refs.weight`; v1 source-of-truth at ExtractService.php:433 uses `context, sequencenumber`. Verbatim discipline (D-46) overrides hypothesis. CONTEXT.md wording corrected at next phase-doc update; this RECONCILIATION entry is the canonical record. The `weight` column may exist on `kuma_page_part_refs` but v1's behavior keys on (context, sequencenumber) — operator-observed CQM behavior over the rehearsal corpus is the witness. |
| Streaming/Generator pattern — `iterable` returns | v1 streams to avoid loading all rows in memory. | ported verbatim | Same iterable return; Phase 3 in-process pipeline (CONTEXT D-48) consumes the stream directly without intermediate disk artifact. |

### Counts (Plan 03-04 only)
| Pair | ported | dropped intentionally | dropped accidentally | partially ported |
|---|---:|---:|---:|---:|
| ExtractService | 4 | 1 (MigrationConfigError) | 0 | 1 (KunstmaanSerializedDecoder — Phase 4) |

**Drift log entry (must remain in this RECONCILIATION):**
> CONTEXT.md D-49 says page-parts are "ordered by `kuma_page_part_refs.weight`". v1 source-of-truth at `~/Sites/craft-kunstmaan-migrator/src/kunstmaan/extract/ExtractService.php:433` uses `ORDER BY context, sequencenumber`. Adopted v1 per D-46 verbatim discipline. CONTEXT.md wording to be corrected at next phase-doc update.
</reconciliation>

<verification>
- `php -l src/extract/ExtractService.php` returns "No syntax errors detected".
- All file-level acceptance greps pass.
- Page-part ordering RECONCILIATION entry documents the D-49 vs v1:433 drift.
- KunstmaanSerializedDecoder defer is documented and surface remains `?object = null`.
- MigrationFilters threaded through `run()` signature with three filter-application comment markers.
</verification>

<success_criteria>
- ExtractService ports verbatim with namespace flatten + dependency retargeting.
- MigrationFilters threads through run() per FILT-02 invariant.
- Page-part SQL preserves `ORDER BY context, sequencenumber` (v1 wins over CONTEXT D-49).
- KunstmaanSerializedDecoder deferred to Phase 4 with documented null-check fall-through.
- Reconciliation table records 4 ported / 1 dropped intentionally / 1 partially ported.
</success_criteria>

<output>
Create `.planning/phases/03-etl-pipeline-field-handlers/03-04-extract-service-SUMMARY.md`. The page-part ordering drift documented in this plan's RECONCILIATION rolls up to the phase-level RECONCILIATION.md in Plan 03-15.
</output>
