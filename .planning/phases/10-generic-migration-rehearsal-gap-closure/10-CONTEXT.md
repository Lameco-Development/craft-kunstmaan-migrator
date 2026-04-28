# Phase 10: Generic Migration Rehearsal Gap Closure - Context

**Gathered:** 2026-04-28
**Status:** Ready for planning

<domain>
## Phase Boundary

Phase 10 closes the generic release-rehearsal blockers surfaced by the first full CQM staging run. It does not add a broad new migration product surface; it hardens the existing page-driven Kunstmaan -> Craft workflow so a restored-backup rehearsal can complete with zero entry/stage failures, honest verification semantics, and no silent loss of page-owned content.

In scope:
- Generic fixes for the three failed entries: required Matrix block native titles, sparse-locale primary saves, and invalid section/entry-type routing.
- Generic fix for taxonomy-backed page relations resolving empty when relation state does not exist at transform/load time.
- PageBuilder Matrix ownership validation so invalid `pageBuilderHandle` propagation is prevented or visibly dropped.
- Verify/count semantics that compare honest like-for-like domains and stop producing false failures from mismatched baseline assumptions.
- Regression tests and a clean CQM rerun path after restoring the pre-live backup.

Out of scope:
- Building Craft schema from Kunstmaan.
- Full orphan media sync.
- Migration of unrelated Kunstmaan subsystems already declared known omissions.
- Broad relation `mode: embed | promote` architecture unless planning proves a minimal slice is necessary for the locked Phase 10 decisions.

</domain>

<decisions>
## Implementation Decisions

### Taxonomy relation timing and scope
- **D-01:** Taxonomy-backed page relations should be page-rooted by default. When migrating a page that references a taxonomy term, the migrator should find or create the matching Craft taxonomy entry in real time and link it, instead of relying solely on a separate preloaded taxonomy stage.
- **D-02:** Default taxonomy behavior is referenced-only: create/link only taxonomy rows referenced by migrated pages. This keeps the migration aligned with the page-driven model.
- **D-03:** Phase 10 should add an operator flag/settings path to include unreferenced taxonomy rows when a site wants the full taxonomy vocabulary migrated even if some terms are not linked by migrated pages.
- **D-04:** Dry-run taxonomy simulation and the exact breadth of deferred relation-token architecture are left to the planner, but the result must preserve the Phase 10 contract: referenced taxonomy relations must not silently resolve to empty after a clean live run.

### Fallback content semantics
- **D-05:** Missing taxonomy locale title/field values fall back to the default-language value. This applies when no per-locale source value exists; it avoids `[legacy id N]` or blank localized taxonomy entries where the source only has a default-language term.
- **D-06:** Matrix block title fallback mechanics are left to the planner. The constraint is strict: synthesize only generic validation-required values, never CQM-specific content.
- **D-07:** Sparse-locale entry primary-save mechanics are left to the planner. The solution must satisfy Craft's primary-site save requirement while preserving source truth and locale enablement as accurately as possible.
- **D-08:** Any synthesized or fallback value must be visible to the operator in migration report/log output. Validation-only fallback should not be invisible magic.

### Rehearsal success bar
- **D-09:** A clean Phase 10 live rehearsal requires zero entry/stage failures. Partial success with failed entries is not release-clean, even if the failures are reported.
- **D-10:** Every page-owned referenced content surface must migrate or be explicitly out-of-scope/dropped. Counts alone are not sufficient; entries, page parts, relations, assets, taxonomies, SEO, redirects, and CKEditor references must be accounted for under the Page-rooted contract.
- **D-11:** The closing proof for Phase 10 is a full workflow rerun after restoring the pre-live CQM backup. Focused entity reruns and unit tests are useful during implementation, but they are not the final gate.
- **D-12:** Verify count design is left to the planner, with one locked constraint: verify must compare like-for-like domains and stop producing false count failures caused by mismatched baseline semantics.

### Compile/load strictness
- **D-13:** Invalid mappings that would become live load failures must not reach `migrate --live` as runtime surprises. The planner may choose compile hard-blocking, migrate preflight blocking, or a narrow source-preserving fallback, but warnings alone are not enough.
- **D-14:** Unknown target fields or invalid pageBuilder ownership may be dropped only when the drop is visibly reported and the content has an alternate mapped home or is explicitly accepted as dropped/out-of-scope. Silent data loss is not acceptable.
- **D-15:** Broad fallback to `defaultEntryType` / default section must not hide bad mappings. Automatic fallback is acceptable only when it is generic, source-preserving, and unambiguous.

### the agent's Discretion
- Exact Matrix block title fallback format.
- Exact sparse-locale primary-save implementation.
- Whether taxonomy lazy find/create resolves during transform, load, or a small shared resolver seam.
- Dry-run simulation behavior for lazy taxonomy creation.
- Exact verify count model, as long as it is honest and documented.
- Exact split between compile failure, migrate preflight failure, and visible drop for invalid mappings.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Phase 10 scope and rehearsal findings
- `.planning/ROADMAP.md` §"Phase 10: Generic Migration Rehearsal Gap Closure" — phase goal, initial requirements, and success criteria.
- `.planning/STATE.md` §"Current focus" and §"Roadmap Evolution" — records why Phase 10 exists after the first CQM staging rehearsal.
- `.planning/PROJECT.md` §"Core Value", §"Migration model", §"Operator workflow", and §"Key Decisions" — page-driven model, genericity contract, CLI-only workflow, and runtime-zero-AI constraints.
- `CLAUDE.md` — locked repo rules and architectural ground rules.

### Prior decisions constraining Phase 10
- `.planning/phases/03-etl-pipeline-field-handlers/03-CONTEXT.md` — page-driven ETL, per-entry atomic load, continue-and-report failure model, mapping-driven Matrix/pagepart runtime.
- `.planning/phases/04-adapters-verify-settings/04-CONTEXT.md` — verify baseline shape and optional adapter/reporting patterns.
- `.planning/phases/08.5-fk-relation-introspection/08.5-CONTEXT.md` — `_rel:<property>.<column>` ManyToOne join idiom and relation graph artifact.
- `.planning/phases/08.7-leaf-entity-promote/08.7-CONTEXT.md` — current open findings around taxonomy relations, page-level relation gaps, compile validation, and page-wins behavior.
- `.planning/phases/09-migration-workflow-hardening-page-rooted-introspection-audit/09-CONTEXT.md` — genericity posture, Page-rooted content graph contract, no-silent-omission rule, and strict failure semantics.

### Implementation seams to inspect
- `src/load/EntryMigrationService.php` — primary-site save flow, Matrix payload normalization, unknown field filtering.
- `src/load/TaxonomyMigrationService.php` — current taxonomy migration service and locale fallback insertion point.
- `src/fields/handlers/RelationHandler.php` — current relation resolution through state table, including direct FK and join-table paths.
- `src/console/MigrateController.php` — stage orchestration and taxonomy/load/finalize ordering.
- `src/compile/MappingCompiler.php` — section/entry-type routing, pageBuilderHandle propagation, and compile validation insertion points.
- `src/compile/CraftTargetIntrospector.php` — current target validation warnings for invalid Craft target combinations.
- `src/console/CompileController.php` — current warning surface and potential hard-block/preflight boundary.
- `src/verify/BaselineCounterService.php` and `src/verify/CountGateService.php` — mismatched count-domain behavior to rework or document.
- `src/console/VerifyController.php` — baseline loading, expected-count translation, and report semantics.

### External rehearsal paths
- `~/Sites/cqm-craft-website` — installed Craft target and primary rehearsal environment.
- `~/Sites/cqm-craft-website/storage/backups/craft-starter-kit--2026-04-28-131310--v5.9.20.sql` — pre-live backup to restore before the closing rerun.
- `~/Sites/cqm-craft-website/storage/migration/REPORT.md` — first live rehearsal report with the three failed entries.
- `~/Sites/cqm-craft-website/storage/migration/VERIFY-2026-04-28--13-23-33.md` — verify-count evidence from the first rehearsal.
- `~/Sites/cqm-craft-website/storage/migration/page-rooted-coverage.json` — Page-rooted coverage evidence from Phase 9.
- `~/Sites/cqm-website`, `~/Sites/simac-website`, `~/Sites/enreach-website` — source-shape samples for genericity checks when needed.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `EntryMigrationService::saveEntryForSites()` already centralizes Craft entry creation/update, primary site selection, enabled-for-site seeding, and state recording; it is the likely seam for sparse-locale primary save behavior.
- `EntryMigrationService::normalizeMatrixPayload()` / `stripSourcePartRefs()` already normalizes Matrix block payloads before save; it is the likely seam for required block title fallback.
- `RelationHandler` already resolves relation IDs via state table and supports join-table expansion; Phase 10 can either extend this seam or add a taxonomy resolver wrapper without inventing unrelated relation machinery.
- `TaxonomyMigrationService` already knows how to create Craft taxonomy entries from taxonomy mappings; Phase 10 should reuse it for lazy find/create instead of duplicating taxonomy writes.
- `CraftTargetIntrospector` already detects invalid section/entry-type pairings; Phase 10 can promote load-fatal warnings to failures or feed them into a migrate preflight.

### Established Patterns
- Runtime migration is deterministic; AI remains analyze-only.
- Operator-reviewed mapping decisions are sacred, but invalid mappings must be surfaced before live load failures.
- Visible warning/report rows are required for best-effort skips and fallbacks.
- Page-driven migration intentionally ignores orphan media by default; referenced-only taxonomy behavior follows the same model, with an explicit opt-in for unreferenced taxonomy rows.
- CQM is the primary integration target, but CQM-only hardcoding is not acceptable.

### Integration Points
- Lazy taxonomy find/create likely connects `RelationHandler` or transform/load relation resolution to `TaxonomyMigrationService`.
- PageBuilder ownership validation belongs in `MappingCompiler` and/or `CraftTargetIntrospector`, before invalid fields are silently dropped by `EntryMigrationService`.
- Verify count semantics need an explicit domain decision at `VerifyController::baselineToExpectedCounts()`, `BaselineCounterService::captureSections()`, and `CountGateService::run()`.
- Closing rehearsal should run from the restored Craft backup through `doctor -> analyze -> map -> compile -> migrate --dry-run -> migrate --live -> verify`, using the corrected semantics.

</code_context>

<specifics>
## Specific Ideas

- The user prefers taxonomy relations to be resolved page-rooted: "Find or create" the taxonomy when migrating the page that uses it.
- The user accepts that referenced-only taxonomy migration means unlinked terms will not be created by default, but wants a flag/settings path to load all taxonomy rows when needed.
- The clean rerun must be strict: zero entry/stage failures, not a tolerated small number of failed entries.
- Verify counts are important only if they are honest; the planner may redesign the count model rather than preserve the current misleading baseline behavior.

</specifics>

<deferred>
## Deferred Ideas

- Full generic relation `mode: embed | promote` architecture remains deferred unless the Phase 10 planner proves a minimal part is required for referenced taxonomy relation correctness.
- Full orphan media import remains outside v1.0 and belongs to explicit recovery/sync tooling.
- Full migration of every unreferenced taxonomy row is opt-in via the new flag/settings path, not the default page-driven behavior.

</deferred>

---

*Phase: 10-generic-migration-rehearsal-gap-closure*
*Context gathered: 2026-04-28*
