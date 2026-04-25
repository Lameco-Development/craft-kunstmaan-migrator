# Phase 1: Foundation & Connectivity - Context

**Gathered:** 2026-04-25
**Status:** Ready for planning

<domain>
## Phase Boundary

A scaffolded Craft 5 plugin (`lameco/craft-kunstmaan-migrator`, schemaVersion 1.0.0) that:

1. Installs cleanly on a stock Craft 5 host with no SEOmatic / Retour dependencies.
2. Owns its legacy MySQL connection internally — no Yii component required in the consumer site's `config/app.php`.
3. Creates the `kunstmaanmigrator_state` table and the `kunstmaanSourceId` Plain Text field (reusing an existing field UID when present, so v1.x→v2 swap-in hosts retain their 570-row tracking continuity).
4. Ships `NeverProductionTrait` gating every legacy-reading and destructive action.
5. Exposes `kunstmaan-migrator/doctor` (3 checks: legacy DB, Anthropic key, `storage/migration/` writable) and `kunstmaan-migrator/migrate/install` (programmatic migration shim).
6. Wires PHPUnit 11 under `tests/`, a `composer test` script, and a single-job GitHub Actions workflow (PHP 8.3, ubuntu-latest) running validate + phpunit on every push.

**Out of scope for Phase 1** (deferred to later phases per ROADMAP.md):
- `analyze` / `map` / `migrate` / `verify` command bodies (Phases 2-4)
- mapping.yaml machinery (Phase 2)
- `MigrationFilters` value object (Phase 2 — FILT-01)
- SEOmatic / Retour adapters (Phase 4)
- CP Settings page UI (Phase 4)
- Transform-stage characterization fixtures (Phase 5)

</domain>

<decisions>
## Implementation Decisions

### Source layout

- **D-01:** Top-level `src/` follows a stage-based vertical-slice structure. v1's three-tier `kunstmaan/` / `bridge/` / `craft/` layout (with Deptrac) is dropped.
- **D-02:** Namespace sub-segments are **lowercase** to match Craft / Yii / established Craft-plugin convention (`craft\db\`, `nystudio107\seomatic\…`, `verbb\hyper\…`). Class names stay PascalCase. Root namespace remains `lameco\kunstmaanmigrator\` per CLAUDE.md.
- **D-03:** Console controllers live in a flat `src/console/` directory (single `controllerNamespace = lameco\kunstmaanmigrator\console`). Stage directories (`src/doctor/`, `src/migrate/`, etc.) hold the services / handlers / models the controllers orchestrate. This mirrors v1's `bridge/console/controllers/` shape minus the `bridge/` tier.
- **D-04:** Phase 1 ships only the directories it actually uses. The full stage skeleton lands progressively.

```
src/
  Plugin.php
  NeverProductionTrait.php
  console/
    DoctorController.php
    MigrateController.php           # Phase 1: only actionInstall
  db/
    LegacyDbService.php
  migrations/
    Install.php
  models/
    Settings.php

# Created by later phases (NOT Phase 1):
#   src/analyze/, src/map/, src/migrate/{extract,transform,load,finalize}/,
#   src/verify/, src/adapters/{seomatic,retour}/
#   src/models/MigrationFilters.php (Phase 2 — FILT-01)
```

- **D-05:** `migrate/install` is an action on `src/console/MigrateController.php` — the same controller Phase 3 will fill out with extract/transform/load/finalize actions. Phase 1 ships only `actionInstall()`. CLI URL `kunstmaan-migrator/migrate/install` matches v1.

### State table schema

- **D-06:** State table schema matches **v1.x's actual `Install.php` byte-for-byte**, not REQUIREMENTS.md FND-02's listed columns. The two contradicted each other; v1's actual schema wins because v1.x rehearsal hosts (CQM, 570 entries) already have rows in this shape and a v2 swap-in must not orphan them.

```sql
CREATE TABLE {{%kunstmaanmigrator_state}} (
  id            INT primaryKey,
  source        VARCHAR(64)   NOT NULL,   -- e.g. 'kuma:NewsPage'
  sourceKey     VARCHAR(255)  NOT NULL,   -- legacy id
  targetType    VARCHAR(64)   NOT NULL,   -- 'entry', 'asset', 'video', etc.
  targetId      INT,
  targetUid     VARCHAR(36),              -- uid()
  siteId        INT NULL,
  meta          JSON NULL,
  dateCreated   DATETIME NOT NULL,
  dateUpdated   DATETIME NOT NULL,
  UNIQUE INDEX (source, sourceKey, siteId),
  INDEX (dateUpdated)
);
```

> **Follow-up:** REQUIREMENTS.md FND-02 lists wrong column names (`legacy_class, legacy_id, craft_id, migrated_at, status`). Update FND-02 when this phase ships so the requirement reflects the actual contract. PROJECT.md Key Decisions row "Keep v1's `kunstmaanmigrator_state` schema verbatim" needs the same correction.

- **D-07:** `Install.php` is the single source of install truth. v1.x's legacy `m000000_000000_install_migration_state.php` and the `m260425_000000_upgrade_to_v2.php` audit migration are **not** carried forward — they are v1-internal archaeology. Existing v1.x hosts already ran them; new hosts don't need them. `Install.php`'s `tableExists` guard handles the swap-in case where the table already exists.
- **D-08:** Plugin `schemaVersion` is declared as `1.0.0` (treat v2 as a fresh plugin). On greenfield Craft hosts this works cleanly. On v1.x→v2 swap-in hosts, v1 had recorded `2.0.0` in the plugin's stored schemaVersion — this places declared (1.0.0) below installed (2.0.0).
- **D-09:** Field UID reuse follows v1's pattern: persist the UID under project-config path `plugins.kunstmaan-migrator.kunstmaanSourceIdFieldUid`. On install, the migration:
  1. Reads existing UID from project-config (forced YAML re-read).
  2. If absent, queries `Craft::$app->fields->getFieldByHandle('kunstmaanSourceId')` and reuses its UID if found.
  3. Only mints a new UID when neither path yields one.
  Field type stays Plain Text (charLimit 255) per PROJECT.md Key Decisions.
- **D-10:** `safeDown()` is a deliberate no-op on both the state table and the `kunstmaanSourceId` field (FND-03). Operators wipe manually for a full reset.

### Legacy DB wiring

- **D-11:** Plugin registers a `legacyDb` Yii application component at `Plugin::init()`, **only when the host has not already declared one**:

```php
if (!Craft::$app->has('legacyDb', true)) {
    Craft::$app->set('legacyDb', [
        'class'    => yii\db\Connection::class,
        'dsn'      => "mysql:host={$host};port={$port};dbname={$database}",
        'username' => $user,
        'password' => $password,
        'charset'  => $charset,
        'tablePrefix' => $tablePrefix,
        'attributes' => [PDO::ATTR_EMULATE_PREPARES => false],
    ]);
}
```

This is the in-place upgrade story: on v1.x hosts that already declare `legacyDb` in `config/app.php`, the existing component wins and the plugin's wiring is a no-op (zero config churn for operators). On greenfield hosts, the plugin's env-driven component fills the gap. `LegacyDbService` continues to use `Craft::$app->get('legacyDb')` verbatim from v1, so service-level code is unchanged.

- **D-12:** Connection params are sourced from the Settings model, which falls back to env vars (D-15). Naming mirrors Craft's own DB env vars for muscle-memory:

```
CRAFT_LEGACY_DB_SERVER       (required)
CRAFT_LEGACY_DB_DATABASE     (required)
CRAFT_LEGACY_DB_USER         (required)
CRAFT_LEGACY_DB_PASSWORD     (required)
CRAFT_LEGACY_DB_PORT         (default 3306)
CRAFT_LEGACY_DB_CHARSET      (default utf8mb4)
CRAFT_LEGACY_DB_TABLE_PREFIX (default empty)
```

Driver is hardcoded to `mysql` — Kunstmaan is always MySQL.

- **D-13:** `LegacyDbService` is **read-only by discipline**. The class never contains `->insert(`, `->update(`, or `->delete(`. Any legacy-side mutation belongs in an ad-hoc dev console, not in plugin code. Code review enforces.
- **D-14:** Anthropic API key sourced from `ANTHROPIC_API_KEY` env var, with Settings override (CONN-02). Doctor checks presence only — never echoes or logs the value (T-1-03 information-disclosure mitigation, ported from v1).

### Settings model

- **D-15:** Settings model declares **the full v2 surface upfront**, even though only DB params + Anthropic key are read in Phase 1. Each field defaults to its env var; `config/kunstmaan-migrator.php` overrides win when present (Craft idiom). The CP Settings page (Phase 4) reads/writes the same model — no refactor required.

```
Phase 1 fields (read-active):
  legacyDbServer, legacyDbPort, legacyDbDatabase,
  legacyDbUser, legacyDbPassword, legacyDbCharset, legacyDbTablePrefix
  anthropicApiKey

Phase 2-4 fields (declared, unused until later phases):
  llmModel, llmTimeout, mappingPath
  defaultEntities, defaultLocales, defaultSince, defaultMaxPerEntity
  dryRunDefault
```

- **D-16:** `hasCpSettings = true`, `createSettingsModel()` returns the populated Settings instance. `settingsHtml()` renders a placeholder template in Phase 1 (the real form lives in Phase 4 / CFG-01).

### Doctor command

- **D-17:** Phase 1 `doctor` ships **3 checks** — not 4 as CONN-03 lists:

  1. Legacy DB reachability (`SELECT 1`)
  2. Anthropic key presence (env or Settings; never logged)
  3. `storage/migration/` writable

  The mapping-file check is deferred to Phase 2 alongside the loader/validator. Shipping 4 checks with a hollow stub is dishonest; CONN-03 will be amended to acknowledge that the mapping check lands with the mapping machinery.

- **D-18:** `doctor` **auto-creates `storage/migration/`** if missing (mkdir + chmod 0755), then verifies write perm. One less manual step for operators. Side-effecting, but only creates a known-good directory under Craft's `storage/` tree.
- **D-19:** Plain-text OK/FAIL output ported from v1's `DoctorController`. Exit 0 when every check passes, exit 1 when any FAIL. **No queue-worker check** (PROJECT.md Key Decisions: "v1's queue-heavy pipeline; v2 is CLI-inline by default").
- **D-20:** Every doctor action calls `$this->enforceNeverProduction()` first — same pattern as v1.

### Tests + CI

- **D-21:** PHPUnit 11 wired under `tests/`. `composer test` runs the suite. Phase 1 ships at least one **non-empty** smoke test (`PluginBootstrapTest`) that asserts `Plugin::class` instantiates and key services (`LegacyDbService`, Settings model) wire correctly. Empty test suites are forbidden — FND-05 is explicit ("non-empty on day one") and PROJECT.md Test discipline is unambiguous.
- **D-22:** Single GitHub Actions workflow at `.github/workflows/ci.yml` running on push and pull_request. Steps:

```yaml
- runs-on: ubuntu-latest
- php: 8.3
- composer validate --strict
- composer install
- composer test (vendor/bin/phpunit)
```

Plugin-load smoke test (TST-03) is Phase 5; Phase 1 just runs the unit suite green. Matrix expansion (8.4, multiple OS) lands when there's a real reason.

### NeverProductionTrait

- **D-23:** Port `NeverProductionTrait` from v1 verbatim (the file is 39 lines and perfectly fit-for-purpose). Lives at `src/NeverProductionTrait.php` (root namespace, alongside `Plugin.php`). Used by every legacy-reading or destructive controller action via `if (($gate = $this->enforceNeverProduction()) !== null) return $gate;`.

### Composer

- **D-24:** `composer.json` requires:
  ```
  php: ^8.3
  craftcms/cms: ^5.0
  symfony/yaml: ^6.0 || ^7.0
  guzzlehttp/guzzle: ^7.0
  ```
  Suggests (not requires):
  ```
  nystudio107/craft-seomatic: ^5.1
  nystudio107/craft-retour: ^5.0
  ```
  Dev:
  ```
  phpunit/phpunit: ^11.0
  ```
  **Drops** v1's `deptrac/deptrac` and `rector/rector`. Deptrac is retired (no three-tier layout to police); Rector can return as a dev tool when there's a real refactor driver, not as default dev infrastructure.
- **D-25:** Composer extra:
  ```
  handle: kunstmaan-migrator
  name: Kunstmaan Migrator
  class: lameco\kunstmaanmigrator\Plugin
  schemaVersion: 1.0.0
  developer: Lameco Development
  ```

### Claude's Discretion

- Exact Settings model property types (PHP property types vs Craft Model `rules()` declarations) — pick whatever Craft 5 idiom is cleanest.
- Exact path resolution for `storage/migration/` — use `Craft::$app->path->getStoragePath() . '/migration'` or equivalent.
- Test file naming and `tests/bootstrap.php` shape — use Craft's testing skeleton if it pulls its weight, otherwise a hand-rolled bootstrap.
- The placeholder `settingsHtml()` template content in Phase 1 (just enough to satisfy the CP without making promises about the Phase 4 UI).
- Whether `LegacyDbService` is a Yii Component (v1 pattern) or a plain class with constructor injection — Yii Component keeps `Plugin::config()` clean; either is fine.
- README.md and UPGRADING.md scope — minimal Phase 1 README is enough; full UPGRADING.md content lands when there's something to migrate from (Phase 5 / release).

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Project planning (this repo)

- `CLAUDE.md` — locked architectural ground rules. Single mapping.yaml, optional adapters, plugin-owned legacy DB, CLI-only operator surface, atomic-always-on, runtime-zero-AI, NeverProductionTrait, no `.claude/skills/` bundle. **Hard constraints — do not casually reverse.**
- `.planning/PROJECT.md` — full vision, constraints, Key Decisions table. The "Context" section's critical-review notes on v1 are load-bearing for what to port verbatim vs redesign.
- `.planning/REQUIREMENTS.md` §"Foundation (FND)" + §"Connectivity (CONN)" — FND-01..05, FND-02a, CONN-01..03 (Phase 1's full requirement set).
- `.planning/REQUIREMENTS.md` §Out of Scope — defines the v1.0 scope wall. Anything listed there is **not** Phase 1 work.
- `.planning/ROADMAP.md` §"Phase 1: Foundation & Connectivity" — phase goal + 5 success criteria the verifier will check against.

### v1.x brownfield reference (read-only — `~/Sites/craft-kunstmaan-migrator/`)

- `src/Plugin.php` — v1's bootstrap. Reference for the `Craft::$app->set()` closure pattern, controllerNamespace switching (console vs web), `EVENT_AFTER_SAVE_PLUGIN_SETTINGS` hook, settings template render. **Do not port wholesale** — most of v1's components are Phase 2-4 territory; Phase 1 ships a much smaller `Plugin.php`.
- `src/craft/migrations/Install.php` — schema source of truth. The `safeUp()` / `ensureStateTable()` / `ensureFieldAndAttach()` shape is what v2's `Install.php` mirrors. Schema columns (D-06) come from this file's `createTable()` call. UID-reuse logic (D-09) ports from `ensureFieldAndAttach()`.
- `src/NeverProductionTrait.php` — port verbatim into `src/NeverProductionTrait.php` (D-23).
- `src/kunstmaan/db/LegacyDbService.php` — read-only legacy DB accessor. Phase 1 only needs the `db()` / `queryOne()` / `queryAll()` / `queryScalar()` / `streamQuery()` methods; the domain helpers (`streamLiveNodes`, `translationsFor`, `pagePartsFor`, `seoFor`, `mediaById`, `redirects`, `extTranslationsFor`, `getDatabaseName`) belong to Phases 2-4. Phase 1 ships the thin core; later phases add the helpers.
- `src/bridge/console/controllers/DoctorController.php` — Phase 1's doctor reference. **Drop the `checkQueueWorker()` method** (D-19, PROJECT.md Key Decisions). Drop the `checkMapping()` method too (D-17 — defers to Phase 2). Keep `checkLegacyDb()` and `checkApiKey()` patterns. Add the new `checkStorageDir()` per D-18.
- `src/craft/migrations/m260425_000000_upgrade_to_v2.php` — **read for context only**, do not port (D-07). This is v1-internal upgrade archaeology.
- `composer.json` — v1's composer manifest. Reference for shape only; v2 drops the SEOmatic / Retour requires (becoming `suggest`), drops Deptrac and Rector dev deps, and bumps PHP to `^8.3` (D-24, D-25).

### Future phase context (read for awareness, not implementation)

- `.planning/REQUIREMENTS.md` §"Schema + Mapping (MAP)" — informs why Phase 1's doctor mapping check is deferred (D-17) and why Settings carries `mappingPath` upfront (D-15).
- `.planning/REQUIREMENTS.md` §"Settings + observability (CFG)" — informs the Settings field set (D-15). CFG-01 is Phase 4; Phase 1 declares the model so Phase 4 plugs in without refactor.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets (verbatim or near-verbatim port from v1)

- **`NeverProductionTrait`** (39 lines): port byte-for-byte. Already perfect.
- **`Install.php`'s schema declaration** (createTable + indexes): port columns/indexes verbatim. The `tableExists` idempotency guard is the right pattern.
- **`Install.php`'s field UID reuse logic**: port the project-config-then-getFieldByHandle lookup chain. The 570-row CQM continuity argument hinges on this code being correct.
- **`LegacyDbService::db() / queryOne / queryAll / queryScalar / streamQuery`**: thin accessor methods over `Craft::$app->get('legacyDb')`. The streamQuery generator is correct as-is.
- **`DoctorController::checkLegacyDb()` and `checkApiKey()`**: port these two methods (rename / re-namespace). Drop the queue and mapping checks.

### Established Patterns (v1) to follow

- Plain-text OK/FAIL output style with ANSI colors (`Console::FG_GREEN` / `Console::FG_RED`).
- Every controller action begins with `if (($gate = $this->enforceNeverProduction()) !== null) return $gate;`.
- Idempotent install: every `Install` step guarded so re-runs are safe (`tableExists`, project-config existence check).
- `LegacyDbService` is a Yii Component with `db()` resolving via `Craft::$app->get('legacyDb')` on every call (test-double-friendly).

### Greenfield items (no v1 analog — Phase 1 builds these)

- The `Craft::$app->set('legacyDb', [...])` boot wiring (D-11) is **new** in v2 — v1 required operators to declare `legacyDb` in `config/app.php` themselves. Phase 1 must verify the `Craft::$app->has('legacyDb', true)` guard semantics on a fresh boot vs a v1.x swap-in.
- Doctor's `checkStorageDir()` (D-18) is new — v1 had no equivalent.
- The Settings model with `CRAFT_LEGACY_DB_*` env-fallback resolution (D-12, D-15) is new in v2 — v1 only had `mappingPath` and a few CP-only settings.
- The single `Install.php` (instead of v1's separate legacy migration + Install pair) is the v2 simplification.

### Integration Points

- Plugin entry: `Plugin::init()` is where `Craft::$app->set('legacyDb', ...)` runs and where `controllerNamespace` is set.
- Test bootstrap: `tests/bootstrap.php` initializes Craft enough to instantiate `Plugin` for the smoke test.
- CI: `.github/workflows/ci.yml` runs validate + composer install + phpunit.

</code_context>

<specifics>
## Specific Ideas

- **v1.x in-place upgrade is a first-class concern.** The CQM rehearsal site has 570 entries with `kunstmaanSourceId` values and rows in `kunstmaanmigrator_state`. v2's design (D-09 UID reuse, D-11 conditional `legacyDb` registration, D-06 schema verbatim, D-07 idempotent install) is shaped by the requirement that swapping v1 for v2 on that site is a no-op for migrated content.
- **schemaVersion 1.0.0 wrinkle for v1.x→v2 swap-in** (D-08): v1 currently declares `schemaVersion = 2.0.0`. v2 declaring `1.0.0` puts declared below installed. Planner research step should confirm the exact Craft behavior — most likely no migrations run (which is fine since `tableExists` guards), but UPGRADING.md may need to instruct operators to run `./craft project-config/sync` once on swap-in. Keep an eye on the `pluginVersionUpdated` hook semantics in Craft 5.
- **REQUIREMENTS.md FND-02 / PROJECT.md Key Decisions row both list wrong column names.** Update both to match v1.x's actual schema (D-06) when this phase ships. Add a project-doc fix to the phase plan.
- **CONN-03 says doctor has 4 checks; Phase 1 ships 3.** Update CONN-03 to reflect that the mapping-file check lands with mapping machinery in Phase 2.

</specifics>

<deferred>
## Deferred Ideas

- **Test scaffolding refinement** — Phase 1 ships a minimal smoke test. Transform-stage characterization fixtures (TST-02), full unit suite covering MigrationFilters / MappingLoader / handlers / CkeditorRewriter / heuristic proposer (TST-01), and the plugin-load smoke test in CI (TST-03) are all Phase 5 territory. Resist the urge to over-test Phase 1.
- **CI matrix expansion** — multiple PHP versions (8.3 + 8.4) and / or multiple OSes are deferred until there's a real driver (failing 8.4 build, Windows operator complaint, etc.). Single-job PHP 8.3 ubuntu-latest is enough today.
- **CP Settings UI** — Settings model is fully populated in Phase 1 (D-15) but the form template stays a placeholder until Phase 4 / CFG-01.
- **README.md / UPGRADING.md long-form content** — minimal Phase 1 README covers install + env vars + doctor. Full UPGRADING.md (v1.x → v2 swap-in playbook) lands at release time in Phase 5, when there's a tested upgrade path to document.
- **Rector / Deptrac dev tooling** — dropped from v2's composer.json (D-24). Re-add only if a real refactor driver appears.
- **Driver abstraction for legacy DB** — hardcoded to `mysql` (D-12). PostgreSQL Kunstmaan installs are unheard of; cross that bridge when an operator brings one.

</deferred>

---

*Phase: 01-foundation-connectivity*
*Context gathered: 2026-04-25*
