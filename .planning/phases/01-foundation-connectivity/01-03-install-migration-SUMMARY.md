---
phase: 01-foundation-connectivity
plan: 03
subsystem: foundation
tags: [install-migration, state-table, kunstmaan-source-id, uid-reuse, migrate-controller, idempotent]

# Dependency graph
requires:
  - "01-01: PSR-4 autoload contract + Plugin entrypoint stub"
  - "01-02: Plugin::init() with controllerNamespace switch (so kunstmaan-migrator/migrate/install resolves to lameco\\kunstmaanmigrator\\console\\MigrateController)"
  - "NeverProductionTrait (Plan 01) — D-20 enforcement gate consumed by actionInstall"
provides:
  - "Install migration (lameco\\kunstmaanmigrator\\migrations\\Install) — D-06 schema verbatim, D-09 UID reuse, D-10 safeDown no-op"
  - "Three migration constants: FIELD_HANDLE='kunstmaanSourceId', STATE_TABLE='{{%kunstmaanmigrator_state}}', PROJECT_CONFIG_UID_PATH='plugins.kunstmaan-migrator.kunstmaanSourceIdFieldUid'"
  - "MigrateController (lameco\\kunstmaanmigrator\\console\\MigrateController) — Phase 1 ships only actionInstall (D-05)"
  - "kunstmaan-migrator/migrate/install CLI route — FND-02a programmatic install shim"
  - "MigrationManager wiring on track 'kunstmaanmigrator' with namespace 'lameco\\kunstmaanmigrator\\migrations' and path src/migrations/"
affects: [01-04-doctor-command, 01-05-tests-ci, all-phase-2-plus]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Idempotent install via tableExists + project-config + getFieldByHandle three-guard chain (D-07 / D-09)"
    - "Plain Text field with charLimit=255 minted via Craft fields service + StringHelper::UUID()"
    - "safeDown() returning true unconditionally as the FND-03 / D-10 'preserve on uninstall' contract"
    - "MigrationManager configured by Craft::createObject() with explicit track / namespace / path (Craft 5 pattern after --migrationPath was dropped)"
    - "NeverProduction guard as the first statement of every controller action body (D-20)"

key-files:
  created:
    - src/migrations/Install.php
    - src/console/MigrateController.php
  modified: []

key-decisions:
  - "D-06 honored: state table schema is byte-for-byte v1.x (10 cols: id, source, sourceKey, targetType, targetId, targetUid, siteId, meta, dateCreated, dateUpdated; 2 indexes: UNIQUE on (source, sourceKey, siteId), non-unique on (dateUpdated))."
  - "D-07 honored: Install is idempotent. tableExists guard skips createTable on swap-in hosts (570-row CQM continuity). project-config existence guard short-circuits when UID already persisted. getFieldByHandle short-circuits when field already exists under our handle."
  - "D-09 honored: UID-reuse chain is project-config → getFieldByHandle → mint. The literal 'kunstmaanSourceId' is kept inline inside getFieldByHandle() so grep-based UID-continuity assertions match deterministically."
  - "D-10 / FND-03 honored: safeDown() is a verbatim no-op (returns true, zero side-effects). State table and field survive uninstall — operator wipes manually for full reset."
  - "D-05 honored: MigrateController in Phase 1 ships ONLY actionInstall. Phase 3 adds extract / transform / load / finalize on the same controller class."
  - "D-20 honored: actionInstall's first statement is the NeverProduction gate (early-return when env=production). T-1-01 mitigation verified."
  - "FND-02a honored: kunstmaan-migrator/migrate/install runs MigrationManager::up() on the kunstmaanmigrator track. Path note: v2's flat src/console/ uses __DIR__ . '/../migrations' (vs v1's __DIR__ . '/../../migrations' from the 3-deep src/bridge/console/controllers/ layout)."

patterns-established:
  - "Idempotent install with audit-logged branch: every UID-reuse branch emits Craft::info() naming the branch (reuse vs mint) so operators can read storage/logs/ to confirm which path fired on swap-in."
  - "MigrationManager wiring pattern for plugin-owned migrations (track + namespace + path triple) — Phase 1 shape that future schema bumps will reuse without further controller changes."

requirements-completed: [FND-02, FND-02a, FND-03]

# Metrics
duration: 5min
completed: 2026-04-25
---

# Phase 01 Plan 03: install-migration Summary

**The `kunstmaanmigrator_state` table and `kunstmaanSourceId` Plain Text field now land via a single idempotent `Install` migration — with v1.x→v2 swap-in continuity preserved by the `tableExists` + project-config + `getFieldByHandle` guard chain — and `kunstmaan-migrator/migrate/install` exposes the migration on the CLI as the FND-02a programmatic shim.**

## Performance

- **Duration:** ~5 min
- **Started:** 2026-04-25T15:58:00Z (approximate — directly after Plan 02 completion)
- **Completed:** 2026-04-25T16:01:00Z
- **Tasks:** 2 (both `type=auto`)
- **Files created:** 2 (`src/migrations/Install.php`, `src/console/MigrateController.php`)
- **Files modified:** 0

## Accomplishments

- `lameco\kunstmaanmigrator\migrations\Install` autoloads via PSR-4. All three constants reflect with the expected values: `FIELD_HANDLE='kunstmaanSourceId'`, `STATE_TABLE='{{%kunstmaanmigrator_state}}'`, `PROJECT_CONFIG_UID_PATH='plugins.kunstmaan-migrator.kunstmaanSourceIdFieldUid'`.
- `safeUp()` calls `ensureStateTable()` then `ensureFieldAndAttach()` — the same orchestration v1.x ships.
- `safeDown()` is a verbatim no-op (returns true) — no `dropTable`, no `removeField`, no `delete` anywhere in the method body. FND-03 / D-10 contract holds.
- `ensureStateTable()` declares the 10-column D-06 schema with both indexes:
  - UNIQUE composite on `(source, sourceKey, siteId)`
  - Non-unique lookup on `(dateUpdated)` (supports `MigrationStateService::getLastRunMeta()` ordering, which lands in Phase 3)
- `ensureStateTable()` is guarded by `$this->db->tableExists(self::STATE_TABLE)` — the v1.x→v2 swap-in critical-path check that prevents dropping the 570-row CQM rehearsal table.
- `ensureFieldAndAttach()` implements the D-09 three-step UID resolution chain:
  1. Read existing UID from project-config with forced YAML re-read (`true` second arg) — survives concurrent project-config races.
  2. If absent, query `Craft::$app->fields->getFieldByHandle('kunstmaanSourceId')` and reuse its UID if found (the swap-in case — preserves the 570 CQM entries' tracking data).
  3. Otherwise mint a new field with `StringHelper::UUID()`. PlainText type with `charLimit = 255`, `searchable = true`, instructions copy preserved verbatim from v1.
- Both UID-reuse branches log via `Craft::info(...)` so operators can grep `storage/logs/` to confirm which branch fired on a given install.
- `lameco\kunstmaanmigrator\console\MigrateController` autoloads. Reflection confirms `actionInstall` is the only controller-declared `action*` method — Phase 3 actions (extract/transform/load/finalize/index/truncate) are correctly absent.
- `actionInstall()`'s first statement is `if (($gate = $this->enforceNeverProduction()) !== null) { return $gate; }` — D-20 ordering verified by `grep -A 5 'function actionInstall' | head -8 | grep -q 'enforceNeverProduction'`.
- `MigrationManager` is instantiated via `Craft::createObject([...])` with track=`kunstmaanmigrator`, namespace=`lameco\kunstmaanmigrator\migrations`, and path=`__DIR__ . '/../migrations'` (the correct 2-deep flat-layout path — NOT v1's `'/../../migrations'`).
- `composer dump-autoload` succeeds; both new classes are reachable through PSR-4.
- `php -l` passes on both files.

## Task Commits

Each task was committed atomically:

1. **Task 1: Port Install.php — state table + field UID reuse** — `898d632` (feat)
2. **Task 2: MigrateController with actionInstall** — `8ba9d1a` (feat)

## Files Created/Modified

- `src/migrations/Install.php` (created) — 112 lines. `class Install extends Migration` with three constants, `safeUp` / `safeDown`, `ensureStateTable` (10-col schema + 2 indexes guarded by `tableExists`), `ensureFieldAndAttach` (D-09 three-step UID-reuse chain with `Craft::info()` audit logs on each branch).
- `src/console/MigrateController.php` (created) — 54 lines. `class MigrateController extends Controller` with `NeverProductionTrait`, single public action `actionInstall(): int` (NeverProduction gate first, MigrationManager wiring + `up()`).

## Decisions Made

None beyond the ones already locked in `01-CONTEXT.md` (D-05, D-06, D-07, D-09, D-10, D-20). Plan executed exactly as written.

## Deviations from Plan

None. Plan executed exactly as specified — both files match the paste-ready content in the PLAN action blocks verbatim. All 17 acceptance criteria across the two tasks pass.

**Note on Task 2's reflection probe wording:** The plan's verify block included a reflection probe that enumerated all `IS_PUBLIC` methods starting with `action*` and asserted only `actionInstall` was present. As literally written, that probe also matches the `actions()` method inherited from `craft\console\Controller`, which returns `["actions"]` as an unexpected match. The intent of the check is clearly "no Phase 3 controller-declared action methods" — which is verified independently by the `! grep -E 'function action(Extract|Transform|Load|Finalize|Index|Truncate)'` acceptance-criterion grep (which passes). Treated as a non-blocking probe artifact, not a code defect.

## Issues Encountered

None.

## User Setup Required

None. The migration runs on demand via either:

- `./craft plugin/install kunstmaan-migrator` (Craft itself runs `Install::safeUp()` on initial install), or
- `./craft kunstmaan-migrator/migrate/install` (the FND-02a shim — runs the same migration; safe to re-run, idempotent).

On `CRAFT_ENVIRONMENT=production` hosts, `migrate/install` returns `ExitCode::UNSPECIFIED_ERROR` with a stderr "Refusing to run against CRAFT_ENVIRONMENT=production" message. Operators should run install in dev/staging only.

## Next Phase Readiness

**Ready for Plan 04 (Doctor command) and Plan 05 (Tests + CI):**

- The `kunstmaanmigrator_state` table is the contract Phase 3's `MigrationStateService` reads/writes; the schema lands here once and stays put.
- The `kunstmaanSourceId` field is the upsert-lookup key Phase 3's load stage uses (`ETL-05` idempotent re-runs). UID reuse means the 570 CQM rehearsal entries continue to resolve through the v2 plugin without remigration.
- `Plugin::getInstance()->getMigrator()` (Craft default) finds `Install.php` automatically because v2 ships migrations at the framework default path `src/migrations/` — no `getMigrator()` override needed (one fewer thing for Plan 04 to wire).
- Plan 04's `DoctorController` will live alongside `MigrateController` in `src/console/`. Both share the same `controllerNamespace = lameco\kunstmaanmigrator\console` set by Plan 02's `Plugin::init()` switch.
- Plan 05's `PluginBootstrapTest` can additionally assert `Install::class` and `MigrateController::class` autoload as part of the smoke check.

**No blockers.**

## Threat Flags

None. The plan's `<threat_model>` register (T-1-01, T-1-06, T-1-07) covers everything this plan introduces. Mitigations were applied as specified:

- **T-1-01 (Elevation of Privilege via actionInstall):** mitigated. NeverProduction guard is the first statement in the action body — verified by ordering grep `grep -A 5 'function actionInstall' src/console/MigrateController.php | head -8 | grep -q 'enforceNeverProduction'`.
- **T-1-06 (Tampering with field UID via project-config / fields service):** accepted per plan. Branch-emitting `Craft::info()` audit lines are present in both reuse and mint branches, making detection possible via `storage/logs/`.
- **T-1-07 (DoS via repeated install):** mitigated by three idempotency guards: `tableExists`, project-config UID existence, `getFieldByHandle` — re-running install causes zero schema churn. All three verified by acceptance-criteria greps.

No new security-relevant surface introduced beyond the plan's threat register.

## Self-Check: PASSED

- src/migrations/Install.php: FOUND
- src/console/MigrateController.php: FOUND
- Commit 898d632: FOUND
- Commit 8ba9d1a: FOUND

---
*Phase: 01-foundation-connectivity*
*Completed: 2026-04-25*
