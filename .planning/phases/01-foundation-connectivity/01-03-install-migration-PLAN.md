---
phase: 01-foundation-connectivity
plan: 03
type: execute
wave: 2
depends_on: [01]
files_modified:
  - src/migrations/Install.php
  - src/console/MigrateController.php
autonomous: true
requirements: [FND-02, FND-02a, FND-03]
must_haves:
  truths:
    - "Install creates the kunstmaanmigrator_state table with v1.x's actual schema (D-06) — byte-for-byte compatible with the 570-row CQM rehearsal site."
    - "Install attaches a kunstmaanSourceId Plain Text field, REUSING an existing field UID when one is present (D-09 — v1.x→v2 swap-in continuity)."
    - "Re-running install on a host where the table or field already exists is a no-op (idempotent — D-07 / D-09 guards)."
    - "Uninstall (safeDown) PRESERVES the state table and the kunstmaanSourceId field — operator wipes manually for full reset (FND-03 / D-10)."
    - "kunstmaan-migrator/migrate/install runs the plugin's own migrations on demand (FND-02a)."
  artifacts:
    - path: src/migrations/Install.php
      provides: "Install migration — state table + kunstmaanSourceId field with UID-reuse"
      contains: "class Install extends Migration"
    - path: src/console/MigrateController.php
      provides: "Console controller exposing migrate/install (Phase 1 ships only this action)"
      contains: "function actionInstall"
  key_links:
    - from: src/console/MigrateController.php
      to: src/migrations/Install.php
      via: "MigrationManager configured with migrationPath = __DIR__ . '/../migrations' and migrationNamespace 'lameco\\\\kunstmaanmigrator\\\\migrations'"
      pattern: "migrationNamespace.*migrations"
    - from: src/migrations/Install.php
      to: "Craft project-config path plugins.kunstmaan-migrator.kunstmaanSourceIdFieldUid"
      via: "Craft::\\$app->projectConfig->get/set"
      pattern: "PROJECT_CONFIG_UID_PATH"
    - from: src/migrations/Install.php
      to: "kunstmaanSourceId field handle"
      via: "Craft::\\$app->fields->getFieldByHandle('kunstmaanSourceId')"
      pattern: "getFieldByHandle\\('kunstmaanSourceId'\\)"
---

<objective>
Port v1.x's `Install.php` byte-for-byte for the schema (D-06) and field-UID-reuse logic (D-09), and ship the
single-action `MigrateController::actionInstall` (D-05) that runs the migration on demand. After this plan,
`./craft plugin/install kunstmaan-migrator` (which Craft itself runs) plus
`./craft kunstmaan-migrator/migrate/install` (the FND-02a programmatic shim) both create the state table
and attach the `kunstmaanSourceId` field — and a re-run is a no-op.

Purpose: This is the v1.x→v2 swap-in correctness gate. The CQM rehearsal site has 570 entries with
`kunstmaanSourceId` values and a populated state table. v2 install MUST NOT orphan them. The schema is
verbatim per D-06; the UID-reuse path follows the project-config-then-getFieldByHandle chain per D-09.
Output: Install migration + the install action both exist; running install is idempotent.
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
<!-- Contracts this plan creates that Plan 04 (DoctorController) and Plan 05 (PluginBootstrapTest) consume. -->

```php
namespace lameco\kunstmaanmigrator\migrations;

class Install extends \craft\db\Migration
{
    public const FIELD_HANDLE = 'kunstmaanSourceId';
    public const STATE_TABLE = '{{%kunstmaanmigrator_state}}';
    public const PROJECT_CONFIG_UID_PATH = 'plugins.kunstmaan-migrator.kunstmaanSourceIdFieldUid';

    public function safeUp(): bool;     // ensureStateTable + ensureFieldAndAttach
    public function safeDown(): bool;   // intentional no-op (D-10 / FND-03)
}
```

```php
namespace lameco\kunstmaanmigrator\console;

class MigrateController extends \craft\console\Controller
{
    use \lameco\kunstmaanmigrator\NeverProductionTrait;
    public function actionInstall(): int;   // runs MigrationManager::up() on the kunstmaanmigrator track
}
```

```sql
-- D-06 verbatim schema (Craft Migration syntax: $this->primaryKey() / $this->string(N) / $this->json() etc.)
CREATE TABLE {{%kunstmaanmigrator_state}} (
  id            INT primaryKey,
  source        VARCHAR(64)   NOT NULL,
  sourceKey     VARCHAR(255)  NOT NULL,
  targetType    VARCHAR(64)   NOT NULL,
  targetId      INT,
  targetUid     VARCHAR(36),
  siteId        INT NULL,
  meta          JSON NULL,
  dateCreated   DATETIME NOT NULL,
  dateUpdated   DATETIME NOT NULL,
  UNIQUE INDEX (source, sourceKey, siteId),
  INDEX (dateUpdated)
);
```
</interfaces>

<tasks>

<task type="auto" tdd="false">
  <name>Task 1: Port Install.php — state table + field UID reuse (verbatim from v1)</name>
  <files>src/migrations/Install.php</files>
  <read_first>
    - ~/Sites/craft-kunstmaan-migrator/src/craft/migrations/Install.php (full file — schema + UID-reuse source of truth per D-06 / D-09)
    - .planning/phases/01-foundation-connectivity/01-PATTERNS.md (section "src/migrations/Install.php", lines 454-580)
    - .planning/phases/01-foundation-connectivity/01-CONTEXT.md (D-06, D-07, D-09, D-10)
  </read_first>
  <action>
    Create `src/migrations/Install.php`. Port schema + UID-reuse logic byte-for-byte from v1's `src/craft/migrations/Install.php`. Only the namespace changes (`lameco\kunstmaanmigrator\migrations` per D-04 flat layout — drop the v1 `craft\` sub-tier). The three constants, `safeUp()`, `safeDown()` (no-op per D-10), `ensureStateTable()`, and `ensureFieldAndAttach()` all port verbatim.

    Concrete file content (paste-ready, follows PATTERNS.md lines 461-577):

    ```php
    <?php

    declare(strict_types=1);

    namespace lameco\kunstmaanmigrator\migrations;

    use Craft;
    use craft\db\Migration;
    use craft\fields\PlainText;
    use craft\helpers\StringHelper;

    /**
     * Install — D-06 (state table schema verbatim from v1.x), D-07 (idempotent),
     * D-09 (field UID reuse for v1.x→v2 swap-in continuity), D-10 (safeDown is no-op).
     */
    class Install extends Migration
    {
        public const FIELD_HANDLE = 'kunstmaanSourceId';
        public const STATE_TABLE = '{{%kunstmaanmigrator_state}}';
        public const PROJECT_CONFIG_UID_PATH = 'plugins.kunstmaan-migrator.kunstmaanSourceIdFieldUid';

        public function safeUp(): bool
        {
            $this->ensureStateTable();
            $this->ensureFieldAndAttach();
            return true;
        }

        // D-10 / FND-03: uninstall PRESERVES state table and field — operator wipes manually for full reset.
        public function safeDown(): bool
        {
            return true;
        }

        private function ensureStateTable(): void
        {
            if ($this->db->tableExists(self::STATE_TABLE)) {
                // D-07 idempotency: prior install (or v1.x) already created the table.
                // Leave it alone; row-level data must survive re-install.
                return;
            }

            // D-06: schema byte-for-byte from v1.x src/craft/migrations/Install.php.
            // The 570-row CQM rehearsal site already has rows in this exact shape.
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

        private function ensureFieldAndAttach(): void
        {
            $projectConfig = Craft::$app->projectConfig;

            // D-09 step 1: forced YAML re-read (true second arg) survives concurrent
            // project-config races — Craft idempotent-install guidance.
            $existingUid = $projectConfig->get(self::PROJECT_CONFIG_UID_PATH, true);

            if ($existingUid !== null) {
                // Plugin already installed against this site — UID persisted under our config path. No-op.
                return;
            }

            // D-09 step 2: before minting a new UID, check whether the site already has a field
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

            // D-09 step 3: greenfield Craft host — mint a new field + UID.
            // Plain Text type per PROJECT.md Key Decisions ("kunstmaanSourceId field stays Plain Text").
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
    }
    ```

    Notes for the executor:
    - The `Craft::info()` audit-log lines on each branch are PART of the pattern (PATTERNS.md "Idempotent install guards" §3) — operators need to know which branch fired. Do NOT remove them.
    - `safeDown()` returning `true` is the FND-03 / D-10 contract. Do NOT add `dropTableIfExists()` or `removeField()`.
    - The `tableExists` check on `STATE_TABLE` is a v1.x→v2 swap-in critical path: the table already exists with 570 rows, and dropping/recreating would orphan them.
    - The literal string `'kunstmaanSourceId'` in `getFieldByHandle('kunstmaanSourceId')` is intentional — keep it inline (not via the constant) so a grep for the literal handle finds the swap-in lookup site.
  </action>
  <acceptance_criteria>
    - `src/migrations/Install.php` exists.
    - `grep -q "namespace lameco\\\\kunstmaanmigrator\\\\migrations;" src/migrations/Install.php` exits 0.
    - `grep -q "class Install extends Migration" src/migrations/Install.php` exits 0.
    - All three constants present: `grep -q "FIELD_HANDLE = 'kunstmaanSourceId'" src/migrations/Install.php && grep -q "STATE_TABLE = '{{%kunstmaanmigrator_state}}'" src/migrations/Install.php && grep -q "PROJECT_CONFIG_UID_PATH = 'plugins.kunstmaan-migrator.kunstmaanSourceIdFieldUid'" src/migrations/Install.php`.
    - Schema columns (D-06): all 10 columns present: `for col in 'id' 'source' 'sourceKey' 'targetType' 'targetId' 'targetUid' 'siteId' 'meta' 'dateCreated' 'dateUpdated'; do grep -q "'$col'" src/migrations/Install.php || exit 1; done`.
    - Both indexes present: `grep -q "createIndex.*source.*sourceKey.*siteId.*true" src/migrations/Install.php && grep -q "createIndex.*dateUpdated.*false" src/migrations/Install.php`.
    - Idempotency guard present: `grep -q 'tableExists(self::STATE_TABLE)' src/migrations/Install.php`.
    - UID-reuse chain present: `grep -q "projectConfig->get(self::PROJECT_CONFIG_UID_PATH, true)" src/migrations/Install.php && grep -q "getFieldByHandle('kunstmaanSourceId')" src/migrations/Install.php && grep -q 'StringHelper::UUID()' src/migrations/Install.php`.
    - Plain Text field type used: `grep -q 'new PlainText(' src/migrations/Install.php && grep -q "use craft\\\\fields\\\\PlainText;" src/migrations/Install.php`.
    - charLimit set to 255: `grep -q 'charLimit = 255' src/migrations/Install.php`.
    - safeDown is no-op (D-10 / FND-03): `awk '/function safeDown/,/^    }/' src/migrations/Install.php | grep -q 'return true;'` AND `! awk '/function safeDown/,/^    }/' src/migrations/Install.php | grep -E 'dropTable|removeField|delete'`.
    - `php -l src/migrations/Install.php` exits 0.
    - `php -r 'require __DIR__ . "/vendor/autoload.php"; class_exists("lameco\\kunstmaanmigrator\\migrations\\Install", true) or exit(1);'` exits 0.
  </acceptance_criteria>
  <verify>
    <automated>php -l src/migrations/Install.php &amp;&amp; php -r 'require __DIR__ . "/vendor/autoload.php"; $rc = new ReflectionClass("lameco\\kunstmaanmigrator\\migrations\\Install"); $rc->hasMethod("safeUp") or exit(1); $rc->hasMethod("safeDown") or exit(2); $rc->getConstant("FIELD_HANDLE") === "kunstmaanSourceId" or exit(3); $rc->getConstant("STATE_TABLE") === "{{%kunstmaanmigrator_state}}" or exit(4); $rc->getConstant("PROJECT_CONFIG_UID_PATH") === "plugins.kunstmaan-migrator.kunstmaanSourceIdFieldUid" or exit(5);'</automated>
  </verify>
  <done>Install.php autoloads with the three D-09 constants, declares the 10-column D-06 schema with both indexes, includes the project-config-then-getFieldByHandle UID-reuse chain, and `safeDown` is a verbatim no-op.</done>
</task>

<task type="auto" tdd="false">
  <name>Task 2: Write MigrateController with actionInstall (Phase 1 only ships this action)</name>
  <files>src/console/MigrateController.php</files>
  <read_first>
    - ~/Sites/craft-kunstmaan-migrator/src/bridge/console/controllers/MigrateController.php (lines 1-46 imports/class shape; lines 342-364 actionInstall)
    - .planning/phases/01-foundation-connectivity/01-PATTERNS.md (section "src/console/MigrateController.php", lines 299-360)
    - .planning/phases/01-foundation-connectivity/01-CONTEXT.md (D-04, D-05, D-20)
    - src/NeverProductionTrait.php (just-ported in Plan 01)
  </read_first>
  <action>
    Create `src/console/MigrateController.php`. Phase 1 ships ONLY `actionInstall()` — the extract / transform / load / finalize actions land in Phase 3 (D-05). Drop every v1 import that isn't needed for install (Queue, Entry, MigrationOptions, MigrationReport).

    Concrete file content (paste-ready, follows PATTERNS.md lines 306-355):

    ```php
    <?php

    declare(strict_types=1);

    namespace lameco\kunstmaanmigrator\console;

    use Craft;
    use craft\console\Controller;
    use craft\db\MigrationManager;
    use craft\helpers\Console;
    use lameco\kunstmaanmigrator\NeverProductionTrait;
    use yii\console\ExitCode;

    /**
     * Migrate command — Phase 1 ships only actionInstall (FND-02a programmatic install shim).
     * extract / transform / load / finalize actions land in Phase 3 (D-05).
     */
    class MigrateController extends Controller
    {
        use NeverProductionTrait;

        /**
         * FND-02a: idempotent re-runner for the plugin's own migrations.
         * `./craft plugin/install kunstmaan-migrator` already runs Install.php on first install;
         * this action is the post-install / future-schema-bump shim — needed because Craft 5
         * dropped --migrationPath from the standard migrate command.
         */
        public function actionInstall(): int
        {
            // D-20: every legacy-reading or destructive action gates on NeverProduction first.
            if (($gate = $this->enforceNeverProduction()) !== null) {
                return $gate;
            }

            // PATH NOTE: v2's flat src/console/ is 2 levels deep (vs v1's 3-deep
            // src/bridge/console/controllers/) — so __DIR__ . '/../migrations' reaches
            // src/migrations/. Do NOT use '/../../migrations' (that was v1's path).
            //
            // NAMESPACE NOTE: 'lameco\\kunstmaanmigrator\\migrations' matches src/migrations/
            // under the PSR-4 prefix declared in composer.json (D-04 flat layout).
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
    }
    ```

    Notes for the executor:
    - The plugin's controllerNamespace switch (Plan 02 / D-03) makes `kunstmaan-migrator/migrate/install` resolve to this `actionInstall()` automatically — no controllerMap entry needed.
    - The NeverProduction guard pattern (`if (($gate = $this->enforceNeverProduction()) !== null) { return $gate; }`) is the FIRST line of the action body per D-20.
    - Do NOT add an `actionExtract` / `actionTransform` / `actionLoad` / `actionFinalize` here — those are Phase 3's contract.
  </action>
  <acceptance_criteria>
    - `src/console/MigrateController.php` exists.
    - `grep -q "namespace lameco\\\\kunstmaanmigrator\\\\console;" src/console/MigrateController.php` exits 0.
    - `grep -q "class MigrateController extends Controller" src/console/MigrateController.php` exits 0.
    - NeverProductionTrait imported and used: `grep -q "use lameco\\\\kunstmaanmigrator\\\\NeverProductionTrait;" src/console/MigrateController.php && grep -q "use NeverProductionTrait;" src/console/MigrateController.php`.
    - actionInstall present and gated: `grep -q "function actionInstall(): int" src/console/MigrateController.php && grep -q 'enforceNeverProduction()' src/console/MigrateController.php`.
    - MigrationManager wired correctly: `grep -q "'track'              => 'kunstmaanmigrator'" src/console/MigrateController.php && grep -q "'migrationNamespace' => 'lameco.\\\\kunstmaanmigrator.\\\\migrations'" src/console/MigrateController.php && grep -q "'migrationPath'      => __DIR__ . '/../migrations'" src/console/MigrateController.php`.
    - Phase 3 actions are NOT present: `! grep -E 'function action(Extract|Transform|Load|Finalize|Index|Truncate)' src/console/MigrateController.php`.
    - `php -l src/console/MigrateController.php` exits 0.
    - `php -r 'require __DIR__ . "/vendor/autoload.php"; $rc = new ReflectionClass("lameco\\kunstmaanmigrator\\console\\MigrateController"); $rc->hasMethod("actionInstall") or exit(1); $methods = array_map(fn($m) => $m->getName(), $rc->getMethods(ReflectionMethod::IS_PUBLIC)); $extra = array_filter($methods, fn($n) => str_starts_with($n, "action") && $n !== "actionInstall"); if ($extra) { fwrite(STDERR, "unexpected actions: " . implode(",", $extra) . "\n"); exit(2); }'` exits 0.
  </acceptance_criteria>
  <verify>
    <automated>php -l src/console/MigrateController.php &amp;&amp; php -r 'require __DIR__ . "/vendor/autoload.php"; $rc = new ReflectionClass("lameco\\kunstmaanmigrator\\console\\MigrateController"); $rc->hasMethod("actionInstall") or exit(1);'</automated>
  </verify>
  <done>MigrateController autoloads, exposes only actionInstall, gates on NeverProduction, configures MigrationManager with the correct track / namespace / path for v2's flat layout.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| Operator CLI invocation → MigrateController::actionInstall | Trusted operator surface (CLI is canonical). NeverProduction guard rejects production env. |
| Install migration → Craft project-config | Plugin writes the new field UID into `plugins.kunstmaan-migrator.kunstmaanSourceIdFieldUid`. This is a plugin-owned config path; no risk of trampling other plugins. |
| Install migration → Craft fields service | `getFieldByHandle('kunstmaanSourceId')` is read-only; `saveField()` is a write but it's the plugin's own field handle. |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-1-01 | Elevation of Privilege | `MigrateController::actionInstall` | mitigate | First line of `actionInstall()` calls `$this->enforceNeverProduction()` and returns its non-null result. Verified by acceptance-criteria grep `grep -q 'enforceNeverProduction()'` + ordering: the call appears before any other statement in the action body. Plan 04 adds the doctor-side enforcement; this plan covers the install action. |
| T-1-06 | Tampering | `Install::ensureFieldAndAttach` field UID handling | accept | The UID-reuse path queries Craft's own field service. If a malicious party has already attached a `kunstmaanSourceId` field with a poisoned UID, the migration will reuse that UID — but Craft's project-config layer is the trust boundary, not this plugin. The audit-log `Craft::info()` lines (which branch fired) make poisoning detectable in `storage/logs/`. |
| T-1-07 | Denial of Service | `Install::safeUp` re-run | mitigate | Idempotent guards (`tableExists`, project-config existence, `getFieldByHandle`) ensure re-running install causes no schema churn — protects against accidental DROP via repeated install calls. Verified by acceptance-criteria greps. |

The plan does NOT introduce a Sn / Re / Tampering risk on user input — `actionInstall` takes zero
arguments. The MigrationManager invocation has hardcoded class/track/namespace/path strings; no
user-controlled values reach it.
</threat_model>

<verification>
After both tasks:

1. `composer dump-autoload --no-interaction` picks up new classes.
2. `php -l src/migrations/Install.php src/console/MigrateController.php` both exit 0.
3. The reflection probes from each task's verify confirm class shape.
4. `php -r 'require __DIR__ . "/vendor/autoload.php"; $rc = new ReflectionClass("lameco\\kunstmaanmigrator\\migrations\\Install"); $expected = ["FIELD_HANDLE" => "kunstmaanSourceId", "STATE_TABLE" => "{{%kunstmaanmigrator_state}}", "PROJECT_CONFIG_UID_PATH" => "plugins.kunstmaan-migrator.kunstmaanSourceIdFieldUid"]; foreach ($expected as $k => $v) { if ($rc->getConstant($k) !== $v) { fwrite(STDERR, "constant $k mismatch\n"); exit(1); } } echo "OK\n";'` exits 0.
5. NeverProduction-guard ordering check (regex must match): `grep -A 5 'function actionInstall' src/console/MigrateController.php | head -8 | grep -q 'enforceNeverProduction'`.
</verification>

<success_criteria>
- FND-02 satisfied: state table schema verbatim per D-06 (10 columns + 2 indexes), reusing existing field UID per D-09.
- FND-02a satisfied: `kunstmaan-migrator/migrate/install` action exists and runs MigrationManager on the `kunstmaanmigrator` track.
- FND-03 satisfied: `safeDown()` is a no-op — operator wipes manually for full reset.
- D-07 idempotency: re-running install is a no-op on hosts where the table or field already exists (verified by guard greps).
- D-09 UID reuse: project-config-then-getFieldByHandle chain present and verified.
- D-20 NeverProduction enforcement: actionInstall gates first.
- Both files lint clean and autoload through PSR-4.
- T-1-01 mitigation confirmed (NeverProduction is first action statement).
- T-1-07 idempotency mitigation confirmed (three guards present).
</success_criteria>

<output>
After completion, create `.planning/phases/01-foundation-connectivity/01-03-SUMMARY.md`.
</output>
