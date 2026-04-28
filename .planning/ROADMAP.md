# Roadmap

9 phases. Coarse granularity. Built greenfield against the v1.x plugin as
brownfield reference. Every v1 requirement maps to exactly one phase. v2
requirements (`NEXT-*`) are deferred to a follow-up milestone.

## Milestone: v1.0

| # | Phase | Goal | Requirements | Success Criteria | UI hint |
|---|-------|------|--------------|------------------|---------|
| 1 | Foundation & Connectivity | A scaffolded Craft 5 plugin with internal legacy-DB connectivity, the `kunstmaanmigrator_state` table, the `kunstmaanSourceId` field, the `NeverProductionTrait`, the `doctor` command, the `migrate/install` shim, and a green PHPUnit suite. | FND-01..05, FND-02a, CONN-01..03 | 5 | no |
| 2 | Schema, Mapping & Filters | `analyze` produces a schema dump + heuristic-and-LLM proposals into a single `mapping.yaml`; the `map` rubber-stamp loop walks proposals; coverage gate hard-blocks `--live`; mapping-audit detects drift; locale auto-detect + preflight; `MigrationFilters` plumbed through every stage. | MAP-01..07, FILT-01..03, LOC-01..02 | 5 | no |
| 02.1 | Kunstmaan Source Introspection | Read the Kunstmaan source codebase (project Entity classes + vendor Kunstmaan bundles + `config/kunstmaancms/pageparts/*.yml`) as the source of truth for content schema. Discovers project-prefixed tables, M2M join tables, polymorphic page-part class identity, page → context → allowed-page-part-classes structure, AND ports v1's DoctrineEntityParser + DetailTableResolver + KnowledgeBase Markdown + BodyScanColumnFinder + MediaFkScanner + TopologicalOrderer + BlockAvailabilityValidator (don't rewrite — port wholesale). Reconciles Plan 02-05's CoverageAuditor + MappingAuditor against v1's originals to catch any rules dropped in the rewrite. | SRC-01..17 (TBD) | 17 | no |
| 3 | ETL Pipeline & Field Handlers | Extract → Transform → Load → Finalize stages with topological ordering, per-entry atomic load, idempotent re-runs, JIT assets (with `--preload-assets`), the six built-in field handlers, and CKEditor token rewrite. | ETL-01..07, FH-01..04, FIN-01..02 | 4 | no |
| 4 | Adapters, Verify & Settings | Optional SEOmatic + Retour adapters (runtime-detected, not composer-required), `verify` parity gate (counts + optional URL spot-check) producing a timestamped report, CP Settings page, console verbosity, rehearsal report artifact. | ADP-01..03, VER-01..03, CFG-01..03 | 4 | yes |
| 5 | Tests, Rehearsal & Release | Transform-stage characterization fixtures from a real dump, full unit suite green, CI workflow running validate + PHPUnit + plugin-load smoke test, rehearsal pass against the CQM dump, release checklist + tag. | TST-01..04 | 4 | no |
| 8 | Taxonomies & AI Proposer Coverage | Port v1's `TaxonomyMigrationService` and add the missing `taxonomies:` mapping block (analyze proposer + compile + extract + load); extend the analyze AI proposer to cover `dataProviders` and page-builder layout (`headerBlock` / `bodyWrapBlock` / `bodyColumn`) so operator hand-authoring shrinks; document remaining out-of-scope Kunstmaan surfaces (Forms, Search, Menus, Users, asset folder hierarchy, slug-history) in CHANGELOG. Closes the "useful as a plugin" gap before v1.0 tag. | TAX-01..11, PROP-01..06, DOC-01..02 | TBD | no |
| 08.1 | Proposer Correctness & Defensive Compile/Migrate | Close three correctness gaps surfaced by the Phase 8 rehearsal: (D-05a) `proposeNonPageEntities` excludes PagePart classes — pageparts are global page-builder Matrix blocks, handled by step 7.6; (D-07a) `compileTaxonomies` defensively skips rows with empty `sourceTable` / `targetSection` / `targetEntryType`; (D-08a) `TaxonomyMigrationService::migrateAll` soft-skips incomplete hand-edited entries with WARN instead of throwing. | TAX-12..14 (codified inline in 08.1-CONTEXT.md) | 1 | no |
| 08.2 | Nested Matrix-of-Sub-Entry-Type Mapping | (D-15) LLM may now propose dotted-path target handles like `headerHome.heading`. CraftKnowledgeBase walks Matrix fields' inner entry-types and emits sub-field handles in both markdown + buildFieldIndex. TransformService collapses dotted-path entries into Craft 5 native Matrix-of-entries payloads. Generic — works for any Matrix-with-sub-entry-types field on any entry-type, not just HomePage's headerHome. | TAX-15..17 (codified inline in 08.2-CONTEXT.md) | 1 | no |
| 08.3 | Page-builder handle derivation | (D-16) Compile auto-resolves targetMatrixField from targetBlockType when uniquely-owned, and propagates to nodeClasses[fqcn].pageBuilderHandle from accepted page-part rows. Surfaces 'page-part Matrix-field auto-resolved: N' + 'pageBuilderHandle propagated to N nodeClasses' counters. Note: parent-blind block-type pick can route to wrong Matrix; 8.6 will tighten. | (none — internal compile fix) | 1 | no |
| 08.4 | Extract priming + page-part emit + taxonomy title heuristic | (D-17) Plugin::init() primes DoctrineEntityParser.sourceCheckoutPath so migrate/doctor get a warmable parser (not just analyze). Without it, DetailTableResolver fails for every PagePart class and ExtractService silently drops every kuma_page_part_refs row. (D-18) compileTaxonomies auto-defaults `name`/`title`/`label` columns on taxonomy tables to targetHandle:'title' so taxonomy entries get real titles instead of '[legacy id N]'. (D-19) New compileRegularPageParts pass folds accepted kind=pagePart proposals into mapping.pageParts[<fqcn>] (was missing — only the synthetic __implicit_content__ rows had a writer). | (internal fixes) | 1 | no |
| 08.5 | FK-relation introspection + extract joining | (D-20) Surface ManyToOne / ManyToMany relations on page entities + page-parts in KnowledgeBase markdown (target table + columns visible to LLM via the KB Relations table). (D-21) ExtractService joins ManyToOne FK targets and embeds their columns under `_rel:<property>.<column>` namespace — operator can map across the relation by setting `fields[].source: '_rel:employee.name'`. (D-22) System prompt notes the `_rel:` idiom (forward-looking — today's residual builder reads from information_schema, so direct LLM proposal of `_rel:*` keys waits on extract-derived residuals in a later phase). (D-23) Emit relation-graph.json artifact during analyze. (D-24) `--no-rel-join` flag + Settings::joinFkRelations toggle. Unblocks EmployeePage→Employee, CaseStudyPage→CaseStudyCategory, NewsPage→NewsAuthor and similar wrapping patterns that today only carry the FK column. | (codified in 08.5-CONTEXT.md) | 1 | no |
| 08.6 | Page-builder block completeness (homepage unblocker) | (D-25) Parent-aware Matrix selection — `CraftKnowledgeBase::matrixFieldsForEntryType` + LLM `proposePagePartBlocks` per-row `allowedMatrixFields=[…]` scoping + MappingCompiler intersection tie-break for shared block-types. Closes 8.3's parent-blind pick that was routing CQM HomePage's page-parts to `pageBuilderCondensed` instead of the actual `pageBuilder` Matrix the entry-type owns. (D-26+D-27) Per-pagepart column proposer (`proposePagePartFields`) + per-block-type sub-field catalog: each pagePart's source columns get their own LLM batch with the chosen block's allowed fields as the closed set. MappingCompiler.collapsePagePartFieldsList converts the residual list-of-dicts shape to the final assoc map. Plus two latent bugs surfaced by validation: --entities now actually scopes the analyze proposer steps (not just SchemaDumper) and the load stage (not just transform). Validated end-to-end against CQM HomePage: `pageBuilderHandle` correctly resolves to `pageBuilder`; all 5 page-parts route to specific blocks (casesCarouselBlock, newsGridBlock, callToActionBlock, iconListBlock, clientLogosBlock); `clientLogosBlock` resolves 15 client logos via the joinTable RelationHandler path; block titles auto-lift from legacy `title` columns. KNOWN GAP (D-28, deferred): LLM prompt's `allowedBlockFields=[handle:type]` is too thin — for Dropdown the options should be surfaced; for Matrix the allowed block-types; for Entries the target section; for Asset the kinds. Without this, the proposer makes plausible-name-matches that fail at handler-time (e.g. `title→titleLevel` is a heading-level dropdown, not a content title). Operator hand-curation works around it; D-28 closes it permanently. | (codified inline) | 1 | no |
| 08.7 | Page-rooted leaf-entity migration (embed vs promote) — first slice | (D-30) `AnalyzeController::buildPagePartFieldsContext` returns a third `pagePartRelations` map per FQCN containing OneToMany / ManyToMany Doctrine relations with target FQCN, child table name, back-ref FK column, and child columns (PK + back-ref filtered out so the LLM picks payload-only). (D-31) `LlmClassifier::proposePagePartFields` accepts the relations map; the prompt renders `relations=[<prop>:<type>(target/childTable/backRef/childCols)]` per row; the system-prompt teaches the LLM to emit `handler: relation` with `joinTable`/`joinLocalColumn`/`joinForeignColumn`/`stateSource` handlerOptions when the target field is `Entries(from: ...)` or `Assets(kinds: ...)` and the page-part has a relation whose child table contains an `_id` column matching the target shape. CRITICAL guardrail: `joinForeignColumn` must end with `_id` — `sanitiseHandlerOptions` drops the whole option set otherwise. `MappingCompiler::collapsePagePartFieldsList` preserves `handlerOptions` through the residual list-of-dicts → final assoc map collapse. Validated end-to-end against CQM HomePage: ClientsPagePart auto-proposes the exact joinTable config the operator hand-curated in 8.6, yielding 15 client logos resolved via the existing RelationHandler joinTable path. (D-29 deferred): true ManyToMany join-table auto-discovery without `#[JoinTable]` annotations — the OneToMany path covers most CQM-shaped projects (back-ref FK on the child); ManyToMany without explicit annotations still requires operator hand-curation. KNOWN GAP: full `mode: embed | promote` per-relation operator intent + topological leaf-migration ordering still future work. The current slice is "operator chooses by adding the relation handler line; LLM auto-proposes when it can"; full embed/promote with leaf entry-type creation will land when operator workflows demand it. | (codified inline) | 1 | no |
| 9 | Migration Workflow Hardening & Page-rooted Introspection Audit | Close the release-blocking audit gaps before v1.0: make the canonical workflow impossible to run as a successful no-op, preserve compiled mapping blocks across analyze reruns, make scoped runs trustworthy, harden CKEditor unresolved markers and migration failure exits, and critically audit Kunstmaan Page-rooted introspection end-to-end. The audit treats a Kunstmaan Page as the entry point and a Craft Entry as the result: every page-owned relation, asset, page-part, taxonomy/data-provider/leaf entity, SEO/redirect sidecar, and CKEditor reference must be either migrated, intentionally dropped with a visible reason, or explicitly marked out of scope. | PH9-01..20 | 10 criteria below | no |

### Phase 1: Foundation & Connectivity

**Goal:** A scaffolded Craft 5 plugin that installs cleanly, owns its legacy DB connection, and exposes a working `doctor` command. PHPUnit suite is wired and non-empty on day one — no "tests skipped in 1.0" regret this time.

**Requirements:** FND-01, FND-02, FND-02a, FND-03, FND-04, FND-05, CONN-01, CONN-02, CONN-03.

**Success criteria:**
1. `composer require lameco/craft-kunstmaan-migrator` followed by `./craft plugin/install kunstmaan-migrator` succeeds on a stock Craft 5 install with no SEOmatic / Retour deps.
2. The state table `kunstmaanmigrator_state` and the `kunstmaanSourceId` field exist after install. If a v1.x `kunstmaanSourceId` field UID was already present, the new install reuses it.
3. `./craft kunstmaan-migrator/doctor` reports OK/FAIL for legacy DB, Anthropic key, mapping file presence, and `storage/migration/` write permission. Exits non-zero on any FAIL.
4. `CRAFT_ENVIRONMENT=production` causes any legacy-reading command to refuse with a clear error message.
5. `composer test` (`phpunit`) runs and is green; CI workflow runs the same on every push.

**Plans:** 5 plans

Plans:
- [x] 01-01-composer-scaffold-PLAN.md — composer.json + Plugin.php stub + NeverProductionTrait (FND-01) — completed 2026-04-25
- [x] 01-02-settings-legacy-db-PLAN.md — Settings model + LegacyDbService + Plugin::init() with conditional legacyDb wiring (CONN-01, CONN-02) — completed 2026-04-25
- [x] 01-03-install-migration-PLAN.md — Install.php (state table + kunstmaanSourceId field with UID reuse) + MigrateController::actionInstall (FND-02, FND-02a, FND-03) — completed 2026-04-25
- [x] 01-04-doctor-command-PLAN.md — DoctorController with 3 preflight checks + NeverProduction enforcement (FND-04, CONN-03 partial — mapping check defers to Phase 2 per D-17) — completed 2026-04-25
- [x] 01-05-tests-ci-docs-PLAN.md — PHPUnit 11 + GitHub Actions CI + README + project-doc patches (FND-05) — completed 2026-04-25

### Phase 2: Schema, Mapping & Filters

**Goal:** The operator can run `analyze` against a Kunstmaan dump and end up with a single `mapping.yaml` populated by 9-heuristic auto-matches plus Anthropic proposals for residuals. The `map` rubber-stamp loop walks unresolved rows. Filters and locale handling are first-class.

**Requirements:** MAP-01..07, FILT-01..03, LOC-01..02.

**Success criteria:**
1. `analyze` against a fixture Kunstmaan dump emits `storage/migration/schema-dump.json` + `REPORT.md` and writes proposals to a single `mapping.yaml` with per-row `status:`.
2. The 9 deterministic heuristics resolve at least 60% of columns on the CQM rehearsal dump before any LLM call is made.
3. `kunstmaan-migrator/map` loops through unresolved rows interactively and persists decisions atomically to `mapping.yaml`.
4. `kunstmaan-migrator/migrate --live` refuses to start while any data-bearing legacy column has no mapping decision; `--dry-run` prints coverage warnings without blocking.
5. The five top-level CLI commands all accept `--entities=`, `--locales=`, `--since=` and produce identical filter behaviour at every stage. _(D-12: per-entity row cap flag dropped from v1.0 — three flags, not four.)_

**Plans:** 6 plans

Plans:
- [x] 02-01-filter-locale-primitives-PLAN.md — MigrationFilters VO + FilterFactory + LocalePreflight + Plugin.php registration (FILT-01, FILT-02, FILT-03, LOC-01, LOC-02) — completed 2026-04-25
- [x] 02-02-mapping-file-PLAN.md — MappingFile reader+writer with status-on-row + atomic IO + skip-existing merge (MAP-04 satisfied; MAP-01 partial — schema-dump.json + REPORT.md emission lands in Plan 03) — completed 2026-04-25
- [x] 02-03-analyze-pipeline-PLAN.md — AnalyzeController + SchemaDumper + HeuristicProposer + LlmClassifier + ReportBuilder (MAP-01..05, LOC-01, FILT-03 satisfied) — completed 2026-04-25
- [x] 02-04-map-rubber-stamp-loop-PLAN.md — MapController interactive loop + two-step picker + atomic per-keypress (MAP-05, FILT-03) — completed 2026-04-25
- [x] 02-05-coverage-audit-doctor-PLAN.md — CoverageAuditor + MappingAuditor + DoctorController 4th check (MAP-06, MAP-07, CONN-03 fully satisfied — mapping-file check landed; FILT-03 doctor flag declarations) — completed 2026-04-25
- [x] 02-06-tests-and-doc-patches-PLAN.md — PHPUnit unit tests for MigrationFilters/FilterFactory/MappingFile/CoverageAuditor + REQUIREMENTS/ROADMAP D-12 patches (FILT-01, MAP-04, MAP-06) — completed 2026-04-25

### Phase 02.1: Kunstmaan Source Introspection

**Goal:** The analyze pipeline reads the Kunstmaan source codebase as the source of truth for content schema — Doctrine entity classes, YAML page-part config, AND vendor Kunstmaan bundles. The database is treated as opaque storage; the source code tells the migrator what each table means, which polymorphic class each row instantiates, which page-part classes are allowed in which page contexts, and how to reconstruct the page → context → matrix-of-page-parts hierarchy in Craft.

**Why this slots between Phase 2 and Phase 3 — the three things the DB cannot tell you:**

1. **Project content tables** are project-prefixed (`lameco_websitebundle_*`) — current `LIKE 'kuma_*'` filter misses them. Even M2M join tables (`case_study_pages_categories`, `lameco_websitebundle_field_pages_method_pages`) are invisible.
2. **Polymorphic page-part identity** — Kunstmaan stores all page-parts in tables like `kuma_main_pageparts` joined to `kuma_node_versions` by context strings, with the actual subclass encoded by Doctrine single-table inheritance. The DB row alone doesn't tell you whether a `main_pageparts` row is a `HeaderPagePart`, `TextPagePart`, `ImagePagePart`, etc.
3. **Page → context → allowed-page-part-classes structure** — lives entirely in `src/Entity/Pages/{X}.php::getPagePartAdminConfigurations()` (returns the contexts the page uses) AND `config/kunstmaancms/pageparts/{context}.yml` (declares which page-part classes are allowed in that context). Without this, the migrator can't construct Craft Matrix fields for page-parts at all — they'd land as orphaned flat rows.

Phase 3's `migrate --live` literally cannot work without this metadata. Phase 02.1 makes it available.

**Requirements:** SRC-01..10 (to be added to REQUIREMENTS.md during plan-phase).

**Success criteria:**

*Doctrine source layer (database structural truth):*
1. `KunstmaanSourceScanner` reads `KUNSTMAAN_SOURCE_PATH` (env or `Settings::kunstmaanSourcePath`); scans `Entity/**/*.php` (project) + `vendor/kunstmaan/*/Entity/**/*.php` (vendor). KUNSTMAAN_SOURCE_PATH is required for analyze; doctor's 5th check FAILs (not WARNs) when unset.
2. Discovered tables: every `#[ORM\Table(name: '…')]` (and `@ORM\Table` legacy syntax); every `#[ORM\JoinTable(name: '…')]` for M2M; vendor base-class tables (`AbstractArticlePage`, `AbstractEntityAdminPart`, etc.) resolved via `extends` chain.
3. `SchemaDumper` consumes the discovered table list when source path is set. WARNs when DB has tables not present in scanner output (drift detection). (Required path; greenfield-fallback dropped per D-31 — KUNSTMAAN_SOURCE_PATH is the new floor.)
4. M2M relations are resolved end-to-end: owning side (with explicit `JoinTable`) and inverse side (with `mappedBy`) cross-reference; both target classes' tables are discovered; the join table is marked as such in `mapping.yaml`.

*Page-part structural layer (source-only truth):*
5. `KunstmaanPageStructureScanner` (separate from the schema scanner) reads each `Entity/Pages/*.php` for `getPagePartAdminConfigurations()` and `getPageTemplates()` returns; reads `config/kunstmaancms/pageparts/*.yml` for context → allowed-page-part-classes mappings.
6. Output: a structured `pageStructure.json` that says, for each Page entity: `{ tableName, contexts: [{ name, allowedPagePartClasses: [{class, table}] }], templates: [...], possibleChildTypes: [...] }`. This is the canonical input for Phase 3's Matrix-field construction.
7. Single-table-inheritance discriminators (`#[ORM\InheritanceType('SINGLE_TABLE')]` + `#[ORM\DiscriminatorColumn]` + `#[ORM\DiscriminatorMap]`) are extracted; each `*_pageparts` row's class can be resolved by discriminator value.

*Heuristic + LLM enrichment:*
8. `HeuristicProposer` gains an entity-aware heuristic: column → entity property → Craft field handle (higher confidence than name matching alone). Inherited columns (from `AbstractArticlePage` etc.) are recognized and grouped.
9. `LlmClassifier` prompts include the parent-entity class signature + property docblock + (for page-parts) the contexts the parent page uses, for each residual chunk.

*Operator surface:*
10. Doctor gains a 5th check: "Kunstmaan source path" — verifies the path exists, contains `src/Entity/`, and contains `config/kunstmaancms/pageparts/` (or notes when YAML config is absent → fall back to PHP-only scan). Re-running analyze against `~/Sites/cqm-craft-website` with `KUNSTMAAN_SOURCE_PATH=~/Sites/cqm-website` produces a meaningful coverage measurement (not 100% fill-rate-zero drops) and emits `pageStructure.json` alongside `schema-dump.json`.

**v1 brownfield reuse — port wholesale, do NOT rewrite:**

Survey of `~/Sites/craft-kunstmaan-migrator/src` shows v1 already solved most of this and shipped against the CQM rehearsal. Don't fall into the v2 trap of rewriting from scratch — port these classes verbatim and only reshape the surface where v2 architectural decisions require it:

| v1 class | LOC | What it does | Phase 02.1 disposition |
|---|---|---|---|
| `kunstmaan/schema/DoctrineEntityParser` | 366 | Parses `#[ORM\Table]`, `#[ORM\Column]`, `#[ORM\ManyToOne]`, `#[ORM\ManyToMany]`, `#[ORM\JoinTable]`; resolves `Foo::class` and `\Full\Ns\Foo::class` short refs via `use` map; `getByFqcn()` / `getByTable()` / `getAll()` API | **Port verbatim**. This IS the scanner Phase 02.1 needs. |
| `kunstmaan/schema/DetailTableResolver` | 280 | 4-tier FQCN→table resolver: (1) operator override array, (2) DoctrineEntityParser source truth, (3) information_schema prefix scan, (4) all-tables suffix scan | **Port verbatim**. Solves polymorphic page-part identity exactly as needed. |
| `kunstmaan/schema/knowledgebase/KnowledgeBase` | 68KB | Generates Markdown KB about Pages + PageParts (table grouping; column lists; per-page page-part contexts; counts per context). Output is fed to LlmClassifier prompts. | **Port partial** — focus on `renderPagesMarkdown` + `renderPagePartsMarkdown`. This is the missing piece in our current LlmClassifier (we hardcode `'', ''` for KB markdown). |
| `kunstmaan/schema/BodyScanColumnFinder` | 100 | Auto-discovers TEXT/LONGTEXT columns matching `(content\|intro\|summary\|body\|description)` regex — used by CKEditor body-token rewrite later | **Port now** as part of scanner output. Phase 3 consumes it. |
| `kunstmaan/schema/MediaFkScanner` | 100 | Auto-discovers columns referencing `kuma_media.id` via information_schema (BIGINT + name regex) | **Port now**. Phase 3 consumes it. |
| `kunstmaan/schema/TopologicalOrderer` | 130 | Pure-function depth-first orderer for `kuma_nodes` parent-first hierarchical insertion (cycle detection included) | **Port now** even though Phase 3 consumes it — the discovery surface belongs here. |
| `bridge/schema/knowledgebase/CoverageAuditor` | 482 | v1's coverage auditor (different from the one we shipped in Plan 02-05) | **Compare + reconcile**. v2's CoverageAuditor was a fresh write; some v1 details may have been lost. |
| `bridge/mapping/MappingValidator` | 647 | Already partially ported into our MappingAuditor (Plan 02-05) | **Re-audit**. Confirm no rules dropped during the port. |
| `bridge/mapping/BlockAvailabilityValidator` | ~200 | Validates every `pageParts[*].target` block handle is available in at least one nodeClass that uses the page-part | **Port for 02.1** — extends MappingAuditor with block-availability checks once page-part contexts are known. |

What v1 had that we are NOT porting in 02.1 (deferred to Phase 3 where they belong):
- `bridge/transform/TransformService` (39.5K) — Phase 3's Transform stage
- `bridge/ckeditor/CkeditorRewriterService` (19.9K) — Phase 3's finalize stage
- `bridge/load/*` — Phase 3's Load stage (asset/taxonomy/redirect/SEO/atomic)
- `bridge/mapping/AbstractPagePartMapper` + `AbstractNodeClassMapper` + `DeclarativeMapperDriver` — Phase 3's per-class escape-hatch mapper system. CQM had 6 custom PagePart mappers + 3 NodeClass mappers — we'll need this same surface for v2 in Phase 3.
- `bridge/queue/*` — explicitly dropped per CLI-only decision.
- `craft/load/handlers` — Phase 3's six built-in field handlers (Asset, Matrix, PlainText, Relation, SplitName, Taxonomy).

**Craft target side — what's already provisioned in cqm-craft-website (validated):**

19 sections exist: `caseCategories, caseOverviewPage, casePages, contactPage, contentPages, cookieConsentPage, errorPage, globalSettings, handoutsPage, homePage, llmSettings, newsCategories, newsOverviewPage, newsPages, searchPage, teamOverviewPage, teamPages, thanksPages, topics`. So the Craft target IS populated — the heuristic-coverage problem from Phase 2 UAT was 100% the source-side scanner missing the project tables, NOT the target side being empty. Once Phase 02.1 lands the source-driven scanner, heuristic 2 (exact name match) should fire repeatedly because the entry types and field handles exist on both sides (NewsPage→newsPages, EmployeePage→teamPages, etc.).

**Additional success criteria (consolidating the v1 survey):**

11. **DoctrineEntityParser** ported verbatim from v1 with the use-map FQCN resolver intact.
12. **DetailTableResolver** ported with the 4-tier resolution priority (operator override → source code → prefix scan → suffix scan).
13. **KnowledgeBase Markdown generator** ported and wired into `LlmClassifier::batchPropose` — replace the current `'', ''` placeholders with `$kb->renderPagesMarkdown()` and `$kb->renderPagePartsMarkdown()`. Truncation to 8000 chars stays.
14. **BodyScanColumnFinder + MediaFkScanner + TopologicalOrderer** ported as part of the scanner output. Phase 3 consumes them — Phase 02.1 is responsible for getting the discovery right.
15. **BlockAvailabilityValidator** ported — extends MappingAuditor with the "every target block handle is reachable from at least one parent page's allowed-page-part list" check.
16. **CoverageAuditor reconciliation** — compare our Plan 02-05 fresh write against v1's `bridge/schema/knowledgebase/CoverageAuditor` (482 LOC); land any rules we dropped.
17. **MappingValidator reconciliation** — same audit for MappingAuditor against v1's 647 LOC original.

**Plans:** 9 plans

Plans:
- [x] 02.1-01-source-path-infra-PLAN.md — Settings field + KunstmaanSourcePathResolver + Doctor 5th check + --source-strict declarations (SRC-10) — completed 2026-04-26
- [x] 02.1-02-doctrine-port-PLAN.md — DoctrineEntityParser + DetailTableResolver + 3 VOs + KunstmaanCoreTables (SRC-11, SRC-12) — completed 2026-04-26
- [x] 02.1-03-discovery-port-PLAN.md — BodyScanColumnFinder + MediaFkScanner + TopologicalOrderer + KnowledgeBase partial port + KnowledgeBaseSmokeTest (SRC-13, SRC-14) — completed 2026-04-26
- [x] 02.1-04-page-structure-scanner-PLAN.md — KunstmaanPageStructureScanner + nikic/php-parser direct require (SRC-05, SRC-06, SRC-07) — completed 2026-04-26
- [x] 02.1-05-source-scanner-orchestrator-PLAN.md — KunstmaanSourceScanner + Plugin::config() registration (SRC-01, SRC-02, SRC-03, SRC-04) — completed 2026-04-26
- [x] 02.1-06-mapping-extension-PLAN.md — MappingFile row-kind support + MapController two-mode walker (SRC-18, SRC-19) — completed 2026-04-26
- [x] 02.1-07-analyze-wiring-PLAN.md — AnalyzeController 11-step pipeline + heuristic 1.5 + LlmClassifier KB integration (SRC-08, SRC-09) — completed 2026-04-26
- [x] 02.1-08-audit-extension-PLAN.md — BlockAvailabilityValidator + Drift section + CoverageAuditor page-part coverage (SRC-15) — completed 2026-04-26
- [x] 02.1-09-reconciliation-PLAN.md — RECONCILIATION.md + REQUIREMENTS SRC codification + ROADMAP D-31 patches (SRC-16, SRC-17) — completed 2026-04-26

### Phase 3: ETL Pipeline & Field Handlers

**Goal:** End-to-end migration of a Kunstmaan dump's content into Craft, atomically per-entry, idempotent across re-runs, with all six field handlers in place and CKEditor body tokens resolved on the finalize pass.

**Requirements:** ETL-01..07, FH-01..04, FIN-01..02.

**Success criteria:**
1. `migrate --live` against the CQM rehearsal dump completes without error and produces Craft entries with the expected counts (within ±1% of baseline).
2. Re-running `migrate --live` after success skips already-migrated rows by `kunstmaanmigrator_state.legacy_id` — zero duplicates.
3. A forced failure mid-run leaves Craft in a consistent state (the failing entry rolled back, all earlier entries persisted).
4. `migrate/finalize` resolves the cross-entry `[NT<id>]` and media `[M<id>]` tokens left by the load pass; unresolvable tokens are annotated with `<!-- MIGRATION:UNRESOLVED ... -->` and reported in the run summary.

**Plans:** 14 plans

Plans:
- [x] 03-01-fields-scaffold-PLAN.md — FieldHandler interface + Registry + DeferredAssetToken + ResolverContext (FH-01, FH-02) — completed 2026-04-26
- [x] 03-02-load-vos-PLAN.md — MigrationStateReader + MigrationOptions + AssetPathResolver + 2 TaxonomyResolvers (ETL-04, ETL-05) — completed 2026-04-26
- [x] 03-03-state-service-PLAN.md — MigrationStateService verbatim port (ETL-05) — completed 2026-04-26
- [x] 03-04-extract-service-PLAN.md — ExtractService 533 LOC + page-part-ordering drift documented (ETL-03) — completed 2026-04-26
- [x] 03-05-asset-migration-PLAN.md — AssetMigrationService 617 LOC + JIT/preload reshape (FH-03, ETL-05) — completed 2026-04-26
- [x] 03-06-ckeditor-rewriter-PLAN.md — CkeditorRewriterService 529 LOC + FinalizeWalker greenfield (FIN-01, FIN-02, FH-04) — completed 2026-04-26
- [x] 03-07-attach-and-entry-migration-PLAN.md — AttachService partial + EntryMigrationService 662 LOC (ETL-04) — completed 2026-04-26
- [x] 03-08-handlers-text-asset-PLAN.md — PlainTextHandler (4 modes; seomatic stripped) + AssetHandler (FH-01, FH-04) — completed 2026-04-26
- [x] 03-09-handlers-relation-matrix-PLAN.md — RelationHandler + MatrixHandler with D-49 dispatch (FH-01) — completed 2026-04-26
- [x] 03-10-handlers-splitname-PLAN.md — SplitNameHandler with Dutch tokens preserved (FH-01) — completed 2026-04-26
- [x] 03-11-transform-service-PLAN.md — TransformService 940 LOC with D-48 in-process pipeline reshape (ETL-01, FH-02) — completed 2026-04-26
- [x] 03-12-atomic-migration-PLAN.md — MigrationReport VO + AtomicMigrationService 255 LOC (ETL-01, ETL-04, ETL-05, FH-04) — completed 2026-04-26
- [x] 03-13-controller-and-wiring-PLAN.md — Plugin::config/init Phase 3 wiring + MigrateController 6 actions + Doctor 6th check (ETL-01..07, FH-02, FH-03, FIN-01) — completed 2026-04-26
- [x] 03-14-reconciliation-PLAN.md — Phase RECONCILIATION.md + REQUIREMENTS/ROADMAP patches (ETL-07, FIN-02) — completed 2026-04-26

### Phase 4: Adapters, Verify & Settings

**Goal:** SEOmatic and Retour are migrated when their plugins are installed and skipped (with WARN) when they aren't — composer no longer hard-requires either. The verify parity gate produces a timestamped report and the CP exposes a Settings screen for the operator-facing toggles.

**Requirements:** ADP-01..03, VER-01..03, CFG-01..03.

**Success criteria:**
1. Plugin installs cleanly on a Craft 5 host with neither SEOmatic nor Retour. `migrate --live` completes; redirects + SEO bundles are skipped with WARN lines in the rehearsal report.
2. On a host with both plugins installed, redirects and per-locale SEOmatic MetaBundles are migrated — counts match baseline within tolerance.
3. `verify capture-baseline` + `verify` produces a `VERIFY-<timestamp>.md` report under `storage/migration/` regardless of pass/fail.
4. CP Settings page reads + writes Anthropic key, LLM model, LLM timeout, default filter values, and the `dryRunDefault` toggle.

**Plans:** 12 plans

Plans:
- [x] 04-01-settings-expansion-PLAN.md — Settings.php verify thresholds + adapter table-name overrides (CFG-01 partial — Settings field exposure; CP template wiring in Plan 04-05) — completed 2026-04-26
- [x] 04-02-seomatic-payload-builder-PLAN.md — SeomaticPayloadBuilder verbatim port (ADP-01) — completed 2026-04-26
- [x] 04-03-verify-primitives-PLAN.md — SnapshotDiffer + SpotCheckUrlFetcher verbatim port (B1 fix preserved) (VER-02 partial — primitives ready; consumer wiring in Plan 04-08 + 04-09) — completed 2026-04-26
- [x] 04-04-count-gate-and-baseline-counter-PLAN.md — CountGateService verbatim + BaselineCounterService shape-derived (D-59) (VER-01 partial / VER-03 partial — services ready; controller wiring in Plan 04-09) — completed 2026-04-26
- [x] 04-05-cp-settings-template-PLAN.md — CP `_settings.twig` grouped sections + editable tables + masked API key (CFG-01) — completed 2026-04-26
- [x] 04-06-seo-migration-service-PLAN.md — SeoMigrationService verbatim port (ADP-01 partial — service ready; controller wiring in Plan 04-09 + actionSeo in Plan 04-10) — completed 2026-04-26
- [x] 04-07-redirect-migration-service-PLAN.md — RedirectMigrationService verbatim port + hardcoded site-handle reshape (ADP-02 service-level — service ready; controller wiring in Plan 04-09 + actionRetour in Plan 04-10) — completed 2026-04-26
- [x] 04-08-capture-baseline-html-service-PLAN.md — CaptureBaselineHtmlService verbatim port (VER-02 service-level — service ready; controller wiring + actionCaptureBaselineHtml in Plan 04-09) — completed 2026-04-26
- [x] 04-09-verify-controller-and-plugin-wiring-PLAN.md — VerifyController + Plugin::config()/init() Phase 4 wiring (VER-01, VER-02, VER-03 complete; ADP-01, ADP-02 service+wiring complete pending Plan 04-10 actionSeo/actionRetour) — completed 2026-04-26
- [x] 04-10-migrate-controller-extensions-PLAN.md — MigrateController actionSeo/actionRetour + verbosity + REPORT.md sections + asset RCA (ADP-01, ADP-02 complete via sub-actions + actionIndex bolt-ons; CFG-02 verbosity + per-run log file; CFG-03 REPORT.md three new sections + asset RCA) — completed 2026-04-26
- [x] 04-11-doctor-7th-8th-checks-PLAN.md — Doctor adapter health + verify baseline presence checks (ADP-01..03, VER-01) — completed 2026-04-26
- [x] 04-12-tests-and-reconciliation-PLAN.md — PHPUnit unit tests (60 → 83 tests, +23) + Phase 4 RECONCILIATION.md aggregate + ADP-03 composer-suggest invariant guard (ADP-01..03, VER-01..03, CFG-02 complete) — completed 2026-04-26

### Phase 4.1: Polish, Recovery & .env Source-of-Truth

**Goal:** Close real correctness, ergonomics, and operator-recovery gaps surfaced by Phase 4 UAT and the .env source-of-truth survey across cqm/simac/enreach. Locked-decision reversals (CP runner, Craft queue, FeedMe-style remap utility) explicitly stay OUT of scope — they get their own Phase 4.2 if pursued.

**Why a 4.1 instead of folding into Phase 5:** Phase 4 shipped twelve plans, ten passed UAT, but five gaps surfaced (two fixed in-band: G-01 settings template fragment, G-03 forms.passwordField masking; three deferred: G-02 CP page slimming, G-04 dropdowns, G-05 REPORT.md always-emit). Plus one critical bug-trap caught mid-execution (04-06 inherited v1's `MigrationFilters` properties that don't exist in v2 — same trap latent in any other v1-port that touches filters). Phase 5 (rehearsal + release) needs a clean foundation; 4.1 closes the loop before simac/enreach rehearsal exposes more.

**Requirements:** CFG-04..07, LOC-03, ADP-04, VER-04, SRC-20, REC-01, REC-02.

**Success criteria:**

*.env source-of-truth (load-bearing for correctness):*
1. `KunstmaanEnvReader` reads `KUNSTMAAN_SOURCE_PATH/.env.example` then `.env` (override) with a strict 2-key whitelist: `DATABASE_URL`, `DEFAULT_LOCALE`. Every other key is deliberately ignored — secrets never enter memory.
2. When `Settings::legacyDb*` are blank, `DATABASE_URL` parses to per-component defaults (host, port, database, user, password). Operator-supplied Settings values always override the env-derived defaults.
3. `LocalePreflight` consults `DEFAULT_LOCALE` as Rung 0 and validates that the first handle in `Settings::localeMap` aligns with it. Mismatch → doctor WARN (advisory; some projects intentionally diverge); no auto-correction, no hard-fail.
4. Doctor 9th check reports the env reader's state: file presence at the source path + whether `DEFAULT_LOCALE` was found.

*CP page slimming + Craft-convention config overrides:*
5. CP Settings page renders only operator-touch-essential fields (~9, two H2 groups: Connectivity + Mapping). Page height ~40% of current. Remaining advanced fields (14) move to `config/kunstmaan-migrator.example.php` documentation.
6. Craft's auto-loaded `config/kunstmaan-migrator.php` already-supported override mechanism exposes every Settings field at runtime — no new code path needed; the change is template + documentation.
7. Surviving CP editable-table columns use Craft `cols: { handle: { type: 'select', options: [...] } }` dropdowns where values are knowable from existing services: Craft site handles (always available), legacy locale codes (when DB reachable), Kunstmaan entity basenames (when KUNSTMAAN_SOURCE_PATH set + scanner can run). Free-text fallback when source data isn't available.

*Adapter behavior + filter contract:*
8. `Settings::seoEnabled` + `Settings::retourEnabled` booleans (default `true` to preserve current behavior) gate the SEOmatic + Retour adapters even when the respective plugin IS installed. Operator can disable per-run via CLI flag (`--no-seo`, `--no-retour`) for the same effect without touching settings.
9. `VerifyController` CLI filter options (`--entity=`, `--locale=`, `--since=`) flow into `CountGateService::run()` and `BaselineCounterService::capture()`. The intelephense `$filters unused` finding from Phase 4 is closed.

*Doctrine entity parser scope reduction:*
10. `DoctrineEntityParser` parses PHP 8 attributes only. Annotation parsing is removed (not deprecated). All targeted Kunstmaan projects (cqm, simac, enreach) verified as PHP 8+ before this lands.

*Operator recovery commands:*
11. `migrate sync-assets` ingests every `kuma_media` row that any prior atomic run referenced but skipped (filesystem_404 / mime_mismatch / too_large / etc.). Idempotent: re-running with everything healed exits cleanly. Permanently-failed assets get a terminal-state marker in migration_state to prevent retry loops.
12. `migrate sync-relations` resolves any `relation:deferred` rows in the migration state table that couldn't be resolved during their owning entry's atomic save (typical: forward-references, taxonomies migrated after the entry that references them). Same idempotence + terminal-state contract.

*REPORT.md operator-diagnosability:*
13. `## Skipped stages` and `## Asset RCA` sections in REPORT.md always emit, even when empty. Empty state shows placeholder copy ("_No skipped stages — all configured adapters were exercised._" / "_No asset RCA rows — no assets were migrated, or all migrated cleanly._"). Constant report shape across runs makes "did this code path run?" trivially answerable.

*Phase 4 RECONCILIATION update:*
14. Phase 4.1 RECONCILIATION.md retires three plan-04-05 acceptance greps that codified bugs (`extends "_layouts/cp" = 1`, `type: 'password' = 2`) in favor of fragment-shape + explicit `passwordField` checks. References commits 363cc5c (G-01 fix) and 0f56288 (G-03 fix).

**Plans:** 7 plans (rough breakdown — confirmed during plan-phase)

Plans:
- [x] 04.1-01-env-reader-PLAN.md — `KunstmaanEnvReader` + 2-key whitelist + `.env.example` + `.env` precedence + doctor 9th check
- [x] 04.1-02-database-url-autofill-PLAN.md — Settings::beforeValidate auto-fill from DATABASE_URL when blank (CFG-04)
- [x] 04.1-03-locale-preflight-rung0-PLAN.md — DEFAULT_LOCALE Rung 0 + primary-handle alignment doctor WARN (LOC-03)
- [x] 04.1-04-cp-slimming-and-config-example-PLAN.md — strip 14 fields from CP, ship `config/kunstmaan-migrator.example.php`, dropdowns where data is knowable (CFG-05, CFG-06)
- [x] 04.1-05-adapter-disable-and-filter-plumbthrough-PLAN.md — seoEnabled/retourEnabled + VerifyController $filters wiring + REPORT.md always-emit (ADP-04, VER-04, CFG-07)
- [x] 04.1-06-attributes-only-parser-PLAN.md — strip annotation parsing from DoctrineEntityParser (SRC-20); pre-flight grep across cqm/simac/enreach
- [x] 04.1-07-recovery-commands-PLAN.md — `migrate sync-assets` + terminal-state marker + Phase 4.1 RECONCILIATION.md (REC-01 ✅; REC-02 deferred to Phase 4.2 — no `relation:deferred` marker exists in state schema, see `04.1-07-DISCOVERY.md` Finding B)

### Phase 5: Tests, Rehearsal & Release

**Goal:** Characterization tests on the Transform stage make regressions cheap to catch, the CI workflow gates every PR, and the v1.0 release ships green against the CQM rehearsal corpus.

**Requirements:** TST-01..04.

**Success criteria:**
1. PHPUnit unit suite covers `MigrationFilters`, `MappingLoader`, every field handler, `CkeditorRewriter`, and the heuristic proposer — minimum 70% line coverage on those modules.
2. Transform-stage characterization fixtures from a real CQM extract run end-to-end through the Transform stage and produce golden-file-stable output across runs.
3. CI workflow on `main` runs `composer validate --strict`, `phpunit`, and a plugin-load smoke test on a scratch Craft 5 install — green on the release commit.
4. v1.0 rehearsal log against the CQM dump records: counts within tolerance vs baseline, zero unresolved CKEditor tokens beyond annotated ones, all assets ingested or explicitly recorded as failed with RCA tags.

**Plans:** 8 plans

Plans:
- [x] 05-01-tests-reorganization-PLAN.md — `git mv` every existing test path into tests/unit/<area>/; PluginBootstrapTest moves to tests/integration/; phpunit.xml.dist retargeted (D-12, D-14)
- [x] 05-02-phpunit-infrastructure-PLAN.md — split phpunit.xml.dist into Unit + Integration testsuites; <source> + <coverage> blocks; composer test-coverage chained-script with PCOV/Xdebug fail-fast; tools/check-coverage.php per-module 70% gate (D-06, D-07, D-08, D-13)
- [x] 05-03-transform-characterization-fixtures-PLAN.md — TransformCharacterizationTest with @dataProvider + UPDATE_SNAPSHOTS=1 + canonicalize-then-diff comparator; tools/capture-transform-fixtures.php operator script (TST-02 / D-01..D-05)
- [x] 05-04-rehearsal-controller-and-dirs-PLAN.md — src/console/RehearsalController.php with three mechanical gate parsers (DELIBERATELY OMITS NeverProductionTrait per D-22); .planning/rehearsal/v1.0/{cqm,simac,enreach}/ directory shape with operator README placeholders (D-19..D-24)
- [x] 05-05-unit-tests-analyze-finalize-PLAN.md — direct unit tests for HeuristicProposer (heuristics + confidence routing) and CkeditorRewriterService (Reflection-on-private-helper); biased by 05-02 baseline coverage (TST-01 / D-10)
- [x] 05-06-unit-tests-field-handlers-PLAN.md — direct unit tests for PlainText/SplitName/Relation/Matrix/Asset handlers; createStub for MigrationStateService where needed (TST-01 / D-10)
- [x] 05-07-ci-smoke-job-PLAN.md — .github/workflows/ci.yml splits into unit (test + coverage gate + clover artifact) and smoke (scratch-Craft + path-repo + ./craft kunstmaan-migrator/doctor exit 0); needs: unit gates smoke (TST-03 / D-15..D-18)
- [x] 05-08-release-checklist-changelog-reconciliation-PLAN.md — .planning/RELEASE-CHECKLIST.md (8 steps; D-25 step 8 omitted per verified Lameco convention); CHANGELOG.md at repo root in Keep-a-Changelog format; Phase 5 RECONCILIATION.md; REQUIREMENTS.md TST-01..04 flipped to [x] (TST-04 / D-25, D-26)

### Phase 8: Taxonomies & AI Proposer Coverage

**Goal:** The plugin migrates Kunstmaan taxonomy entities (NewsCategory / CaseStudyCategory / employee-style flat-table standalones) into Craft category groups or sections, and the analyze AI proposer covers two surfaces it currently leaves to the operator: `dataProviders` block typing and per-page Matrix layout (`headerBlock` / `bodyWrapBlock` / `bodyColumn`). Closes the gaps surveyed after Phase 7 part 2 — these together are what makes the plugin useful for any real Kunstmaan project before the v1.0 tag.

**Why this phase exists:** A post-Phase-7 coverage survey (see commit history `5f547cb..` and the CONTEXT.md gap analysis) showed three gaps between what Kunstmaan supports and what the v2 mapper handles:

1. **Taxonomies** — v1's `TaxonomyMigrationService` (443 LOC) and its `taxonomies:` mapping block were never ported. Doctrine standalone entities (categories, tags) silently fail to migrate.
2. **dataProviders proposer** — `TransformService` already dispatches `mapping.dataProviders[]` (B9 wiring), but `MappingCompiler` doesn't propose them. Operator must hand-author every entry.
3. **Page-builder layout proposer** — `headerBlock` / `bodyWrapBlock` / `bodyColumn` are honored by transform but never proposed by analyze. Phase 7's implicit-content emitter is the closest precedent and the same shape of work.

**Requirements:** TAX-01..11, PROP-01..06, DOC-01..02 (codified by Plan 08-17 in REQUIREMENTS.md).

**Success criteria (vision — refine in CONTEXT.md):**

*Taxonomies:*
1. `taxonomies:` mapping block accepted by `MappingFile` / `MappingAuditor` / `MappingCompiler`. Per-FQCN spec at minimum: `{ sourceTable, section, entryType, fields, translatableFields[]?, action: SKIP? }`.
2. Analyze AI proposer emits taxonomy candidates: scans Doctrine entities NOT reachable from `kuma_nodes` (no node join), classifies as taxonomy when it has translation rows in `ext_translations` or carries a `name`/`slug` shape.
3. New `TaxonomyMigrationService` (port v1's 443 LOC; reshape only where v2 architectural ground rules require: single `mapping.yaml`, atomic-always-on, runtime-zero-AI). Site-agnostic state rows keyed by FQCN-slug.
4. End-to-end test (a la Phase 7's `TransformImplicitContentTest`) drives one taxonomy entity through analyze → compile → load and asserts a Craft entry exists.

*AI proposers:*
5. `dataProviders` proposer: for any extracted page-part FQCN whose source table doesn't match a node-attached page-part, the LLM proposes a `(target, configFields)` shape against the Matrix catalog.
6. Page-builder layout proposer: for each accepted `nodeClass`, the LLM proposes `headerBlock` / `bodyWrapBlock` / `bodyColumn` when the parent entry-type's Matrix catalog suggests one. Operator-set values always win (skip-existing).
7. Compile surfaces a counter for each proposer (`dataProvidersEmitted`, `headerBlocksEmitted`, etc.) the same way Phase 7 surfaced `implicitBlocksEmitted`.

*Documentation:*
8. CHANGELOG.md "Known omissions in v1.0" section listing Kunstmaan surfaces this migrator deliberately does NOT cover: FormBundle, SearchBundle, MenuBundle, user accounts / roles / ACLs, `kuma_translations` (i18n string catalog), media folder hierarchy, asset metadata (alt text / focal point), slug history (Retour-style mining beyond `kuma_redirects`).

**Plans:** 15 plans

Plans:
- [x] 08-01-PLAN.md — MappingFile/MappingAuditor/MappingCompiler taxonomy + dataProvider row scaffolding (TAX-01, PROP-03)
- [x] 08-02-PLAN.md — DoctrineEntityParser Gedmo namespace scan (TAX-04)
- [x] 08-03-PLAN.md — KnowledgeBase::renderTaxonomiesMarkdown (TAX-05)
- [x] 08-04-PLAN.md — LegacyDbService::extTranslationsFor + EXT_TRANSLATIONS constant (TAX-06)
- [x] 08-05-PLAN.md — LlmClassifier 3 new proposers: proposeNonPageEntities + proposeLayoutBlocks + proposeDataProviders (TAX-02, PROP-01..03)
- [x] 08-08-PLAN.md — Settings + AnalyzeController flags + 3 proposer dispatch steps (PROP-04)
- [x] 08-09-PLAN.md — MappingCompiler compile passes + counter wiring (TAX-01, PROP-05)
- [x] 08-10-PLAN.md — MigrationFilters reachability auto-include (TAX-03)
- [x] 08-11-PLAN.md — TaxonomyMigrationService verbatim port (TAX-06, TAX-07)
- [x] 08-12-PLAN.md — Plugin DI + MigrateController bolt-on/sub-action (TAX-08)
- [x] 08-13-PLAN.md — _settings.twig AI H2 group (PROP-04 polish)
- [x] 08-14-PLAN.md — Doctor 11th check ext_translations (TAX-09)
- [x] 08-15-PLAN.md — TaxonomyMigrationTest integration (TAX-10)
- [x] 08-16-PLAN.md — MappingCompiler taxonomies/layout/dataProviders unit tests (TAX-10, PROP-06)
- [x] 08-17-PLAN.md — RECONCILIATION.md + CHANGELOG known-omissions + REQUIREMENTS codification (DOC-01, DOC-02)

> Note: plan numbers 08-06 and 08-07 are intentionally absent. The three proposer additions originally drafted as 08-05/06/07 were merged into 08-05 (single plan, 3 tasks) because all three modify the same file (`src/analyze/LlmClassifier.php`) and same-wave plans require zero file overlap.

---

### Phase 9: Migration Workflow Hardening & Page-rooted Introspection Audit

**Goal:** The operator workflow is release-safe, page-faithful, and generic across Lameco Kunstmaan sites: running the documented commands cannot silently do nothing, reruns cannot erase compiled mapping state, scoped migrations behave consistently, failures surface as failures, and a Kunstmaan Page's complete content graph is accounted for before it becomes a Craft Entry.

**Why this phase exists:** A post-audit review found that the plugin has broad implementation coverage but still has workflow-fidelity risks: the documented command sequence omits `compile`, `analyze` reruns can drop compiled mapping blocks, filter semantics drift across stages, CKEditor unresolved markers embed raw legacy URLs, `migrate --live` can finish with exit 0 after per-entry failures, transform characterization fixtures are empty, and CI/doc surfaces lag the real plugin state. The same audit also called for a deeper source/target introspection check: Kunstmaan Pages are the root of migration, so related page-parts, assets, page-owned relations, leaf entities, taxonomies, dataProviders, SEO, redirects, and CKEditor references must be traced from that root to their Craft result. This must not overfit to the current CQM rehearsal/install target (`~/Sites/cqm-craft-website`); the plugin goal is to be as generic as practical for any Kunstmaan website Lameco needs to migrate, including `~/Sites/simac-website` and `~/Sites/enreach-website`, while accepting that Kunstmaan → Craft migration will never reach perfect 100% automation.

**Requirements:** PH9-01..20 as defined by the verified Phase 9 plan set. Coverage spans workflow hardening, mapping preservation, filter consistency, Page-rooted introspection coverage, Craft target introspection, failure semantics, security hardening, fixture/CI/doc alignment, and genericity evidence.

**Success criteria (vision — refine in discuss/plan):**
1. Canonical docs and command behavior agree: `compile` is included, or `migrate` refuses/auto-compiles when runtime blocks are missing.
2. Re-running `analyze` after `compile` preserves all non-proposal mapping blocks (`nodeClasses`, `sections`, `sites`, `pageParts`, `taxonomies`, `dataProviders`, and future top-level blocks).
3. `MigrationFilters` have one authoritative interpretation across analyze, extract, transform, load, finalize, taxonomy, SEO, Retour, verify, and recovery commands; relation graph reachability is either wired or removed from the value object. _(Core D-14/D-15 covered by 09-02: source FQCN/basename normalization plus relationGraph injection into `MigrationFilters`.)_
4. Genericity is tested and documented as a first-class constraint: CQM remains the installed integration target, but the audit samples at least Simac and Enreach source shapes where available and identifies any project-specific assumptions, naming heuristics, hardcoded CQM patterns, or fields that need operator mapping rather than plugin logic.
5. Kunstmaan Page-rooted introspection has an explicit coverage report: for each Page class, list page parts, direct fields, ManyToOne/ManyToMany/OneToMany relations, asset references, dataProviders, taxonomy references, SEO rows, redirect/URL rows, CKEditor `[M]`/`[NT]`/media URLs, and the Craft target(s) or drop reason.
6. The Craft target introspection is checked against compiled mapping: every accepted target entry type, Matrix field, block type, field handle, asset field, Entries field source, and SEO/Retour adapter target is valid for the receiving Craft schema.
7. `migrate --live` still continues per-entry for diagnostics, but exits non-zero and writes a clear report when any entry/stage failed.
8. CKEditor unresolved markers cannot break HTML comments or inject renderable markup; regression tests include malicious legacy URL/comment-breakout cases. _(Covered by 09-06: unresolved marker source details now use recoverable unpadded base64url `sourceB64` payloads; tests cover `-->`, `<script`, and quote-looking URL values.)_
9. Transform characterization uses real fixtures or fails loudly in release/CI mode; incomplete taxonomy/leaf-entity coverage is either completed or explicitly excluded from v1.0.
10. CI smoke, README, PROJECT, ROADMAP, and CHANGELOG match the actual release workflow, generic plugin positioning, and known limitations.

**Plans:** 9 plans

Plans:
- `09-01-PLAN.md` — workflow safety: merge preservation and compiled-block preflight.
- [x] `09-02-PLAN.md` — source-domain filter core. Summary: `.planning/phases/09-migration-workflow-hardening-page-rooted-introspection-audit/09-02-SUMMARY.md`.
- `09-02B-PLAN.md` — Craft query translation from compiled mapping.
- `09-02C-PLAN.md` — cross-stage filter handoff and Page-owned sidecar closure.
- `09-03-PLAN.md` — compiler validation and Craft target introspection.
- `09-04-PLAN.md` — Page-rooted surface discovery and coverage report.
- `09-05-PLAN.md` — truthful migrate failure exit and referenced-only preload.
- [x] `09-06-PLAN.md` — CKEditor unresolved marker hardening. Summary: `.planning/phases/09-migration-workflow-hardening-page-rooted-introspection-audit/09-06-SUMMARY.md`.
- `09-07-PLAN.md` — docs, CI, release, fixture, and genericity evidence.

---

## Dependencies

- Phase 2 depends on Phase 1 (needs `doctor`, state table, legacy DB connection).
- Phase 3 depends on Phase 2 (needs `mapping.yaml` and filter spec).
- Phase 4 can start after Phase 3 begins — adapter and verify work is largely independent of ETL details.
- Phase 5 depends on Phase 3 + Phase 4 being feature-complete.
- Phase 8 depends on Phase 3 (Load stage + Asset/SEO/Redirect migration shape) + Phase 02.1 (KnowledgeBase + DoctrineEntityParser → fed to LLM for new proposers).
- Phase 9 depends on Phase 8 and the post-audit findings; it is the final hardening gate before v1.0 release rehearsal/tagging.

## Out-of-milestone (deferred)

`NEXT-01` (writer seam for starter-kit-driven Craft scaffolding), `NEXT-02` (read-only CP status mirror), `NEXT-03` (multi-provider AI), `NEXT-04` (cross-client rehearsal matrix) are explicitly out of v1.0 — see `PROJECT.md` Out of Scope.
