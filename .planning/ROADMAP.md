# Roadmap

5 phases. Coarse granularity. Built greenfield against the v1.x plugin as
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
- [ ] 04-02-seomatic-payload-builder-PLAN.md — SeomaticPayloadBuilder verbatim port (ADP-01)
- [ ] 04-03-verify-primitives-PLAN.md — SnapshotDiffer + SpotCheckUrlFetcher verbatim port (B1 fix preserved) (VER-02)
- [ ] 04-04-count-gate-and-baseline-counter-PLAN.md — CountGateService verbatim + BaselineCounterService shape-derived (D-59) (VER-01, VER-03)
- [ ] 04-05-cp-settings-template-PLAN.md — CP `_settings.twig` grouped sections + editable tables + masked API key (CFG-01)
- [ ] 04-06-seo-migration-service-PLAN.md — SeoMigrationService verbatim port (ADP-01)
- [ ] 04-07-redirect-migration-service-PLAN.md — RedirectMigrationService verbatim port + hardcoded site-handle reshape (ADP-02)
- [ ] 04-08-capture-baseline-html-service-PLAN.md — CaptureBaselineHtmlService verbatim port (VER-02)
- [ ] 04-09-verify-controller-and-plugin-wiring-PLAN.md — VerifyController + Plugin::config()/init() Phase 4 wiring (VER-01, VER-02, VER-03, ADP-01, ADP-02)
- [ ] 04-10-migrate-controller-extensions-PLAN.md — MigrateController actionSeo/actionRetour + verbosity + REPORT.md sections + asset RCA (ADP-01, ADP-02, CFG-02, CFG-03)
- [ ] 04-11-doctor-7th-8th-checks-PLAN.md — Doctor adapter health + verify baseline presence checks (ADP-01..03, VER-01)
- [ ] 04-12-tests-and-reconciliation-PLAN.md — PHPUnit unit tests + Phase 4 RECONCILIATION.md + ADP-03 composer-suggest guard (ADP-01..03, VER-01..03, CFG-02)

### Phase 5: Tests, Rehearsal & Release

**Goal:** Characterization tests on the Transform stage make regressions cheap to catch, the CI workflow gates every PR, and the v1.0 release ships green against the CQM rehearsal corpus.

**Requirements:** TST-01..04.

**Success criteria:**
1. PHPUnit unit suite covers `MigrationFilters`, `MappingLoader`, every field handler, `CkeditorRewriter`, and the heuristic proposer — minimum 70% line coverage on those modules.
2. Transform-stage characterization fixtures from a real CQM extract run end-to-end through the Transform stage and produce golden-file-stable output across runs.
3. CI workflow on `main` runs `composer validate --strict`, `phpunit`, and a plugin-load smoke test on a scratch Craft 5 install — green on the release commit.
4. v1.0 rehearsal log against the CQM dump records: counts within tolerance vs baseline, zero unresolved CKEditor tokens beyond annotated ones, all assets ingested or explicitly recorded as failed with RCA tags.

---

## Dependencies

- Phase 2 depends on Phase 1 (needs `doctor`, state table, legacy DB connection).
- Phase 3 depends on Phase 2 (needs `mapping.yaml` and filter spec).
- Phase 4 can start after Phase 3 begins — adapter and verify work is largely independent of ETL details.
- Phase 5 depends on Phase 3 + Phase 4 being feature-complete.

## Out-of-milestone (deferred)

`NEXT-01` (writer seam for starter-kit-driven Craft scaffolding), `NEXT-02` (read-only CP status mirror), `NEXT-03` (multi-provider AI), `NEXT-04` (cross-client rehearsal matrix) are explicitly out of v1.0 — see `PROJECT.md` Out of Scope.
