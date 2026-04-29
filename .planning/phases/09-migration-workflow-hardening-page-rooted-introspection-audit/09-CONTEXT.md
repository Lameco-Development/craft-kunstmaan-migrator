# Phase 9: Migration Workflow Hardening & Page-rooted Introspection Audit - Context

**Gathered:** 2026-04-28
**Status:** Ready for planning

<domain>
## Phase Boundary

Phase 9 is the v1.0 hardening gate. It does not add a broad new migration product surface; it makes the existing page-driven Kunstmaan -> Craft migrator safe, honest, generic, and auditable before release.

This phase delivers:

1. A release-safe operator workflow: the documented command sequence and runtime behavior agree, `migrate` cannot silently succeed with missing compiled runtime blocks, rerunning `analyze` cannot erase compiled mapping state, and per-entry failures produce a non-zero run outcome.
2. A generic Page-rooted introspection audit: for each Kunstmaan Page, the audit traces the complete page-owned content graph into the Craft Entry result or an explicit drop/out-of-scope reason.
3. Cross-stage filter consistency: `--entities`, `--locales`, `--since`, and relation-graph reachability mean the same thing across analyze, compile, extract, transform, load, finalize, taxonomy, SEO, Retour, verify, and recovery surfaces.
4. Security and release evidence: CKEditor unresolved markers are comment-safe, asset preload semantics match the page-driven model, transform/compile fixtures are meaningful, and CI/docs match the real release workflow.
5. Genericity checks against multiple Lameco Kunstmaan source shapes. CQM remains the installed Craft integration target (`~/Sites/cqm-craft-website`), but Simac (`~/Sites/simac-website`) and Enreach (`~/Sites/enreach-website`) are used as read-only source-shape samples to catch CQM-only assumptions.

Out of scope for Phase 9:

- Building a Craft site from scratch or generating Craft schema.
- Migrating every Kunstmaan subsystem. FormBundle, SearchBundle, MenuBundle, users/roles/ACLs, `kuma_translations`, full media folder hierarchy, full asset metadata, drafts/non-public versions, and full slug-history mining remain v1.0 known omissions unless an existing in-scope page-owned path already handles them.
- A generic "sync all orphan media" pass. Referenced assets are in scope; orphan media remains `NEXT-05` / recovery territory.
- A full UI/CP operator surface. CLI remains canonical.

</domain>

<decisions>
## Implementation Decisions

### Genericity and rehearsal posture

- **D-01:** Treat CQM as the primary integration/rehearsal target because the plugin is installed in `~/Sites/cqm-craft-website`, but do not accept CQM-specific heuristics as generic unless they also make sense against Simac and Enreach source shapes or are explicitly framed as operator-curated mapping decisions.
- **D-02:** Use Simac and Enreach as read-only source-shape samples during research/planning. The planner should inspect entity/page/pagepart/relation naming patterns and note assumptions, but must not require those projects to have a configured Craft target or runnable migration environment.
- **D-03:** "Generic" does not mean 100% automatic. The correct contract is: every page-owned source surface is either migrated, visibly dropped with rationale, or explicitly declared out of scope. Operator review and mapping edits are valid outcomes; silent omissions are not.
- **D-04:** Do not embed private source code or project-specific secrets from CQM/Simac/Enreach into committed artifacts. Planning docs may cite paths and summarize structural patterns, not copy proprietary implementation bodies.

### Page-rooted content graph contract

- **D-05:** The Kunstmaan Page is the migration root and the Craft Entry is the result. The Phase 9 audit should start at accepted `nodeClasses` Page mappings and trace outward through direct detail columns, page parts, implicit content rows, ManyToOne relations, ManyToMany/OneToMany child tables, asset references, taxonomy/dataProvider/leaf entities, SEO rows, redirect/URL rows, and CKEditor `[M]` / `[NT]` / media URL references.
- **D-06:** The audit output should be a deterministic artifact under the Phase 9 work or migration storage surface that answers, per Page class: "What source surfaces did we find?", "Which Craft target receives them?", and "If not migrated, why not?" This artifact is for release confidence and operator review; it should be text/JSON friendly and diffable.
- **D-07:** Page-owned relations default to "page wins" when a Kunstmaan Page wraps a 1:1 content entity and the Craft model has a corresponding Page entry type. The related entity's fields should be folded into the Page result via `_rel:<property>.<column>` or equivalent mapping unless the operator mapping explicitly promotes the related entity to its own Craft entry/relation.
- **D-08:** Full generic `mode: embed | promote` architecture is allowed only if planning proves it is necessary to make existing in-scope Page-rooted migration reliable. Otherwise Phase 9 should implement the smallest generic hardening needed: clear audit flags, compile/type validation, relation handler options, and operator-curatable mapping rows.
- **D-09:** Page-level ManyToMany coverage is in scope when the join table is directly page-owned and feeds a Craft relation field (for example NewsPage -> NewsCategory). Phase 8.7 covered pagepart relations; Phase 9 should close the equivalent page-level relation gap or explicitly mark each unsupported relation shape in the coverage artifact.

### Workflow hardening

- **D-10:** `compile` must be part of the canonical operator workflow. README/PROJECT/release docs should show `doctor -> analyze -> map -> compile -> migrate --dry-run -> migrate --live -> verify`.
- **D-11:** `migrate` should fail fast with a clear actionable message when required compiled runtime blocks are missing or stale enough to produce an obvious no-op (`nodeClasses`, `sections`, `sites`, and relevant compiled blocks such as `pageParts`, `taxonomies`, `dataProviders`). Do not silently auto-compile during `migrate`; compilation can alter operator-reviewed structures and should remain an explicit command.
- **D-12:** `MappingFile::merge()` must preserve all existing top-level mapping blocks and metadata when merging new `proposals[]`. Existing operator decisions and compiled blocks are sacred unless a command explicitly overwrites them.
- **D-13:** Compile-stage validation should reject or loudly drop incompatible handler/source/target combinations that would otherwise create silent empty output. Examples from current Phase 8.7 findings: Matrix handler on scalar longtext, dropdown handler fed by arbitrary title text, relation handler without enough state/join information.

### Filter semantics

- **D-14:** The canonical meaning of `--entities` is Kunstmaan source entity identity. Accept both FQCN and basename at CLI boundaries, normalize internally, and translate to Craft entry-type handles only at Craft-query surfaces such as finalize/verify.
- **D-15:** `MigrationFilters::relationGraph` should either be fully wired from source/relation artifacts into `FilterFactory` or removed from behavior. The current half-wired state is misleading because `MigrationFilters` advertises reachability while `FilterFactory` constructs an empty graph.
- **D-16:** Scoped runs must be closed over page-owned dependencies. If an operator runs `--entities=NewsPage`, the migration should include the assets, taxonomies, page-level relations, pageparts, SEO/redirect sidecars, and CKEditor targets needed by those NewsPage entries, unless explicitly dropped/out-of-scope.
- **D-17:** Verify/count filters must compare like with like. Do not compare Craft section handles directly to Kunstmaan source entity filters without a mapping translation step.

### Failure, security, and asset behavior

- **D-18:** Per-entry load should continue after individual entry failures for diagnostics, preserving Phase 3's continue-and-report behavior, but the command must return non-zero when any entry or stage failed. Partial success is not a green run.
- **D-19:** CKEditor unresolved markers must never include raw legacy URLs inside HTML comments. Use a comment-safe encoding (for example base64url or another delimiter-safe representation) plus regression tests for `-->` breakout and markup-looking URL cases.
- **D-20:** `--preload-assets` must match the page-driven migration model. It should preload only assets referenced by in-scope entries or be renamed/reframed. The preferred fix is referenced-only preload; full `kuma_media` ingestion belongs to explicit recovery/sync tooling, not the default migration path.
- **D-21:** Silent best-effort skips are acceptable only when they are also visible in a warning/report/audit artifact. This applies especially to relation joins, invalid source/target identifiers, missing target rows, and unsupported relation shapes.

### Tests, fixtures, and release evidence

- **D-22:** Transform characterization fixtures must be non-empty and representative before v1.0. If real fixtures cannot be committed because of privacy, add deterministic synthetic fixtures plus a release/rehearsal check that fails loudly when no real rehearsal fixture set has been captured.
- **D-23:** Phase 9 tests should prioritize regression coverage for the actual audit findings: merge preservation, missing compile/no-op prevention, filter normalization/translation, CKEditor marker escaping, non-zero load exit on recorded failures, referenced-only preload, and Page-rooted relation closure.
- **D-24:** CI smoke should reflect the real minimum runtime configuration for `doctor` and the release workflow. Do not keep a green CI path that calls a command with impossible/default-missing configuration and therefore tests the wrong thing.
- **D-25:** Documentation should be honest about partial automation: generic Kunstmaan -> Craft migration is the goal, but some project-specific mapping is expected. The docs should teach operators how to read the Page-rooted coverage report and decide whether a missing surface is acceptable.

### the agent's Discretion

- The exact artifact names and formats for the Page-rooted coverage report and any supporting JSON are left to the planner, as long as they are deterministic, diffable, and referenced from docs/release checklist.
- The planner may split Phase 9 into audit-first then fix waves, or interleave fixes with tests, but must keep workflow-safety fixes ahead of release/rehearsal validation.
- The planner may decide whether `compile` staleness detection is timestamp/hash based or structural-presence based. The minimum accepted behavior is "missing compiled blocks cannot produce a successful zero-row migrate."
- The planner may choose how much direct Simac/Enreach inspection is needed. At minimum, it should sample source entities/pageparts/relations enough to identify genericity risks and avoid CQM-only assumptions.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Phase 9 scope and project ground rules

- `.planning/ROADMAP.md` §"Phase 9: Migration Workflow Hardening & Page-rooted Introspection Audit" — phase boundary, success criteria, genericity constraint, and audit seed backlog.
- `.planning/STATE.md` §"Current focus" and §"Roadmap Evolution" — records the user's genericity clarification and the installed CQM target path.
- `.planning/PROJECT.md` §"Core Value", §"Migration model", §"Operator workflow", and §"Key Decisions" — page-driven model, referenced-assets-only posture, CLI-only operator surface, runtime-zero-AI, NeverProductionTrait, and known v1 architectural decisions.
- `.planning/REQUIREMENTS.md` — especially FILT-01..03, SRC-01..20, ETL-01..07, FH-01..04, FIN-01..02, ADP-01..04, VER-01..04, CFG-01..07, REC-01..02, TST-01..03.
- `CLAUDE.md` — locked repo rules and architectural ground rules for this plugin.

### Prior decisions that constrain Phase 9

- `.planning/phases/02-schema-mapping-filters/02-CONTEXT.md` — filter flag cap and merge semantics; Phase 9 may harden semantics but should not add casual new flags.
- `.planning/phases/02.1-source-introspection/02.1-CONTEXT.md` — source path required, Doctrine/parser source truth, pageStructure, pagepart row shape, reconciliation discipline.
- `.planning/phases/03-etl-pipeline-field-handlers/03-CONTEXT.md` — page-driven ETL, continue-and-report per-entry failures, atomic-always-on, mapping-driven Matrix/pagepart runtime, truncate safety rails.
- `.planning/phases/04-adapters-verify-settings/04-CONTEXT.md` — optional SEO/Retour detection and Settings+CLI override pattern.
- `.planning/phases/05-tests-rehearsal-release/05-CONTEXT.md` — transform characterization fixture discipline and release/CI expectations.
- `.planning/phases/08-taxonomies-and-proposers/08-CONTEXT.md` — taxonomy/dataProvider/layout proposer decisions, taxonomies-before-pages run order, relation-graph auto-include intent.
- `.planning/phases/08.5-fk-relation-introspection/08.5-CONTEXT.md` — `_rel:<property>.<column>` ManyToOne join idiom and relation-graph artifact.
- `.planning/phases/08.7-leaf-entity-promote/08.7-CONTEXT.md` — current open findings around embed/promote, compile type validation, page-level M2M, filter-shape inconsistencies, and "pages lead" behavior.

### Current implementation seams to inspect

- `src/console/CompileController.php` — explicit compile bridge; proves missing compile can yield zero extracted rows.
- `src/compile/MappingCompiler.php` — compiled runtime block emission, relation handler options, and type/source validation insertion point.
- `src/mapping/MappingFile.php` — merge preservation bug and mapping row identity semantics.
- `src/console/AnalyzeController.php` — relation graph emission, proposer dispatch, and mapping merge/write flow.
- `src/filter/MigrationFilters.php` and `src/filter/FilterFactory.php` — relationGraph reachability contract vs actual construction.
- `src/extract/ExtractService.php` — Page-rooted extraction, `_rel:` join behavior, pagepart/implicit content inclusion, relation skip visibility.
- `src/transform/TransformService.php` — maps extracted Page-rooted payloads into Craft entry payloads.
- `src/load/AtomicMigrationService.php` — per-entry atomic save and JIT asset resolution.
- `src/console/MigrateController.php` — workflow orchestration, `runLoadFromDisk()` exit semantics, preload-assets path, finalize/filter behavior.
- `src/load/TaxonomyMigrationService.php` — taxonomy load and filter/fallback behavior.
- `src/load/AssetMigrationService.php` — `--preload-assets` referenced-only vs all-`kuma_media` behavior.
- `src/finalize/CkeditorRewriterService.php` — unresolved marker escaping.
- `src/verify/CountGateService.php` and verify controllers/services — filter translation and count parity behavior.
- `.github/workflows/ci.yml` — CI smoke alignment with real `doctor` requirements.

### External rehearsal/sample paths

- `~/Sites/cqm-craft-website` — installed Craft target and primary integration/rehearsal environment.
- `~/Sites/cqm-website` — CQM Kunstmaan source shape and existing rehearsal source reference.
- `~/Sites/simac-website` — read-only Kunstmaan source-shape sample for genericity checks.
- `~/Sites/enreach-website` — read-only Kunstmaan source-shape sample for genericity checks.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets

- `CompileController` already documents the core workflow risk: without compiled `nodeClasses` / `sections` / `sites`, migrate extracts zero rows. Phase 9 can reuse that structural knowledge for a migrate preflight.
- `MappingFile::identityKey()` and existing row-kind handling already protect operator decisions at row level. The missing piece is preserving non-`proposals` top-level blocks in `merge()`.
- `MigrationFilters::allows()` already implements graph reachability. Phase 9 should wire graph construction rather than invent a second reachability model.
- `ExtractService::joinManyToOneRelations()` already emits `_rel:` keys for ManyToOne relations. Page-level M2M/OneToMany closure should build on this naming/visibility convention.
- `MigrationReport::recordFailure()` and MigrateController's per-entry failure loop already collect failures. Phase 9 can change final exit semantics without abandoning diagnostic continuation.
- Existing optional adapter services already warn-and-skip when unavailable. Page-rooted audit should classify SEO/Retour absence as a visible skip, not a silent omission.

### Established Patterns

- NeverProductionTrait gates every legacy-reading/destructive command first.
- Mapping/operator decisions are sacred: skip-existing merge and explicit `--overwrite` are the pattern.
- CLI flags generally have Settings mirrors when they represent reusable operator preferences; one-off hardening checks should avoid new flags unless there is a clear operator need.
- v1 brownfield behavior is authoritative where v2 intended a port. When Phase 9 changes behavior, document why it is hardening rather than accidental drift.
- Runtime-zero-AI remains locked. Phase 9 may improve analyze/compile prompts and deterministic validators, but migrate/load/finalize/verify must stay deterministic.

### Integration Points

- `MigrateController::actionIndex()` needs a preflight before extract/load and a final "fail if report has failures" gate after all diagnostic continuation is complete.
- `FilterFactory::fromCli()` is the right boundary for normalization and relationGraph injection, but may need additional inputs or a helper loaded from `relation-graph.json` / source scanner artifacts.
- `MappingCompiler` is the right location for compile-time type/source/target validation because it sees accepted mapping rows before runtime handlers silently no-op.
- A Page-rooted coverage report can be produced during `analyze` or `compile`; `compile` is likely better if the report must compare accepted source mappings to real Craft targets.
- Verify/finalize Craft queries need a translation layer from source entity filters to Craft entry-type/section handles via compiled mapping.

</code_context>

<specifics>
## Specific Ideas

- The user explicitly clarified that the plugin should work "in the best possible way" for any Lameco Kunstmaan website, including Simac and Enreach. This should guide every Phase 9 decision: prefer source/Craft introspection and operator-visible mapping over hardcoded CQM naming rules.
- The user accepts that Kunstmaan -> Craft will never be 100%. The planning bar is not perfect automation; it is reliable accounting, visible trade-offs, and enough generic migration machinery that the operator is not surprised.
- Current local path check confirmed all four named paths exist: `~/Sites/cqm-craft-website`, `~/Sites/cqm-website`, `~/Sites/simac-website`, and `~/Sites/enreach-website`.
- No matching pending SQL todos were found for Phase 9 at context time.

</specifics>

<deferred>
## Deferred Ideas

- Full orphan media import remains `NEXT-05` / recovery-tool territory, not Phase 9.
- Full generic embed/promote architecture for every relation shape can become a later phase if Phase 9's audit shows operator-curated mapping is insufficient.
- Craft schema generation / starter-kit writer seam remains v2, not Phase 9.
- Migration of non-content Kunstmaan subsystems (Forms, Search, Menus, Users/ACLs) remains out of v1.0.

</deferred>

---

*Phase: 09-migration-workflow-hardening-page-rooted-introspection-audit*
*Context gathered: 2026-04-28*
