---
phase: 03-etl-pipeline-field-handlers
plan: 13
type: execute
wave: 5
depends_on: ['03-01', '03-02', '03-03', '03-04', '03-05', '03-06', '03-07', '03-08', '03-09', '03-10', '03-11', '03-12']
files_modified:
  - src/Plugin.php
  - src/console/MigrateController.php
  - src/console/DoctorController.php
autonomous: false
requirements: [ETL-01, ETL-02, ETL-03, ETL-04, ETL-05, ETL-06, ETL-07, FH-02, FH-03, FIN-01]
must_haves:
  truths:
    - "Plugin::config() registers ~14 new components in the existing components map AFTER the Phase 02.1 block (line 88) — never reordering existing entries (PluginBootstrapTest invariant)."
    - "Plugin::init() sibling-DI block extends with Phase 3 wiring per the 75a95bc pattern: register all 4 PlainTextHandler modes + 4 other handlers; wire AssetHandler/CkeditorRewriter/Extract/Transform/AtomicMigration/AssetMigration/EntryMigration sibling deps."
    - "MigrateController gains 6 actions: actionIndex (default = full pipeline, --live writes), actionExtract, actionTransform, actionLoad, actionFinalize, actionTruncate. Each action gates first on enforceNeverProduction()."
    - "Per-entry progress emission (ETL-06): [N/total] slug → created|updated|skipped|FAILED: reason. FAILED prints to stderr; others to stdout. Plain-text discipline (Phase 1 / D-19)."
    - "actionTruncate honors --entities + --locales filters and requires --live --confirm to actually delete (D-51 wide+safety-rails)."
    - "DoctorController gains 6th check: state-table reachability (per CONTEXT Discretion — included since cheap and catches Phase 1 install drift)."
    - "Mid-phase smoke checkpoint: human-verify task confirms migrate --dry-run --entities=NewsPage stdout output before phase closes."
  artifacts:
    - path: "src/Plugin.php"
      provides: "Phase 3 component registrations + sibling-DI wiring."
      min_lines: 280
    - path: "src/console/MigrateController.php"
      provides: "6 actions: index + 5 stage actions + truncate. ETL-01 through ETL-07."
      min_lines: 400
    - path: "src/console/DoctorController.php"
      provides: "6th check: state-table reachability."
      min_lines: 240
  key_links:
    - from: "src/Plugin.php::init"
      to: "all Phase 3 components"
      via: "75a95bc sibling-DI property assignments"
      pattern: "\\$this->\\(transformService\\|atomicMigrationService\\|extractService\\|fieldHandlerRegistry\\)"
    - from: "src/console/MigrateController.php"
      to: "src/load/EntryMigrationService.php"
      via: "the only saveElement() consumer in the codebase"
      pattern: "EntryMigrationService"
---

<objective>
Phase 3's wiring + operator-surface plan. Three modifications:

1. **`src/Plugin.php`** — extend `Plugin::config()` with ~14 new components AFTER the Phase 02.1 block; extend `Plugin::init()` sibling-DI block with Phase 3 wiring per 75a95bc.
2. **`src/console/MigrateController.php`** — preserve `actionInstall` verbatim (FND-02a); add `actionIndex` (full pipeline) + `actionExtract` + `actionTransform` + `actionLoad` + `actionFinalize` + `actionTruncate`. Filter flag declarations. Per-entry progress emission.
3. **`src/console/DoctorController.php`** — add 6th check `checkStateTable()` per CONTEXT Discretion.

`autonomous: false` — final task is a checkpoint:human-verify for the mid-phase smoke (advisor recommendation: a `migrate --dry-run --entities=NewsPage` smoke before phase closes).

Wave 5 — depends on every prior Phase 3 plan. The chokepoint plan that wires everything together.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/PROJECT.md
@.planning/phases/03-etl-pipeline-field-handlers/03-CONTEXT.md
@.planning/phases/03-etl-pipeline-field-handlers/03-PATTERNS.md
@.planning/phases/02.1-source-introspection/02.1-PATTERNS.md

<interfaces>
**Plugin::config() additions (AFTER line 88's `blockAvailabilityValidator`):**
```php
// Phase 3 additions — ETL pipeline + handlers + finalize.
'fieldHandlerRegistry'    => FieldHandlerRegistry::class,
'plainTextHandler'        => PlainTextHandler::class,    // mode='plain' default; init() registers 4 modes
'assetHandler'            => AssetHandler::class,
'relationHandler'         => RelationHandler::class,
'matrixHandler'           => MatrixHandler::class,
'splitNameHandler'        => SplitNameHandler::class,
'migrationStateService'   => MigrationStateService::class,
'ckeditorRewriterService' => CkeditorRewriterService::class,
'finalizeWalker'          => FinalizeWalker::class,
'extractService'          => ExtractService::class,
'transformService'        => TransformService::class,
'atomicMigrationService'  => AtomicMigrationService::class,
'assetMigrationService'   => AssetMigrationService::class,
'entryMigrationService'   => EntryMigrationService::class,
'attachService'           => AttachService::class,
```

**Plugin::init() additions (sibling-DI per 75a95bc):**
```php
// Phase 3 sibling-DI — every service that depends on another sibling component is wired here.
$registry = $this->fieldHandlerRegistry;
$registry->register(new PlainTextHandler('plain'));
$registry->register(new PlainTextHandler('ckeditor'));
$registry->register(new PlainTextHandler('link'));
$registry->register(new PlainTextHandler('dropdown'));
$registry->register($this->assetHandler);
$registry->register($this->relationHandler);
$registry->register($this->matrixHandler);
$registry->register($this->splitNameHandler);

$this->assetHandler->assetResolver = $this->assetMigrationService;

$this->ckeditorRewriterService->migrationState = $this->migrationStateService;
$this->ckeditorRewriterService->legacyDb       = $this->legacyDbService;
$this->ckeditorRewriterService->assetResolver  = $this->assetMigrationService;

$this->finalizeWalker->rewriter = $this->ckeditorRewriterService;

$this->extractService->legacyDb            = $this->legacyDbService;
$this->extractService->detailTableResolver = $this->detailTableResolver;
$this->extractService->topologicalOrderer  = $this->topologicalOrderer; // verify v2 location

$this->transformService->handlerRegistry   = $this->fieldHandlerRegistry;
$this->transformService->ckeditorRewriter  = $this->ckeditorRewriterService;
$this->transformService->legacyDb          = $this->legacyDbService;
$this->transformService->migrationState    = $this->migrationStateService; // narrows to MigrationStateReader
$this->transformService->assetPathResolver = new AssetPathResolver();      // static-helper carrier

$this->assetMigrationService->legacyDb       = $this->legacyDbService;
$this->assetMigrationService->migrationState = $this->migrationStateService;

$this->atomicMigrationService->migrationStateService = $this->migrationStateService;
$this->atomicMigrationService->entryMigrationService = $this->entryMigrationService;
$this->atomicMigrationService->assetMigrationService = $this->assetMigrationService;

$this->entryMigrationService->stateService = $this->migrationStateService;
$this->entryMigrationService->sites        = $this->resolveSitesMap(); // helper composes LocalePreflight + Settings::$localeMap
```

**MigrateController shape — D-42 per-action 11-step (or N-step) idiom.**
Each action follows: gate-first → filter parse → step emits (`$this->stdout("  OK  step\n", FG_GREEN)`) → ExitCode return.

**Per-entry progress (ETL-06):**
```
[1/547] news/article-foo → created
[2/547] news/article-bar → skipped
[3/547] news/article-baz → FAILED: AssetHandler threw on missing kuma_media:99
```

**`actionTruncate` (D-51):**
- Defaults to `--dry-run` (prints what would be deleted).
- Requires `--live --confirm` to actually delete.
- Honors `--entities` + `--locales` filters.
- Three deletes scoped by filter: state-table rows where `(source IN allowedSources)`; Craft entries with `kunstmaanSourceId` set + entry-type matching filter; assets pulled in by this plugin (state-table `targetType = 'asset'`).
</interfaces>
</context>

<tasks>

<task type="auto">
  <name>Task 1: Extend Plugin.php with Phase 3 components + sibling-DI wiring</name>
  <files>src/Plugin.php</files>
  <read_first>
    - src/Plugin.php (lines 1-165 — full current file; understand existing config() + init() shape and the PluginBootstrapTest invariant on line 70)
    - .planning/phases/03-etl-pipeline-field-handlers/03-PATTERNS.md §22 (Plugin.php extension — full)
    - .planning/phases/03-etl-pipeline-field-handlers/03-PATTERNS.md "Shared Pattern 1" (75a95bc sibling-DI)
    - src/locale/LocalePreflight.php (lines 1-50 — confirm detect() signature for resolveSitesMap helper)
    - src/models/Settings.php (lines 1-115 — confirm $localeMap or similar field name)
    - All Wave 1-4 files for sibling-DI dependency confirmation: src/fields/FieldHandlerRegistry.php, src/load/MigrationStateService.php, src/finalize/CkeditorRewriterService.php, src/finalize/FinalizeWalker.php, src/extract/ExtractService.php, src/transform/TransformService.php, src/load/AtomicMigrationService.php, src/load/AssetMigrationService.php, src/load/EntryMigrationService.php, src/load/AttachService.php, src/load/AssetPathResolver.php
  </read_first>
  <action>
    **Modify** `src/Plugin.php`. Two extensions:

    **A. Extend `Plugin::config()` components map.**

    Locate the existing `'blockAvailabilityValidator' => BlockAvailabilityValidator::class,` line (currently line 88). AFTER that line, add the 14 Phase 3 entries (NEVER before — Phase 1 / D-21 PluginBootstrapTest invariant requires the existing 21 entries to stay byte-for-byte aligned in source order). Use this exact ordering:

    ```php
    // Phase 3 additions — ETL pipeline + handlers + finalize.
    'fieldHandlerRegistry'    => FieldHandlerRegistry::class,
    'plainTextHandler'        => PlainTextHandler::class,
    'assetHandler'            => AssetHandler::class,
    'relationHandler'         => RelationHandler::class,
    'matrixHandler'           => MatrixHandler::class,
    'splitNameHandler'        => SplitNameHandler::class,
    'migrationStateService'   => MigrationStateService::class,
    'ckeditorRewriterService' => CkeditorRewriterService::class,
    'finalizeWalker'          => FinalizeWalker::class,
    'extractService'          => ExtractService::class,
    'transformService'        => TransformService::class,
    'atomicMigrationService'  => AtomicMigrationService::class,
    'assetMigrationService'   => AssetMigrationService::class,
    'entryMigrationService'   => EntryMigrationService::class,
    'attachService'           => AttachService::class,
    ```

    Add the matching `use` statements at the top of the file (alphabetized within their existing groups):
    - `use lameco\kunstmaanmigrator\extract\ExtractService;`
    - `use lameco\kunstmaanmigrator\fields\FieldHandlerRegistry;`
    - `use lameco\kunstmaanmigrator\fields\handlers\AssetHandler;`
    - `use lameco\kunstmaanmigrator\fields\handlers\MatrixHandler;`
    - `use lameco\kunstmaanmigrator\fields\handlers\PlainTextHandler;`
    - `use lameco\kunstmaanmigrator\fields\handlers\RelationHandler;`
    - `use lameco\kunstmaanmigrator\fields\handlers\SplitNameHandler;`
    - `use lameco\kunstmaanmigrator\finalize\CkeditorRewriterService;`
    - `use lameco\kunstmaanmigrator\finalize\FinalizeWalker;`
    - `use lameco\kunstmaanmigrator\load\AssetMigrationService;`
    - `use lameco\kunstmaanmigrator\load\AssetPathResolver;`
    - `use lameco\kunstmaanmigrator\load\AtomicMigrationService;`
    - `use lameco\kunstmaanmigrator\load\AttachService;`
    - `use lameco\kunstmaanmigrator\load\EntryMigrationService;`
    - `use lameco\kunstmaanmigrator\load\MigrationStateService;`
    - `use lameco\kunstmaanmigrator\transform\TransformService;`

    Add `@property-read` lines to the class docblock for IDE / static analysis (one per new component name).

    **B. Extend `Plugin::init()` sibling-DI block.**

    Locate the closing `}` of `init()` (line 150 area) and ADD the Phase 3 sibling-DI block BEFORE the closing brace, AFTER the existing Phase 02.1 block (line 130-149). Apply the wiring template from the `<interfaces>` section above. Mark the block start with:

    ```php
    // Phase 3 / 75a95bc sibling-DI — handlers + ETL services + finalize. See Plan 03-13.
    ```

    **C. Add `private function resolveSitesMap(): array` private helper.**

    The helper composes Phase 2's `LocalePreflight::detect()` + `Settings::$localeMap` (or similar — read Settings.php to confirm exact field name) into the kuma_locale → Craft site handle map that EntryMigrationService.$sites consumes:

    ```php
    /**
     * Build the kuma_locale → Craft site handle map used by EntryMigrationService::$sites.
     * Composes LocalePreflight::detect() (returns kuma_locales) with Settings::$localeMap
     * (returns kuma_locale → site handle override) per Phase 2 / D-28 locale matching ladder.
     */
    private function resolveSitesMap(): array
    {
        $detected = $this->localePreflight->detect();
        $explicit = $this->getSettings()->localeMap ?? [];
        // ... resolve via LocalePreflight matching ladder (explicit map → exact handle/language → language-prefix loose match) ...
        // Returns array<string, string> — kuma_locale code → Craft site handle.
        return $resolved;
    }
    ```

    The exact body must invoke whatever method LocalePreflight provides for resolution. If LocalePreflight only provides `detect()` returning `list<string>` of kuma_locale codes (and not a built-in `resolve(): array` method), the executor implements the matching ladder inline here per Phase 2 / D-28.

    **D. PluginBootstrapTest invariant verification.** Re-read line 70 to confirm:
    ```php
    'legacyDbService' => LegacyDbService::class,      // Phase 1 (literal preserved for PluginBootstrapTest)
    ```
    Must remain byte-for-byte. The Phase 1 / D-21 source-level reflection check asserts this line; Phase 3 additions must not move/edit it.

    **E. Add `declare(strict_types=1);`** is already present at line 3 — preserve.
  </action>
  <verify>
    <automated>php -l src/Plugin.php && grep -c "'legacyDbService' => LegacyDbService::class" src/Plugin.php</automated>
  </verify>
  <done>
    - `src/Plugin.php` `php -l` returns "No syntax errors".
    - File has at least 280 lines (existing 165 + ~115 lines of Phase 3 additions).
    - `grep -c "'legacyDbService' => LegacyDbService::class" src/Plugin.php` returns 1 (PluginBootstrapTest invariant intact).
    - `grep -c "'fieldHandlerRegistry'" src/Plugin.php` returns 1.
    - `grep -c "'transformService'" src/Plugin.php` returns 1.
    - `grep -c "'atomicMigrationService'" src/Plugin.php` returns 1.
    - `grep -c "'extractService'" src/Plugin.php` returns 1.
    - `grep -c "'ckeditorRewriterService'" src/Plugin.php` returns 1.
    - `grep -c "'entryMigrationService'" src/Plugin.php` returns 1.
    - `grep -c "'finalizeWalker'" src/Plugin.php` returns 1.
    - `grep -c "new PlainTextHandler('plain')" src/Plugin.php` returns 1.
    - `grep -c "new PlainTextHandler('ckeditor')" src/Plugin.php` returns 1.
    - `grep -c "new PlainTextHandler('link')" src/Plugin.php` returns 1.
    - `grep -c "new PlainTextHandler('dropdown')" src/Plugin.php` returns 1.
    - `grep -c "register(new PlainTextHandler\\|register(\\$this->" src/Plugin.php` >= 8 (4 PlainText modes + 4 other handlers).
    - `grep -c "->assetResolver = \\$this->assetMigrationService" src/Plugin.php` >= 2 (AssetHandler + CkeditorRewriterService).
    - `grep -c "transformService->handlerRegistry" src/Plugin.php` returns 1.
    - `grep -c "atomicMigrationService->migrationStateService" src/Plugin.php` returns 1.
    - `grep -c "function resolveSitesMap" src/Plugin.php` returns 1.
    - `grep -c "75a95bc sibling-DI" src/Plugin.php` >= 1 (block marker present).
  </done>
</task>

<task type="auto">
  <name>Task 2: Extend MigrateController with 6 actions (index + 5 stage actions) + per-entry progress + truncate safety rails</name>
  <files>src/console/MigrateController.php</files>
  <read_first>
    - src/console/MigrateController.php (lines 1-54 — full current file; preserve actionInstall verbatim per FND-02a)
    - src/console/AnalyzeController.php (lines 1-100 — copy the action-shape pattern: gate-first → filter parse → N-step emits → ExitCode return)
    - .planning/phases/03-etl-pipeline-field-handlers/03-PATTERNS.md §23 (MigrateController extension — full)
    - .planning/phases/03-etl-pipeline-field-handlers/03-PATTERNS.md "Shared Pattern 3" (NeverProductionTrait gate-first idiom)
    - .planning/phases/03-etl-pipeline-field-handlers/03-PATTERNS.md "Shared Pattern 4" (plain-text OK/WARN/FAIL emit + per-entry progress)
    - src/console/AnalyzeController.php (lines 49-100 — filter flag declarations + options() merge pattern)
    - src/Plugin.php (Task 1 output — confirm Phase 3 components reachable via Plugin::getInstance())
  </read_first>
  <action>
    **Modify** `src/console/MigrateController.php`. Extend the existing 54-LOC stub with 6 new actions.

    **A. Preserve `actionInstall()` verbatim.** FND-02a contract — Phase 1 / Plan 03 acceptance grep enforces. Do NOT touch the existing method body.

    **B. Add filter + flag properties + options() declaration** (mirror AnalyzeController):

    ```php
    public bool $live = false;
    public bool $confirm = false;       // for actionTruncate safety rail
    public bool $preloadAssets = false; // FH-03 opt-in
    public ?string $entities = null;
    public ?string $locales = null;
    public ?string $since = null;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), [
            'live', 'confirm', 'preloadAssets', 'entities', 'locales', 'since',
        ]);
    }
    ```

    **C. Add `actionIndex()`** — full pipeline (extract → transform → load → finalize). Default dry-run; `--live` writes.

    Shape (per PATTERNS §23 + D-42 11-step idiom):
    ```php
    public function actionIndex(): int
    {
        if (($gate = $this->enforceNeverProduction()) !== null) return $gate;

        $this->stdout("Migrate: extract → transform → load → finalize\n", Console::FG_CYAN);
        $plugin = Plugin::getInstance();
        $filters = $plugin->filterFactory->fromCli($this->entities, $this->locales, $this->since);

        // Step 1: locale preflight (LOC-02).
        // Step 2: load mapping.yaml + coverage gate (MAP-06).
        // Step 3: extract (ETL-03 topological order via TopologicalOrderer).
        // Step 4: transform (per-entry resolver loop).
        // Step 5: load (per-entry atomic migration + JIT asset materialisation OR --preload-assets batch).
        // Step 6: finalize (CKEditor token resolution pass).
        // Step 7: REPORT.md (D-50 failures + D-52 counts via MigrationReport).

        return ExitCode::OK;
    }
    ```

    For each step, emit a stdout line per Shared Pattern 4: `  OK   <step name>` (FG_GREEN), `  WARN <step name> — <detail>` (FG_YELLOW), `  FAIL <step name> — <detail>` (FG_RED).

    **D. Add `actionExtract()` / `actionTransform()` / `actionLoad()` / `actionFinalize()`** — per-stage resume actions per ETL-02. Each gates first, parses filters, runs only that stage's primitive against the in-process pipeline. Per CONTEXT D-48: standalone `actionLoad` re-runs extract+transform internally (lazy/streaming) and skips state-recorded entries.

    **E. Add `actionTruncate()`** — D-51 wide+safety-rails:
    ```php
    public function actionTruncate(): int
    {
        if (($gate = $this->enforceNeverProduction()) !== null) return $gate;

        if (!$this->live || !$this->confirm) {
            $this->stdout("DRY RUN — would delete the following (use --live --confirm to actually delete):\n", Console::FG_YELLOW);
            // Walk and print scopes.
            return ExitCode::OK;
        }

        // Honors --entities + --locales (no "nuke everything ever migrated by this plugin" footgun).
        // Three scoped deletes:
        //   1. state-table rows where source IN scoped sources
        //   2. Craft entries with kunstmaanSourceId set + entry-type matching filter
        //   3. assets pulled in by this plugin (state targetType='asset' + scoped)
        // Sequence: delete state rows LAST so the operator can re-run if mid-truncate fails.

        return ExitCode::OK;
    }
    ```

    **F. Per-entry progress emission (ETL-06).** Inside actionLoad's per-entry loop, emit:
    ```
    [N/total] <slug> → <verb>
    ```
    where verb is `created` / `updated` / `skipped` / `FAILED: <reason>`. `FAILED:` prints to stderr (FG_RED) + collects via `$report->recordFailure(...)`. Other verbs print to stdout (FG_GREEN).

    **G. REPORT.md emission at end of actionIndex / actionLoad.** Use `Plugin::getInstance()->mappingFile->writeAtomic($storageDir . '/REPORT.md', $rendered)` with the D-52 counts table + D-50 failures section appended to the existing Phase 2 REPORT.md content. (Phase 2 already writes REPORT.md; Phase 3 adds the migration-counts + failures sections at the bottom of an existing render or via a new render+write cycle.)

    **H. NeverProductionTrait gate-first** (Shared Pattern 3): every action's first non-comment statement is the `if (($gate = $this->enforceNeverProduction()) !== null) { return $gate; }` guard.

    **I. `use NeverProductionTrait;`** is already present (line 20). Preserve.

    DO NOT change actionInstall body. DO NOT remove the existing `use Craft;` / `use craft\console\Controller;` / `use craft\db\MigrationManager;` / `use craft\helpers\Console;` imports.
  </action>
  <verify>
    <automated>php -l src/console/MigrateController.php && grep -c "function actionInstall" src/console/MigrateController.php</automated>
  </verify>
  <done>
    - `src/console/MigrateController.php` `php -l` returns "No syntax errors".
    - File has at least 400 lines.
    - `grep -c "function actionInstall" src/console/MigrateController.php` returns 1 (FND-02a preserved).
    - `grep -c "function actionIndex" src/console/MigrateController.php` returns 1.
    - `grep -c "function actionExtract" src/console/MigrateController.php` returns 1.
    - `grep -c "function actionTransform" src/console/MigrateController.php` returns 1.
    - `grep -c "function actionLoad" src/console/MigrateController.php` returns 1.
    - `grep -c "function actionFinalize" src/console/MigrateController.php` returns 1.
    - `grep -c "function actionTruncate" src/console/MigrateController.php` returns 1.
    - `grep -c "enforceNeverProduction" src/console/MigrateController.php` >= 7 (gate-first on every action — install + 6 new).
    - `grep -c "public bool \\$live = false" src/console/MigrateController.php` returns 1.
    - `grep -c "public bool \\$confirm = false" src/console/MigrateController.php` returns 1.
    - `grep -c "public bool \\$preloadAssets = false" src/console/MigrateController.php` returns 1.
    - `grep -c "public ?string \\$entities = null" src/console/MigrateController.php` returns 1.
    - `grep -c "public ?string \\$locales = null" src/console/MigrateController.php` returns 1.
    - `grep -c "public ?string \\$since = null" src/console/MigrateController.php` returns 1.
    - `grep -c "filterFactory->fromCli" src/console/MigrateController.php` >= 1.
    - `grep -c "DRY RUN" src/console/MigrateController.php` >= 1 (truncate safety rail).

    **EntryMigrationService monopoly enforcement (the only saveElement consumer):**
    - `grep -rn "saveElement" src/ | grep -v 'src/load/EntryMigrationService.php' | grep -v 'src/finalize/FinalizeWalker.php'` returns zero matches. (FinalizeWalker is the documented exception per Plan 03-06 — its CKEditor-field re-save is scoped to already-saved entries and uses propagate=false; the discipline holds.)
  </done>
</task>

<task type="auto">
  <name>Task 3: Extend DoctorController with 6th check — checkStateTable</name>
  <files>src/console/DoctorController.php</files>
  <read_first>
    - src/console/DoctorController.php (lines 1-220 — full current file; understand existing 5-check shape from Phase 02.1)
    - .planning/phases/03-etl-pipeline-field-handlers/03-PATTERNS.md §24 (DoctorController 6th check — full code template)
    - src/migrations/Install.php (lines 1-60 — confirm STATE_TABLE constant)
  </read_first>
  <action>
    **Modify** `src/console/DoctorController.php`. Extend the 5-check chain with a 6th `checkStateTable()`.

    Locate the `&&`-chained checks block (lines 56-60 area):
    ```php
    $ok = $this->checkLegacyDb()             && $ok;
    $ok = $this->checkApiKey()               && $ok;
    $ok = $this->checkStorageDir()           && $ok;
    $ok = $this->checkMappingFile()          && $ok;
    $ok = $this->checkKunstmaanSourcePath()  && $ok;
    ```

    Add a 6th line AFTER the 5th:
    ```php
    $ok = $this->checkStateTable()           && $ok;
    ```

    Add the `checkStateTable()` method body — mirror the shape of `checkMappingFile()` (line 160-180 area):
    ```php
    private function checkStateTable(): bool
    {
        try {
            $tableName = '{{%kunstmaanmigrator_state}}';
            if (!Craft::$app->db->getTableSchema($tableName)) {
                $this->stderr(
                    "  FAIL state table '{$tableName}' missing — run "
                    . "`./craft kunstmaan-migrator/migrate/install` first.\n",
                    Console::FG_RED,
                );
                return false;
            }
            // Probe writability with a no-op SELECT against the table.
            Craft::$app->db->createCommand("SELECT COUNT(*) FROM {$tableName}")->queryScalar();
            $this->stdout("  OK   kunstmaanmigrator_state table reachable\n", Console::FG_GREEN);
            return true;
        } catch (\Throwable $e) {
            $this->stderr("  FAIL state table check: {$e->getMessage()}\n", Console::FG_RED);
            return false;
        }
    }
    ```

    DO NOT change: any existing check method body, the `enforceNeverProduction()` gate-first call, the existing `&&` chain (only ADD a 6th line, do not reorder).
  </action>
  <verify>
    <automated>php -l src/console/DoctorController.php</automated>
  </verify>
  <done>
    - `src/console/DoctorController.php` `php -l` returns "No syntax errors".
    - `grep -c "function checkStateTable" src/console/DoctorController.php` returns 1.
    - `grep -c "kunstmaanmigrator_state" src/console/DoctorController.php` >= 1.
    - `grep -c "checkLegacyDb\\|checkApiKey\\|checkStorageDir\\|checkMappingFile\\|checkKunstmaanSourcePath\\|checkStateTable" src/console/DoctorController.php` >= 6 (all 6 checks present).
    - `grep -c "&& \\$ok" src/console/DoctorController.php` >= 6 (6 chained checks).
  </done>
</task>

<task type="checkpoint:human-verify" gate="blocking">
  <name>Task 4: Mid-phase smoke verification — migrate --dry-run --entities=NewsPage against CQM rehearsal</name>
  <what-built>
    Phase 3 wave 5 wired: all components registered, sibling-DI complete, MigrateController exposes 6 actions, DoctorController has 6 checks. The full ETL pipeline is now executable end-to-end against the CQM rehearsal pair (cqm-website source + cqm-craft-website target).
  </what-built>
  <how-to-verify>
    The advisor recommended a mid-phase smoke before phase closes. Run from the consumer site (cqm-craft-website):

    1. **Doctor check** — confirm Phase 3 components register cleanly:
       ```bash
       cd ~/Sites/cqm-craft-website
       ./craft kunstmaan-migrator/doctor
       ```
       Expect: 6 OK lines (legacy DB, Anthropic key, storage/migration writable, mapping.yaml present, Kunstmaan source path, kunstmaanmigrator_state reachable). Exit code 0.

    2. **Dry-run pipeline** — confirm extract → transform → load → finalize stages all execute without error:
       ```bash
       ./craft kunstmaan-migrator/migrate --dry-run --entities=NewsPage --locales=nl
       ```
       Expect: per-entry progress lines `[N/total] <slug> → <verb>`. No assertion on count exactness yet (UAT round closes the phase).

    3. **Inspect REPORT.md** — confirm D-52 counts table + D-50 failures section:
       ```bash
       cat storage/migration/REPORT.md | tail -60
       ```
       Expect: a `## Migration counts` section with NewsPage row showing Created/Updated/Skipped/Failed columns.

    4. **Verify the saveElement monopoly invariant**:
       ```bash
       cd ~/Sites/craft-kunstmaan-migrator-revisited
       grep -rn 'saveElement' src/ | grep -v 'src/load/EntryMigrationService.php' | grep -v 'src/finalize/FinalizeWalker.php'
       ```
       Expect: zero matches. (FinalizeWalker is the documented exception.)

    5. **Verify the page-part ordering RECONCILIATION drift entry exists**:
       ```bash
       grep -c "ORDER BY context, sequencenumber" .planning/phases/03-etl-pipeline-field-handlers/03-04-extract-service-PLAN.md
       ```
       Expect: >= 1.

    Approval criteria: doctor exits 0; dry-run completes without uncaught exception; REPORT.md renders; saveElement monopoly invariant holds.
  </how-to-verify>
  <resume-signal>Type "approved" if all 5 checks pass, or describe issues for follow-up.</resume-signal>
</task>

</tasks>

<reconciliation>
## Plugin.php reconciliation

**v1 file:** `~/Sites/craft-kunstmaan-migrator/src/Plugin.php` (closure-DI pattern at v1 lines 235-294).
**v2 file:** `src/Plugin.php` (existing 165-line file; Phase 3 extends to ~280 LOC).

| v1 Rule | Description | Disposition | v2 location / rationale |
|---|---|---|---|
| Lines 235-294 — closure-DI via `$this->set('extractService', function() use ($self) { ... })` | v1 used closures to inject sibling deps. | **dropped intentionally — replaced with init() property-injection per 75a95bc** | Closures defeat the bare-class-strings-in-config() invariant that PluginBootstrapTest depends on. v2 init() does direct property assignment. Documented in PATTERNS §22. |
| Hardcoded sites map `['nl' => 'default', 'en' => 'en']` at v1 line 292 | v1 baked a CQM-specific locale map. | dropped intentionally — replaced with resolveSitesMap() helper composing LocalePreflight + Settings::$localeMap | Greenfield-friendly; no project-specific defaults. |

## MigrateController reconciliation

**v1 reference:** v1 had multiple controllers (extract / transform / load / finalize / verify / etc.) — ~20+ commands per CLAUDE.md.
**v2 file:** `src/console/MigrateController.php` (existing 54-LOC stub; Phase 3 extends to ~400 LOC with 6 actions).

| v1 Rule | Description | Disposition | v2 location / rationale |
|---|---|---|---|
| 20+ separate console commands | v1 surface area. | reshape — collapsed to 6 actions | PROJECT.md decision: ~5 commands in v2 (doctor / analyze / map / migrate / verify). Phase 3 ships migrate's 6 actions (index + 5 stage actions per ETL-02). |
| Per-stage commands | Resume support. | ported (reshape) — 5 sub-actions on single MigrateController | ETL-02 satisfied. |
| Per-entry progress lines | v1 emitted via Console::output. | ported verbatim shape — `[N/total] slug → verb` (FG_GREEN/FG_RED) | ETL-06 + Shared Pattern 4. |

## DoctorController reconciliation

**v1 reference:** v1's doctor had a queue-worker check + 4 base checks. v2 dropped queue-worker per PROJECT.md and added Kunstmaan-source check (Phase 02.1 / D-31). Phase 3 adds 6th: state-table reachability.

| Rule | Description | Disposition |
|---|---|---|
| 6th check `checkStateTable()` | greenfield in v2 — Phase 3 / CONTEXT Discretion | new in v2 |

### Counts (Plan 03-13 only)
| Pair | ported | dropped intentionally | dropped accidentally | new in v2 |
|---|---:|---:|---:|---:|
| Plugin.php | 0 | 2 (closure-DI, hardcoded sites map) | 0 | 1 (75a95bc init() pattern) |
| MigrateController | 1 (per-entry progress shape) | 0 | 0 | 1 (6-action shape collapsing 20+ v1 commands) |
| DoctorController | 0 | 0 | 0 | 1 (state-table 6th check) |
</reconciliation>

<verification>
- All 3 modified files `php -l` exit 0.
- Plugin.php preserves PluginBootstrapTest invariant (legacyDbService line at line 70 byte-for-byte).
- MigrateController preserves actionInstall (FND-02a) verbatim.
- All 6 MigrateController actions gate first on enforceNeverProduction.
- DoctorController has 6 checks chained.
- Mid-phase smoke checkpoint requires human approval before phase closes.
- saveElement monopoly invariant verified by phase-level grep.
</verification>

<success_criteria>
- Plugin::config() registers 14 new components after the Phase 02.1 block.
- Plugin::init() sibling-DI wiring covers every Phase 3 service (75a95bc pattern).
- MigrateController exposes 6 actions: index + extract + transform + load + finalize + truncate, plus the preserved actionInstall (7 total).
- DoctorController has 6 checks: existing 5 + state-table.
- Per-entry progress emission (ETL-06) + REPORT.md counts (D-52) + failures section (D-50).
- actionTruncate D-51 safety rails: defaults to dry-run; --live --confirm to delete; honors filter flags.
- saveElement monopoly invariant: only EntryMigrationService + FinalizeWalker call saveElement.
- Mid-phase smoke checkpoint passes against CQM rehearsal (doctor 6/6 + dry-run + REPORT.md render).
</success_criteria>

<output>
Create `.planning/phases/03-etl-pipeline-field-handlers/03-13-controller-and-wiring-SUMMARY.md`. Note the human-verified smoke result.
</output>
