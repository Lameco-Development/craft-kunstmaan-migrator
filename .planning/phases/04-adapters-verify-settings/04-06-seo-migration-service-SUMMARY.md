---
phase: 04-adapters-verify-settings
plan: 06
subsystem: adapters
tags: [seomatic, adapter, verbatim-port, D-54, D-55, D-56, D-57, ADP-01]
status: complete
requires:
  - phase: 04
    plan: "01"
    artifact: "src/models/Settings.php — Settings::$seoTableName property (D-57 override seam target). Plan 04-09 reads it and assigns to SeoMigrationService::$seoTableName."
  - phase: 04
    plan: "02"
    artifact: "src/load/SeomaticPayloadBuilder.php — pure-function helper that converts a kuma_seo row into the SEOmatic seo field payload (metaGlobalVars + metaBundleSettings). Wired into SeoMigrationService::$seoPayload."
  - phase: 03
    plan: "02"
    artifact: "src/load/MigrationOptions.php — DTO carrying dryRun + legacyClassFilter consumed by migrateAll/migrateForEntry."
  - phase: 03
    plan: "03"
    artifact: "src/load/MigrationStateService.php — Generator-based all() + record() + getTargetId() used to walk state rows + persist the seo_meta self-row."
  - phase: 03
    plan: "12"
    artifact: "src/load/MigrationReport.php — incr/warn surface (note: v2 warn() takes string only, no context-array second arg — forced reshape during port)."
  - phase: 01
    plan: "02"
    artifact: "src/db/LegacyDbService.php — queryOne() used to inline the kuma_seo SELECT (replaces v1's seoFor() helper which v2 LegacyDbService doesn't expose)."
  - phase: 02
    plan: "01"
    artifact: "src/filter/MigrationFilters.php — includeDrafts / includeDeleted / includeOffline / cutoffAfter / cutoffBefore feed the resolveLegacyRef() WHERE-clause builder."
provides:
  - artifact: "src/load/SeoMigrationService.php (606 lines, byte-for-byte port of v1's bridge/load/SeoMigrationService.php)"
    summary: "Per-locale SEOmatic MetaBundle migration that runs LAST in the in-process pipeline (D-55). D-56 optional-plugin gate at both call sites (migrateAll + migrateForEntry) — short-circuits with WARN when SEOmatic absent. D-57 $seoTableName override seam (default 'kuma_seo') now genuinely flows into SQL via inlined fetchKumaSeoRow() — v1 had this surface declared but its seoFor() ignored it. Public DI properties (\$legacyDb / \$stateService / \$seoPayload / \$filters / \$seoTableName / \$sites) carry the bare-property pattern Plan 04-09 will wire."
affects:
  - "Plan 04-09 (Plugin wiring) — registers `seoMigrationService` in Plugin::config(), wires \$legacyDb/\$stateService/\$seoPayload/\$filters siblings in init(), assigns \$sites from Plugin::resolveSitesMap() (same map already feeding EntryMigrationService::$sites at Plugin.php:260), and applies Settings::\$seoTableName when non-default."
  - "Plan 04-10 (MigrateController extensions) — adds `actionSeo` standalone sub-action calling SeoMigrationService::migrateAll(); also calls migrateForEntry from AtomicMigrationService for in-process per-entry SEO writes once entries+assets exist."
  - "Plan 04-11 (Doctor 7th/8th checks) — DoctorController checkAdapterPlugins reads `Craft::$app->plugins->getPlugin('seomatic')` mirroring the gate inside this service."
  - "Phase 5 / TST-04 rehearsal — runtime exercise (CQM dump with SEOmatic installed → counts match within tolerance) deferred per the plan's Verification section."
tech-stack:
  added: []
  patterns:
    - "D-54 verbatim-port + RECONCILIATION discipline — copy v1 byte-for-byte, reshape only namespace + import paths + documented v2 architectural decisions; never 'improve' during a port."
    - "D-56 in-service optional-plugin gate — `Craft::$app->plugins->getPlugin('seomatic') === null` checked at every public entry point (not at controller). Single seam means consumers can't accidentally bypass the WARN-and-skip behavior. Same idiom will repeat in RedirectMigrationService (Plan 04-07) for Retour."
    - "D-57 table-name override seam — `public string $seoTableName = 'kuma_seo'` on the service, assigned from Settings in Plugin::init (Plan 04-09). v2 reshape: inlined the SELECT in fetchKumaSeoRow() so the override actually reaches SQL (v1's LegacyDbService::seoFor used a hardcoded constant, making the v1 override surface decorative)."
    - "PATTERNS.md flag #3 — `$sites` source. v2 wires from `Plugin::resolveSitesMap()` (same map feeding EntryMigrationService::$sites), NOT from a mapping.yaml `sites:` block. v1's mapping.yaml `sites:` block is gone in v2; the kuma-locale → Craft-site-handle map composes from LocalePreflight + Settings::\$localeMap (D-28 ladder) at init() time."
    - "Bare-public-property DI surface — service declares public typed properties (\$legacyDb, \$stateService, \$seoPayload, \$filters, \$seoTableName, \$sites) with no constructor; Plugin::init() does sibling-wiring after `parent::init()` (matching the Phase 02.1 / commit 75a95bc pattern at Plugin.php:185-260). Test bootstrap can instantiate the bare class without a Craft app."
    - "`saveElement($entry, true, false)` propagateChanges=false invariant — RESEARCH.md §2 / Pitfall 2. The third arg `false` is load-bearing: omitting it lets Craft's element propagation overwrite per-site SEO with the primary site's payload."
    - "Per-site `setCurrentSite` / `previousSite` swap inside try/finally — required because SEOmatic's SeoSettings field normalizeValue pulls metaSiteVars defaults (siteName, identity, creator, referrer) from getCurrentSite(), not from the Entry's siteId. Without the swap, every per-site SEO save bakes the primary site's identity into all locales."
    - "STATE_SOURCE = 'seo_meta' self-exclusion — the migrateAll() source-distinct query excludes its own state-source via `andWhere(['not in', 'source', [self::STATE_SOURCE]])`. Without this, a re-run would re-process its own seo_meta rows with meta=null (no refIdsByLocale) and overwrite the just-written per-locale SEO with empty payloads. Load-bearing for D-55 'SEO runs last' ordering."
key-files:
  created:
    - "src/load/SeoMigrationService.php (606 lines) — verbatim port of v1's bridge/load/SeoMigrationService.php (600 lines)."
  modified: []
key-decisions:
  - "D-54 verbatim port + named reshape list. Body copied byte-for-byte from v1; reshapes confined to namespace, imports, $sites source comment, and the two API-mismatch fixes documented under Deviations."
  - "D-55 last-stage ordering preserved — STATE_SOURCE='seo_meta' self-exclusion + state-driven source discovery via the kunstmaanmigrator_state table both intact. Together they guarantee the service walks only EntryMigrationService-written rows and never re-processes its own writes."
  - "D-56 optional-plugin gate ported at both call sites (migrateAll + migrateForEntry) byte-for-byte: same Craft::warning + $report->warn idiom both places. WARN-and-skip, never hard-error — consumers without SEOmatic see the skip in logs + REPORT.md (D-68 'Skipped stages' section)."
  - "D-57 override seam inlined into fetchKumaSeoRow() so $seoTableName genuinely flows into SQL. v1 had a latent bug — declared the override but seoFor() used a hardcoded KunstmaanCoreTables::SEO constant; the override never took effect. v2 inline restores truthfulness to the docblock 'passed verbatim into raw SQL'."
  - "$sites source reshape — bare public property, no mapping.yaml read. Plan 04-09 will wire from Plugin::resolveSitesMap() (the same map feeding EntryMigrationService::$sites at Plugin.php:260). v1's mapping.yaml `sites:` top-level block is gone in v2 (PROJECT.md Out-of-Scope: 'Multiple mapping files' rule + LocalePreflight covers locale resolution)."
  - "v2 reshape — $report->warn() context-array second arg dropped (4 call sites). v2 MigrationReport::warn signature is `warn(string $message): void` — the v1 surface accepted (string, array $context) and stored both. v2 simplification was intentional per Plan 03-12. The warning messages themselves are preserved verbatim; only the structured-context payloads disappear (messages already inline source/sourceKey/entryId via sprintf, so log-readability is unchanged)."
patterns-established:
  - "Optional-adapter service shape — verbatim port + in-service plugin gate + bare-property DI. Will repeat in RedirectMigrationService (Plan 04-07) for Retour with the same pattern."
  - "Override-seam-flowing-into-SQL — when v1 declares an override surface that doesn't actually reach SQL, the v2 port inlines the SELECT so the override genuinely takes effect. Document the v1 latent bug in the v2 method's docblock."
requirements-completed: [ADP-01]
metrics:
  duration: "~25 min"
  completed: "2026-04-26"
  tasks-completed: "1/1"
  total-loc-added: "606"
  test-suite: "60 tests / 137 assertions / 0 failures (unchanged baseline; runtime exercise deferred to Phase 5 / TST-04 per the plan's Verification section)"
---

# Phase 4 Plan 06: SeoMigrationService verbatim port Summary

**`src/load/SeoMigrationService.php` ports v1's `bridge/load/SeoMigrationService.php` (600 LOC) byte-for-byte to v2's flattened layout. D-56 optional-plugin gate at both call sites (migrateAll + migrateForEntry); D-55 STATE_SOURCE='seo_meta' self-exclusion + state-driven source discovery preserved; D-57 `$seoTableName` override seam now genuinely flows into SQL (v1 had this declared but its `LegacyDbService::seoFor()` used a hardcoded constant — v2 inlines the SELECT in `fetchKumaSeoRow()` so the override takes effect). Public DI surface (`$legacyDb` / `$stateService` / `$seoPayload` / `$filters` / `$seoTableName` / `$sites`) preserved for Plan 04-09 wiring. Two forced API-mismatch reshapes documented under Deviations: `$report->warn()` lost its context-array second arg (v2 signature is string-only) and `LegacyDbService::seoFor()` was inlined as a `queryOne()` call. composer test 60/137 baseline preserved.**

## Status

**COMPLETE.** Single task executed and committed (`593870d`). composer test green (60 tests / 137 assertions — unchanged baseline). Two forced reshapes (Rule 3 — Blocking) and one bug-fix-during-port (Rule 1 — latent v1 bug) documented below.

## Performance

- **Duration:** ~25 min
- **Started:** 2026-04-26T18:25:00Z (approx)
- **Completed:** 2026-04-26T18:50:00Z (approx)
- **Tasks:** 1/1
- **Files created:** 1 (`src/load/SeoMigrationService.php`)
- **Files modified:** 0

## Tasks Completed

| Task | Name                                                       | Commit    | Files                                  |
| ---- | ---------------------------------------------------------- | --------- | -------------------------------------- |
| 1    | Verbatim port of bridge/load/SeoMigrationService.php       | `593870d` | `src/load/SeoMigrationService.php`     |

## Acceptance Criteria — Verified

All 17 acceptance criteria from the plan task pass:

| Check                                                              | Expected | Actual |
| ------------------------------------------------------------------ | -------- | ------ |
| `test -f src/load/SeoMigrationService.php`                         | true     | true   |
| `^namespace lameco\kunstmaanmigrator\load;`                        | 1        | 1      |
| `class SeoMigrationService extends Component`                      | 1        | 1      |
| `getPlugin('seomatic') === null` (D-56 gate count)                 | >= 2     | 2      |
| `STATE_SOURCE = 'seo_meta'`                                        | 1        | 1      |
| `saveElement($entry, true, false)` (propagate=false invariant)     | >= 1     | 1      |
| `public string $seoTableName = 'kuma_seo'` (D-57 default)          | 1        | 1      |
| `public array $sites` (PATTERNS flag #3)                           | 1        | 1      |
| `public LegacyDbService $legacyDb`                                 | 1        | 1      |
| `public MigrationStateService $stateService`                       | 1        | 1      |
| `public SeomaticPayloadBuilder $seoPayload`                        | 1        | 1      |
| `?MigrationFilters $filters`                                       | >= 1     | 1      |
| `kuma_media:` (state-lookup key for media resolution)              | >= 1     | 1      |
| `kunstmaanmigrator_state` (state table reference)                  | >= 1     | 2      |
| `bridge.load` (no leftover bridge\\ namespace)                     | 0        | 0      |
| `php -l src/load/SeoMigrationService.php`                          | clean    | clean  |
| Line count within 580–620 (v1 = 600 LOC)                           | 580–620  | 606    |
| `composer test` exit code                                          | 0        | 0      |

The `kuma_media:` match comes from the v1 docblock at lines 22-23 (`'media:kuma_media:<id>'` — explanation of how kuma_seo image refs resolve via state lookup). Preserved verbatim. The two `kunstmaanmigrator_state` matches come from the migrateAll() source-distinct query (line 167) and the migrateForEntry() source-distinct query (line 287) — both intact per the plan's reconciliation row.

## Files Created

- `src/load/SeoMigrationService.php` (606 lines) — Verbatim port. Body byte-for-byte from v1's `~/Sites/craft-kunstmaan-migrator/src/bridge/load/SeoMigrationService.php`; reshapes confined to namespace + imports + 3 documented API/architecture deltas (see Deviations).

## Decisions Made

- **D-54 verbatim port** — copy v1 byte-for-byte, reshape only namespace + imports + documented architectural deltas. The 6-LOC drift from v1 (606 vs 600) traces to the `MigrationReport` import line moving from `models\` to `load\` and the comment update on `$sites` (replaced the v1 NL/EN-fallback paragraph with the v2 `resolveSitesMap()` paragraph). Body itself unchanged.
- **D-56 gate at both call sites** — same `Craft::warning` + `$report->warn` idiom in both `migrateAll` and `migrateForEntry`. Single-seam discipline means a future caller can't accidentally bypass the WARN-and-skip behavior by routing through one entry point but not the other.
- **D-57 inlined-SELECT decision** — v1's `LegacyDbService::seoFor()` doesn't exist in v2. Two paths: (a) inline in `fetchKumaSeoRow()` using `$this->seoTableName`; (b) add `seoFor()` to v2 `LegacyDbService`. Picked (a) — it makes the D-57 override genuinely flow into SQL (which v1 declared but didn't deliver), keeps the override-seam responsibility on the service that owns the property, and avoids growing LegacyDbService for a single caller. Documented the v1 latent bug in the method's v2 docblock.
- **`$sites` bare public property** — Plan 04-09 wires from `Plugin::resolveSitesMap()`. v1's mapping.yaml `sites:` top-level block is gone in v2 (PROJECT.md Out-of-Scope: "Multiple mapping files"); LocalePreflight + Settings::$localeMap (D-28 ladder) cover locale resolution. The v1 buildSiteList() body is unchanged — it just consumes whatever map is in `$this->sites` regardless of source.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 — Blocking] `$report->warn()` context-array second arg dropped at 4 call sites**

- **Found during:** Task 1 (verbatim port — discovered while diffing v1 against v2 surfaces)
- **Issue:** v1 `MigrationReport::warn(string $message, array $context = [])` carried a structured-context payload as a second arg. v2 `MigrationReport::warn(string $message): void` (Plan 03-12 simplification) accepts a single string. A literal verbatim port wouldn't compile against v2.
- **Fix:** Stripped the second arg from all 4 internal call sites:
  - `migrateAll()` per-row exception handler (line 213 in v1) — context was `['source' => ..., 'sourceKey' => ..., 'entryId' => ...]`; sprintf message already inlines all three values.
  - `migrateForEntryInternal()` unresolved-legacy-ref warning (line 339 in v1) — same pattern; context was `['source', 'sourceKey', 'entryId']`; message inlines them.
  - `migrateForEntryInternal()` save-failed warning (line 466 in v1) — context was `['entryId', 'siteId']`; message inlines them.
  - The unconditional WARN strings at v1 lines 131 / 139 / 270 were already single-arg in v1 (no context payload), preserved verbatim.
- **Files modified:** `src/load/SeoMigrationService.php`
- **Verification:** `php -l` clean; sprintf messages still carry source/sourceKey/entryId/siteId so log-readability is unchanged.
- **Committed in:** `593870d` (Task 1 commit)

**2. [Rule 3 — Blocking] `$this->legacyDb->seoFor()` inlined as `queryOne()` call in `fetchKumaSeoRow()`**

- **Found during:** Task 1 (verbatim port — discovered while diffing v1 against v2 LegacyDbService)
- **Issue:** v1 `LegacyDbService::seoFor(int $refId, string $class): ?array` doesn't exist in v2. v2's LegacyDbService deliberately keeps the surface narrow (only `queryOne` + `queryAll` + connection plumbing). A literal verbatim port wouldn't compile.
- **Fix:** Inlined the SELECT in `fetchKumaSeoRow()` using `$this->seoTableName` and `$this->legacyDb->queryOne()`:
  ```php
  return $this->legacyDb->queryOne(
      'SELECT * FROM ' . $this->seoTableName
      . ' WHERE ref_id = :rid AND ref_entity_name = :class'
      . ' ORDER BY id DESC LIMIT 1',
      [':rid' => $legacyEntityId, ':class' => $legacyClass],
  );
  ```
- **Files modified:** `src/load/SeoMigrationService.php`
- **Verification:** `php -l` clean. Same query shape v1 had (`SELECT * FROM kuma_seo WHERE ref_id = :rid AND ref_entity_name = :class ORDER BY id DESC LIMIT 1`). 4 LOC delta inside fetchKumaSeoRow() — kept inside the ±20 LOC tolerance.
- **Committed in:** `593870d` (Task 1 commit)

**3. [Rule 1 — Bug] `$seoTableName` override seam now actually flows into SQL (latent v1 bug)**

- **Found during:** Task 1 (while resolving Deviation 2 above)
- **Issue:** v1 declared `public string $seoTableName = 'kuma_seo'` on `SeoMigrationService` as the D-57 override seam, with a docblock claiming "passed verbatim into raw SQL". But the actual SQL was issued by `LegacyDbService::seoFor()`, which used a hardcoded `KunstmaanCoreTables::SEO` constant — meaning the v1 override surface was decorative. Setting `$seoMigrationService->seoTableName = 'custom_seo'` in v1 would have changed nothing.
- **Fix:** The v2 inlined query (Deviation 2) uses `'SELECT * FROM ' . $this->seoTableName . ' WHERE ...'` — making the override genuinely take effect. The v1 docblock at the property is now truthful in v2. v2 docblock on `fetchKumaSeoRow()` documents the change explicitly.
- **Files modified:** `src/load/SeoMigrationService.php`
- **Verification:** acceptance criterion `public string $seoTableName = 'kuma_seo'` still grep-matches (1 occurrence) — default value unchanged. Plan 04-09 wires `Settings::$seoTableName` into this property; that operator-facing override now reaches SQL.
- **Committed in:** `593870d` (Task 1 commit)

---

**Total deviations:** 3 auto-fixed (2 blocking — forced API reshape; 1 latent v1 bug fix paired with the reshape).
**Impact on plan:** All three were forced or made the v1 surface honest. No scope creep. The plan's `must_haves` (D-56 gate, STATE_SOURCE constant, propagate=false invariant, $sites bare property, $seoTableName seam, composer test green) all hold.

## Issues Encountered

None — the v1 source compiled cleanly into the v2 layout once the documented API reshapes were applied. Advisor consultation up-front (before writing) confirmed the reshape strategy and avoided second-guessing during execution.

## CFG-08 / D-56 — Confirmed at both call sites

The optional-plugin gate idiom is intentional duplication, not DRY violation:

```php
if (Craft::$app->plugins->getPlugin('seomatic') === null) {
    Craft::warning('...', 'kunstmaanmigrator');
    $report->warn('...');
    return $report;       // migrateAll
    // OR
    return 0;             // migrateForEntry
}
```

The early-return shape differs (`MigrationReport` vs `int`), so a shared helper would either return mixed types or force callers to unpack. Verbatim duplication is the right call here.

## D-55 — Last-stage ordering preserved

The state-driven source discovery query intact at line 163-172:

```php
$sources = array_column(
    (new Query())
        ->select('source')
        ->distinct()
        ->from('{{%kunstmaanmigrator_state}}')
        ->where(['targetType' => 'entry'])
        ->andWhere(['not in', 'source', [self::STATE_SOURCE]])
        ->all(),
    'source',
);
```

The `not in [seo_meta]` clause is the load-bearing reason this stage MUST run last: it walks the state-table for FQCN-derived source names that EntryMigrationService wrote in earlier stages, while excluding the rows this very service writes back as side-effect state.

## Plan 04-09 wiring contract

When Plan 04-09 lands, it must wire (per PATTERNS.md "DI registration via Plugin::config() + Plugin::init() sibling-wiring"):

```php
// Plugin::config() components map:
'seoMigrationService' => SeoMigrationService::class,

// Plugin::init() sibling-wiring (after parent::init()):
$this->seoMigrationService->legacyDb     = $this->legacyDbService;
$this->seoMigrationService->stateService = $this->migrationStateService;
$this->seoMigrationService->seoPayload   = $this->seomaticPayloadBuilder; // Plan 04-02
$this->seoMigrationService->filters      = $this->filterFactory->build(); // optional
$this->seoMigrationService->sites        = $this->resolveSitesMap();      // same map as EntryMigrationService

// D-57 override (only if Settings carries non-default):
$settings = $this->getSettings();
if (is_string($settings->seoTableName) && $settings->seoTableName !== '') {
    $this->seoMigrationService->seoTableName = $settings->seoTableName;
}
```

That sequencing matches PATTERNS.md lines 720+740+762.

## Manual rehearsal deferred to Phase 5 / TST-04

Per the plan's Verification section: "Runtime exercise deferred to Phase 5 / TST-04 rehearsal smoke (CQM dump with SEOmatic installed → counts match within tolerance)." Static verification (php -l, composer test, acceptance grep checklist) all green here. Plan 04-12 (tests + reconciliation) covers static-analysis sign-off; Phase 5 / TST-04 covers the live CQM rehearsal pass.

## Next Phase Readiness

- ADP-01 partially closed — `SeomaticPayloadBuilder` (Plan 04-02) + `SeoMigrationService` (this plan) are both shipped; final closure waits on Plan 04-09 (Plugin wiring) + Plan 04-10 (MigrateController actionSeo).
- Wave 2 progress — 04-06 done; 04-07 (RedirectMigrationService) + 04-08 (CaptureBaselineHtmlService) ready to start (no inter-dependency between the three).
- composer test baseline preserved (60 tests / 137 assertions). Phase 4 hasn't added unit tests yet by design — Plan 04-12 owns Phase 4's test corpus.

## Self-Check: PASSED

- File exists at `src/load/SeoMigrationService.php` (606 lines).
- Commit `593870d` present in `git log --oneline -5`.
- All 17 acceptance criteria pass (see table above).
- composer test exits 0 (60 / 137 — unchanged baseline).
- `php -l src/load/SeoMigrationService.php` clean.

---
*Phase: 04-adapters-verify-settings*
*Completed: 2026-04-26*
