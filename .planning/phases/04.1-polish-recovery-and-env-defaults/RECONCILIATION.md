# Phase 4.1: Polish, Recovery & .env Source-of-Truth — Reconciliation

**Date:** 2026-04-26
**Plans shipped:** 04.1-01 through 04.1-07
**Test corpus delta:** Phase 4 closeout (~83 tests) → Phase 4.1 closeout (179 tests). Final count from `composer test` head: `Tests: 179, Assertions: 488, Deprecations: 1` (the deprecation is upstream Composer / phar autoload, not project code).
**Plan-07 LOC delta on `MigrateController`:** 1316 → 1707 LOC (+391). Phase 4 closeout was 1189 LOC; Phase 4.1 cumulative delta is +518 LOC (Plan 04.1-05 added the bulk; Plan 04.1-07 added `actionSyncAssets` + 5 static helpers + 1 docblock).

## Retired Plan-04-05 acceptance greps (per CONTEXT D-19)

Phase 4 / Plan 04-05 (`04-05-cp-settings-template-PLAN.md`) shipped two acceptance criteria that codified bugs uncovered during Phase 4 UAT (G-01, G-03):

1. **`grep -c 'extends "_layouts/cp"' src/templates/_settings.twig` should return `1`.**
   _Why this was wrong:_ G-01 from Phase 4 UAT exposed that `{% extends "_layouts/cp" %}` caused the CP form to render INSIDE another `<form>` element (Craft's CP layout already wraps `{{ settingsHtml|raw }}` in a form), breaking submit. The fix at commit `363cc5c` removed the extends and restructured `_settings.twig` as a fragment template. The acceptance grep was codifying the broken state.

   **Replacement (in Plan 04.1-04 / `tests/Plugin/SettingsHtmlTest::testFragmentShapeHasNoExtends`):** the assertion is now that `_settings.twig` carries NO `{% extends %}` directive — the fragment-shape contract.

2. **`grep -c "type: 'password'" src/templates/_settings.twig` should return `2`.**
   _Why this was wrong:_ G-03 from Phase 4 UAT exposed that `forms.autosuggestField({type: 'password'})` silently ignores the `type` parameter — `forms.autosuggestField` always renders `<input type="text">` and echoes the value back into rendered HTML on each page load, defeating any masking. The fix at commit `0f56288` switched both `legacyDbPassword` and `anthropicApiKey` to `forms.passwordField` (the real masked-input macro). The acceptance grep was codifying the broken state.

   **Replacement (in Plan 04.1-04 / `tests/Plugin/SettingsHtmlTest::testBothSecretsUsePasswordField`):** the assertion is now that `_settings.twig` calls `forms.passwordField` at least twice — the masking-shape contract.

Both retired greps are explicitly noted here per D-19; future grep-against-source acceptance criteria for these template invariants should reference the Plan 04.1-04 SettingsHtmlTest assertions, not the original Plan 04-05 string-shape greps.

## Phase 4 ↔ Phase 4.1 commit references

The two G-class fixes from Phase 4 UAT landed in-band (during Phase 4 execution) and are referenced by the retired-grep entries above:

- `363cc5c` — G-01 fix: `_settings.twig` fragment-shape (no `{% extends %}`).
- `0f56288` — G-03 fix: `forms.passwordField` masking on both `legacyDbPassword` + `anthropicApiKey`.

Phase 4.1 itself shipped no further UAT-class regressions on these templates. The Plan 04.1-04 tests above lock the fixed shape against future regression.

## Requirements closed in Phase 4.1

| ID     | Plan              | One-line outcome |
|--------|-------------------|------------------|
| CFG-04 | 04.1-01 + 04.1-02 | `KunstmaanEnvReader` reads `.env`/`.env.example`; `Settings::beforeValidate()` auto-fills blank `legacyDb*` from the parsed `DATABASE_URL`. |
| CFG-05 | 04.1-04           | CP slimmed from 23 → 9 fields across two H2 groups; 14 advanced fields documented in `config/kunstmaan-migrator.example.php`. |
| CFG-06 | 04.1-04           | `localeMap` editable-table uses dropdowns (legacy locale codes from `LocalePreflight::detect` when DB reachable; Craft site handles always). |
| CFG-07 | 04.1-05           | `REPORT.md` `## Skipped stages` and `## Asset RCA` sections always emit (placeholder copy when empty). |
| LOC-03 | 04.1-01 + 04.1-03 | Doctor 10th check consults `DEFAULT_LOCALE` as Rung 0; WARN on mismatch with `localeMap[0]`; `LocalePreflight::resolve` unchanged. |
| ADP-04 | 04.1-05           | `Settings::seoEnabled` + `retourEnabled` gates; `--no-seo` / `--no-retour` CLI flags; D-27 distinct warn copy in REPORT.md. |
| VER-04 | 04.1-05           | `VerifyController $filters` flow into `CountGateService::run()` and `BaselineCounterService::capture()`; intelephense `$filters unused` warning closed. |
| SRC-20 | 04.1-06           | `DoctrineEntityParser` is attributes-only; annotation paths + tests removed; cqm/simac/enreach pre-flight verified. |
| REC-01 | 04.1-07           | `migrate/sync-assets` re-ingests skipped media; idempotent; terminal-state via `meta.terminalState='permanently_failed'` (no schema migration). |
| REC-02 | (deferred)        | **Deferred to Phase 4.2 — no existing deferred-relation marker in `kunstmaanmigrator_state`.** Plan 04.1-07 Task 1 discovery resolved Finding B; the hard-scope restriction in the plan forbids extending `RelationHandler` / `AtomicMigrationService` state-write surface in this plan. REQUIREMENTS REC-02 stays `[ ]`. |

**9 of 10 Phase 4.1 requirement IDs are now `[x]`.** REC-02 is the sole open requirement from this phase, and is documented in `04.1-CONTEXT.md` `## Deferred Ideas` for follow-on scoping.

## Architectural ground rules respected

- **Single `mapping.yaml` + per-row `status:`** — untouched.
- **Optional SEOmatic / Retour adapters** — runtime detection unchanged; ADP-04 prepends a Settings-disabled gate BEFORE the existing presence check (D-25).
- **Filter spec from day one** — `MigrationFilters` extended with `noSeo` + `noRetour` (Plan 04.1-05); readonly + final preserved. Plan 04.1-07's `actionSyncAssets` consumes the same `MigrationFilters` shape via `FilterFactory::fromCli`.
- **CLI-only operator surface** — new commands ship as CLI sub-actions on the existing `MigrateController`. No new CP surfaces.
- **Atomic-always-on** — sync command does NOT introduce a `--non-atomic` flag.
- **JIT assets** — `actionSyncAssets` uses the existing JIT path via `AssetMigrationService::resolveFromLegacyId` — no preload regression.
- **Runtime-zero-AI** — sync command makes zero LLM calls.
- **No `.claude/skills/` bundle** — untouched.
- **`NeverProductionTrait`** — added to `actionSyncAssets` as the first statement (acceptance lock via `awk` grep).
- **No CP runner / Craft queue / FeedMe-style remap utility** — explicitly out of scope; `04.1-CONTEXT.md` `## Deferred Ideas` records as Phase 4.2 candidates.

## Plan 07 implementation deviations from PLAN.md pseudocode

The plan pseudocode contained three API mismatches with the live codebase that were corrected during execution. Documented here for traceability:

1. **`MigrationReport::incrFor()` does not exist.** The plan's `$report->incrFor('healed')` was rewritten to `$report->counts['healed'] ?? 0` (the codebase's 15+ existing read sites all use this idiom). For increments, `$report->incr('healed')` (the existing public method).

2. **`AssetMigrationService::resolveFromLegacyId(int): int`** has a single positional arg and returns `int` (0 == miss/failure path), not `?Asset`. The plan's `if ($asset === null)` branch was rewritten to `if ($resolved === 0)`. The `?int $siteId` argument from the pseudocode was dropped (no such parameter on the live signature).

3. **`MigrationStateService::all()` returns `Generator`,** not a list. The plan's `count($candidates)` after collection still works because `collectAssetCandidates` materialises the result into a list before counting; the implementation uses `foreach` over the Generator inside `collectAssetCandidates` and counts the materialised list once.

These deviations are Rule 1 (correctness) auto-fixes per the executor deviation rules — no expansion of plan scope.

## Phase 5 carry-overs

- **simac-website / enreach-website end-to-end rehearsal** (out of scope per Phase 4.1 CONTEXT).
- **Whole-plugin code review / scope reconsideration** (`MigrateController` is now 1707 LOC; `SeoMigrationService` 606 LOC) — Phase 5 owns.
- **`BASE_URL`-derived verify URL list auto-generation** — Phase 5 owns.
- **REC-02 (`migrate/sync-relations`)** — could land in Phase 4.2 (with a coherent design for the deferred-relation marker on `RelationHandler` / `AtomicMigrationService`) or be merged into a broader recovery surface in Phase 5. Either way, owners need the design discussion before the plan; not a drop-in.
