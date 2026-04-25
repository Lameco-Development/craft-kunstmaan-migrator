# Requirements

v1 scope for `lameco/craft-kunstmaan-migrator` (revisited). All requirements are
hypotheses until shipped and validated by the rehearsal pass at the end of v1.

## v1 Requirements

### Foundation (FND)

- [ ] **FND-01**: Plugin scaffolds as a Craft 5 plugin (`composer.json` type `craft-plugin`, PSR-4 namespace `lameco\kunstmaanmigrator\` under `src/`, PHP 8.3+) and installs cleanly via `./craft plugin/install kunstmaan-migrator`.
- [ ] **FND-02**: Install creates a state table `kunstmaanmigrator_state` and attaches a `kunstmaanSourceId` Plain Text field. If a `kunstmaanSourceId` field UID already exists from the legacy v1.x plugin, the install reuses it instead of minting a new one.
- [ ] **FND-03**: Uninstall is a deliberate no-op on the state table and `kunstmaanSourceId` field — operator must remove manually for a full wipe.
- [ ] **FND-04**: `NeverProductionTrait` hard-blocks every legacy-reading or destructive command when `CRAFT_ENVIRONMENT=production`.
- [ ] **FND-05**: PHPUnit 11 test suite scaffolded under `tests/`, wired to a `composer test` script and a CI workflow. Suite is non-empty on day one (smoke test on plugin bootstrap).

### Connectivity (CONN)

- [ ] **CONN-01**: Plugin owns the legacy DB connection internally — no Yii component required in the consuming site's `config/app.php`. Connection params come from env vars (`CRAFT_LEGACY_DB_*`) and/or plugin settings.
- [ ] **CONN-02**: Anthropic API key sourced from `ANTHROPIC_API_KEY` env var or plugin settings (settings override env if both present, never logged).
- [ ] **CONN-03**: `kunstmaan-migrator/doctor` command reports OK/FAIL on: legacy DB reachability, Anthropic key presence, mapping file validity, write permissions on `storage/migration/`.

### Schema + Mapping (MAP)

- [ ] **MAP-01**: `kunstmaan-migrator/analyze` reads the legacy DB and emits a schema dump under `storage/migration/schema-dump.json` plus a human-readable `REPORT.md`.
- [ ] **MAP-02**: Analyze runs the heuristic-first proposal pipeline: 9 deterministic heuristics (exact name, `*_id`→asset, `*_image`/`*_afbeelding`→asset, TEXT→ckeditor, `*_date`/`*_datum`→date, `*_url`/`*_link`→url, `*_email`→email, Dutch semantic alias map, zero-fill-rate auto-drop) before any LLM call.
- [ ] **MAP-03**: Residual columns are sent to Anthropic in entry-type-grouped batches with `sqlType` and sample values in the prompt, returning typed proposals with confidence tiers.
- [ ] **MAP-04**: All proposals (heuristic and LLM) land in a single `mapping.yaml` with a per-row `status:` field — `proposed`, `accepted`, `dropped`, `needs-review`. Existing rows are never overwritten without an explicit operator action.
- [ ] **MAP-05**: `kunstmaan-migrator/map` runs an interactive rubber-stamp loop walking every `proposed` / `needs-review` row: `[a]ccept / [d]rop / [r]emap / [s]kip / [q]uit`. `--auto-accept-high` non-interactively promotes high-confidence proposals.
- [ ] **MAP-06**: A coverage gate refuses to run `migrate --live` while any data-bearing legacy column has no mapping decision. `migrate` (dry-run) prints coverage warnings but does not block.
- [ ] **MAP-07**: A mapping-audit checks every `(entryType, fieldHandle)` pair declared in `mapping.yaml` against the live Craft FieldLayout and emits drift findings.

### Filtering (FILT)

- [ ] **FILT-01**: A `MigrationFilters` value object captures: included entity types (allow-list), locale subset, `--since=YYYY-MM-DD` floor, `--max-per-entity=N` cap.
- [ ] **FILT-02**: Filters apply uniformly through every stage (extract / transform / load / verify) — a row excluded at extract is also absent from verify counts.
- [ ] **FILT-03**: All five top-level CLI commands accept the filter flags. `analyze` uses them to scope schema scanning.

### Locale handling (LOC)

- [ ] **LOC-01**: `analyze` auto-detects Kunstmaan locales from `kuma_node_translations` and emits a paste-ready `sites:` YAML block when locales aren't yet mapped.
- [ ] **LOC-02**: A preflight gate FAILs hard on any unmapped locale (no silent default-locale fallthrough).

### ETL pipeline (ETL)

- [ ] **ETL-01**: `kunstmaan-migrator/migrate` runs extract → transform → load → finalize in sequence. Top-level command is dry-run by default; `--live` writes to Craft.
- [ ] **ETL-02**: Per-stage commands exist for resume (`migrate/extract`, `migrate/transform`, `migrate/load`, `migrate/finalize`).
- [ ] **ETL-03**: Topological ordering of entity migrations so foreign-key references resolve before they're written.
- [ ] **ETL-04**: Per-entry atomic load (always-on, no flag). On failure, the entry is rolled back; the next entry continues.
- [ ] **ETL-05**: Idempotent re-runs: a row already migrated (tracked via `kunstmaanmigrator_state.legacy_id`) is skipped or refreshed, never duplicated.
- [ ] **ETL-06**: Per-entry console progress (`[N/total] slug → created|updated|skipped`).
- [ ] **ETL-07**: `migrate/truncate` resets state for a fresh rehearsal without rolling the whole DB.

### Field handlers (FH)

- [ ] **FH-01**: Pluggable `FieldHandler` interface with built-in handlers: PlainText, Asset, Matrix, Relation, SplitName (Dutch composite-name splitter), CKEditor.
- [ ] **FH-02**: Handlers are registered in a `FieldHandlerRegistry` wired from the plugin bootstrap, allowing project-level overrides.
- [ ] **FH-03**: Asset handler supports JIT (default) and `--preload-assets` (opt-in batch ingest).
- [ ] **FH-04**: CKEditor handler emits `[NT<id>]` / `[M<id>]` deferred tokens for cross-entry / media references that don't yet exist at load time.

### Finalize + CKEditor rewriting (FIN)

- [ ] **FIN-01**: `migrate/finalize` walks every CKEditor field across every migrated entry and resolves `[NT<id>]` / `[M<id>]` tokens (plus URL-encoded `%5BNT<id>%5D` / `%5BM<id>%5D` forms).
- [ ] **FIN-02**: Strict policy on unresolvable tokens — left verbatim, annotated with `<!-- MIGRATION:UNRESOLVED ... -->`. No silent drops.

### Optional adapters (ADP)

- [ ] **ADP-01**: SEOmatic adapter — detected at runtime via `Craft::$app->plugins->getPlugin('seomatic')`. If present, migrate per-locale SEOmatic MetaBundles with default-locale fallback. If absent, skip with WARN. Plugin installs cleanly on hosts without SEOmatic.
- [ ] **ADP-02**: Retour adapter — same shape. Migrate redirects from `kuma_redirects` if Retour is installed; skip with WARN otherwise.
- [ ] **ADP-03**: Composer requirements list SEOmatic and Retour as `suggest`, not `require`.

### Verify (VER)

- [ ] **VER-01**: `kunstmaan-migrator/verify capture-baseline` snapshots pre-migration counts (entries, assets, taxonomies, redirects, SEOmatic bundles) into a JSON artifact.
- [ ] **VER-02**: Optional `verify capture-baseline-html` snapshots rendered HTML for a configurable URL set.
- [ ] **VER-03**: `verify` runs the parity gate — counts diff vs baseline plus optional URL spot-check — and writes a `VERIFY-<timestamp>.md` report under `storage/migration/` regardless of outcome.

### Settings + observability (CFG)

- [ ] **CFG-01**: CP Settings page (Settings → Plugins → Kunstmaan Migrator): Anthropic key (secret), LLM model, LLM timeout, default filters, `dryRunDefault` toggle.
- [ ] **CFG-02**: Console verbosity flag (`-v` through `-vvv`) toggles per-stage timing + asset RCA logging into `storage/migration/*.log`.
- [ ] **CFG-03**: A "rehearsal report" produced at the end of every `migrate --live` run summarising what was migrated, what was dropped, and why — distinct from `verify`'s parity gate.

### Tests (TST)

- [ ] **TST-01**: PHPUnit suite runs `tests/unit` and `tests/integration`. Unit suite covers `MigrationFilters`, `MappingLoader`, every field handler, `CkeditorRewriter`, and the heuristic proposer.
- [ ] **TST-02**: Characterization fixtures captured from a real Kunstmaan dump exercise the Transform stage end-to-end (golden-file diffs).
- [ ] **TST-03**: CI workflow runs `composer validate --strict`, PHPUnit, and a smoke test that the plugin loads in a scratch Craft install.
- [ ] **TST-04**: `kunstmaan-migrator/doctor` and the rehearsal smoke check are part of the release checklist before any tag.

## v2 Requirements (deferred)

- [ ] **NEXT-01**: Writer seam — abstract the Craft-write side behind a `Writer` interface so a future `ProjectConfigWriter` can scaffold sections / fields / entry types from a Kunstmaan dump + `~/Sites/craft-starter-kit` instead of writing into pre-existing schema.
- [ ] **NEXT-02**: Read-only CP status mirror — a small CP page that surfaces last run, mapping coverage, and counts. Not a runner.
- [ ] **NEXT-03**: Multi-provider AI — abstract the proposer behind an interface so OpenAI / local models can plug in.
- [ ] **NEXT-04**: Cross-client rehearsal matrix (Simac, Enreach, Joulz) — exercising the migrator against more than the CQM corpus.

## Out of Scope (v1)

- **Building Craft from scratch with the starter-kit** — Future milestone (NEXT-01).
- **CP "Migration Pipeline" runner utility** — Dropped from v1; CLI is canonical. Re-evaluate when there's a real operator complaint.
- **Inline mapping authoring in the CP** — Dropped. CLI rubber-stamp loop is the only authoring surface.
- **`.claude/skills/` skill bundle** — Dropped. Rubber-stamp loop is just a CLI command.
- **Three-tier `kunstmaan/` / `craft/` / `bridge/` source layout + Deptrac** — Replaced with a flatter, vertical-slice structure. Mechanism without proportionate benefit at this size.
- **Multiple mapping files** — Single `mapping.yaml` with per-row `status:` replaces `mapping.yaml.draft` and `mapping-drops-{ts}.yaml`.
- **Multi-provider AI** — Anthropic only in v1. Re-evaluate when a real driver appears (NEXT-03).
- **Production-environment migration** — `NeverProductionTrait` hard-blocks. Plugin is dev/staging only by design.
- **Atomic flag** — Atomic-always-on. v1.x's `--atomic` was the right thing to remove; we don't reintroduce it.

## Traceability

Filled in by the roadmap step. Every requirement above must map to exactly one phase.

| REQ | Phase |
|-----|-------|
| FND-01 … FND-05 | 1 |
| CONN-01 … CONN-03 | 1 |
| MAP-01 … MAP-07 | 2 |
| FILT-01 … FILT-03 | 2 |
| LOC-01, LOC-02 | 2 |
| ETL-01 … ETL-07 | 3 |
| FH-01 … FH-04 | 3 |
| FIN-01, FIN-02 | 3 |
| ADP-01 … ADP-03 | 4 |
| VER-01 … VER-03 | 4 |
| CFG-01 … CFG-03 | 4 |
| TST-01 … TST-04 | 5 |
