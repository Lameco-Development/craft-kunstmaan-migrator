---
phase: 04-adapters-verify-settings
plan: 05
subsystem: cp-settings
tags: [cp, settings, twig, forms, editable-table, masked-input, D-62, D-63, D-64, CFG-01]
status: complete
requires:
  - phase: 04
    plan: "01"
    artifact: "src/models/Settings.php — 23 typed properties (post-Plan 04-01 with verifyCountTolerance / verifyUrlDiffThreshold / seoTableName / redirectsTableName added). The CP form binds 1:1 to these properties via Craft's standard plugin-settings save handler."
  - phase: 01
    plan: "bootstrap"
    artifact: "src/Plugin.php — `hasCpSettings = true` (D-16) + `settingsHtml()` returns the kunstmaan-migrator/_settings.twig template path. Already wired in Phase 1; this plan only replaces the template body."
provides:
  - artifact: "src/templates/_settings.twig (254 lines, full replacement)"
    summary: "Grouped-section CP form per D-62: five H2 sections (Connectivity / AI / Defaults / Verify / Adapters) covering all 23 Settings properties. Single Save button, standard `_layouts/cp` extension, no top-level CP nav and no Utilities entry. Roundtrips through Craft's built-in plugin-settings save handler. Three editable tables (D-63) for the array fields. Masked + env-hinted Anthropic API key (D-64)."
affects:
  - "Operator UX — Phase 1's placeholder is now replaced with a full CP form. Operators can configure all 23 Settings fields via Settings → Plugins → Kunstmaan Migrator without dropping into config/kunstmaan-migrator.php or env vars (env vars still win as fallbacks via EnvAttributeParserBehavior + Settings::init()). CFG-01 requirement closed."
  - "Phase 5 rehearsal — manual smoke deferred: visit Settings → Plugins → Kunstmaan Migrator in a Craft 5 dev install, verify the five H2 sections render, the password input is masked, the editable tables work, Save persists all 23 fields. Plan 04-12 (tests + reconciliation) owns the rehearsal sign-off."
  - "Future plans 04-09 / 04-10 — VerifyController + MigrateController extensions reading Settings::$verifyCountTolerance / Settings::$seoTableName / Settings::$redirectsTableName at runtime. Operators now have a CP path to override these alongside the existing env-var + config-file paths."
tech-stack:
  added: []
  patterns:
    - "Craft `_includes/forms` macro idiom — all 23 fields render via `forms.autosuggestField` (env-aware strings), `forms.textField` (numeric / float / non-env strings), `forms.lightswitchField` (`dryRunDefault`), `forms.editableTableField` (the three array fields). Native CP look; no Twig macro reinvention."
    - "D-62 grouped-section layout — five `<h2>` tags partition the form into Connectivity / AI / Defaults / Verify / Adapters. Single Save button (Craft's CP layout wraps the block content in the form automatically). Tabs were considered and rejected — H2 grouping is lower-friction for a form this short."
    - "D-63 editable tables for array fields — `defaultEntities` + `defaultLocales` are single-column tables, `localeMap` is a two-column legacy→craft table. `allowAdd` / `allowDelete` / `allowReorder` (where appropriate) so operators don't need to drop into YAML for routine edits. Plain text + comma-splitting was considered and rejected — `localeMap`'s tuple shape doesn't render well as CSV."
    - "D-64 masked password input — `anthropicApiKey` rendered as `type: 'password'` + `suggestEnvVars: true` + instructions string citing `ANTHROPIC_API_KEY` env-var override per Phase 1 / D-14. `legacyDbPassword` similarly masked. Doctor's existing presence-only reporting (T-1-03 invariant from Phase 1 / Plan 04) is preserved — Settings still never logs the resolved value; the CP just renders the input."
    - "EnvAttributeParserBehavior pass-through — every `forms.autosuggestField` carries `suggestEnvVars: true` so `$ENV_VAR` syntax keeps working in the CP field. The 14 env-aware properties on Settings (legacyDb*, anthropicApiKey, llmModel, mappingPath, defaultSince, kunstmaanSourcePath, seoTableName, redirectsTableName) all use the autosuggest macro; non-env scalars use plain `forms.textField`."
    - "No CP nav, no Utilities entry — PROJECT.md Out-of-Scope. Form roundtrips through `hasCpSettings = true` (Phase 1 / D-16) + `Plugin::settingsHtml()` only. No `csrfInput()` / `actionInput()` / `<form>` tags in the template — Craft's CP layout wraps the block content automatically."
key-files:
  created: []
  modified:
    - "src/templates/_settings.twig (12 lines → 254 lines) — replaced Phase 1 placeholder with the grouped-section form. Preserved `{% extends '_layouts/cp' %}`."
decisions:
  - "D-62 grouped-section layout (single page, H2-separated). Tabs were considered and rejected — only 23 fields total; H2 grouping with one Save button is lower-friction. Standard `_layouts/cp` extension matches every other Craft 5 plugin's settings page."
  - "D-63 editable tables for the three array fields. `defaultEntities` + `defaultLocales` are single-column with `allowReorder` (operator-determined order matters for CLI default-arg precedence). `localeMap` is two-column legacy→craft; reorder-irrelevant for a hash map so `allowReorder` omitted. Plain text + comma-split was considered and rejected — localeMap's tuple shape doesn't fit CSV."
  - "D-64 masked password for `anthropicApiKey` + env hint instructions. Plain-text-with-env-only-recommendation was considered but loses muscle memory: every other CP plugin masks API keys. `legacyDbPassword` similarly masked — same security argument. The `EnvAttributeParserBehavior` (already on `anthropicApiKey` per Phase 1) means `$ENV_VAR` syntax keeps working in the CP field too."
  - "All 23 Settings properties rendered (plan said '19' but Settings.php post-Plan 04-01 declares 23 — `mappingPath` was always there + 4 new Phase 4 fields). The plan's instruction 'render every Settings property declared in src/models/Settings.php' supersedes the field-count number; acceptance criteria don't grep-check field counts beyond the named ones (verify*, seo*, redirects*, dryRunDefault, localeMap, ANTHROPIC_API_KEY)."
  - "No CP nav entry / no Utilities entry. PROJECT.md Out-of-Scope explicitly lists 'A Control Panel pipeline runner UI' and 'Inline mapping authoring in the CP'. The CP Settings page is the only operator surface in the CP; CLI is canonical for everything else."
  - "Form name attributes are unprefixed (`name: 'legacyDbServer'`, NOT `name: 'settings[legacyDbServer]'`). Matches v1 brownfield convention (`~/Sites/craft-kunstmaan-migrator/templates/_settings.twig` line 7). Craft's plugin save controller wraps unprefixed names; PATTERNS.md template snippet showed prefixed names but plan task instructions explicitly used unprefixed — plan wins."
  - "No `csrfInput()` / `actionInput()` / `<form>` tags. Plan task explicitly forbade these — Craft's CP layout wraps the block content in the form automatically when `hasCpSettings = true`. PATTERNS.md snippet showed them but plan wins per the same precedence rule above."
  - "Verify-section thresholds (verifyCountTolerance + verifyUrlDiffThreshold) render as `forms.textField` with `type: 'number'`, `step: '0.001'`, `min: '0'`, `max: '1'`. Matches the Settings.php validator rule `[..., 'number', 'min' => 0, 'max' => 1]`. Float env-parse was deliberately not added to EnvAttributeParserBehavior (PATTERNS.md flag #2 — fragile); CLI override on `verify` runs is the runtime knob, CP form is the persistent override."
metrics:
  completed: "2026-04-26"
  tasks-completed: "1/1"
  total-loc-added: "242 (12 → 254)"
  test-suite: "60 tests / 137 assertions (unchanged from baseline; no test additions per plan — Plan 04-12 owns Phase 4 test corpus)"
---

# Phase 4 Plan 05: CP Settings Template Summary

**`src/templates/_settings.twig` replaces the Phase 1 placeholder (12 lines) with a 254-line grouped-section CP form per D-62. Five H2 sections (Connectivity / AI / Defaults / Verify / Adapters) cover all 23 Settings properties from the post-Plan 04-01 model. Three editable tables (D-63) handle the array fields. The Anthropic API key is masked + carries an env-var hint (D-64). Form roundtrips through Craft's standard plugin-settings save handler — no CP nav entry, no Utilities entry. CFG-01 closed.**

## Status

**COMPLETE.** Single task executed and committed (7a5d1a8). Template replaced byte-for-byte; placeholder note removed. composer test green (60 tests / 137 assertions — unchanged baseline). Zero plan-deviations.

## Performance

- **Duration:** ~12 min
- **Tasks:** 1/1
- **Files created:** 0
- **Files modified:** 1 (`src/templates/_settings.twig`)

## Tasks Completed

| Task | Name                                                          | Commit   | Files                          |
| ---- | ------------------------------------------------------------- | -------- | ------------------------------ |
| 1    | Replace _settings.twig placeholder with grouped CP form       | 7a5d1a8  | `src/templates/_settings.twig` |

## Acceptance Criteria — Verified

All 22 grep-based acceptance criteria from the plan task pass:

| Check                                              | Expected | Actual |
| -------------------------------------------------- | -------- | ------ |
| `extends "_layouts/cp"`                            | 1        | 1      |
| `editableTableField`                               | 3        | 3      |
| `type: 'password'`                                 | >= 1     | 2      |
| `ANTHROPIC_API_KEY`                                | >= 1     | 1      |
| `<h2>`                                             | 5        | 5      |
| `'Connectivity'`                                   | >= 1     | 1      |
| `'AI'`                                             | >= 1     | 1      |
| `'Defaults'`                                       | >= 1     | 1      |
| `'Verify'`                                         | >= 1     | 1      |
| `'Adapters'`                                       | >= 1     | 1      |
| `verifyCountTolerance`                             | >= 1     | 3      |
| `verifyUrlDiffThreshold`                           | >= 1     | 3      |
| `seoTableName`                                     | >= 1     | 3      |
| `redirectsTableName`                               | >= 1     | 3      |
| `dryRunDefault`                                    | >= 1     | 3      |
| `localeMap`                                        | >= 1     | 3      |
| `kunstmaan-migrator/utilities`                     | 0        | 0      |
| `<nav` / `nav-item`                                | 0        | 0      |
| `suggestEnvVars: true`                             | >= 8     | 13     |
| `composer test` exit code                          | 0        | 0      |

The two `type: 'password'` matches cover both `anthropicApiKey` (D-64) and `legacyDbPassword` (parity — every other CP plugin masks DB passwords). The 13 `suggestEnvVars: true` occurrences match the 14 EnvAttributeParserBehavior-decorated Settings properties minus 1 (legacyDbPassword's autosuggest carries `suggestEnvVars: true` like the others — recount = 13 because `mappingPath` also gets it, `defaultSince` also gets it, totalling 13 of the 14 env-aware fields rendered via autosuggest). All five section labels appear exactly once each.

## Form structure

```
<h2>Connectivity</h2>
  legacyDbServer (autosuggest, suggestEnvVars)
  legacyDbPort (textField, number)
  legacyDbDatabase (autosuggest, suggestEnvVars)
  legacyDbUser (autosuggest, suggestEnvVars)
  legacyDbPassword (autosuggest, suggestEnvVars, type=password)
  legacyDbCharset (autosuggest, suggestEnvVars)
  legacyDbTablePrefix (autosuggest, suggestEnvVars)
  kunstmaanSourcePath (autosuggest, suggestEnvVars)

<h2>AI</h2>
  anthropicApiKey (autosuggest, suggestEnvVars, type=password) — D-64
  llmModel (autosuggest, suggestEnvVars)
  llmTimeout (textField, number)
  llmInterChunkDelay (textField, number)

<h2>Defaults</h2>
  mappingPath (autosuggest, suggestEnvVars)
  defaultEntities (editableTableField, allowReorder) — D-63
  defaultLocales (editableTableField, allowReorder) — D-63
  localeMap (editableTableField, two-col) — D-63
  defaultSince (autosuggest, suggestEnvVars)
  defaultMaxPerEntity (textField, number)
  dryRunDefault (lightswitchField)

<h2>Verify</h2>
  verifyCountTolerance (textField, number, step=0.001, min=0, max=1)
  verifyUrlDiffThreshold (textField, number, step=0.001, min=0, max=1)

<h2>Adapters</h2>
  seoTableName (autosuggest, suggestEnvVars)
  redirectsTableName (autosuggest, suggestEnvVars)
```

## Deviations from Plan

None — plan executed exactly as written, with one documented interpretation:

- **Field count.** Plan introduction says "all 19 Settings fields"; Settings.php post-Plan 04-01 actually declares 23 typed properties (legacyDb* 7 + anthropicApiKey 1 + llmModel/Timeout/InterChunkDelay 3 + mappingPath/kunstmaanSourcePath 2 + defaultEntities/Locales/localeMap 3 + defaultSince/MaxPerEntity/dryRunDefault 3 + verifyCountTolerance/UrlDiffThreshold 2 + seoTableName/redirectsTableName 2). The plan's task instruction overrides — "Render every Settings property declared in src/models/Settings.php" — was followed; the "19" intro figure is an artefact from before mappingPath / kunstmaanSourcePath / verify thresholds / adapter table-names landed. All 23 are rendered.

## CFG-01 — Closed

This plan completes the CP Settings page promised by `CFG-01` and deferred to Phase 4 in PROJECT.md Key Decisions ("CP Settings page deferred to Phase 4"). Operators can now configure all 23 Settings fields via Settings → Plugins → Kunstmaan Migrator. Env vars (`CRAFT_LEGACY_DB_*`, `ANTHROPIC_API_KEY`, `KUNSTMAAN_SOURCE_PATH`) still work as fallbacks via Settings::init() + EnvAttributeParserBehavior; config/kunstmaan-migrator.php overrides still win when present (Craft loads the config file before init() and assigns to public properties before env-fallback runs).

## Manual smoke deferred to Phase 5 rehearsal

Per the plan's Verification section: "visit Settings → Plugins → Kunstmaan Migrator in a Craft 5 dev install, verify the five H2 sections render, the password input is masked, the editable tables work, Save persists all 23 fields." This requires a Craft 5 dev install with the plugin loaded. Plan 04-12 (tests + reconciliation) covers the static-analysis + grep verification done here; the live-CP smoke is sequenced into Phase 5 rehearsal alongside the doctor / analyze / map / migrate / verify end-to-end flow.

## Self-Check: PASSED

- File exists at `src/templates/_settings.twig` (254 lines).
- Commit `7a5d1a8` present in `git log --oneline -5`.
- All 22 grep-based acceptance criteria pass.
- composer test exits 0 (60 / 137 — unchanged baseline).
