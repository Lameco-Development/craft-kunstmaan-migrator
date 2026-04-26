---
plan: 07
phase: 04
title: "RedirectMigrationService verbatim port (Retour adapter)"
wave: 3
depends_on: ["04-01"]
files_modified:
  - src/load/RedirectMigrationService.php
autonomous: true
requirements_addressed: [ADP-02]
---

# Plan 04-07: RedirectMigrationService verbatim port

## Objective

Port v1's `bridge/load/RedirectMigrationService.php` (692 LOC) verbatim to `src/load/RedirectMigrationService.php`. kuma_redirects → Retour `retour_static_redirects` import + section-move 301 emission for migrated entries. Implements D-56 in-service detection (short-circuits with WARN when Retour absent) and D-57 table-name override seam.

## Context

- D-54: verbatim port + RECONCILIATION discipline.
- D-55: standalone `migrate/retour` sub-action lives in Plan 04-10.
- D-56: optional-plugin double gate inside the service.
- D-57: `Settings::$redirectsTableName` override (default `kuma_redirects`).
- v2 reshape (PATTERNS.md flag #4): v1 hardcodes Craft site handles `'default'` and `'en'` at two call sites — `lookupNewUrlByLegacyUrl` (lines 350-354) and `emitSectionMoveForOne` (lines 461-462). v2 must replace these with iteration over `Plugin::resolveSitesMap()` so this service works on any client whose Craft site handles aren't `default`/`en`.
- Idempotent upsert via `Retour::$plugin->redirects->getRedirectByRedirectSrcUrl($srcUrl, null)` lookup → pass-id-into-config.
- DI wiring lives in Plan 04-09.

## Tasks

<task id="01">
  <action>
Create `src/load/RedirectMigrationService.php`. Copy v1's `~/Sites/craft-kunstmaan-migrator/src/bridge/load/RedirectMigrationService.php` body byte-for-byte. Reshape ONLY:

1. **Namespace** `bridge\load` → `load`.
2. **Imports** — same path corrections as Plan 04-06 for `MigrationStateService`, `MigrationOptions`, `MigrationReport`, `LegacyDbService`, `MigrationFilters`. The Retour SDK import (`use nystudio107\retour\Retour;`) stays unchanged — Retour 5.x package shape confirmed in CONTEXT canonical refs.
3. **`$sites` source** — public `array $sites = []` property; Plan 04-09 wires it from `Plugin::resolveSitesMap()` in `Plugin::init()`.
4. **REPLACE hardcoded `'default'` / `'en'` site handles** at the two call sites flagged by PATTERNS.md:
   - `lookupNewUrlByLegacyUrl` (v1 lines 350-354): instead of `getSiteByHandle('en')` / `getSiteByHandle('default')`, iterate `$this->sites` (the kuma-locale → Craft-handle map). For each Craft handle, attempt the lookup; return the first non-null match. This makes the method work on any client whose handles are not `default`/`en`.
   - `emitSectionMoveForOne` (v1 lines 461-462): instead of `$nlSite = $sites->getSiteByHandle('default'); $enSite = $sites->getSiteByHandle('en');`, walk `$this->sites` to discover all Craft sites participating in the migration. The section-move emission must run for every kuma-locale's resolved Craft site, not just the two CQM hardcoded handles.

   Add a `// v2 reshape: iterate $this->sites instead of hardcoded 'default'/'en' (PATTERNS flag #4)` comment at each replaced site so the deviation from v1 is traceable.

Preserve verbatim:
- The optional-plugin double gate (v1 lines 96-118):
  ```php
  if (Craft::$app->plugins->getPlugin('retour') === null) {
      Craft::warning('Retour plugin not installed; skipping redirect migration pass.', 'kunstmaanmigrator');
      $report->warn('Retour plugin not installed; redirect migration skipped.', ['retour_loaded' => false]);
      return $report;
  }
  if (!class_exists(Retour::class) || Retour::$plugin === null) {
      $report->incr('failed');
      $report->warn('Retour plugin not loaded (class/plugin null); redirect migration aborted.', ['retour_loaded' => false]);
      return $report;
  }
  ```
- The idempotent upsert pattern (v1 lines 615-637): `getRedirectByRedirectSrcUrl` lookup → pass `$config['id']` into `saveRedirect($config, false)`. The `false` second arg disables `checkForRedirectLoop` — operator audits manually.
- The full config-array shape (`redirectSrcMatch = 'pathonly'`, `redirectMatchType = 'exactmatch'`, `siteId = null`, `hitCount = 0`, `enabled = true`).
- `STATE_SOURCE = 'redirect'` constant + `truncate()` method that walks state rows.
- `SECTION_MOVE_SOURCES = ['team', 'news', 'cases']` constant.
- `normalisePath()` helper.
- `$redirectsTableName = 'kuma_redirects'` default (D-57 seam).

Body is 692 LOC; do not rewrite outside the two flagged hardcoded-handle sites.
  </action>
  <read_first>
    - ~/Sites/craft-kunstmaan-migrator/src/bridge/load/RedirectMigrationService.php (entire file — verbatim source; pay special attention to lines 350-354 and 461-462 hardcoded handles)
    - src/load/MigrationStateService.php (Phase 3 — confirm v2 namespace)
    - src/load/MigrationOptions.php (Phase 3 — confirm v2 import)
    - src/db/LegacyDbService.php (Phase 1 — confirm v2 namespace)
    - src/Plugin.php (lines 280-304 — `resolveSitesMap()` shape)
    - .planning/phases/04-adapters-verify-settings/04-PATTERNS.md (RedirectMigrationService section, advisor flag #4 — exact replacement guidance)
    - .planning/phases/04-adapters-verify-settings/04-CONTEXT.md (D-54, D-55, D-56, D-57)
  </read_first>
  <acceptance_criteria>
    - `test -f src/load/RedirectMigrationService.php` returns true
    - `grep -c '^namespace lameco\\\\kunstmaanmigrator\\\\load;' src/load/RedirectMigrationService.php` returns `1`
    - `grep -c 'class RedirectMigrationService extends Component' src/load/RedirectMigrationService.php` returns `1`
    - `grep -c 'use nystudio107.retour.Retour' src/load/RedirectMigrationService.php` returns `1` (Retour SDK import preserved)
    - `grep -E "getPlugin\('retour'\) === null" src/load/RedirectMigrationService.php` returns at least `1` (D-56 primary gate)
    - `grep -c 'class_exists(Retour::class)' src/load/RedirectMigrationService.php` returns at least `1` (D-56 secondary defensive gate)
    - `grep -c "STATE_SOURCE = 'redirect'" src/load/RedirectMigrationService.php` returns `1`
    - `grep -c "SECTION_MOVE_SOURCES" src/load/RedirectMigrationService.php` returns at least `1`
    - `grep -c "public string \$redirectsTableName = 'kuma_redirects'" src/load/RedirectMigrationService.php` returns `1` (D-57 default)
    - `grep -c 'public array \$sites' src/load/RedirectMigrationService.php` returns `1`
    - `grep -c 'getRedirectByRedirectSrcUrl' src/load/RedirectMigrationService.php` returns at least `1` (idempotent upsert preserved)
    - `grep -c "saveRedirect" src/load/RedirectMigrationService.php` returns at least `1`
    - `grep -E "saveRedirect\(\\\$config, false\)" src/load/RedirectMigrationService.php` returns at least `1` (checkForRedirectLoop=false invariant)
    - `grep -E "getSiteByHandle\('default'\)|getSiteByHandle\(\\\"default\\\"\)" src/load/RedirectMigrationService.php` returns `0` (PATTERNS flag #4 — hardcoded `'default'` removed)
    - `grep -E "getSiteByHandle\('en'\)|getSiteByHandle\(\\\"en\\\"\)" src/load/RedirectMigrationService.php` returns `0` (PATTERNS flag #4 — hardcoded `'en'` removed)
    - `grep -c 'PATTERNS flag #4' src/load/RedirectMigrationService.php` returns at least `2` (traceability comments at both replaced sites)
    - `grep -c "\$this->sites" src/load/RedirectMigrationService.php` returns at least `2` (sites map iteration replaces hardcoded handles)
    - `grep -c 'normalisePath' src/load/RedirectMigrationService.php` returns at least `1` (helper preserved)
    - `grep -c 'bridge.load' src/load/RedirectMigrationService.php` returns `0` (no leftover bridge\ namespace)
    - `php -l src/load/RedirectMigrationService.php` outputs `No syntax errors detected`
    - Line count within ±25 of v1's 692 LOC: `[ $(wc -l < src/load/RedirectMigrationService.php) -ge 667 ] && [ $(wc -l < src/load/RedirectMigrationService.php) -le 717 ]`
    - `composer test` exits `0`
  </acceptance_criteria>
</task>

## Verification

- Static load: `php -r 'require "vendor/autoload.php"; new \lameco\kunstmaanmigrator\load\RedirectMigrationService();'` runs without errors.
- Optional-plugin gates verified by grep.
- Runtime exercise deferred to Phase 5 rehearsal (CQM dump with Retour installed → kuma_redirects rows → retour_static_redirects rows; counts match).

## must_haves

- File `src/load/RedirectMigrationService.php` exists with v2 namespace + imports.
- D-56 optional-plugin double gate present (Retour plugin null + class_exists/Retour::$plugin defensive).
- Idempotent upsert via `getRedirectByRedirectSrcUrl` lookup intact.
- `checkForRedirectLoop=false` arg on `saveRedirect` preserved.
- v2 reshape: `getSiteByHandle('default')` and `getSiteByHandle('en')` are gone — replaced by `$this->sites` iteration. Traceability comments mark each replacement site.
- `$redirectsTableName` override seam present (D-57).
- `STATE_SOURCE = 'redirect'` + `SECTION_MOVE_SOURCES` constants intact.
- `composer test` stays green.

## RECONCILIATION

| v1 rule | v2 disposition |
|---|---|
| Namespace `lameco\kunstmaanmigrator\bridge\load` | **reshaped** — flattened to `load`. |
| Imports from `bridge\load\*` and `models\MigrationReport` | **reshaped** — point at v2 Phase 3 locations. |
| Retour SDK import `use nystudio107\retour\Retour;` | **ported** — Retour 5.x package shape unchanged. |
| Optional-plugin double gate (lines 96-118) | **ported** — D-56 idiom byte-for-byte (primary + class_exists defensive). |
| Idempotent upsert via `getRedirectByRedirectSrcUrl` then pass-id-into-config | **ported** — load-bearing per Pitfall 5; Retour's saveRedirect takes the update branch only when `id` is in config. |
| `saveRedirect($config, false)` second arg `checkForRedirectLoop=false` | **ported** — operator audits redirect loops manually. |
| `STATE_SOURCE = 'redirect'` + `truncate()` method walking state rows | **ported** — required for `migrate/truncate` parity. |
| `SECTION_MOVE_SOURCES = ['team', 'news', 'cases']` constant | **ported** — v1 default; CQM-specific but configurable upstream if needed. |
| Hardcoded `getSiteByHandle('en')` / `getSiteByHandle('default')` (lines 350-354 + 461-462) | **dropped accidentally → patched (D-54)** — replaced with `$this->sites` iteration. v1 deviation: this would have failed silently on any client whose Craft site handles aren't literally `default`+`en`. v2 fix preserves the section-move + lookupNewUrlByLegacyUrl behavior on arbitrary site-handle maps. PATTERNS.md flag #4 documents the rationale. |
| `$sites` sourced from mapping.yaml | **reshaped** — wired from `Plugin::resolveSitesMap()` (same source as SeoMigrationService::$sites). |
| `$redirectsTableName = 'kuma_redirects'` setComponents override | **ported + reshaped** — default kept; override now via `Settings::$redirectsTableName` in Plugin::init (Plan 04-09). |
