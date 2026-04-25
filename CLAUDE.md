# CLAUDE.md

Guidance for Claude Code working in this repo.

## What this is

`lameco/craft-kunstmaan-migrator` (revisited) — a clean rewrite of the Craft 5 plugin that migrates legacy Kunstmaan content into Craft. Greenfield repo; the v1.x plugin at `~/Sites/craft-kunstmaan-migrator` is the brownfield reference.

PHP 8.3+, Craft CMS 5, PSR-4 namespace `lameco\kunstmaanmigrator\` under `src/`, composer type `craft-plugin`. Anthropic-only AI (proposal stage), runtime-zero-AI everywhere else, dev/staging only (`NeverProductionTrait` hard-blocks production).

## Project context

- **Project doc:** `.planning/PROJECT.md`
- **Requirements:** `.planning/REQUIREMENTS.md`
- **Roadmap:** `.planning/ROADMAP.md`
- **State:** `.planning/STATE.md`
- **Config:** `.planning/config.json`

Read these before making non-trivial changes. The project follows the GSD workflow — phases live under `.planning/phases/`, each with `PLAN.md`, `EXECUTION.md`, and review artifacts.

## Brownfield reference

When porting machinery, always check `~/Sites/craft-kunstmaan-migrator/src/` first to see how v1.x solved the same problem. The critical review distilled in `.planning/PROJECT.md` (Context section) lists what to keep verbatim and what to redesign — don't blindly port the over-engineered bits (three-tier layout + Deptrac, three mapping files, three operator UI surfaces, hard composer pins on SEOmatic/Retour, the Yii `legacyDb` component leaking into consumer config, the `.claude/skills/` bundle).

## Architectural ground rules

These are locked decisions (see `PROJECT.md` Key Decisions). Don't casually reverse them.

- **Single `mapping.yaml`** with per-row `status:` (`proposed` / `accepted` / `dropped` / `needs-review`). No `.draft` and no `mapping-drops-{ts}.yaml` files.
- **Optional SEOmatic / Retour adapters.** Detect at runtime via `Craft::$app->plugins->getPlugin(...)`. Don't add either to composer `require`.
- **Filter spec from day one.** A `MigrationFilters` value object piped through every stage. v1 surface: entity allow-list, locale subset, `--since=YYYY-MM-DD`, `--max-per-entity=N`.
- **Plugin-owned legacy DB connection.** No Yii `legacyDb` component required in the consumer site's `config/app.php`. Connection comes from env vars + plugin settings.
- **CLI-only operator surface.** No CP "Migration Pipeline" runner utility, no inline mapping editor in the CP. CLI is canonical.
- **Atomic-always-on.** No `--atomic` flag. Per-entry atomic load is the default and only mode.
- **JIT assets.** `--preload-assets` is opt-in. Don't silently revert to always-preload.
- **Runtime-zero-AI.** Anthropic is called only during `analyze`. Every other stage must be deterministic.
- **No `.claude/skills/` bundle.** The rubber-stamp loop is just a CLI command — `kunstmaan-migrator/map`.
- **`NeverProductionTrait`** gates every legacy-reading and destructive command.

## Test discipline

The v1.x plugin shipped 1.0 with no tests ("deliberately skipped"). We don't repeat that. Transform-stage characterization tests are required before Phase 3 ships. PHPUnit 11 under `tests/`, run via `composer test`.

## GSD workflow

Run `/gsd-discuss-phase 1` to start Phase 1. Phase commands available:
- `/gsd-discuss-phase <N>` — gather context + clarify approach
- `/gsd-plan-phase <N>` — create the plan
- `/gsd-execute-phase <N>` — run the plan
- `/gsd-progress` — see where we are
