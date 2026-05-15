# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`lameco/craft-kunstmaan-migrator` (revisited) — a clean rewrite of the Craft 5 plugin that migrates legacy Kunstmaan (Symfony) content into an existing Craft CMS site. Craft is the source of truth for schema; Kunstmaan content gets mapped onto sections / fields / entry types as they already exist.

PHP 8.3+, Craft CMS 5, PSR-4 namespace `lameco\kunstmaanmigrator\` under `src/`, composer type `craft-plugin`. Anthropic-only AI (proposal stage), runtime-zero-AI everywhere else, dev/staging only (`NeverProductionTrait` hard-blocks production).

## Dev commands

```bash
composer install
composer test                 # full PHPUnit 11 suite
composer test-unit            # tests/unit only
composer test-integration     # tests/integration only
composer test-coverage        # requires pcov or xdebug; runs tools/check-coverage.php
vendor/bin/phpunit --filter SomeTestName       # run a single test
vendor/bin/phpunit tests/unit/transform/...    # run one file/dir
```

Release rehearsal (run before tagging v1.0 — fails loudly if the private CQM fixture corpus is empty; normal dev runs skip the empty-corpus sentinel):

```bash
RELEASE_REHEARSAL=1 vendor/bin/phpunit tests/integration/transform/TransformCharacterizationTest.php --testdox
```

To populate that corpus, run `tools/capture-transform-fixtures.php` against a rehearsal target — it writes paired `input/` + `golden/` JSON pairs plus `tests/fixtures/transform/mapping.json` (a one-shot snapshot of the compiled mapping used at capture time).

Coverage scope in `phpunit.xml` is intentionally narrow (filters, mapping file, ckeditor rewriter, heuristic proposer, field handlers) — adding files to `<source><include>` widens the gate. CI runs `composer validate --strict` + install + `composer test` on PHP 8.3.

## Operator CLI workflow

The canonical pipeline. Page entities are the source root; each accepted Page mapping produces a Craft Entry with its page parts, relations, taxonomies, SEO/redirect sidecars, CKEditor refs, and assets pulled from there.

First-time install on a consumer site:

```bash
composer require lameco/craft-kunstmaan-migrator
./craft plugin/install kunstmaan-migrator
```

Then per-run:

```bash
./craft kunstmaan-migrator/doctor          # legacy-DB reachable, Anthropic key set, storage writable
./craft kunstmaan-migrator/analyze         # AI-assisted mapping proposal → storage/migration/mapping.yaml
# Operator hand-edits mapping.yaml (per-row status: proposed/accepted/dropped/needs-review)
./craft kunstmaan-migrator/map             # operator review fallback (CP utility is the canonical surface)
./craft kunstmaan-migrator/compile         # validates + writes storage/migration/PAGE-ROOTED-COVERAGE.md
./craft kunstmaan-migrator/migrate --dry-run
./craft kunstmaan-migrator/migrate --live
./craft kunstmaan-migrator/verify
./craft kunstmaan-migrator/rehearsal/check <dir>   # mechanical gates over a committed rehearsal dir; release-time only
```

`analyze` is the only stage that calls Anthropic. `compile`, `migrate`, `finalize`, `verify` are deterministic.

Asset behavior (see README §"Asset behavior" for the full policy): JIT by default — assets are pulled as migrated entries reference them. `--preload-assets` still follows the page-driven model and preloads **referenced assets only** from in-scope transformed payloads. Orphan `kuma_media` rows are never imported.

## High-level architecture

The plugin is a knowledge-first ETL pipeline. Source-of-truth wiring is `src/Plugin.php::init()` — every service is registered as a bare Yii component in `config()` and gets its sibling deps injected in `init()`. Adding a new service almost always means adding a `config()` entry **and** wiring its `public ?Foo $dep = null` slots in `init()`; missed wiring produces silent NPEs at first call.

Pipeline stages (`src/<stage>/`) and their orchestrators (`src/workflow/<Stage>Workflow.php`, also reachable via `src/console/<Stage>Controller.php`):

- **source/** — Read-only introspection of the legacy codebase + DB. Path resolver, Doctrine entity parser, detail-table resolver, body-column finder, media-FK scanner, two knowledge bases (Kunstmaan and Craft) that render markdown for LLM prompts, plus page/entry walkers.
- **analyze/** — Heuristic + LLM-assisted mapping proposer. `HeuristicProposer` runs 9 deterministic heuristics; `LlmClassifier` calls Anthropic Haiku in batches; `ReportBuilder` emits the paste-ready report.
- **mapping/** — `MappingFile` IO (single `mapping.yaml`, per-row `status:`), `MappingAuditor` + `CoverageAuditor` for review, `BlockAvailabilityValidator` for matrix-block target sanity.
- **compile/** — `MappingCompiler` bridges proposals[] → nodeClasses/sections/sites. `CraftTargetIntrospector` validates the compiled target.
- **audit/** — `PageRootedSurfaceDiscovery` walks the page-rooted surface graph; `PageRootedCoverageAuditor` writes the `PAGE-ROOTED-COVERAGE.md` operator artifact during `compile`.
- **extract/** — `ExtractService` pulls page+detail+page-part rows in topological order; honors `joinFkRelations` for `_rel:<prop>.<col>` ManyToOne joins.
- **transform/** — `TransformService` runs each row through `fields/FieldHandlerRegistry` (PlainText with sub-modes, Asset, Relation, Matrix, SplitName handlers). CKEditor rewrite + asset-path resolution happen here pre-load.
- **load/** — Per-entry atomic save (no `--atomic` flag — atomic-always-on). `AtomicMigrationService` orchestrates `EntryMigrationService` + `AssetMigrationService`. Adapter services (`SeoMigrationService` + `SeomaticPayloadBuilder`, `RedirectMigrationService`, `NavigationMigrationService`, `TranslationMigrationService`, `TaxonomyMigrationService`) run as siblings; SEOmatic/Retour/Navigation detection is runtime via `Craft::$app->plugins->getPlugin(...)` — never `composer require` (mirrored in `composer.json::suggest`, not `require`).
- **finalize/** — Post-load top-level walk. `FinalizeWalker` + `CkeditorRewriterService` resolve cross-entry references that needed all entries saved first.
- **verify/** — `BaselineCounterService`, `CountGateService`, `SnapshotDiffer` (deferred `--deep` flag), `SpotCheckUrlFetcher`, `CaptureBaselineHtmlService`.
- **filter/** — `MigrationFilters` value object piped through every stage (entity allow-list, locale subset, `--since`, `--max-per-entity`). `FilterFactory` merges CLI args with Settings.
- **safety/** — `MigrationSafety` + `MigrationGateService` (run-precondition gating consumed by both the CLI controllers and the CP queue adapter). `NeverProductionTrait` lives at `src/NeverProductionTrait.php` and is applied at the controller seam.
- **runs/** — `MigrationRunService` persists run records into `migration_runs` (schema in `migrations/m260429_000001_create_migration_runs.php`); rows are `records/MigrationRunRecord` ActiveRecords.
- **queue/** — `queue/jobs/MigrationPipelineJob` + `MigrationStageJob` dispatch long-running stage execution off the request thread. The CP `controllers/MigrationConsoleController` enqueues jobs against a `MigrationRunRecord` instead of running workflows inline.
- **controllers/** — Web (CP) controllers: `MappingController` powers the mapping-review utility, `MigrationConsoleController` is the CP queue adapter for analyze/compile/migrate/verify.
- **db/** — `LegacyDbService` owns the plugin-private legacy MySQL connection (the "plugin-owned legacy DB connection" ground rule); consumers never declare a `legacyDb` Yii component.
- **models/** — `Settings` is the env-overridable Yii Model backing plugin Settings.
- **migrations/** — `Install.php` is the plugin install migration; `m260429_*` adds the `migration_runs` table.
- **locale/** — `LocalePreflight` detects `kuma_node_translations.lang` values and resolves them to Craft site handles via the Settings ladder. `Plugin::resolveSitesMap()` prefers operator-curated `mapping.yaml::sites:` over preflight guesswork.
- **utilities/** — `KunstmaanMappingUtility` is the CP-first mapping review surface (registered in `Plugin::init()` for web requests only).

Console controllers live in `src/console/`: `Doctor`, `Analyze`, `Map`, `Compile`, `Migrate`, `Verify`, `Rehearsal`. The first six are the operator workflow; `Rehearsal` (`kunstmaan-migrator/rehearsal/check <dir>`) runs three mechanical gates over a committed rehearsal directory (e.g. `.planning/rehearsal/v1.0/cqm`) and is release-time only.

State flows through `MigrationStateService` (`load/`), which writes `migration_state` records mapping legacy keys → Craft ids; downstream stages (asset resolution, ckeditor rewrite, taxonomy resolution, SEO/redirect sidecars) read through `MigrationStateReader`.

## Architectural ground rules

These are locked decisions (see `.planning/PROJECT.md` Key Decisions). Don't casually reverse them.

- **Single `mapping.yaml`** with per-row `status:` (`proposed` / `accepted` / `dropped` / `needs-review`). No `.draft` and no `mapping-drops-{ts}.yaml` files.
- **Optional SEOmatic / Retour adapters.** Detect at runtime via `Craft::$app->plugins->getPlugin(...)`. Don't add either to composer `require` — they belong in `composer.json::suggest` (already wired) and stay there.
- **Filter spec from day one.** A `MigrationFilters` value object piped through every stage. v1 surface: entity allow-list, locale subset, `--since=YYYY-MM-DD`, `--max-per-entity=N`.
- **Plugin-owned legacy DB connection.** No Yii `legacyDb` component required in the consumer site's `config/app.php`. `Plugin::init()` registers it from Settings/env only when the host hasn't already declared one (zero churn for v1.x→v2 swap-in hosts).
- **CP-first mapping review.** The operator mapping-review surface is a Craft CP utility backed by the single `mapping.yaml`; CLI commands remain useful fallback/automation surfaces. Do not add a CP pipeline runner that performs migration stages without the existing dev/staging guards.
- **Atomic-always-on.** No `--atomic` flag. Per-entry atomic load is the default and only mode.
- **JIT assets.** `--preload-assets` is opt-in and preloads referenced-only assets from in-scope transformed payloads. Don't silently revert to always-preload, and don't import orphan `kuma_media` rows.
- **Runtime-zero-AI.** Anthropic is called only during `analyze`. Every other stage must be deterministic.
- **No `.claude/skills/` bundle.** Mapping review lives in the plugin's CP utility with `kunstmaan-migrator/map` as a CLI fallback; consumers don't need copied skill files.
- **`NeverProductionTrait`** gates every legacy-reading and destructive command at the controller seam — not at the service level.

## Test discipline

The v1.x plugin shipped 1.0 with no tests ("deliberately skipped"). We don't repeat that. Transform-stage characterization tests are required before Phase 3 ships. PHPUnit 11 under `tests/`, with `Unit` and `Integration` testsuites. The release rehearsal mode (`RELEASE_REHEARSAL=1`) fails loudly on an empty fixture corpus — required before tagging v1.0.

## Project planning context

- **Project doc:** `.planning/PROJECT.md`
- **Requirements:** `.planning/REQUIREMENTS.md`
- **Roadmap:** `.planning/ROADMAP.md`
- **State:** `.planning/STATE.md`
- **Config:** `.planning/config.json`
- **Phases:** `.planning/phases/<NN-name>/` with `PLAN.md`, `EXECUTION.md`, review artifacts

Read these before making non-trivial changes.

## Brownfield reference

When porting machinery, check the v1.x plugin at `~/Sites/craft-kunstmaan-migrator/src/` first to see how it solved the same problem. The critical review distilled in `.planning/PROJECT.md` (Context section) lists what to keep verbatim and what to redesign — don't blindly port the over-engineered bits (three-tier layout + Deptrac, three mapping files, three operator UI surfaces, hard composer pins on SEOmatic/Retour, the Yii `legacyDb` component leaking into consumer config, the `.claude/skills/` bundle).

## GSD workflow

Phases live under `.planning/phases/`. Common commands:
- `/gsd-discuss-phase <N>` — gather context + clarify approach
- `/gsd-plan-phase <N>` — create the plan
- `/gsd-execute-phase <N>` — run the plan
- `/gsd-progress` — see where we are
