---
phase: 01-foundation-connectivity
verified: 2026-04-25T00:00:00Z
status: human_needed
score: 9/9 must-haves verified (structural); 5/5 ROADMAP success criteria structurally verified, runtime confirmation deferred to human UAT
overrides_applied: 0
re_verification:
  previous_status: none
  previous_score: n/a
  gaps_closed: []
  gaps_remaining: []
  regressions: []
human_verification:
  - test: "Install plugin into a stock Craft 5 host"
    expected: "`composer require lameco/craft-kunstmaan-migrator` resolves; `./craft plugin/install kunstmaan-migrator` reports success; the `kunstmaanmigrator_state` table is created and the `kunstmaanSourceId` Plain Text field appears in the Craft CP fields list with charLimit 255."
    why_human: "Craft 5's plugin install path requires a real Craft host bootstrap, project-config + DB. Phase 1 verifies the contract structurally (autoload, FQCN, schema declarations, idempotent guards) but cannot run `./craft plugin/install` without a live host. Maps to ROADMAP SC1 + SC2."
  - test: "Run `./craft kunstmaan-migrator/doctor` on a properly-configured dev host"
    expected: "Three OK lines printed in green: `legacyDb reachable`, `ANTHROPIC_API_KEY set`, `storage/migration writable (<absolute path>)`; final summary `Doctor: PASS`; exit code 0. Then break each prerequisite (wrong DB password, unset ANTHROPIC_API_KEY, chmod 0555 storage/migration) one at a time and confirm each variant emits a red FAIL line and exit code 1, AND that all three checks still print (no short-circuit). Maps to ROADMAP SC3 + CONN-03."
    why_human: "Requires a live Craft bootstrap, a reachable legacy MySQL DB, and an API key. Static reflection confirms the controller shape and check methods exist but cannot exercise the runtime exit codes."
  - test: "Run `./craft kunstmaan-migrator/migrate/install` twice in a row"
    expected: "First run reports migrations applied; second run is a no-op (table already exists, project-config UID already set) — neither run errors. Confirms FND-02a + D-07 idempotency at runtime."
    why_human: "Cannot exercise the MigrationManager from a unit-test context without a Craft host. Structural verification confirms the manager is wired with track=`kunstmaanmigrator`, namespace=`lameco\\kunstmaanmigrator\\migrations`, path=`__DIR__ . '/../migrations'`."
  - test: "v1.x → v2 swap-in on a host with an existing `kunstmaanSourceId` field UID and populated state table (e.g. CQM with ~570 entries)"
    expected: "Install does NOT re-create the field with a new UID (D-09 step 1 / step 2 reuse path fires; check `Craft::info` log line `reusing existing field UID <uid>` in `storage/logs/`). The 570 existing rows in `kunstmaanmigrator_state` are preserved (no schema churn). FND-02 swap-in continuity holds."
    why_human: "Requires a real v1.x-installed Craft host as the test fixture. This is the headline correctness gate for the v2 rewrite — the entire UID-reuse chain (D-09 three steps) exists to make this case work, and only a real host with v1.x state demonstrates it. Map to ROADMAP SC2 second sentence."
  - test: "Set `CRAFT_ENVIRONMENT=production` and invoke `./craft kunstmaan-migrator/doctor` and `./craft kunstmaan-migrator/migrate/install`"
    expected: "Both refuse to run with the message `Refusing to run against CRAFT_ENVIRONMENT=production` printed in red on stderr; exit code is `ExitCode::UNSPECIFIED_ERROR` (1). No checks or migrations execute. Maps to ROADMAP SC4 + FND-04."
    why_human: "Requires a Craft host with the environment override. Static checks confirm both controller actions gate `enforceNeverProduction()` as the first statement and the trait returns ExitCode::UNSPECIFIED_ERROR when the env equals 'production'."
  - test: "GitHub Actions CI run on the next push to a remote branch"
    expected: "Workflow `CI / test` runs on PHP 8.3 / ubuntu-latest, executes `composer validate --strict --no-plugins`, `composer install`, and `composer test`; all three steps green; total run is < 2 min. Maps to ROADMAP SC5 second clause."
    why_human: "Repo is not currently linked to a remote in this verification run (no git context observed). Local `composer test` is green (3/3 tests, 3/3 assertions); CI must be observed in GitHub Actions UI on the next push to confirm parity."
---

# Phase 1: Foundation & Connectivity — Verification Report

**Phase Goal:** A scaffolded Craft 5 plugin that installs cleanly, owns its legacy DB connection, and exposes a working `doctor` command. PHPUnit suite is wired and non-empty on day one — no "tests skipped in 1.0" regret this time.

**Verified:** 2026-04-25
**Status:** human_needed (all structural / static checks PASS; runtime acceptance requires a Craft host)
**Re-verification:** No — initial verification

## Goal Achievement

### Observable Truths

| #  | Truth                                                                                                              | Status     | Evidence                                                                                                                                            |
| -- | ------------------------------------------------------------------------------------------------------------------ | ---------- | --------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1  | Greenfield repo has a runnable composer manifest declaring the Craft plugin                                        | VERIFIED   | `composer validate --strict --no-plugins` exits 0; manifest at `composer.json` has `type=craft-plugin`, PSR-4 `lameco\kunstmaanmigrator\: src/`, schemaVersion `1.0.0`, handle `kunstmaan-migrator`. |
| 2  | Plugin entrypoint class exists at the declared FQCN with `schemaVersion='1.0.0'` and `hasCpSettings=true`          | VERIFIED   | Reflection probe: `lameco\kunstmaanmigrator\Plugin` autoloads, default props read `schemaVersion='1.0.0'`, `hasCpSettings=true`, methods `init`, `config`, `createSettingsModel`, `settingsHtml` all present. |
| 3  | NeverProductionTrait is available and ported byte-for-byte from v1                                                 | VERIFIED   | `src/NeverProductionTrait.php` autoloads as a trait; `enforceNeverProduction()` is `protected`, return type `?int`, contains `CRAFT_ENVIRONMENT === 'production'` check + returns `ExitCode::UNSPECIFIED_ERROR`. 19-line file. |
| 4  | Plugin owns the legacy DB connection internally (CONN-01)                                                          | VERIFIED   | `Plugin::init()` contains `if (!Craft::$app->has('legacyDb', true))` followed by `Craft::$app->set('legacyDb', [...])` with DSN built from Settings. Existing host declarations win (D-11 swap-in path). |
| 5  | Anthropic API key sourced from `ANTHROPIC_API_KEY` env with Settings override (CONN-02)                            | VERIFIED   | `Settings::init()` resolves `$this->anthropicApiKey ??= App::env('ANTHROPIC_API_KEY') ?: null`. Settings property override wins via `??=`. Never echoed/logged in any file (grep clean). |
| 6  | Settings model declares the full v2 surface upfront (D-15)                                                         | VERIFIED   | Reflection probe lists all 16 properties (8 Phase-1 read-active + 8 Phase 2-4 declared). |
| 7  | LegacyDbService is a Yii Component with five read-only methods                                                     | VERIFIED   | All five methods (`db`, `queryOne`, `queryAll`, `queryScalar`, `streamQuery`) present; extends `yii\base\Component`; resolves underlying connection via `Craft::$app->get('legacyDb')`. D-13 read-only discipline holds (no `insert(`/`update(`/`delete(`/`batchInsert(` in file). |
| 8  | Install creates `kunstmaanmigrator_state` with v1.x's actual schema (D-06 / FND-02)                                | VERIFIED   | `Install::safeUp` calls `ensureStateTable()` which builds the 10-column table (id, source, sourceKey, targetType, targetId, targetUid, siteId, meta, dateCreated, dateUpdated) + UNIQUE index on (source, sourceKey, siteId) + INDEX on (dateUpdated). Guarded by `tableExists` for idempotency (D-07). |
| 9  | Install attaches `kunstmaanSourceId` Plain Text field, REUSING existing UID when present (D-09 / FND-02 / FND-02a) | VERIFIED   | `ensureFieldAndAttach()` walks the three-step chain: project-config `get(PROJECT_CONFIG_UID_PATH, true)` → `Craft::$app->fields->getFieldByHandle('kunstmaanSourceId')` (literal handle) → mint new with `StringHelper::UUID()`. PlainText with `charLimit = 255`. Audit log lines present on both reuse + mint branches. |
| 10 | Re-running install on a host where table or field already exists is a no-op (D-07 idempotency)                     | VERIFIED   | Three guards present: `if ($this->db->tableExists(self::STATE_TABLE)) return;`, `if ($existingUid !== null) return;`, `if ($existingField !== null) { ...set UID without saveField...; return; }`. |
| 11 | Uninstall preserves state table and field — operator wipes manually (FND-03 / D-10)                                | VERIFIED   | `safeDown(): bool { return true; }` — verbatim no-op. AWK scan over the function body confirms zero `dropTable`/`removeField`/`delete` calls. |
| 12 | `kunstmaan-migrator/migrate/install` runs the plugin's own migrations on demand (FND-02a)                          | VERIFIED   | `MigrateController::actionInstall()` instantiates `MigrationManager` with `track='kunstmaanmigrator'`, `migrationNamespace='lameco\kunstmaanmigrator\migrations'`, `migrationPath=__DIR__ . '/../migrations'` — paths resolve correctly for v2's flat `src/console/` layout. |
| 13 | Doctor reports OK/FAIL on legacy DB, Anthropic key, storage/migration writable (3 checks per D-17)                 | VERIFIED   | `DoctorController` declares the four expected methods (`actionIndex`, `checkLegacyDb`, `checkApiKey`, `checkStorageDir`) and does NOT declare the dropped methods (`checkQueueWorker`, `checkMapping`). `actionIndex` orchestrates with `&&`-against-`$ok` so all three checks always run. CONN-03 patched in REQUIREMENTS.md to acknowledge mapping check defers to Phase 2. |
| 14 | Doctor exits 0 on full pass, 1 on any FAIL, with plain-text OK/FAIL output (D-19)                                  | VERIFIED   | `actionIndex` returns `$ok ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR`. Per-check methods write `OK` lines via `stdout(..., Console::FG_GREEN)` and `FAIL` lines via `stderr(..., Console::FG_RED)`. |
| 15 | storage/migration/ is auto-created if missing (D-18)                                                               | VERIFIED   | `checkStorageDir` constructs `$dir = Craft::$app->path->getStoragePath() . '/migration'`, then `if (!is_dir($dir)) { @mkdir($dir, 0755, true) ...}` then `is_writable` verification. Path is constrained — no user input. |
| 16 | Doctor refuses to run when CRAFT_ENVIRONMENT=production (FND-04 enforcement)                                       | VERIFIED   | `actionIndex` first non-comment statement is `if (($gate = $this->enforceNeverProduction()) !== null) return $gate;` — confirmed by line-ordering inspection. |
| 17 | Anthropic key check reports presence ONLY (T-1-03 mitigation)                                                      | VERIFIED   | `checkApiKey` reduces both Settings + env values to `$hasKey` boolean and uses static literal output strings. Grep confirms no `stdout.*$fromSettings`/`stdout.*$fromEnv`/`echo.*anthropicApiKey` paths. |
| 18 | PHPUnit 11 is wired under `tests/` and runs via `composer test` (FND-05)                                           | VERIFIED   | `composer test` exits 0 (`PHPUnit 11.5.55`); 3 tests, 3 assertions, all pass. `phpunit.xml.dist` declares `bootstrap="tests/bootstrap.php"` + `<directory>tests</directory>`. |
| 19 | Test suite is non-empty on day one — PluginBootstrapTest asserts Plugin/Settings/LegacyDbService autoload (D-21)   | VERIFIED   | `tests/PluginBootstrapTest.php` declares 3 methods: `testPluginClassIsLoadable`, `testKeyServiceClassesAreLoadable`, `testPluginDeclaresLegacyDbServiceComponent`. Test runs green. |
| 20 | GitHub Actions runs validate + install + test on every push and pull_request (D-22)                                | VERIFIED   | `.github/workflows/ci.yml` parses as valid YAML; declares single job `test` on `runs-on: ubuntu-latest`, PHP 8.3, four steps: checkout, setup-php, `composer validate --strict --no-plugins`, `composer install --no-interaction --no-progress`, `composer test`. No Deptrac, no FQCN-lint, no matrix expansion. |
| 21 | README.md ships operator minimum (install + env vars + doctor invocation)                                          | VERIFIED   | Top-level title, `composer require lameco/craft-kunstmaan-migrator`, all 8 env var names listed, `./craft kunstmaan-migrator/doctor` invocation present, production-safety mention present, does NOT promise Phase 2-5 commands as available. |
| 22 | Project documentation is internally consistent (FND-02 schema, CONN-03 deferral, PROJECT.md Key Decisions)         | VERIFIED   | Old wrong column list (`legacy_class, legacy_id, craft_id, migrated_at, status`) absent from both REQUIREMENTS.md and PROJECT.md; new column list present in both; `mapping-file validity check ships in Phase 2` + `deferred per D-17` present in CONN-03. |

**Score:** 22/22 supporting truths verified statically. (See ROADMAP success-criteria summary below — all 5 SCs are structurally satisfied with runtime confirmation deferred to human UAT items.)

### Required Artifacts

| Artifact                                | Expected                                                                          | Status     | Details |
| --------------------------------------- | --------------------------------------------------------------------------------- | ---------- | ------- |
| `composer.json`                         | Manifest, type=craft-plugin, PSR-4 autoload, extra block                          | VERIFIED   | Validates strict; all required fields present; SEOmatic/Retour in `suggest` (NOT require); deptrac/rector absent. |
| `src/Plugin.php`                        | Plugin entrypoint with init() + config() + createSettingsModel() + settingsHtml() | VERIFIED   | All four methods present; legacyDb conditional registration + console controllerNamespace switch + Settings wiring all in place. |
| `src/NeverProductionTrait.php`          | Production-environment guard                                                      | VERIFIED   | 19 lines; `enforceNeverProduction(): ?int`; gates on `CRAFT_ENVIRONMENT === 'production'`. |
| `src/models/Settings.php`               | Settings model with env-fallback resolution                                       | VERIFIED   | All 16 properties (8 read-active + 8 declared); env-var fallback in `init()`; `EnvAttributeParserBehavior` wired; no secret leaks. |
| `src/db/LegacyDbService.php`            | Yii Component with 5 read-only methods                                            | VERIFIED   | Extends `yii\base\Component`; 5 methods present; D-13 read-only discipline confirmed by grep. |
| `src/migrations/Install.php`            | Install migration (state table + kunstmaanSourceId field with UID-reuse)          | VERIFIED   | All 3 constants; D-06 schema verbatim (10 cols + 2 indexes); D-09 three-step UID-reuse chain; safeDown is no-op. |
| `src/console/MigrateController.php`     | Console controller exposing migrate/install                                       | VERIFIED   | Only `actionInstall` action; uses NeverProductionTrait; gates first; MigrationManager wired correctly. Dropped Phase-3 actions confirmed absent. |
| `src/console/DoctorController.php`      | Doctor console controller — 3 preflight checks + plain-text OK/FAIL output        | VERIFIED   | 4 expected methods present; 2 dropped methods (`checkQueueWorker`, `checkMapping`) confirmed absent; gates on enforceNeverProduction first. |
| `src/templates/_settings.twig`          | Placeholder CP Settings template                                                  | VERIFIED   | Phase 4 marker present; env var names listed (no values); `settings.legacyDbPassword`/`settings.anthropicApiKey` echo paths absent. |
| `phpunit.xml.dist`                      | PHPUnit 11 configuration                                                          | VERIFIED   | bootstrap, testsuite directory, requireCoverageMetadata=false, cacheDirectory=.phpunit.cache. |
| `tests/bootstrap.php`                   | Minimal autoloader bootstrap                                                      | VERIFIED   | 3 lines; loads vendor/autoload.php. |
| `tests/PluginBootstrapTest.php`         | Non-empty smoke test                                                              | VERIFIED   | `final class PluginBootstrapTest`; 3 test methods; runs green. |
| `.github/workflows/ci.yml`              | Single-job CI workflow                                                            | VERIFIED   | Valid YAML; one job `test`; PHP 8.3; 3 commands. |
| `README.md`                             | Operator-facing minimum doc                                                       | VERIFIED   | All required content; no over-promising. |
| `.gitignore`                            | Excludes vendor/, composer.lock, .phpunit.cache, storage/                         | VERIFIED   | All four entries present. |

### Key Link Verification

| From                                  | To                                            | Via                                                                                       | Status     |
| ------------------------------------- | --------------------------------------------- | ----------------------------------------------------------------------------------------- | ---------- |
| `composer.json` extra.class           | `src/Plugin.php`                              | `lameco\\kunstmaanmigrator\\Plugin` FQCN + PSR-4 autoload                                 | WIRED      |
| `src/Plugin.php` `config()`           | `src/db/LegacyDbService.php`                  | `'legacyDbService' => LegacyDbService::class` in components array                         | WIRED      |
| `src/Plugin.php` `createSettingsModel`| `src/models/Settings.php`                     | `return new Settings();`                                                                  | WIRED      |
| `src/Plugin.php` `init()`             | Yii application 'legacyDb' component          | `Craft::$app->set('legacyDb', [...])` inside `if (!Craft::$app->has('legacyDb', true))`   | WIRED      |
| `src/db/LegacyDbService.php`          | Yii application 'legacyDb' component          | `Craft::$app->get('legacyDb')` per call                                                   | WIRED      |
| `src/console/MigrateController.php`   | `src/migrations/Install.php`                  | MigrationManager `migrationNamespace='lameco\kunstmaanmigrator\migrations'` + path        | WIRED      |
| `src/console/DoctorController.php`    | `src/db/LegacyDbService.php`                  | `Plugin::getInstance()->legacyDbService->queryOne('SELECT 1 AS ok')`                      | WIRED      |
| `src/console/DoctorController.php`    | `src/models/Settings.php`                     | `Plugin::getInstance()->getSettings()->anthropicApiKey`                                   | WIRED      |
| `src/console/DoctorController.php`    | `src/NeverProductionTrait.php`                | `use NeverProductionTrait` + `enforceNeverProduction()` guard                             | WIRED      |
| `src/console/MigrateController.php`   | `src/NeverProductionTrait.php`                | `use NeverProductionTrait` + `enforceNeverProduction()` guard                             | WIRED      |
| `phpunit.xml.dist`                    | `tests/bootstrap.php`                         | `bootstrap="tests/bootstrap.php"` attribute                                               | WIRED      |
| `composer.json` scripts.test          | `phpunit.xml.dist`                            | `vendor/bin/phpunit` auto-discovers config                                                | WIRED      |
| `.github/workflows/ci.yml`            | `composer.json` scripts.test                  | `run: composer test` step                                                                 | WIRED      |
| `src/migrations/Install.php`          | Craft project-config kunstmaanSourceIdFieldUid| `$projectConfig->get/set(self::PROJECT_CONFIG_UID_PATH, ...)`                             | WIRED      |
| `src/migrations/Install.php`          | `kunstmaanSourceId` field handle              | `Craft::$app->fields->getFieldByHandle('kunstmaanSourceId')`                              | WIRED      |

### Data-Flow Trace (Level 4)

Phase 1 ships no UI components rendering dynamic data. The CP Settings placeholder template renders only static env-var NAMES (no settings values flow through it). Data-flow trace is N/A for Phase 1; runtime data-flow validation (legacy DB → doctor SELECT 1, ANTHROPIC_API_KEY → presence check, Install migration → DB schema) requires a Craft host and is captured in the human_verification items above.

### Behavioral Spot-Checks

| Behavior                                          | Command                                                                                               | Result                                                                            | Status |
| ------------------------------------------------- | ----------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------- | ------ |
| composer.json validates strict                    | `composer validate --strict --no-plugins`                                                             | `./composer.json is valid`                                                        | PASS   |
| PHPUnit suite runs green and is non-empty         | `composer test`                                                                                       | `OK (3 tests, 3 assertions)`; testdox output lists 3 named tests                  | PASS   |
| All 7 Phase-1 FQCNs autoload via PSR-4            | `php -r 'require vendor/autoload.php; class_exists/trait_exists for each FQCN'`                       | All 7 OK                                                                          | PASS   |
| Plugin reflection: schemaVersion + hasCpSettings  | Reflection probe on `lameco\kunstmaanmigrator\Plugin`                                                 | `schemaVersion='1.0.0'`, `hasCpSettings=true`, all 4 expected methods present     | PASS   |
| Install constants reflect D-09 contract           | Reflection probe on `Install::FIELD_HANDLE/STATE_TABLE/PROJECT_CONFIG_UID_PATH`                       | All three match expected values byte-for-byte                                     | PASS   |
| Doctor declares 3 checks + 0 dropped checks       | Reflection probe on `DoctorController` for 4 expected + 2 absent methods                              | All 4 present (`actionIndex`,`checkLegacyDb`,`checkApiKey`,`checkStorageDir`); both `checkQueueWorker` and `checkMapping` absent | PASS   |
| Migrate ships only actionInstall (Phase-3 absent) | Reflection probe on `MigrateController` for `actionInstall` + 5 absent action methods                 | `actionInstall` present; `actionExtract/Transform/Load/Finalize/Truncate` absent  | PASS   |
| LegacyDbService is read-only (D-13)               | `grep -nE 'insert\(|update\(|delete\(|batchInsert\(' src/db/LegacyDbService.php`                      | (no matches)                                                                      | PASS   |
| Settings exposes 16 properties (D-15)             | Reflection probe on `Settings` for all 16 expected property names                                     | All 16 present                                                                    | PASS   |
| LegacyDbService exposes 5 read-only methods       | Reflection probe on `LegacyDbService` for `db`,`queryOne`,`queryAll`,`queryScalar`,`streamQuery`      | All 5 present                                                                     | PASS   |
| CI YAML parses as valid YAML                      | `php -r 'Symfony\Component\Yaml\Yaml::parseFile(.github/workflows/ci.yml)'`                           | `YAML valid; jobs: test`                                                          | PASS   |
| NeverProductionTrait shape                        | Reflection probe — visibility, return type, env check, ExitCode usage                                 | `protected`, `?int`, contains `CRAFT_ENVIRONMENT === 'production'` + `ExitCode::UNSPECIFIED_ERROR` | PASS   |
| Both controllers gate `enforceNeverProduction` first | AWK first-8-lines check on both controller actions                                                 | Confirmed first-statement guard in both `actionIndex` and `actionInstall`         | PASS   |

### Requirements Coverage

| Requirement | Source Plan | Description (abbreviated)                                                | Status     | Evidence                                                                                     |
| ----------- | ----------- | ------------------------------------------------------------------------ | ---------- | -------------------------------------------------------------------------------------------- |
| FND-01      | 01-01       | Plugin scaffolds as Craft 5 plugin (composer + Plugin stub + trait)      | SATISFIED  | composer.json valid + Plugin/NeverProductionTrait autoload (truths 1, 2, 3).                |
| FND-02      | 01-03       | Install creates state table + kunstmaanSourceId field; UID-reuse         | SATISFIED  | Install.php declares D-06 schema + D-09 reuse chain (truths 8, 9). Runtime install deferred to human UAT. |
| FND-02a     | 01-03       | Programmatic install command runs plugin's DB migrations on demand       | SATISFIED  | MigrateController::actionInstall wires MigrationManager correctly (truth 12). Runtime exec deferred to human UAT. |
| FND-03      | 01-03       | Uninstall is deliberate no-op on state table + field                     | SATISFIED  | Install::safeDown returns true unconditionally; AWK-scoped grep confirms no destructive calls (truth 11). |
| FND-04      | 01-04       | NeverProductionTrait hard-blocks every legacy-reading/destructive command | SATISFIED  | Both Phase-1 controller actions gate first on enforceNeverProduction; trait returns ExitCode::UNSPECIFIED_ERROR for production env (truths 16, runtime confirmation deferred to human UAT). |
| FND-05      | 01-05       | PHPUnit 11 suite scaffolded under tests/, composer test, CI workflow      | SATISFIED  | composer test exits 0 with 3/3 assertions; phpunit.xml.dist + bootstrap + smoke test all in place; CI YAML parses (truths 18, 19, 20). |
| CONN-01     | 01-02       | Plugin owns legacy DB connection internally (no Yii component required)  | SATISFIED  | Plugin::init conditional Craft::$app->set('legacyDb', [...]) (truth 4).                     |
| CONN-02     | 01-02       | Anthropic API key from ANTHROPIC_API_KEY env or Settings; never logged   | SATISFIED  | Settings::init resolves env via `??=`; no secret echo paths anywhere (truth 5).             |
| CONN-03     | 01-04       | Doctor reports OK/FAIL on 3 checks (mapping deferred per D-17 amendment) | SATISFIED  | DoctorController declares all 3 expected check methods; CONN-03 wording in REQUIREMENTS.md updated to acknowledge deferral (truths 13, 14, 15, 22). |

All 9 phase requirements are marked `[x]` in REQUIREMENTS.md and satisfied structurally. No requirements are ORPHANED — every ID in REQUIREMENTS.md mapped to Phase 1 was claimed by a plan in the phase, and every plan's `requirements:` field references at least one of the 9 IDs.

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
| ---- | ---- | ------- | -------- | ------ |
| (none) | — | — | — | — |

Anti-pattern scans run:
- TODO/FIXME/HACK/PLACEHOLDER comments in `src/`: none found.
- Secret-leak patterns in Settings.php / DoctorController.php / _settings.twig: none.
- Write-op patterns in LegacyDbService.php (D-13 discipline): none.
- Destructive operations in Install::safeDown (FND-03 discipline): none.
- Phase-3 controller actions leaking into Phase-1 MigrateController: none.
- Dropped Doctor methods (checkQueueWorker, checkMapping) leaking back: none.
- Old wrong column list in REQUIREMENTS.md / PROJECT.md: removed.

### Human Verification Required

See `human_verification:` in the YAML frontmatter for the 6 structured items the orchestrator should persist to a HUMAN-UAT.md. Each is keyed to a ROADMAP success criterion or a phase-level invariant that can only be confirmed against a Craft 5 host (and, for the v1.x→v2 swap-in, a host with v1.x-installed state). Summary:

1. `./craft plugin/install kunstmaan-migrator` on a stock Craft 5 host (SC1 + SC2).
2. `./craft kunstmaan-migrator/doctor` happy path + each of the three failure modes (SC3 + CONN-03).
3. `./craft kunstmaan-migrator/migrate/install` run twice — second is a no-op (FND-02a + D-07).
4. v1.x → v2 swap-in on a host with existing kunstmaanSourceId field UID + ~570-row state table (SC2 second sentence — the headline correctness gate of the v2 rewrite).
5. `CRAFT_ENVIRONMENT=production` refusal across both doctor + migrate/install (SC4 + FND-04).
6. GitHub Actions CI run on the next push to confirm parity with the local green suite (SC5 second clause).

### Gaps Summary

No gaps. All 22 supporting truths verified; all 15 artifacts exist + substantive + wired; all 15 key links wired; all 9 phase requirements satisfied; zero anti-patterns. The only items left are the 6 runtime-host UAT verifications in `human_verification:` — these are EXPECTED Phase-1 outcomes per the verification task brief (Phase 1 ships a Craft plugin library, not a runnable dev server) and do not represent failures of the plans.

### ROADMAP Success-Criteria Map

| #  | ROADMAP SC                                                                                                                                                                  | Structural Status | Runtime Confirmation         |
| -- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------- | ---------------------------- |
| 1  | `composer require lameco/craft-kunstmaan-migrator` + `./craft plugin/install kunstmaan-migrator` succeeds on stock Craft 5 install with no SEOmatic/Retour deps             | VERIFIED          | Human item #1                |
| 2  | State table `kunstmaanmigrator_state` + `kunstmaanSourceId` field exist after install; v1.x UID is reused if present                                                        | VERIFIED          | Human items #1, #4           |
| 3  | `./craft kunstmaan-migrator/doctor` reports OK/FAIL for 3 checks (CONN-03 amended); exits non-zero on any FAIL                                                              | VERIFIED          | Human item #2                |
| 4  | `CRAFT_ENVIRONMENT=production` causes legacy-reading commands to refuse                                                                                                     | VERIFIED          | Human item #5                |
| 5  | `composer test` runs and is green; CI workflow runs the same on push                                                                                                        | VERIFIED locally  | Human item #6 (next push)    |

### Follow-ups

- **Code-review gate skipped this run.** This verification was chained from the auto-mode orchestrator under `--auto --no-transition`, which bypassed the optional `/gsd-code-review 1` step. The user should run `/gsd-code-review 1` manually before tagging Phase 1 as truly closed — verification confirms structural correctness and goal achievement, but does not substitute for the multi-rule code-review pass (security, style, architecture-fit anti-patterns the verifier doesn't enumerate).
- **PHP 8.5 deprecation in vendor.** `craft\console\Controller::output(): Implicitly marking parameter $string as nullable is deprecated`. This is in `craftcms/cms` itself, not the plugin. Tracked as an upstream item; CI runs PHP 8.3 so it does not affect the green suite.
- **Vendor composer.lock note.** `.gitignore` excludes `composer.lock` (correct for a library/plugin per Composer's official guidance), so consumer hosts will resolve transitive deps freshly. No action needed.

---

_Verified: 2026-04-25_
_Verifier: Claude (gsd-verifier, Opus 4.7 1M context)_
