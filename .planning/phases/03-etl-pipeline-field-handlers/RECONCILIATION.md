# RECONCILIATION.md — v1↔v2 rule-by-rule disposition

**Phase:** 03 ETL Pipeline & Field Handlers · **Plan:** 14 (closing)
**Methodology:** D-46 — Verbatim port + per-plan disposition.

**v1 brownfield reference root:** `~/Sites/craft-kunstmaan-migrator/src/`
**v2 fresh-write reference root:** `src/{extract,transform,load,finalize,fields}/`

LOC delta context: ~3000 LOC v1 ETL (TransformService 940 + AssetMigrationService 617 +
EntryMigrationService 662 + CkeditorRewriterService 529 + ExtractService 533 +
MigrationStateService 356 + AtomicMigrationService 255 + 5 handlers ~880 + AttachService 178 +
support VOs ~250) ports to v2 at ~2900 LOC after intentional drops + reshape adds. The
delta is justified by D-48 (in-process pipeline collapses disk-walking entry/exit shells)
and D-46 verbatim discipline (no opportunistic refactors). Architectural reshape adds
(filter piping per FILT-02, Phase B SEOmatic closure stub, deferred-token paired-regex
contract) are accounted for in the per-plan tables below.

---

## DRIFT NOTE — page-part ordering (CRITICAL)

CONTEXT.md D-49 says page-parts are "ordered by `kuma_page_part_refs.weight`". v1 source-of-truth
at `~/Sites/craft-kunstmaan-migrator/src/kunstmaan/extract/ExtractService.php:433` uses
`ORDER BY context, sequencenumber`. **D-46 verbatim discipline overrides — v1 wins.**
Adopted v1 ordering verbatim in Plan 03-04. CONTEXT.md wording to be corrected at next
phase-doc update.

The full SQL fragment preserved verbatim (Plan 03-04 acceptance grep enforces):

```sql
SELECT pp.* FROM kuma_page_part_refs ppr
JOIN <pagePartTable> pp ON pp.id = ppr.pagepart_id
WHERE ppr.pagepartable_id = :nv AND ppr.pagepartable_type = :ptype
ORDER BY context, sequencenumber
```

The `weight` column may exist on `kuma_page_part_refs` but v1's behavior keys on
`(context, sequencenumber)` — operator-observed CQM behavior over the rehearsal corpus
is the witness. This RECONCILIATION entry is the canonical record.

---

## Per-plan reconciliation aggregates

### Plan 03-01: Fields scaffold (FieldHandler / Registry / DeferredAssetToken / ResolverContext)

**v1 files:** `~/Sites/craft-kunstmaan-migrator/src/bridge/fields/{FieldHandler,FieldHandlerRegistry,DeferredAssetToken,ResolverContext}.php` (41 + 48 + 27 + 40 = 156 LOC)
**v2 files:** `src/fields/{FieldHandler,FieldHandlerRegistry,DeferredAssetToken,ResolverContext}.php` (~25 + ~32 + ~18 + ~35 = ~110 LOC)

| v1 Rule (file:line) | Description | Disposition | v2 location / rationale |
|---|---|---|---|
| `FieldHandler.php:1-19` — file docblock | v1 docblock describes the stateless contract (handlers must not hold per-row state). | ported verbatim | Same file. |
| `FieldHandler.php:21-41` — interface body | `id(): string` + `resolve(mixed, ResolverContext, array): mixed` | ported verbatim | Same file. |
| `FieldHandlerRegistry.php:6` — `use ...MigrationConfigError;` | Imports the typed config-error class. | dropped intentionally | Replaced with `\RuntimeException` per 02.1-PATTERNS.md §3 reshape recipe. Same operator-facing message via sprintf literal. |
| `FieldHandlerRegistry.php:25-48` — `final class` + register/get/ids body | Hash-keyed registry. | ported verbatim (modulo throw replacement) | Same file. |
| `DeferredAssetToken.php:21-27` — `emit(int): string` returning `'asset:' . $legacyId` | Asset-deferred token format. | ported verbatim | Same file. Paired-regex contract added as comment. |
| `ResolverContext.php` imports lines 5-9 | 4 deps from v1's bridge/craft/kunstmaan namespace tree. | ported (retargeted) | All four imports retarget to v2 flat namespaces per D-41. MigrationStateReader specifically moves from `bridge/fields/` to `src/load/`. |
| `ResolverContext.php:21-40` — 7-arg readonly constructor | siteId, siteHandle, state, ck, paths, siteMap, legacyDb=null. | ported verbatim | Same file, same arg order, same readonly modifiers. |

**Counts:**

| Pair | ported | dropped intentionally | dropped accidentally | partially ported | new in v2 |
|---|---:|---:|---:|---:|---:|
| FieldHandler | 2 | 0 | 0 | 0 | 0 |
| FieldHandlerRegistry | 1 | 1 | 0 | 0 | 0 |
| DeferredAssetToken | 1 | 0 | 0 | 0 | 0 |
| ResolverContext | 2 | 0 | 0 | 0 | 0 |
| **Plan 03-01 totals** | **6** | **1** | **0** | **0** | **0** |

---

### Plan 03-02: Load VOs (MigrationStateReader / MigrationOptions / AssetPathResolver / TaxonomyResolvers)

**v1 files:** `~/Sites/craft-kunstmaan-migrator/src/{bridge/fields/MigrationStateReader.php, craft/load/{MigrationOptions, AssetPathResolver, TaxonomyResolver, BulkNameMatchTaxonomyResolver}.php}` (43 + 45 + 103 + 46 + 150 = 387 LOC)
**v2 files:** `src/load/{MigrationStateReader, MigrationOptions, AssetPathResolver, TaxonomyResolver, BulkNameMatchTaxonomyResolver}.php` (~28 + ~32 + ~95 + ~40 + ~145 = ~340 LOC)

| v1 Rule (file:line) | Description | Disposition | v2 location / rationale |
|---|---|---|---|
| `MigrationStateReader.php:18-43` — 3-method narrow read surface | getTargetId / getTargetUid / get | ported verbatim | Same file. |
| `bridge/fields/MigrationStateReader.php` location | v1 placed it next to handlers (sole consumers). | reshape: relocated to `src/load/` | Sole implementer is `MigrationStateService` in `src/load/`. Co-locating with the implementer keeps the firewall obvious. |
| `MigrationOptions.php:28-43` — 6-arg constructor | dryRun/force/verbosity/batchSize/legacyClassFilter/skipAssets | ported verbatim | Same file, same args, same defaults. |
| (no v1 rule) — `final` modifier | v1 omits. | added (v2 convention) | All v2 VOs are `final`. |
| `AssetPathResolver.php:36-72` — `resolveLocal` realpath-on-both-sides + prefix-match | T-04-11 path-traversal-safe local resolver. | ported verbatim | Same file. T-04-11 traceability comment added. |
| `AssetPathResolver` other static helpers | Various `kuma_` URL parsers. | ported verbatim | Same file. |
| `BulkNameMatchTaxonomyResolver.php:5` — `use ...MigrationConfigError;` | Typed error import. | dropped intentionally | Replaced with `\RuntimeException`. |
| `BulkNameMatchTaxonomyResolver.php:77-113` — fail-fast preflight (resolveAll) | 30-miss truncation + remediation hint. | ported verbatim (modulo throw replacement) | Same logic. |
| `BulkNameMatchTaxonomyResolver.php:123-149` — lazy-cache `Entry::find()...site('*')->unique()` | Multi-site dedupe; first-write-wins. | ported verbatim | Same file. |

**Counts:**

| Pair | ported | dropped intentionally | dropped accidentally | partially ported | new in v2 |
|---|---:|---:|---:|---:|---:|
| MigrationStateReader | 1 | 0 | 0 | 0 | 0 |
| MigrationOptions | 1 | 0 | 0 | 0 | 0 |
| AssetPathResolver | 2 | 0 | 0 | 0 | 0 |
| TaxonomyResolver | 1 | 0 | 0 | 0 | 0 |
| BulkNameMatchTaxonomyResolver | 2 | 1 | 0 | 0 | 0 |
| **Plan 03-02 totals** | **7** | **1** | **0** | **0** | **0** |

---

### Plan 03-03: MigrationStateService

**v1 file:** `~/Sites/craft-kunstmaan-migrator/src/bridge/load/MigrationStateService.php` (356 LOC)
**v2 file:** `src/load/MigrationStateService.php` (~340 LOC after namespace flatten + import retargeting + `declare(strict_types)` add)

| v1 Rule (file:line) | Description | Disposition | v2 location / rationale |
|---|---|---|---|
| Lines 28-29 — docblock note about kunstmaanSourceId custom-field replacement | v1 hedge that the state table might be replaced by per-element custom field. | dropped intentionally | Per CONTEXT D-48: v2 commits to state-table-only resume model. |
| Lines 48-67 — class header + statePrefix + table() + db() helpers | Schema-sync chokepoint. | ported verbatim (modulo namespace) | statePrefix invariant preserved. |
| Lines 74-356 — entire CRUD surface (record / get / has / forget / updateMeta / runOnce / getTargetId / getTargetUid) | All read/write operations. | ported verbatim | Same file. |
| Line 132-134 area — `$targetUidSafe = $targetUid ?? '';` null coercion | NOT NULL DEFAULT '0' compatibility. | ported verbatim | Load-bearing for Craft's uid() helper compatibility. |
| `implements MigrationStateReader` import | v1 imports from `bridge/fields/`. | retargeted | v2 lands MigrationStateReader at `src/load/MigrationStateReader.php`. |

**Install.php disposition:** `verify schema parity, no-op modification`. Phase 1 / FND-02 already shipped the byte-for-byte schema; Plan 03-03 reads/writes the existing table. No DDL changes.

**Counts:**

| Pair | ported | dropped intentionally | dropped accidentally | partially ported | new in v2 |
|---|---:|---:|---:|---:|---:|
| MigrationStateService | 4 | 1 | 0 | 0 | 0 |
| **Plan 03-03 totals** | **4** | **1** | **0** | **0** | **0** |

---

### Plan 03-04: ExtractService

**v1 file:** `~/Sites/craft-kunstmaan-migrator/src/kunstmaan/extract/ExtractService.php` (533 LOC)
**v2 file:** `src/extract/ExtractService.php` (~510 LOC after namespace flatten + filter-piping additions)

| v1 Rule (file:line) | Description | Disposition | v2 location / rationale |
|---|---|---|---|
| Imports — bridge/kunstmaan/craft namespace tree | 5 deps. | retargeted | LegacyDbService, DetailTableResolver, TopologicalOrderer, KunstmaanCoreTables retained; MigrationConfigError dropped. |
| Imports — `KunstmaanSerializedDecoder` | v1 chokepoint for serialized-blob safety. | **partially ported — Phase 4 follow-up** | Replaced with `?object = null` slot; null-check guards every call site. |
| MigrationConfigError throws | Typed config errors. | dropped intentionally | Replaced with `\RuntimeException`. |
| `run()` public signature — `(array $mapping, array $options = [])` | v1 had no Phase 2 filter spec. | **intentional reshape — Phase 2 D-10 filter piping per FILT-02** | v2 signature: `(array $mapping, MigrationFilters $filters, array $options = [])`. |
| **Line 433 — page-part ordering SQL** — `ORDER BY context, sequencenumber` | v1's authoritative ordering for joining kuma_page_part_refs to per-class detail tables. | **ported verbatim** — D-46 wins over CONTEXT D-49 | **CONTEXT.md D-49 drift recorded.** See DRIFT NOTE at top of file. |
| Streaming/Generator pattern — `iterable` returns | v1 streams to avoid loading all rows in memory. | ported verbatim | Phase 3 in-process pipeline (CONTEXT D-48) consumes the stream directly. |

**Counts:**

| Pair | ported | dropped intentionally | dropped accidentally | partially ported | new in v2 |
|---|---:|---:|---:|---:|---:|
| ExtractService | 4 | 1 | 0 | 1 | 0 |
| **Plan 03-04 totals** | **4** | **1** | **0** | **1** | **0** |

---

### Plan 03-05: AssetMigrationService

**v1 file:** `~/Sites/craft-kunstmaan-migrator/src/bridge/load/AssetMigrationService.php` (617 LOC)
**v2 file:** `src/load/AssetMigrationService.php` (~530 LOC after reshape)

| v1 Rule (file:line) | Description | Disposition | v2 location / rationale |
|---|---|---|---|
| `resolveFromLegacyId(int): int` body | JIT single-asset materialiser. | ported verbatim | Same file. JIT default path (FH-03). |
| `ingestReferenced(...)` body | Batch pre-walk of referenced kuma_media ids. | **repurposed — v1 batch-by-default → v2 opt-in via --preload-assets per FH-03** | Method body preserved verbatim modulo MigrationFilters threading. |
| State-row write contract | source='media', sourceKey='kuma_media:{id}', targetType='asset'\|'video', meta={originalUrl/location/contentType/videoId} | ported verbatim | Same call signature on `MigrationStateService::record()`. |
| Idempotent skip (line ~36) | `has(...) && !$opts->force → continue` | ported verbatim | ETL-05 idempotency. |
| `use AssetScanService` | v1 pre-scanned legacy media before entries loop. | dropped intentionally | v2 page-driven JIT (FH-03). Orphan media deliberately not migrated (NEXT-05 deferred). |
| `use AssetBatchJob` | v1 queued asset ingests for parallel processing. | dropped intentionally | Queue dropped from v2 / v1.0. |
| `use KunstmaanSerializedDecoder` | v1 chokepoint for serialized blobs. | partially ported — Phase 4 follow-up | `?object = null` slot. |
| `use MigrationReport` | v1's per-run counts/warnings VO. | partially ported — Plan 03-12 reinstates VO | Plan 03-05 used `Craft::warning(...)` + local `$counts` markers; Plan 03-12 introduced the VO. |
| MigrationConfigError throws | Typed errors. | dropped intentionally | Replaced with `\RuntimeException`. |
| `run()` / `ingestReferenced()` signatures | v1 had no MigrationFilters. | intentional reshape — Phase 2 D-10 piping per FILT-02 | Added `MigrationFilters $filters` to ingestReferenced. |

**Counts:**

| Pair | ported | dropped intentionally | dropped accidentally | partially ported | new in v2 |
|---|---:|---:|---:|---:|---:|
| AssetMigrationService | 4 | 3 | 0 | 2 | 0 |
| **Plan 03-05 totals** | **4** | **3** | **0** | **2** | **0** |

---

### Plan 03-06: CkeditorRewriterService + FinalizeWalker

**v1 file:** `~/Sites/craft-kunstmaan-migrator/src/bridge/ckeditor/CkeditorRewriterService.php` (529 LOC)
**v2 file:** `src/finalize/CkeditorRewriterService.php` (~510 LOC after namespace flatten + AssetResolver typing reshape)

| v1 Rule (file:line) | Description | Disposition | v2 location / rationale |
|---|---|---|---|
| Lines 50, 58 — `KUMA_MEDIA_PLACEHOLDER_REGEX` + `KUMA_NT_PLACEHOLDER_REGEX` | FIN-01 mandated `[`/`%5B` + `]`/`%5D` + `i` flag. | ported byte-for-byte | Same file. Load-bearing — any change breaks the URL-encoded path. |
| Lines 98-127 — 6-step `rewrite()` pipeline | Asset attrs / media / NT / entry links / strip kuma classes / remove empty paragraphs. | ported verbatim | Same file. |
| Lines 23-25 — `<!-- MIGRATION:UNRESOLVED -->` strict-policy marker | FIN-02 strict-policy annotation. | ported verbatim | Same file. |
| Lines 78-91 — three caches (`$urlIdCache`, `$kumaMediaIdCache`, `$ntToEntryCache`) + warm guards | Cache warming. | ported verbatim | Same file. |
| `use AssetResolver` (separate v1 class) | v1 had a dedicated AssetResolver. | reshape: typed `?object $assetResolver = null` slot | Advisor-locked decision: v2 folds asset resolution into AssetMigrationService. Plugin::init() Plan 03-13 wires `$this->ckeditorRewriterService->assetResolver = $this->assetMigrationService;`. |
| MigrationConfigError throws | If present. | dropped intentionally | `\RuntimeException`. |

**FinalizeWalker — greenfield:**

**v1 analog:** None at the orchestration layer. v1 did inline rewriting only via PlainTextHandler 'ckeditor' mode.
**v2 file:** `src/finalize/FinalizeWalker.php` (greenfield ~110 LOC)

| Rule | Description | Disposition |
|---|---|---|
| Greenfield orchestrator | Walks every Entry across every site, finds CKEditor fields, calls rewriter, saves with propagate=false. | new in v2 |

The walker design is deliberately conservative — `Entry::find()->siteId('*')->each(50)` for memory, propagate=false for multi-site discipline, idempotent re-run via diff guard.

**Counts:**

| Pair | ported | dropped intentionally | dropped accidentally | partially ported | new in v2 |
|---|---:|---:|---:|---:|---:|
| CkeditorRewriterService | 4 | 1 | 0 | 0 | 0 |
| FinalizeWalker | 0 | 0 | 0 | 0 | 1 |
| **Plan 03-06 totals** | **4** | **1** | **0** | **0** | **1** |

---

### Plan 03-07: AttachService + EntryMigrationService

**v1 files:** `~/Sites/craft-kunstmaan-migrator/src/{craft/services/AttachService.php (178 LOC), craft/load/EntryMigrationService.php (662 LOC)}`
**v2 files:** `src/load/{AttachService.php (~135 LOC), EntryMigrationService.php (~640 LOC)}`

| v1 Rule (file:line) | Description | Disposition | v2 location / rationale |
|---|---|---|---|
| AttachService lines 37-137 — `attachFieldToEntryType` body + fast-path check | Idempotent field attach + early return when field already in layout. | ported verbatim | Same file. |
| AttachService lines 50-62 — fast-path layout-walks | Walks `$layout->getTabs() → $tab->getElements()` looking for `getField()` matching `$fieldUid`. | ported verbatim | Load-bearing — preserves operator-observable Project-Config quietness. |
| AttachService lines 147-177 — `attachAllFromSettings` body | v1 reads `Settings::$entryTypeUids` and walks. | partially ported — Phase 4 follow-up | Method stub throws `\RuntimeException` with "deferred to Phase 4 / CFG-01" message. v2 Settings does not declare `$entryTypeUids` until CFG-01 introduces the CP form. |
| EntryMigrationService lines 53-57 docblock — propagateChanges=false discipline | Sole saveElement consumer; multi-site re-load before save. | ported verbatim | Plan 03-13 enforces "no other saveElement calls" via phase-level grep. (AssetMigrationService is a documented exception — see Open Issues.) |
| EntryMigrationService lines 95-102 — `saveEntryForSites` public signature | API surface. | ported verbatim | Same args. |
| `public array $sites = []` | v1 hardcoded `['nl' => 'default', 'en' => 'en']` in v1's Plugin.php:292. | reshape: empty default + Plugin::init() population from LocalePreflight + Settings::$localeMap | Plan 03-13 wires. v2 must throw if `$sites` is empty when `saveEntryForSites` is called — defensive guard added. |
| MigrationConfigError throws | Typed errors. | dropped intentionally | Replaced with `\RuntimeException`. |

**Counts:**

| Pair | ported | dropped intentionally | dropped accidentally | partially ported | new in v2 |
|---|---:|---:|---:|---:|---:|
| AttachService | 2 | 1 | 0 | 1 | 0 |
| EntryMigrationService | 2 | 1 | 0 | 0 | 0 |
| **Plan 03-07 totals** | **4** | **2** | **0** | **1** | **0** |

---

### Plan 03-08: PlainTextHandler + AssetHandler

**v1 files:** `~/Sites/craft-kunstmaan-migrator/src/bridge/fields/handlers/{PlainTextHandler.php (188 LOC), AssetHandler.php (95 LOC)}`
**v2 files:** `src/fields/handlers/{PlainTextHandler.php (~160 LOC after seomatic strip), AssetHandler.php (~85 LOC)}`

| v1 Rule (file:line) | Description | Disposition | v2 location / rationale |
|---|---|---|---|
| PlainTextHandler lines 64-73 — match dispatcher with 5 arms | plain / ckeditor / link / dropdown / seomatic | ported (4 arms preserved) — `'seomatic'` arm dropped intentionally | Phase 4 / ADP-01 owns SEOmatic. Plan 03-08 ships 4 modes; Phase 4 reinstates the 5th. |
| PlainTextHandler lines 140-152 — `writeSeomatic()` method | SEOmatic payload writer. | dropped intentionally | Same Phase 4 deferral. v2 SeomaticPayloadBuilder constructor param + import also dropped. |
| PlainTextHandler lines 59-62 — `id()` method | `$mode === 'plain' ? 'plain' : $mode` — 4 distinct registry ids. | ported verbatim | Plugin::init() (Plan 03-13) registers 4 instances. |
| PlainTextHandler lines 109-134 — `writeLink` classify pattern | Page-internal-link resolver via `state->getTargetId('page', ...)`. | ported verbatim | Same file. |
| PlainTextHandler `writeCkeditor` body | Inline `$ctx->ck->rewrite(...)` call. | ported verbatim | FH-04 inline-rewrite path. |
| AssetHandler lines 47-94 — `resolve()` body (state-lookup → JIT-fallback → deferred-token) | Dual-token emission for two consumer paths. | ported byte-for-byte | FH-04 contract — finalize-pass + load-pass each consume their own token format. |
| AssetHandler lines 73-80 — `[M{$legacyValue}]` vs `DeferredAssetToken::emit()` branch | Two formats for two consumers. | ported byte-for-byte | Load-bearing for FH-04. |
| AssetHandler lines 66-71 — JIT lazy-resolve via `$this->assetResolver->resolveFromLegacyId()` | FH-03 JIT default path. | ported verbatim | Plan 03-13 wires assetResolver to AssetMigrationService. |
| `use AssetResolver` (v1 separate class) | v1 had dedicated AssetResolver. | reshape: typed `?object $assetResolver = null` | Advisor-locked — v2 folds into AssetMigrationService. |
| MigrationConfigError throws | If present. | dropped intentionally | `\RuntimeException` replaces. |

**Counts:**

| Pair | ported | dropped intentionally | dropped accidentally | partially ported | new in v2 |
|---|---:|---:|---:|---:|---:|
| PlainTextHandler | 4 | 3 | 0 | 0 | 0 |
| AssetHandler | 3 | 1 | 0 | 0 | 0 |
| **Plan 03-08 totals** | **7** | **4** | **0** | **0** | **0** |

---

### Plan 03-09: RelationHandler + MatrixHandler

**v1 files:** `~/Sites/craft-kunstmaan-migrator/src/bridge/fields/handlers/{RelationHandler.php (312 LOC), MatrixHandler.php (112 LOC)}`
**v2 files:** `src/fields/handlers/{RelationHandler.php (~295 LOC), MatrixHandler.php (~140 LOC after D-49 enhancement)}`

| v1 Rule (file:line) | Description | Disposition | v2 location / rationale |
|---|---|---|---|
| RelationHandler lines 63-83 — 3-dispatch resolve() | stateSource validate + joinTable/joinTranslation/direct dispatch. | ported verbatim | Same file. |
| RelationHandler lines 49-54 docblock — T-06-02-01 mitigation | Identifier whitelist regex + bound parameters + LIMIT int cast. | ported verbatim | Load-bearing safety. |
| RelationHandler private resolve* method bodies | The 3 dispatch implementations. | ported verbatim | Same file. |
| MatrixHandler lines 47-110 — generic Matrix resolve | itemTable/fkCol/blockType + streamQuery + new1/new2 keys. | ported verbatim — relocated to `resolveGenericMatrix` private method | Single class, options-shape dispatch. PATTERNS §20 advisor-locked option (a). |
| MatrixHandler line 103 — `'new' . $n` block-array key | Craft 5 setFieldValue semantics for new blocks. | ported verbatim | Both paths. |
| MatrixHandler — D-49 v2-only — `resolvePagePartMatrix` private method | Page-part Matrix path keyed on (pagePartClass, parentPageClass, context). | new in v2 (greenfield — D-49) | Mapping-driven runtime contract. TransformService pre-resolves field hashes; MatrixHandler wraps with new-block keys. |
| MatrixHandler `resolve()` public dispatch | v1 had no dispatch (single body). | enhanced — D-49 dispatch added | Branches on `isset($options['pagePartClass'])` to select pagePart vs generic path. |
| MigrationConfigError throws | If present. | dropped intentionally | `\RuntimeException`. |

**Counts:**

| Pair | ported | dropped intentionally | dropped accidentally | partially ported | new in v2 |
|---|---:|---:|---:|---:|---:|
| RelationHandler | 3 | 1 | 0 | 0 | 0 |
| MatrixHandler | 3 | 1 | 0 | 0 | 1 |
| **Plan 03-09 totals** | **6** | **2** | **0** | **0** | **1** |

---

### Plan 03-10: SplitNameHandler

**v1 file:** `~/Sites/craft-kunstmaan-migrator/src/bridge/fields/handlers/SplitNameHandler.php` (176 LOC)
**v2 file:** `src/fields/handlers/SplitNameHandler.php` (~165 LOC after namespace flatten)

| v1 Rule (file:line) | Description | Disposition | v2 location / rationale |
|---|---|---|---|
| Lines 45-63 — three const token lists (PREFIX_TOKENS / INFIX_TOKENS / SUFFIX_TOKENS) | Dutch tussenvoegsel + academic title + generation-marker tokens. | ported byte-for-byte | Same file. CQM rehearsal correctness depends on the exact Dutch token contents. |
| Lines 72-89 — per-part dispatcher | `part` option selects firstName/infix/lastName/prefix/suffix. | ported verbatim | Same file. |
| Lines 96-160 — pure-function `split()` 5-part returner | Tokenization core. | ported verbatim | Same file. |
| Lines 152-157 — defensive infix→lastName fallback | Prevents empty lastName saves on names like "Jan van". | ported verbatim | Load-bearing. |
| (v2 convention) — `final` class modifier | v1 omits. | added | v2 convention; behavioral equivalence preserved. |
| MigrationConfigError throws | If present. | dropped intentionally | `\RuntimeException`. |

**Counts:**

| Pair | ported | dropped intentionally | dropped accidentally | partially ported | new in v2 |
|---|---:|---:|---:|---:|---:|
| SplitNameHandler | 4 | 1 | 0 | 0 | 0 |
| **Plan 03-10 totals** | **4** | **1** | **0** | **0** | **0** |

---

### Plan 03-11: TransformService

**v1 file:** `~/Sites/craft-kunstmaan-migrator/src/bridge/transform/TransformService.php` (940 LOC)
**v2 file:** `src/transform/TransformService.php` (~890 LOC after namespace flatten + D-48 reshape + filter piping)

| v1 Rule (file:line) | Description | Disposition | v2 location / rationale |
|---|---|---|---|
| Lines 42-51 — class header (5 ?Foo slots + storagePath) | Sibling-DI shape. | ported verbatim | Plugin::init() (Plan 03-13) wires all 5 slots per 75a95bc. |
| Lines 200+ — per-row handler-routing loop body | The bulk of the 940 LOC. | ported verbatim | Site-loop + ResolverContext construction + FieldSpec walk + pageParts pre-resolution. |
| Lines 26-27 docblock — named-arguments recommendation for ResolverContext | API doc. | ported verbatim | Same file. |
| `run(array $mapping, array $options)` v1 signature with disk-scan loop entry | Walked `extracted/<fqcn-slug>/<node-id>.json` artifacts. | **intentional reshape — D-48 single-process pipeline** | v2 signature: `run(iterable $extracted, array $mapping, MigrationFilters $filters, array $options = [])`. Disk walk replaced with `foreach ($extracted as $row)` consuming ExtractService stream directly. |
| (no v1 rule) — MigrationFilters | v1 had no Phase 2 filter spec. | **intentional reshape — Phase 2 D-10 filter piping per FILT-02** | Filters scope per-site loop + FQCN dispatch. |
| MigrationConfigError throws | Typed errors. | dropped intentionally | `\RuntimeException`. |

**Counts:**

| Pair | ported | dropped intentionally | dropped accidentally | partially ported | new in v2 |
|---|---:|---:|---:|---:|---:|
| TransformService | 3 | 1 | 0 | 0 | 0 (2 intentional reshapes — D-48 + filter piping) |
| **Plan 03-11 totals** | **3** | **1** | **0** | **0** | **0** |

---

### Plan 03-12: MigrationReport + AtomicMigrationService

**v1 file:** `~/Sites/craft-kunstmaan-migrator/src/bridge/load/AtomicMigrationService.php` (255 LOC); MigrationReport — small v1 VO if present, v2 builds independently.
**v2 files:** `src/load/{MigrationReport.php (~50 LOC greenfield-from-v1), AtomicMigrationService.php (~245 LOC)}`

| v1 Rule (file:line) | Description | Disposition | v2 location / rationale |
|---|---|---|---|
| MigrationReport `incr(string, int=1)` + `warn(string)` API | v1 surface. | ported (rebuilt as small VO) | v1 may have been more elaborate but Phase 3 only needs incr/warn/recordFailure. |
| MigrationReport `recordFailure(legacyId, slug, handler, Throwable)` | v2 D-50 failure schema. | new in v2 (D-50) | last-5-frames stack excerpt. |
| AtomicMigrationService lines 117-135 — Phase A file I/O before transaction | Asset materialisation outside tx. | ported verbatim | Same file. File copies aren't transactional — preserved discipline. |
| AtomicMigrationService lines 145-184 — Phase B Craft DB transaction wrapping saveEntryForSites + state record | Atomic-always-on. | ported verbatim (modulo SEO closure drop) | ETL-04 satisfied. |
| AtomicMigrationService lines ~150 — `seoMigrationService->migrateForEntry(...)` inside closure | SEOmatic per-entry write. | partially ported — Phase 4 / ADP-01 reinstates | Comment marker preserves the closure shape; Phase 4 reinstates the call inside the same closure (preserves atomicity). |
| AtomicMigrationService lines 209-212 — deferred-token resolver `/^asset:\d+$/` + capture form | FH-04 load-pass token resolution. | ported byte-for-byte | Tightly coupled to `DeferredAssetToken::emit()`. |
| AtomicMigrationService lines 107-116 — idempotency gate (`existingId !== null && !$overwrite`) | ETL-05 state-table-presence skip. | ported verbatim | Same gate. |
| `use AssetResolver` (separate v1 class) | v1 had dedicated AssetResolver. | dropped intentionally | Advisor-locked: AssetMigrationService implements the resolver surface directly. |
| `use MigrationReport` (v1 namespace) | Per-run counters. | retargeted to local namespace | Import dropped (same namespace as this file). |
| MigrationConfigError throws | Typed errors. | dropped intentionally | `\RuntimeException`. |

**Counts:**

| Pair | ported | dropped intentionally | dropped accidentally | partially ported | new in v2 |
|---|---:|---:|---:|---:|---:|
| MigrationReport | 1 | 0 | 0 | 0 | 1 |
| AtomicMigrationService | 4 | 2 | 0 | 1 | 0 |
| **Plan 03-12 totals** | **5** | **2** | **0** | **1** | **1** |

---

### Plan 03-13: Plugin wiring + MigrateController + Doctor 6th check

**v1 references:** `~/Sites/craft-kunstmaan-migrator/src/Plugin.php` (closure-DI at v1 lines 235-294); v1 had 20+ separate console commands (extract / transform / load / finalize / verify / etc.).
**v2 files:** `src/Plugin.php` (existing 165-line file extended to ~280 LOC); `src/console/MigrateController.php` (existing 54-LOC stub extended to ~400 LOC with 6 actions); `src/console/DoctorController.php` extended with 6th check.

| v1 Rule | Description | Disposition | v2 location / rationale |
|---|---|---|---|
| Plugin.php lines 235-294 — closure-DI via `$this->set('extractService', function() use ($self) { ... })` | v1 used closures to inject sibling deps. | **dropped intentionally — replaced with init() property-injection per 75a95bc** | Closures defeat the bare-class-strings-in-config() invariant that PluginBootstrapTest depends on. v2 init() does direct property assignment. |
| Plugin.php hardcoded sites map `['nl' => 'default', 'en' => 'en']` at v1 line 292 | v1 baked a CQM-specific locale map. | dropped intentionally — replaced with resolveSitesMap() helper composing LocalePreflight + Settings::$localeMap | Greenfield-friendly; no project-specific defaults. |
| MigrateController 20+ separate console commands | v1 surface area. | reshape — collapsed to 6 actions | PROJECT.md decision: ~5 commands in v2 (doctor / analyze / map / migrate / verify). Phase 3 ships migrate's 6 actions (index + 5 stage actions per ETL-02). |
| MigrateController per-stage commands | Resume support. | ported (reshape) — 5 sub-actions on single MigrateController | ETL-02 satisfied. |
| MigrateController per-entry progress lines | v1 emitted via Console::output. | ported verbatim shape — `[N/total] slug → verb` (FG_GREEN/FG_RED) | ETL-06 + Shared Pattern 4. |
| DoctorController 6th check `checkStateTable()` | greenfield in v2 — Phase 3 / CONTEXT Discretion | new in v2 | State-table reachability: `MigrationStateService::table()` SELECT-1 round-trip with WARN+remediation hint. |

**Counts:**

| Pair | ported | dropped intentionally | dropped accidentally | partially ported | new in v2 |
|---|---:|---:|---:|---:|---:|
| Plugin.php | 0 | 2 | 0 | 0 | 1 |
| MigrateController | 1 | 0 | 0 | 0 | 1 |
| DoctorController | 0 | 0 | 0 | 0 | 1 |
| **Plan 03-13 totals** | **1** | **2** | **0** | **0** | **3** |

---

## Summary

### Aggregate counts table (one row per (Plan, file) pair)

| Plan | File | ported | dropped intentionally | dropped accidentally | partially ported | new in v2 |
|---|---|---:|---:|---:|---:|---:|
| 03-01 | FieldHandler | 2 | 0 | 0 | 0 | 0 |
| 03-01 | FieldHandlerRegistry | 1 | 1 | 0 | 0 | 0 |
| 03-01 | DeferredAssetToken | 1 | 0 | 0 | 0 | 0 |
| 03-01 | ResolverContext | 2 | 0 | 0 | 0 | 0 |
| 03-02 | MigrationStateReader | 1 | 0 | 0 | 0 | 0 |
| 03-02 | MigrationOptions | 1 | 0 | 0 | 0 | 0 |
| 03-02 | AssetPathResolver | 2 | 0 | 0 | 0 | 0 |
| 03-02 | TaxonomyResolver | 1 | 0 | 0 | 0 | 0 |
| 03-02 | BulkNameMatchTaxonomyResolver | 2 | 1 | 0 | 0 | 0 |
| 03-03 | MigrationStateService | 4 | 1 | 0 | 0 | 0 |
| 03-04 | ExtractService | 4 | 1 | 0 | 1 | 0 |
| 03-05 | AssetMigrationService | 4 | 3 | 0 | 2 | 0 |
| 03-06 | CkeditorRewriterService | 4 | 1 | 0 | 0 | 0 |
| 03-06 | FinalizeWalker | 0 | 0 | 0 | 0 | 1 |
| 03-07 | AttachService | 2 | 1 | 0 | 1 | 0 |
| 03-07 | EntryMigrationService | 2 | 1 | 0 | 0 | 0 |
| 03-08 | PlainTextHandler | 4 | 3 | 0 | 0 | 0 |
| 03-08 | AssetHandler | 3 | 1 | 0 | 0 | 0 |
| 03-09 | RelationHandler | 3 | 1 | 0 | 0 | 0 |
| 03-09 | MatrixHandler | 3 | 1 | 0 | 0 | 1 |
| 03-10 | SplitNameHandler | 4 | 1 | 0 | 0 | 0 |
| 03-11 | TransformService | 3 | 1 | 0 | 0 | 0 |
| 03-12 | MigrationReport | 1 | 0 | 0 | 0 | 1 |
| 03-12 | AtomicMigrationService | 4 | 2 | 0 | 1 | 0 |
| 03-13 | Plugin.php | 0 | 2 | 0 | 0 | 1 |
| 03-13 | MigrateController | 1 | 0 | 0 | 0 | 1 |
| 03-13 | DoctorController | 0 | 0 | 0 | 0 | 1 |
| **Phase 3 totals** | — | **59** | **22** | **0** | **5** | **6** |

**Headline outcome:** **0 dropped accidentally.** Phase 3's verbatim discipline (D-46) succeeded — no v1 rules slipped through unintentionally. Every drop is documented with a rationale (intentional architectural choice or partial port deferred to a named Phase 4 plan).

The 5 partially-ported items are all explicit Phase 4 follow-ups; the 6 new-in-v2 items are documented architectural additions (FinalizeWalker orchestrator, MigrationReport::recordFailure D-50 schema, MatrixHandler D-49 page-part path, Plugin::init() 75a95bc property-injection, MigrateController 6-action shape, DoctorController 6th check).

---

## Phase 4 follow-up backlog

The 5 "partially ported" items, each pointing at the Phase 4 plan that reinstates them:

1. **KunstmaanSerializedDecoder** — both `ExtractService` (Plan 03-04) and `AssetMigrationService` (Plan 03-05) carry a `?object = null` slot with null-check guards at every call site. Phase 4 reinstates the typed property and removes the null-fallthrough; Phase 4 / ADP-01 ships the decoder alongside SeomaticPayloadBuilder, since both share this dependency.

2. **AttachService::attachAllFromSettings** — Plan 03-07's stub throws `\RuntimeException("deferred to Phase 4 / CFG-01")`. Phase 4 / CFG-01 reinstates the v1 body (`~/Sites/craft-kunstmaan-migrator/src/craft/services/AttachService.php` lines 147-177) once `Settings::$entryTypeUids` is declared by the CP form.

3. **AtomicMigrationService SEOmatic closure-call** — Plan 03-12 preserves the closure shape with a comment marker; Phase 4 / ADP-01 reinstates `$module->seoMigrationService->migrateForEntry(...)` inside the same transaction closure (preserves atomicity with the entry save).

4. **PlainTextHandler 'seomatic' mode** — Plan 03-08 dropped the 5th match arm + `writeSeomatic()` method + `SeomaticPayloadBuilder` constructor parameter. Phase 4 / ADP-01 reinstates all three at once.

5. **MigrationReport extension** — baseline VO landed in Plan 03-12 with `incr / warn / recordFailure`. Phase 4 may extend with additional fields if Phase 3 rehearsal surfaces gaps (e.g., per-stage timing, per-handler counts). Tracked but not committed.

**Carryover from Phase 02.1 (informational):**

- Source-scan caching across invocations (`source-scan.json` artifact + `--rescan-source` flag) — Phase 02.1 follow-up; profile-driven.

---

## Open issues (transparent record)

The following deviations from the Phase 3 plans were absorbed during execution and documented for the audit trail:

### 1. Wave 1a commit attribution leak (Plan 03-01 / 03-02)

Plan 03-01's executor used `git add .` and absorbed Plan 03-02 Task 1 files into commit `4808be9`. Disk state is correct (every file ended up where it should); the audit trail attribution is imprecise but recoverable from the per-plan SUMMARY.md files. Subsequent waves used file-specific `git add` exclusively. No code impact.

### 2. Plan 03-13 auto-fixes (Rule 3 — blocking-class)

Three blocking-class issues were auto-fixed during 03-13 execution per Deviation Rule 3:

- **TopologicalOrderer wired inline:** Plan 03-04's ExtractService consumes TopologicalOrderer; the dependency had to be wired in Plugin::init() during 03-13 to satisfy the smoke run.
- **AtomicMigrationService↔TransformService disk-bridge for actionLoad:** Standalone `actionLoad` re-runs extract+transform internally; a small disk bridge wires the iterable flow.
- **Composer require for craftcms/ckeditor:** required for the FH-04 inline rewrite path; added to composer.json with a chore commit (`00ba6d6`).

### 3. Plan 03-13 actionTruncate partial

Safety rails (D-51 wide+safety-rails per ETL-07: defaults to `--dry-run`, requires `--live --confirm`, honors `--entities` + `--locales` filters) and the asset-delete primitive ship now. Multi-source state-row deletion + entry deletion are deferred to Phase 4 once the Settings UID-list is available (CFG-01).

### 4. AssetMigrationService saveElement consumer (documented exception to Entry-monopoly invariant)

Plan 03-07 introduced the "no other saveElement calls outside EntryMigrationService" invariant via phase-level grep. AssetMigrationService is a documented exception: Asset elements are saved by AssetMigrationService by design (file-copy + `Asset` element creation). The smoke grep is updated to exclude AssetMigrationService.php from the Entry-monopoly check. Documented here so future audits do not flag it as a regression.

### 5. Lint diagnostics from verbatim ports (D-46 forbids touching)

The following unused-symbol diagnostics from verbatim ports remain by design (D-46 forbids opportunistic refactoring of ported code):

- `MatrixHandler.php:165` — unused `$ctx` parameter
- `AtomicMigrationService.php:223` — unused `$k` loop variable
- `AttachService.php` — unused `Install` import

These are carryovers from v1 source files; Phase 4 may revisit if the related code paths are touched for SEOmatic/CFG-01 reasons. Until then they stay verbatim.

The following 03-13-native unused symbols were cleaned in this plan's housekeeping pass (separate commit) — `Yaml` import + `$jsonPath` local in `MigrateController.php`. Both were 03-13 native (not v1 ports), so D-46 does not apply.

---

## Smoke results (rehearsal pair `~/Sites/cqm-craft-website`)

Recorded before this plan's RECONCILIATION/REQUIREMENTS/ROADMAP edits:

- **`doctor`** → PASS (6/6 OK: legacyDb, ANTHROPIC_API_KEY, storage/migration writable, mapping.yaml 278 rows, Kunstmaan source path, kunstmaanmigrator_state table reachable).
- **`migrate --entities=NewsPage --locales=nl` (dry-run)** → PASS. All 7 stages OK/WARN; "Migrate: PASS". Extract returned 0 nodes because mapping.yaml has no NewsPage rows in `accepted` status yet — content concern, not a code concern; Phase 5 rehearsal will validate real-world counts against the full mapping.
- **REPORT.md** → renders D-52 counts table + D-50 "_No per-entry failures._" section.
- **Invariant greps** — all green: saveElement Entry-monopoly clean (with documented AssetMigrationService exception), page-part ordering RECONCILIATION drift documented, no namespace leaks, no MigrationConfigError leaks, PHP syntax clean across all 27 new files.

---

## LOC delta summary

- **v1 originals:** ~3000 LOC (TransformService 940 + AssetMigrationService 617 + EntryMigrationService 662 + CkeditorRewriterService 529 + ExtractService 533 + MigrationStateService 356 + AtomicMigrationService 255 + 5 handlers ~880 + AttachService 178 + support VOs ~250).
- **v2 ported:** ~2900 LOC across the same units after intentional drops + reshape adds.
- **Delta justification:** D-48 (in-process pipeline collapses disk-walking entry/exit shells) + D-46 verbatim discipline (no opportunistic refactors) + intentional drops (AssetScanService, AssetBatchJob, MigrationConfigError, closure-DI, hardcoded sites map, 'seomatic' arm) — net savings absorbed by reshape adds (filter piping per FILT-02, Phase B SEOmatic closure stub, deferred-token paired-regex contract, 75a95bc init() property-injection, FinalizeWalker greenfield, MatrixHandler D-49 page-part path).

The narrowing tracks the load-bearing parity work. Remaining LOC delta is justified architectural deletion, not regression risk.

---

## Threat-model traceability

D-46 satisfied: every distinct v1 rule classified across all 13 plans, every accidental-drop count is **0** (no patches needed in this closing plan), every partially-ported item has an explicit Phase 4 reinstatement target, every "intentional reshape" carries a CONTEXT decision reference (D-48, D-49, D-50, D-51, D-52, FILT-02). T-03-14-rep (Repudiation: phase RECONCILIATION as audit trail) mitigated by committing this artifact.
