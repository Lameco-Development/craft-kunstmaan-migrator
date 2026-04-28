# Phase 4: Adapters, Verify & Settings - Context

**Gathered:** 2026-04-26
**Status:** Ready for planning

<domain>
## Phase Boundary

Phase 4 ships three concerns bundled into one phase, all in service of letting the operator close out a CQM rehearsal with the same observable surface v1.x exposed:

1. **Optional adapters (ADP-01..03)** — SEOmatic + Retour become runtime-detected; the plugin installs cleanly without either. `migrate --live` runs the SEO + Retour stages when present, skips them with a single WARN line + REPORT.md note when absent.
2. **Verify parity gate (VER-01..03)** — `verify capture-baseline` snapshots pre-migration counts; `verify capture-baseline-html` snapshots URL HTML; `verify` runs the count-match + URL-diff gates and writes `storage/migration/VERIFY-<timestamp>.md`.
3. **Settings + observability (CFG-01..03)** — CP Settings page replaces the Phase 1 placeholder `_settings.twig`; `-v..-vvv` verbosity layers on Yii's `-v` flag; rehearsal report extends Phase 3's REPORT.md with `## Asset RCA` + `## Skipped stages` + `## Rehearsal summary` sections.

**Out of scope for Phase 4** (deferred):
- Characterization tests + CI workflow + release tag (Phase 5 / TST-01..04).
- Per-class escape-hatch mapper (D-47 carryover from Phase 3 — only revisit if CQM rehearsal proves a gap).
- Top-level CP utility entry / `Utilities → Kunstmaan Migrator` (PROJECT.md out-of-scope).
- NEXT-02 read-only CP status mirror.
- Multi-provider AI (NEXT-03).
- Cross-client rehearsal matrix (NEXT-04).
- Orphan-media sync pass (NEXT-05).
- v1's full deterministic SHA snapshot shape (`BaselineSnapshotService` 525 LOC) — see Deferred Ideas.

</domain>

<decisions>
## Implementation Decisions

### Phase scope + delivery shape

- **D-53:** **Single Phase 4 with three concern-aligned wave clusters.** Same wave-structured execution that worked for Phase 02.1 (9 plans) and Phase 3 (14 plans). Adapters / Verify / Settings are independent enough that wave parallelism is straightforward; phase closes when all three concerns are operator-verifiable on the CQM rehearsal pair (no adapter plugins installed → clean WARN-skip; both adapter plugins installed → counts match within `verifyCountTolerance`; CP Settings form roundtrips all 12 fields). Estimated 8-12 plans across 3-4 waves.

### Brownfield port discipline

- **D-54:** **Verbatim port + RECONCILIATION.md** (Phase 3 / D-46 carryover). Apply to:
  - `~/Sites/craft-kunstmaan-migrator/src/bridge/load/SeoMigrationService.php` (600 LOC).
  - `~/Sites/craft-kunstmaan-migrator/src/bridge/load/SeomaticPayloadBuilder.php` (165 LOC).
  - `~/Sites/craft-kunstmaan-migrator/src/bridge/load/RedirectMigrationService.php` (692 LOC).
  - `~/Sites/craft-kunstmaan-migrator/src/craft/verify/CountGateService.php` (131 LOC).
  - `~/Sites/craft-kunstmaan-migrator/src/craft/verify/SnapshotDiffer.php` (128 LOC).
  - `~/Sites/craft-kunstmaan-migrator/src/craft/verify/SpotCheckUrlFetcher.php` (234 LOC).
  - `~/Sites/craft-kunstmaan-migrator/src/craft/verify/CaptureBaselineHtmlService.php` (73 LOC).
  - `~/Sites/craft-kunstmaan-migrator/src/bridge/console/controllers/VerifyController.php` (343 LOC).

  Each plan's RECONCILIATION section documents v1 rule → v2 disposition (`ported` / `dropped intentionally` / `dropped accidentally → patched`). v1's `BaselineSnapshotService` (525 LOC) is **NOT** ported verbatim — see D-57.

### Adapter integration (ADP-01..03)

- **D-55:** **Bolt-on + sub-actions both.** `migrate --live` runs `extract → transform → load → finalize → seo → retour` in sequence (mirrors v1's "SEO runs LAST" docblock so kuma_seo image refs resolve to migrated assets via state lookup). Standalone `migrate/seo` and `migrate/retour` sub-actions exist for resume / debug — same pattern Phase 3's ETL-02 established (`migrate/extract`, `migrate/transform`, etc.). Each sub-action is independently filter-aware (honors `--entities` + `--locales` + `--since`).

- **D-56:** **Detection inside the adapter service.** `SeoMigrationService::migrate()` and `RedirectMigrationService::migrate()` short-circuit with a single WARN line at start when `Craft::$app->plugins->getPlugin('seomatic')` / `getPlugin('retour')` returns null. Single seam; controller stays thin. Service is registered unconditionally in `Plugin::config()` (no boot-time branching). REPORT.md gets a `## Skipped stages` row — `seo: skipped (plugin not installed; N entries had kuma_seo rows)` — so absence is preserved in the audit artifact, not just stderr. Mirrors v1's `CONFIG-08` gate semantics.

- **D-57:** **Hardcoded behavior for SEO + Retour reads.** No mapping.yaml rows for adapter logic. v1's `SeoMigrationService` reads `kuma_seo` directly + writes to SEOmatic's `seo` field; `RedirectMigrationService` reads `kuma_redirects` directly. Table-name overrides via `Settings::$seoTableName` (default `kuma_seo`) and `Settings::$redirectsTableName` (default `kuma_redirects`) for non-CQM Kunstmaan flavours, mirroring v1's `setComponents` override seam. Rationale: the two source schemas are stable (Kunstmaan ships them in vendor); analyze coverage of these tables would be churn for zero gain.

### Verify scope + determinism (VER-01..03)

- **D-58:** **Ship full v1 verify shape.** Both gates land in Phase 4: count-match (Gate 1) + URL HTML diff (Gate 2). Three console actions: `verify capture-baseline`, `verify capture-baseline-html`, `verify` (default = run both gates). v1 already debugged the "byte-count vs real-diff" trap (B1 fix in v1's VerifyController) — port that fix verbatim. URL list path: `storage/migration/spot-check-urls.txt` (operator-curated). VER-02's "optional" wording in REQUIREMENTS preserved structurally — `verify capture-baseline-html` is a separate operator action, and `verify` Gate 2 emits a `WARN no-baseline` line + flips overall pass to false rather than blowing up when the operator hasn't captured HTML baselines yet.

- **D-59:** **Counts + light metadata baseline.** `verify capture-baseline` produces `storage/migration/baseline.json` with: per-entry-type entry counts (keyed by handle), asset count, taxonomy counts (per category-group handle), Retour redirect count, SEOmatic bundle count. NO per-entry contentSha256 / per-asset hash_file SHA / Matrix block sortOrder hashing — v1's full deterministic snapshot (`BaselineSnapshotService` 525 LOC) is overkill for the v1.0 operator workflow ("did the migrate run leave entries within ±1% of baseline?"). Skipping the heavy SHA path is a deliberate trade-off documented for D-46-style recall: operators who want refactor-safety regressions can land that as a `--deep` flag in a follow-up phase. Baseline JSON is timestamped at the value level (`generatedAt`) but stored at a stable path so `verify` reads `baseline.json` without needing to glob.

- **D-60:** **Tolerance via Settings + CLI override.** `Settings::$verifyCountTolerance` (default `0.01` = ±1% per ROADMAP success criterion 3) and `Settings::$verifyUrlDiffThreshold` (default `0.05` = 5%) are the persistent layer. CLI flags `--count-tolerance=` / `--url-diff-threshold=` override per-run. Mirrors Phase 1 / D-15's `default*` ladder + Phase 2 / D-10's Settings+CLI merge pattern. `mapping.yaml` stays clean of verify-config noise (deviation from v1, which read `verify.tolerance` from mapping.yaml — that intermingled operator content with config and was a v1 wart).

- **D-61:** **Markdown-only `VERIFY-<ts>.md`.** Single artifact at `storage/migration/VERIFY-<timestamp>.md`. Format mirrors v1's `renderReportMarkdown`: `Overall: PASS|FAIL` line, `## Count gate` table (Key / Expected / Actual / Delta / Pass), `## URL gate` table (URL / Status / Diff ratio), one row per skipped optional-plugin gate (`SKIP seomatic (plugin not installed)`). NO JSON sidecar in v1.0 — defer until rehearsal automation (NEXT-04 cross-client matrix) actually consumes it. Aligns with REPORT.md / MAPPING-AUDIT.md / RECONCILIATION.md markdown-canonical discipline.

### CP Settings page (CFG-01)

- **D-62:** **Single page, grouped sections.** One `_settings.twig` form with H2-separated sections (no tabs):
  - **Connectivity** — `legacyDbServer`, `legacyDbPort`, `legacyDbDatabase`, `legacyDbUser`, `legacyDbPassword`, `legacyDbCharset`, `legacyDbTablePrefix`, `kunstmaanSourcePath`.
  - **AI** — `anthropicApiKey` (masked, see D-64), `llmModel`, `llmTimeout`, `llmInterChunkDelay`.
  - **Defaults** — `defaultEntities`, `defaultLocales`, `localeMap`, `defaultSince`, `defaultMaxPerEntity`, `dryRunDefault`.
  - **Verify (new this phase)** — `verifyCountTolerance`, `verifyUrlDiffThreshold`.
  - **Adapters (new this phase)** — `seoTableName`, `redirectsTableName`.

  Single Save button. Standard Craft `_layouts/cp` extension. NO top-level CP nav entry (PROJECT.md out-of-scope). NO Utilities entry. The form roundtrips through `hasCpSettings = true` (already wired in Phase 1).

- **D-63:** **Craft `editableTable` for array fields.** `defaultEntities` + `defaultLocales` = single-column editable tables. `localeMap` = two-column editable table (Legacy locale → Craft site handle). Native CP look; no Twig macro reinvention. Plain text inputs + comma-splitting were considered and rejected — `localeMap`'s tuple shape doesn't render well as CSV, and editable tables are a 2-line macro call.

- **D-64:** **Masked password input + env hint for `anthropicApiKey`.** `<input type="password" autocomplete="new-password">` with help text below: "Defaults to `ANTHROPIC_API_KEY` env var; setting here overrides env per Phase 1 / D-14." Doctor's existing presence-only reporting (T-1-03 invariant from Phase 1 / Plan 04) is preserved — Settings still never logs the resolved value. The `EnvAttributeParserBehavior` (already on `anthropicApiKey` per Phase 1) means `$ENV_VAR` syntax keeps working in the CP field too. Plain text + env-only-recommendation was considered but loses muscle-memory: every other CP plugin masks API keys.

### Verbosity (CFG-02)

- **D-65:** **Layer `-v..-vvv` on Yii's built-in `-v`.** Yii Console exposes `-v` as a verbose flag by default; we extend semantically:
  - `-v` (default Yii) → stage-level timings + per-stage summary lines (e.g. `extract: 1.4s, 547 rows`).
  - `-vv` → per-entry progress detail (asset-resolution chain, deferred-token emission, `[N/total] slug` lines already emitted at default level; `-vv` adds resolution-chain breadcrumbs).
  - `-vvv` → SQL query traces, handler-internal logs, full state-table writes.

  Read via counting `-v` flag invocations in `MigrateController::options()` (similar to git/ssh/rsync convention). `--quiet` / `-q` reserved for future; not in scope.

- **D-66:** **Asset RCA = structured per-asset failure line.** When `AssetMigrationService` fails to ingest an asset, emit a single structured RCA line at `-v` level (always logged; no `-v` flag needed once the asset path failed):
  ```
  RCA asset=<legacy_id> reason=<filesystem_404|mime_mismatch|too_large|deferred_unresolved> path=<relative_legacy_path>
  ```
  Reasons enumerated as a closed set (extensible — researcher confirms full list against v1 `AssetMigrationService`). REPORT.md gets a `## Asset RCA` section that lists every failure verbatim. CFG-02's wording ("asset RCA logging into `storage/migration/*.log`") satisfied via the per-run log file (D-67) AND the REPORT.md inline section.

- **D-67:** **One log file per run, timestamped.** `storage/migration/migrate-<timestamp>.log` (`Y-m-d--H-i-s` shape, mirroring `VERIFY-<ts>.md`). Self-rotating by name; `-v..-vvv` controls density; no rotation knob, no deletion logic. Matches REPORT.md / VERIFY-<ts>.md / MAPPING-AUDIT.md discipline. Operator manages disk; `verify` and `analyze` get sibling log files (`verify-<ts>.log`, `analyze-<ts>.log`) when verbosity is enabled.

### Rehearsal report (CFG-03)

- **D-68:** **Extend Phase 3's REPORT.md, no new artifact.** Phase 3 already emits `storage/migration/REPORT.md` after `migrate --live` with migration counts (Phase 3 / D-52). Phase 4 adds three sections to the same artifact:
  - `## Asset RCA` — per-asset failure rows (D-66).
  - `## Skipped stages` — adapter absence WARNs (D-56).
  - `## Rehearsal summary` — totals (created / updated / skipped / failed across all entities), wall-clock duration, filter scope (entities / locales / since), `--live`-or-`--dry-run` flag, log file path.

  Single artifact post-`migrate --live`. `verify` keeps its own `VERIFY-<ts>.md` (D-61) — different concern, different cadence. CFG-03's "distinct from `verify`'s parity gate" wording is satisfied because verify is a separate command, not because there's a separate file.

### Doctor extensions

- **D-69:** **Doctor's 7th + 8th checks.** Phase 1 / D-19 doctor pattern + Phase 02.1 / D-31 (5th check) + Phase 3 / D-13's check (6th — state table) carry forward. Phase 4 adds:
  - **7th check** — Adapter health: report `OK` or `INFO` for SEOmatic / Retour presence (`OK seomatic v5.x installed` / `INFO seomatic not installed (adapter will skip)`). Always exits OK — adapter absence is not a FAIL since they're optional. Surfaces availability before `migrate --live` runs.
  - **8th check** — Verify baseline presence: report `OK` if `storage/migration/baseline.json` exists, `INFO` if missing (`run verify capture-baseline first`). Always OK — operators may run doctor before capturing baseline.

  Researcher confirms ordering in DoctorController; planner specifies the per-check `OK`/`INFO`/`WARN`/`FAIL` shape.

### Claude's Discretion

- **`Settings` model expansion** — Phase 1 / D-15 declared most fields upfront; Phase 4 adds `verifyCountTolerance`, `verifyUrlDiffThreshold`, `seoTableName`, `redirectsTableName`. Researcher proposes the property declarations + `rules()` updates + `EnvAttributeParserBehavior` attribute additions; planner sequences in a "Settings expansion" plan.
- **Plugin::config() growth** — Phase 4 adds ~6-8 components (SeoMigrationService, SeomaticPayloadBuilder, RedirectMigrationService, BaselineCounterService, CountGateService, SnapshotDiffer, SpotCheckUrlFetcher, CaptureBaselineHtmlService). Researcher proposes registration order; planner sequences component registration the way Phase 02.1 / Plan 05 and Phase 3 / Plan 13 did.
- **Plugin::init() sibling DI wiring** — Same pattern Phase 02.1 / commit 75a95bc and Phase 3 plans followed. Every Phase 4 service that depends on a sibling component gets wired here.
- **REPORT.md section ordering** — D-68 names three new sections; researcher proposes ordering relative to Phase 3's existing `## Migration counts` block; planner specifies.
- **`VERIFY-<ts>.md` exact column ordering** — D-61 names content; v1's table shape is the reference. Researcher checks for any v1 column we'd want to drop or rename.
- **Spot-check URL list shape** — `storage/migration/spot-check-urls.txt` is operator-curated, one URL per line, `#`-prefixed comments allowed. Format inherits from v1's spot-check-urls.txt convention. Researcher confirms.
- **Console controller: extend MigrateController vs new SeoController + RetourController** — D-55 says sub-actions exist; whether they live as `migrate/seo` (sub-action on the existing MigrateController) or as separate controllers is a researcher/planner call. Phase 02.1's pattern (one controller, multiple actions) suggests staying on MigrateController.
- **VerifyController shape** — Either a single controller with `actionIndex` / `actionCaptureBaseline` / `actionCaptureBaselineHtml` (mirrors v1) or sub-actions on MigrateController. Researcher recommends; planner specifies. Lean toward separate VerifyController to mirror v1 surface and keep `verify` as a separate top-level command (per ROADMAP "5 commands: doctor, analyze, map, migrate, verify").
- **Per-stage timing instrumentation** — D-65's `-v` level needs stage timings; researcher proposes whether to instrument inside each service (v1's pattern) or via a stage-wrapper / decorator at the controller level.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### v1 brownfield port targets (verbatim discipline — D-54)

#### Adapters
- `~/Sites/craft-kunstmaan-migrator/src/bridge/load/SeoMigrationService.php` — 600 LOC. Per-locale SEOmatic MetaBundle migration; runs LAST so kuma_seo image refs resolve via state lookup; `CONFIG-08` optional-plugin gate.
- `~/Sites/craft-kunstmaan-migrator/src/bridge/load/SeomaticPayloadBuilder.php` — 165 LOC. Builds the SEOmatic `seo` field payload structure.
- `~/Sites/craft-kunstmaan-migrator/src/bridge/load/RedirectMigrationService.php` — 692 LOC. kuma_redirects → Retour import + section-move 301s + `permanent` flag → 301/302; idempotency via `getRedirectByRedirectSrcUrl`.

#### Verify
- `~/Sites/craft-kunstmaan-migrator/src/bridge/console/controllers/VerifyController.php` — 343 LOC. Three actions (`index` / `capture-baseline` / `capture-baseline-html`), B1 fix (real diff vs byte count), B2 SEOmatic optional-plugin gate, count tolerance + URL diff threshold flags, `VERIFY-<ts>.md` rendering.
- `~/Sites/craft-kunstmaan-migrator/src/craft/verify/CountGateService.php` — 131 LOC. Count-match gate (per-section + assets + retour + seomatic) with tolerance comparison + per-key delta calculation.
- `~/Sites/craft-kunstmaan-migrator/src/craft/verify/SnapshotDiffer.php` — 128 LOC. Helpers for snapshot comparison.
- `~/Sites/craft-kunstmaan-migrator/src/craft/verify/SpotCheckUrlFetcher.php` — 234 LOC. URL fetch + HTML normalization + `diff()` (real diff, B1 fix).
- `~/Sites/craft-kunstmaan-migrator/src/craft/verify/CaptureBaselineHtmlService.php` — 73 LOC. Baseline HTML capture from legacy host; writes `<slug>.html` files.

#### Verify shape NOT ported verbatim
- `~/Sites/craft-kunstmaan-migrator/src/craft/verify/BaselineSnapshotService.php` — 525 LOC. v1's full deterministic snapshot (per-entry contentSha256, Matrix block sortOrder, asset hash_file SHA). Phase 4 ships counts + light metadata only (D-59); the SHA-heavy path is deferred. RECONCILIATION.md should document this drop with rationale + a clear hook for a follow-up `--deep` flag.

#### Settings (sparse v1 reference)
- `~/Sites/craft-kunstmaan-migrator/src/models/Settings.php` — 77 LOC. v1's settings model is small (api key + entry type UIDs + 2 path overrides). v2's `src/models/Settings.php` is already richer (Phase 1 / D-15) — use it as the base; Phase 4 just adds the verify + adapter fields.

### Project-level decisions
- `.planning/PROJECT.md` §"Key Decisions" — Optional SEOmatic / Retour adapters (composer suggest, runtime detection), CP Settings page deferred to Phase 4, no CP runner utility, no top-level CP nav entry, NeverProductionTrait hard-gate.
- `.planning/PROJECT.md` §"Out of Scope (v1)" — No CP "Migration Pipeline" runner utility, no inline mapping authoring in CP, no skill bundle, no multi-provider AI.
- `.planning/PROJECT.md` §"Requirements / Out of Scope (v1)" — Orphan media (NEXT-05).

### Requirements
- `.planning/REQUIREMENTS.md` §"Optional adapters (ADP)" — ADP-01..03 (SEOmatic adapter, Retour adapter, composer suggest).
- `.planning/REQUIREMENTS.md` §"Verify (VER)" — VER-01..03 (capture-baseline, capture-baseline-html optional, verify parity gate writes `VERIFY-<ts>.md`).
- `.planning/REQUIREMENTS.md` §"Settings + observability (CFG)" — CFG-01..03 (CP Settings page, `-v..-vvv` verbosity + asset RCA logging, rehearsal report).
- `.planning/ROADMAP.md` §"Phase 4: Adapters, Verify & Settings" — 4 success criteria (clean install without plugins, both plugins → counts match baseline, `verify` writes `VERIFY-<ts>.md`, CP Settings reads + writes 5+ fields).

### Prior phase decisions (load-bearing)
- `.planning/phases/01-foundation-connectivity/01-CONTEXT.md` — Phase 1 / D-12 env-then-Settings `??=` ladder, Phase 1 / D-14 Anthropic key never-logged invariant (T-1-03), Phase 1 / D-15 Settings fields declared upfront, Phase 1 / D-16 `hasCpSettings = true` + placeholder template, Phase 1 / D-19 doctor plain-text OK/FAIL, Phase 1 / D-20 gate-first idiom, Phase 1 / D-21 PluginBootstrapTest invariant.
- `.planning/phases/02-schema-mapping-filters/02-CONTEXT.md` — Phase 2 / D-07 atomic write (`MappingFile::writeAtomic` / `writeAtomicJson`), Phase 2 / D-10 Settings+CLI filter merge pattern (template for D-60), Phase 2 / D-12 three filter flags only.
- `.planning/phases/02.1-source-introspection/02.1-CONTEXT.md` — Phase 02.1 / D-31 doctor 5th check pattern, Phase 02.1 / D-33 Yii Component resolver pattern, Phase 02.1 / commit 75a95bc Plugin::init() sibling DI wiring (load-bearing for Phase 4 services).
- `.planning/phases/02.1-source-introspection/RECONCILIATION.md` — RECONCILIATION pattern template for Phase 4's per-plan reconciliation sections.
- `.planning/phases/03-etl-pipeline-field-handlers/03-CONTEXT.md` — Phase 3 / D-46 verbatim port discipline (D-54 carries it forward), Phase 3 / D-50 per-entry failure handling (REPORT.md `## Failures` section informs `## Asset RCA` placement), Phase 3 / D-52 inline migration counts in REPORT.md (D-68 extends), Phase 3 / D-13 doctor's 6th check (template for D-69's 7th + 8th).

### v2 codebase priors that Phase 4 builds on
- `src/Plugin.php` — Phase 02.1 / commit 75a95bc + Phase 3 already wire sibling component dependencies in `init()`. Phase 4 plans MUST follow the same pattern.
- `src/models/Settings.php` — Phase 1 / D-15 declared most fields upfront; Phase 4 adds verify + adapter properties + `EnvAttributeParserBehavior` extensions.
- `src/templates/_settings.twig` — Phase 1 / D-16 placeholder. Phase 4 replaces with the full grouped-section form (D-62).
- `src/console/MigrateController.php` — Phase 3 / Plan 13 owns the per-stage actions. Phase 4 adds `actionSeo`, `actionRetour` sub-actions (or new SeoController / RetourController per Claude's Discretion).
- `src/console/DoctorController.php` — Phase 1 / Plan 04 base + Phase 02.1 / Plan 01 5th check + Phase 3 / Plan 13 6th check. Phase 4 adds the 7th + 8th checks (D-69).
- `src/console/AnalyzeController.php`, `src/console/MapController.php` — Phase 02.1 console controller pattern (single `actionIndex` with N steps). Phase 4's `VerifyController` follows the same shape.
- `src/load/AtomicMigrationService.php`, `src/load/MigrationStateService.php` — Phase 3 outputs that SeoMigrationService + RedirectMigrationService consume (state lookup for asset/entry resolution).
- `src/load/AssetMigrationService.php` — Phase 3 owns asset failure paths; D-66 asset RCA emission lives here.
- `src/NeverProductionTrait.php` — Every Phase 4 controller action that writes to Craft or reads legacy DB MUST `use` this trait.

### Adapter-specific context
- `composer.json` — already lists SEOmatic + Retour as `suggest` (ADP-03 satisfied at the manifest level since Phase 1).
- v1's `nystudio107/retour` import path (`use nystudio107\retour\Retour;`) — confirms Retour 5.x package shape.
- v1's `RedirectMigrationService` `Retour::$plugin->redirects->saveRedirect()` API call — confirms Retour write surface.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- **Settings model** (`src/models/Settings.php`) — Phase 1 / D-15 declared `llmModel`, `llmTimeout`, `llmInterChunkDelay`, `defaultEntities`, `defaultLocales`, `localeMap`, `defaultSince`, `defaultMaxPerEntity`, `dryRunDefault`, `mappingPath`, `kunstmaanSourcePath`. Phase 4 adds: `verifyCountTolerance`, `verifyUrlDiffThreshold`, `seoTableName`, `redirectsTableName`.
- **`hasCpSettings = true` + `_settings.twig` placeholder** — Phase 1 / D-16 wired the route. Phase 4 replaces template content (D-62).
- **`MappingFile::writeAtomic` + `writeAtomicJson`** (Phase 2 / D-07) — REPORT.md additions, `VERIFY-<ts>.md`, `baseline.json` all flow through this primitive.
- **`MigrationStateService`** (Phase 3 / Plan 03) — SEO + Retour migration uses the state map for `media:kuma_media:<id>` and entry-uri resolution.
- **`MigrationFilters` + `FilterFactory`** (Phase 2 / Plan 01) — `verify`, `migrate/seo`, `migrate/retour` all accept the filter flags via the established pattern.
- **`LegacyDbService`** (Phase 1 / Plan 02) — kuma_seo + kuma_redirects reads go through here.
- **`NeverProductionTrait`** — every new Phase 4 controller action uses it.
- **`EnvAttributeParserBehavior`** (already on `anthropicApiKey` per Phase 1) — extends to new Settings fields where env-var override is desirable (probably `seoTableName`, `redirectsTableName`, possibly `verifyCountTolerance`).
- **Phase 3's REPORT.md** — extended with `## Asset RCA` + `## Skipped stages` + `## Rehearsal summary` (D-68) instead of replaced.

### Established Patterns
- **Verbatim port + RECONCILIATION.md** (Phase 3 / D-46 → D-54) — dominant Phase 4 discipline.
- **Plugin::init() sibling-component DI wiring** (Phase 02.1 commit 75a95bc) — every Phase 4 service that depends on a sibling component MUST be wired here.
- **Console controller as single `actionIndex` with N steps + sub-actions for resume** (Phase 02.1 / D-42 11-step AnalyzeController; Phase 3 / Plan 13 MigrateController) — VerifyController follows the same shape (3 actions: index / capture-baseline / capture-baseline-html).
- **Doctor OK/WARN/FAIL plain-text output** (Phase 1 / D-19) — D-69's 7th + 8th checks follow the same plain-text discipline.
- **Settings + CLI flag merge** (Phase 2 / D-10) — D-60 verify tolerance + URL diff threshold use the same ladder.
- **Timestamped artifacts** (`VERIFY-<ts>.md`, `migrate-<ts>.log`, REPORT.md, RECONCILIATION.md, MAPPING-AUDIT.md) — Phase 4 inherits.

### Integration Points
- **`src/Plugin.php` Plugin::config()** — grows ~6-8 entries for Phase 4 (adapter services + verify services).
- **`src/Plugin.php` Plugin::init()** — grows the sibling-DI wiring block.
- **`src/console/MigrateController.php`** — adds `actionSeo`, `actionRetour` sub-actions (or planner picks new controllers).
- **`src/console/VerifyController.php`** — new top-level controller; mirrors v1's three-action shape.
- **`src/console/DoctorController.php`** — extended with 7th + 8th checks.
- **`src/templates/_settings.twig`** — full form replaces placeholder.
- **`src/models/Settings.php`** — adds 4 properties (D-60 + D-57) + `EnvAttributeParserBehavior` attribute additions.

</code_context>

<deferred>
## Deferred Ideas

- **Full deterministic snapshot baseline** (`BaselineSnapshotService` 525 LOC port) — Phase 4 ships counts + light metadata only (D-59). Reintroduce as `verify capture-baseline --deep` in a future phase if refactor-safety regressions surface a need. RECONCILIATION.md should document the drop verbatim.
- **`VERIFY-<ts>.json` machine-readable sidecar** — D-61 ships markdown only. Reintroduce when NEXT-04 cross-client rehearsal matrix actually exists and wants automation.
- **Top-level CP nav entry** (Utilities → Kunstmaan Migrator) — PROJECT.md out-of-scope. Stays Settings → Plugins only.
- **NEXT-02 read-only CP status mirror** — out of milestone.
- **Multi-provider AI** (NEXT-03) — Anthropic-only stays.
- **Cross-client rehearsal matrix** (NEXT-04) — Simac / Enreach / Joulz. Out of milestone.
- **Orphan-media sync pass** (NEXT-05) — page-driven by design.
- **`--quiet` / `-q` verbosity floor** — D-65 doesn't include a sub-default-quiet level. Reintroduce if rehearsal output proves too chatty even at default.
- **Log file rotation by size or age** — D-67 ships per-run timestamped only. Add rotation if disk pressure surfaces in rehearsal.
- **Per-stage log files** (`extract.log`, `transform.log`, ...) — rejected for v1.0 (cross-stage correlation matters more).
- **Verify mapping.yaml `verify` block** — v1 shape rejected (D-60 keeps mapping.yaml clean of verify config). RECONCILIATION.md documents the drop.
- **CP form save-time legacy DB reachability test** — could be added as a "Test connection" button on the Connectivity section. Deferred unless rehearsal operators ask for it (doctor already covers it).
- **`--max-failures=N` threshold flag** (Phase 3 / deferred carryover) — also surfaces in Phase 4 SEO / Retour stages. Same disposition: defer until rehearsal proves a need.

</deferred>

---

*Phase: 04-adapters-verify-settings*
*Context gathered: 2026-04-26*
