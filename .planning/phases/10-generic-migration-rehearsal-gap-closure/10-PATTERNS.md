# Phase 10: Generic Migration Rehearsal Gap Closure - Pattern Map

**Mapped:** 2026-04-28
**Files analyzed:** 22
**Analogs found:** 22 / 22

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|---|---|---|---|---|
| `src/load/EntryMigrationService.php` | service | Craft entry save / idempotent load | same file | exact seam |
| `src/load/TaxonomyMigrationService.php` | service | taxonomy CRUD / state recording | same file | exact seam |
| `src/fields/handlers/RelationHandler.php` | field handler | transform relation lookup | same file | exact seam |
| `src/fields/ResolverContext.php` | context value object | dependency transport | same file | exact seam |
| `src/console/MigrateController.php` | console controller | pipeline orchestration | same file | exact seam |
| `src/models/Settings.php` | model | plugin config | same file | exact seam |
| `src/compile/MappingCompiler.php` | compiler service | mapping validation/compilation | same file | exact seam |
| `src/compile/CraftTargetIntrospector.php` | validation service | Craft target introspection | same file | exact seam |
| `src/console/CompileController.php` | console controller | compile command surface | same file | exact seam |
| `src/verify/BaselineCounterService.php` | read service | Craft count capture | same file | exact seam |
| `src/verify/CountGateService.php` | gate service | count comparison | same file | exact seam |
| `src/console/VerifyController.php` | console controller | verify reporting | same file | exact seam |
| `tests/unit/compile/CraftTargetIntrospectorTest.php` | test | validation unit test | same file | exact seam |
| `tests/unit/compile/MappingCompilerValidationTest.php` | test | compiler validation unit test | same file | exact seam |
| `tests/unit/console/MigrateControllerTaxonomiesWiringTest.php` | test | CLI/service wiring | same file | exact seam |
| `tests/unit/verify/CountGateServiceTest.php` | test | pure count arithmetic | same file | exact seam |
| `tests/unit/verify/CountGateServiceFiltersTest.php` | test | filter-aware counts | same file | exact seam |
| `tests/integration/load/TaxonomyMigrationTest.php` | test | taxonomy load integration | same file | exact seam |
| `tests/integration/transform/TransformCharacterizationTest.php` | test | structural regression fixtures | same file | exact seam |

## Pattern Assignments

### `src/load/EntryMigrationService.php`

Use this service as the primary pattern for:

- Matrix native-title fallback.
- Sparse-locale primary-save fallback.
- Operator-visible warnings for fallback/drop behavior.
- Preserving per-site save semantics and state-row idempotency.

Relevant existing patterns:

- Configured site validation fails loudly when transformed payload contains an unknown site handle.
- Primary-site selection prefers the actual Craft primary site among configured handles, then falls back to the first resolved site if none are primary.
- Enabled-for-site state is computed from each source site payload using `enabled` or `online`.
- Primary save happens before localized saves with `propagateChanges=false`.
- `normalizeMatrixPayload()` and `stripSourcePartRefs()` centralize Matrix payload cleanup before Craft field validation.
- Unknown fields are dropped only after field-layout inspection and are surfaced through `Craft::warning(...)`.

Phase 10 insertion points:

- Insert sparse-locale fallback before primary `applyPerSiteData()`. If the primary-site payload is missing or validation-empty for native fields such as `title`/`slug`, copy a best available non-empty locale payload for the first save, without changing the original per-site payload set.
- Extend Matrix normalization to synthesize a generic non-empty peer-level `title` when a Matrix block has no native `title` after lifting `title`/`heading` out of fields.
- Report fallback usage through the same warning/report style used for dropped unknown fields.

### `src/load/TaxonomyMigrationService.php`

Use this service as the primary pattern for:

- Lazy page-rooted taxonomy find/create.
- Default-language fallback for missing taxonomy locale values.
- Idempotent state-row reuse.
- SQL identifier whitelisting.
- Dry-run simulation/reporting.

Relevant existing patterns:

- `migrateAll()` loads `mapping.taxonomies`, warns and returns cleanly when no taxonomy mappings exist.
- Incomplete taxonomy mapping rows are skipped with counters and report warnings.
- Source table identifiers are whitelisted before being interpolated into SQL.
- Existing state rows are checked before creating a new Craft entry.
- New/updated taxonomy entries record site-agnostic state rows through `MigrationStateService::record(...)`.
- Localized taxonomy saves seed from canonical/default values, then overlay non-empty locale values.
- Dry-run avoids Craft/state writes.

Phase 10 insertion points:

- Extract or expose a shared resolver/upsert method that accepts a taxonomy source/FQCN and legacy ID, checks state first, loads the source row, creates or updates the Craft taxonomy entry, records state, and returns the Craft ID.
- Keep `RelationHandler` thin; do not duplicate taxonomy creation logic there.
- In dry-run, report would-create/would-link counts and avoid writes.
- Keep default-language fallback for missing taxonomy locale values and make fallback usage visible.

### `src/fields/handlers/RelationHandler.php`

Use this handler as the pattern for:

- Required option fail-fast behavior.
- Direct state-table lookup.
- Join-table relation expansion.
- Join-translation ID-space correction.
- Identifier validation.
- Special handling for non-empty source references that do not have state rows.

Relevant existing patterns:

- `stateSource` is a required option and invalid handler options throw `RuntimeException`.
- Direct relation lookup first checks state by site ID, then by site-agnostic key.
- Join-table and join-translation paths whitelist table/column identifiers.
- Asset relations already demonstrate a non-empty source ref with missing state row receiving explicit special handling instead of silent drop.

Phase 10 insertion points:

- Keep first lookup through state.
- On miss for a non-empty taxonomy FK, delegate to the shared taxonomy resolver if the mapping/options identify the relation as taxonomy-backed.
- Avoid broad fallback behavior for unknown relation classes; unresolved non-empty relations should be visible in the report/log.
- If resolver dependencies are needed, pass them through `ResolverContext` rather than global service lookup where practical.

### `src/fields/ResolverContext.php`

Use this as the pattern for dependency transport into field handlers.

Relevant existing patterns:

- Constructor-promoted readonly properties.
- Required runtime collaborators first.
- Nullable optional dependencies last.
- No mutation in handlers.

Phase 10 insertion point:

- Add an optional taxonomy resolver/report dependency only if the selected implementation requires lazy resolution from `RelationHandler`.

### `src/console/MigrateController.php`

Use this controller as the pattern for:

- Boolean CLI flag wiring via public properties and `options()`.
- `NeverProductionTrait` gate as first meaningful action.
- Compiled mapping preflight before pipeline stages run.
- Stage report merging.
- Taxonomy full-import opt-in behavior.

Relevant existing patterns:

- `actionIndex()` checks the production gate first.
- `preflightCompiledMapping()` fails fast for missing compiled mapping requirements.
- Taxonomy migration is currently staged between transform and load so state rows exist before page load.
- Report stages are merged and printed with created/updated/skipped/failed counters.

Phase 10 insertion points:

- Add a CLI flag/settings path for including unreferenced taxonomies.
- Change default behavior to page-rooted/referenced-only taxonomy creation.
- Keep full taxonomy import explicit and visible.
- Add migrate preflight for load-fatal compile validation only if compile itself does not hard-fail.

### `src/models/Settings.php`

Use this model as the pattern for plugin-owned configuration.

Phase 10 insertion point:

- Add a boolean setting for unreferenced taxonomy import only if CLI flag should have persistent default support. Keep default false.

### `src/compile/CraftTargetIntrospector.php`

Use this service as the pattern for Craft target validation.

Relevant existing patterns:

- It already detects invalid section + entry-type combinations.
- It returns validation messages rather than throwing directly.
- Tests already cover target discovery behavior.

Phase 10 insertion points:

- Add severity or classification so load-fatal messages are distinguishable from advisory warnings.
- Treat section + entry-type incompatibility as fatal for compile/preflight.
- Keep advisory messages as non-blocking warnings when they cannot cause live load failure.

### `src/console/CompileController.php`

Use this controller as the pattern for command output and exit-code behavior.

Phase 10 insertion points:

- Print fatal target validation separately from warnings.
- Return a config/non-zero exit code when load-fatal target validation exists.
- Preserve existing warning output for advisory-only issues.

### `src/compile/MappingCompiler.php`

Use this compiler as the pattern for mapping-row propagation and validation.

Relevant existing patterns:

- Page part rows can influence parent node class metadata.
- Compiler validation tests already cover accepted/dropped row behavior.

Phase 10 insertion points:

- Validate that propagated `pageBuilderHandle` exists on the target entry type's field layout.
- Do not set `pageBuilderHandle` when the parent entry type does not own that Matrix field.
- If an alternate flat target exists, keep content mapped there.
- If no alternate home exists, surface a blocking or explicit data-loss warning according to planner severity decisions.

### Verify Services and Controller

Use these as the pattern for count-domain separation:

- `src/verify/BaselineCounterService.php` captures Craft counts.
- `src/verify/CountGateService.php` compares expected/actual count maps.
- `src/console/VerifyController.php` transforms captured data into report output.

Phase 10 insertion points:

- Stop converting pre-migration Craft baseline counts into source migration expectations.
- Split report domains into Craft baseline/current drift, migration-created state counts, and source/transformed parity.
- Keep filter semantics from existing count-gate tests.
- Label report sections so operators can see what is being compared.

## Test Patterns

### Compile tests

Extend:

- `tests/unit/compile/CraftTargetIntrospectorTest.php`
- `tests/unit/compile/MappingCompilerValidationTest.php`
- `tests/unit/compile/MappingCompilerPageRelationClosureTest.php`

Needed assertions:

- Invalid section + entry type is fatal/blocking.
- Advisory validation remains warning-only.
- Invalid pageBuilder ownership does not propagate.
- Flat fallback keeps content mapped where available.

### Console wiring tests

Extend:

- `tests/unit/console/MigrateControllerTaxonomiesWiringTest.php`
- `tests/unit/console/MigrateControllerFailureExitTest.php`

Needed assertions:

- Default taxonomy mode is referenced-only.
- Full/unreferenced taxonomy import requires explicit flag/settings.
- Load-fatal compiled mapping state returns non-zero before live load.
- Report output includes fallback/would-create information.

### Load tests

Extend or add near:

- `tests/integration/load/TaxonomyMigrationTest.php`
- a new `tests/integration/load/EntryMigrationServiceTest.php` if no suitable entry-load integration test exists.

Needed assertions:

- Matrix blocks missing native `title` receive a non-empty peer title.
- Sparse-locale payload can save the Craft primary site using best-available locale data.
- Lazy taxonomy resolver creates/links a referenced term when no state row existed.
- Re-running the same lazy taxonomy relation does not create duplicate entries/state.
- Taxonomy locale values fall back to default-language values.

### Verify tests

Extend:

- `tests/unit/verify/CountGateServiceTest.php`
- `tests/unit/verify/CountGateServiceFiltersTest.php`

Needed assertions:

- Baseline/current Craft count comparison is distinct from source parity.
- Site-wide vs primary-site count differences do not produce false migration failures.
- Report labels name the compared domain.

### Transform characterization tests

Extend:

- `tests/integration/transform/TransformCharacterizationTest.php`

Use sanitized structural fixtures for the three failed page classes and taxonomy-backed relation shape. Do not commit proprietary source content.

## Implementation Cautions

- Do not fix Matrix title only in transform; load normalization is the generic final boundary.
- Do not silently mutate primary-site source truth. Use fallback only for Craft validation and report it.
- Do not let invalid section/type pairs reach `migrate --live`.
- Do not create taxonomy entries inside `RelationHandler`; delegate to `TaxonomyMigrationService`.
- Do not add broad default-entry-type fallback that hides bad mapping.
- Do not treat pre-migration Craft baseline counts as source-parity expectations.
- Do not make full taxonomy import default; referenced-only remains default.

## Suggested Planning Dependencies

1. Compile/preflight fatal validation first, because it prevents known live-load surprises.
2. Load fallback hardening second, because it resolves two known entry failures once invalid mappings are blocked.
3. Lazy taxonomy resolver third, because it changes page-rooted relation semantics and depends on clean reporting.
4. Verify semantics and restored-backup rehearsal last, because it validates the corrected workflow end-to-end.
