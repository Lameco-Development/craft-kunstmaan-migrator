---
phase: 04.1-polish-recovery-and-env-defaults
verified: 2026-04-26T00:00:00Z
status: passed
score: 9/10 must-haves verified (1 explicitly deferred to Phase 4.2)
verdict: PASS-WITH-NOTES
notes:
  - "ADP-04 surface gap: Settings::seoEnabled / retourEnabled are functional but absent from both _settings.twig (9-field CP) and config/kunstmaan-migrator.example.php (14 advanced). Operator must read source to discover the persistent-disable knob. Does NOT violate ADP-04 as worded; flagged for follow-up scoping."
  - "REC-02 deferral is empirically grounded — discovery (04.1-07-DISCOVERY.md) confirmed by independent grep: zero `relation:deferred` strings in src/, no `kind` column in kunstmaanmigrator_state schema, RelationHandler:45 explicitly documents silent-drop semantics."
  - "Phase 5 carry-overs already enumerated in RECONCILIATION.md — MigrateController LOC growth to 1707, simac/enreach rehearsal, BASE_URL auto-gen, REC-02 design + impl."
---

# Phase 4.1: Polish, Recovery & .env Source-of-Truth — Verification Report

**Phase Goal (from ROADMAP):** Close 9 driver requirements (CFG-04, CFG-05, CFG-06, CFG-07, LOC-03, ADP-04, VER-04, SRC-20, REC-01) plus formally resolve REC-02; deliver `.env` source-of-truth for DSN + locale, slimmed CP, adapter explicit-disable surface, filter plumb-through to verify, constant-shape REPORT.md, attributes-only parser, and sync-assets recovery command.

**Verified:** 2026-04-26
**Status:** PASS-WITH-NOTES (9 PASS / 1 DEFERRED / 0 FAIL)
**Re-verification:** No — initial verification.
**Test sweep:** `composer test` → 179 tests / 488 assertions / 1 deprecation (upstream Composer phar autoload, not project code).

---

## Per-Requirement Verification

| # | ID | Phase 4.1 Plan | Status | Evidence |
|---|----|----|----|----|
| 1 | **CFG-04** | 04.1-01 + 04.1-02 | ✓ PASS | `src/source/KunstmaanEnvReader.php` ships, registered as Yii component (`src/Plugin.php:131`); whitelist exactly `['DATABASE_URL','DEFAULT_LOCALE']` (`KunstmaanEnvReader.php:53`). DSN scheme allow-list is mysql / mysql+pdo / pdo:mysql / pdo_mysql ONLY (`KunstmaanEnvReader.php:228-235`); postgres/sqlite return null components. `Settings::beforeValidate()` (`models/Settings.php:141-187`) auto-fills blank `legacyDb*` from reader using `??=` (operator wins); fast-path skip when all 4 string fields set; explicit early return when DSN scheme rejected (lines 162-168); port special-cased on the 3306 sentinel. |
| 2 | **CFG-05** | 04.1-04 | ✓ PASS | `src/templates/_settings.twig` is a fragment (`grep -c extends → 0`, line 1-16 docblock locks fragment-shape); 9 fields rendered across 2 H2 groups: Connectivity (legacyDbServer, legacyDbPort, legacyDbDatabase, legacyDbUser, legacyDbPassword, anthropicApiKey = 6), Mapping (kunstmaanSourcePath, mappingPath, localeMap = 3) = 9. `config/kunstmaan-migrator.example.php` documents exactly 14 advanced fields (legacyDbCharset, legacyDbTablePrefix, llmModel, llmTimeout, llmInterChunkDelay, defaultEntities, defaultLocales, defaultSince, defaultMaxPerEntity, dryRunDefault, verifyCountTolerance, verifyUrlDiffThreshold, seoTableName, redirectsTableName) with multi-env shape (`*` / `.dev` / `.production`). |
| 3 | **CFG-06** | 04.1-04 | ✓ PASS | `_settings.twig:97-118` `editableTableField` for `localeMap`: `craft` column always uses `type: 'select'` with `siteHandleOptions` (always available); `legacy` column uses `type: localeOptions ? 'select' : 'singleline'` — dropdown when DB reachable, free-text fallback otherwise. Test guard `tests/Plugin/SettingsHtmlTest::testLocaleMapDropdownShape` + `testPluginSettingsHtmlPayloadIncludesDropdownOptions` lock the contract. |
| 4 | **CFG-07** | 04.1-05 | ✓ PASS | `MigrateController::renderSkippedStagesSection()` (lines 1638-1652) and `renderAssetRcaSection()` (lines 1662-1683) ALWAYS emit the `## Skipped stages` and `## Asset RCA` H2 headings. Empty input → placeholder copy `_No skipped stages — all configured adapters were exercised._` and `_No asset RCA rows — no assets were migrated, or all migrated cleanly._`. Both helpers `public static` so characterization tests (`MigrateControllerReportEmptyStateTest`) call them without Craft bootstrap. |
| 5 | **LOC-03** | 04.1-01 + 04.1-03 | ✓ PASS | `KunstmaanEnvReader::getDefaultLocale()` (`KunstmaanEnvReader.php:87-91`) reads `DEFAULT_LOCALE` from `.env`/`.env.example`. `DoctorController::checkLocalePreflightRung0()` (lines 527-562) consults it as Rung 0 advisory: WARN-only on mismatch with `localeMap[0]` (lines 549-559, exact D-12 verbatim copy `"WARN Kunstmaan default locale `%s` but operator's `localeMap` lists `%s` first…"`); returns `true` always (never FAILs). Existing `LocalePreflight::resolve()` 3-rung ladder unchanged per D-11. |
| 6 | **ADP-04** | 04.1-05 | ✓ PASS (with note) | `Settings::seoEnabled` + `Settings::retourEnabled` defaults `true` (`models/Settings.php:70-71`). Settings-disabled gate runs FIRST, BEFORE plugin-presence check (`SeoMigrationService.php:131`, `RedirectMigrationService.php:117`) per D-25. CLI `--no-seo`/`--no-retour` flags exist on MigrateController (lines 71-72, options at line 91), forwarded into `MigrationFilters::noSeo`/`noRetour` (`filter/MigrationFilters.php:31-32`, `filter/FilterFactory.php:27-49`) — readonly preserved. **D-27 four distinct warn-line copies confirmed**: (a) `'SEO migration skipped via --no-seo (CLI override).'` (`MigrateController.php:1694`), (b) `'Redirect migration skipped via --no-retour (CLI override).'` (`MigrateController.php:1705`), (c) `'SEO adapter disabled (explicitly via Settings::seoEnabled); SEO migration skipped.'` (`SeoMigrationService.php:623`), (d) `'Retour adapter disabled (explicitly via Settings::retourEnabled); redirect migration skipped.'` (`RedirectMigrationService.php:709`). REPORT.md aggregator pattern-matches all four (`MigrateController.php:1543-1556`). **Note:** `seoEnabled`/`retourEnabled` properties are NOT exposed in `_settings.twig` and NOT documented in `config/kunstmaan-migrator.example.php` — operator discovers persistent-disable knob via source-read only. Does not violate ADP-04 as worded. |
| 7 | **VER-04** | 04.1-05 | ✓ PASS | `CountGateService::run(array $expectedCounts, float $tolerance, ?MigrationFilters $filters = null)` (`verify/CountGateService.php:48`) accepts filters; uses `isSectionFilteredOut()` (line 204) and `resolveScopeSiteIds()` (line 228). `BaselineCounterService::capture(?MigrationFilters $filters = null)` (`verify/BaselineCounterService.php:65`) accepts filters; baseline JSON embeds `filterScope` header (lines 70, 87-96) per D-29. `VerifyController::actionIndex` (line 109) and `actionCaptureBaseline` (line 292) build filters via `FilterFactory::fromCli` and pass through (lines 148, plus capture call). intelephense `$filters unused` warning closed. |
| 8 | **SRC-20** | 04.1-06 | ✓ PASS | `src/source/DoctrineEntityParser.php`: `grep -c "@ORM"` returns **0** (zero annotation literal hits); LOC = 316 (target ≤320 met; was 366 LOC pre-strip). Annotation paths and tests removed (not deprecated). Pre-flight cqm/simac/enreach grep returned zero hits before removal landed (per Plan 04.1-06 SUMMARY). |
| 9 | **REC-01** | 04.1-07 | ✓ PASS | `MigrateController::actionSyncAssets()` (lines 799-907) ships with `enforceNeverProduction()` as **first statement** (line 801) per FND-04 / T-04.1-07-04 mitigation. Re-ingests state-rows under `source='media'` whose `targetId` is unset via `collectAssetCandidates(filters)` (line 824); calls `AssetMigrationService::resolveFromLegacyId` (line 834); on classified failure (`filesystem_404` / `too_large`) writes terminal-state via `MigrationStateService::markTerminal` (lines 864-869) → `meta.terminalState='permanently_failed'` (`MigrationStateService.php:241`); idempotent — re-runs with everything healed exit with `candidates=0` and emit placeholder line. Honors `MigrationFilters` (--entities/--locales/--since). Emits `SYNC-ASSETS-{ts}.md` via `MappingFile::writeAtomic` (line 893) per D-39. No `kunstmaanmigrator_state` schema migration needed (terminal marker rides in existing `meta` JSON column per PATTERNS recommendation b). |
| 10 | **REC-02** | (deferred) | ⚠ DEFERRED to Phase 4.2 | **Discovery (`04.1-07-DISCOVERY.md`) Finding B is empirically correct.** Independent verification: `grep -rn "relation:deferred\|relation_deferred" src/` returns 0 hits. `kunstmaanmigrator_state` schema (`migrations/Install.php:45-56`) has 10 columns and **no `kind` column** — no marker convention to query. `RelationHandler.php:45` explicitly documents `"Misses are dropped silently"` for `resolveDirect` / `resolveViaJoinTranslation`. The only deferred-resolution paths today are in-memory: `asset:N` tokens emitted by `AssetHandler` + `RelationHandler::resolveViaJoinTable` (line 220), resolved at load-time by `AtomicMigrationService::ingestAndResolveAssets`; `[NT<id>]` / `[M<id>]` body tokens resolved at finalize-time by `CkeditorRewriterService`. None of these write a state-row. Implementing REC-02 requires extending `RelationHandler` / `AtomicMigrationService` state-write surface, which Plan 04.1-07's hard-scope restriction (Task 1) explicitly forbids. REQUIREMENTS.md REC-02 stays `[ ]` with the deferral rationale inline; traceability table maps REC-02 → Phase 4.2 (line 165). **Sound deferral, not scope leakage.** |

**Score:** 9/10 closed, 1 explicitly deferred. Zero failed.

---

## Goal-Backward Verdict

**Did the operator get the experience the phase promised?** Yes, on every dimension:

| Promised outcome | Delivered? | Evidence |
|---|---|---|
| `.env` source-of-truth for DSN | ✓ Yes | `Settings::beforeValidate()` auto-fills 4 DSN components + port from `KUNSTMAAN_SOURCE_PATH/.env.example`+`.env`; operator overrides win via `??=`; non-mysql DSNs no-op cleanly. |
| `.env` source-of-truth for locale | ✓ Yes | Doctor 10th check WARNs when `DEFAULT_LOCALE` from `.env` disagrees with `localeMap[0]`; advisory only (NL→EN-primary intentional pattern preserved). |
| Slimmed CP (operator-touch fields only) | ✓ Yes | 23-field page → 9-field page across 2 H2 groups; 14 advanced fields documented in `kunstmaan-migrator.example.php` with multi-env shape. CP form load is dramatically less noisy. |
| Adapter explicit-disable surface | ✓ Yes | `Settings::seoEnabled`/`retourEnabled` boolean gates + `--no-seo`/`--no-retour` CLI flags; 4 distinct warn copies let REPORT.md / operators distinguish CLI-bypass vs Settings-disabled vs plugin-not-installed. *(Doc surface gap: knob is not exposed in CP/example; minor follow-up.)* |
| Filter plumb-through to verify stage | ✓ Yes | `--entity`/`--locale`/`--since` flags on `VerifyController` now actually scope `CountGateService::run()` + `BaselineCounterService::capture()`; baseline JSON embeds `filterScope` header so post-hoc readers know what scope produced the snapshot. |
| Constant-shape REPORT.md | ✓ Yes | `## Skipped stages` and `## Asset RCA` H2 headings always present, with placeholder copy when empty. "Did this code path run?" trivially answerable from REPORT.md alone. |
| Attributes-only parser | ✓ Yes | `DoctrineEntityParser` has zero `@ORM\` literal hits and shrunk 366→316 LOC. Halves regex/state-machine surface. |
| Sync-assets recovery | ✓ Yes | New `migrate/sync-assets` CLI sub-action: re-ingests skipped media, idempotent (re-runs with everything healed exit cleanly with `candidates=0`), terminal-state marker prevents retry loops, emits dated SYNC-ASSETS-*.md report, NeverProductionTrait first-statement gate. |

**The full-story user journey works:** an operator setting up a fresh Kunstmaan→Craft rehearsal can now point `KUNSTMAAN_SOURCE_PATH` at a checkout, get DSN + DEFAULT_LOCALE auto-filled into Settings, see a 9-field CP form with constrained dropdowns where source data is knowable, run `migrate` with adapter overrides via Settings or CLI flags, get a constant-shape REPORT.md that surfaces both empty and populated code paths, run `verify` with scope flags that actually narrow what gets compared, and recover from skipped media via `migrate/sync-assets` without re-running the full pipeline.

---

## Architectural Ground Rules — Spot-Check

| Rule | Held? | Evidence |
|---|---|---|
| Single `mapping.yaml` + per-row `status:` | ✓ | Untouched by Phase 4.1. |
| Optional SEOmatic/Retour adapters (runtime detection) | ✓ | `seoEnabled`/`retourEnabled` Settings gate prepends BEFORE existing `getPlugin('seomatic')` check — does not introduce composer requirement. |
| Filter spec from day one | ✓ | `MigrationFilters` extended with `noSeo`+`noRetour` (readonly + final preserved); `actionSyncAssets` consumes the same VO via `FilterFactory::fromCli`. |
| CLI-only operator surface | ✓ | New commands ship as sub-actions on existing `MigrateController`; no new CP runner. |
| Atomic-always-on | ✓ | `actionSyncAssets` does NOT introduce a `--non-atomic` flag. |
| JIT assets | ✓ | `actionSyncAssets` uses `AssetMigrationService::resolveFromLegacyId` (JIT path); no preload regression. |
| Runtime-zero-AI | ✓ | Sync command makes zero LLM calls. |
| `NeverProductionTrait` gates legacy-reading commands | ✓ | `actionSyncAssets` line 801 first-statement guard; doctor checks unchanged. |
| No `.claude/skills/` bundle | ✓ | Untouched. |

---

## Risks / Known Gaps / Deferred Items

### Deferred (sound, scoped)
- **REC-02** (`migrate sync-relations`): No existing deferred-relation marker exists in `kunstmaanmigrator_state`; implementing the marker requires a coherent design decision about which relation kinds get state-row treatment vs which keep silent-drop semantics, and extending `RelationHandler`/`AtomicMigrationService` state-write surface — both forbidden by Plan 04.1-07's hard-scope restriction. Phase 4.2 owns design + implementation. REQUIREMENTS.md REC-02 stays `[ ]` and the traceability table reflects this.

### Notes (non-blocking, follow-up worthy)
- **ADP-04 doc surface gap:** `Settings::seoEnabled`/`Settings::retourEnabled` are functional gates (defaults `true`), but neither appears in `_settings.twig` (CP) nor in `config/kunstmaan-migrator.example.php` (advanced overrides). An operator who wants a persistent disable would have to read the Settings model source to discover the knob exists. Resolution path: add to `kunstmaan-migrator.example.php` under a new `// Adapter gates (Phase 4.1 / ADP-04)` section. Not a goal failure (requirement does not specify exposure surface), but worth a 5-line polish patch.
- **MigrateController growth:** Now 1707 LOC (was 1316 pre-Phase-4.1, +391 LOC, mostly Plan 04.1-05 + 04.1-07). `SeoMigrationService` 606 LOC. RECONCILIATION.md captures this for Phase 5 review-and-decompose work.

### Phase 5 carry-overs (already documented in RECONCILIATION.md)
- simac-website / enreach-website end-to-end rehearsal
- Whole-plugin code-review / scope reconsideration (LOC growth)
- `BASE_URL`-derived verify URL list auto-generation

---

## Anti-Pattern Spot-Check

No blocker anti-patterns found in modified files. Spot-checks:
- No TODO/FIXME/PLACEHOLDER literals introduced in Phase 4.1 commits
- No `return [];` stubs in `actionSyncAssets`, `KunstmaanEnvReader`, doctor checks
- No hardcoded test-only values leaking into production paths
- Test suite green (179/488)

---

## Final Verdict

**PASS-WITH-NOTES — 9 PASS / 1 DEFERRED / 0 FAIL across 10 requirements.**

Top risk note: ADP-04's `seoEnabled`/`retourEnabled` Settings booleans work but aren't exposed in CP or documented in the example config — operator discovers the persistent-disable knob via source-read only.

---

_Verified: 2026-04-26_
_Verifier: Claude (gsd-verifier)_
