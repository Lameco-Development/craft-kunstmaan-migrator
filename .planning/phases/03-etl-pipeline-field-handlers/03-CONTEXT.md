# Phase 3: ETL Pipeline & Field Handlers - Context

**Gathered:** 2026-04-26
**Status:** Ready for planning

<domain>
## Phase Boundary

Phase 3 ships end-to-end migration of a Kunstmaan SQL dump's content into Craft, atomically per-entry, idempotent across re-runs, with all six field handlers in place and CKEditor body tokens resolved on the finalize pass. By the end of Phase 3:

1. `migrate --live` against the CQM rehearsal dump (~570 entries / 2,732 assets / 11 taxonomies) completes without error and produces Craft entries with counts within ±1% of the v1 baseline.
2. Re-runs skip already-migrated rows via the `kunstmaanmigrator_state` table — zero duplicates.
3. Forced mid-run failure leaves Craft consistent (the failing entry rolls back; earlier entries persist).
4. `migrate/finalize` resolves cross-entry `[NT<id>]` and media `[M<id>]` tokens (plus URL-encoded `%5BNT<id>%5D` / `%5BM<id>%5D`); unresolvables annotated with `<!-- MIGRATION:UNRESOLVED ... -->` and reported in REPORT.md.

This is the largest single phase in v1.0 — ETL pipeline + 6 handlers + state table + atomic + CKEditor finalize + truncate. Delivered as one mega-phase (D-45) so `migrate --live` works end-to-end at the end of phase, not in pieces. Estimated 12-15 plans across 4-5 waves.

**Out of scope for Phase 3** (deferred):
- SEOmatic + Retour adapters (Phase 4 / ADP-01..03).
- `verify capture-baseline` + `verify` parity gate (Phase 4 / VER-01..03). Phase 3 ships inline counts in REPORT.md (D-52) but no baseline comparison.
- CP Settings page (Phase 4 / CFG-01).
- Characterization tests + CI workflow (Phase 5 / TST-01..04).
- Per-class escape-hatch mapper system (`AbstractPagePartMapper` / `AbstractNodeClassMapper` / `DeclarativeMapperDriver`) — D-47, deferred until CQM rehearsal proves a gap mapping.yaml can't express.
- Source-scan caching across invocations (`source-scan.json` artifact + `--rescan-source`) — defer until profiling justifies (carryover from Phase 02.1 deferred list).
- Heuristic-2 name-match auto-promotion of page-part rows — page-part rows always start `needs-review` per Phase 02.1 D-35.
- Multi-list `--since` column candidates — Phase 2 D-11 default carries forward.
- Migrating orphan assets (NEXT-05).

</domain>

<decisions>
## Implementation Decisions

### Phase scope + delivery shape

- **D-45:** **Single Phase 3 (no 3a/3b/3c split).** 12-15 plans across multiple waves, all landing into one phase that closes when `migrate --live` runs end-to-end against the CQM rehearsal pair. Same wave-structured execution that worked for Phase 02.1's 9 plans. Pro: no half-states where the pipeline is unverifiable. Con: phase sits open longer; UAT comes only at the end. Acceptable trade — handlers can't be operator-verified in isolation anyway.

### Brownfield port discipline

- **D-46:** **Verbatim port + `RECONCILIATION.md` for every accidentally-dropped v1 rule** — same discipline as Phase 02.1 (D-37/D-38/D-39). Port byte-for-byte modulo namespace flatten (D-41 carryover) and dependency import retargeting. Functional changes (regex literals, SQL shapes, control flow, cache structures) are NEVER touched without an explicit reshape decision in the plan. The ~3000 LOC of v1 ETL services target this discipline:
  - `src/bridge/transform/TransformService.php` (940 LOC)
  - `src/bridge/load/AtomicMigrationService.php` (255 LOC)
  - `src/bridge/load/AssetMigrationService.php` (617 LOC)
  - `src/bridge/load/MigrationStateService.php` (356 LOC)
  - `src/bridge/ckeditor/CkeditorRewriterService.php` (529 LOC)
  - `src/bridge/fields/handlers/{Asset,Matrix,PlainText,Relation,SplitName}Handler.php` (5 handlers)
  - `src/bridge/fields/{FieldHandler,FieldHandlerRegistry,DeferredAssetToken,ResolverContext,MigrationStateReader}.php`

  Each plan's RECONCILIATION section documents v1 rule → v2 disposition (`ported` / `dropped intentionally` / `dropped accidentally → patched`). Patches landed during Phase 3, not deferred. Trade-off: TransformService stays ~940 LOC in v2 — accepted because v1's logic is battle-tested against the CQM rehearsal corpus and refactor-during-port risks subtle behavior drift.

### Per-class escape-hatch mapper

- **D-47:** **Defer `AbstractPagePartMapper` / `AbstractNodeClassMapper` / `DeclarativeMapperDriver` until CQM rehearsal proves a gap.** Phase 3 ships YAML-driven only — every page-part transformation expressed via mapping.yaml's pagePart row (D-34 from Phase 02.1: pagePartClass + parentPageClass + context + targetEntryType + targetMatrixField + targetBlockType + fields[]). If CQM rehearsal surfaces a case where mapping.yaml can't express the transform, file as Phase 4/5 follow-up (or a Phase 5 / TST-04 finding). Pro: ships smaller, avoids speculative architecture. Con: late discovery hurts. Mitigation: Phase 02.1 already shipped `BlockAvailabilityValidator` so structural gaps surface at audit-time, not load-time.

### Stage state persistence + resume model

- **D-48:** **State-table-only + in-process pipeline.** `migrate` runs all 4 stages (extract → transform → load → finalize) in one process. Per-stage commands (`migrate/extract`, `migrate/transform`, `migrate/load`, `migrate/finalize`, `migrate/truncate` per ETL-02) exist for resume / debugging — `migrate/load` standalone re-runs extract+transform internally (lazy / streaming), skipping rows already in `kunstmaanmigrator_state` with `(source, sourceKey, siteId)` UNIQUE matched. No intermediate disk artifacts (no `extract.json` / `transform.json`). Matches v1.x model. Lighter, less IO, single concept.
  - **Trade-off acknowledged:** debuggability is slightly worse than disk-artifact ETLs (can't inspect `transform.json` to see what would have been written). Mitigated by per-entry progress lines (ETL-06) + REPORT.md inline summary (D-52).
  - **Resume semantics:** state table records `(legacyId → targetId)` only on successful entry creation. Failed entries don't get a state row (D-50 — failure path), so re-running picks them up automatically.

### Matrix handler runtime contract

- **D-49:** **Mapping-driven** — mapping.yaml is the runtime source of truth for "which page-part class → which Craft Matrix block-type". The transform stage walks DB page-part rows for each entry (joined via `kuma_node_versions` → `kuma_main_pageparts` → `kuma_page_part_refs`, ordered by `kuma_page_part_refs.weight`), looks up each row by `(pagePartClass, parentPageClass, context)` tuple in mapping.yaml, and emits a Craft Matrix block via the Matrix handler. `pageStructure.json` is consulted at audit-time only (Phase 02.1 / D-36 BlockAvailabilityValidator). Single source of truth at runtime; pageStructure stays a build-time artifact.
  - **Concrete data flow** for a `NewsPage` entry's `main` context:
    1. Extract loads kuma_news_page row + JOINs the ordered pagePart rows
    2. For each pagePart row, resolve pagePartClass via Doctrine single-table inheritance discriminator
    3. Look up mapping.yaml pagePart row keyed on `(pagePartClass, parentPageClass='App\Entity\Pages\NewsPage', context='main')`
    4. Matrix handler emits Craft Matrix block with `targetBlockType` + `fields[]`
    5. State table records the (legacyPagePartId → craftBlockId) link

### Per-entry failure handling

- **D-50:** **Continue + REPORT.md.** Per-entry failure during `migrate --live`: the entry's transaction rolls back (ETL-04 atomic), console emits `[N/total] slug → FAILED: reason`, the loop continues with the next entry. State table doesn't record failed entries (so re-runs pick them up automatically). End-of-run REPORT.md lists every failure with: legacy ID + slug + handler that threw + exception message + stack-trace excerpt (last 5 frames).
  - **No `--max-failures` threshold flag in v1.0** — operator can ctrl-C if the run is clearly off the rails. Adding the flag later is cheap if rehearsal proves it useful.
  - **Re-run discipline:** `migrate --live` re-run after a failed run picks up where it left off (state-table skip), THEN retries the failed entries because they're absent from the state table. No retry counter needed.

### `migrate/truncate` scope

- **D-51:** **Wide + safety rails.** `migrate/truncate` (a) drops `kunstmaanmigrator_state` rows AND (b) deletes Craft entries with `kunstmaanSourceId` set AND (c) removes assets pulled in by this plugin (tracked via state-table `targetType='craft\elements\Asset'`). Defaults to `--dry-run` (prints what would be deleted, deletes nothing); requires `--live --confirm` to actually delete. Honors `--entities` and `--locales` filters (no "nuke everything ever migrated by this plugin" footgun). NeverProductionTrait still hard-gates production regardless of flags.
  - **Use case:** between rehearsal runs, operator runs `./craft kunstmaan-migrator/migrate/truncate --live --confirm --entities=NewsPage` to wipe just NewsPage's migrated state (entries + assets + state rows) for a focused re-test.

### Inline verify-counts in `migrate --live`

- **D-52:** **Inline summary in REPORT.md.** Phase 3's REPORT.md includes per-entity counts (created / updated / skipped / failed) at the end of every `migrate --live` run. NO baseline comparison — Phase 4's `verify capture-baseline` + `verify` owns the ±1% gate. Phase 3 inline numbers exist so an operator can eyeball obvious regressions before bothering with the full verify cycle. Format mirrors v1.x's REPORT.md:

  ```
  ## Migration counts

  | Entity | Created | Updated | Skipped | Failed |
  |--------|--------:|--------:|--------:|-------:|
  | NewsPage      | 547 |  0 | 23 | 0 |
  | CaseStudyPage | 142 |  0 |  0 | 1 |
  ...
  ```

### Claude's Discretion

- **Module/class boundaries within `src/transform/`, `src/load/`, `src/finalize/`** — verbatim port discipline (D-46) anchors the file-level decomposition; researcher/planner decide src/* slot per file (likely `src/transform/`, `src/load/`, `src/finalize/`, `src/fields/handlers/` mirroring v1's `src/bridge/transform/`, `src/bridge/load/`, `src/bridge/ckeditor/`, `src/bridge/fields/handlers/`).
- **Plugin::config() growth** — Phase 3 adds ~10-15 components (handlers + services). Researcher proposes the registration order; planner sequences component registration plans the way Phase 02.1's Plan 05 did (one plan = "register all the new components + DI wiring").
- **Dependency wiring pattern** — Phase 02.1 surfaced the bug where `public ?Foo $dep = null` properties stayed null because `Plugin::config()` registered class names without DI. Plugin::init() now wires sibling components (commit 75a95bc). Phase 3's plans MUST follow the same pattern: every new service that depends on another sibling component gets wired in `Plugin::init()`. Researcher to confirm the pattern is documented in PATTERNS.md before planning starts.
- **REPORT.md structural shape** — Phase 2 already ships REPORT.md (locales + tables block); Phase 3 adds the migration-counts block (D-52) + failures block (D-50). Researcher proposes the placement; planner specifies.
- **Error message format for failure REPORT.md entries** — D-50 specifies content (legacy ID + slug + handler + exception + stack excerpt). Exact rendering is researcher/planner's call.
- **Page-part DB row ordering source** — D-49 says "ordered by `kuma_page_part_refs.weight`". Researcher to confirm against v1 (specifically `TransformService` page-parts iteration) and the actual CQM schema.
- **Doctor extension** — Phase 02.1 / D-31 added the 5th check (Kunstmaan source path). Phase 3 may add a 6th check (e.g., `kunstmaanmigrator_state` table exists + writable). Researcher to decide based on Phase 1 / D-19 doctor pattern.

</decisions>

<specifics>
## Specific Ideas

- v1.x is the canonical reference for every behavior in Phase 3. The v1 corpus at `~/Sites/craft-kunstmaan-migrator/src/{bridge,kunstmaan,craft}/` is read-only and authoritative for what to port. Verbatim discipline applies — when in doubt, mirror v1.
- The CQM rehearsal corpus (`~/Sites/cqm-website` source + `~/Sites/cqm-craft-website` Craft target + the Kunstmaan SQL dump) is the production-shaped test bed. Phase 3 UAT against this pair is the closing gate — `migrate --live --entities=NewsPage` must produce 547 NewsPage entries (or whatever the baseline records) within ±1% before the phase closes.
- Phase 02.1 commit 75a95bc unblocked the source/page-structure wiring; running `./craft kunstmaan-migrator/analyze` against the CQM rehearsal pair now produces 442 mapping.yaml proposals (143 accepted + 167 dropped + 410 needs-review at the time of measurement) + the 25KB `pageStructure.json`. Phase 3 starts from that working analyze output, not from scratch.
- The mega-phase shape (D-45) means Phase 3 will likely need an EXECUTION-CHECKPOINT artifact halfway through (e.g., after handlers ship but before CKEditor finalize) so the operator can do a partial-rehearsal smoke. Researcher/planner to scope.

</specifics>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### v1 brownfield port targets (verbatim discipline — D-46)
- `~/Sites/craft-kunstmaan-migrator/src/bridge/transform/TransformService.php` — 940 LOC. Per-entry transform pipeline; routes columns through field handlers; emits Craft entry payload.
- `~/Sites/craft-kunstmaan-migrator/src/bridge/load/AtomicMigrationService.php` — 255 LOC. Per-entry transactional rollback; ETL-04 atomic-always-on.
- `~/Sites/craft-kunstmaan-migrator/src/bridge/load/AssetMigrationService.php` — 617 LOC. Asset ingest (JIT default + `--preload-assets`); FH-03.
- `~/Sites/craft-kunstmaan-migrator/src/bridge/load/MigrationStateService.php` — 356 LOC. State table reads/writes; ETL-05 idempotency.
- `~/Sites/craft-kunstmaan-migrator/src/bridge/ckeditor/CkeditorRewriterService.php` — 529 LOC. `[NT<id>]` / `[M<id>]` token emission + finalize-pass resolution; FH-04 + FIN-01/02.
- `~/Sites/craft-kunstmaan-migrator/src/bridge/fields/handlers/AssetHandler.php` — Asset field handler.
- `~/Sites/craft-kunstmaan-migrator/src/bridge/fields/handlers/MatrixHandler.php` — Matrix field handler (D-49 mapping-driven contract).
- `~/Sites/craft-kunstmaan-migrator/src/bridge/fields/handlers/PlainTextHandler.php` — PlainText field handler.
- `~/Sites/craft-kunstmaan-migrator/src/bridge/fields/handlers/RelationHandler.php` — Relation field handler.
- `~/Sites/craft-kunstmaan-migrator/src/bridge/fields/handlers/SplitNameHandler.php` — SplitName field handler (Dutch composite-name splitter, including tussenvoegsel handling).
- `~/Sites/craft-kunstmaan-migrator/src/bridge/fields/FieldHandler.php` — Handler interface (FH-01).
- `~/Sites/craft-kunstmaan-migrator/src/bridge/fields/FieldHandlerRegistry.php` — Registry (FH-02).
- `~/Sites/craft-kunstmaan-migrator/src/bridge/fields/DeferredAssetToken.php` — Token VO.
- `~/Sites/craft-kunstmaan-migrator/src/bridge/fields/ResolverContext.php` — Per-row resolver context.
- `~/Sites/craft-kunstmaan-migrator/src/bridge/fields/MigrationStateReader.php` — State-table read shim.
- `~/Sites/craft-kunstmaan-migrator/src/kunstmaan/extract/ExtractService.php` — Extract stage (DB → in-memory rows).
- `~/Sites/craft-kunstmaan-migrator/src/craft/load/EntryMigrationService.php` — Entry-side load orchestration.
- `~/Sites/craft-kunstmaan-migrator/src/craft/load/{TaxonomyResolver,BulkNameMatchTaxonomyResolver,AssetPathResolver,MigrationOptions}.php` — Load helpers.
- `~/Sites/craft-kunstmaan-migrator/src/craft/migrations/Install.php` — `kunstmaanmigrator_state` schema (verbatim per PROJECT.md decision).
- `~/Sites/craft-kunstmaan-migrator/src/craft/services/AttachService.php` — Asset-to-entry attach helper.

### Project-level decisions
- `.planning/PROJECT.md` §"Key Decisions" — Atomic-always-on, JIT assets default, `kunstmaanSourceId` Plain Text, state schema verbatim, page-driven migration, no queue, no CP runner, no multi-provider AI, NeverProductionTrait hard-gate.
- `.planning/PROJECT.md` §"What works in v1 — port without redesign" — load-bearing list of v1 behaviors that must port.
- `.planning/PROJECT.md` §"Migration model" — page-driven, entries-as-unit-of-work, lazy asset/relation pull-in.

### Requirements
- `.planning/REQUIREMENTS.md` §"ETL pipeline (ETL)" — ETL-01..07 (the migrate command surface, atomic, idempotent, topological, per-entry progress, truncate).
- `.planning/REQUIREMENTS.md` §"Field handlers (FH)" — FH-01..04 (interface + 6 handlers, registry, asset JIT/preload, CKEditor deferred tokens).
- `.planning/REQUIREMENTS.md` §"Finalize + CKEditor rewriting (FIN)" — FIN-01..02 (finalize walks every CKEditor field; unresolvable token annotation policy).
- `.planning/REQUIREMENTS.md` §"Source introspection (SRC)" — SRC-01..19 (Phase 02.1 — pageStructure.json + mapping.yaml pagePart rows are Phase 3 inputs).
- `.planning/ROADMAP.md` §"Phase 3: ETL Pipeline & Field Handlers" — 4 success criteria.

### Prior phase decisions (load-bearing)
- `.planning/phases/01-foundation-connectivity/01-CONTEXT.md` — Phase 1 / D-12 env-then-Settings `??=` ladder pattern; Phase 1 / D-19 doctor plain-text OK/FAIL; Phase 1 / D-20 gate-first idiom; Phase 1 / D-21 PluginBootstrapTest invariant.
- `.planning/phases/02-schema-mapping-filters/02-CONTEXT.md` — Phase 2 / D-04 mapping.yaml merge identity tuple; Phase 2 / D-07 atomic write (`MappingFile::writeAtomic` / `writeAtomicJson`); Phase 2 / D-10 Settings+CLI filter merge; Phase 2 / D-12 three filter flags only (entities, locales, since).
- `.planning/phases/02.1-source-introspection/02.1-CONTEXT.md` — Phase 02.1 / D-30..D-44 (source path resolver, mapping.yaml row kinds, pagePart structural-only merge tuple, KunstmaanSourceScanner output shape, BlockAvailabilityValidator, RECONCILIATION.md discipline).
- `.planning/phases/02.1-source-introspection/RECONCILIATION.md` — Phase 02.1 reconciliation pattern (4 ported / 23 dropped intentionally / 2 dropped accidentally → patched). Template for Phase 3's per-plan reconciliation sections.
- `.planning/phases/02.1-source-introspection/02.1-PATTERNS.md` — Patterns surfaced during Phase 02.1 (verbatim port discipline, transitive-walk port rule, test-mocking-driven inline-chain reshape, out-parameter pattern). All four apply in Phase 3.

### v2 codebase priors that Phase 3 builds on
- `src/source/TopologicalOrderer.php` — ported in Phase 02.1 / Plan 03; Phase 3 consumes for ETL-03 ordering.
- `src/source/BodyScanColumnFinder.php` + `src/source/MediaFkScanner.php` — ported in Phase 02.1 / Plan 03; Phase 3's transform consumes (CKEditor body-token rewrite + asset migration).
- `src/source/KunstmaanSourceScanner.php` — Phase 02.1 / Plan 05 orchestrator; transform stage reads its `entities` / `m2mJoins` / `bodyCols` / `mediaFks` outputs.
- `src/source/KunstmaanPageStructureScanner.php` — Phase 02.1 / Plan 04; consumed at audit-time only per D-49.
- `src/mapping/MappingFile.php` — Phase 02.1 extended with `kind: pagePart` rows + structural-only merge tuple. Transform consumes via `loadAccepted()` (Phase 3 may add a `loadAccepted(MigrationFilters $filters)` filter-aware variant).
- `src/Plugin.php` — Phase 02.1 / commit 75a95bc wires sibling component dependencies in `init()`. Phase 3 plans MUST follow the same pattern (see Claude's Discretion above).
- `src/console/AnalyzeController.php`, `src/console/MapController.php`, `src/console/DoctorController.php` — Phase 02.1 console controller pattern (single `actionIndex` with N steps + per-step OK/WARN/FAIL emits). Phase 3's `MigrateController` follows the same shape, with sub-actions for `extract`/`transform`/`load`/`finalize`/`truncate`.
- `src/NeverProductionTrait.php` — Phase 1 hard-gate. Every Phase 3 controller action that writes to Craft or reads legacy DB MUST `use` this trait.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- **TopologicalOrderer** (Phase 02.1) — ETL-03 ordering directly consumes its `order(graph, &$warnings)` API.
- **MappingFile.loadAccepted()** (Phase 2) — Returns the operator-blessed mapping rows. Transform's main input.
- **MigrationFilters** (Phase 2) — `entities`/`locales`/`since` already piped through every Phase 2 stage. Phase 3 inherits the same model and pipes it through extract/transform/load/finalize/truncate.
- **LegacyDbService** (Phase 1) — Plugin-owned legacy DB connection. Extract stage's only entry to the source DB.
- **MappingFile.writeAtomic + writeAtomicJson** (Phase 2 / D-07) — REPORT.md / `kunstmaanmigrator_state` audit artifact writes go through these.
- **KunstmaanSourceScanner.scan()** (Phase 02.1) — `m2mJoins` + `bodyCols` + `mediaFks` are direct transform-stage inputs.
- **KnowledgeBase** (Phase 02.1) — Not consumed in Phase 3 (LLM is `analyze`-only per the runtime-zero-AI rule). Listed here only to make explicit it's NOT a Phase 3 dependency.

### Established Patterns
- **Verbatim port + RECONCILIATION.md** (Phase 02.1) — D-46 carries this forward as the dominant Phase 3 pattern.
- **Plugin::init() sibling-component DI wiring** (Phase 02.1 fix commit 75a95bc) — every Phase 3 service that depends on another sibling component MUST be wired here, not left as a `public ?Foo $dep = null` default.
- **Console controller as single `actionIndex` with N steps** (Phase 02.1 / D-42 11-step AnalyzeController) — Phase 3's `MigrateController::actionIndex` follows the same shape; per-stage actions (`actionExtract`, `actionTransform`, `actionLoad`, `actionFinalize`, `actionTruncate`) call into the same internals.
- **Doctor OK/WARN/FAIL plain-text output** (Phase 1 / D-19) — Phase 3 progress lines (ETL-06 `[N/total] slug → created|updated|skipped|FAILED: reason`) follow the same plain-text discipline.
- **MigrationFilters merge** (Phase 2 / D-10) — Phase 3's CLI flags merge with Settings::default* the same way Phase 2's `analyze` and `map` did.

### Integration Points
- **`src/Plugin.php` Plugin::config() components map** — grows ~10-15 entries for Phase 3 (handlers + services).
- **`src/Plugin.php` Plugin::init()** — grows the sibling-DI wiring block (D-46 / 75a95bc pattern).
- **`src/console/MigrateController.php`** — new controller; mirrors AnalyzeController / MapController shape. 5 actions: `actionIndex` (default = full pipeline), `actionExtract`, `actionTransform`, `actionLoad`, `actionFinalize`, `actionTruncate`.
- **`src/migrations/Install.php`** — Phase 1 already ships skeleton; Phase 3 adds the `kunstmaanmigrator_state` table creation (verbatim from v1's `Install.php` per PROJECT.md "schema verbatim" decision). Doctor's check (per Claude's Discretion) verifies the table exists.
- **`src/models/Settings.php`** — Phase 1 / D-15 declared all forward-looking settings upfront. Phase 3 may add `dryRunDefault`-related settings (CFG-01 owns the actual CP form in Phase 4; Phase 3 just adds the property if needed).

</code_context>

<deferred>
## Deferred Ideas

- **Per-class escape-hatch mapper system** (`AbstractPagePartMapper` / `AbstractNodeClassMapper` / `DeclarativeMapperDriver`) — D-47 deferred until CQM rehearsal proves a mapping.yaml-can't-express gap. Most likely Phase 4 or Phase 5 if needed.
- **`--max-failures=N` threshold flag** — D-50 ships continue-always; flag added later if rehearsal proves operators want stricter mode.
- **Disk artifacts between stages** (`extract.json` / `transform.json`) — D-48 deferred unless single-process pipeline proves limiting (e.g., extract is too slow to re-run for `migrate/transform` standalone).
- **Source-scan caching across invocations** (`source-scan.json`, `--rescan-source`) — Phase 02.1 deferred carryover. Profile-driven.
- **Heuristic-2 name-match auto-promotion of page-part rows** — Phase 02.1 D-35 invariant: page-part rows always start `needs-review`. Future work if operator UX surfaces a need.
- **Async / queue execution** — v1 had queue jobs; v2 dropped queue for v1.0. If a future phase introduces async, doctor's queue-worker check (deferred per PROJECT.md) returns.
- **CP read-only status mirror** — PROJECT.md notes "may land later if it pulls its weight". Out of scope for Phase 3.
- **Multi-provider AI** — PROJECT.md out-of-scope. Anthropic-only stays.
- **Migrating orphan assets** — NEXT-05. Page-driven by design.

</deferred>

---

*Phase: 03-etl-pipeline-field-handlers*
*Context gathered: 2026-04-26*
