---
phase: 04-adapters-verify-settings
plan: 07
subsystem: load
tags: [retour, redirects, optional-plugin, verbatim-port, kunstmaan, section-move]

# Dependency graph
requires:
  - phase: 03-extract-transform-load
    provides: MigrationStateService, MigrationOptions, MigrationReport, LegacyDbService, atomic upsert primitives
  - phase: 04-adapters-verify-settings
    provides: Plan 04-01 Settings::$redirectsTableName override seam (D-57)
provides:
  - src/load/RedirectMigrationService.php (683 LOC) — Retour adapter for kuma_redirects + section-move 301 emission
  - D-56 optional-plugin double gate (primary getPlugin('retour') === null + secondary class_exists/Retour::$plugin defensive)
  - Idempotent Retour upsert via getRedirectByRedirectSrcUrl → pass-id-into-config (Pitfall 5 avoidance)
  - STATE_SOURCE='redirect' state-table convention for migrate/truncate parity
  - Section-move 301 emission for SECTION_MOVE_SOURCES = ['team', 'news', 'cases']
  - $redirectsTableName='kuma_redirects' default + Settings override seam (D-57)
affects:
  - 04-09-verify-controller-and-plugin-wiring (DI registration)
  - 04-10-migrate-controller-extensions (migrate/retour sub-action — D-55)
  - 04-12-tests-and-reconciliation (Phase 4 test corpus)
  - Phase 5 rehearsal (CQM dump runtime exercise)

# Tech tracking
tech-stack:
  added: []
  patterns:
    - D-54 verbatim-port discipline with explicit RECONCILIATION rule-by-rule disposition
    - D-56 optional-plugin double gate at every entry point (migrateAll + truncate)
    - Idempotent Retour saveRedirect via getRedirectByRedirectSrcUrl lookup → $config['id'] pass-through
    - checkForRedirectLoop=false invariant (operators audit loops manually)
    - PATTERNS.md flag #4 hardcoded-handle replacement via $this->sites iteration
    - v2 MigrationFilters {entities, locales, since} contract enforcement (D-09..D-13)

key-files:
  created:
    - src/load/RedirectMigrationService.php
  modified: []

key-decisions:
  - "v1 MigrationFilters properties (includeDrafts/includeDeleted/includeOffline/cutoffAfter/cutoffBefore) are dropped per D-09..D-13. Defaults hardcoded: published-version JOIN (public_node_version_id), n.deleted = 0, (nt_nl.online = 1 OR nt_en.online = 1). Single since floor on nt_nl.created (CQM is NL-primary per Phase 5 D-03)."
  - "Both v1 hardcoded site-handle sites (lookupNewUrlByLegacyUrl @ v1 lines 350-354 + emitSectionMoveForOne @ v1 lines 461-462) replaced with $this->sites iteration. lookupNewUrlByLegacyUrl prefers the kuma-lang-prefix site if known then falls back to walking all configured handles; emitSectionMoveForOne walks every kuma-locale → Craft-handle pair and emits a redirect for each (oldPath, newPath) tuple where they differ."
  - "$report->warn() context-array second arg dropped (v2 MigrationReport::warn() is string-only per Plan 03-12 signature). Same forced reshape Plan 04-06 hit on SeoMigrationService."
  - "v1's `includeOffline` toggle is REPLACED by always-on `(nt_nl.online = 1 OR nt_en.online = 1)` predicate — matches v2 default behavior already established in SeoMigrationService::resolveLegacyRefByStateRow (line 566). Operators wanting offline-inclusive lookups would need to extend MigrationFilters; out of v1.0 scope per D-12."
  - "DI wiring deferred to Plan 04-09 — public DI surface ($legacyDb, $stateService, $filters, $sites, $redirectsTableName) preserved for setComponents()."

patterns-established:
  - "v2 reshape comment idiom: `// v2 reshape: iterate $this->sites instead of hardcoded 'default'/'en' (PATTERNS flag #4)` traceability comment at every replacement site"
  - "v2 filter-contract reshape comment idiom: `// v2 reshape: MigrationFilters is {entities, locales, since} only — v1's includeDrafts/.../cutoffBefore are dropped per D-09..D-13. Defaults hardcoded.`"

requirements-completed: [ADP-02]

# Metrics
duration: 4m 32s
completed: 2026-04-26
---

# Phase 4 Plan 07: RedirectMigrationService verbatim port (Retour adapter) Summary

**Retour adapter for kuma_redirects → retour_static_redirects import + section-move 301 emission, with D-56 optional-plugin double gate and v2 MigrationFilters contract reshape (683 LOC).**

## Performance

- **Duration:** 4m 32s
- **Started:** 2026-04-26T18:58:12Z
- **Completed:** 2026-04-26T19:02:44Z
- **Tasks:** 1
- **Files modified:** 1 (created)

## Accomplishments

- `src/load/RedirectMigrationService.php` (683 LOC) lands as v2 flat-layout port of v1's `bridge/load/RedirectMigrationService.php` (692 LOC). Two redirect sources handled:
  1. Direct `kuma_redirects` import → `Retour::$plugin->redirects->saveRedirect()` with permanent flag mapping to 301/302; legacy targets re-resolved through state map to avoid bouncing through defunct legacy URLs.
  2. Section-move 301s for migrated team/news/cases entries whose new URI differs from legacy `kuma_node_translations.url`.
- D-56 optional-plugin gate at every entry point: `migrateAll()` short-circuits with WARN when `Craft::$app->plugins->getPlugin('retour') === null`; `truncate()` returns `0` silently. Secondary defensive `class_exists(Retour::class) || Retour::$plugin === null` guard catches mid-request uninstall.
- Idempotent upsert via `getRedirectByRedirectSrcUrl($srcUrl, null)` lookup → `$config['id']` pass-through, then `saveRedirect($config, false)` (`checkForRedirectLoop=false` — operators audit loops manually). Pitfall 5 avoidance preserved verbatim from v1.
- D-57 `$redirectsTableName='kuma_redirects'` default + Settings override seam. Plan 04-09 wiring will populate from `Settings::$redirectsTableName`.
- Public DI surface preserved for Plan 04-09: `$legacyDb`, `$stateService`, `$filters`, `$sites`, `$redirectsTableName`.
- `composer test` 60/137 baseline preserved (no test additions per plan — Plan 04-12 owns Phase 4 test corpus).

## Task Commits

1. **Task 1: Port RedirectMigrationService** — `401a3fb` (feat)

## Files Created/Modified

- `src/load/RedirectMigrationService.php` — created (683 LOC). Retour adapter with kuma_redirects direct import + section-move 301 emission. D-56 double gate, D-57 table override seam, idempotent Retour upsert, STATE_SOURCE='redirect' + truncate() walking state rows.

## Decisions Made

- **v2 MigrationFilters contract enforcement (D-09..D-13).** v1 source uses `includeDrafts` (line 289), `includeDeleted` (304), `includeOffline` (307), `cutoffAfter` (310), `cutoffBefore` (314) inside `lookupNewUrlByLegacyUrl`, and reproduces the same scaffolding inside `kumaNodeIdForEmployee` (lines 532, 547, 550, 553, 557). v2 MigrationFilters has only `entities`, `locales`, `since` — every v1 filter property had to be reshaped per the orchestrator's critical filter contract. Same pattern Plan 04-06 had to apply to `SeoMigrationService::resolveLegacyRefByStateRow`. Hardcoded defaults: `$versionCol = 'public_node_version_id'`, `n.deleted = 0`, `(nt_nl.online = 1 OR nt_en.online = 1)`. Single `since` floor on `nt_nl.created` (CQM is NL-primary per Phase 5 D-03).
- **`$report->warn()` context-array second arg dropped throughout.** v2 `MigrationReport::warn(string $message): void` is string-only (Plan 03-12 signature). Every v1 call site that passed `['retour_loaded' => false]` / `['srcUrl' => ..., 'destUrl' => ...]` / `['legacy_id' => ...]` etc. as the second arg had to be trimmed to single-arg. Lossy reshape — context that was previously machine-readable in the warning log lines is now embedded in the formatted message string only. Same forced reshape Plan 04-06 hit on SeoMigrationService.
- **`$record(...)` named-arg call preserves verbatim shape.** v2 `MigrationStateService::record(source, key, targetType, targetId, targetUid, siteId, meta)` signature lets the v1 `source:`, `key:`, `targetType:`, `targetId:`, `meta:` named-arg call survive byte-for-byte by skipping `targetUid` + `siteId` defaults. No reshape needed.
- **`$this->sites` iteration replacing hardcoded site handles (PATTERNS.md flag #4).** Two replacements with traceability comments:
  - `lookupNewUrlByLegacyUrl` (v1 lines 348-354): if a kuma-lang prefix was extracted from the URL, prefer the corresponding Craft handle from `$this->sites[$lang]`; fall through to walking every configured handle and returning the first non-null match. Replaces v1's `getSiteByHandle('en')` / `getSiteByHandle('default')` pair.
  - `emitSectionMoveForOne` (v1 lines 460-465): walk `$this->sites as $kumaLang => $craftHandle`, fetch `$legacyUrls[$kumaLang]`, resolve site via `getSiteByHandle($craftHandle)`, emit redirect for each (oldPath, newPath) tuple where they differ. Replaces v1's hardcoded `$nlSite` / `$enSite` pair. Section-move now correctly fires for any client whose Craft site handles aren't literally `default`+`en`.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] v2 MigrationFilters contract reshape inside two private query methods**

- **Found during:** Task 1 (during the verbatim-port walk through v1 lines 280-360 + 530-580).
- **Issue:** Plan body said "verbatim except namespace/imports/$sites/hardcoded handles" but the v1 source accesses `$this->filters->includeDrafts` / `->includeDeleted` / `->includeOffline` / `->cutoffAfter` / `->cutoffBefore` inside `lookupNewUrlByLegacyUrl` (5 hits) and `kumaNodeIdForEmployee` (5 hits). v2 `MigrationFilters` is `{entities, locales, since}` only — accessing the missing properties would have hit a fatal at first method call. Orchestrator's `<critical_v2_filter_contract>` block flagged the same trap as Plan 04-06 and provided the patch pattern.
- **Fix:** Followed the same pattern Plan 04-06 already established in `SeoMigrationService::resolveLegacyRefByStateRow` (lines 557-578): hardcode `$versionCol = 'public_node_version_id'` (no `includeDrafts` toggle); hardcode `n.deleted = 0` (no `includeDeleted` toggle); hardcode `(nt_nl.online = 1 OR nt_en.online = 1)` (no `includeOffline` toggle); replace `cutoffAfter` + `cutoffBefore` branches with single `since` floor on `nt_nl.created`. Both `lookupNewUrlByLegacyUrl` and `kumaNodeIdForEmployee` got the same reshape with `// v2 reshape: MigrationFilters is {entities, locales, since} only ...` traceability comment.
- **Files modified:** `src/load/RedirectMigrationService.php`
- **Verification:** Orchestrator success criterion grep — `grep -E 'includeDeleted|includeOffline|includeDrafts|cutoffAfter|cutoffBefore' src/load/RedirectMigrationService.php` returns matches only inside the three reshape-comment blocks (no executable code references). `php -l` clean. composer test exits 0 (60/137 baseline preserved).
- **Committed in:** `401a3fb` (Task 1 commit)

**2. [Rule 3 - Blocking] `$report->warn()` second-arg context array dropped**

- **Found during:** Task 1 (during the verbatim-port walk).
- **Issue:** v1 source calls `$report->warn(string, array $context)` at 8+ sites (e.g. line 102 `['retour_loaded' => false]`, line 197 `['legacy_id' => ...]`, line 408 `['source' => ..., 'sourceKey' => ...]`, etc.). v2 `MigrationReport::warn(string $message): void` is string-only — passing a second arg is a fatal `ArgumentCountError`. Plan 04-06 had documented this exact reshape on SeoMigrationService.
- **Fix:** Trimmed every `$report->warn(...)` call to single-arg form. Context that was previously machine-readable in log lines is now embedded in the `sprintf()` message string only (which is where the v1 sites already put the human-readable rendition).
- **Files modified:** `src/load/RedirectMigrationService.php`
- **Verification:** `php -l` clean; composer test passes; `grep -c 'warn(' src/load/RedirectMigrationService.php` = 7 (every call site trimmed).
- **Committed in:** `401a3fb` (Task 1 commit)

---

**Total deviations:** 2 auto-fixed (both Rule 3 blocking — v2 surface deltas vs v1 source).
**Impact on plan:** Both reshapes were unavoidable to compile; both follow the precedent Plan 04-06 already set on SeoMigrationService; both are documented inline with traceability comments. No scope creep.

## Issues Encountered

None — the orchestrator's `<critical_v2_filter_contract>` block surfaced the filter-contract trap up front, so the reshape was applied in the initial write rather than discovered after a failing self-check (which is what happened on Plan 04-06).

## Acceptance Criteria Check

All 24 plan acceptance grep checks green:

| Criterion | Result |
|---|---|
| `test -f src/load/RedirectMigrationService.php` | OK |
| `^namespace lameco\kunstmaanmigrator\load;` | 1 |
| `class RedirectMigrationService extends Component` | 1 |
| `use nystudio107.retour.Retour` | 1 |
| `getPlugin('retour') === null` | 2 (≥1) |
| `class_exists(Retour::class)` | 2 (≥1) |
| `STATE_SOURCE = 'redirect'` | 1 |
| `SECTION_MOVE_SOURCES` | 2 (≥1) |
| `public string $redirectsTableName = 'kuma_redirects'` | 1 |
| `public array $sites` | 1 |
| `getRedirectByRedirectSrcUrl` | 3 (≥1) |
| `saveRedirect` | 8 (≥1) |
| `saveRedirect($config, false)` | 1 (≥1) |
| `getSiteByHandle('default')` / `("default")` | 0 |
| `getSiteByHandle('en')` / `("en")` | 0 |
| `PATTERNS flag #4` | 2 (≥2) |
| `$this->sites` | 7 (≥2) |
| `normalisePath` | 3 (≥1) |
| `bridge.load` (literal `bridge\` namespace residue) | 0 |
| `php -l` clean | OK |
| Line count 667-717 | 683 |
| `composer test` exits 0 | OK |

Orchestrator additional success criterion:
- `grep -E 'includeDeleted\|includeOffline\|includeDrafts\|cutoffAfter\|cutoffBefore' src/load/RedirectMigrationService.php` returns hits only inside reshape comments (3 comment blocks of 2 lines each = 6 lines), zero executable code references — matches the orchestrator allowance ("only in comments if necessary").

## Next Phase Readiness

- ADP-02 complete at the service-class level — Retour adapter ready for Plan 04-09 DI wiring + Plan 04-10 `migrate/retour` sub-action surface.
- Wave 2 advances: 04-08 (CaptureBaselineHtmlService) is the last Wave 2 verbatim-port plan before Wave 3 (Plugin wiring + MigrateController extensions + doctor checks + tests).
- Phase 5 rehearsal will exercise this adapter end-to-end against a CQM dump with Retour installed (kuma_redirects rows → retour_static_redirects rows; counts match; truncate() round-trip).

## Self-Check: PASSED

- File `src/load/RedirectMigrationService.php` exists.
- Commit `401a3fb` on `main`.

---
*Phase: 04-adapters-verify-settings*
*Completed: 2026-04-26*
