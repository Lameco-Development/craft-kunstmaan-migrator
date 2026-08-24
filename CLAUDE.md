# CLAUDE.md

Guidance for Claude Code working in this repo.

## What this is

`lameco/craft-kunstmaan-migrator` — a Craft 5 plugin that migrates a legacy Kunstmaan (Symfony) site into Craft. Public, on Packagist.

PHP 8.3+, Craft CMS 5, composer type `craft-plugin`. One PSR-4 root, `Lameco\Kunstmaanmigrator\` → `src/`, packaged by contract with an enforced dependency direction:

- **The kernel** (CamelCase packages): `Payload\` (the payload VO, `SourceUid`, the validator — depends on nothing), `Source\` (legacy Kunstmaan access), `Mapping\` (the program: schema, document, skeleton, editor model), `Target\` (Craft's content model read from project config, plus the `TargetSchema` port), `Compile\` (Source + Mapping + Target → Payload), `Report\` (coverage, readiness, survey), `Command\` (the standalone `bin/kuma-compile` adapters). **Craft-schema-aware but Craft-runtime-free**: it parses Craft project config (`Target\CraftSchema`) to model targets, but never names a `craft\`/`yii\` symbol nor a Craft-side package — enforced by `phpstan/LibPurityRule`, by package list, `tests/kernel` included. It was a separate repo (`Lameco\KumaCompile\`, then `lib/kuma-compile/`) until the 2026-08-24 consolidation. Standalone CLI: `php bin/kuma-compile list`.
- **The Craft side** (lowercase packages, Craft's convention): `craft\` (the gateways — every Craft coupling behind a seam with an in-memory twin — plus `TargetModel`, the live `TargetSchema`), `load\` (Payload → Craft: the saver, the state table, the migration services), `adapters\`, `finalize\`, `run\` (the pipeline, diagnostics, run panel), `editor\` (the CP mapping editor), `safety\`, and Craft's own `console\`, `controllers\`, `queue\`, `models\`, `migrations\`, `utilities\`, `web\`.

Deterministic throughout — no AI at run time. Dev/staging only; `NeverProductionTrait` and `ProductionGuard` hard-block `CRAFT_ENVIRONMENT=production` on every command, job and control-panel action.

## Branches

`development` is the integration branch and everything lands there first. `main` receives from `development`. `v2-loader` is historical — it stopped receiving work at PR #19 and consumers pinned to `dev-v2-loader` are frozen there.

## Project context

- **Project doc:** `.planning/PROJECT.md`
- **Requirements:** `.planning/REQUIREMENTS.md`
- **Roadmap:** `.planning/ROADMAP.md`
- **State:** `.planning/STATE.md`
- **Config:** `.planning/config.json`

Read these before making non-trivial changes. The project follows the GSD workflow — phases live under `.planning/phases/`, each with `PLAN.md`, `EXECUTION.md`, and review artifacts.

## Architectural ground rules

Don't casually reverse these. Each is recorded with its constraint in `docs/adr/` — read the record before proposing the alternative; reopen one only when its constraint has changed.

- **The mapping owns the topology.** Which databases exist, where each one's uploads are, which legacy locale writes to which Craft site — all of it lives in the version-controlled mapping, next to the field mappings it travels with. A control-panel form is worse than a YAML file at exactly the parts a real corpus needs: an ordered media-root fallback chain, and a locale marked "deliberately not migrated, and here is why".
- **The Settings screen states what config decided; it does not decide anything machine-level.** The connection, the mapping path and asset placement are `config/kunstmaan-migrator.php` + `.env` concerns — a form that wrote them into project config would ship one developer's local paths and credentials to everyone else. The `Settings` *model* still carries those properties (for the config file to populate), but the CP screen only offers the adapter on/off switches and each adapter's own settings — what genuinely varies per Kunstmaan install — plus a read-only summary of what's currently in force.
- **Nothing secret reaches project config.** Craft persists plugin settings into project config, which is committed and deployed. The credential fields store an environment variable *name*; `Settings::validateIsEnvReference()` refuses a value. Never write a resolved value onto a Settings attribute.
- **Optional adapters, detected at runtime** via `Craft::$app->plugins->getPlugin(...)`. SEOmatic, Retour, Navigation, Formie and spicyweb/craft-embedded-assets stay out of composer `require`.
- **The environment job is batched, not monolithic.** `MigrateEnvironmentJob extends craft\queue\BaseBatchedJob`, driven by `CompilerRun` + the unit primitives on `Compiler` (`compileNodeUnit`, `compileEntitySlice`, `catchUpStructural`, `finishStructural`) rather than the single `compile()` walk. A batch is a fresh `CompilerRun` every execution; a resumed batch calls `catchUpStructural()` to silently re-register the placeholders an earlier batch already emitted, against a no-op emit. The chain (`RunAdaptersJob` pushing the next environment, then the corpus-wide fixup/finalize) is what replaces FIFO-hopeful ordering with a structural guarantee — don't reach for `getQueue()->push()` directly on these jobs; use `QueueHelper::push()` so priority/TTR propagate to spawned continuations.
- **One pipeline, two callers.** `EnvironmentPipeline` is what the console command and the queue job both run. They must not assemble a run differently — the compile and load halves already drifted apart once when a file was the contract between them.
- **Adapters come from the registry.** A pass that runs after an environment's entries is a `MigrationAdapter` with an `Adapter` row and a factory. Don't add a fifth hard-coded call site; `redirects` is the one documented exception and says why.
- **Per-environment facts are parameters, not properties.** `SiteMap` already made this trip. The three that remain — the environment name and the two media-root properties — are the standing follow-up, and the reason a cache once outlived its database.
- **Atomic always on**, per-entry. No `--atomic` flag.
- **JIT assets.** Assets materialise on demand; `ingestReferenced()` is an API for a caller
  that already holds an id set, not a console flag — a preload would need a compile-then-load
  two-phase run that does not exist.
- **Deterministic at run time.** No AI in any stage.
- **`NeverProductionTrait` / `ProductionGuard`** gate every legacy-reading and destructive path — command, queue job and control-panel action alike.

## Test discipline

The v1.x plugin shipped 1.0 with no tests. We don't repeat that. PHPUnit 11, `composer test`.

The suite cannot boot a Craft application, so anything reaching `Craft::$app` (or a third-party plugin's static facade) is only testable through a seam. Four exist and all are real — `ElementWriter` (Craft's element writes), `NavigationGateway` (verbb/navigation), `FormGateway` (verbb/formie) and `EmbedGateway` (spicyweb/craft-embedded-assets) — each with a production adapter and an in-memory one, held in place by `WriteBoundaryTest`. **Put new Craft coupling behind a seam rather than adding a call site**, and give it a second adapter; one adapter is a hypothesis, two is a seam.

`tools/check-coverage.php` gates named modules at 70%. Its `WATCHED` list is reported, not gated — those are the write-half modules the `ElementWriter` seam made testable, waiting on a measured number before they graduate. A module named in either list that CI cannot find is a hard failure: a stale gate reads as coverage that is not there.

## Reviews

`.planning/` holds the GSD phase history and is a record, not a plan — the phases it describes are finished. The live assessments live in the consuming project, `enreach-craft-website/docs/migration/`: `AUDIT.md` (what the migration measured against the real corpus), `ARCHITECTURE-REVIEW.md` (why the code let those defects happen) and `PLUGIN-REVIEW.md` (Craft conventions, seams, coverage, and the run start to finish). Read them before non-trivial work; each records what is still open and in what order.
