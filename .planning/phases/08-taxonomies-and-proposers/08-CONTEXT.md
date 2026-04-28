# Phase 8: Taxonomies & AI Proposer Coverage - Context

**Gathered:** 2026-04-27
**Status:** Ready for planning

<domain>
## Phase Boundary

The analyze pipeline gains a third proposer surface (taxonomy candidates) plus two new in-scope proposer subjects (dataProviders + page-builder layout: headerBlock / bodyWrapBlock / bodyColumn). The load pipeline gains a `TaxonomyMigrationService` that consumes the taxonomy decisions. `mapping.yaml` gains a fourth row kind (`kind=taxonomy`) and a top-level `taxonomies:` block emitted by the compiler. CHANGELOG.md gets a "Known omissions in v1.0" section listing Kunstmaan surfaces this migrator deliberately does NOT cover (FormBundle, SearchBundle, MenuBundle, users / roles / ACLs, `kuma_translations`, media folder hierarchy, asset metadata, slug history).

This is the last gap-fill before v1.0 tag. Without these decisions, content-only sites work (Phase 7) but real-world Kunstmaan projects that use categories, dataProviders, or per-entry-type Matrix layouts still need extensive operator hand-authoring of `mapping.yaml`.

</domain>

<decisions>
## Implementation Decisions

### Taxonomy load target

- **D-01:** Taxonomies become Craft **Sections + Entry Types** (not Categories). Rationale: Craft 5+ unifies content under entries; v1's `TaxonomyMigrationService` already chose this; Categories are leaner than Entries and lose features (custom field layouts, drafts, revisions) that Kunstmaan taxonomy entities expect.
- **D-02:** Section-handle resolution uses the **same rule as `nodeClasses`**: LLM proposes `targetSection` + `targetEntryType` per FQCN, validated against the closed Craft entry-type catalog (Phase 6 / `craftKnowledgeBase->entryTypeHandles()` pattern). Operator overrides via `mapping.yaml`. No separate handle-derivation logic.
- **D-03:** Run order: **taxonomies migrate BEFORE pages**. Their state rows exist before any page's RelationHandler does the FK→entryId lookup. The regular `RelationHandler` resolves category FKs via the existing state-table — no `relation:deferred` surface, no finalize-pass deferred resolution. (Phase 4.1 / REC-02's deferred-relation marker stays unimplemented; Phase 8 does not need it.)
- **D-04:** Filter scoping: `--entities=NewsPage,CaseStudyPage` **auto-includes taxonomies referenced by allowed FQCNs** (relation-graph reachability). No new `--taxonomies=` flag — preserves the Phase 2 / D-12 three-flag cap (`--entities`, `--locales`, `--since`). Reachability is computed at extract-time from the FK index `MappingFilters` already exposes.

### Detection signal for taxonomies

- **D-05:** Detection is **LLM-proposed**, not heuristic-only. Extend the existing analyze proposer with a class for non-Page Doctrine entities. Input: `LegacyDbService`'s entity index + the `KnowledgeBase` markdown already produced for pageParts (no new KB surface needed).
- **D-06:** Output uses the **Phase 2 / D-02 confidence-tier ladder**: high → `status:accepted`; medium/low → `status:needs-review` (operator walks via `kunstmaan-migrator/map`); dropped → `status:dropped` with `reason`. Entities that are neither pages nor taxonomies (Settings, embedded VOs, ConfigBundle classes) emit as `status:dropped` with `reason="not-taxonomy-likely-supporting"` — consistent shape with column-row drops.
- **D-07:** New mapping row kind = **`kind=taxonomy`**. Mirrors `kind=nodeClass` exactly:
  - Row carries: `(fqcn, sourceTable, targetSection, targetEntryType, status, confidence, rationale)`.
  - **No nested `fields[]`** — that pattern is reserved for `kind=pagePart` (Phase 7).
  - Field-level mapping is inferred from matching `kind=column` rows on the same `sourceTable` — same convention `nodeClasses` already uses.
  - Identity tuple = `(kind, fqcn)`.
  - Skip-existing merge per MAP-04: operator decisions sacred.

### Translatable fields wiring

- **D-08:** **Verbatim port** of v1's `TaxonomyMigrationService` translation loop (`legacyDb->extTranslationsFor()` + per-locale Craft-entry copy). Phase 4 / D-54 verbatim-port discipline applies: write `RECONCILIATION.md` documenting v1 rule → v2 disposition. Restore `extTranslationsFor()` on `v2 LegacyDbService` (it was dropped in v2's slimmer port; D-54 says port what's needed verbatim).
- **D-09:** When `ext_translations` is **missing or empty** (monolingual Kunstmaan installs do not use Gedmo): treat the source-locale row as the only locale and **copy across every site in `mapping.sites`**. `doctor` emits a WARN line when the table is empty so the operator sees it; no hard-fail. Pragmatic default.
- **D-10:** Translatable-field auto-detection = **union of two signals**:
  1. **Source attribute** — `#[Gedmo\Translatable]` (and the `@Gedmo\Translatable` legacy annotation) captured by extending `DoctrineEntityParser` to scan the `Gedmo\Mapping\Annotation\*` namespace alongside `Doctrine\ORM\Mapping\*`. Phase 4.1 / SRC-20 (attributes-only) constraint applies — annotations only when they survive in the targeted projects.
  2. **Runtime signal** — actual rows in `ext_translations` for any instance of this entity (the empirical truth).
  Operator override via `taxonomies[fqcn].translatableFields[]` always wins (skip-existing).

### Layout / dataProvider proposer scope

- **D-11:** Both new proposers (page-builder layout + dataProviders) are **ON BY DEFAULT** with **heuristic-trigger gating**. Mirrors Phase 7's implicit-content emitter pattern (heuristic decides whether to even prompt the LLM). Default-on so the operator gets coverage automatically; gated so the LLM bill stays bounded.
- **D-12:** **Layout proposer trigger = Matrix catalog signal.** Fires when the parent entry-type's Matrix catalog contains a header-shaped or wrap-shaped block (Phase 6 closed-set validation pattern — Craft side is the canonical truth for what slots exist). LLM looks at the page-table columns to decide what fills `headerBlock` / `bodyWrapBlock` / `bodyColumn`. Resilient to project-specific column naming (CQM uses `banner_*`, others may use `hero_*` / `intro_*`).
- **D-13:** **dataProvider proposer trigger = orphan page-part detection.** Fires for any extracted page-part FQCN that does NOT match a standard Kunstmaan page-part pattern: no `kuma_page_part_refs` row references it AND its source table is not joined to `kuma_node_versions`. These are dynamic content classes the page-builder consumes via runtime injection.
- **D-14:** Operator escape hatch: **`Settings::proposeLayout` + `Settings::proposeProviders`** booleans (default `true`), with CLI mirrors `--no-layout` / `--no-providers`. Mirrors Phase 4.1 / ADP-04's `seoEnabled` / `retourEnabled` + `--no-seo` / `--no-retour` pattern exactly. The existing `--no-ai` blanket flag still disables every LLM call. Two new Settings fields surface in the CP `_settings.twig` "AI" group (Phase 4 / D-62).

### Claude's Discretion

The user explicitly deferred to my recommendation on three of the trigger/scope decisions (D-06, D-11, D-12, D-14). The reasoning trail above is what justified each pick — downstream agents are free to re-litigate any of them if research surfaces a stronger signal, but they should re-litigate via this CONTEXT.md, not silently.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Project ground rules
- `.planning/PROJECT.md` — single mapping.yaml with per-row status; runtime-zero-AI; atomic-always-on; verbatim-port discipline; never-production gate.
- `.planning/REQUIREMENTS.md` — MAP-04 (skip-existing merge), MAP-07 (audit), FILT-01..03 (filter VO + plumbing), LOC-01..02 (locale preflight).
- `.planning/ROADMAP.md` Phase 8 — phase boundary + success criteria.
- `.planning/STATE.md` Roadmap Evolution — why Phase 8 was added (post-Phase-7 coverage survey).

### Prior-phase decisions that constrain Phase 8
- `.planning/phases/02-schema-mapping-filters/02-CONTEXT.md` — D-02 (confidence-tier ladder: high → accepted, medium/low → needs-review, dropped → status:dropped); D-04 (skip-existing merge); D-12 (three-flag cap).
- `.planning/phases/02.1-source-introspection/02.1-CONTEXT.md` — DoctrineEntityParser source-truth; KnowledgeBase markdown surface fed to LLM; SRC-20 attributes-only constraint.
- `.planning/phases/03-etl-pipeline-field-handlers/03-CONTEXT.md` — D-46 verbatim-port discipline; RelationHandler state-table contract; topological run-order pattern.
- `.planning/phases/04-adapters-verify-settings/04-CONTEXT.md` — D-54 verbatim-port + RECONCILIATION.md; D-56 detection inside the adapter service; D-60 Settings + CLI override pattern; D-62 CP _settings.twig grouped sections.
- `.planning/phases/05-tests-rehearsal-release/05-CONTEXT.md` — D-10 unit test discipline (one test file per service); D-22 NeverProductionTrait deliberately omitted from RehearsalController.

### v1 brownfield reference (port targets)
- `~/Sites/craft-kunstmaan-migrator/src/bridge/load/TaxonomyMigrationService.php` (443 LOC) — verbatim-port target. Reads `kuma_*` taxonomy tables + `ext_translations`; upserts per-locale Craft entries.
- `~/Sites/craft-kunstmaan-migrator/src/kunstmaan/db/LegacyDbService.php` — restore `extTranslationsFor(string|array $fqcns, int $id): array` (currently absent in v2's slimmer port).

### Existing v2 surfaces to extend (NOT rewrite)
- `src/analyze/LlmClassifier.php` — extend with `proposeNonPageEntities()` proposer for taxonomies + non-page Doctrine entities.
- `src/source/DoctrineEntityParser.php` — extend Gedmo namespace scan (currently scans `Doctrine\ORM\Mapping\*` only).
- `src/compile/MappingCompiler.php` — add `compileTaxonomies()` private (mirror `compileImplicitBlocks` shape from Phase 7), emit `mapping.taxonomies` block.
- `src/console/CompileController.php` — surface `taxonomiesEmitted` counter alongside Phase 7's `implicitBlocksEmitted`.
- `src/mapping/MappingFile.php` — add `buildTaxonomyRow()` helper alongside `buildPagePartRow()`.
- `src/mapping/MappingAuditor.php` — extend audit to cover `kind=taxonomy` rows + the new `taxonomies:` block.
- `src/load/AtomicMigrationService.php` — wire taxonomies as a topological-pre stage to pages (run order from D-03).
- `src/models/Settings.php` — add `proposeLayout` (bool, default true) + `proposeProviders` (bool, default true).
- `src/console/AnalyzeController.php` — add `--no-layout` + `--no-providers` flags; thread through to LlmClassifier proposer dispatch.
- `src/templates/_settings.twig` — surface the two new Settings fields under the "AI" H2 group (Phase 4 / D-62).

### Test surfaces to mirror (Phase 7 patterns)
- `tests/integration/transform/TransformImplicitContentTest.php` — end-to-end loop closure pattern. Mirror for taxonomies: `tests/integration/load/TaxonomyMigrationTest.php`.
- `tests/unit/compile/MappingCompilerImplicitBlocksTest.php` — table-driven compile test pattern. Mirror for taxonomies + the two new proposers.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- **Phase 7 implicit-content shape** (`MappingCompiler::compileImplicitBlocks`, `ExtractService::buildImplicitContentPageParts`, `MappingFile::buildPagePartRow`) — proven pattern for "AI proposes structurally novel rows + compile bridges to runtime + extract synthesizes" loop. Phase 8's three new proposers should follow the same shape.
- **Phase 6 entity-level LLM proposer** (`AnalyzeController` step 7.5 + `LlmClassifier::proposeNodeClasses`) — extends to taxonomies almost verbatim: same prompt shape, same confidence-tier output, same closed-set validation against `craftKnowledgeBase->entryTypeHandles()`.
- **`KnowledgeBase` markdown rendering** (`renderPagesMarkdown` + `renderPagePartsMarkdown` in `src/source/KnowledgeBase.php`) — extend with `renderTaxonomiesMarkdown()` so the new proposer prompts get the same source-truth surface pages and pageParts already get.
- **`MigrationStateService`** site-agnostic state rows (`siteId: null`) — already supports the keying pattern v1's TaxonomyMigrationService used; no schema change.

### Established Patterns
- **Detection inside the service** (Phase 4 / D-56) — `TaxonomyMigrationService::migrate()` should short-circuit with a single WARN line when no `kind=taxonomy` rows are accepted in the mapping. Same pattern `SeoMigrationService` and `RedirectMigrationService` use today.
- **Settings + CLI override ladder** (Phase 4 / D-60) — operator escape hatches always have both layers. Phase 8's `proposeLayout` / `proposeProviders` follow this verbatim.
- **Compile-report counter** (Phase 7's `implicitBlocksEmitted`) — every compiler emission should expose its count. Phase 8 adds `taxonomiesEmitted`, `layoutBlocksEmitted`, `dataProvidersEmitted`.

### Integration Points
- `Plugin::config()` — register `TaxonomyMigrationService` as a Yii component. Wire `legacyDb` + `migrationStateService` slots same as `SeoMigrationService` does today.
- `MigrateController::actionIndex` — insert `taxonomies` stage in the wave order BEFORE `extract → transform → load → finalize`. Same surface as the existing `seo` / `retour` sub-actions get for resume / debug.
- `DoctorController` — add a 10th check for `ext_translations` presence (warn-only when empty, per D-09).

</code_context>

<specifics>
## Specific Ideas

- **CHANGELOG layout** — the user did not pick this; defer the doc location decision to plan-phase. Recommendation seeded: a "Known omissions in v1.0" section under the v1.0 entry in `CHANGELOG.md`. Cross-link from `README.md` Quickstart and from `PROJECT.md` Out of Scope. (Out-of-scope-features list already authored in the post-Phase-7 coverage survey transcript: Forms, Search, Menus, Users, `kuma_translations`, media folder hierarchy, asset metadata, slug history.)
- **Phase 7 precedent strength** — the user has now seen both implicit-content (Phase 7) and taxonomies (Phase 8) follow nearly identical "AI proposes a novel row kind + compile bridges + extract synthesizes" arcs. If the planner identifies a fourth proposer surface mid-Phase-8, treat it as a candidate v1.1 phase rather than scope creep into Phase 8.
- **Verbatim-port discipline reminder** — D-08's "verbatim port" of v1's TaxonomyMigrationService means `RECONCILIATION.md` is mandatory in this phase. v1 → v2 differences expected: single mapping.yaml (vs v1's three files); atomic-always-on; LegacyDbService surface restoration. List every dropped-or-reshaped rule.

</specifics>

<deferred>
## Deferred Ideas

- **Asset folder hierarchy preservation** — Kunstmaan's `kuma_media_folder` parent_id chain is dropped at migrate time (assets land flat). Listed in CHANGELOG known-omissions. Could become a v1.1 phase if a real project demands it.
- **Asset metadata** (alt text, copyright, focal point) — same as above; listed but deferred.
- **Slug history mining** — beyond `kuma_redirects`, Kunstmaan tracks slug changes that could feed Retour as historical redirects. Deferred.
- **Page drafts / non-public versions** — `streamLiveNodes` filters `online=1 AND public_node_version_id`; drafts are explicitly skipped by design (carryover from v1). Listed in CHANGELOG. Not deferred — explicitly out of scope forever.
- **Form / Search / Menu / User migration** — never been in v1 OR v2 scope. Listed in CHANGELOG. Stays out.
- **`relation:deferred` finalize-pass** — Phase 4.1 / REC-02 left this unimplemented because no concrete need surfaced. Phase 8 / D-03 confirms taxonomies don't need it (run-before-pages avoids the deferred-relation case). Continue to defer.

</deferred>

---

*Phase: 08-Taxonomies-and-Proposers*
*Context gathered: 2026-04-27*
