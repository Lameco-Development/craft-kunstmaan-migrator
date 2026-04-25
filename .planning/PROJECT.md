# Kunstmaan → Craft Migrator (revisited)

## What This Is

A Craft CMS 5 plugin that migrates **content** from a legacy Kunstmaan (Symfony) site into an existing Craft CMS site. Craft is the source of truth for schema — Kunstmaan content gets mapped onto Craft sections/fields/entry types as they already exist. Anything in Kunstmaan that has no place in Craft is dropped, deliberately and visibly.

This is a clean rewrite of the existing `lameco/craft-kunstmaan-migrator` (located at `~/Sites/craft-kunstmaan-migrator`), informed by what worked and what didn't in the v1.x build. The goal is the same general functionality at roughly half the surface area, with cleaner seams, optional integrations, first-class filtering, and a tighter operator workflow.

## Core Value

**An operator can take a Kunstmaan SQL dump and a configured Craft site, walk through an AI-assisted mapping review, and end up with a faithful migration of content into Craft — predictably, idempotently, and with a clear record of what was migrated and what was dropped.**

If everything else fails, that one workflow must work.

## Requirements

### Validated

(None yet — ship to validate)

### Active

See `.planning/REQUIREMENTS.md` for the full breakdown with REQ-IDs.

Headline scope:
- [ ] Read a Kunstmaan MySQL dump via plugin-owned connection (no host-config leakage)
- [ ] AI-assisted mapping proposals (Anthropic) with deterministic-heuristics-first routing
- [ ] Stateful single-file mapping with per-row status (proposed/accepted/dropped/needs-review)
- [ ] One canonical CLI rubber-stamp loop for review
- [ ] ETL pipeline: extract → transform → load → finalize → verify
- [ ] Filter spec from day one (entity allow-list, locale subset, `--since` date floor)
- [ ] Optional SEOmatic + Retour adapters (detected at runtime, not required deps)
- [ ] CKEditor body-token rewrite (cross-entry / media references)
- [ ] Per-entry atomic load + idempotent re-runs via state table
- [ ] Verify gate: counts + URL spot-check against a captured baseline
- [ ] Tests on the Transform stage from day one (PHPUnit fixtures from a real dump)

### Out of Scope (v1)

- **Building a Craft site from scratch using `~/Sites/craft-starter-kit`** — Roadmapped as a future milestone (v2.0). Today the plugin assumes Craft sections/fields already exist.
- **A Control Panel pipeline runner UI** — The existing plugin's "Migration Pipeline" CP utility is dropped. CLI is the only operator surface in v1. A read-only CP status mirror may land later if it pulls its weight.
- **Inline mapping authoring in the CP** — No editor in the CP utility. CLI is canonical (the v1 plugin's "reviewer-only CP utility" decision is preserved, but we don't ship a CP utility at all in v1.0).
- **The `.claude/skills/` skill bundle** — Dropped. The rubber-stamp loop is just a CLI command; consumers don't need to copy skill files.
- **Multi-provider AI** — Anthropic only for now. Abstraction can land later if a real need surfaces.
- **Production-environment migration** — `NeverProductionTrait` hard-blocks `CRAFT_ENVIRONMENT=production`. Plugin is a dev-host tool only.
- **Three-tier `kunstmaan/` ⇄ `craft/` ⇄ `bridge/` source layout with Deptrac** — Replaced with a flatter, vertical-slice-friendly structure. Tier isolation was over-engineered for the problem.
- **Multiple mapping files (`mapping.yaml.draft`, `mapping-drops-{ts}.yaml`)** — Single mapping file with per-row status replaces all three.
- **Atomic flag, runtime AI calls, always-on asset preload** — Resolved decisions from v1.1 carried forward: atomic-always (transactional rollback per entry), runtime-zero-AI, JIT assets driven by page/entry references.
- **Migrating orphan assets** — Out of scope for v1. The migration is page-driven by design (see Migration model below) — only assets actually referenced from migrated entries get pulled in. A post-run "sync remaining" pass for un-referenced media is roadmapped (`NEXT-05`).

## Context

### What we're rewriting from

`~/Sites/craft-kunstmaan-migrator` (v1.1, 92 PHP files / ~23k LOC). Mature and battle-tested against the CQM rehearsal site (570 entries / 2,732 assets / 205 redirects / 11 taxonomies / 3,420 SEOmatic bundles), but accreted scope: 20+ CLI commands, 8 queue jobs, 3 operator UI surfaces, 3 mapping files, hard composer pins on SEOmatic/Retour, a `legacyDb` Yii component required in the consumer site's `config/app.php`, and a separate `.claude/skills/` bundle copied via `cp -r`.

### What works in v1 — port without redesign

- AI proposal pipeline with **9 deterministic heuristics first**, then Anthropic Haiku for residuals, with confidence tiers routing to direct-merge / draft / drop.
- `NeverProductionTrait` hard gate on destructive ops.
- Topological ordering of entity migrations so FKs resolve.
- CKEditor `[NT<id>]` / `[M<id>]` deferred-token rewrite + finalize pass.
- Idempotent install + state table for re-runs (`kunstmaanmigrator_state`).
- Per-entry atomic load (`--atomic` flag was correctly removed in 1.1; atomic stays always-on).
- `kunstmaanSourceId` field reuse for tracking continuity.

### What changes

- **Source layout:** flatter, by stage and concern, not by direction-of-data-flow tier. Deptrac retired.
- **CLI surface:** ~5 commands (`doctor`, `analyze`, `map`, `migrate`, `verify`) instead of 20+.
- **Mapping persistence:** single `mapping.yaml` with per-row `status:` field. Anthropic proposes into it; the rubber-stamp loop edits in place.
- **DB connection:** plugin owns it from env vars + plugin settings. No `legacyDb` Yii component leaks into consumer `config/app.php`.
- **SEOmatic / Retour:** optional adapters, detected at runtime. Plugin installs cleanly on hosts that have neither.
- **Filter spec:** `MigrationFilters` model piped through every stage from day one. v1 surface is entity allow-list, locale subset, `--since=YYYY-MM-DD`. Designed to grow.
- **Tests:** PHPUnit 11 from day one, with characterization fixtures on the Transform stage. No "tests deliberately skipped in 1.0" this time.
- **No skill bundle.** No CP runner utility. No Phase 8 D-08-XX traceability tables in user-facing docs.

### Migration model

The migration is **page-driven**. Entries are the unit of work; assets, taxonomies, and relations get pulled in lazily as references are encountered while transforming and loading entries. Two consequences worth naming:

- **Faster, more predictable runs.** We never iterate the full legacy media table; we only touch the rows actually used by entries that pass the filter spec.
- **Orphan media is expected.** Assets in the legacy DB that no migrated entry references are not migrated. This is intentional, not a bug. The trade-off is acceptable because Craft is the schema-leading side: if no entry needs a given asset, there's no Craft home for it.
- **Deferred CKEditor token resolution** (`[NT<id>]` / `[M<id>]`) is part of the same model. Tokens that point at not-yet-migrated entries get re-resolved in `migrate/finalize` once all referenced entries exist.

The post-run "sync remaining media" sweep is roadmapped as `NEXT-05` for cases where stakeholders want every legacy asset, referenced or not, to land in Craft.

### Operator workflow (target shape)

```bash
./craft kunstmaan-migrator/doctor
./craft kunstmaan-migrator/analyze            # schema dump + AI proposals into mapping.yaml
./craft kunstmaan-migrator/map                # interactive rubber-stamp loop (a/d/r/s/q)
./craft kunstmaan-migrator/migrate            # dry-run by default
./craft kunstmaan-migrator/migrate --live
./craft kunstmaan-migrator/verify             # parity gate vs captured baseline
```

Each command accepts the filter flags (`--entities=...`, `--since=...`, `--locales=...`, `--max-per-entity=...`).

## Constraints

- **Tech stack:** PHP 8.3+, Craft CMS 5 (`^5.0`), PHPUnit 11, Symfony YAML 6+, GuzzleHttp 7+. Composer type `craft-plugin`.
- **AI provider:** Anthropic only. API key required for `analyze` (proposal stage). All other stages are zero-AI.
- **Environments:** dev / staging only. `NeverProductionTrait` hard-blocks production.
- **Compatibility:** must coexist with v1.x plugin on a host site (separate composer package handle is fine; field UID for `kunstmaanSourceId` is preserved if v1 has already attached it).
- **Tests required for ship:** Transform stage characterization tests must be green; integration smoke against a rehearsal Kunstmaan dump must pass before any release tag.
- **Performance budget:** rehearsal-scale dumps (≤5k entries, ≤10k assets) should complete `migrate --live` in under 30 minutes on a developer laptop. Larger sites are a v2 concern.

## Key Decisions

| Decision | Rationale | Outcome |
|----------|-----------|---------|
| Single `mapping.yaml` with per-row `status:` | Replaces v1's three-file scheme (`mapping.yaml` + `.draft` + `mapping-drops-{ts}.yaml`). One source of truth, easier diffs and PRs. | — Pending |
| Optional SEOmatic / Retour adapters | v1 hard-required both via composer; that prevented installation on hosts that didn't use them. Runtime detection is the right shape. | — Pending |
| Filter spec from day one | Bolt-on filtering is painful; user has already named `--since` and entity scoping as future needs. Cheap to design in now. | — Pending |
| Writer seam (LiveCraftWriter / ProjectConfigWriter) deferred | User asked for it on the roadmap, not in v1. Avoid premature abstraction; revisit when the starter-kit milestone starts. | — Pending |
| Anthropic-only AI | Pragmatic. Multi-provider abstraction has a real cost and no current driver. | — Pending |
| Drop the three-tier `kunstmaan/`/`craft/`/`bridge/` layout + Deptrac | Mechanism without proportionate benefit at this codebase size. Vertical-slice-by-stage is easier to navigate. | — Pending |
| Drop `.claude/skills/` bundle | Fragile (`cp -r` from `vendor/`), and the rubber-stamp loop is fully expressible as a CLI command. | — Pending |
| Drop the CP "Migration Pipeline" runner utility | Three operator surfaces in v1 were one too many; the CLI is canonical. | — Pending |
| Tests required from day one | v1's "test suite deliberately skipped in 1.0" was a regret. Transform-stage characterization tests are the cheapest insurance against regression. | — Pending |
| Page-driven migration (entries are the unit of work; assets/relations pulled in lazily) | Faster, more predictable runs. Orphan media is acknowledged as a deliberate trade-off — Craft schema leads, so unreferenced legacy assets have no Craft home. Post-run "sync remaining media" is `NEXT-05`. | — Pending |

## Evolution

This document evolves at phase transitions and milestone boundaries.

**After each phase transition** (via `/gsd-transition`):
1. Requirements invalidated? → Move to Out of Scope with reason
2. Requirements validated? → Move to Validated with phase reference
3. New requirements emerged? → Add to Active
4. Decisions to log? → Add to Key Decisions
5. "What This Is" still accurate? → Update if drifted

**After each milestone** (via `/gsd-complete-milestone`):
1. Full review of all sections
2. Core Value check — still the right priority?
3. Audit Out of Scope — reasons still valid?
4. Update Context with current state

---
*Last updated: 2026-04-25 after initialization*
