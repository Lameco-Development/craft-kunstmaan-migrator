# Changelog

All notable changes to `lameco/craft-kunstmaan-migrator` are documented in this
file. Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/);
versions follow [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.0.0 — <release-date>

Clean rewrite of the v1.x plugin. The operator workflow is now explicit:
`doctor -> analyze -> map -> compile -> migrate --dry-run -> migrate --live -> verify`.
Internally the migration pipeline still runs extract → transform → load →
finalize → verify, but reviewed mapping must be compiled before migration so
runtime blocks and release audit artifacts are present.

### Added

- **CLI-only operator surface** — `kunstmaan-migrator/migrate`, `analyze`,
  `verify`, `doctor`, `map`, and `rehearsal/check` console controllers cover
  every operator workflow. No CP "Migration Pipeline" runner utility; no
  inline mapping authoring in the CP.
- **Single `mapping.yaml` with per-row `status:`** — `proposed` /
  `accepted` / `dropped` / `needs-review`. Replaces v1.x's three-file scheme
  (`mapping.yaml.draft` + `mapping-drops-{ts}.yaml`).
- **`MigrationFilters` value object** — entity allow-list, locale subset,
  `--since=YYYY-MM-DD`, `--no-seo`, `--no-retour`. Piped through every stage.
- **Plugin-owned legacy DB connection** — host site does NOT need a
  `legacyDb` Yii component in `config/app.php`. Connection comes from env
  vars (`CRAFT_LEGACY_DB_*` or `DATABASE_URL` parsed from the Symfony
  `.env`/`.env.example` at `KUNSTMAAN_SOURCE_PATH`) + plugin Settings.
- **Optional SEOmatic + Retour adapters** — runtime detection via
  `Craft::$app->plugins->getPlugin(...)`. Neither plugin is in composer
  `require`. Settings flags (`seoEnabled`, `retourEnabled`) and CLI flags
  (`--no-seo`, `--no-retour`) override.
- **Atomic-always-on** — per-entry atomic load is the only mode. No
  `--atomic` flag.
- **JIT asset ingestion** — default is per-entry JIT. Opt-in
  `--preload-assets` preloads only assets referenced by the current in-scope
  transformed payloads; it does not import every legacy `kuma_media` row or
  orphan media by default.
- **`migrate sync-assets` recovery command** — re-ingests every `kuma_media`
  row a prior atomic run referenced but skipped (filesystem_404 /
  mime_mismatch / too_large / etc.). Idempotent. Permanently-failed assets
  get a terminal-state marker (`meta.terminalState='permanently_failed'`)
  that prevents retry loops.
- **`kunstmaan-migrator/doctor`** — deterministic boot checks for local
  operator configuration before analyze/compile/migrate. CI scratch-Craft
  smoke verifies plugin install/load semantics without pretending a full
  migration workflow is configured.
- **`kunstmaan-migrator/compile`** — converts reviewed `mapping.yaml` rows into
  runtime blocks and writes Page-rooted coverage evidence before migration.
- **Page-rooted coverage report** —
  `storage/migration/PAGE-ROOTED-COVERAGE.md` accounts for Kunstmaan Page-owned
  surfaces as `migrated`, `dropped`, `out_of_scope`, `unsupported`, or
  `warning` so release review can reject silent omissions.
- **`kunstmaan-migrator/rehearsal/check`** — read-only mechanical gate
  against committed rehearsal artifacts under
  `.planning/rehearsal/v1.0/{cqm,simac,enreach}/`. Three gates: counts
  within tolerance, zero unresolved CKEditor tokens, all assets RCA-tagged.
- **`MigrationStateService::markTerminal()` + `isTerminal()`** — terminal-
  state contract for permanently-failed rows. Reuses the existing `meta`
  JSON column; no schema migration.
- **AI-assisted mapping (analyze stage)** — Anthropic Haiku via the
  `analyze` CLI. 9 deterministic heuristics first; LLM only for residuals.
  Confidence tiers (`high` / `medium` / `low`) route proposals to
  `mapping.yaml` directly, the draft section, or drops with rationale.
  Runtime-zero-AI: every other stage is deterministic.
- **Characterization tests on the Transform stage** — per-row JSON fixtures
  under `tests/fixtures/transform/{input,golden}/`. Comparator JSON-
  canonicalizes (recursive ksort + JSON_PRETTY_PRINT) before diff to survive
  PHP version bumps. Refresh via `UPDATE_SNAPSHOTS=1`.
- **PHPUnit unit + integration testsuites** with per-module 70% line-
  coverage gate on `MigrationFilters`, `MappingFile`, every field handler,
  `CkeditorRewriterService`, and `HeuristicProposer`. Enforced in CI via
  `tools/check-coverage.php`.
- **CI workflow** — `.github/workflows/ci.yml` splits into `unit`
  (composer validate + phpunit + coverage gate) and `smoke` (scratch-Craft
  install + plugin path-repo + plugin-load/config-absence check). PHP 8.3 only.
- **Configuration via `config/kunstmaan-migrator.php`** — full operator
  example shipped at `config/kunstmaan-migrator.example.php`. Settings
  auto-fill blank `legacyDb*` from `DATABASE_URL` when present.
- **`NeverProductionTrait`** — every legacy-reading and destructive command
  hard-blocks `CRAFT_ENVIRONMENT=production`. Rehearsal-check command
  DELIBERATELY OMITS the trait (read-only over committed artifacts).

### Changed (vs v1.x)

- **Mapping persistence:** single `mapping.yaml` with `status:` per row
  (was: `mapping.yaml` + `mapping.yaml.draft` + `mapping-drops-{ts}.yaml`).
- **Adapter integration:** SEOmatic + Retour are runtime-detected and
  optional (was: hard composer pins on specific versions).
- **Legacy DB wiring:** plugin owns the connection from env vars (was:
  consumer site had to declare a Yii `legacyDb` component in `config/app.php`).
- **Atomic mode:** always-on, no flag (was: `--atomic` opt-in).
- **Operator surface:** CLI-canonical (was: CP "Migration Pipeline"
  utility + inline mapping editor; both removed).
- **Source layout:** flat `src/<concern>/` (was: three-tier
  `kunstmaan/` + `craft/` + `bridge/` + Deptrac).
- **AI provider:** Anthropic-only (was: multi-provider abstraction; v1.x
  shipped only Anthropic too, but the abstraction added complexity for
  no driver).
- **Test discipline:** PHPUnit 11 corpus from day one with per-module
  coverage gate (was: v1.x shipped 1.0 with no tests).

### Removed (vs v1.x)

- `--atomic` CLI flag (atomic is always-on)
- `mapping.yaml.draft` and `mapping-drops-{ts}.yaml` files (consolidated
  into `mapping.yaml` with per-row status)
- CP "Migration Pipeline" runner utility
- Inline mapping editor in the CP
- `.claude/skills/` skill bundle
- Three-tier `kunstmaan/` + `craft/` + `bridge/` source layout + Deptrac
- Hard composer requires on SEOmatic / Retour
- Yii `legacyDb` component requirement in consumer site `config/app.php`

### Security

- **`NeverProductionTrait`** gates every legacy-reading and destructive
  command on `CRAFT_ENVIRONMENT != production`. Plugin is dev/staging
  only by design. The runtime-zero-AI posture means the ETL path makes
  zero outbound LLM calls — only the `analyze` stage talks to Anthropic.
- **Anthropic API calls only during `analyze`** — no runtime AI in the
  ETL path; no API key required to run `migrate`.

### Release evidence

- **CQM executable rehearsal** — CQM is the configured Craft rehearsal target
  for v1.0 release evidence.
- **Simac and Enreach structural samples** — Simac and Enreach are source-shape
  samples only unless an operator explicitly configures separate Craft targets.
  They are used to catch CQM-only assumptions, not as mandatory runnable
  migration targets.
- **Page-rooted and source-shape audit evidence** — release review includes
  `PAGE-ROOTED-COVERAGE` plus structural source-shape audit output; proprietary
  source bodies, row data, and content samples are not committed as part of
  genericity evidence.

### Known omissions in v1.0

The following Kunstmaan surfaces are deliberately out of scope for v1.0:

- **FormBundle** — form schemas + submissions are not migrated.
- **SearchBundle** — Elasticsearch / search-index integration is not
  migrated.
- **MenuBundle** — menu trees are not migrated.
- **User accounts / roles / ACLs** — operator authentication and
  authorization are not migrated.
- **`kuma_translations`** — Kunstmaan's i18n string catalog (distinct
  from `ext_translations`, which Phase 8 DOES support for Gedmo
  Translatable taxonomies).
- **Media folder hierarchy** — `kuma_media_folder` parent_id chains are
  dropped at migrate time; assets land flat. May become a v1.1 phase if
  a real project demands it.
- **Asset metadata** — alt text, copyright, focal point. May become a
  v1.1 phase.
- **Slug history** — beyond `kuma_redirects`, Kunstmaan tracks slug
  changes that could feed Retour as historical redirects. Deferred.
- **Drafts / non-public node versions** — `streamLiveNodes` filters
  `online=1 AND public_node_version_id`; drafts are explicitly skipped
  by design (carryover from v1). Permanently out of scope.

Operators needing any of these should treat this migrator as a starting
point and write project-specific extensions.

[1.0.0]: https://github.com/lameco/kunstmaan-migrator/releases/tag/v1.0.0
