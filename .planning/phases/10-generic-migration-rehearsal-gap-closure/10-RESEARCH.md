# Phase 10: Generic Migration Rehearsal Gap Closure - Research

**Researched:** 2026-04-28
**Domain:** Craft CMS 5 migration hardening for page-driven Kunstmaan -> Craft plugin
**Confidence:** High for code seams and failure modes; medium for final verify-count model because Phase 10 context leaves exact semantics to planning discretion.

## Summary

Phase 10 should be a targeted hardening phase around existing seams, not a broad architecture rewrite. The first CQM staging rehearsal produced three entry failures and one relation-class data-loss issue:

1. ContactPage-style payloads can produce Matrix blocks whose Craft entry type requires a native `title`, while source content only populates block fields.
2. TextPage-style sparse-locale payloads can contain only a non-primary Craft site, while `EntryMigrationService` must save the Craft primary site first.
3. VacancyFormPage-style mappings can pair a section with an entry type that exists in Craft but is not allowed by that section, causing a live `typeId` validation failure.
4. Taxonomy-backed page relations can resolve empty when relation lookup depends on state rows that do not exist yet.

The minimal generic strategy is:

- Move validation-required fallbacks to the latest safe generic boundary before Craft validation.
- Move load-fatal target errors earlier into compile/preflight.
- Reuse existing taxonomy migration/state logic for page-rooted lazy taxonomy find/create.
- Make every fallback/drop visible in reports or logs.
- Keep full unreferenced taxonomy import opt-in; referenced-only is the default.
- Make verify count domains explicit instead of treating pre-migration Craft counts as source-parity expectations.

## User Constraints from Context

- Runtime migration remains deterministic and zero-AI.
- Page-rooted lazy taxonomy find/create is the default: when a migrated page references a taxonomy term, find or create the Craft taxonomy entry and link it.
- Referenced-only taxonomy migration is the default.
- Add a flag/settings path to include unreferenced taxonomy rows when operators want the full vocabulary.
- Missing taxonomy locale title/field values fall back to the default-language value.
- Matrix and sparse-locale fallback details are planner discretion, but must be generic and source-preserving.
- Fallback usage must be visible in report/log output.
- A clean live rehearsal requires zero entry/stage failures.
- Every page-owned referenced surface must migrate or be explicitly dropped/out-of-scope.
- The closing gate is a full CQM rerun after restoring the pre-live backup.
- Verify must compare like-for-like domains and stop producing false failures from mismatched baseline semantics.
- Warnings alone are insufficient for mappings that would become live load failures.

## Architectural Responsibility Map

| Capability | Primary seam | Secondary seam | Rationale |
|---|---|---|---|
| Matrix block title fallback | `EntryMigrationService::normalizeMatrixPayload()` / `stripSourcePartRefs()` | Transform payload shape | All entry field values pass through load-side Matrix normalization before `setFieldValues()` and `saveElement()`. |
| Sparse-locale primary save fallback | `EntryMigrationService::saveEntryForSites()` | Transform site payloads | Primary-site selection, enabled-for-site map, first save, and localized saves are centralized here. |
| Section + entry-type compatibility | `CraftTargetIntrospector::validate()` + `CompileController::actionIndex()` | Migrate preflight | Current code detects invalid section/type pairs but only warns. Load-fatal categories should block before live migration. |
| Lazy taxonomy find/create | `TaxonomyMigrationService` shared resolver method | `RelationHandler` or load-time relation resolver | Taxonomy service already owns section/type lookup, entry save, locale fallback, and state recording. Relation handler already resolves via state. |
| Full unreferenced taxonomy import opt-in | `Settings`, `MigrateController`, `TaxonomyMigrationService::migrateAll()` | CLI options | Existing full taxonomy stage can be reused, but should no longer be the default page-driven behavior. |
| PageBuilder ownership validation | `MappingCompiler` propagation + `CraftTargetIntrospector` | Transform flat fallback | `pageBuilderHandle` should only propagate when the target entry type owns that Matrix field. |
| Verify count semantics | `VerifyController::baselineToExpectedCounts()`, `BaselineCounterService`, `CountGateService` | Verify report renderer | Current baseline capture and verify compare different domains; report labels should make domains explicit. |
| Rehearsal proof | Documentation/runbook + CQM target | CLI workflow | Phase 10 closes only after restored-backup full workflow reaches zero failures. |

## Recommended Plan/Wave Breakdown

### Wave 1: Compile/preflight safety and structural regression fixtures

Plan first because it prevents known bad mappings from reaching live load while other fallback work is still in progress.

- Extend target validation so load-fatal Craft target mismatches are distinguishable from advisory warnings.
- Block or preflight-fail invalid section + entry-type combinations such as `contentPages` + `formContentBlock`.
- Validate pageBuilder Matrix ownership before propagating `pageBuilderHandle`.
- Preserve page-owned content via existing flat fallback only when a valid alternate home exists.
- Add sanitized structural test fixtures for the three failed entry classes without committing proprietary content values.

### Wave 2: Load fallback hardening

Plan second because it directly fixes two of the three entry failures once invalid mappings are blocked before load.

- Add generic Matrix native-title fallback in `EntryMigrationService` normalization.
- Add sparse-locale primary-save fallback before primary `applyPerSiteData()`.
- Ensure fallback usage is reported or logged through existing migration-report seams.
- Keep behavior generic: no CQM-specific block type names or page IDs.

### Wave 3: Page-rooted taxonomy resolver and opt-in full taxonomy import

Plan third because it changes default taxonomy semantics and must preserve idempotency.

- Extract or expose a taxonomy find/create method from `TaxonomyMigrationService`.
- Call it from the relation-resolution path when a taxonomy-backed relation misses the state table.
- Record state rows with the same semantics as existing taxonomy migration.
- Preserve default-language locale fallback for missing taxonomy translations.
- Add settings/flag wiring for unreferenced full taxonomy import.
- Decide and document dry-run behavior. Recommended default: no writes in dry-run; report would-create terms and would-link relation counts.

### Wave 4: Verify semantics and restored-backup rehearsal runbook

Plan last because it validates the corrected pipeline and clarifies release evidence.

- Split or label verify count domains so pre-migration Craft baseline counts are not treated as source migration expectations.
- Prefer transformed-payload/state-table counts for migration-created source parity, and keep Craft baseline counts as baseline/current Craft drift checks.
- Update report labels and tests for false-failure shapes.
- Add restore-from-backup CQM runbook and inspection gates.
- Closing gate: restore pre-live backup and run `doctor -> analyze -> map -> compile -> migrate --dry-run -> migrate --live -> verify`.

## Scope Item Guidance

### Matrix block title fallback

Best seam: `src/load/EntryMigrationService.php`.

Current normalization strips `_sourcePartRef` and lifts nested `title`/`heading` out of `fields`, but it does not synthesize a missing native title. Add a generic fallback for Matrix blocks that lack peer-level `title` before Craft save. The fallback should be deterministic, validation-only, and not CQM-specific. Candidate format is planner discretion, but should be stable enough for tests.

Tests:
- Matrix payload block with `type`, `enabled`, and `fields`, but no peer `title`, receives a non-empty peer title.
- Fields remain unchanged except `_sourcePartRef` stripping and native-key lifting.
- Test uses arbitrary field/block handles, not CQM names.

### Sparse-locale primary save fallback

Best seam: `src/load/EntryMigrationService.php`.

`saveEntryForSites()` selects the Craft primary site, then currently reads `$perSite[$primarySite->handle] ?? []`. If transformed payload only has `en`, primary save applies empty data and can fail blank title/slug validation. Add a best-available locale fallback before primary `applyPerSiteData()` when primary data is missing or validation-empty.

Key constraints:
- Preserve the original enabled-for-site truth as accurately as possible.
- Do not pretend a primary locale existed in source if it did not.
- Save localized site payloads normally after primary save.
- Report fallback usage.

Tests:
- Configured sites include primary `default`; payload only has `en`.
- Primary save input receives non-empty title/slug from best available locale.
- Enabled map remains based on source data, not on fallback presence alone.

### Section + entry-type compatibility

Best seams: `src/compile/CraftTargetIntrospector.php`, `src/console/CompileController.php`, and possibly a migrate preflight in `src/console/MigrateController.php`.

`CraftTargetIntrospector` already detects `section does not allow entryType`, but the controller prints warnings. Phase 10 should classify this as load-fatal. Acceptable behaviors:

- Compile hard-fails.
- Or compile emits structured fatal validation and `migrate --live` preflight hard-fails.
- Or planner chooses narrow automatic fallback only if it is compatible, generic, and source-preserving.

Warnings alone are not acceptable for this category.

Tests:
- Compiled mapping with section `contentPages` and entry type `formContentBlock` blocks before load.
- Advisory target warnings remain warnings only if they cannot cause live load failure.

### PageBuilder ownership validation

Best seam: `src/compile/MappingCompiler.php`.

`MappingCompiler` propagates `targetMatrixField` from accepted page-part rows to `nodeClasses[fqcn].pageBuilderHandle`. It must verify the chosen target entry type actually owns that Matrix field before propagation. Invalid ownership should be a visible warning/failure or a visible drop with flat fallback, depending on data-loss risk.

Tests:
- A page-part row targeting a Matrix field not owned by the parent entry type does not set `pageBuilderHandle`.
- If `flatPagePartContent` or equivalent flat fallback exists, content remains mapped there.
- If no alternate home exists, the invalid target is surfaced as a blocking or visible data-loss issue.

### Lazy taxonomy find/create

Best seam: a public/shared resolver in `TaxonomyMigrationService`, called by relation resolution.

Do not duplicate taxonomy entry creation in `RelationHandler`. Instead, reuse taxonomy mapping and state-recording logic. The resolver should:

- Accept the taxonomy source FQCN/state source and legacy ID.
- Check state table first.
- Load the legacy row if state misses.
- Find or create the Craft taxonomy entry using `mapping.taxonomies`.
- Apply default-language fallback to missing locale values.
- Record state rows consistently.
- Return the Craft element ID for relation assignment.

Dry-run should not write Craft/state. Recommended: report would-create/would-link counts and leave transformed output explicitly marked as unresolved/simulated if needed.

Tests:
- Non-empty taxonomy FK resolves to a Craft relation even when no state row existed before.
- Re-running same FK does not create duplicates.
- Missing locale values fallback to default-language values.
- Full unreferenced taxonomy import remains opt-in.

### Verify count semantics

Best seams: `src/console/VerifyController.php`, `src/verify/BaselineCounterService.php`, `src/verify/CountGateService.php`.

Current behavior converts pre-migration/current Craft baseline counts into expected migration counts. That caused false failures. Phase 10 should introduce explicit count domains:

- Craft baseline/current drift: compare the same Craft count domain over time.
- Migration-created state counts: count what this plugin created via state table and Craft elements.
- Source parity: compare source/transformed expected counts to migrated state/Craft counts.

Planner can choose exact report layout, but the output must stop implying that a pre-migration Craft baseline is the expected post-migration source count.

Tests:
- A baseline count and a migration-created count render as separate domains.
- Single-section/site-count mismatches caused by `site('*')` vs primary-site counts no longer produce false failures.
- Verify report labels make the compared domain clear.

## Existing Tests to Reuse

| Test file/pattern | Reuse for |
|---|---|
| `tests/unit/compile/CraftTargetIntrospectorTest.php` | Section/type compatibility and target validation severity. |
| `tests/unit/compile/MappingCompilerValidationTest.php` | Compile validation and invalid-target drops. |
| `tests/unit/console/MigrateControllerTaxonomiesWiringTest.php` | CLI/settings wiring for taxonomy stage semantics. |
| `tests/integration/load/TaxonomyMigrationTest.php` | Taxonomy entry creation, locale fallback, state rows. |
| `tests/unit/verify/CountGateServiceTest.php` and `CountGateServiceFiltersTest.php` | Verify domain arithmetic and filter semantics. |
| `tests/unit/console/MigrateControllerFailureExitTest.php` | Zero-failure run semantics and report handling. |
| `tests/integration/transform/TransformCharacterizationTest.php` | Sanitized structural regression fixtures. |

## Specific Regression Tests Needed

1. Matrix native-title fallback adds non-empty peer `title` and preserves block fields.
2. Matrix fallback test is generic and does not hardcode CQM handles.
3. Sparse-locale primary save uses first/best available locale for validation while preserving enabled state.
4. Invalid section + entry-type combination blocks before live load.
5. Lazy taxonomy relation find/create resolves non-empty FK without prior state row.
6. Lazy taxonomy resolver is idempotent on rerun.
7. Missing taxonomy locale values fallback to default-language values.
8. PageBuilder ownership validation prevents invalid `pageBuilderHandle` propagation.
9. Verify count report separates baseline/current Craft counts from migration/source-parity counts.
10. Fallback usage appears in `REPORT.md`, warnings, or an equivalent operator-visible log/report surface.

## Common Pitfalls

- **Fixing Matrix titles only in transform:** load-time/singleton paths can still fail. Use load normalization.
- **Saving an empty primary-site payload:** sparse locale payloads can be valid source data; primary Craft save still needs validation fields.
- **Letting invalid section/type pairs reach load:** target introspection already knows enough to prevent this.
- **Creating taxonomy entries directly inside RelationHandler:** risks duplicate entries and inconsistent state rows. Reuse TaxonomyMigrationService.
- **Silent lazy taxonomy misses:** a non-empty source FK resolving to `[]` is no longer acceptable.
- **Broad defaultEntryType fallback:** can hide bad mappings and route content to the wrong Craft type.
- **Verify comparing unlike domains:** pre-migration Craft counts are not source-parity expectations.
- **Committing proprietary rehearsal content:** use structural fixtures and summaries only.

## Rehearsal Runbook Guidance

Final Phase 10 proof should:

1. Restore `~/Sites/cqm-craft-website/storage/backups/craft-starter-kit--2026-04-28-131310--v5.9.20.sql`.
2. Run the canonical workflow:

   ```bash
   php craft kunstmaan-migrator/doctor
   php craft kunstmaan-migrator/analyze
   php craft kunstmaan-migrator/map
   php craft kunstmaan-migrator/compile
   php craft kunstmaan-migrator/migrate --dry-run
   php craft kunstmaan-migrator/migrate --live
   php craft kunstmaan-migrator/verify
   ```

3. Inspect `storage/migration/REPORT.md` for zero entry/stage failures.
4. Inspect fallback reporting for Matrix titles, sparse locale saves, taxonomy locale fallback, invalid target drops/failures.
5. Inspect taxonomy-backed relation fields where source FKs are non-empty.
6. Inspect Page-rooted coverage for entries, page parts, relations, assets, taxonomies, SEO, redirects, and CKEditor references.
7. Inspect verify report with corrected domain labels.

Focused entity reruns are useful during implementation but do not replace the restored-backup full workflow closing gate.

## Open Questions (RESOLVED)

1. **Lazy taxonomy resolution seam:** Resolved to use a load-safe resolver seam owned by `TaxonomyMigrationService`. `RelationHandler` delegates only on taxonomy-backed non-empty state misses; it does not create taxonomy entries directly.
2. **Dry-run behavior:** Resolved to perform no Craft writes and no migration-state writes. Dry-run reports would-create/would-link taxonomy counts instead of pretending persisted targets exist.
3. **Source-parity expected counts:** Resolved to split verify domains into Craft baseline/current drift, migration-created state counts, and source/transformed parity where source-derived expected counts exist.
4. **Fatal target validation:** Resolved to treat section + entry-type incompatibility as load-fatal. Advisory-only validation messages remain warnings when they cannot cause live load failure.

## Sources

- `.planning/phases/10-generic-migration-rehearsal-gap-closure/10-CONTEXT.md`
- `.planning/ROADMAP.md` Phase 10 section
- `.planning/STATE.md`
- `.planning/PROJECT.md`
- `CLAUDE.md`
- `src/load/EntryMigrationService.php`
- `src/load/TaxonomyMigrationService.php`
- `src/fields/handlers/RelationHandler.php`
- `src/compile/MappingCompiler.php`
- `src/compile/CraftTargetIntrospector.php`
- `src/console/CompileController.php`
- `src/console/MigrateController.php`
- `src/verify/BaselineCounterService.php`
- `src/verify/CountGateService.php`
- `src/console/VerifyController.php`
- Existing tests under `tests/unit/compile`, `tests/unit/console`, `tests/unit/verify`, `tests/integration/load`, and `tests/integration/transform`.
