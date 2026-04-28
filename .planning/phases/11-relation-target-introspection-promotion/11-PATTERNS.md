# Phase 11: Dual Schema Walkers & LLM-first Mapping - Pattern Map

**Mapped:** 2026-04-28
**Files analyzed:** 17 inferred new/modified files or component areas
**Analogs found:** 17 / 17

## File Classification

| New/Modified File or Component Area | Role | Data Flow | Closest Analog | Match Quality |
|---|---|---|---|---|
| `src/source/Kunstmaan*Graph*` value objects or array contract | model | transform | `src/source/DoctrineEntityInfo.php`, `DoctrineColumnInfo.php`, `DoctrineRelationInfo.php` | role-match |
| `src/source/KunstmaanPageWalker.php` | service | graph traversal / transform | `src/source/KunstmaanSourceScanner.php`, `src/source/KunstmaanPageStructureScanner.php`, `src/source/DoctrineEntityParser.php` | exact |
| `src/source/CraftEntryWalker.php` | service | Craft service introspection / transform | `src/source/CraftKnowledgeBase.php` | exact |
| `src/source/KunstmaanKnowledgeBase.php` | service | source graph to LLM prompt surface | existing `KunstmaanKnowledgeBase` render methods | exact |
| `src/source/CraftKnowledgeBase.php` | service | target graph to LLM prompt surface | existing `CraftKnowledgeBase` schema/catalog methods | exact |
| `src/analyze/LlmClassifier.php` | service | external API request/response | existing prompt + Anthropic flow | exact |
| `src/console/AnalyzeController.php` | controller | CLI orchestration / artifact I/O | current source + target schema dump flow | exact |
| `src/console/CompileController.php` | controller | CLI orchestration / validation | current schema and mapping validation flow | exact |
| `src/compile/MappingCompiler.php` | service | transform / compile validation | existing proposal-to-runtime compiler | exact |
| `src/mapping/MappingFile.php` | utility | file I/O | atomic YAML/JSON helpers | exact |
| `src/mapping/MappingAuditor.php` | service | validation / batch | existing drift and taxonomy checks | exact |
| `src/mapping/BlockAvailabilityValidator.php` or new graph validator | service | validation / batch | Matrix ownership validator | role-match |
| Promoted/shared relation target loading (`src/load/*`, relation handler area) | service / handler | CRUD + state lookup | `src/load/EntryMigrationService.php`, `src/fields/handlers/RelationHandler.php` | role-match |
| `tests/unit/source/KunstmaanPageWalkerTest.php` | test | scanner behavior | `tests/unit/source/DoctrineEntityParserAttributesOnlyTest.php` and source scanner tests | role-match |
| `tests/unit/source/CraftEntryWalkerTest.php` | test | Craft introspection behavior | `tests/unit/compile/CraftTargetIntrospectorTest.php`, `tests/unit/console/AnalyzeControllerDualSchemaDumpTest.php` | role-match |
| `tests/unit/console/AnalyzeControllerGraphArtifactsTest.php` | test | controller artifact behavior | `tests/unit/console/AnalyzeControllerDualSchemaDumpTest.php` | exact |
| `tests/unit/compile/MappingCompilerPromotedTargetsTest.php` | test | compile validation / transform | existing compile tests and `KunstmaanSchemaDumperEntityFilterTest` | role-match |

## Pattern Assignments

### Graph contracts / value objects

Use the existing source value-object style from `DoctrineEntityInfo`, `DoctrineColumnInfo`, and `DoctrineRelationInfo`: `declare(strict_types=1)`, namespace `lameco\kunstmaanmigrator\source`, `final class`, constructor-promoted readonly properties, and precise array-shape docblocks when JSON interoperability requires arrays.

Phase 11 graph contracts should include:
- explicit `graphVersion` or `schemaVersion`;
- normalized registries rather than repeated nested page-local blobs;
- stable string IDs for page roots, entry roots, usages, entities, fields, relations, pageparts, Matrix block types, assets/volumes, samples, and constraints;
- factual graph data only, with relation intent and content-only policy left to mapping decisions.

### `KunstmaanPageWalker`

Build on `KunstmaanSourceScanner`, `KunstmaanPageStructureScanner`, and `DoctrineEntityParser`.

Established patterns to preserve:
- Yii component dependency style with nullable public properties, matching `KunstmaanSourceScanner`.
- One per-request cached graph build, like `KunstmaanSourceScanner::scan()`.
- Fail-closed empty graph when source path or parser is unavailable, but keep the returned shape versioned and parseable.
- Source discovery from `KUNSTMAAN_SOURCE_PATH` via `KunstmaanSourcePathResolver`.
- `FilesystemIterator` / `RecursiveDirectoryIterator` for source walks.
- `Yaml::parseFile()` with default flags for pagepart config; never execute project PHP.
- `Craft::info()` for expected unresolved detail-table cases and `Craft::warning()` for unexpected parser/scanner failures.

Phase 11-specific guidance:
- Start from scoped page roots (`NewsPage`, `HomePage`) for proposal input.
- Walk reachable Doctrine relations recursively with a visited set and configurable max depth.
- Represent `NewsPage.employee_id -> App\Entity\Employee` as relation evidence with FK column and target FQCN.
- Declare shared targets such as `App\Entity\Employee` once, with inbound owners/usages.
- Declare reusable pagepart classes once, with per-page/context usage refs.
- Include content-only columns factually; do not decide that they map to Matrix blocks inside the walker.

### `CraftEntryWalker`

Build on `CraftKnowledgeBase`.

Established patterns to preserve:
- Read Craft services only; no schema mutations.
- Stable sorting for reproducible artifacts and prompt cache friendliness.
- Capture sections, entry types, field layouts, custom fields, Matrix fields, Matrix block entry types, nested block fields, asset volumes, plugin availability, flat handles, and Matrix ownership.
- Reuse helper shapes from `buildFieldIndex()`, `entryTypeFlatHandles()`, `matrixFieldCatalog()`, and `matrixFieldsForEntryType()`.

Phase 11-specific guidance:
- Start from candidate Craft entry roots for `newsPage` and `homePage` proofs, while still allowing broader target candidate registries.
- Normalize field and block definitions once, then reference them from entry roots/usages.
- Include Entries/Assets field constraints, allowed source/kind evidence, native field requirements (`title`, `slug`), and Matrix block ownership.
- Expose enough data for deterministic compile validation: target entry type owns field, Matrix field allows block entry type, relation target type is compatible, asset target volume/kind is compatible.

### Analyze integration

Use `AnalyzeController`'s current staged orchestration pattern:
- parse filters via `FilterFactory`;
- enforce `NeverProductionTrait` first;
- resolve source path before legacy source reads;
- write JSON artifacts through `MappingFile::writeAtomicJson()`;
- print concise OK/FAIL/WARN status lines;
- fail with `ExitCode::UNSPECIFIED_ERROR` when required artifacts cannot be written or graph building throws.

Phase 11 should transition the canonical pair `kunstmaan-schema.json` and `craft-schema.json` from catalog-shaped dumps toward graph-shaped artifacts. Backward-compatible compatibility surfaces may stay temporarily if compile/tests still consume old keys, but the plan should state the migration path.

### LLM mapping integration

Use the existing `LlmClassifier` pattern for Anthropic-only analyze-stage calls, preserving runtime-zero-AI outside analyze. Prompt input should become the graph pair, with heuristics limited to deterministic candidate narrowing and safety hints.

The LLM may propose:
- source page/entity/pagepart field refs to Craft entry/field/block refs;
- relation intents (`reference`, `promote`, `embed`, `drop`, `out_of_scope`);
- target candidates for shared relation entities.

Compile and audit code must validate all proposals before executable mapping is emitted.

### Compile and validation integration

Use existing `MappingCompiler`, `MappingAuditor`, and `BlockAvailabilityValidator` patterns:
- reject mappings to fields not owned by the target entry type;
- reject Matrix block assignments where the Matrix field does not allow the block entry type;
- reject graph-incompatible relation target mappings;
- require non-empty relation FK evidence to have a mapping/drop/out-of-scope decision before live migration;
- emit actionable diagnostics rather than silent drops.

Promoted/shared target mappings must include enough `stateSource` and target contract data for load ordering and idempotent state resolution.

### Load and relation handling integration

Use existing state-table patterns from load services and `RelationHandler`, but avoid unsafe lazy creates inside a relation field handler. Promoted/shared targets should be extracted/transformed/loaded under their own source identity before owners reference them.

Phase 11 planning should identify the minimal load-path changes needed for the `NewsPage` / `Employee` proof without turning every relation target into a new generic loader in one step.

### Testing patterns

Use existing PHPUnit patterns:
- scanner/parser unit tests with temporary source fixtures;
- controller artifact tests like `AnalyzeControllerDualSchemaDumpTest`;
- compile validation tests for invalid target fields/Matrix ownership;
- no proprietary CQM fixture dependency in unit tests;
- scoped CQM rehearsal commands may be verification steps, not unit-test prerequisites.

## Implementation Landmines

- Do not reintroduce fake pageparts or relation-expanded `_rel:*` helper blobs as canonical owner data.
- Do not classify all non-page entities as taxonomies; `App\Entity\Employee` is the counterexample.
- Do not hardcode CQM handles/classes beyond test fixtures and proof commands.
- Do not write files with raw `file_put_contents`; use `MappingFile` atomic helpers.
- Do not execute source project PHP during introspection.
- Do not add runtime AI outside analyze.
- Do not make SEOmatic/Retour required dependencies.
- Do not hide content-only page policy inside a generic implicit-content heuristic.

## PATTERN MAPPING COMPLETE
