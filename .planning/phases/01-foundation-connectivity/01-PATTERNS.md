# Phase 1: Foundation & Connectivity - Pattern Map

**Mapped:** 2026-04-25
**Files analyzed:** 12 (10 net-new src/test/CI files + composer.json + phpunit.xml)
**Analogs found:** 9 / 12 (3 greenfield: storage-dir check, env-fallback Settings, CI workflow)

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|-------------------|------|-----------|----------------|---------------|
| `src/Plugin.php` | bootstrap / plugin entry | event-driven + DI wiring | `~/Sites/craft-kunstmaan-migrator/src/Plugin.php` | exact (heavily trimmed) |
| `src/NeverProductionTrait.php` | trait / guard | request-response | `~/Sites/craft-kunstmaan-migrator/src/NeverProductionTrait.php` | exact (port verbatim) |
| `src/console/DoctorController.php` | console controller | request-response | `~/Sites/craft-kunstmaan-migrator/src/bridge/console/controllers/DoctorController.php` | exact (subset port) |
| `src/console/MigrateController.php` | console controller | request-response | `~/Sites/craft-kunstmaan-migrator/src/bridge/console/controllers/MigrateController.php::actionInstall` | exact (single-action subset) |
| `src/db/LegacyDbService.php` | service (Yii Component) | streaming + request-response | `~/Sites/craft-kunstmaan-migrator/src/kunstmaan/db/LegacyDbService.php` | exact (5-method subset) |
| `src/migrations/Install.php` | migration | schema + project-config | `~/Sites/craft-kunstmaan-migrator/src/craft/migrations/Install.php` | exact (port verbatim with namespace updates) |
| `src/models/Settings.php` | model (Craft Model) | config | `~/Sites/craft-kunstmaan-migrator/src/models/Settings.php` | role-match (greenfield expansion of env-fallback) |
| `composer.json` | manifest | n/a | `~/Sites/craft-kunstmaan-migrator/composer.json` | exact (with diffs per D-24/D-25) |
| `tests/PluginBootstrapTest.php` | test | n/a | `~/Sites/craft-kunstmaan-migrator/tests/unit/PluginBootstrapTest.php` | role-match (drastically trimmed) |
| `tests/bootstrap.php` | test bootstrap | n/a | `~/Sites/craft-kunstmaan-migrator/tests/bootstrap.php` | exact (4-line port) |
| `phpunit.xml` (or `.dist`) | config | n/a | `~/Sites/craft-kunstmaan-migrator/phpunit.xml` | exact |
| `.github/workflows/ci.yml` | CI | n/a | `~/Sites/craft-kunstmaan-migrator/.github/workflows/ci.yml` | role-match (drastically trimmed) |

---

## Pattern Assignments

### `src/Plugin.php` (bootstrap / plugin entry)

**Analog:** `~/Sites/craft-kunstmaan-migrator/src/Plugin.php` — adapt heavily. v1's 443-line Plugin.php is bloated with Phase 2-4 service wiring. Phase 1's Plugin.php is small (~80-100 lines): single component (`legacyDbService`), conditional `legacyDb` Yii-component registration, controllerNamespace switch, settings model + placeholder `settingsHtml()`.

**Class skeleton + property pattern** (v1 lines 107-118 — keep `schemaVersion`, `hasCpSettings`; **drop** `migratorRebound` since v2 does NOT need a `getMigrator()` override — Phase 1 ships `src/migrations/` at the framework default path):

```php
class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.0';   // D-08 — v2 declares 1.0.0, NOT v1's 2.0.0
    public bool $hasCpSettings = true;
}
```

**Component declaration** (v1 lines 120-157 — Phase 1 keeps **only** `legacyDbService`):

```php
public static function config(): array
{
    return [
        'components' => [
            'legacyDbService' => LegacyDbService::class,
            // Phase 2-4 components land in later phases.
        ],
    ];
}
```

**`init()` skeleton** (v1 lines 186-426 — Phase 1 strips this to: legacyDb registration + controllerNamespace switch + parent::init()):

```php
public function init(): void
{
    parent::init();

    // D-11 — register legacyDb Yii component only when host hasn't already declared one.
    // On v1.x swap-in hosts the existing `config/app.php` `legacyDb` wins (zero churn);
    // on greenfield hosts the plugin fills the gap from env vars / Settings.
    if (!Craft::$app->has('legacyDb', true)) {
        $settings = $this->getSettings();
        Craft::$app->set('legacyDb', [
            'class'       => \yii\db\Connection::class,
            'dsn'         => sprintf(
                'mysql:host=%s;port=%d;dbname=%s',
                $settings->legacyDbServer,
                $settings->legacyDbPort,
                $settings->legacyDbDatabase,
            ),
            'username'    => $settings->legacyDbUser,
            'password'    => $settings->legacyDbPassword,
            'charset'     => $settings->legacyDbCharset,
            'tablePrefix' => $settings->legacyDbTablePrefix,
            'attributes'  => [\PDO::ATTR_EMULATE_PREPARES => false],
        ]);
    }

    // D-03 — single console controllerNamespace; web namespace deferred to Phase 4.
    if (Craft::$app->request->getIsConsoleRequest()) {
        $this->controllerNamespace = 'lameco\\kunstmaanmigrator\\console';
    }
}
```

> **Note on web controller namespace:** v1 lines 401-405 set both console + web namespaces. Phase 1 has no web controllers (CP Settings deferred to Phase 4 / CFG-01), so omit the web branch. Add it when Phase 4 introduces the Settings save handler.

**Settings model wiring** (v1 lines 428-442 — port verbatim, swap template path):

```php
protected function createSettingsModel(): ?Model
{
    return new Settings();
}

protected function settingsHtml(): ?string
{
    return Craft::$app->view->renderTemplate(
        'kunstmaan-migrator/_settings.twig',
        ['plugin' => $this, 'settings' => $this->getSettings()],
    );
}
```

> **D-16:** Phase 1's `_settings.twig` is a placeholder (see Claude's Discretion in CONTEXT.md). The real form lives in Phase 4.

**Property-read docblock** (v1 lines 64-93 — Phase 1's docblock is one line):

```php
/** @property-read LegacyDbService $legacyDbService */
```

**Drop entirely from v1's Plugin.php:**
- All Phase 2-4 component wirings (lines 130-376): `mappingLoader`, `extractService`, `transformService`, `assetMigrationService`, `seoMigrationService`, etc.
- `getMigrator()` override (lines 172-184): v2 ships migrations at `src/migrations/` — the framework default path — so no rebind is needed (v1 needed it because the brownfield moved migrations to `src/craft/migrations/`).
- `migratorRebound` flag (line 118).
- `Plugins::EVENT_AFTER_SAVE_PLUGIN_SETTINGS` hook (lines 412-425): wired by Phase 4 / CFG-01 once the settings page exists.
- `Utilities::EVENT_REGISTER_UTILITIES` (lines 379-385): no CP utility in v2 (CLI-only — PROJECT.md Key Decisions).
- `View::EVENT_REGISTER_CP_TEMPLATE_ROOTS` (lines 390-396): only needed when CP templates beyond `_settings.twig` exist; default plugin template root resolution covers settingsHtml().

---

### `src/NeverProductionTrait.php` (trait / guard)

**Analog:** `~/Sites/craft-kunstmaan-migrator/src/NeverProductionTrait.php`

**Port verbatim** (D-23). The file is 39 lines of perfectly fit-for-purpose code. Update only the namespace declaration (`lameco\kunstmaanmigrator` is unchanged) — no other edits needed.

**Full file** (port byte-for-byte):

```php
<?php

namespace lameco\kunstmaanmigrator;

use craft\helpers\App;
use craft\helpers\Console;
use yii\console\ExitCode;

trait NeverProductionTrait
{
    protected function enforceNeverProduction(): ?int
    {
        if (App::env('CRAFT_ENVIRONMENT') === 'production') {
            $this->stderr("Refusing to run against CRAFT_ENVIRONMENT=production\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        return null;
    }
}
```

**Caller pattern** (used by every controller action):
```php
public function actionFoo(): int
{
    if (($gate = $this->enforceNeverProduction()) !== null) {
        return $gate;
    }
    // ... action body
}
```

---

### `src/console/DoctorController.php` (console controller)

**Analog:** `~/Sites/craft-kunstmaan-migrator/src/bridge/console/controllers/DoctorController.php`

**Imports + class declaration** (v1 lines 1-17 — strip the `Queue` and `PingJob` imports per D-19; namespace becomes `lameco\kunstmaanmigrator\console`):

```php
<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\console;

use lameco\kunstmaanmigrator\NeverProductionTrait;
use lameco\kunstmaanmigrator\Plugin;
use Craft;
use craft\console\Controller;
use craft\helpers\App;
use craft\helpers\Console;
use Throwable;
use yii\console\ExitCode;

class DoctorController extends Controller
{
    use NeverProductionTrait;
```

**`actionIndex` orchestrator** (v1 lines 38-60 — drop the queue + mapping check rows per D-17/D-19):

```php
public function actionIndex(): int
{
    if (($gate = $this->enforceNeverProduction()) !== null) {
        return $gate;
    }

    $this->stdout("Doctor: preflight diagnostics\n", Console::FG_CYAN);

    $ok = true;
    // `&&` against $ok so every check still executes even after a failure;
    // operators want the full report, not a short-circuited tail.
    $ok = $this->checkLegacyDb()    && $ok;
    $ok = $this->checkApiKey()      && $ok;
    $ok = $this->checkStorageDir()  && $ok;

    $this->stdout(
        "\n" . ($ok ? "Doctor: PASS\n" : "Doctor: FAIL — fix the above before running migrate\n"),
        $ok ? Console::FG_GREEN : Console::FG_RED,
    );

    return $ok ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
}
```

**`checkLegacyDb()` — port verbatim** (v1 lines 105-115):

```php
private function checkLegacyDb(): bool
{
    try {
        Plugin::getInstance()->legacyDbService->queryOne('SELECT 1 AS ok');
        $this->stdout("  OK   legacyDb reachable\n", Console::FG_GREEN);
        return true;
    } catch (Throwable $e) {
        $this->stderr("  FAIL legacyDb unreachable: {$e->getMessage()}\n", Console::FG_RED);
        return false;
    }
}
```

**`checkApiKey()` — port verbatim, but with Settings-override fallback per D-14** (v1 lines 122-134 reads env-only; v2 must check Settings first since CP override is a v2 feature per D-15):

```php
private function checkApiKey(): bool
{
    // D-14: prefer Settings override, fall back to env. Never echo or log the value.
    $fromSettings = (string) (Plugin::getInstance()->getSettings()->anthropicApiKey ?? '');
    $fromEnv      = (string) (App::env('ANTHROPIC_API_KEY') ?? '');
    $hasKey = $fromSettings !== '' || $fromEnv !== '';

    if ($hasKey) {
        $this->stdout("  OK   ANTHROPIC_API_KEY set\n", Console::FG_GREEN);
        return true;
    }
    $this->stderr(
        "  FAIL ANTHROPIC_API_KEY missing — set in .env or plugin Settings (analyze will fail without it).\n",
        Console::FG_RED,
    );
    return false;
}
```

**`checkStorageDir()` — greenfield** (D-18 — auto-create `storage/migration/` then verify writable):

```php
/**
 * D-18: ensure storage/migration/ exists and is writable.
 * Auto-creates the directory under Craft's storage tree (one less manual op step).
 */
private function checkStorageDir(): bool
{
    $dir = Craft::$app->path->getStoragePath() . '/migration';
    try {
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
                $this->stderr("  FAIL could not create {$dir}\n", Console::FG_RED);
                return false;
            }
        }
        if (!is_writable($dir)) {
            $this->stderr("  FAIL {$dir} not writable\n", Console::FG_RED);
            return false;
        }
        $this->stdout("  OK   storage/migration writable ({$dir})\n", Console::FG_GREEN);
        return true;
    } catch (Throwable $e) {
        $this->stderr("  FAIL storage check error: {$e->getMessage()}\n", Console::FG_RED);
        return false;
    }
}
```

**Drop entirely from v1:**
- `checkQueueWorker()` (lines 67-97) and `use craft\queue\Queue` + `use lameco\kunstmaanmigrator\craft\queue\PingJob` imports (D-19, PROJECT.md Key Decisions).
- `checkMapping()` (lines 141-163) — defers to Phase 2 alongside the mapping loader (D-17).

---

### `src/console/MigrateController.php` (console controller — Phase 1 = `actionInstall` only)

**Analog:** `~/Sites/craft-kunstmaan-migrator/src/bridge/console/controllers/MigrateController.php::actionInstall` (lines 342-364)

**Imports + class declaration** (mirror v1 lines 3-46, but Phase 1 drops everything except install — strip queue/Entry/MigrationOptions/MigrationReport imports):

```php
<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\console;

use lameco\kunstmaanmigrator\NeverProductionTrait;
use Craft;
use craft\console\Controller;
use craft\db\MigrationManager;
use craft\helpers\Console;
use yii\console\ExitCode;

/**
 * Migrate command — Phase 1 ships only actionInstall (programmatic install shim).
 * extract / transform / load / finalize actions land in Phase 3.
 */
class MigrateController extends Controller
{
    use NeverProductionTrait;
```

**`actionInstall()` — port from v1 lines 342-364, updating namespaces to v2's flat layout:**

```php
/**
 * Idempotent re-runner for the plugin's own migrations
 * (`./craft plugin/install` already runs Install.php on first install;
 * this action is the post-install / schema-bump shim).
 */
public function actionInstall(): int
{
    if (($gate = $this->enforceNeverProduction()) !== null) {
        return $gate;
    }

    $manager = Craft::createObject([
        'class'              => MigrationManager::class,
        'track'              => 'kunstmaanmigrator',
        'migrationNamespace' => 'lameco\\kunstmaanmigrator\\migrations',
        'migrationPath'      => __DIR__ . '/../migrations',
    ]);

    $this->stdout("Installing migrator migrations...\n", Console::FG_CYAN);
    $manager->up();
    $this->stdout("  OK migrator migrations applied (track=kunstmaanmigrator)\n", Console::FG_GREEN);

    return ExitCode::OK;
}
```

> **Path delta from v1:** v1 used `__DIR__ . '/../../migrations'` because controllers were at `src/bridge/console/controllers/` (3 levels deep). v2's flat `src/console/` is 2 levels deep — so `__DIR__ . '/../migrations'` reaches `src/migrations/`.
>
> **Namespace delta from v1:** v1's `migrationNamespace` was `lameco\\kunstmaanmigrator\\migrations` (it pointed to a sibling track that no longer exists post-Wave-3). v2's same namespace string now correctly points at `src/migrations/` since v2 doesn't use the brownfield's `craft\\migrations` sub-tier.

---

### `src/db/LegacyDbService.php` (service — Yii Component)

**Analog:** `~/Sites/craft-kunstmaan-migrator/src/kunstmaan/db/LegacyDbService.php`

**Phase 1 keeps only 5 methods** (per CONTEXT.md `<canonical_refs>`): `db()`, `queryOne()`, `queryAll()`, `queryScalar()`, `streamQuery()`. Drop the domain helpers (`streamLiveNodes`, `translationsFor`, `pagePartsFor`, `seoFor`, `mediaById`, `redirects`, `extTranslationsFor`, `getDatabaseName`) — Phases 2-4 add them back.

**Imports + class declaration** (v1 lines 1-23 — drop the `KunstmaanCoreTables` import since the domain helpers ship later):

```php
<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\db;

use Craft;
use Generator;
use yii\base\Component;
use yii\db\Connection;

/**
 * Read-only accessor for the legacy Kunstmaan MySQL DB.
 *
 * Discipline: **no writes**. Code review enforces no `->insert(`, `->update(`,
 * or `->delete(` ever appears in this file (D-13).
 */
class LegacyDbService extends Component
{
```

**`db()` — port verbatim** (v1 lines 31-36; the `Craft::$app->get('legacyDb')` resolution is unchanged because Phase 1 still uses the same Yii application component, just registered by `Plugin::init()` instead of `config/app.php`):

```php
public function db(): Connection
{
    /** @var Connection $conn */
    $conn = Craft::$app->get('legacyDb');
    return $conn;
}
```

**`queryOne` / `queryAll` / `queryScalar` — port verbatim** (v1 lines 38-69):

```php
/** @param array<string, mixed> $params */
public function queryOne(string $sql, array $params = []): ?array
{
    $row = $this->db()->createCommand($sql, $params)->queryOne();
    return $row ?: null;
}

/**
 * @param array<string, mixed> $params
 * @return array<int, array<string, mixed>>
 */
public function queryAll(string $sql, array $params = []): array
{
    return $this->db()->createCommand($sql, $params)->queryAll();
}

/** @param array<string, mixed> $params */
public function queryScalar(string $sql, array $params = []): mixed
{
    return $this->db()->createCommand($sql, $params)->queryScalar();
}
```

**`streamQuery` — port verbatim** (v1 lines 78-88 — generator with `try/finally` close on the PDO reader):

```php
/**
 * @param array<string, mixed> $params
 * @return Generator<int, array<string, mixed>>
 */
public function streamQuery(string $sql, array $params = []): Generator
{
    $reader = $this->db()->createCommand($sql, $params)->query();
    try {
        foreach ($reader as $row) {
            yield $row;
        }
    } finally {
        $reader->close();
    }
}
```

> **Discretion (D-15 footnote):** v1 makes this a Yii `Component` so `Craft::$app->get('legacyDb')` resolves on every call (test-double-friendly). Keep that shape — it's the established pattern. Constructor-injection would force `Plugin::config()` rewiring on every test.

---

### `src/migrations/Install.php` (migration)

**Analog:** `~/Sites/craft-kunstmaan-migrator/src/craft/migrations/Install.php`

**Port verbatim with namespace + comment trims.** Schema is byte-for-byte the v1 schema per D-06. UID-reuse logic per D-09 is the central correctness gate (570-row CQM continuity).

**Class header + constants** (v1 lines 1-33 — update namespace from `craft\migrations` to `migrations`, retain constants verbatim):

```php
<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\migrations;

use Craft;
use craft\db\Migration;
use craft\fields\PlainText;
use craft\helpers\StringHelper;

class Install extends Migration
{
    public const FIELD_HANDLE = 'kunstmaanSourceId';
    public const STATE_TABLE = '{{%kunstmaanmigrator_state}}';
    public const PROJECT_CONFIG_UID_PATH = 'plugins.kunstmaan-migrator.kunstmaanSourceIdFieldUid';
```

**`safeUp` / `safeDown` — port verbatim** (v1 lines 35-48; D-10 — `safeDown` is intentional no-op):

```php
public function safeUp(): bool
{
    $this->ensureStateTable();
    $this->ensureFieldAndAttach();
    return true;
}

// D-10: uninstall PRESERVES state table and field — operator wipes manually for full reset.
public function safeDown(): bool
{
    return true;
}
```

**`ensureStateTable` — port verbatim** (v1 lines 50-93 — the `tableExists` guard is the idempotency contract):

```php
private function ensureStateTable(): void
{
    if ($this->db->tableExists(self::STATE_TABLE)) {
        // D-07 idempotency: prior install (or v1.x) already created the table.
        // Leave it alone; row-level data must survive re-install.
        return;
    }

    $this->createTable(self::STATE_TABLE, [
        'id'          => $this->primaryKey(),
        'source'      => $this->string(64)->notNull(),
        'sourceKey'   => $this->string(255)->notNull(),
        'targetType'  => $this->string(64)->notNull(),
        'targetId'    => $this->integer(),
        'targetUid'   => $this->uid(),
        'siteId'      => $this->integer()->null(),
        'meta'        => $this->json()->null(),
        'dateCreated' => $this->dateTime()->notNull(),
        'dateUpdated' => $this->dateTime()->notNull(),
    ]);

    $this->createIndex(null, self::STATE_TABLE, ['source', 'sourceKey', 'siteId'], true);
    $this->createIndex(null, self::STATE_TABLE, ['dateUpdated'], false);
}
```

**`ensureFieldAndAttach` — port verbatim** (v1 lines 95-158 — the project-config-then-getFieldByHandle lookup chain is the 570-row correctness gate per D-09. Drop only the v1-specific Plan-05 reference comment):

```php
private function ensureFieldAndAttach(): void
{
    $projectConfig = Craft::$app->projectConfig;

    // Forced YAML re-read (true second arg) per Craft idempotent-install guidance.
    $existingUid = $projectConfig->get(self::PROJECT_CONFIG_UID_PATH, true);

    if ($existingUid !== null) {
        // Plugin already installed against this site — UID persisted under our config path. No-op.
        return;
    }

    // D-09: before minting a new UID, check whether the site already has a field
    // with our handle (v1.x → v2 swap-in case). REUSE its UID — never replace.
    // Literal handle kept inline so grep-based UID-continuity assertions work.
    $existingField = Craft::$app->fields->getFieldByHandle('kunstmaanSourceId');

    if ($existingField !== null) {
        $projectConfig->set(self::PROJECT_CONFIG_UID_PATH, $existingField->uid);
        Craft::info(
            "kunstmaan-migrator Install: reusing existing field UID {$existingField->uid} for handle '" . self::FIELD_HANDLE . "'",
            'kunstmaan-migrator',
        );
        return;
    }

    // Greenfield Craft host — mint a new field + UID.
    $field = new PlainText([
        'name'         => 'Kunstmaan Source ID',
        'handle'       => self::FIELD_HANDLE,
        'instructions' => "Legacy Kunstmaan source identifier (format '<source>:<id>'). Used by the Kunstmaan→Craft migrator for upsert lookup. Do not edit.",
        'searchable'   => true,
        'uid'          => StringHelper::UUID(),
    ]);
    $field->charLimit = 255;

    if (!Craft::$app->fields->saveField($field)) {
        throw new \RuntimeException(
            'Failed to save kunstmaanSourceId field: ' . json_encode($field->getErrors()),
        );
    }

    $projectConfig->set(self::PROJECT_CONFIG_UID_PATH, $field->uid);
    Craft::info(
        "kunstmaan-migrator Install: minted new field UID {$field->uid} for handle '" . self::FIELD_HANDLE . "'",
        'kunstmaan-migrator',
    );
}
```

> **CONTEXT.md follow-ups (FND-02 + PROJECT.md row):** REQUIREMENTS.md FND-02 lists wrong column names (`legacy_class, legacy_id, craft_id, migrated_at, status`). When this phase ships, update both FND-02 and PROJECT.md's Key Decisions row to match the actual v1 schema (`source, sourceKey, targetType, targetId, targetUid, siteId, meta, dateCreated, dateUpdated`).

---

### `src/models/Settings.php` (model — env-fallback Settings)

**Analog:** `~/Sites/craft-kunstmaan-migrator/src/models/Settings.php` (role-match — v2 expands the surface significantly per D-15)

**Pattern from v1 to keep:**
- `craft\base\Model` extension.
- `EnvAttributeParserBehavior` for `$ENV_VAR` syntax on string fields.
- Resolved-getter helpers (e.g. `getResolvedMappingPath()` parses through `Craft::parseEnv()`).
- `rules()` declarations for type validation.

**v1 imports + class shape** (v1 lines 1-21):

```php
<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\models;

use Craft;
use craft\base\Model;
use craft\behaviors\EnvAttributeParserBehavior;
use craft\helpers\App;

class Settings extends Model
{
```

**Phase 1 fields (read-active per D-15)** — defaults pulled from env vars on access. Use nullable typed properties; resolve with App::env() in getters or rules-driven coercion:

```php
// Legacy DB connection (D-12). Defaults to CRAFT_LEGACY_DB_* env vars.
public ?string $legacyDbServer       = null;
public int     $legacyDbPort         = 3306;
public ?string $legacyDbDatabase     = null;
public ?string $legacyDbUser         = null;
public ?string $legacyDbPassword     = null;
public string  $legacyDbCharset      = 'utf8mb4';
public string  $legacyDbTablePrefix  = '';

// Anthropic key (D-14). Defaults to ANTHROPIC_API_KEY.
public ?string $anthropicApiKey = null;
```

**Phase 2-4 fields (declared upfront per D-15, unused until later phases):**

```php
public ?string $llmModel        = null;
public ?int    $llmTimeout      = null;
public ?string $mappingPath     = null;
public array   $defaultEntities = [];
public array   $defaultLocales  = [];
public ?string $defaultSince    = null;
public ?int    $defaultMaxPerEntity = null;
public bool    $dryRunDefault   = true;
```

**`behaviors()` — port v1 pattern** (v1 lines 39-47), expanded for v2's env-overridable string set:

```php
public function behaviors(): array
{
    return [
        'parser' => [
            'class' => EnvAttributeParserBehavior::class,
            'attributes' => [
                'legacyDbServer', 'legacyDbDatabase', 'legacyDbUser', 'legacyDbPassword',
                'legacyDbCharset', 'legacyDbTablePrefix',
                'anthropicApiKey',
                'llmModel', 'mappingPath', 'defaultSince',
            ],
        ],
    ];
}
```

**Env-fallback constructor pattern** (greenfield — v1 didn't do this; D-15 is new in v2):

```php
public function init(): void
{
    parent::init();

    // D-12: env-var fallback. config/kunstmaan-migrator.php overrides win when present.
    $this->legacyDbServer      ??= App::env('CRAFT_LEGACY_DB_SERVER');
    $this->legacyDbDatabase    ??= App::env('CRAFT_LEGACY_DB_DATABASE');
    $this->legacyDbUser        ??= App::env('CRAFT_LEGACY_DB_USER');
    $this->legacyDbPassword    ??= App::env('CRAFT_LEGACY_DB_PASSWORD');
    $envPort = App::env('CRAFT_LEGACY_DB_PORT');
    if ($envPort !== null && $envPort !== '') {
        $this->legacyDbPort = (int) $envPort;
    }
    $envCharset = App::env('CRAFT_LEGACY_DB_CHARSET');
    if (is_string($envCharset) && $envCharset !== '') {
        $this->legacyDbCharset = $envCharset;
    }
    $envPrefix = App::env('CRAFT_LEGACY_DB_TABLE_PREFIX');
    if (is_string($envPrefix)) {
        $this->legacyDbTablePrefix = $envPrefix;
    }
    $this->anthropicApiKey ??= App::env('ANTHROPIC_API_KEY');
}
```

**`rules()` pattern** (v1 lines 49-56 — expand to cover v2's surface):

```php
public function rules(): array
{
    return [
        [['legacyDbServer', 'legacyDbDatabase', 'legacyDbUser'], 'string'],
        [['legacyDbPort'], 'integer'],
        [['legacyDbPassword', 'legacyDbCharset', 'legacyDbTablePrefix'], 'string'],
        [['anthropicApiKey', 'llmModel', 'mappingPath', 'defaultSince'], 'string'],
        [['llmTimeout', 'defaultMaxPerEntity'], 'integer'],
        [['defaultEntities', 'defaultLocales'], 'safe'],
        [['dryRunDefault'], 'boolean'],
    ];
}
```

---

### `composer.json` (manifest)

**Analog:** `~/Sites/craft-kunstmaan-migrator/composer.json`

**Diffs from v1 per D-24/D-25:**

| Field | v1 value | v2 value | Reason |
|-------|----------|----------|--------|
| `require.php` | `>=8.2` | `^8.3` | D-24 — bump to PHP 8.3 |
| `require.nystudio107/craft-seomatic` | `^5.1` (REQUIRED) | **moved to `suggest`** | D-24 — optional adapter |
| `require.nystudio107/craft-retour` | `^5.0` (REQUIRED) | **moved to `suggest`** | D-24 — optional adapter |
| `require-dev.deptrac/deptrac` | `^4.6` | **dropped** | D-24 — three-tier layout retired |
| `require-dev.rector/rector` | `^2.4` | **dropped** | D-24 — re-add when there's a real refactor driver |
| `extra.schemaVersion` | `2.0.0` | `1.0.0` | D-08/D-25 — v2 declares 1.0.0 |
| `scripts.test` | (missing) | `vendor/bin/phpunit` | D-21 — composer test runs the suite |

**Target shape** (port v1 lines 1-58 with the diffs above):

```json
{
    "name": "lameco/craft-kunstmaan-migrator",
    "description": "Kunstmaan → Craft CMS migration plugin — knowledge-first ETL with AI-assisted mapping proposals.",
    "type": "craft-plugin",
    "license": "MIT",
    "authors": [
        { "name": "Lameco Development", "email": "development@lameco.nl" }
    ],
    "require": {
        "php": "^8.3",
        "craftcms/cms": "^5.0",
        "symfony/yaml": "^6.0 || ^7.0",
        "guzzlehttp/guzzle": "^7.0"
    },
    "suggest": {
        "nystudio107/craft-seomatic": "Enables the SEOmatic adapter (^5.1).",
        "nystudio107/craft-retour": "Enables the Retour adapter (^5.0)."
    },
    "require-dev": {
        "phpunit/phpunit": "^11.0"
    },
    "autoload": {
        "psr-4": { "lameco\\kunstmaanmigrator\\": "src/" }
    },
    "autoload-dev": {
        "psr-4": { "lameco\\kunstmaanmigrator\\tests\\": "tests/" }
    },
    "scripts": {
        "test": "vendor/bin/phpunit"
    },
    "extra": {
        "handle": "kunstmaan-migrator",
        "name": "Kunstmaan Migrator",
        "class": "lameco\\kunstmaanmigrator\\Plugin",
        "schemaVersion": "1.0.0",
        "developer": "Lameco Development"
    },
    "config": {
        "sort-packages": true,
        "allow-plugins": {
            "craftcms/plugin-installer": true,
            "yiisoft/yii2-composer": true
        }
    }
}
```

> **Drop from v1:** `post-autoload-dump` clear-caches script (v1's was tied to its CP utility; v2 doesn't need it). `lint-fqcn` script (v1-specific brownfield-tier-mismatch tool). `archive.exclude` (defer to release prep in Phase 5).

---

### `tests/PluginBootstrapTest.php` (smoke test — non-empty per D-21)

**Analog:** `~/Sites/craft-kunstmaan-migrator/tests/unit/PluginBootstrapTest.php` (role-match — v1's 188-line test enumerates 13 components + 8 queue jobs + a getMigrator regression guard; Phase 1 trims to 2-3 assertions)

**Pattern from v1 to keep:**
- `final class ... extends TestCase` shape.
- `class_exists($fqcn, true)` autoload assertions.
- `markTestSkipped` guard for "requires Craft bootstrap" tests (v1 lines 130-138).
- Reflection-based shape lint of `Plugin::config()` (v1 lines 56-70).

**Phase 1 minimal version** (asserts Plugin loads + Settings loads + LegacyDbService loads — three FQCN smoke checks):

```php
<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests;

use lameco\kunstmaanmigrator\Plugin;
use lameco\kunstmaanmigrator\db\LegacyDbService;
use lameco\kunstmaanmigrator\models\Settings;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class PluginBootstrapTest extends TestCase
{
    public function testPluginClassIsLoadable(): void
    {
        self::assertTrue(class_exists(Plugin::class, true), 'Plugin must autoload via PSR-4');
    }

    public function testKeyServiceClassesAreLoadable(): void
    {
        $missing = [];
        foreach ([LegacyDbService::class, Settings::class] as $fqcn) {
            if (!class_exists($fqcn, true)) {
                $missing[] = $fqcn;
            }
        }
        self::assertSame([], $missing, 'Key Phase 1 service / model classes must autoload');
    }

    public function testPluginDeclaresLegacyDbServiceComponent(): void
    {
        // Source-level reflection (no Craft container in unit context).
        $source = (string) file_get_contents((new ReflectionClass(Plugin::class))->getFileName());
        self::assertStringContainsString(
            "'legacyDbService' => LegacyDbService::class",
            $source,
            'Plugin::config() must declare legacyDbService component',
        );
    }
}
```

> **Drop from v1:** Queue job enumeration (Phase 1 has no queue). `getMigrator()` regression guard (Phase 1 doesn't override `getMigrator`). The 13-component enumeration (Phase 1 has 1 component).

---

### `tests/bootstrap.php` (PHPUnit bootstrap)

**Analog:** `~/Sites/craft-kunstmaan-migrator/tests/bootstrap.php`

**Port verbatim** — v1's bootstrap is 4 lines and exactly right for Phase 1:

```php
<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
```

> **Discretion:** Phase 1 deliberately does NOT bootstrap Craft. Per v1's `PluginBootstrapTest::testFullCircularDiCheckIsDeferredToConsumerRehearsal()` pattern (lines 128-138), full Craft-bootstrapped tests are deferred — half-bootstrapping Craft and fighting the framework is a known anti-pattern.

---

### `phpunit.xml` (or `phpunit.xml.dist`)

**Analog:** `~/Sites/craft-kunstmaan-migrator/phpunit.xml`

**Pattern to mirror** (v1 file is 12 lines — a reasonable Phase 1 starting point with one testsuite directory adjustment):

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="tests/bootstrap.php"
         colors="true"
         testdox="true"
         cacheDirectory=".phpunit.cache"
         requireCoverageMetadata="false">
    <testsuites>
        <testsuite name="Unit">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

> **Delta from v1:** v1 used `<directory>tests/unit</directory>` because it had `tests/unit/`, `tests/fixtures/`, `tests/scripts/`. Phase 1 has only `tests/` flat — reflect that. Add `tests/unit/` later if/when characterization fixtures land in Phase 5.

---

### `.github/workflows/ci.yml` (CI workflow)

**Analog:** `~/Sites/craft-kunstmaan-migrator/.github/workflows/ci.yml` (role-match — v1's 26-line workflow runs Deptrac + FQCN-lint + composer-validate + phpunit; Phase 1 is just composer-validate + phpunit per D-22)

**Pattern from v1 to keep:**
- `on: [push, pull_request]` triggers.
- `runs-on: ubuntu-latest` single job.
- `actions/checkout@v4` + `shivammathur/setup-php@v2` action chain.
- `composer install --no-interaction --no-progress` install step.
- `composer validate --strict --no-plugins` (v1 line 23 — `--no-plugins` avoids the craftcms/plugin-installer Filesystem.php absolute-path error when validating the plugin's own repo; this gotcha applies to v2 too).

**Phase 1 target shape per D-22:**

```yaml
name: CI
on: [push, pull_request]
jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
      - run: composer validate --strict --no-plugins
      - run: composer install --no-interaction --no-progress
      - run: composer test
```

> **Drop from v1:** Deptrac step (v2 retires three-tier layout per D-24). `assert-fqcn-loadable.php` step (v1-specific brownfield diagnostic). `--optimize-autoloader` flag (v1 needed it to mask classmap mismatches; v2 has none). Matrix expansion deferred per D-22.
>
> **Naming note:** v1's job is named `static-analysis` — Phase 1's job is `test` since it just runs the unit suite. Single job is fine.

---

## Shared Patterns

### Plain-text OK/FAIL output with ANSI colors
**Source:** `~/Sites/craft-kunstmaan-migrator/src/bridge/console/controllers/DoctorController.php` (lines 44, 76-80, 109, 126)
**Apply to:** All Phase 1 console controllers (`DoctorController`, `MigrateController`)

```php
$this->stdout("Doctor: preflight diagnostics\n", Console::FG_CYAN);   // section header
$this->stdout("  OK   <what>\n", Console::FG_GREEN);                  // pass row
$this->stderr("  FAIL <what>: {$msg}\n", Console::FG_RED);            // fail row
$this->stdout("\nDoctor: PASS\n", Console::FG_GREEN);                 // summary
```

### NeverProduction guard (first line of every action)
**Source:** `~/Sites/craft-kunstmaan-migrator/src/NeverProductionTrait.php` + every controller action in v1
**Apply to:** Every action in `DoctorController` and `MigrateController` (D-20)

```php
public function actionFoo(): int
{
    if (($gate = $this->enforceNeverProduction()) !== null) {
        return $gate;
    }
    // ... action body
}
```

### Yii Component pattern for plugin services
**Source:** `~/Sites/craft-kunstmaan-migrator/src/kunstmaan/db/LegacyDbService.php` (lines 23, 31-36) + `~/Sites/craft-kunstmaan-migrator/src/Plugin.php` (lines 120-157)
**Apply to:** `LegacyDbService` and any future Phase 1+ services accessed via `Plugin::getInstance()->serviceName`

Two-part pattern:
1. Service `extends \yii\base\Component` (no constructor injection) and resolves dependencies via `Craft::$app->get(...)` on every call (test-double-friendly).
2. Plugin declares it in `Plugin::config()['components']` as a class-string component:
```php
public static function config(): array
{
    return ['components' => ['legacyDbService' => LegacyDbService::class]];
}
```
Combined with the `@property-read` docblock (v1 Plugin lines 64-93), `Plugin::getInstance()->legacyDbService` becomes IDE-autocompletable.

### Idempotent install guards
**Source:** `~/Sites/craft-kunstmaan-migrator/src/craft/migrations/Install.php` (lines 52, 102-108, 116-127)
**Apply to:** `src/migrations/Install.php` (per D-07 / D-09)

Three guard patterns:
1. **Table existence guard:** `if ($this->db->tableExists(self::STATE_TABLE)) { return; }` before `createTable()`.
2. **Project-config existence guard with forced YAML re-read:** `$projectConfig->get(self::PROJECT_CONFIG_UID_PATH, true)` (the `true` flag survives concurrent project-config races).
3. **Field handle reuse guard:** `Craft::$app->fields->getFieldByHandle('kunstmaanSourceId')` — if found, persist the existing UID instead of minting a new one.

The `Craft::info()` audit log line on each branch (v1 lines 122-125, 146-149) is part of the pattern — operators need to know which branch fired during install.

### Conditional Yii application-component registration
**Source:** Greenfield in v2 (D-11 — no v1 analog). Pattern is standard Yii idiom but new in this codebase.
**Apply to:** `Plugin::init()` for the `legacyDb` component

```php
if (!Craft::$app->has('legacyDb', true)) {
    Craft::$app->set('legacyDb', [/* Yii component config */]);
}
```

The `true` second argument to `has()` checks for a *registered* component (vs an *instantiated* one); on v1.x swap-in hosts this returns `true` because `config/app.php` already declared `legacyDb`, so the plugin's wiring becomes a no-op (zero churn). On greenfield hosts it returns `false` and the plugin fills the gap from Settings/env.

---

## No Analog Found

Files with no close v1 match — planner should treat these as greenfield:

| File | Role | Data Flow | Reason |
|------|------|-----------|--------|
| `Doctor::checkStorageDir()` method | controller method | filesystem | D-18 — new in v2; v1 had no equivalent storage-dir check |
| `Settings` env-fallback resolution in `init()` | model bootstrap | config | D-12/D-15 — new in v2; v1's Settings only carried `mappingPath` + a few CP-only settings |
| `Plugin::init()` `Craft::$app->set('legacyDb', ...)` block | bootstrap | DI registration | D-11 — new in v2; v1 required operators to declare `legacyDb` themselves in `config/app.php` |
| `composer.json` `suggest` block for SEOmatic / Retour | manifest | n/a | D-24 — new in v2; v1 hard-required both |

These four greenfield items are why match-quality on `Plugin.php`, `DoctorController.php`, and `Settings.php` is *partial* — they all combine a v1 verbatim port with one or more v2-novel additions.

---

## Metadata

**Analog search scope:** `~/Sites/craft-kunstmaan-migrator/src/`, `~/Sites/craft-kunstmaan-migrator/tests/`, `~/Sites/craft-kunstmaan-migrator/.github/`, `~/Sites/craft-kunstmaan-migrator/composer.json`, `~/Sites/craft-kunstmaan-migrator/phpunit.xml`
**Files scanned:** 9 brownfield files (Plugin.php, NeverProductionTrait.php, DoctorController.php, MigrateController.php, LegacyDbService.php, Install.php, Settings.php, PluginBootstrapTest.php, bootstrap.php, ci.yml, composer.json, phpunit.xml)
**Pattern extraction date:** 2026-04-25
