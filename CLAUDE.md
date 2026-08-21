# CLAUDE.md

Guidance for Claude Code working in this repo.

## What this is

`lameco/craft-kunstmaan-migrator` — a Craft 5 plugin that migrates a legacy Kunstmaan (Symfony) site into Craft. Public, on Packagist.

PHP 8.3+, Craft CMS 5, composer type `craft-plugin`. Two PSR-4 roots:

- `lameco\kunstmaanmigrator\` → `src/` — the write half. Owns the Craft side: payload validation against the live schema, the idempotent state table, entry/asset writes, the adapters, both operator surfaces.
- `Lameco\KumaCompile\` → `lib/kuma-compile/` — the compile half. Reads the legacy database and the mapping and emits payloads; **knows nothing about Craft**. It was a separate repo on one laptop until 2026-08-21; the namespace was kept on the merge so that boundary stays legible. Standalone CLI: `php lib/kuma-compile/bin/kuma-compile list`.

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

Don't casually reverse these.

- **The mapping owns the topology.** Which databases exist, where each one's uploads are, which legacy locale writes to which Craft site — all of it lives in the version-controlled mapping, next to the field mappings it travels with. Settings owns the connection and the adapter switches, nothing more. A control-panel form is worse than a YAML file at exactly the parts a real corpus needs: an ordered media-root fallback chain, and a locale marked "deliberately not migrated, and here is why".
- **Nothing secret reaches project config.** Craft persists plugin settings into project config, which is committed and deployed. The credential fields store an environment variable *name*; `Settings::validateIsEnvReference()` refuses a value. Never write a resolved value onto a Settings attribute.
- **Optional adapters, detected at runtime** via `Craft::$app->plugins->getPlugin(...)`. SEOmatic, Retour and Navigation stay out of composer `require`.
- **One pipeline, two callers.** `EnvironmentPipeline` is what the console command and the queue job both run. They must not assemble a run differently — the compile and load halves already drifted apart once when a file was the contract between them.
- **Adapters come from the registry.** A pass that runs after an environment's entries is a `MigrationAdapter` with an `Adapter` row and a factory. Don't add a fifth hard-coded call site; `redirects` is the one documented exception and says why.
- **Per-environment facts are parameters, not properties.** `SiteMap` already made this trip. The three that remain — the environment name and the two media-root properties — are the standing follow-up, and the reason a cache once outlived its database.
- **Atomic always on**, per-entry. No `--atomic` flag.
- **JIT assets.** `--preload-assets` is opt-in.
- **Deterministic at run time.** No AI in any stage.
- **`NeverProductionTrait` / `ProductionGuard`** gate every legacy-reading and destructive path — command, queue job and control-panel action alike.

## Test discipline

The v1.x plugin shipped 1.0 with no tests. We don't repeat that. PHPUnit 11, `composer test`.

The suite cannot boot a Craft application, so anything reaching `Craft::$app` is only testable through a seam. Two exist and both are real — `ElementWriter` (Craft's element writes) and `NavigationGateway` (verbb) — each with a production adapter and an in-memory one, held in place by `WriteBoundaryTest`. **Put new Craft coupling behind a seam rather than adding a call site**, and give it a second adapter; one adapter is a hypothesis, two is a seam.

`tools/check-coverage.php` gates named modules at 70%. Its `WATCHED` list is reported, not gated — those are the write-half modules the `ElementWriter` seam made testable, waiting on a measured number before they graduate. A module named in either list that CI cannot find is a hard failure: a stale gate reads as coverage that is not there.

## Reviews

`.planning/` holds the GSD phase history and is a record, not a plan — the phases it describes are finished. The live assessments live in the consuming project, `enreach-craft-website/docs/migration/`: `AUDIT.md` (what the migration measured against the real corpus), `ARCHITECTURE-REVIEW.md` (why the code let those defects happen) and `PLUGIN-REVIEW.md` (Craft conventions, seams, coverage, and the run start to finish). Read them before non-trivial work; each records what is still open and in what order.
