# Phase 9: Migration Workflow Hardening & Page-rooted Introspection Audit - Pattern Map

**Mapped:** 2026-04-28
**Files analyzed:** 15 surfaces / file groups
**Analogs found:** 15 / 15

## File Classification

| Surface | Role | Closest analog | Planner use |
|---|---|---|---|
| `src/mapping/MappingFile.php` | mapping file utility | existing `load()`, `merge()`, `writeAtomic()` | Preserve top-level blocks while merging proposals. |
| `src/console/CompileController.php` | CLI workflow bridge | existing config guards/report output | Model `migrate` preflight messages and explicit compile requirement. |
| `src/compile/MappingCompiler.php` | pure compile service | existing warnings + `_compileReport` | Add type/source validation and page-level relation closure warnings. |
| `src/filter/MigrationFilters.php` | immutable filter value object | existing relationGraph DFS | Keep source-entity semantics and reachability. |
| `src/filter/FilterFactory.php` | CLI/settings normalization | existing null/empty/comma split ladder | Normalize FQCN/basename and inject relation graph. |
| `src/extract/ExtractService.php` | page-rooted extraction | existing detail/pagepart/implicit content and `_rel:` joins | Extend/inspect relation closure and unsupported-shape visibility. |
| New Page-rooted coverage service/artifact | audit utility | `src/mapping/CoverageAuditor.php` | Return structured rows, render Markdown/JSON deterministically. |
| `src/console/MigrateController.php` | workflow orchestration | existing locale/mapping preflight, `runLoadFromDisk()` | Add compiled-block preflight and truthful final exit. |
| `src/load/MigrationReport.php` | run accumulator | `recordFailure()` increments failed bucket | Centralize `hasFailures()` or equivalent success check. |
| `src/load/AssetMigrationService.php` | asset ingestion | `resolveFromLegacyId()` + `ingestReferenced()` | Make preload actually referenced-only. |
| `src/finalize/CkeditorRewriterService.php` | HTML rewrite/security | unresolved marker emission | Encode comment marker source safely. |
| `src/verify/CountGateService.php` | count parity service | existing filter-aware skip rows | Translate source filters to Craft sections before comparing. |
| `src/console/VerifyController.php` | verify CLI | existing FilterFactory handoff + atomic JSON writes | Load compiled mapping/translator at controller boundary. |
| `.github/workflows/ci.yml` | CI config | existing unit/smoke split | Align smoke with real doctor/release workflow requirements. |
| Docs/release files | operator documentation | README + PROJECT + CHANGELOG + release checklist | Show `compile` and generic partial-automation contract. |

## Concrete Patterns

### Mapping merge preservation

`MappingFile::load()` already preserves sibling top-level blocks by returning the parsed mapping array and normalizing only `proposals`. `MappingFile::merge()` is the bug: it builds `$merged` rows and returns only `['proposals' => $merged]`.

**Plan pattern:** start from `$existing`, replace only `$existing['proposals']`, and return the full mapping. Preserve `nodeClasses`, `sections`, `sites`, `pageParts`, `taxonomies`, `dataProviders`, metadata, and future top-level audit blocks. Add a unit test in `tests/unit/mapping/MappingFileTest.php` proving compiled blocks survive an analyze-style merge.

### Explicit compile + migrate preflight

`CompileController` already has the right CLI guard style:

- NeverProduction gate first.
- Load mapping with `try/catch`.
- Return `ExitCode::CONFIG` for actionable missing prerequisites.
- Print exact command hints like "run `analyze` first" or "pass `--overwrite`".

**Plan pattern:** add a `MigrateController` preflight immediately after mapping load. Required blocks: `nodeClasses`, `sections`, and `sites`; conditionally require `pageParts`, `taxonomies`, and `dataProviders` when accepted proposals imply those compiled blocks should exist. Do not auto-compile. Message should tell the operator to run `./craft kunstmaan-migrator/compile`.

### Compiler validation

`MappingCompiler::compile()` is pure logic and already emits warnings through `_compileReport['warnings']`. It also has `relationOptionsForFkColumn()` as a precedent for enriching relation handler options from Doctrine metadata.

**Plan pattern:** add validation inside compile before fields are emitted:

- incompatible handler/source shape: warn and skip field;
- relation handler without resolvable `stateSource` or join options: warn and skip or mark unsupported;
- scalar-to-Matrix/dropdown mistakes: warn and skip rather than silently producing empty output;
- page-level M2M relation closure should mirror the existing relation-option helper but emit `joinTable`/columns where discovered or a visible unsupported-shape warning.

### Filter semantics

`MigrationFilters` is already source-entity oriented and has a relationGraph DFS. `FilterFactory` currently never passes a relation graph, so reachability is half-wired.

**Plan pattern:** keep `MigrationFilters::$entities` as Kunstmaan FQCN/basename identity. Normalize accepted CLI spellings at `FilterFactory` or a dedicated helper. Load relation graph from source/analyze artifacts and pass it into `MigrationFilters`. Translate to Craft handles only at Craft query surfaces such as finalize and verify.

### Page-rooted extraction and relation closure

`ExtractService` already builds the page-rooted payload:

- detail row via `loadDetailRow()`;
- page parts via `loadPageParts()`;
- implicit content via `buildImplicitContentPageParts()`;
- ManyToOne rows under `_rel:<property>.<column>`.

**Plan pattern:** build the coverage report from this same conceptual graph. Unsupported relation shapes must not disappear silently. ManyToMany/OneToMany page-owned relations should be either mapped, dropped, out-of-scope, or reported as unsupported with enough join metadata for an operator to act.

### Page-rooted coverage report

`CoverageAuditor` is the analog: pure service returns structured violations, controller renders/writes artifacts atomically.

**Plan pattern:** create a pure audit service that returns per-Page rows with categories such as `migrated`, `dropped`, `out_of_scope`, `unsupported`, and `warning`. Write deterministic JSON plus a human-readable Markdown report via `MappingFile::writeAtomicJson()` / `writeAtomic()`. The report should not copy proprietary source bodies; path references and structural summaries are enough.

### Load failure semantics

`MigrateController::runLoadFromDisk()` correctly continues after per-entry failures and records them with `MigrationReport::recordFailure()`. The final `actionIndex()` path currently prints `Migrate: PASS` and returns `ExitCode::OK` regardless of recorded failures.

**Plan pattern:** keep per-entry continuation. After report writing, inspect `$report->failures` and `$report->counts['failed']`. If any failures exist, print a concise failure summary and return `ExitCode::UNSPECIFIED_ERROR`.

### Asset preload

`AssetMigrationService::ingestReferenced()` claims referenced-only behavior but currently selects from all `kuma_media`, optionally filtered by `created_at`.

**Plan pattern:** source preload IDs from in-scope transformed payloads, extracted rows, deferred asset tokens, or a dedicated referenced-ID collector. Keep full-media ingestion out of `--preload-assets`; if useful, it belongs to explicit recovery tooling.

### CKEditor unresolved marker safety

`CkeditorRewriterService::rewriteAssetAttributes()` currently emits:

```php
$marker = '<!-- MIGRATION:UNRESOLVED source=' . $url . ' -->';
```

**Plan pattern:** keep the original URL in the attribute, but never put raw URL text inside the HTML comment. Use a delimiter-safe value such as `sourceB64=<base64url>`. Add tests with `-->`, `<script`, quotes, and markup-looking URL values.

### Verify/finalize filter translation

`CountGateService::isSectionFilteredOut()` compares Craft section handles directly with source `entities`, which is the wrong domain. `VerifyController` already resolves filters at the controller boundary.

**Plan pattern:** load compiled mapping once at the controller or service boundary, translate source FQCN/basename filters to Craft section/entry type handles, then apply skip logic. Do the same for finalize if it passes filters into Craft element queries.

### CI/docs/release workflow

The existing CI has a unit job and a scratch-Craft smoke job. The smoke currently models plugin-load/doctor behavior, not the full release workflow.

**Plan pattern:** keep unit/integration tests as the primary CI gate. Adjust smoke/docs so they do not claim a green migration workflow without required source/legacy DB config. README/PROJECT/release docs must include `compile` and describe the page-driven/generic-but-not-100%-automatic contract.

## Test Pattern Inventory

Prefer targeted tests modeled after existing suites:

- `tests/unit/mapping/MappingFileTest.php` — merge preservation.
- `tests/unit/filter/MigrationFiltersReachabilityTest.php` and `tests/unit/filter/FilterFactoryTest.php` — reachability and normalization.
- `tests/unit/extract/ExtractServiceFkJoinTest.php` — `_rel:` and relation closure behavior.
- `tests/unit/compile/*` — compile warnings and type/source validation.
- `tests/unit/finalize/CkeditorRewriterServiceTest.php` — unresolved marker escaping.
- `tests/unit/verify/CountGateService*Test.php` — source filter to Craft section translation.
- `tests/integration/transform/*` — Page-rooted fixture/characterization coverage.

## Genericity Sampling Pattern

Use CQM for executable integration because Craft is installed in `~/Sites/cqm-craft-website`. Use `~/Sites/cqm-website`, `~/Sites/simac-website`, and `~/Sites/enreach-website` only as source-shape samples unless the user configures additional Craft targets.

Sampling should record structural patterns only:

- Page entity naming and location.
- PagePart entity naming and relation style.
- Doctrine ManyToOne / ManyToMany / OneToMany shapes.
- Media FK column naming.
- Taxonomy/dataProvider-like standalone entity shapes.

Do not commit proprietary source code snippets from those projects.
