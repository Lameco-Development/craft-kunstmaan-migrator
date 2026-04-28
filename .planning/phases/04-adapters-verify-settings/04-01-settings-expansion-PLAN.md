---
plan: 01
phase: 04
title: "Settings expansion: verify thresholds + adapter table-name overrides"
wave: 1
depends_on: []
files_modified:
  - src/models/Settings.php
autonomous: true
requirements_addressed: [CFG-01]
---

# Plan 04-01: Settings expansion — verify thresholds + adapter table-name overrides

## Objective

Add the four Phase 4 properties to `Settings.php` so downstream Wave 2/3/4 plans can read them: `verifyCountTolerance`, `verifyUrlDiffThreshold` (per D-60), `seoTableName`, `redirectsTableName` (per D-57). Extend `rules()` with a `'number'` validator for the float pair, extend `EnvAttributeParserBehavior` with the two string fields (NOT the floats — float env-parse is fragile, advisor flag #2 in PATTERNS.md), and preserve the Phase 1 `anthropicApiKey` env-fallback / never-logged invariants intact.

This is the foundation for Wave 2: every adapter and verify service reads at least one of these properties.

## Context

- Phase 1 / D-15 declared most Settings fields upfront — Phase 4 extends, doesn't replace.
- D-60: tolerance via Settings + CLI override (mirrors Phase 2 / D-10 merge pattern).
- D-57: hardcoded behavior for SEO + Retour reads, with table-name override seam for non-CQM Kunstmaan flavours.
- D-64: `anthropicApiKey` masking discipline preserved (this plan does NOT touch the existing field).
- PATTERNS.md advisor flag #1: existing `rules()` has no `'number'` validator — must add for the floats.
- PATTERNS.md advisor flag #2: only `seoTableName` + `redirectsTableName` join `EnvAttributeParserBehavior` — floats stay out.

## Tasks

<task id="01">
  <action>
Add four properties to `src/models/Settings.php` immediately after the existing `dryRunDefault` declaration:
- `public float $verifyCountTolerance = 0.01;` — D-60 default ±1%, ROADMAP success criterion 3.
- `public float $verifyUrlDiffThreshold = 0.05;` — D-60 default 5% URL diff threshold.
- `public string $seoTableName = 'kuma_seo';` — D-57 source-table override for SEO adapter.
- `public string $redirectsTableName = 'kuma_redirects';` — D-57 source-table override for Retour adapter.

Add docblock comments above each property block citing the decision IDs (`Phase 4 / D-60` and `Phase 4 / D-57`) so future readers can trace the origin.
  </action>
  <read_first>
    - src/models/Settings.php (current Phase 1+02.1+3 baseline; locate the `dryRunDefault` line and the trailing `rules()` / `behaviors()` methods before editing)
    - .planning/phases/04-adapters-verify-settings/04-CONTEXT.md (D-57, D-60, D-64 wording)
    - .planning/phases/04-adapters-verify-settings/04-PATTERNS.md (Settings.php section, advisor flags 1+2)
    - .planning/phases/01-foundation-connectivity/01-CONTEXT.md (Phase 1 / D-15 Settings field declaration pattern)
  </read_first>
  <acceptance_criteria>
    - `grep -c 'public float \$verifyCountTolerance = 0.01' src/models/Settings.php` returns `1`
    - `grep -c 'public float \$verifyUrlDiffThreshold = 0.05' src/models/Settings.php` returns `1`
    - `grep -c "public string \$seoTableName = 'kuma_seo'" src/models/Settings.php` returns `1`
    - `grep -c "public string \$redirectsTableName = 'kuma_redirects'" src/models/Settings.php` returns `1`
    - `grep -c 'D-60' src/models/Settings.php` returns at least `1` (decision-ID traceability)
    - `grep -c 'D-57' src/models/Settings.php` returns at least `1` (decision-ID traceability)
  </acceptance_criteria>
</task>

<task id="02">
  <action>
Extend `rules()` in `src/models/Settings.php` with a Yii `'number'` validator for the two new float fields and a `'string'` validator for the two new string fields. Add to the array (before the closing `];`):

```php
[['verifyCountTolerance', 'verifyUrlDiffThreshold'], 'number', 'min' => 0, 'max' => 1],
[['seoTableName', 'redirectsTableName'], 'string'],
```

Add `seoTableName` and `redirectsTableName` to the `EnvAttributeParserBehavior` `attributes` list inside `behaviors()`. **Do NOT** add the float fields — see PATTERNS.md advisor flag #2 (env-parse of floats is fragile).
  </action>
  <read_first>
    - src/models/Settings.php (especially the `rules()` and `behaviors()` methods — must preserve existing entries)
    - .planning/phases/04-adapters-verify-settings/04-PATTERNS.md (Settings.php section, exact rules() additions)
  </read_first>
  <acceptance_criteria>
    - `grep -c "'number'" src/models/Settings.php` returns at least `1` (new validator added)
    - `grep -E "verifyCountTolerance.*verifyUrlDiffThreshold|verifyUrlDiffThreshold.*verifyCountTolerance" src/models/Settings.php` returns at least `1` (paired in number rule)
    - `grep -E "'min' => 0, 'max' => 1" src/models/Settings.php` returns at least `1`
    - `grep -E "seoTableName.*redirectsTableName|redirectsTableName.*seoTableName" src/models/Settings.php` returns at least `2` (one in rules, one in behaviors)
    - `grep -c 'verifyCountTolerance' src/models/Settings.php` returns exactly `2` (property declaration + rules entry only — NOT in behaviors attributes)
    - `composer test` exits `0` (PluginBootstrapTest still loads Settings cleanly)
    - `php -r 'require "vendor/autoload.php"; new \lameco\kunstmaanmigrator\models\Settings();'` runs without errors (syntactic + Yii validation rule sanity)
  </acceptance_criteria>
</task>

## Verification

- `composer test` exits 0.
- A fresh `Settings` instantiation produces the four new properties with the documented defaults: a one-shot script can `echo (new Settings())->verifyCountTolerance` and assert `0.01`.
- `EnvAttributeParserBehavior` resolves `$KUNSTMAAN_SEO_TABLE` if set as `kuma_seo` env override (manual smoke; not a unit test in this plan — Plan 04-12 covers tests).

## must_haves

- Settings model exposes 4 new typed properties with documented defaults.
- Yii `'number'` validator pins `verifyCountTolerance` and `verifyUrlDiffThreshold` to `[0, 1]`.
- `EnvAttributeParserBehavior` ladder includes the two string fields (env-var override works) and excludes the two floats.
- Phase 1 / D-14 `anthropicApiKey` never-logged invariant preserved (this plan does not modify that code path).
- `composer test` stays green.
