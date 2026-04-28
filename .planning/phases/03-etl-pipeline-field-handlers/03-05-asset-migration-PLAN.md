---
phase: 03-etl-pipeline-field-handlers
plan: 05
type: execute
wave: 2
depends_on: ['03-02', '03-03']
files_modified:
  - src/load/AssetMigrationService.php
autonomous: true
requirements: [FH-03, ETL-05]
must_haves:
  truths:
    - "AssetMigrationService::resolveFromLegacyId(int $legacyId): int is the JIT entry point — returns the Craft asset id for a legacy kuma_media id, materializing the asset (file copy + Asset element create + state row write) when not already present (FH-03 JIT default)."
    - "AssetMigrationService::ingestReferenced(MigrationOptions $opts, MigrationFilters $filters): void is the --preload-assets opt-in batch path — pre-walks every referenced kuma_media id before the entries loop runs (FH-03 opt-in)."
    - "State row write contract: source='media', sourceKey='kuma_media:{id}', targetType='asset' (local) or 'video' (remote), meta carries originalUrl/location/contentType/videoId."
    - "Re-run idempotency: kuma_media ids already present in state are skipped unless $opts->force = true (ETL-05)."
  artifacts:
    - path: "src/load/AssetMigrationService.php"
      provides: "FH-03 asset ingest (JIT default + --preload-assets opt-in); state row writer for media."
      min_lines: 500
  key_links:
    - from: "src/load/AssetMigrationService.php"
      to: "src/db/LegacyDbService.php"
      via: "public ?LegacyDbService $legacyDb"
      pattern: "LegacyDbService"
    - from: "src/load/AssetMigrationService.php"
      to: "src/load/MigrationStateService.php"
      via: "public ?MigrationStateService $migrationState"
      pattern: "MigrationStateService"
    - from: "src/load/AssetMigrationService.php"
      to: "src/load/AssetPathResolver.php"
      via: "static call AssetPathResolver::resolveLocal"
      pattern: "AssetPathResolver::resolveLocal"
---

<objective>
Port the 617-LOC AssetMigrationService verbatim from v1 — the asset ingest layer. Two consumer paths:
1. **JIT default (FH-03)** — `resolveFromLegacyId(int)` materializes a single asset on demand (called from AssetHandler when state-lookup misses; called from AtomicMigrationService::ingestAndResolveAssets via the `asset:N` token list).
2. **--preload-assets opt-in (FH-03)** — `ingestReferenced(MigrationOptions, MigrationFilters)` pre-walks every kuma_media id referenced by the entry set before the entries loop. Repurposed from v1's batch-by-default path; v2 makes it opt-in only.

Wave 2 — depends on Plans 03-02 (MigrationOptions, AssetPathResolver) + 03-03 (MigrationStateService).
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
`src/load/AssetMigrationService.php`:
```php
namespace lameco\kunstmaanmigrator\load;

use lameco\kunstmaanmigrator\db\LegacyDbService;
use lameco\kunstmaanmigrator\filter\MigrationFilters;
use yii\base\Component;

class AssetMigrationService extends Component
{
    public ?LegacyDbService        $legacyDb        = null;
    public ?MigrationStateService  $migrationState  = null;
    public ?object                 $serializedDecoder = null; // KunstmaanSerializedDecoder — deferred to Phase 4

    /**
     * JIT entry point: materialize one asset by legacy kuma_media.id, return Craft asset id.
     * Called from AssetHandler (state-lookup miss path) and AtomicMigrationService (deferred-token resolver).
     * Returns 0 if the kuma_media row is missing or the file cannot be located.
     */
    public function resolveFromLegacyId(int $legacyId): int;

    /**
     * --preload-assets opt-in: pre-walk every referenced kuma_media id before entries loop.
     * FH-03 opt-in batch path — repurposed from v1 batch-by-default.
     */
    public function ingestReferenced(MigrationOptions $opts, MigrationFilters $filters): void;
}
```

State-row contract for media (verbatim from v1 lines 30-35):
- source = 'media'
- sourceKey = 'kuma_media:{id}'
- targetType = 'asset' (local file) or 'video' (remote URL)
- targetId = Craft asset id or 0
- targetUid = asset uid or null
- meta = { originalUrl, location, contentType, videoId? }
</interfaces>
</context>

<tasks>

<task type="auto">
  <name>Task 1: Verbatim port AssetMigrationService with JIT-vs-preload reshape + AssetScan/AssetBatchJob/MigrationReport drops</name>
  <files>src/load/AssetMigrationService.php</files>
  <read_first>
    - ~/Sites/craft-kunstmaan-migrator/src/bridge/load/AssetMigrationService.php (v1, 617 LOC — ENTIRE FILE)
    - .planning/phases/03-etl-pipeline-field-handlers/03-PATTERNS.md §14 (AssetMigrationService reshape recipe — full)
    - src/load/MigrationStateService.php (Plan 03-03 output — confirm record() / has() / getTargetId() public surface)
    - src/load/MigrationOptions.php (Plan 03-02 output — confirm dryRun/force/skipAssets/batchSize fields)
    - src/load/AssetPathResolver.php (Plan 03-02 output — confirm resolveLocal() static helper)
    - src/db/LegacyDbService.php (lines 1-50 — confirm queryAll / streamQuery surface)
    - src/filter/MigrationFilters.php (lines 1-50 — confirm readonly fields)
  </read_first>
  <action>
    Copy `~/Sites/craft-kunstmaan-migrator/src/bridge/load/AssetMigrationService.php` to `src/load/AssetMigrationService.php`. Apply these mechanical edits per PATTERNS §14:

    **1. Namespace flatten:** `lameco\kunstmaanmigrator\bridge\load` → `lameco\kunstmaanmigrator\load`.

    **2. Retarget imports:**
    - `use lameco\kunstmaanmigrator\bridge\load\MigrationStateService;` → `use lameco\kunstmaanmigrator\load\MigrationStateService;` (same namespace — drop import; MigrationStateService is in same namespace as this file).
    - `use lameco\kunstmaanmigrator\craft\load\MigrationOptions;` → drop the import (same namespace).
    - `use lameco\kunstmaanmigrator\craft\load\AssetPathResolver;` → drop the import (same namespace).
    - `use lameco\kunstmaanmigrator\kunstmaan\db\LegacyDbService;` → `use lameco\kunstmaanmigrator\db\LegacyDbService;`.
    - Add `use lameco\kunstmaanmigrator\filter\MigrationFilters;`.

    **3. Drop these imports + replace usage:**
    - `use lameco\kunstmaanmigrator\...\AssetScanService;` — drop. v2 page-driven JIT (FH-03) means we don't pre-scan; replace any `$this->assetScanService->...` calls with the equivalent `LegacyDbService` query against `kuma_media` directly. Document in RECONCILIATION as `dropped intentionally — page-driven JIT default per FH-03`.
    - `use lameco\kunstmaanmigrator\...\AssetBatchJob;` — drop. Queue jobs are out of scope for v2 (PROJECT.md). Any `Craft::$app->queue->push(new AssetBatchJob(...))` site converts to a synchronous loop calling `$this->resolveFromLegacyId(...)` per id. Document as `dropped intentionally — queue out of scope per PROJECT.md`.
    - `use lameco\kunstmaanmigrator\...\KunstmaanSerializedDecoder;` — drop. Replace with `public ?object $serializedDecoder = null;` slot; null-check at every call site (same disposition as Plan 03-04 ExtractService).
    - `use lameco\kunstmaanmigrator\...\MigrationReport;` — drop. Replace with out-parameter `array &$counts = []` for any method that v1 wrote to MigrationReport, OR defer the `MigrationReport` VO to Plan 03-13 (AtomicMigrationService) where it becomes a top-level VO consumed across services. **Decision: defer MigrationReport VO to Plan 03-13 as `src/load/MigrationReport.php`. In Plan 03-05, replace any `$report->incr('xxx')` / `$report->warn('xxx')` calls with `Craft::warning(...)` for warnings and a local `$counts['xxx'] = ($counts['xxx'] ?? 0) + 1` accumulator for incr. The Plan 03-13 MigrationReport will replace these accumulators with method calls when wiring lands in Plan 03-14.** Mark each replacement site with `// MigrationReport VO deferred to Plan 03-13 — Phase 3 wiring lands in 03-14.`

    **4. Drop and replace MigrationConfigError:**
    - Drop import; replace throws with `new \RuntimeException(...)` carrying same message.

    **5. JIT-vs-preload reshape (FH-03):**
    - The public method `resolveFromLegacyId(int $legacyId): int` is the v1 verbatim JIT entry point — do NOT modify its body. (Find the v1 method by `grep -n "function resolveFromLegacyId" ~/Sites/craft-kunstmaan-migrator/src/bridge/load/AssetMigrationService.php`. Port its body byte-for-byte.)
    - The public method that v1 named `ingestReferenced(...)` (or similar — locate by `grep -n "public function ingest" ~/Sites/craft-kunstmaan-migrator/src/bridge/load/AssetMigrationService.php`) is the batch path. **Reshape:** in v1 this was the default-by-default; in v2 it becomes opt-in (called only from `migrate --live --preload-assets` per FH-03). Add a docblock comment at the method top: `// FH-03 opt-in: only called when MigrateController parses --preload-assets. JIT default (resolveFromLegacyId) handles the rest.`
    - Update the method signature to thread `MigrationFilters`: `public function ingestReferenced(MigrationOptions $opts, MigrationFilters $filters): void`. Apply filters at the kuma_media query site to scope by `locales` (when locales restrict the entry set, the asset set scopes accordingly). Mark with `// Phase 2 / D-10 filter piping per FILT-02.`

    **6. State row write contract — preserve verbatim** (PATTERNS §14):
    - The call site `$this->migrationState->record('media', "kuma_media:{$id}", null, $targetType, $craftId, $craftUid, ['originalUrl' => ..., 'location' => ..., 'contentType' => ..., 'videoId' => ...])` (or equivalent) must be preserved byte-for-byte.

    **7. Re-run idempotency** (v1 line ~36): preserve the early-skip via `$this->migrationState->has('media', "kuma_media:{$id}", null) && !$opts->force`.

    **8. Sibling-DI slots:**
    ```php
    public ?LegacyDbService        $legacyDb          = null;
    public ?MigrationStateService  $migrationState    = null;
    public ?object                 $serializedDecoder = null;
    ```

    **9. Add `declare(strict_types=1);` if v1 omits.**

    DO NOT change: any private helper bodies (file copy logic, content-type sniffing, video URL detection, asset element creation), the state-row meta shape, the legacy_id → craft_id resolution flow, the threat-model-traceability comments if present.

    **10. Note about MigrationReport temporary state:** The compile-time absence of `MigrationReport` is acceptable for `php -l` syntax verification. Plan 03-13 will introduce the VO; Plan 03-14 wires consumers. Plan 03-05's compile-time check is `php -l` only.
  </action>
  <verify>
    <automated>php -l src/load/AssetMigrationService.php</automated>
  </verify>
  <done>
    - `src/load/AssetMigrationService.php` exists; `php -l` returns "No syntax errors".
    - File has at least 500 lines (verbatim from 617 LOC v1 modulo reshape).
    - `grep -c "namespace lameco\\\\kunstmaanmigrator\\\\load;" src/load/AssetMigrationService.php` returns 1.
    - `grep -c "function resolveFromLegacyId(int \\$legacyId): int" src/load/AssetMigrationService.php` returns 1.
    - `grep -c "function ingestReferenced(MigrationOptions" src/load/AssetMigrationService.php` returns 1.
    - `grep -c "MigrationFilters \\$filters" src/load/AssetMigrationService.php` >= 1.
    - `grep -c "use lameco\\\\kunstmaanmigrator\\\\filter\\\\MigrationFilters;" src/load/AssetMigrationService.php` returns 1.
    - `grep -c "use lameco\\\\kunstmaanmigrator\\\\db\\\\LegacyDbService;" src/load/AssetMigrationService.php` returns 1.
    - `grep -c "AssetScanService" src/load/AssetMigrationService.php` returns 0.
    - `grep -c "AssetBatchJob" src/load/AssetMigrationService.php` returns 0.
    - `grep -c "MigrationConfigError" src/load/AssetMigrationService.php` returns 0.
    - `grep -c "KunstmaanSerializedDecoder" src/load/AssetMigrationService.php` returns 0.
    - `grep -c "public ?object \\$serializedDecoder = null" src/load/AssetMigrationService.php` returns 1.
    - `grep -c "MigrationReport VO deferred" src/load/AssetMigrationService.php` >= 1 (replacement-site markers present).
    - `grep -c "kuma_media:" src/load/AssetMigrationService.php` >= 1 (state-row sourceKey format preserved).
    - `grep -c "FH-03" src/load/AssetMigrationService.php` >= 1 (FH-03 ingestReferenced docblock comment).
    - `grep -rn "lameco\\\\kunstmaanmigrator\\\\\\(bridge\\|craft\\|kunstmaan\\)" src/load/AssetMigrationService.php` returns zero matches.
  </done>
</task>

</tasks>

<reconciliation>
## AssetMigrationService reconciliation

**v1 file:** `~/Sites/craft-kunstmaan-migrator/src/bridge/load/AssetMigrationService.php` (617 LOC)
**v2 file:** `src/load/AssetMigrationService.php` (~530 LOC after reshape)

| v1 Rule (file:line) | Description | Disposition | v2 location / rationale |
|---|---|---|---|
| `resolveFromLegacyId(int): int` body | JIT single-asset materialiser. | ported verbatim | Same file. The JIT default path (FH-03) — called from AssetHandler miss path + AtomicMigrationService deferred-token resolver. |
| `ingestReferenced(...)` body | Batch pre-walk of referenced kuma_media ids. | **repurposed — v1 batch-by-default → v2 opt-in via --preload-assets per FH-03** | Method body preserved verbatim modulo MigrationFilters threading. v1 called this from the default code path; v2 calls it only when MigrateController parses `--preload-assets` (Plan 03-14). |
| State-row write contract (lines ~30-35 area) | source='media', sourceKey='kuma_media:{id}', targetType='asset'\|'video', meta={originalUrl/location/contentType/videoId} | ported verbatim | Same file, same call signature on `MigrationStateService::record()`. |
| Idempotent skip (line ~36) | `has(...) && !$opts->force → continue` | ported verbatim | ETL-05 idempotency — same gate. |
| `use AssetScanService` | v1 pre-scanned legacy media before entries loop. | dropped intentionally | v2 page-driven JIT (FH-03) — assets discover via the deferred-token resolver per-entry. Pre-scan unnecessary; orphan media deliberately not migrated (NEXT-05 deferred). |
| `use AssetBatchJob` | v1 queued asset ingests for parallel processing. | dropped intentionally | Queue dropped from v2 / v1.0 (PROJECT.md). Synchronous loop replaces queue.push. |
| `use KunstmaanSerializedDecoder` | v1 chokepoint for serialized blobs. | partially ported — Phase 4 follow-up | Same disposition as Plan 03-04 ExtractService. `?object = null` slot; null-check call sites. |
| `use MigrationReport` | v1's per-run counts/warnings VO. | partially ported — Plan 03-13 reinstates VO | Plan 03-05 uses `Craft::warning(...)` + local `$counts` accumulators with `// MigrationReport VO deferred to Plan 03-13` markers. Plan 03-14 sibling-DI wiring + AtomicMigrationService introduces the VO and re-binds these markers. |
| MigrationConfigError throws | Typed errors. | dropped intentionally | Replaced with `\RuntimeException` per 02.1 reshape recipe. |
| `run()` / `ingestReferenced()` signatures | v1 had no MigrationFilters. | intentional reshape — Phase 2 D-10 piping per FILT-02 | Added `MigrationFilters $filters` to ingestReferenced; locales scope kuma_media query. |

### Counts (Plan 03-05 only)
| Pair | ported | dropped intentionally | dropped accidentally | partially ported |
|---|---:|---:|---:|---:|
| AssetMigrationService | 4 | 3 (AssetScanService, AssetBatchJob, MigrationConfigError) | 0 | 2 (KunstmaanSerializedDecoder, MigrationReport) |
</reconciliation>

<verification>
- `php -l src/load/AssetMigrationService.php` returns "No syntax errors detected".
- All file-level acceptance greps pass.
- JIT (`resolveFromLegacyId`) and opt-in batch (`ingestReferenced`) paths preserved.
- State-row contract for media preserved verbatim.
- 4 deferred-import dispositions documented in RECONCILIATION.
</verification>

<success_criteria>
- AssetMigrationService ports verbatim modulo JIT-vs-preload reshape, MigrationFilters threading, and 4 import drops.
- FH-03 invariants preserved: JIT default + --preload-assets opt-in; orphan media deliberately not migrated.
- ETL-05 idempotency: state-row presence skips re-ingest unless `$opts->force`.
- MigrationReport VO deferred to Plan 03-13 with explicit replacement-site markers.
</success_criteria>

<output>
Create `.planning/phases/03-etl-pipeline-field-handlers/03-05-asset-migration-SUMMARY.md`.
</output>
