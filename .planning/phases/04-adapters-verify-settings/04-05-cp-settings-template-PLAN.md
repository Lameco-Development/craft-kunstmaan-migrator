---
plan: 05
phase: 04
title: "CP Settings template — grouped sections + editable tables + masked API key"
wave: 2
depends_on: ["04-01"]
files_modified:
  - src/templates/_settings.twig
autonomous: true
requirements_addressed: [CFG-01]
---

# Plan 04-05: CP Settings template

## Objective

Replace the Phase 1 placeholder `_settings.twig` with the full grouped-section CP form per D-62. Five H2 sections (Connectivity / AI / Defaults / Verify / Adapters) cover all 19 Settings fields. `defaultEntities`, `defaultLocales`, `localeMap` use Craft's `editableTableField` macro (D-63). `anthropicApiKey` is masked + carries an env-var hint (D-64). Phase 1 / D-16's `hasCpSettings = true` + `Plugin::settingsHtml()` already point at this template; this plan replaces only the body.

## Context

- D-62: single page, H2-grouped sections (no tabs). Standard `_layouts/cp` extension, single Save button.
- D-63: `editableTable` for array fields — native Craft idiom.
- D-64: `<input type="password">` + env hint, NOT plain text.
- PROJECT.md out-of-scope: NO top-level CP nav entry, NO Utilities entry. The form roundtrips through Craft's standard plugin-settings save handler.
- Phase 1 / D-14: `anthropicApiKey` never logged — Settings already preserves this; the template just renders the input.
- Settings.php now has 19 properties post-Plan 04-01.

## Tasks

<task id="01">
  <action>
Replace the contents of `src/templates/_settings.twig` with the grouped-section form. Preserve `{% extends "_layouts/cp" %}` from the placeholder.

Structure:

```twig
{% extends "_layouts/cp" %}
{% import "_includes/forms" as forms %}

{% set title = "Kunstmaan Migrator"|t('kunstmaan-migrator') %}

{% block content %}

<h2>{{ 'Connectivity'|t('kunstmaan-migrator') }}</h2>

{{ forms.autosuggestField({
    label: 'Legacy DB host'|t('kunstmaan-migrator'),
    id: 'legacyDbServer',
    name: 'legacyDbServer',
    value: settings.legacyDbServer,
    suggestEnvVars: true,
}) }}
{# legacyDbPort, legacyDbDatabase, legacyDbUser, legacyDbPassword (type=password), legacyDbCharset, legacyDbTablePrefix, kunstmaanSourcePath — all autosuggestField with suggestEnvVars: true #}

<h2>{{ 'AI'|t('kunstmaan-migrator') }}</h2>

{{ forms.autosuggestField({
    label: 'Anthropic API key'|t('kunstmaan-migrator'),
    id: 'anthropicApiKey',
    name: 'anthropicApiKey',
    value: settings.anthropicApiKey,
    type: 'password',
    suggestEnvVars: true,
    instructions: 'Defaults to ANTHROPIC_API_KEY env var; setting here overrides env per Phase 1 / D-14.'|t('kunstmaan-migrator'),
}) }}
{# llmModel (autosuggestField), llmTimeout (textField, integer), llmInterChunkDelay (textField, integer) #}

<h2>{{ 'Defaults'|t('kunstmaan-migrator') }}</h2>

{{ forms.editableTableField({
    label: 'Default entity allow-list'|t('kunstmaan-migrator'),
    id: 'defaultEntities',
    name: 'defaultEntities',
    cols: {
        entity: { heading: 'Entity handle'|t('kunstmaan-migrator'), type: 'singleline' },
    },
    rows: settings.defaultEntities|default([])|map(e => { entity: e }),
    addRowLabel: 'Add entity'|t('kunstmaan-migrator'),
    allowAdd: true,
    allowDelete: true,
    allowReorder: true,
}) }}

{# defaultLocales — same shape, single col 'locale' #}

{{ forms.editableTableField({
    label: 'Locale map'|t('kunstmaan-migrator'),
    id: 'localeMap',
    name: 'localeMap',
    cols: {
        legacy: { heading: 'Legacy locale'|t('kunstmaan-migrator'), type: 'singleline' },
        craft: { heading: 'Craft site handle'|t('kunstmaan-migrator'), type: 'singleline' },
    },
    rows: settings.localeMap|default([]),
    addRowLabel: 'Add locale mapping'|t('kunstmaan-migrator'),
    allowAdd: true,
    allowDelete: true,
}) }}

{# defaultSince (textField), defaultMaxPerEntity (textField), dryRunDefault (lightswitchField) #}

<h2>{{ 'Verify'|t('kunstmaan-migrator') }}</h2>

{# verifyCountTolerance (textField step 0.001 — D-60), verifyUrlDiffThreshold (textField step 0.001 — D-60) #}

<h2>{{ 'Adapters'|t('kunstmaan-migrator') }}</h2>

{# seoTableName (autosuggestField with suggestEnvVars: true — D-57), redirectsTableName (autosuggestField with suggestEnvVars: true — D-57) #}

{% endblock %}
```

Render every Settings property declared in `src/models/Settings.php`. Use `forms.autosuggestField` for any field that supports env-var hints (everything decorated with `EnvAttributeParserBehavior`), `forms.textField` for plain numeric/string fields without env support, `forms.lightswitchField` for `dryRunDefault`, `forms.editableTableField` for the three array fields per D-63.

The form posts to Craft's built-in plugin-settings save handler — DO NOT add `csrfInput()`, `actionInput()`, or `<form>` tags. Craft's CP layout wraps the block content in the form automatically.
  </action>
  <read_first>
    - src/templates/_settings.twig (current placeholder — confirm extension chain)
    - src/models/Settings.php (post-Plan 04-01 — confirm all 19 property names + types so each one is rendered)
    - src/Plugin.php (around lines 311-317 — confirm `settingsHtml()` returns this template path)
    - .planning/phases/04-adapters-verify-settings/04-PATTERNS.md (`_settings.twig` section, D-62 grouping spec, D-63 editableTableField shape, D-64 masked input)
    - .planning/phases/04-adapters-verify-settings/04-CONTEXT.md (D-62, D-63, D-64)
    - .planning/phases/01-foundation-connectivity/01-CONTEXT.md (D-14 anthropicApiKey never-logged invariant; D-16 hasCpSettings)
  </read_first>
  <acceptance_criteria>
    - `grep -c 'extends "_layouts/cp"' src/templates/_settings.twig` returns `1`
    - `grep -c 'editableTableField' src/templates/_settings.twig` returns `3` (defaultEntities + defaultLocales + localeMap)
    - `grep -c "type: 'password'" src/templates/_settings.twig` returns at least `1` (anthropicApiKey masking — D-64)
    - `grep -c 'ANTHROPIC_API_KEY' src/templates/_settings.twig` returns at least `1` (env hint — D-64)
    - `grep -c '<h2>' src/templates/_settings.twig` returns `5` (Connectivity / AI / Defaults / Verify / Adapters — D-62)
    - `grep -c "'Connectivity'" src/templates/_settings.twig` returns at least `1`
    - `grep -c "'AI'" src/templates/_settings.twig` returns at least `1`
    - `grep -c "'Defaults'" src/templates/_settings.twig` returns at least `1`
    - `grep -c "'Verify'" src/templates/_settings.twig` returns at least `1`
    - `grep -c "'Adapters'" src/templates/_settings.twig` returns at least `1`
    - `grep -c 'verifyCountTolerance' src/templates/_settings.twig` returns at least `1`
    - `grep -c 'verifyUrlDiffThreshold' src/templates/_settings.twig` returns at least `1`
    - `grep -c 'seoTableName' src/templates/_settings.twig` returns at least `1`
    - `grep -c 'redirectsTableName' src/templates/_settings.twig` returns at least `1`
    - `grep -c 'dryRunDefault' src/templates/_settings.twig` returns at least `1`
    - `grep -c 'localeMap' src/templates/_settings.twig` returns at least `1`
    - `grep -c 'kunstmaan-migrator/utilities' src/templates/_settings.twig` returns `0` (no Utilities entry — out-of-scope)
    - `grep -E '<nav|nav-item' src/templates/_settings.twig` returns at most `0` (no top-level CP nav entry)
    - `grep -c 'suggestEnvVars: true' src/templates/_settings.twig` returns at least `8` (every env-aware field uses the hint)
    - `composer test` exits `0` (PluginBootstrapTest still passes — template syntax is parsed lazily but Plugin::settingsHtml() path resolution happens at boot)
  </acceptance_criteria>
</task>

## Verification

- Manual smoke (deferred to Phase 5 rehearsal): visit `Settings → Plugins → Kunstmaan Migrator` in a Craft 5 dev install, verify the five H2 sections render, the password input is masked, the editable tables work, Save persists all 19 fields.
- `composer test` exits 0.

## must_haves

- `src/templates/_settings.twig` renders five H2 sections (Connectivity / AI / Defaults / Verify / Adapters).
- All 19 Settings properties are rendered as form fields.
- `defaultEntities`, `defaultLocales`, `localeMap` use `editableTableField` macros.
- `anthropicApiKey` is rendered as `type: 'password'` with the env-var hint string.
- No top-level CP nav, no Utilities entry — only the Settings → Plugins entry roundtrip.
- `composer test` stays green.
