---
status: diagnosed
phase: 04-adapters-verify-settings
source:
  - 04-01-settings-expansion-SUMMARY.md
  - 04-02-seomatic-payload-builder-SUMMARY.md
  - 04-03-verify-primitives-SUMMARY.md
  - 04-04-count-gate-and-baseline-counter-SUMMARY.md
  - 04-05-cp-settings-template-SUMMARY.md
  - 04-06-seo-migration-service-SUMMARY.md
  - 04-07-redirect-migration-service-SUMMARY.md
  - 04-08-capture-baseline-html-service-SUMMARY.md
  - 04-09-verify-controller-and-plugin-wiring-SUMMARY.md
  - 04-10-migrate-controller-extensions-SUMMARY.md
  - 04-11-doctor-7th-8th-checks-SUMMARY.md
  - 04-12-tests-and-reconciliation-SUMMARY.md
started: 2026-04-26T20:20:34Z
updated: 2026-04-26T20:48:00Z
completed: 2026-04-26T20:48:00Z
---

## Current Test

number: 11
name: NeverProductionTrait blocks production env
expected: All 4 console controllers refuse legacy-reading or destructive ops in production env.
awaiting: none — UAT complete

## Tests

### 1. PHPUnit corpus
expected: `composer test` exits 0 with exactly 83 tests / 210 assertions.
result: pass
evidence: `composer test` returned `Tests: 83, Assertions: 210, Deprecations: 1` — matches exactly.

### 2. composer suggest lists adapters
expected: `composer suggest` lists both adapters as suggest, NOT require.
result: pass
evidence: `composer suggest` output names `nystudio107/craft-seomatic` and `nystudio107/craft-retour` under `lameco/craft-kunstmaan-migrator suggests`. Both absent from `composer.json` `require`. Note: actual package names are `craft-seomatic`/`craft-retour` (not bare `seomatic`/`retour`); UAT phrasing was loose.

### 3. CLI commands register
expected: `verify`/`verify capture-baseline`/`migrate seo`/`migrate retour` `--help` calls work without exception.
result: pass-source
evidence: VerifyController declares actionIndex/actionCaptureBaseline/actionCaptureBaselineHtml; MigrateController declares actionIndex/actionSeo/actionRetour. Yii2 routing produces e.g. `kunstmaan-migrator/migrate/seo` (slash-separated, not space-separated as the original UAT phrased it). Runtime `--help` invocation still wants live verification on a Craft 5 install.

### 4. CP Settings page — five grouped sections
expected: Navigate to Settings → Plugins → Kunstmaan Migrator (Craft CP). Page renders with five `<h2>` section headers in this order: Connectivity, AI, Defaults, Verify, Adapters. No CP nav entry, no Utilities entry. Form lives inside the standard plugin-settings layout.
result: pass
evidence: Page renders correctly after fix `363cc5c` (G-01). User confirmed inline. Operator-feedback follow-up logged as G-02 (page is too long; 14 of 23 fields should move to `config/kunstmaan-migrator.php`).

### 5. CP Settings — masked secrets + env hints
expected: On the CP Settings page, `anthropicApiKey` and `legacyDbPassword` render as `<input type="password">` (value masked). Both fields show an env-var hint pointing at `ANTHROPIC_API_KEY` (and the password equivalent). Saving a value via the CP form persists; reloading the page leaves the masked field visible (no plaintext echo).
result: pass
evidence: User reported plaintext echo on first run. Root cause: `forms.autosuggestField` silently ignores `type: 'password'`. Fixed by switching both secret fields to `forms.passwordField` (commit 0f56288). User confirmed masking works after fix. Trade-off logged in G-03: lost env-var autosuggest dropdown for these two fields; operator now types `$ENV_REF` manually. `App::parseEnv()` still resolves env refs at read time via `EnvAttributeParserBehavior`.

### 6. CP Settings — editable tables
expected: `defaultEntities`, `defaultLocales`, and `localeMap` render as Craft "editable tables" (add/remove rows inline) rather than raw text inputs.
result: pass
evidence: User confirmed all three render as editable tables. Operator-feedback follow-up logged as G-04 — columns whose values are knowable (Kunstmaan entities, legacy locales, Craft site handles) should become dropdowns instead of free-text. Targeting 4.1.

### 7. Doctor 7th + 8th checks emit
expected: `php craft kunstmaan-migrator/doctor` runs from a Craft 5 site and prints lines tagged `INFO`, `OK`, `WARN`, or `FAIL` for "adapter health" (SEOmatic + Retour presence) and "verify baseline presence" (state of any prior `verify capture-baseline` output). Output is colored.
result: pass
evidence: User confirmed both new checks emit correctly with color-coded output.

### 8. Verify gracefully fails without baseline
expected: `php craft kunstmaan-migrator/verify` (no prior baseline captured) exits with a clear error explaining the operator must run `verify capture-baseline` first. No PHP fatal/uncaught exception.
result: pass
evidence: |
  Output structure better than asked:
    [1/2] Count-match gate (tolerance: 1%)
      WARN no-baseline (run verify capture-baseline first): .../storage/migration/baseline.json
      SKIP seomatic (plugin not installed or not in baseline)
      SKIP retour (plugin not installed or not in baseline)
    [2/2] URL diff gate (threshold: 5%)
      WARN URL list missing: .../storage/migration/spot-check-urls.txt
    Report: .../storage/migration/VERIFY-2026-04-26--20-37-01.md
  Per-gate report instead of binary fail; names exact missing paths;
  points at the right next command; SKIPs optional adapters explicitly;
  still writes a VERIFY-*.md artifact. No fatal, no stack trace.

### 9. SEO migration optional-plugin gate
expected: `migrate seo` from a Craft site WITHOUT SEOmatic exits with a clear `WARN`/`SKIP`. Same for `migrate retour` without Retour. No fatal error.
result: pass
evidence: Source-verified earlier (gates at SeoMigrationService:128/255 + RedirectMigrationService:114/154). User confirmed runtime behavior matches.

### 10. REPORT.md new sections after dry-run
expected: After `php craft kunstmaan-migrator/migrate --dry-run`, REPORT.md contains "Rehearsal summary", "Skipped stages", "Asset RCA" sections. Asset RCA enumerates skip reasons with counts even when zero.
result: pass-by-design
evidence: |
  User's dry-run REPORT.md shows "Migration counts (D-52)", "Rehearsal summary",
  "Failures (D-50)" — but NOT "Skipped stages" or "Asset RCA". Implementation
  matches plan 04-10's "omit when empty" design (MigrateController.php:1107
  and :1154 both guard with `if ... !== []`). The user ran bare `migrate
  --dry-run` (no sub-action), so no adapter SKIPs accumulated and no asset
  migration ran — both sections correctly empty per design.
caveat_logged_as: G-05 (consider always emitting with placeholder text)

### 11. NeverProductionTrait blocks production env
expected: With `CRAFT_ENVIRONMENT=production`, every legacy-reading or destructive command hard-fails with a "production environment refused" error. dev/staging unaffected.
result: pass
evidence: Source-verified earlier (`use NeverProductionTrait` on all 4 console controllers). User confirmed runtime hard-fail behavior matches.

## Summary

total: 11
passed: 10
pass-by-design: 1
pending: 0
skipped: 0
gaps: 5

in-band fixes shipped during UAT:
  - G-01 → 363cc5c (settings template fragment shape)
  - G-03 → 0f56288 (forms.passwordField for secrets)

gaps deferred to Phase 4.1 (folded into scope sketch as CFG-05/06/07):
  - G-02 (CP page slimming + config-file overrides for advanced settings)
  - G-04 (editable-table dropdowns where values are knowable)
  - G-05 (REPORT.md sections always emit, even when empty)

## Gaps

### G-01 — _settings.twig was a full page, not an HTML fragment
related_test: 4
severity: blocking (CP settings would render with nested layouts before fix)
status: fixed in-band
fix_commit: 363cc5c
detail: |
  Template extended `_layouts/cp` and wrapped content in `{% block content %}`,
  but Craft's own `cms/templates/settings/plugins/_settings.twig` already
  extends `_layouts/cp` and dumps `{{ settingsHtml|raw }}` inside its own
  `{% block content %}` (with fullPageForm + actionInput already injected).
  Net effect: nested CP layouts and a double-form structure on the live
  settings page. Fixed by stripping `{% extends %}`, `{% set title %}`,
  and the surrounding `{% block content %}…{% endblock %}` wrapper.
follow_up: |
  Plan 04-05's acceptance criterion `grep -c 'extends "_layouts/cp"' = 1`
  codified the bug — Phase 4.1 RECONCILIATION should retire that grep
  and replace with a fragment-shape check (e.g. assert NO `{% extends %}`
  in `_settings.twig`). User caught this during UAT before any Craft
  install touched it.

### G-02 — CP settings page is too long; advanced fields should be config-file-only
related_test: 4
severity: ergonomics (functional but not idiomatic Craft)
status: open — fold into Phase 4.1
detail: |
  After the G-01 fix the page renders correctly, but it's ~23 fields tall
  across five `<h2>` sections. Operator feedback: too long for daily use,
  and Craft convention is to keep CP minimal while routing advanced
  settings through `config/{handle}.php` (auto-loaded by Craft, multi-env
  via `*`/`.dev`/`.production` keys, overrides DB-saved values).
proposed_split: |
  KEEP IN CP (9 fields, 2 H2 groups):
    Connectivity (6): legacyDbServer/Port/Database/User/Password, anthropicApiKey
    Mapping (3): kunstmaanSourcePath, mappingPath, localeMap

  MOVE TO config/kunstmaan-migrator.example.php (14 fields):
    legacyDbCharset, legacyDbTablePrefix,
    llmModel, llmTimeout, llmInterChunkDelay,
    defaultEntities, defaultLocales, defaultSince, defaultMaxPerEntity,
    dryRunDefault,
    verifyCountTolerance, verifyUrlDiffThreshold,
    seoTableName, redirectsTableName
follow_up: |
  Phase 4.1 requirement CFG-05 (added to scope sketch). Tabs aren't needed
  at 9 fields — H2 sections suffice. Tabs would need either an own-the-page
  switch from `settingsHtml()` to `getSettingsResponse()` (Twig set-scoping
  prevents fragment-set tabs from propagating to Craft's outer layout) or
  HTML-only tab markup; not worth the cost vs. just cutting 14 fields.

### G-03 — secret fields rendered as plaintext (autosuggestField ignores type:password)
related_test: 5
severity: security (raw secrets visible on settings page reload)
status: fixed in-band
fix_commit: 0f56288
detail: |
  Plan 04-05 used `forms.autosuggestField({type: 'password'})` for
  `anthropicApiKey` and `legacyDbPassword`. The autosuggest macro silently
  ignores `type:password` — it always renders a text input with the
  env-var dropdown. Plan 04-05's grep acceptance `type: 'password' = 2`
  matched the literal string in the template config but didn't verify
  the rendered HTML carried `<input type="password">`. Operator saw
  raw secret values on reload.
fix: |
  Switch both fields to `forms.passwordField` (real masked input).
  Trade-off: lost env-var autosuggest dropdown for these two fields;
  operator now types `$ANTHROPIC_API_KEY` / `$CRAFT_LEGACY_DB_PASSWORD`
  manually. `App::parseEnv()` resolves at read time — wired via
  `EnvAttributeParserBehavior` which already lists both attributes.
  Instructions copy updated to make the env-reference convention explicit.
follow_up: |
  Phase 4.1 RECONCILIATION should retire plan 04-05's `type: 'password'`
  grep in favor of an explicit `passwordField` shape check on those two
  field declarations.

### G-04 — editable-table columns should be dropdowns where values are knowable
related_test: 6
severity: ergonomics (functional today as free-text, but error-prone)
status: open — fold into Phase 4.1
detail: |
  All three editable tables (`defaultEntities`, `defaultLocales`, `localeMap`)
  currently use free-text columns. Operator must remember exact strings
  (locale codes, entity class basenames, Craft site handles) and typos
  silently break the migration. Constrained dropdowns prevent the typo
  class entirely.
data_sources_already_in_plugin: |
  - Craft site handles → `Craft::$app->getSites()->getAllSites()` (always available)
  - Legacy locale codes → `LocalePreflight::detect()` (SELECT DISTINCT lang FROM kuma_node_translations; needs legacy DB)
  - Kunstmaan entity basenames → `KunstmaanSourceScanner::scan()['entities']` (needs KUNSTMAAN_SOURCE_PATH)
proposed_split: |
  - `localeMap` right column (Craft site handle): dropdown — no dependency
  - `localeMap` left column + `defaultLocales` (legacy locales): dropdown when DB
    reachable, free-text fallback otherwise
  - `defaultEntities` (Kunstmaan basenames): dropdown when KUNSTMAAN_SOURCE_PATH
    set + scanner can run, free-text fallback otherwise
  Craft's `editableTableField` supports `cols: { handle: { type: 'select', options: [...] } }`.
  Work is plumbing option arrays from existing services into `Plugin::settingsHtml()`.
follow_up: |
  Phase 4.1 requirement CFG-06 (added to scope sketch). Composes with G-02
  proposed CP slimming — `defaultEntities`/`defaultLocales` move to
  config-file by G-02, so CFG-06 only applies to fields that survive the
  cut. Net: only `localeMap` keeps editable-table-with-dropdowns; the
  other two become array config-file values. Reconcile both gaps when
  scoping 4.1.

### G-05 — REPORT.md sections silently omitted when empty
related_test: 10
severity: ergonomics (matches plan 04-10's "omit when empty" design, but UX-confusing)
status: open — fold into Phase 4.1
detail: |
  After `migrate --dry-run` produces 0/0/0/0 counts, REPORT.md shows
  "Migration counts", "Rehearsal summary", and "Failures" but NOT
  "Skipped stages" or "Asset RCA". Implementation is correct per plan
  04-10 (`MigrateController.php:1107` + `:1154` both guard with
  `if ... !== []`). Operator reading the report can't tell whether
  those code paths ran or whether the implementation just isn't
  emitting them.
proposal: |
  Always emit all three D-68 sections. When empty, render placeholder text:
    ## Skipped stages

    _No stages skipped — all configured adapters were exercised._

    ## Asset RCA

    _No asset RCA rows — no assets were migrated, or all migrated cleanly._

  Constant report shape across runs makes "did this code path run?"
  trivially answerable. Costs ~6 lines of conditional removal.
follow_up: |
  Phase 4.1 requirement CFG-07 (added to scope sketch). Cheap to fix.
  Reconciles plan 04-10's "omit when empty" decision against operator
  diagnosability.
