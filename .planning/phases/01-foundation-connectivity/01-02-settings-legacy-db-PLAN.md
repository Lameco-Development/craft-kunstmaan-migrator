---
phase: 01-foundation-connectivity
plan: 02
type: execute
wave: 2
depends_on: [01]
files_modified:
  - src/models/Settings.php
  - src/db/LegacyDbService.php
  - src/Plugin.php
  - src/templates/_settings.twig
autonomous: true
requirements: [CONN-01, CONN-02]
must_haves:
  truths:
    - "Plugin owns the legacy DB connection internally — no Yii component required in the consumer site's config/app.php (CONN-01)."
    - "On v1.x→v2 swap-in hosts the existing legacyDb component wins (zero churn); on greenfield hosts the plugin fills the gap from env vars / Settings."
    - "Anthropic API key is sourced from the ANTHROPIC_API_KEY env var with a Settings override (CONN-02)."
    - "Settings model declares the full v2 surface upfront (D-15) so Phase 4 / CFG-01 plugs in without a refactor."
    - "LegacyDbService is a Yii Component with five read-only methods (db, queryOne, queryAll, queryScalar, streamQuery)."
  artifacts:
    - path: src/models/Settings.php
      provides: "Settings model with env-fallback resolution for CRAFT_LEGACY_DB_* + ANTHROPIC_API_KEY"
      contains: "class Settings extends Model"
    - path: src/db/LegacyDbService.php
      provides: "Yii Component wrapping the legacyDb connection — read-only by discipline (D-13)"
      contains: "class LegacyDbService extends Component"
    - path: src/Plugin.php
      provides: "Plugin entrypoint with init() body — registers legacyDb conditionally, switches controllerNamespace, wires settings"
      contains: "Craft::\\$app->set('legacyDb'"
    - path: src/templates/_settings.twig
      provides: "Placeholder CP Settings template — D-16 (real form lives in Phase 4 / CFG-01)"
      contains: "Phase 4"
  key_links:
    - from: src/Plugin.php
      to: src/models/Settings.php
      via: "createSettingsModel() returns new Settings()"
      pattern: "return new Settings()"
    - from: src/Plugin.php
      to: src/db/LegacyDbService.php
      via: "Plugin::config()['components']['legacyDbService'] => LegacyDbService::class"
      pattern: "'legacyDbService' => LegacyDbService::class"
    - from: src/Plugin.php
      to: "Yii application 'legacyDb' component"
      via: "Craft::\\$app->set('legacyDb', [...]) inside `if (!Craft::\\$app->has('legacyDb', true))`"
      pattern: "!Craft::\\$app->has\\('legacyDb', true\\)"
    - from: src/db/LegacyDbService.php
      to: "Yii application 'legacyDb' component"
      via: "Craft::\\$app->get('legacyDb') resolution per call"
      pattern: "Craft::\\$app->get\\('legacyDb'\\)"
---

<objective>
Wire the legacy MySQL DB connection (plugin-owned, env-driven) and the Anthropic API key into a Settings
model that downstream Phase 1 plans consume. Three concrete deliverables:

1. `Settings` model with the full v2 surface (D-15) — Phase 1 fields read-active, Phase 2-4 fields declared
   but unused.
2. `LegacyDbService` Yii Component with the five read-only methods Phase 1 needs (`db`, `queryOne`,
   `queryAll`, `queryScalar`, `streamQuery`); domain helpers ship in Phases 2-4.
3. `Plugin::init()` populated: conditional `Craft::$app->set('legacyDb', ...)` registration (D-11),
   `controllerNamespace` switch (D-03), `createSettingsModel()`, `settingsHtml()`, and `Plugin::config()`
   declaring the `legacyDbService` component.

After this plan, Plan 03 (Install migration) and Plan 04 (Doctor command) can both run because they depend
on `Plugin::getInstance()->legacyDbService` and the registered `legacyDb` Yii component.

Purpose: Plan 03 needs the registered legacyDb (its install touches Craft only, but its test surface uses
the plugin); Plan 04 calls `legacyDbService->queryOne('SELECT 1')` in the doctor `checkLegacyDb()` method.
Settings is the shared seam.
Output: Plugin loads cleanly into a Craft 5 host; legacyDb is registered when the host hasn't already
declared one; Settings is consumable from anywhere via `Plugin::getInstance()->getSettings()`.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/PROJECT.md
@.planning/ROADMAP.md
@.planning/REQUIREMENTS.md
@.planning/phases/01-foundation-connectivity/01-CONTEXT.md
@.planning/phases/01-foundation-connectivity/01-PATTERNS.md
@CLAUDE.md
@src/Plugin.php
@src/NeverProductionTrait.php
@composer.json
</context>

<interfaces>
<!-- Contracts this plan creates that Plans 03/04 consume. -->

```php
namespace lameco\kunstmaanmigrator\models;

class Settings extends \craft\base\Model
{
    // Phase 1 read-active (D-12, D-14, D-15)
    public ?string $legacyDbServer       = null;
    public int     $legacyDbPort         = 3306;
    public ?string $legacyDbDatabase     = null;
    public ?string $legacyDbUser         = null;
    public ?string $legacyDbPassword     = null;
    public string  $legacyDbCharset      = 'utf8mb4';
    public string  $legacyDbTablePrefix  = '';
    public ?string $anthropicApiKey      = null;

    // Phase 2-4 declared upfront (D-15)
    public ?string $llmModel             = null;
    public ?int    $llmTimeout           = null;
    public ?string $mappingPath          = null;
    public array   $defaultEntities      = [];
    public array   $defaultLocales       = [];
    public ?string $defaultSince         = null;
    public ?int    $defaultMaxPerEntity  = null;
    public bool    $dryRunDefault        = true;
}
```

```php
namespace lameco\kunstmaanmigrator\db;

class LegacyDbService extends \yii\base\Component
{
    public function db(): \yii\db\Connection;
    public function queryOne(string $sql, array $params = []): ?array;
    public function queryAll(string $sql, array $params = []): array;
    public function queryScalar(string $sql, array $params = []): mixed;
    public function streamQuery(string $sql, array $params = []): \Generator;
}
```

```php
namespace lameco\kunstmaanmigrator;

class Plugin extends \craft\base\Plugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;

    /** @property-read \lameco\kunstmaanmigrator\db\LegacyDbService $legacyDbService */
    public static function config(): array;            // declares legacyDbService component
    public function init(): void;                       // legacyDb registration + controllerNamespace
    protected function createSettingsModel(): ?\craft\base\Model;
    protected function settingsHtml(): ?string;
}
```
</interfaces>

<tasks>

<task type="auto" tdd="false">
  <name>Task 1: Write Settings model with env-fallback resolution</name>
  <files>src/models/Settings.php</files>
  <read_first>
    - ~/Sites/craft-kunstmaan-migrator/src/models/Settings.php (v1 reference — class shape, behaviors() pattern, rules())
    - .planning/phases/01-foundation-connectivity/01-PATTERNS.md (section "src/models/Settings.php", lines 584-703)
    - .planning/phases/01-foundation-connectivity/01-CONTEXT.md (D-12, D-14, D-15)
  </read_first>
  <action>
    Create `src/models/Settings.php`. The file declares the FULL v2 surface upfront per D-15 (Phase 1 fields read-active; Phase 2-4 fields declared but unused). Env-var fallback in `init()` resolves CRAFT_LEGACY_DB_* + ANTHROPIC_API_KEY.

    Concrete file content (paste-ready, follows PATTERNS.md lines 596-702):

    ```php
    <?php

    declare(strict_types=1);

    namespace lameco\kunstmaanmigrator\models;

    use Craft;
    use craft\base\Model;
    use craft\behaviors\EnvAttributeParserBehavior;
    use craft\helpers\App;

    /**
     * Plugin Settings — shared seam between env vars, config/kunstmaan-migrator.php,
     * and the (Phase 4) CP Settings page. Phase 1 reads only the legacyDb* fields and
     * anthropicApiKey; the rest are declared upfront per D-15 so Phase 4 / CFG-01
     * plugs in without a refactor.
     */
    class Settings extends Model
    {
        // Legacy DB connection (D-12). Defaults to CRAFT_LEGACY_DB_* env vars.
        public ?string $legacyDbServer       = null;
        public int     $legacyDbPort         = 3306;
        public ?string $legacyDbDatabase     = null;
        public ?string $legacyDbUser         = null;
        public ?string $legacyDbPassword     = null;
        public string  $legacyDbCharset      = 'utf8mb4';
        public string  $legacyDbTablePrefix  = '';

        // Anthropic key (D-14). Defaults to ANTHROPIC_API_KEY.
        public ?string $anthropicApiKey      = null;

        // Phase 2-4 fields (D-15) — declared, unused until later phases.
        public ?string $llmModel             = null;
        public ?int    $llmTimeout           = null;
        public ?string $mappingPath          = null;
        public array   $defaultEntities      = [];
        public array   $defaultLocales       = [];
        public ?string $defaultSince         = null;
        public ?int    $defaultMaxPerEntity  = null;
        public bool    $dryRunDefault        = true;

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

        public function init(): void
        {
            parent::init();

            // D-12: env-var fallback. config/kunstmaan-migrator.php overrides win when present
            // (Craft loads the config file BEFORE init() and assigns to the public properties,
            // so `??=` only fills the unset cases).
            $this->legacyDbServer      ??= App::env('CRAFT_LEGACY_DB_SERVER') ?: null;
            $this->legacyDbDatabase    ??= App::env('CRAFT_LEGACY_DB_DATABASE') ?: null;
            $this->legacyDbUser        ??= App::env('CRAFT_LEGACY_DB_USER') ?: null;
            $this->legacyDbPassword    ??= App::env('CRAFT_LEGACY_DB_PASSWORD') ?: null;
            $envPort = App::env('CRAFT_LEGACY_DB_PORT');
            if ($envPort !== null && $envPort !== '' && $envPort !== false) {
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
            // D-14: ANTHROPIC_API_KEY env fallback. Settings property override wins when present.
            // Never logged by this class; doctor reports presence only (T-1-03).
            $this->anthropicApiKey ??= App::env('ANTHROPIC_API_KEY') ?: null;
        }

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
    }
    ```

    Threat-mitigation notes (T-1-02 + T-1-03):
    - This file does NOT log or echo any value. Any future serializer or `__toString()` MUST mask `legacyDbPassword` and `anthropicApiKey` — not a concern for Phase 1 since neither is added.
    - Do NOT add a `__debugInfo()` that exposes secrets either.
  </action>
  <acceptance_criteria>
    - `src/models/Settings.php` exists.
    - `grep -q "namespace lameco\\\\kunstmaanmigrator\\\\models;" src/models/Settings.php` exits 0.
    - `grep -q "class Settings extends Model" src/models/Settings.php` exits 0.
    - All 8 Phase 1 fields present: `for prop in legacyDbServer legacyDbPort legacyDbDatabase legacyDbUser legacyDbPassword legacyDbCharset legacyDbTablePrefix anthropicApiKey; do grep -q "\\\$$prop" src/models/Settings.php || exit 1; done`.
    - All 8 Phase 2-4 fields present: `for prop in llmModel llmTimeout mappingPath defaultEntities defaultLocales defaultSince defaultMaxPerEntity dryRunDefault; do grep -q "\\\$$prop" src/models/Settings.php || exit 1; done`.
    - `grep -q "App::env('CRAFT_LEGACY_DB_SERVER')" src/models/Settings.php` exits 0.
    - `grep -q "App::env('ANTHROPIC_API_KEY')" src/models/Settings.php` exits 0.
    - `grep -q "EnvAttributeParserBehavior::class" src/models/Settings.php` exits 0.
    - `! grep -i 'echo.*password\\|var_dump.*Password\\|print_r.*Password' src/models/Settings.php` (no secret leak).
    - `! grep -i 'echo.*anthropicApiKey\\|var_dump.*anthropicApiKey\\|print_r.*anthropicApiKey' src/models/Settings.php` (no secret leak).
    - `php -l src/models/Settings.php` exits 0.
    - `php -r 'require __DIR__ . "/vendor/autoload.php"; class_exists("lameco\\kunstmaanmigrator\\models\\Settings", true) or exit(1);'` exits 0.
  </acceptance_criteria>
  <verify>
    <automated>php -l src/models/Settings.php &amp;&amp; php -r 'require __DIR__ . "/vendor/autoload.php"; class_exists("lameco\\kunstmaanmigrator\\models\\Settings", true) or exit(1);'</automated>
  </verify>
  <done>Settings class autoloads, declares all 16 fields (8 read-active + 8 declared), env-fallback init() resolves CRAFT_LEGACY_DB_* + ANTHROPIC_API_KEY, no secret echo/log paths.</done>
</task>

<task type="auto" tdd="false">
  <name>Task 2: Write LegacyDbService Yii Component (read-only)</name>
  <files>src/db/LegacyDbService.php</files>
  <read_first>
    - ~/Sites/craft-kunstmaan-migrator/src/kunstmaan/db/LegacyDbService.php (lines 1-23 imports, lines 31-69 the four query methods, lines 78-88 streamQuery)
    - .planning/phases/01-foundation-connectivity/01-PATTERNS.md (section "src/db/LegacyDbService.php", lines 363-451)
    - .planning/phases/01-foundation-connectivity/01-CONTEXT.md (D-13 read-only discipline)
  </read_first>
  <action>
    Create `src/db/LegacyDbService.php`. Phase 1 ships ONLY the five core methods per the canonical_refs in CONTEXT.md: `db()`, `queryOne()`, `queryAll()`, `queryScalar()`, `streamQuery()`. Drop every domain helper from v1 (`streamLiveNodes`, `translationsFor`, `pagePartsFor`, `seoFor`, `mediaById`, `redirects`, `extTranslationsFor`, `getDatabaseName`) — those return in Phases 2-4 when their callers exist.

    Concrete file content (paste-ready, follows PATTERNS.md lines 372-447):

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
     * Discipline (D-13): no writes. Code review enforces that no `->insert(`, `->update(`,
     * or `->delete(` ever appears in this file. Any legacy-side mutation belongs in an
     * ad-hoc dev console, not in plugin code.
     *
     * The underlying `legacyDb` Yii application component is registered by `Plugin::init()`
     * (D-11) when the host hasn't already declared one in `config/app.php` — this service
     * resolves it via `Craft::$app->get('legacyDb')` on every call so test doubles can
     * replace the component without re-wiring this class.
     */
    class LegacyDbService extends Component
    {
        public function db(): Connection
        {
            /** @var Connection $conn */
            $conn = Craft::$app->get('legacyDb');
            return $conn;
        }

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
    }
    ```

    The read-only discipline is enforced at file level: no `insert`, `update`, `delete`, or `execute` write ops. The acceptance criteria below grep for those patterns to fail-loud if added.
  </action>
  <acceptance_criteria>
    - `src/db/LegacyDbService.php` exists.
    - `grep -q "namespace lameco\\\\kunstmaanmigrator\\\\db;" src/db/LegacyDbService.php` exits 0.
    - `grep -q "class LegacyDbService extends Component" src/db/LegacyDbService.php` exits 0.
    - All five methods present: `for m in 'function db' 'function queryOne' 'function queryAll' 'function queryScalar' 'function streamQuery'; do grep -q "$m" src/db/LegacyDbService.php || exit 1; done`.
    - `grep -q "Craft::\\\$app->get('legacyDb')" src/db/LegacyDbService.php` exits 0.
    - Read-only discipline (D-13) — these patterns MUST be absent: `! grep -E '->insert\\(|->update\\(|->delete\\(|->batchInsert\\(' src/db/LegacyDbService.php`.
    - `php -l src/db/LegacyDbService.php` exits 0.
    - `php -r 'require __DIR__ . "/vendor/autoload.php"; class_exists("lameco\\kunstmaanmigrator\\db\\LegacyDbService", true) or exit(1);'` exits 0.
  </acceptance_criteria>
  <verify>
    <automated>php -l src/db/LegacyDbService.php &amp;&amp; php -r 'require __DIR__ . "/vendor/autoload.php"; class_exists("lameco\\kunstmaanmigrator\\db\\LegacyDbService", true) or exit(1);' &amp;&amp; ! grep -E '->insert\(|->update\(|->delete\(|->batchInsert\(' src/db/LegacyDbService.php</automated>
  </verify>
  <done>LegacyDbService autoloads as a Yii Component, exposes the five read-only methods, contains zero write-op symbols.</done>
</task>

<task type="auto" tdd="false">
  <name>Task 3: Fill Plugin::init() — legacyDb registration, controllerNamespace, settings hooks</name>
  <files>src/Plugin.php, src/templates/_settings.twig</files>
  <read_first>
    - src/Plugin.php (current stub from Plan 01)
    - src/models/Settings.php (just-written)
    - src/db/LegacyDbService.php (just-written)
    - ~/Sites/craft-kunstmaan-migrator/src/Plugin.php (lines 64-93 docblock, 107-118 props, 120-157 config(), 186-426 init() — Phase 1 strips init() to legacyDb + controllerNamespace only)
    - .planning/phases/01-foundation-connectivity/01-PATTERNS.md (section "src/Plugin.php", lines 28-125)
    - .planning/phases/01-foundation-connectivity/01-CONTEXT.md (D-03, D-08, D-11, D-12, D-15, D-16)
  </read_first>
  <action>
    Replace `src/Plugin.php` (the stub from Plan 01) with the full Phase 1 version. Adds (relative to the stub):
    - `@property-read LegacyDbService $legacyDbService` docblock
    - `Plugin::config()` declaring the `legacyDbService` component
    - `init()` body: conditional `Craft::$app->set('legacyDb', [...])` registration + console controllerNamespace switch
    - `createSettingsModel()` returning a populated Settings instance
    - `settingsHtml()` rendering the placeholder template

    Concrete final file content (paste-ready, this REPLACES the Plan-01 stub):

    ```php
    <?php

    declare(strict_types=1);

    namespace lameco\kunstmaanmigrator;

    use Craft;
    use craft\base\Model;
    use craft\base\Plugin as BasePlugin;
    use lameco\kunstmaanmigrator\db\LegacyDbService;
    use lameco\kunstmaanmigrator\models\Settings;
    use PDO;
    use yii\db\Connection;

    /**
     * Kunstmaan → Craft Migrator plugin entrypoint.
     *
     * @property-read LegacyDbService $legacyDbService
     * @method Settings getSettings()
     */
    class Plugin extends BasePlugin
    {
        // D-08: v2 declares 1.0.0 (NOT v1.x's 2.0.0).
        public string $schemaVersion = '1.0.0';

        // D-16: enables CP Settings page; placeholder template ships with this plan,
        // real form lives in Phase 4 / CFG-01.
        public bool $hasCpSettings = true;

        public static function config(): array
        {
            return [
                'components' => [
                    // D-15: only Phase-1 component. Phase 2-4 components land in later phases.
                    'legacyDbService' => LegacyDbService::class,
                ],
            ];
        }

        public function init(): void
        {
            parent::init();

            // D-11: register the legacyDb Yii application component ONLY when the host has
            // not already declared one. On v1.x→v2 swap-in hosts the existing config/app.php
            // declaration wins (zero churn for operators); on greenfield hosts the plugin
            // fills the gap from Settings (which falls back to CRAFT_LEGACY_DB_* env vars).
            //
            // Use the `true` second arg to has() — it checks for a *registered* (vs
            // *instantiated*) component, which is the right check pre-first-access.
            if (!Craft::$app->has('legacyDb', true)) {
                /** @var Settings $settings */
                $settings = $this->getSettings();
                Craft::$app->set('legacyDb', [
                    'class'       => Connection::class,
                    'dsn'         => sprintf(
                        'mysql:host=%s;port=%d;dbname=%s',
                        (string) $settings->legacyDbServer,
                        $settings->legacyDbPort,
                        (string) $settings->legacyDbDatabase,
                    ),
                    'username'    => $settings->legacyDbUser,
                    'password'    => $settings->legacyDbPassword,
                    'charset'     => $settings->legacyDbCharset,
                    'tablePrefix' => $settings->legacyDbTablePrefix,
                    'attributes'  => [PDO::ATTR_EMULATE_PREPARES => false],
                ]);
            }

            // D-03: console controllerNamespace points at the flat src/console/ directory.
            // No web controller namespace yet — the CP Settings save handler lands in Phase 4
            // when CFG-01 introduces the real form.
            if (Craft::$app->request->getIsConsoleRequest()) {
                $this->controllerNamespace = 'lameco\\kunstmaanmigrator\\console';
            }
        }

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
    }
    ```

    Then create the placeholder Twig template at `src/templates/_settings.twig` (D-16 — Phase 1 placeholder; Phase 4 / CFG-01 fills in the real form). Concrete content:

    ```twig
    {# D-16: Phase 1 placeholder. The real CP Settings form lands in Phase 4 / CFG-01. #}
    {% extends "_layouts/cp" %}

    {% set title = "Kunstmaan Migrator — Settings" %}

    {% block content %}
        <div>
            <p><strong>Kunstmaan Migrator settings</strong> — placeholder.</p>
            <p>The CP Settings form ships in Phase 4 (CFG-01). Until then, configure the plugin via environment variables:</p>
            <ul>
                <li><code>CRAFT_LEGACY_DB_SERVER</code>, <code>CRAFT_LEGACY_DB_DATABASE</code>, <code>CRAFT_LEGACY_DB_USER</code>, <code>CRAFT_LEGACY_DB_PASSWORD</code></li>
                <li><code>CRAFT_LEGACY_DB_PORT</code> (default 3306), <code>CRAFT_LEGACY_DB_CHARSET</code> (default utf8mb4), <code>CRAFT_LEGACY_DB_TABLE_PREFIX</code></li>
                <li><code>ANTHROPIC_API_KEY</code></li>
            </ul>
            <p>Run <code>./craft kunstmaan-migrator/doctor</code> to verify connectivity.</p>
        </div>
    {% endblock %}
    ```

    Threat-mitigation notes:
    - T-1-02 (DB credentials leak via stack trace / log): the `Craft::$app->set('legacyDb', [...])` config is a static array — Yii's Connection uses these values directly, never logs them. Do NOT add `Craft::info()` or `var_dump` calls inside the `if` block.
    - The placeholder template never echoes any settings VALUE, only env-var NAMES. That's deliberate — the real form (Phase 4) will use Craft's `passwordField` macro which masks input.
  </action>
  <acceptance_criteria>
    - `src/Plugin.php` contains the full init() body (replaces Plan-01 stub).
    - `grep -q "@property-read LegacyDbService \\\$legacyDbService" src/Plugin.php` exits 0.
    - `grep -q "'legacyDbService' => LegacyDbService::class" src/Plugin.php` exits 0.
    - `grep -q "if (!Craft::\\\$app->has('legacyDb', true))" src/Plugin.php` exits 0.
    - `grep -q "Craft::\\\$app->set('legacyDb'" src/Plugin.php` exits 0.
    - `grep -q "PDO::ATTR_EMULATE_PREPARES" src/Plugin.php` exits 0.
    - `grep -q "Craft::\\\$app->request->getIsConsoleRequest()" src/Plugin.php` exits 0.
    - `grep -q "controllerNamespace = 'lameco" src/Plugin.php` exits 0.
    - `grep -q "return new Settings();" src/Plugin.php` exits 0.
    - `grep -q "kunstmaan-migrator/_settings.twig" src/Plugin.php` exits 0.
    - The two declared properties from Plan 01 are still present: `grep -q "schemaVersion = '1.0.0'" src/Plugin.php && grep -q "hasCpSettings = true" src/Plugin.php`.
    - `src/templates/_settings.twig` exists.
    - `grep -q 'Phase 4' src/templates/_settings.twig` exits 0 (placeholder marker).
    - `grep -q 'CRAFT_LEGACY_DB_SERVER' src/templates/_settings.twig` exits 0.
    - `! grep -q 'settings.legacyDbPassword\\|settings.anthropicApiKey' src/templates/_settings.twig` (placeholder MUST NOT echo secrets — even though it's just a placeholder, this is a discipline gate).
    - `php -l src/Plugin.php` exits 0.
    - `php -r 'require __DIR__ . "/vendor/autoload.php"; class_exists("lameco\\kunstmaanmigrator\\Plugin", true) or exit(1); $rc = new ReflectionClass("lameco\\kunstmaanmigrator\\Plugin"); $rc->hasMethod("init") or exit(2); $rc->hasMethod("config") or exit(3); $rc->hasMethod("createSettingsModel") or exit(4); $rc->hasMethod("settingsHtml") or exit(5);'` exits 0.
  </acceptance_criteria>
  <verify>
    <automated>php -l src/Plugin.php &amp;&amp; test -f src/templates/_settings.twig &amp;&amp; php -r 'require __DIR__ . "/vendor/autoload.php"; $rc = new ReflectionClass("lameco\\kunstmaanmigrator\\Plugin"); foreach (["init","config","createSettingsModel","settingsHtml"] as $m) { $rc->hasMethod($m) or exit(1); }'</automated>
  </verify>
  <done>Plugin.php is the full Phase 1 version: declares legacyDbService component, registers legacyDb conditionally, switches console controllerNamespace, wires Settings model + placeholder template. Plugin class methods reflect: init, config, createSettingsModel, settingsHtml.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| Operator env / config → plugin Settings | Untrusted-by-classification but operator-supplied: `CRAFT_LEGACY_DB_*`, `ANTHROPIC_API_KEY`. Mostly a confidentiality concern (don't leak), not an injection concern (no user input flows to SQL here). |
| Plugin Settings → Yii `legacyDb` Connection | The DSN, username, password are passed verbatim to a Yii `Connection`. PDO handles escaping for downstream queries; this layer must not accidentally log the credentials. |
| Plugin → CP Twig `_settings.twig` | Phase 1 placeholder — no settings values rendered. Phase 4 (CFG-01) will render with autoescape ON and password fields masked. |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-1-02 | Information Disclosure | `Plugin::init()` legacyDb registration block, `Settings` model | mitigate | Static config array passed to `Craft::$app->set('legacyDb', [...])`; no `Craft::info()`, `var_dump`, `error_log`, or `print_r` of `$settings` anywhere in the registration block. Settings class has no `__toString`, `__debugInfo`, or `serialize` override that exposes `legacyDbPassword` / `anthropicApiKey`. Verified by acceptance-criteria greps above (`! grep -i 'echo.*password|var_dump.*Password|print_r.*Password' src/models/Settings.php`). |
| T-1-03 | Information Disclosure | `Settings::$anthropicApiKey` | mitigate | Same as T-1-02 — Settings file MUST NOT echo or log the key. The placeholder `_settings.twig` template renders ONLY env-var NAMES, never values; verified by `! grep -q 'settings.anthropicApiKey' src/templates/_settings.twig`. Doctor-check enforcement (presence-only reporting) lands in Plan 04. |
| T-1-05 | Tampering | Yii `legacyDb` component | accept | If a malicious party has write access to `config/app.php` they can already register an arbitrary `legacyDb`. Plan 02's conditional registration RESPECTS the existing component (D-11) — that's the intended swap-in story. Risk is bounded by repo write access; mitigation is git review + filesystem perms, not in-code. |

The phase-level T-1-01 (NeverProduction bypass) and T-1-04 (storage-dir creation path) are Plan 04's
responsibility — they don't surface in this plan.
</threat_model>

<verification>
After all three tasks, run:

1. `composer dump-autoload --no-interaction` — pick up new PSR-4 classes.
2. `php -l src/Plugin.php src/models/Settings.php src/db/LegacyDbService.php src/NeverProductionTrait.php` — all four lint clean.
3. The reflection probe on Plugin (Task 3 verify) confirms `init`, `config`, `createSettingsModel`, `settingsHtml` methods exist.
4. Read-only-discipline grep on `LegacyDbService.php` confirms no write ops.
5. Settings smoke: `php -r 'require __DIR__ . "/vendor/autoload.php"; $r = new ReflectionClass("lameco\\kunstmaanmigrator\\models\\Settings"); $expected = ["legacyDbServer","legacyDbPort","legacyDbDatabase","legacyDbUser","legacyDbPassword","legacyDbCharset","legacyDbTablePrefix","anthropicApiKey","llmModel","llmTimeout","mappingPath","defaultEntities","defaultLocales","defaultSince","defaultMaxPerEntity","dryRunDefault"]; foreach ($expected as $p) { if (!$r->hasProperty($p)) { fwrite(STDERR, "missing $p\n"); exit(1); } } echo "OK\n";'` exits 0.
</verification>

<success_criteria>
- CONN-01 satisfied: `Plugin::init()` registers `legacyDb` Yii component conditionally; consumer site does not need to declare it in `config/app.php` (greenfield path), and existing v1.x declaration is respected (swap-in path).
- CONN-02 satisfied: `Settings::$anthropicApiKey` resolves from `ANTHROPIC_API_KEY` env var with explicit Settings property override; never echoed or logged from the Settings class.
- D-11 wiring is conditional (the `!Craft::$app->has('legacyDb', true)` guard).
- D-13 read-only discipline holds (LegacyDbService contains no write-op symbols).
- D-15 surface declared in full (8 read-active + 8 declared properties).
- D-16 hooks present: `hasCpSettings = true`, `createSettingsModel()`, `settingsHtml()` rendering the placeholder template.
- All four files lint clean and autoload through PSR-4.
- T-1-02, T-1-03 mitigations verified by file-content greps.
</success_criteria>

<output>
After completion, create `.planning/phases/01-foundation-connectivity/01-02-SUMMARY.md`.
</output>
