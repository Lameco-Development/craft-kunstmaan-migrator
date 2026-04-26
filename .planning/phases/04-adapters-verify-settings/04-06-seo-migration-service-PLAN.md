---
plan: 06
phase: 04
title: "SeoMigrationService verbatim port (SEOmatic adapter)"
wave: 3
depends_on: ["04-01", "04-02"]
files_modified:
  - src/load/SeoMigrationService.php
autonomous: true
requirements_addressed: [ADP-01]
---

# Plan 04-06: SeoMigrationService verbatim port

## Objective

Port v1's `bridge/load/SeoMigrationService.php` (600 LOC) verbatim to `src/load/SeoMigrationService.php`. Per-locale SEOmatic MetaBundle migration that runs LAST in the in-process pipeline (D-55 — kuma_seo image refs resolve via state lookup once entries+assets exist). Implements D-56 in-service detection (short-circuits with WARN when SEOmatic absent) and D-57 table-name override seam.

## Context

- D-54: verbatim port + RECONCILIATION discipline.
- D-55: bolt-on after finalize + standalone `migrate/seo` sub-action (sub-action lives in Plan 04-10).
- D-56: optional-plugin gate inside the service (NOT at controller). Single seam.
- D-57: `Settings::$seoTableName` override (default `kuma_seo`).
- v2 reshape (PATTERNS.md flag #3): `$sites` is wired from `Plugin::resolveSitesMap()`, NOT a mapping.yaml `sites:` block. Source of truth lives at `src/Plugin.php:280-304` and is already fed into `EntryMigrationService::$sites` at line 260.
- `propagateChanges = false` invariant on `saveElement` is load-bearing per RESEARCH.md §2 — don't drop the third arg.
- DI wiring lives in Plan 04-09.

## Tasks

<task id="01">
  <action>
Create `src/load/SeoMigrationService.php`. Copy v1's `~/Sites/craft-kunstmaan-migrator/src/bridge/load/SeoMigrationService.php` body byte-for-byte. Reshape ONLY:

1. **Namespace** `bridge\load` → `load`.
2. **Imports:**
   - `MigrationStateService` → `lameco\kunstmaanmigrator\load\MigrationStateService` (Phase 3 / Plan 03-03 location).
   - `MigrationOptions` → `lameco\kunstmaanmigrator\load\MigrationOptions` (Phase 3 / Plan 03-02 location).
   - `MigrationReport` → `lameco\kunstmaanmigrator\load\MigrationReport` (Phase 3 / Plan 03-12 location).
   - `LegacyDbService` → `lameco\kunstmaanmigrator\db\LegacyDbService` (Phase 1 location).
   - `SeomaticPayloadBuilder` → `lameco\kunstmaanmigrator\load\SeomaticPayloadBuilder` (Plan 04-02 just shipped).
   - `MigrationFilters` → `lameco\kunstmaanmigrator\filter\MigrationFilters` (Phase 2 location).
3. **`$sites` source** — drop any v1 `mapping.yaml` `sites:` reading. The property stays as a public `array $sites = []`; Plan 04-09 wires it from `Plugin::resolveSitesMap()` in `Plugin::init()`.

Preserve verbatim:
- The CONFIG-08 optional-plugin gate at BOTH call sites (`migrateAll` line ~126 + `migrateForEntry` line ~255):
  ```php
  if (Craft::$app->plugins->getPlugin('seomatic') === null) {
      Craft::warning('SEOmatic plugin not installed; skipping SEO migration pass.', 'kunstmaanmigrator');
      $report->warn('SEOmatic plugin not installed; SEO migration skipped.');
      return $report;
  }
  ```
- The state-driven source discovery query (v1 lines 162-172):
  ```php
  $sources = array_column((new Query())->select('source')->distinct()
      ->from('{{%kunstmaanmigrator_state}}')
      ->where(['targetType' => 'entry'])
      ->andWhere(['not in', 'source', [self::STATE_SOURCE]])
      ->all(), 'source');
  ```
- `STATE_SOURCE = 'seo_meta'` self-exclusion constant.
- The per-site fan-out with `setCurrentSite` / `previousSite` swap and `Craft::$app->elements->saveElement($entry, true, false)` — third arg `false` is `$propagate`, load-bearing per RESEARCH.md §2.
- `$seoTableName = 'kuma_seo'` default (D-57 override seam — Plan 04-09 sets from Settings if non-default).
- All public DI surface: `$legacyDb`, `$stateService`, `$seoPayload`, `$filters`, `$seoTableName`, `$sites`.

The body is 600 LOC; do not rewrite, do not refactor, do not "improve".
  </action>
  <read_first>
    - ~/Sites/craft-kunstmaan-migrator/src/bridge/load/SeoMigrationService.php (entire file — verbatim source)
    - src/load/SeomaticPayloadBuilder.php (Plan 04-02 just-built — confirm v2 import path)
    - src/load/MigrationStateService.php (Phase 3 / Plan 03-03 — confirm v2 namespace + getTargetId signature)
    - src/load/MigrationOptions.php (Phase 3 / Plan 03-02 — confirm v2 constructor signature)
    - src/load/MigrationReport.php (Phase 3 / Plan 03-12 — confirm `incr` / `warn` / `merge` API matches v1 expectations)
    - src/db/LegacyDbService.php (Phase 1 / Plan 02 — confirm v2 namespace)
    - src/Plugin.php (lines 260-304 — `resolveSitesMap()` shape that Plan 04-09 will plumb in)
    - .planning/phases/04-adapters-verify-settings/04-PATTERNS.md (SeoMigrationService section — exact reshape list, advisor flags)
    - .planning/phases/04-adapters-verify-settings/04-CONTEXT.md (D-54, D-55, D-56, D-57)
  </read_first>
  <acceptance_criteria>
    - `test -f src/load/SeoMigrationService.php` returns true
    - `grep -c '^namespace lameco\\\\kunstmaanmigrator\\\\load;' src/load/SeoMigrationService.php` returns `1`
    - `grep -c 'class SeoMigrationService extends Component' src/load/SeoMigrationService.php` returns `1`
    - `grep -E "getPlugin\('seomatic'\) === null" src/load/SeoMigrationService.php` returns at least `2` (D-56 — gate at both call sites: migrateAll + migrateForEntry)
    - `grep -c "STATE_SOURCE = 'seo_meta'" src/load/SeoMigrationService.php` returns `1`
    - `grep -c "saveElement(\$entry, true, false)" src/load/SeoMigrationService.php` returns at least `1` (propagate=false invariant preserved)
    - `grep -c "public string \$seoTableName = 'kuma_seo'" src/load/SeoMigrationService.php` returns `1` (D-57 default)
    - `grep -c 'public array \$sites' src/load/SeoMigrationService.php` returns `1` (PATTERNS flag #3 — wired in Plugin::init via resolveSitesMap)
    - `grep -c 'public LegacyDbService \$legacyDb' src/load/SeoMigrationService.php` returns `1`
    - `grep -c 'public MigrationStateService \$stateService' src/load/SeoMigrationService.php` returns `1`
    - `grep -c 'public SeomaticPayloadBuilder \$seoPayload' src/load/SeoMigrationService.php` returns `1`
    - `grep -c '?MigrationFilters \$filters' src/load/SeoMigrationService.php` returns at least `1`
    - `grep -c 'kuma_media:' src/load/SeoMigrationService.php` returns at least `1` (state-lookup key for media resolution)
    - `grep -c 'kunstmaanmigrator_state' src/load/SeoMigrationService.php` returns at least `1`
    - `grep -c 'bridge.load' src/load/SeoMigrationService.php` returns `0` (no leftover bridge\ namespace)
    - `php -l src/load/SeoMigrationService.php` outputs `No syntax errors detected`
    - Line count within ±20 of v1's 600 LOC: `[ $(wc -l < src/load/SeoMigrationService.php) -ge 580 ] && [ $(wc -l < src/load/SeoMigrationService.php) -le 620 ]`
    - `composer test` exits `0`
  </acceptance_criteria>
</task>

## Verification

- Static load: `php -r 'require "vendor/autoload.php"; new \lameco\kunstmaanmigrator\load\SeoMigrationService();'` runs without errors.
- Optional-plugin gate verified by reading both call sites.
- Runtime exercise deferred to Phase 5 / TST-04 rehearsal smoke (CQM dump with SEOmatic installed → counts match within tolerance).

## must_haves

- File `src/load/SeoMigrationService.php` exists with the v2 namespace + correct imports.
- D-56 optional-plugin gate present at both call sites (`migrateAll` + `migrateForEntry`).
- `STATE_SOURCE = 'seo_meta'` self-exclusion constant intact.
- `propagateChanges = false` arg preserved on `saveElement` calls.
- `$sites` is a public bare property — Plan 04-09 wires it from `Plugin::resolveSitesMap()` in `Plugin::init()`.
- `$seoTableName` override seam present (D-57).
- `composer test` stays green.

## RECONCILIATION

| v1 rule | v2 disposition |
|---|---|
| Namespace `lameco\kunstmaanmigrator\bridge\load` | **reshaped** — flattened to `load`. |
| Imports from `bridge\load\*` and `models\MigrationReport` | **reshaped** — point at v2 Phase 3 locations. |
| CONFIG-08 SEOmatic optional-plugin gate at both call sites | **ported** — D-56 idiom, byte-for-byte. |
| `STATE_SOURCE = 'seo_meta'` constant + self-exclusion `not in` clause in source-distinct query | **ported** — load-bearing for "SEO runs last" ordering (D-55). |
| Per-site fan-out with `setCurrentSite($previousSite)` finally block | **ported** — required for cross-locale SEO bundle correctness. |
| `Craft::$app->elements->saveElement($entry, true, false)` `propagate=false` third arg | **ported** — RESEARCH.md §2 invariant; v1 docblock at lines 27-30 cites this verbatim. |
| `$sites` sourced from mapping.yaml `sites:` block (v1 line 67-74) | **reshaped** — v2 wires from `Plugin::resolveSitesMap()` in `Plugin::init()` (PATTERNS flag #3). mapping.yaml stays clean of site config; same map already feeds `EntryMigrationService::$sites`. |
| `$seoTableName = 'kuma_seo'` default with `setComponents` override seam | **ported + reshaped** — D-57 default kept; override now sourced from `Settings::$seoTableName` in Plugin::init (Plan 04-09) instead of v1's setComponents call. |
| `$legacyDb`, `$stateService`, `$seoPayload`, `$filters` public DI properties | **ported** — bare-public-property DI surface. Plugin::config + init wiring lands in Plan 04-09. |
