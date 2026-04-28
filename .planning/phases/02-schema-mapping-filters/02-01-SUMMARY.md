---
phase: 02-schema-mapping-filters
plan: 01
subsystem: filter+locale primitives
tags: [filter, locale, value-object, plugin-components]
requires:
  - Phase 1 / Plugin::config() components map (existing)
  - Phase 1 / Settings::default{Entities,Locales,Since} (existing — D-15)
  - Phase 1 / LegacyDbService::queryAll (existing)
provides:
  - lameco\kunstmaanmigrator\filter\MigrationFilters (immutable VO, 3 props)
  - lameco\kunstmaanmigrator\filter\FilterFactory (Yii Component, fromCli merge)
  - lameco\kunstmaanmigrator\locale\LocalePreflight (Yii Component, detect + ensure)
  - Plugin::getInstance()->filterFactory (component access)
  - Plugin::getInstance()->localePreflight (component access)
affects:
  - src/Plugin.php (components map expanded from 1 → 3)
tech-stack:
  added: []
  patterns:
    - "PHP 8.3 readonly constructor-promoted properties for immutable VOs"
    - "Yii Component + Plugin::config() registration + Plugin::getInstance()->serviceName access"
    - "@property-read PHPDoc for IDE / static-analysis resolution of magic getters"
key-files:
  created:
    - src/filter/MigrationFilters.php
    - src/filter/FilterFactory.php
    - src/locale/LocalePreflight.php
  modified:
    - src/Plugin.php
decisions:
  - "D-12 honored: MigrationFilters has exactly three readonly properties (entities, locales, since) — no maxPerEntity"
  - "D-10 merge rules implemented in FilterFactory::fromCli: null falls through to Settings, '' clears default, comma-split + trim for entities/locales"
  - "D-17 LOC-02 ensure() returns null on pass or list of unmapped locales on fail; caller is responsible for hard-failing"
  - "LocalePreflight::ensure() scopes the check to filters->locales when explicitly set (operator-scoped run)"
  - "Paste-ready sites: block rendering deferred to ReportBuilder (Plan 03) — LocalePreflight is detection + gate only"
metrics:
  duration_seconds: 179
  duration_human: "2m 59s"
  tasks_completed: 4
  files_created: 3
  files_modified: 1
  completed_date: "2026-04-25T20:20:34Z"
---

# Phase 02 Plan 01: Filter + Locale Primitives Summary

**One-liner:** Cross-cutting MigrationFilters value object (3 readonly props per D-12) + FilterFactory (Settings+CLI merge per D-10) + LocalePreflight (detect + LOC-02 hard-fail gate per D-17), all wired into Plugin::config() so downstream Phase 2-6 plans can resolve them via `Plugin::getInstance()->filterFactory` and `Plugin::getInstance()->localePreflight`.

## What Shipped

### Files Created

| File | Lines | Role |
|------|-------|------|
| `src/filter/MigrationFilters.php` | 30 | Immutable value object — three readonly properties (entities, locales, since). Not a Yii Component; instantiated by FilterFactory. |
| `src/filter/FilterFactory.php` | 44 | Yii Component. `fromCli(?string $entitiesArg, ?string $localesArg, ?string $sinceArg): MigrationFilters` merges CLI string args with Settings defaults per D-10. |
| `src/locale/LocalePreflight.php` | 74 | Yii Component. `detect(): list<string>` queries `kuma_node_translations.lang`. `ensure(MigrationFilters): ?array` returns null on pass or unmapped-locale list per LOC-02. |

### Files Modified

| File | Diff Summary |
|------|-------------|
| `src/Plugin.php` | +5 / -2 lines. (1) Added `use FilterFactory;` and `use LocalePreflight;`. (2) Added `@property-read FilterFactory $filterFactory` and `@property-read LocalePreflight $localePreflight` to class docblock. (3) Components map expanded from 1 (legacyDbService) to 3 (legacyDbService, filterFactory, localePreflight); D-15 single-component comment removed. `init()`, `createSettingsModel()`, `settingsHtml()` untouched. |

## Commits

| Hash | Task | Message |
|------|------|---------|
| `dc50088` | 1 | feat(02-01): add MigrationFilters value object |
| `8fa4bcc` | 2 | feat(02-01): add FilterFactory service for Settings+CLI merge |
| `ac78230` | 3 | feat(02-01): add LocalePreflight service for LOC-01 detect + LOC-02 gate |
| `eb06930` | 4 | feat(02-01): register filterFactory + localePreflight in Plugin::config() |

## Decisions Honored

- **D-12 (max-per-entity dropped)**: `MigrationFilters` has exactly three readonly properties — `entities`, `locales`, `since`. `grep -c maxPerEntity src/filter/MigrationFilters.php` returns 0.
- **D-10 (CLI override merge)**: `FilterFactory::fromCli` implements the three-state merge per filter:
  - null CLI arg → fall through to `Settings::default*`
  - empty-string CLI arg → clear the default (operator wants no filter on this dimension)
  - non-empty string → comma-split + trim (entities / locales) or use as-is (since)
  Each filter is independent — `--entities` does not touch `--locales`.
- **D-17 (LOC-02 hard-fail)**: `LocalePreflight::ensure()` returns null on pass, or the list of unmapped locale codes on fail. Caller is responsible for the hard fail; this service does not print, does not throw — it returns a verdict.
- **D-13 (filters apply uniformly)**: FilterFactory ships now so AnalyzeController + MapController (later plans in Phase 2) can build the VO once per CLI invocation and thread it through every service call. The VO is constructor-only (no setters), so it cannot be mutated mid-pipeline.

## Design Notes

- **LocalePreflight does NOT render the paste-ready `sites:` block.** That suggestion-block rendering is deferred to `ReportBuilder` in Plan 03. LocalePreflight is detection + gate verdict only — keeping it focused makes it cheap to call from every legacy-reading controller without dragging markdown rendering into the gate path.
- **`MigrationFilters` is NOT a Yii Component.** It's an immutable VO. FilterFactory instantiates it; consumers receive it as a method/constructor argument. Listed in `@property-read` only for `filterFactory` and `localePreflight`, never for `migrationFilters`.
- **`array_values()` wraps every comma-split** so the resulting `entities` / `locales` lists are always 0-indexed (matches the VO's `@var list<string>` annotation).
- **`(array)` cast on Settings::defaultEntities / defaultLocales** is defensive: Settings declares them as `array` but config-file overrides could theoretically arrive as something else. The cast is a no-op for arrays.

## Verification

- `php -l` clean on all 4 files (3 new + 1 modified).
- `composer test` exits 0: 7 tests, 11 assertions (Phase 1 PluginBootstrapTest — 3 tests — still green; the literal `'legacyDbService' => LegacyDbService::class` substring is preserved in the expanded components map, so the source-level reflection assertion in `testPluginDeclaresLegacyDbServiceComponent` passes unchanged).
- Components map declares exactly 3 services: legacyDbService (Phase 1), filterFactory (Phase 2 Plan 01), localePreflight (Phase 2 Plan 01).
- `MigrationFilters` has exactly three readonly properties; no `maxPerEntity` reference in the file.
- `LocalePreflight::ensure(MigrationFilters $filters): ?array` signature matches the planned shape (returns `?array`, nullable union for null = pass).

## Deviations from Plan

None — plan executed exactly as written. The action text was mechanical and no auto-fixes (Rules 1-3) were triggered. No architectural decisions (Rule 4) needed.

## Authentication Gates

None — all work was code-only, no external services were called.

## Threat Surface Scan

No new threat surface beyond the plan's `<threat_model>`:
- T-2-01 (CLI tampering): FilterFactory only splits on commas + trims. No SQL is built from these strings here. Downstream consumers (Phase 3+ extract) MUST use parameterized queries — Phase 1 LegacyDbService::queryAll already does this.
- T-2-02 (locale info disclosure): mitigated. `ensure()` returns plain locale codes (e.g. `'fr'`). Locale codes are not secrets.
- T-2-03 (legacy-DB read elevation): mitigated by upstream — LocalePreflight does NOT enforce NeverProduction. Its callers (AnalyzeController in Plan 03, MapController in Plan 04) gate first via `enforceNeverProduction()` per Phase 1 / D-20. Plan 02-01 ships only the service; controller wiring + gate-first verification land in Plans 03/04.

## Downstream Hooks

The next Phase 2 plans can now wire to:
- `Plugin::getInstance()->filterFactory->fromCli($this->entities, $this->locales, $this->since)` in `AnalyzeController` / `MapController` `actionIndex()` (after the NeverProduction gate, before any DB read).
- `Plugin::getInstance()->localePreflight->ensure($filters)` after the FilterFactory call. On non-null return, render the paste-ready sites: block (via ReportBuilder, Plan 03) and exit `ExitCode::CONFIG`.

## Self-Check: PASSED

**Files exist:**
- src/filter/MigrationFilters.php FOUND
- src/filter/FilterFactory.php FOUND
- src/locale/LocalePreflight.php FOUND
- src/Plugin.php FOUND (modified)

**Commits exist:**
- dc50088 FOUND
- 8fa4bcc FOUND
- ac78230 FOUND
- eb06930 FOUND
