# Phase 11: Dual Schema Walkers & LLM-first Mapping - Context

**Gathered:** 2026-04-28
**Status:** Ready for planning

<domain>
## Phase Boundary

Phase 11 replaces heuristic-led mapping context with explicit, symmetric walker outputs for both sides of the migration.

The Kunstmaan side starts from selected `Entity\Pages` roots and walks the full page-owned closure: direct columns, media FKs, Doctrine relations, pagepart contexts/classes, pagepart fields, pagepart relations, samples, and shared relation targets. The Craft side starts from candidate sections/entry types and walks field layouts, native fields, custom fields, Matrix fields, Matrix block entry types, nested block fields, Entries/Assets constraints, allowed sources/kinds, and validation-relevant requirements.

The proof scope is deliberately narrow: `NewsPage` proves relation-heavy behavior, especially `employee_id -> App\Entity\Employee` and shared target resolution; `HomePage` proves pagepart/Matrix-heavy behavior, including real pageparts, nested block fields, assets, ordering, and Matrix ownership.

This phase should produce graph artifacts and wire them into analyze/mapping/compile/load far enough to prove the architecture end-to-end for those two page types. It should not broaden into project-specific exception handling beyond exposing those cases as configurable mapping policy.

</domain>

<decisions>
## Implementation Decisions

### Graph Model
- **D-01:** Walker outputs should be normalized registries with stable references, not fully duplicated nested trees under every page or entry type.
- **D-02:** The Kunstmaan graph should declare shared structures once, with roots/usages pointing to them. Expected registries include page roots, Doctrine entities, pageparts, relations, media/assets, tables, and samples.
- **D-03:** The Craft graph should mirror that shape with entry roots/usages pointing to normalized entry types, fields, Matrix block entry types, relation targets, asset volumes, and constraints.
- **D-04:** The persisted artifacts must carry an explicit graph/schema version so future changes can be validated instead of silently changing the mapping contract.

### Walking Scope and Recursion
- **D-05:** Walking starts from scoped roots (`NewsPage`, `HomePage` for the proof) and recursively follows reachable structures.
- **D-06:** Recursion must be cycle-safe through a visited set and should expose a configurable max depth as an operator/developer safety valve.
- **D-07:** Shared relation targets such as `App\Entity\Employee` must be represented under their own identity, with inbound owner evidence from `NewsPage`, `EmployeePage`, or any other page that points at the same entity.
- **D-08:** Pageparts that are reusable across multiple pages must be declared once and referenced from each page/context usage; their field structure should not be re-declared repeatedly in page-local blobs.

### Mapping Role Split
- **D-09:** The LLM should receive the Kunstmaan and Craft graph pair as its primary mapping input and propose graph-to-graph mappings.
- **D-10:** Deterministic code remains responsible for candidate narrowing, exact/safe matches, validation, compile safety, load ordering, state-source contracts, and release blocking.
- **D-11:** Heuristics should become pre/post-processing helpers, not the primary source of truth for ambiguous mapping decisions.
- **D-12:** High-confidence LLM output should still pass deterministic validation before it can become executable mapping.

### Relation and Target Semantics
- **D-13:** Do not classify every non-page relation target as a taxonomy. `App\Entity\Employee` is the key counterexample: it is a rich reusable relation target that may map to Craft entries rather than taxonomy/category elements.
- **D-14:** Relation intent should be explicit per evidence-backed target: `reference`, `promote`, `embed`, `drop`, or `out_of_scope` are acceptable decision categories.
- **D-15:** A non-empty relation FK on an in-scope page must not disappear silently. Live migration should be blocked until the relation has a mapping, drop, or out-of-scope decision.
- **D-16:** Promoted/shared targets need their own extract/transform/load identity and state rows; owners should reference those state rows rather than embedding copied relation columns into every owner extract.
- **D-17:** Load order must create promoted/shared targets before owners that reference them, and reruns must remain idempotent through migration state.

### Content-only Pages and Project-specific Exceptions
- **D-18:** Content-only Kunstmaan pages with no real pageparts but WYSIWYG-like `content`, `body`, or `intro` columns should be exposed factually in the graph.
- **D-19:** Whether that content maps to a Craft pagebuilder block, a flat CKEditor field such as `ckeditorSimple`, a drop/out-of-scope decision, or a project override is mapping policy/configuration, not hardcoded generic behavior.
- **D-20:** Existing implicit-content behavior should be reconsidered during implementation: keep any useful candidate suggestion, but avoid hiding policy decisions inside generic analyzer logic.

### Proof Targets
- **D-21:** `NewsPage` is the relation-heavy proof. It must show direct columns, image FK fields, `employee_id -> App\Entity\Employee`, taxonomy/classifier-style relations, reachable target fields/samples, Craft candidates such as `caseTeamMembers` and `image`, and a promoted/shared target path where appropriate.
- **D-22:** `HomePage` is the pagepart/Matrix proof. It must show real pageparts, pagepart field structures, pagepart relations/assets, ordering evidence, target Matrix field ownership, allowed block entry types, and nested block fields.
- **D-23:** The phase should remain generic across CQM, Simac, Enreach, and future Lameco Kunstmaan sites. CQM is the proof environment, not a hardcoding source.

### the agent's Discretion
- Planner may split Phase 11 into multiple implementation plans if needed. Recommended split: graph contract/value objects, Kunstmaan walker, Craft walker, analyzer/prompt integration, compile/load validation for promoted targets, and CQM proof/tests.
- Planner may choose exact class/value-object names, but source-side classes/files should include `Kunstmaan` and target-side classes/files should include `Craft` to keep naming consistent.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Project Intent and Phase Scope
- `.planning/PROJECT.md` - original project goal, architectural ground rules, and release philosophy.
- `.planning/REQUIREMENTS.md` - requirement IDs and milestone acceptance context.
- `.planning/ROADMAP.md` - Phase 11 goal, requirements PH11-01..10, proof scope, and success criteria.
- `.planning/STATE.md` - current focus and recent decisions from the NewsPage/Employee rehearsal.

### Prior Phase Evidence
- `.planning/phases/10-generic-migration-rehearsal-gap-closure/10-01-SUMMARY.md` - compile/preflight safety and PageBuilder ownership validation context.
- `.planning/phases/10-generic-migration-rehearsal-gap-closure/10-03-SUMMARY.md` - taxonomy resolver and referenced-only default context.
- `.planning/phases/10-generic-migration-rehearsal-gap-closure/10-06-SUMMARY.md` - relation-shape diagnostics and page-rooted coverage evidence.
- `.planning/phases/10-generic-migration-rehearsal-gap-closure/10-VERIFICATION.md` - remaining human/release-owner evidence class before Phase 11.

### Existing Source-side Introspection Code
- `src/source/KunstmaanSourceScanner.php` - current source scan orchestrator for Doctrine entities, tables, joins, body columns, media FKs, and drift.
- `src/source/KunstmaanPageStructureScanner.php` - current page-root scanner for `Entity\Pages`, templates, contexts, and allowed pagepart classes.
- `src/source/DoctrineEntityParser.php` - current parser for Doctrine columns and relations.
- `src/source/KunstmaanKnowledgeBase.php` - current source-side LLM markdown surface.
- `src/analyze/KunstmaanSchemaDumper.php` - current source schema dump writer and scoped table filter.

### Existing Craft-side Introspection Code
- `src/source/CraftKnowledgeBase.php` - current Craft schema dump, entry type field index, Matrix catalog, flat handles, and target markdown.

### Pipeline Integration Points
- `src/console/AnalyzeController.php` - analyze orchestration and current artifact writer for `pageStructure.json`, `relation-graph.json`, `kunstmaan-schema.json`, and `craft-schema.json`.
- `src/console/CompileController.php` - compile-time schema consumption and validation entry point.
- `src/mapping/MappingFile.php` - mapping row shapes and atomic artifact writes.
- `src/mapping/MappingAuditor.php` - mapping audit/reporting seam.
- `src/mapping/BlockAvailabilityValidator.php` - existing Matrix ownership validation seam.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `KunstmaanSourceScanner::scan()` already produces the raw entity/table/join/body/media scan that a `KunstmaanPageWalker` can build on.
- `KunstmaanPageStructureScanner::scan()` already discovers page roots, pagepart contexts, and allowed pagepart classes, but does not yet emit a canonical recursive graph.
- `DoctrineEntityParser` already exposes columns and relations per FQCN; it is the likely source for walking direct properties and relation targets.
- `KunstmaanSchemaDumper` now writes scoped source schema data using Doctrine FQCN-to-table metadata, which should become part of or be fed by the new graph contract.
- `CraftKnowledgeBase::dumpTargetSchema()` now persists a target-side schema snapshot, but it remains catalog-shaped rather than a root-aware recursive Craft entry graph.
- `CraftKnowledgeBase` already has useful pieces for `CraftEntryWalker`: entry type field indexes, flat handle indexes, Matrix catalogs, and Matrix ownership lookups.

### Established Patterns
- Artifact writes should use `MappingFile::writeAtomicJson()` / `writeAtomic()` rather than raw file writes.
- All legacy-reading commands must remain protected by `NeverProductionTrait`.
- Optional SEOmatic/Retour behavior must stay runtime-detected, not hard composer requirements.
- Mapping decisions should preserve operator reviewability through `mapping.yaml`, audit reports, and explicit statuses.
- Source-side naming should include `Kunstmaan`; target-side naming should include `Craft`.

### Integration Points
- `AnalyzeController` is the first integration point: replace or supplement scattered schema artifacts with canonical graph outputs consumed by mapping.
- LLM prompt construction should move from separate knowledge-base snippets toward graph-pair context.
- `CompileController` should reject graph-incompatible proposals before they can become executable mappings.
- Load/migrate code must support promoted/shared targets before owner references when a relation is mapped as a target entry.
- Reporting must distinguish unresolved relation evidence from intentional `drop` or `out_of_scope` decisions.

</code_context>

<specifics>
## Specific Ideas

- The original target remains: "dump full Kunstmaan page schema + dump full Craft entry schema + LLM cleverness = mapping for data from Kunstmaan -> Craft."
- `NewsPage.employee_id -> App\Entity\Employee` and `EmployeePage.employee_id -> App\Entity\Employee` should converge on one shared target decision when both point at the same source entity.
- Extracted owner JSON should stay source-faithful: raw FK IDs and real pageparts only. Relation-expanded helper blobs such as `_rel:*` should not be treated as canonical owner data.
- Taxonomy/classifier-style entities such as `NewsCategory`, `CaseCategory`, and `Employee` need full introspection because each can have its own properties and may need different Craft target semantics.
- Pageparts can be shared across pages, so their structure belongs in a reusable graph registry with usage references from pages/contexts.

</specifics>

<deferred>
## Deferred Ideas

- Project-specific content-only page mapping policies, such as forcing a WYSIWYG field into a particular PageBuilder block versus a flat CKEditor field, should be represented as configuration/policy in or after Phase 11 but not hardcoded as generic behavior.
- Broadening proof beyond `NewsPage` and `HomePage` belongs after the Phase 11 architecture is working end-to-end for those two roots.

</deferred>

---

*Phase: 11-relation-target-introspection-promotion*
*Context gathered: 2026-04-28*
