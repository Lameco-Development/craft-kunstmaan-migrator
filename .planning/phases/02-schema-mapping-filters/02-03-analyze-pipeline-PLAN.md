---
phase: 02-schema-mapping-filters
plan: 03
type: execute
wave: 3
depends_on:
  - "02-01"
  - "02-02"
files_modified:
  - src/analyze/SchemaDumper.php
  - src/analyze/HeuristicProposer.php
  - src/analyze/LlmClassifier.php
  - src/analyze/ReportBuilder.php
  - src/console/AnalyzeController.php
  - src/Plugin.php
autonomous: true
requirements:
  - MAP-01
  - MAP-02
  - MAP-03
  - MAP-04
  - MAP-05
  - LOC-01
  - FILT-03
requirements_addressed:
  - MAP-01
  - MAP-02
  - MAP-03
  - MAP-04
  - MAP-05
  - LOC-01
  - FILT-03
must_haves:
  truths:
    - "analyze runs against legacy DB and writes storage/migration/schema-dump.json + storage/migration/REPORT.md"
    - "analyze populates mapping.yaml with proposals from 9 deterministic heuristics first, then Anthropic for residuals"
    - "Heuristic-high proposals land status: accepted (D-02 trust calibration)"
    - "LLM proposals land status: proposed/needs-review per confidence tier (D-02)"
    - "Re-running analyze preserves existing operator-decided rows verbatim (D-04 skip-existing)"
    - "REPORT.md contains a paste-ready Craft sites: block when locales are unmapped (D-17 LOC-01)"
    - "AnalyzeController gates on enforceNeverProduction first, then calls LocalePreflight::ensure"
    - "AnalyzeController accepts --entities, --locales, --since (FILT-03), --no-ai, --auto-accept-high (MAP-05), --audit-strict"
    - "Plugin.php registers schemaDumper, heuristicProposer, llmClassifier, reportBuilder"
  artifacts:
    - path: "src/analyze/SchemaDumper.php"
      provides: "Pure transform: legacy DB → schema-dump array"
      contains: "final class SchemaDumper extends Component"
    - path: "src/analyze/HeuristicProposer.php"
      provides: "9 deterministic heuristics (port v1 verbatim)"
      contains: "final class HeuristicProposer"
      preserves: "DUTCH_ALIASES const and 9-heuristic ordering verbatim"
    - path: "src/analyze/LlmClassifier.php"
      provides: "Anthropic Haiku batch caller (port v1 verbatim)"
      contains: "final class LlmClassifier"
      preserves: "v1 defaults: maxTokens=4096, timeoutSeconds=60, ANTHROPIC_VERSION_HEADER, batch=10, sleep=20"
    - path: "src/analyze/ReportBuilder.php"
      provides: "Schema-dump → REPORT.md markdown including paste-ready locales block"
      contains: "final class ReportBuilder extends Component"
    - path: "src/console/AnalyzeController.php"
      provides: "Single actionIndex() orchestrating the analyze pipeline"
      contains: "class AnalyzeController extends Controller"
    - path: "src/Plugin.php"
      provides: "Registers 4 new analyze components"
      contains: "'schemaDumper'"
  key_links:
    - from: "src/console/AnalyzeController.php"
      to: "src/locale/LocalePreflight.php"
      via: "Plugin::getInstance()->localePreflight->ensure(\$filters)"
      pattern: "localePreflight->ensure"
    - from: "src/console/AnalyzeController.php"
      to: "src/mapping/MappingFile.php"
      via: "Plugin::getInstance()->mappingFile->merge / writeAtomicJson / writeAtomic"
      pattern: "mappingFile->"
    - from: "src/analyze/SchemaDumper.php"
      to: "src/db/LegacyDbService.php"
      via: "Plugin::getInstance()->legacyDbService->queryAll for information_schema; streamQuery for samples"
      pattern: "streamQuery"
    - from: "src/analyze/LlmClassifier.php"
      to: "Anthropic API"
      via: "https://api.anthropic.com/v1/messages with anthropic-version header"
      pattern: "https://api.anthropic.com/v1/messages"
---

<objective>
Ship the `analyze` pipeline that turns a legacy Kunstmaan DB into a populated mapping.yaml.

Purpose: Port v1's HeuristicProposer (407 LOC) and LlmClassifier (481 LOC) near-verbatim under v2's flatter `lameco\kunstmaanmigrator\analyze` namespace; build a fresh SchemaDumper service that uses LegacyDbService::streamQuery for sample collection (D-related streaming threat); build a fresh ReportBuilder that produces REPORT.md with the paste-ready locales block per D-17; collapse v1's 9-sub-action AnalyzeController (2138 LOC) into a single `actionIndex()` orchestration per CONTEXT.md operator workflow. Wire all four services into `Plugin::config()`.

Output: 5 new files (4 services + 1 controller), `Plugin.php` modified to register the four services.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/PROJECT.md
@.planning/phases/02-schema-mapping-filters/02-CONTEXT.md
@.planning/phases/02-schema-mapping-filters/02-PATTERNS.md

@src/Plugin.php
@src/models/Settings.php
@src/db/LegacyDbService.php
@src/console/DoctorController.php
@src/filter/FilterFactory.php
@src/filter/MigrationFilters.php
@src/locale/LocalePreflight.php
@src/mapping/MappingFile.php

<interfaces>
<!-- v1 brownfield reference paths (read-only) -->
- ~/Sites/craft-kunstmaan-migrator/src/bridge/services/HeuristicProposer.php (407 LOC — port verbatim, namespace flatten only)
- ~/Sites/craft-kunstmaan-migrator/src/bridge/services/LlmClassifier.php (481 LOC — port near-verbatim, status-on-row tweak)
- ~/Sites/craft-kunstmaan-migrator/src/bridge/console/controllers/AnalyzeController.php
  - lines 70-87: options() flag declaration shape
  - lines 352-419: actionDetectLocales (locale detection SQL — already in LocalePreflight from Plan 01)
  - lines 386-417: paste-ready sites: block heuristic (port into ReportBuilder)
  - lines 537-660: actionProposeMappings orchestration shape
  - lines 1769-1888: collectMappingDrifts (deferred to Plan 05 MappingAuditor)
  - lines 1942-2050: buildSchemaDump algorithm (port into SchemaDumper)
  - lines 1897-1934: actionIndex orchestration shape (port simplified)

<!-- From src/console/DoctorController.php — class header / NeverProduction gate idiom -->
class DoctorController extends Controller
{
    use NeverProductionTrait;
    public function actionIndex(): int
    {
        if (($gate = $this->enforceNeverProduction()) !== null) { return $gate; }
        // ...
    }
}

<!-- From src/db/LegacyDbService.php (Phase 1) -->
public function queryAll(string $sql, array $params = []): array;
public function streamQuery(string $sql, array $params = []): Generator;

<!-- From src/mapping/MappingFile.php (Plan 02) -->
public function load(?string $path = null): array;
public function buildRow(array $proposal, string $initialStatus): array;
public function merge(array $existing, array $incoming): array;
public function writeAtomic(string $path, string $contents): bool;
public function writeAtomicJson(string $path, array $data): bool;
public function resolvePath(): string;

<!-- From src/locale/LocalePreflight.php (Plan 01) -->
public function detect(): array;
public function ensure(MigrationFilters $filters): ?array;

<!-- From src/filter/FilterFactory.php (Plan 01) -->
public function fromCli(?string $entitiesArg, ?string $localesArg, ?string $sinceArg): MigrationFilters;
</interfaces>
</context>

<tasks>

<task type="auto">
  <name>Task 1: Port HeuristicProposer + LlmClassifier verbatim under analyze namespace</name>
  <files>src/analyze/HeuristicProposer.php, src/analyze/LlmClassifier.php</files>
  <read_first>
    - .planning/phases/02-schema-mapping-filters/02-CONTEXT.md (D-02 confidence-tier table; D-CONTEXT "Keep v1's defaults" — chunk 10, sleep 20s, timeout 60s)
    - .planning/phases/02-schema-mapping-filters/02-PATTERNS.md ("src/analyze/HeuristicProposer.php" section, lines 302–316; "src/analyze/LlmClassifier.php" section, lines 320–356)
    - ~/Sites/craft-kunstmaan-migrator/src/bridge/services/HeuristicProposer.php (FULL FILE — to be ported verbatim, all 407 LOC)
    - ~/Sites/craft-kunstmaan-migrator/src/bridge/services/LlmClassifier.php (FULL FILE — to be ported with API key resolution + status-on-row tweaks)
    - src/models/Settings.php (anthropicApiKey, llmModel, llmTimeout properties — Phase 1 / D-15)
  </read_first>
  <action>
**Step A — port HeuristicProposer.php verbatim:**

1. Copy `~/Sites/craft-kunstmaan-migrator/src/bridge/services/HeuristicProposer.php` to `src/analyze/HeuristicProposer.php`.
2. Change ONLY the namespace declaration:
   - From: `namespace lameco\kunstmaanmigrator\bridge\services;`
   - To: `namespace lameco\kunstmaanmigrator\analyze;`
3. Update any `use` statements that reference `lameco\kunstmaanmigrator\bridge\…` to drop the `bridge\` segment if applicable. If the file has no such uses, leave imports alone.
4. Do NOT reorder the 9 heuristics (zero-fill → exact name → *_id → TEXT → *_image → *_date → *_url → *_email → Dutch alias). Per CONTEXT.md "Don't reshuffle without a reason."
5. Do NOT modify the `DUTCH_ALIASES` const (lines 42–56 in v1).
6. Do NOT modify `handlerForClassification()` match expression.
7. Keep `final class HeuristicProposer` declaration.

The class is consumed in Task 4's AnalyzeController via `Plugin::getInstance()->heuristicProposer->autoMatch(...)`. The output rows are then passed through `MappingFile::buildRow($proposal, $status)` where the orchestrator applies the D-02 confidence-tier → status mapping. HeuristicProposer itself stays pure.

**Step B — port LlmClassifier.php with adjustments:**

1. Copy `~/Sites/craft-kunstmaan-migrator/src/bridge/services/LlmClassifier.php` to `src/analyze/LlmClassifier.php`.
2. Change namespace: `namespace lameco\kunstmaanmigrator\bridge\services;` → `namespace lameco\kunstmaanmigrator\analyze;`.
3. Drop `bridge\` segments from any internal `use` statements.
4. **Replace direct `App::env('ANTHROPIC_API_KEY')` reads with Settings-then-env per Phase 1 / D-14:**

   Find any block resembling:
   ```php
   $apiKey = (string) App::env('ANTHROPIC_API_KEY');
   if ($apiKey === '') {
       throw new MappingProposalException('ANTHROPIC_API_KEY is not set.');
   }
   ```
   Replace with:
   ```php
   $settings = \lameco\kunstmaanmigrator\Plugin::getInstance()->getSettings();
   $apiKey = (string) ($settings->anthropicApiKey ?? '');
   if ($apiKey === '') {
       throw new MappingProposalException(
           'ANTHROPIC_API_KEY is not set. Set it in .env or plugin settings, or re-run with --no-ai.',
       );
   }
   ```
   (Settings::init() already env-merges per Phase 1, so reading the property is sufficient.)

5. **Wire llmModel / llmTimeout overrides from Settings (REQUIRED, not optional):** v1 has hardcoded `$this->defaultModel = 'claude-haiku-4-5';` / `timeoutSeconds = 60;`. You MUST add a constructor (or `init()` for Yii component shape) that reads `lameco\kunstmaanmigrator\Plugin::getInstance()->getSettings()->llmModel` and `->llmTimeout` and assigns to the same internal properties when the Settings value is non-null/non-empty. The override mechanism (constructor vs init() vs setter) is at your discretion; the wiring itself is mandatory in this task. Acceptance criterion below greps for the Settings reference in LlmClassifier.php — the task is not complete without it.

6. **Keep verbatim** (per CONTEXT.md "Keep v1's defaults"):
   - `defaultModel = 'claude-haiku-4-5'`
   - `maxTokens = 4096`
   - `timeoutSeconds = 60`
   - `ANTHROPIC_ENDPOINT = 'https://api.anthropic.com/v1/messages'`
   - `ANTHROPIC_VERSION_HEADER = '2023-06-01'`
   - Batching: chunk size 10, inter-batch sleep 20s
   - Retry/backoff: 3 retries on 429, 15s/30s/45s exponential, honor retry-after

7. If the v1 file references `MappingProposalException` (or similar) from a `bridge\…` namespace, port that exception class too into `src/analyze/` with the namespace flattened. If v1's MappingProposalException pulls in additional dependencies, copy those minimally — keep the port self-contained under `src/analyze/`.

8. Per D-02, do NOT assign a `status` field inside LlmClassifier output. The classifier emits `{table, column, targetEntryType, targetHandle, handler, confidence, rationale}` per row; Task 4's AnalyzeController applies the confidence-tier → status mapping when building the row via `MappingFile::buildRow($proposal, $status)`.
  </action>
  <verify>
    <automated>php -l src/analyze/HeuristicProposer.php && php -l src/analyze/LlmClassifier.php</automated>
  </verify>
  <acceptance_criteria>
    - `php -l src/analyze/HeuristicProposer.php` exits 0
    - `php -l src/analyze/LlmClassifier.php` exits 0
    - `grep -c 'namespace lameco\\\\kunstmaanmigrator\\\\analyze;' src/analyze/HeuristicProposer.php` equals 1
    - `grep -c 'namespace lameco\\\\kunstmaanmigrator\\\\analyze;' src/analyze/LlmClassifier.php` equals 1
    - `grep -c 'final class HeuristicProposer' src/analyze/HeuristicProposer.php` equals 1
    - `grep -c 'final class LlmClassifier' src/analyze/LlmClassifier.php` equals 1
    - `grep -c 'DUTCH_ALIASES' src/analyze/HeuristicProposer.php` is 1 or more (the const must be present)
    - `grep -c '2023-06-01' src/analyze/LlmClassifier.php` equals 1 (anthropic-version header preserved)
    - `grep -c 'claude-haiku-4-5' src/analyze/LlmClassifier.php` equals 1 (default model preserved)
    - `grep -c '4096' src/analyze/LlmClassifier.php` is 1 or more (maxTokens preserved)
    - `grep -c 'https://api.anthropic.com/v1/messages' src/analyze/LlmClassifier.php` equals 1
    - `grep -c 'getSettings()->anthropicApiKey' src/analyze/LlmClassifier.php` equals 1 (Settings-then-env)
    - `grep -cE 'getSettings\(\)->llmModel|->llmModel' src/analyze/LlmClassifier.php` is 1 or more (Settings::llmModel override wired per Step 5; mandatory)
    - `grep -cE 'getSettings\(\)->llmTimeout|->llmTimeout' src/analyze/LlmClassifier.php` is 1 or more (Settings::llmTimeout override wired per Step 5; mandatory)
    - `grep -c "App::env('ANTHROPIC_API_KEY')" src/analyze/LlmClassifier.php` equals 0 (must NOT read env directly; reads via Settings)
    - `grep -c 'bridge\\\\services' src/analyze/HeuristicProposer.php` equals 0 (namespace flattened)
    - `grep -c 'bridge\\\\services' src/analyze/LlmClassifier.php` equals 0 (namespace flattened)
  </acceptance_criteria>
  <done>HeuristicProposer + LlmClassifier ported under analyze namespace; v1 defaults preserved; Settings-then-env for API key; PHP lint clean.</done>
</task>

<task type="auto">
  <name>Task 2: Build SchemaDumper service (legacy DB → schema-dump array; uses streaming for samples)</name>
  <files>src/analyze/SchemaDumper.php</files>
  <read_first>
    - .planning/phases/02-schema-mapping-filters/02-CONTEXT.md (Claude's Discretion section on schema-dump.json shape; D-13 filter integration)
    - .planning/phases/02-schema-mapping-filters/02-PATTERNS.md ("src/analyze/SchemaDumper.php" section, lines 250–298)
    - ~/Sites/craft-kunstmaan-migrator/src/bridge/console/controllers/AnalyzeController.php (lines 1942–2050+: buildSchemaDump algorithm reference; SQL shape for information_schema; sample collection)
    - src/db/LegacyDbService.php (queryAll + streamQuery — Phase 1)
    - src/filter/MigrationFilters.php (consumed as method arg)
  </read_first>
  <action>
Create `src/analyze/SchemaDumper.php` as a `final` Yii Component with one public method `dump(MigrationFilters $filters): array`. Returns the schema dump array shape (does NOT write to disk — caller writes via MappingFile::writeAtomicJson). Use `LegacyDbService::queryAll` for `information_schema` reads (small, bounded result) and `LegacyDbService::streamQuery` for sample collection on potentially-large tables (D threat — DoS via OOM on big tables).

Exact contents (~120 LOC; flesh out from v1's buildSchemaDump but adapt for streaming):

```php
<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\analyze;

use Craft;
use lameco\kunstmaanmigrator\Plugin;
use lameco\kunstmaanmigrator\filter\MigrationFilters;
use yii\base\Component;

/**
 * Reads the legacy MySQL schema and returns a structured dump array.
 *
 * Output shape:
 *   [
 *     'generatedAt' => '2026-04-25T17:30:00+00:00',
 *     'driver'      => 'mysql',
 *     'tables'      => [ '<tableName>' => <rowCount>, ... ],
 *     'columns'     => [ '<tableName>' => [ {column, sqlType, fillRate, samples}, ... ], ... ],
 *     'locales'     => [ 'nl', 'fr', ... ],
 *   ]
 *
 * Samples are collected via `LegacyDbService::streamQuery` (D-related threat: SELECT *
 * on a 5k-entry table would OOM with queryAll; the generator yields rows one at a time).
 *
 * Filter integration (D-13): when $filters->entities is non-empty, only those table names
 * pass through. The Kunstmaan-class → table-name resolution lives in the caller; this
 * service receives a pre-filtered list via filtering on `kuma_*` table prefix and entities.
 */
final class SchemaDumper extends Component
{
    /** @var int Max distinct sample values to collect per column. */
    public int $maxSamplesPerColumn = 5;

    /** @var int Max rows to scan per table during sample collection. */
    public int $maxRowsScanPerTable = 1000;

    /**
     * @return array{
     *   generatedAt: string,
     *   driver:      string,
     *   tables:      array<string, int>,
     *   columns:     array<string, list<array<string, mixed>>>,
     *   locales:     list<string>,
     * }
     */
    public function dump(MigrationFilters $filters): array
    {
        $db = Plugin::getInstance()->legacyDbService;
        $conn = $db->db();
        $driver = $conn->getDriverName();
        $schema = $this->extractSchemaName($conn->dsn);

        // 1. List candidate tables (kuma_* prefix). Apply entities filter if set.
        $allTables = $this->listKunstmaanTables($schema);
        $tables = $this->applyEntitiesFilter($allTables, $filters);

        // 2. Per-table row count.
        $rowCounts = [];
        foreach ($tables as $t) {
            $count = (int) $db->queryScalar("SELECT COUNT(*) FROM `{$t}`");
            $rowCounts[$t] = $count;
        }

        // 3. Per-table column metadata (information_schema).
        $columnsByTable = [];
        foreach ($tables as $t) {
            $cols = $db->queryAll(
                'SELECT COLUMN_NAME, DATA_TYPE FROM information_schema.columns WHERE TABLE_SCHEMA = :s AND TABLE_NAME = :t ORDER BY ORDINAL_POSITION',
                [':s' => $schema, ':t' => $t],
            );
            $columnsByTable[$t] = [];
            foreach ($cols as $col) {
                $colName = (string) ($col['COLUMN_NAME'] ?? '');
                $sqlType = (string) ($col['DATA_TYPE'] ?? '');
                if ($colName === '') { continue; }
                $columnsByTable[$t][] = [
                    'column'   => $colName,
                    'sqlType'  => $sqlType,
                    'fillRate' => 0.0,
                    'samples'  => [],
                ];
            }
        }

        // 4. Per-table sample collection via streamQuery (T-2-09 streaming).
        foreach ($columnsByTable as $t => &$cols) {
            $rowCount = $rowCounts[$t] ?? 0;
            if ($rowCount === 0) { continue; }
            $scanLimit = min($rowCount, $this->maxRowsScanPerTable);

            // Initialize per-column counters.
            $nonNullByCol = [];
            $samplesByCol = [];
            foreach ($cols as $c) {
                $nonNullByCol[$c['column']] = 0;
                $samplesByCol[$c['column']] = [];
            }

            $rowsSeen = 0;
            foreach ($db->streamQuery("SELECT * FROM `{$t}` LIMIT {$scanLimit}") as $row) {
                $rowsSeen++;
                foreach ($row as $colName => $value) {
                    $colName = (string) $colName;
                    if (!isset($nonNullByCol[$colName])) { continue; }
                    if ($value !== null && $value !== '') {
                        $nonNullByCol[$colName]++;
                        if (count($samplesByCol[$colName]) < $this->maxSamplesPerColumn
                            && !in_array($value, $samplesByCol[$colName], true)) {
                            $samplesByCol[$colName][] = $value;
                        }
                    }
                }
                if ($rowsSeen >= $scanLimit) { break; }
            }

            foreach ($cols as &$c) {
                $name = $c['column'];
                $c['fillRate'] = $rowsSeen > 0 ? round($nonNullByCol[$name] / $rowsSeen, 4) : 0.0;
                $c['samples']  = array_values($samplesByCol[$name]);
            }
            unset($c);
        }
        unset($cols);

        // 5. Locales (delegate to LocalePreflight::detect — already wired).
        $locales = Plugin::getInstance()->localePreflight->detect();

        return [
            'generatedAt' => date('c'),
            'driver'      => $driver,
            'tables'      => $rowCounts,
            'columns'     => $columnsByTable,
            'locales'     => $locales,
        ];
    }

    /**
     * @return list<string>
     */
    private function listKunstmaanTables(string $schema): array
    {
        $rows = Plugin::getInstance()->legacyDbService->queryAll(
            'SELECT TABLE_NAME FROM information_schema.tables WHERE TABLE_SCHEMA = :s AND TABLE_NAME LIKE :p ORDER BY TABLE_NAME',
            [':s' => $schema, ':p' => 'kuma\\_%'],
        );
        $out = [];
        foreach ($rows as $r) {
            $name = (string) ($r['TABLE_NAME'] ?? '');
            if ($name !== '') {
                $out[] = $name;
            }
        }
        return $out;
    }

    /**
     * Apply $filters->entities to a candidate table list.
     * Empty entities = unbounded (every kuma_* table passes).
     * Non-empty = include only tables matching `kuma_<snake_case_of_entity>` heuristic.
     *
     * @param list<string> $tables
     * @return list<string>
     */
    private function applyEntitiesFilter(array $tables, MigrationFilters $filters): array
    {
        if ($filters->entities === []) {
            return $tables;
        }
        $needles = [];
        foreach ($filters->entities as $e) {
            // 'NewsPage' → 'news_page' (Kunstmaan table convention).
            $snake = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $e) ?? $e);
            $needles[] = 'kuma_' . $snake;
        }
        $out = [];
        foreach ($tables as $t) {
            foreach ($needles as $n) {
                if (str_starts_with($t, $n)) {
                    $out[] = $t;
                    break;
                }
            }
        }
        return $out;
    }

    private function extractSchemaName(string $dsn): string
    {
        if (preg_match('/dbname=([^;]+)/', $dsn, $m)) {
            return $m[1];
        }
        return '';
    }
}
```

Notes:
- Streaming via `streamQuery` is the load-bearing DoS mitigation (T-2-09 in threat model). Do NOT use `queryAll` for the SELECT * sample scan.
- The `LIMIT {$scanLimit}` bounds the sample scan; we never scan the full table even if `streamQuery` is used.
- Locales are delegated to `LocalePreflight::detect()` — single source of truth.
- The entities filter is a heuristic snake-case match (NewsPage → kuma_news_page). It's "good enough" for v1.0 rehearsal; refine in Phase 3 if needed.
- No file I/O — caller writes via `MappingFile::writeAtomicJson`.
  </action>
  <verify>
    <automated>php -l src/analyze/SchemaDumper.php</automated>
  </verify>
  <acceptance_criteria>
    - `php -l src/analyze/SchemaDumper.php` exits 0
    - `grep -c 'final class SchemaDumper extends Component' src/analyze/SchemaDumper.php` equals 1
    - `grep -c 'namespace lameco\\\\kunstmaanmigrator\\\\analyze;' src/analyze/SchemaDumper.php` equals 1
    - `grep -c 'public function dump(MigrationFilters \$filters)' src/analyze/SchemaDumper.php` equals 1
    - `grep -c 'streamQuery' src/analyze/SchemaDumper.php` is 1 or more (T-2-09 mitigation)
    - `grep -c 'information_schema' src/analyze/SchemaDumper.php` is 2 or more (tables + columns queries)
    - `grep -c 'localePreflight->detect' src/analyze/SchemaDumper.php` equals 1
    - `grep -c "use lameco\\\\kunstmaanmigrator\\\\filter\\\\MigrationFilters;" src/analyze/SchemaDumper.php` equals 1
  </acceptance_criteria>
  <done>SchemaDumper produces array-shape dump using streamQuery for samples; locale detection delegates to LocalePreflight; PHP lint clean.</done>
</task>

<task type="auto">
  <name>Task 3: Build ReportBuilder service (schema-dump → REPORT.md including paste-ready locales block)</name>
  <files>src/analyze/ReportBuilder.php</files>
  <read_first>
    - .planning/phases/02-schema-mapping-filters/02-CONTEXT.md (D-17 LOC-01 paste-ready sites: block; Claude's Discretion section on REPORT.md content)
    - .planning/phases/02-schema-mapping-filters/02-PATTERNS.md ("src/analyze/ReportBuilder.php" section, lines 360–404)
    - ~/Sites/craft-kunstmaan-migrator/src/bridge/console/controllers/AnalyzeController.php (lines 386–417: paste-ready locales suggestion heuristic)
    - src/Plugin.php (Plugin::getInstance() + getSettings() pattern)
  </read_first>
  <action>
Create `src/analyze/ReportBuilder.php` as a `final` Yii Component with one public method `render(array $schemaDump, array $mappingProposals): string`. Returns the markdown string (caller writes via MappingFile::writeAtomic). Sections per Claude's Discretion + D-17:

```php
<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\analyze;

use Craft;
use lameco\kunstmaanmigrator\Plugin;
use yii\base\Component;

/**
 * Renders REPORT.md from the schema dump + mapping proposals.
 *
 * Sections:
 *   1. Header (analyze timestamp, driver, table count)
 *   2. Locales (D-17 LOC-01 — paste-ready Craft sites: block when unmapped)
 *   3. Tables overview (row counts; one screenful target)
 *   4. Mapping summary (counts per status: accepted / proposed / dropped / needs-review)
 *
 * Per CONTEXT.md Claude's Discretion: "Aim one screenful per section."
 */
final class ReportBuilder extends Component
{
    /**
     * @param array{
     *   generatedAt: string, driver: string,
     *   tables: array<string, int>,
     *   columns: array<string, list<array<string, mixed>>>,
     *   locales: list<string>,
     * } $schemaDump
     * @param list<array<string, mixed>> $mappingProposals
     */
    public function render(array $schemaDump, array $mappingProposals): string
    {
        $sections = [];
        $sections[] = $this->renderHeader($schemaDump);
        $sections[] = $this->renderLocales($schemaDump['locales'] ?? []);
        $sections[] = $this->renderTablesOverview($schemaDump);
        $sections[] = $this->renderMappingSummary($mappingProposals);

        return implode("\n\n", array_filter($sections, static fn(string $s): bool => $s !== '')) . "\n";
    }

    private function renderHeader(array $schemaDump): string
    {
        $tableCount = count($schemaDump['tables'] ?? []);
        $columnCount = 0;
        foreach (($schemaDump['columns'] ?? []) as $cols) {
            $columnCount += count($cols);
        }
        return "# Kunstmaan Migration — Analyze Report\n\n"
            . "Generated: {$schemaDump['generatedAt']}\n"
            . "Driver: {$schemaDump['driver']}\n"
            . "Tables scanned: {$tableCount}\n"
            . "Columns scanned: {$columnCount}\n";
    }

    /**
     * D-17 LOC-01: paste-ready Craft sites: block when locales are unmapped.
     *
     * @param list<string> $detected
     */
    private function renderLocales(array $detected): string
    {
        if ($detected === []) {
            return "## Locales\n\nNo locales detected in `kuma_node_translations`.";
        }

        $craftHandles = [];
        foreach (Craft::$app->getSites()->getAllSites() as $s) {
            $craftHandles[] = (string) $s->handle;
        }
        $settingsLocales = (array) Plugin::getInstance()->getSettings()->defaultLocales;

        $unmapped = [];
        foreach ($detected as $l) {
            if (!in_array($l, $craftHandles, true) && !in_array($l, $settingsLocales, true)) {
                $unmapped[] = $l;
            }
        }

        $primaryHandle = (string) (Craft::$app->getSites()->getPrimarySite()->handle ?? 'default');

        $out = "## Locales\n\n"
            . "Detected Kunstmaan locales: " . implode(', ', $detected) . "\n"
            . "Currently mapped (Craft sites + Settings::defaultLocales): " . implode(', ', array_unique(array_merge($craftHandles, $settingsLocales))) . "\n"
            . "Unmapped: " . ($unmapped === [] ? '(none)' : implode(', ', $unmapped)) . "\n";

        if ($unmapped !== []) {
            $out .= "\nAdd these to your Craft `config/sites.php` (or set Settings::defaultLocales to map them):\n\n"
                . "```php\nreturn [\n";
            foreach ($detected as $l) {
                $suggested = in_array($l, $craftHandles, true) ? $l : $primaryHandle;
                $out .= "    '{$l}' => ['language' => '{$l}', 'baseUrl' => 'https://example.com/'],   // suggested handle: {$suggested}\n";
            }
            $out .= "    // ...\n];\n```\n\nRe-run analyze after the sites are mapped.\n";
        }

        return $out;
    }

    private function renderTablesOverview(array $schemaDump): string
    {
        $tables = $schemaDump['tables'] ?? [];
        if ($tables === []) {
            return "## Tables\n\nNo tables found.";
        }
        arsort($tables);
        $top = array_slice($tables, 0, 25, true);
        $lines = ["## Tables (top 25 by row count)\n"];
        $lines[] = "| Table | Rows |";
        $lines[] = "|-------|-----:|";
        foreach ($top as $t => $n) {
            $lines[] = sprintf('| `%s` | %d |', $t, $n);
        }
        if (count($tables) > 25) {
            $lines[] = "\n_…and " . (count($tables) - 25) . " more._";
        }
        return implode("\n", $lines);
    }

    /**
     * @param list<array<string, mixed>> $mappingProposals
     */
    private function renderMappingSummary(array $mappingProposals): string
    {
        if ($mappingProposals === []) {
            return "## Mapping Summary\n\nNo proposals yet. Run analyze first.";
        }
        $counts = ['accepted' => 0, 'proposed' => 0, 'dropped' => 0, 'needs-review' => 0, '(other)' => 0];
        foreach ($mappingProposals as $row) {
            $s = (string) ($row['status'] ?? '');
            if (isset($counts[$s])) {
                $counts[$s]++;
            } else {
                $counts['(other)']++;
            }
        }
        $total = count($mappingProposals);
        $lines = ["## Mapping Summary\n", "Total rows: {$total}\n"];
        foreach ($counts as $status => $n) {
            $lines[] = "- {$status}: {$n}";
        }
        return implode("\n", $lines);
    }
}
```

Notes:
- The locales block is the load-bearing D-17 LOC-01 deliverable. The paste-ready Craft `sites:` config is the operator's hand-off into project-config / `config/sites.php`.
- Heuristic for "suggested handle": if the Kunstmaan locale code matches a Craft site handle, use it; otherwise default to the primary Craft site handle (so the operator at least gets a starting point).
- Other sections are best-effort summaries — the test of "good enough" is "the operator can scan it in 5 seconds and know what's next."
- No file I/O — caller writes via `MappingFile::writeAtomic($reportPath, $contents)`.
  </action>
  <verify>
    <automated>php -l src/analyze/ReportBuilder.php</automated>
  </verify>
  <acceptance_criteria>
    - `php -l src/analyze/ReportBuilder.php` exits 0
    - `grep -c 'final class ReportBuilder extends Component' src/analyze/ReportBuilder.php` equals 1
    - `grep -c 'namespace lameco\\\\kunstmaanmigrator\\\\analyze;' src/analyze/ReportBuilder.php` equals 1
    - `grep -c 'public function render' src/analyze/ReportBuilder.php` equals 1
    - `grep -c '## Locales' src/analyze/ReportBuilder.php` is 1 or more
    - `grep -c 'paste-ready' src/analyze/ReportBuilder.php` is 1 or more (D-17 reference) OR `grep -c 'config/sites.php' src/analyze/ReportBuilder.php` is 1 or more
    - `grep -c 'getPrimarySite()' src/analyze/ReportBuilder.php` equals 1
    - `grep -c 'defaultLocales' src/analyze/ReportBuilder.php` is 1 or more
  </acceptance_criteria>
  <done>ReportBuilder produces markdown with D-17 paste-ready locales block; PHP lint clean.</done>
</task>

<task type="auto">
  <name>Task 4: Build AnalyzeController (single actionIndex orchestration) + register all 4 components in Plugin.php</name>
  <files>src/console/AnalyzeController.php, src/Plugin.php</files>
  <read_first>
    - .planning/phases/02-schema-mapping-filters/02-CONTEXT.md (D-02 confidence-tier→status; D-04 skip-existing; D-08 stateless; D-13 filter wiring; D-17 LocalePreflight gate)
    - .planning/phases/02-schema-mapping-filters/02-PATTERNS.md ("src/console/AnalyzeController.php" section, lines 30–110: full code blueprint)
    - src/console/DoctorController.php (NeverProduction gate idiom; ANSI color usage; ExitCode shape)
    - src/console/MigrateController.php (controller idiom — NeverProduction gate, namespace)
    - ~/Sites/craft-kunstmaan-migrator/src/bridge/console/controllers/AnalyzeController.php (lines 70–87: options() shape; lines 537–660: actionProposeMappings orchestration)
    - All Plan 03 services created in Tasks 1–3 (HeuristicProposer, LlmClassifier, SchemaDumper, ReportBuilder)
    - src/mapping/MappingFile.php (Plan 02 — load/buildRow/merge/writeAtomic/writeAtomicJson/resolvePath)
    - src/locale/LocalePreflight.php (Plan 01 — ensure())
    - src/filter/FilterFactory.php (Plan 01 — fromCli())
    - src/Plugin.php (current shape — needs 4 new components added)
  </read_first>
  <action>
**Step A — create `src/console/AnalyzeController.php`** with a single `actionIndex()` orchestrating the analyze pipeline. ~150 LOC:

```php
<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\console;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use lameco\kunstmaanmigrator\NeverProductionTrait;
use lameco\kunstmaanmigrator\Plugin;
use Throwable;
use yii\console\ExitCode;

/**
 * Analyze — single-action orchestration of the schema-dump + heuristic + LLM proposal
 * pipeline + report rendering. Collapses v1's 9-sub-action AnalyzeController (2138 LOC)
 * to one entrypoint per CONTEXT.md operator workflow.
 *
 * Pipeline:
 *   1. NeverProduction gate (FIRST statement — Phase 1 / D-20)
 *   2. Build MigrationFilters from CLI flags + Settings::default*
 *   3. LocalePreflight::ensure — hard-fail on unmapped locales (D-17 LOC-02)
 *   4. SchemaDumper::dump → schema-dump.json
 *   5. HeuristicProposer::autoMatch → high-confidence rows status: accepted (D-02)
 *   6. LlmClassifier::batchPropose for residuals (skip when --no-ai or no API key)
 *   7. MappingFile::merge with skip-existing semantics (D-04)
 *   8. ReportBuilder::render → REPORT.md
 *
 * Exit:
 *   - ExitCode::OK on green
 *   - ExitCode::CONFIG on locale-preflight FAIL
 *   - ExitCode::UNSPECIFIED_ERROR on any other FAIL
 */
class AnalyzeController extends Controller
{
    use NeverProductionTrait;

    public bool    $noAi           = false;
    public bool    $autoAcceptHigh = false;
    public bool    $auditStrict    = false;
    public ?string $entities       = null;
    public ?string $locales        = null;
    public ?string $since          = null;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), [
            'noAi', 'autoAcceptHigh', 'auditStrict',
            'entities', 'locales', 'since',
        ]);
    }

    public function actionIndex(): int
    {
        // FND-04 / D-20: NeverProduction guard FIRST — before any legacy DB read.
        if (($gate = $this->enforceNeverProduction()) !== null) {
            return $gate;
        }

        $this->stdout("Analyze: scanning legacy schema\n", Console::FG_CYAN);

        $plugin = Plugin::getInstance();
        $filters = $plugin->filterFactory->fromCli($this->entities, $this->locales, $this->since);

        // Step 1: locale preflight (LOC-02 D-17 hard-fail).
        $unmapped = $plugin->localePreflight->ensure($filters);
        if ($unmapped !== null) {
            $this->stderr(
                "  FAIL unmapped Kunstmaan locales: " . implode(', ', $unmapped) . "\n"
                . "       Add them to config/sites.php or Settings::defaultLocales, then re-run.\n",
                Console::FG_RED,
            );
            return ExitCode::CONFIG;
        }
        $this->stdout("  OK   locale preflight\n", Console::FG_GREEN);

        // Step 2: schema dump.
        try {
            $schemaDump = $plugin->schemaDumper->dump($filters);
        } catch (Throwable $e) {
            $this->stderr("  FAIL schema dump: {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $tableCount = count($schemaDump['tables'] ?? []);
        $colCount   = array_sum(array_map('count', $schemaDump['columns'] ?? []));
        $storageDir = Craft::$app->path->getStoragePath() . '/migration';
        $schemaPath = $storageDir . '/schema-dump.json';
        if (!$plugin->mappingFile->writeAtomicJson($schemaPath, $schemaDump)) {
            $this->stderr("  FAIL could not write {$schemaPath}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $this->stdout("  OK   schema dumped ({$tableCount} tables, {$colCount} columns) → {$schemaPath}\n", Console::FG_GREEN);

        // Step 3: heuristic proposals.
        try {
            $heuristicProposals = $plugin->heuristicProposer->autoMatch($schemaDump);
        } catch (Throwable $e) {
            $this->stderr("  FAIL heuristic proposer: {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $this->stdout("  OK   heuristics produced " . count($heuristicProposals) . " proposals\n", Console::FG_GREEN);

        // Step 4: LLM batch proposals for residuals (skip when --no-ai or key missing).
        $llmProposals = [];
        $apiKey = (string) ($plugin->getSettings()->anthropicApiKey ?? '');
        $skipLlm = $this->noAi || $apiKey === '';
        if ($skipLlm) {
            $reason = $this->noAi ? '--no-ai set' : 'ANTHROPIC_API_KEY not set';
            $this->stdout("  WARN LLM call skipped ({$reason}) — residual columns land status: needs-review\n", Console::FG_YELLOW);
        } else {
            try {
                $llmProposals = $plugin->llmClassifier->batchPropose($schemaDump, $heuristicProposals);
                $this->stdout("  OK   LLM produced " . count($llmProposals) . " proposals\n", Console::FG_GREEN);
            } catch (Throwable $e) {
                $this->stderr("  FAIL LLM classifier: {$e->getMessage()}\n", Console::FG_RED);
                return ExitCode::UNSPECIFIED_ERROR;
            }
        }

        // Step 5: build rows with D-02 confidence-tier → status assignment.
        $rows = [];
        foreach ($heuristicProposals as $p) {
            $rows[] = $plugin->mappingFile->buildRow($p, $this->statusForHeuristic($p));
        }
        foreach ($llmProposals as $p) {
            $rows[] = $plugin->mappingFile->buildRow($p, $this->statusForLlm($p, $skipLlm));
        }
        // Apply --auto-accept-high: promote LLM-high proposed → accepted (MAP-05).
        if ($this->autoAcceptHigh) {
            foreach ($rows as &$r) {
                if (($r['status'] ?? '') === 'proposed' && ($r['confidence'] ?? '') === 'high') {
                    $r['status'] = 'accepted';
                }
            }
            unset($r);
        }

        // Step 6: skip-existing merge (D-04 — operator decisions sacred).
        $mappingPath = $plugin->mappingFile->resolvePath();
        $existing = $plugin->mappingFile->load($mappingPath);
        $merged = $plugin->mappingFile->merge($existing, $rows);

        $yaml = \Symfony\Component\Yaml\Yaml::dump($merged, 4, 2, \Symfony\Component\Yaml\Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
        if (!$plugin->mappingFile->writeAtomic($mappingPath, $yaml)) {
            $this->stderr("  FAIL could not write {$mappingPath}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $this->stdout("  OK   mapping.yaml updated (" . count($merged['proposals']) . " rows total) → {$mappingPath}\n", Console::FG_GREEN);

        // Step 7: REPORT.md
        $reportPath = $storageDir . '/REPORT.md';
        $report = $plugin->reportBuilder->render($schemaDump, $merged['proposals']);
        if (!$plugin->mappingFile->writeAtomic($reportPath, $report)) {
            $this->stderr("  FAIL could not write {$reportPath}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $this->stdout("  OK   REPORT.md written → {$reportPath}\n", Console::FG_GREEN);

        $this->stdout("\nAnalyze: PASS\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    /**
     * D-02: heuristic confidence → initial status.
     * @param array<string, mixed> $proposal
     */
    private function statusForHeuristic(array $proposal): string
    {
        $confidence = (string) ($proposal['confidence'] ?? '');
        $fillRate = (float) ($proposal['fillRate'] ?? 0);
        if ($fillRate === 0.0) {
            return 'dropped'; // zero-fill heuristic auto-drop
        }
        return $confidence === 'high' ? 'accepted' : 'proposed';
    }

    /**
     * D-02 + --no-ai semantics: LLM confidence → initial status; skip-LLM rows get needs-review.
     * @param array<string, mixed> $proposal
     */
    private function statusForLlm(array $proposal, bool $skipped): string
    {
        if ($skipped) {
            return 'needs-review';
        }
        $confidence = (string) ($proposal['confidence'] ?? 'low');
        return $confidence === 'high' ? 'proposed' : 'needs-review';
    }
}
```

Notes:
- The `autoMatch()` method name and `batchPropose()` method name are inferred from PATTERNS.md / v1's HeuristicProposer & LlmClassifier. If the v1 method names differ, adjust the calls in this controller — but verify by greppping the ported files first. The CONTEXT.md is explicit about input shape: HeuristicProposer takes the schema-dump array and returns proposals.
- Mapping-audit invocation (D-16) is deferred to Plan 05 (CoverageAuditor + MappingAuditor). This controller does NOT call them — the orchestrator runs ARE: NeverProduction → preflight → schemaDumper → heuristics → LLM → merge → report. That's the Phase 2 / Plan 03 surface. Plan 05 hooks `mappingAuditor->audit()` in afterward.
- `--audit-strict` flag is declared here so Plan 05 doesn't need to re-touch this file. Its semantics ("audit drift findings elevated to fail-state") activate when Plan 05 wires in `mappingAuditor->audit($merged)` and inspects `$this->auditStrict`. Document this on the SUMMARY.

**Step B — modify `src/Plugin.php` to register 4 new components:**

Add `use` statements for the 4 new analyze classes:

```php
use lameco\kunstmaanmigrator\analyze\HeuristicProposer;
use lameco\kunstmaanmigrator\analyze\LlmClassifier;
use lameco\kunstmaanmigrator\analyze\ReportBuilder;
use lameco\kunstmaanmigrator\analyze\SchemaDumper;
```

Add 4 `@property-read` lines to the class docblock:

```php
 * @property-read SchemaDumper $schemaDumper
 * @property-read HeuristicProposer $heuristicProposer
 * @property-read LlmClassifier $llmClassifier
 * @property-read ReportBuilder $reportBuilder
```

Extend the `config()` components map:

```php
return [
    'components' => [
        'legacyDbService'   => LegacyDbService::class,    // Phase 1
        'filterFactory'     => FilterFactory::class,      // Phase 2 (Plan 01)
        'localePreflight'   => LocalePreflight::class,    // Phase 2 (Plan 01)
        'mappingFile'       => MappingFile::class,        // Phase 2 (Plan 02)
        'schemaDumper'      => SchemaDumper::class,       // Phase 2 (Plan 03)
        'heuristicProposer' => HeuristicProposer::class,  // Phase 2 (Plan 03)
        'llmClassifier'     => LlmClassifier::class,      // Phase 2 (Plan 03)
        'reportBuilder'     => ReportBuilder::class,      // Phase 2 (Plan 03)
    ],
];
```
  </action>
  <verify>
    <automated>php -l src/console/AnalyzeController.php && php -l src/Plugin.php && composer test</automated>
  </verify>
  <acceptance_criteria>
    - `php -l src/console/AnalyzeController.php` exits 0
    - `php -l src/Plugin.php` exits 0
    - `composer test` exits 0
    - `grep -c 'class AnalyzeController extends Controller' src/console/AnalyzeController.php` equals 1
    - `grep -c 'use NeverProductionTrait;' src/console/AnalyzeController.php` equals 1
    - `grep -nE 'enforceNeverProduction' src/console/AnalyzeController.php` shows the call BEFORE any other statement in actionIndex (gate-first idiom)
    - `grep -c 'localePreflight->ensure' src/console/AnalyzeController.php` equals 1
    - `grep -c "public ?string \$entities" src/console/AnalyzeController.php` equals 1
    - `grep -c "public ?string \$locales" src/console/AnalyzeController.php` equals 1
    - `grep -c "public ?string \$since" src/console/AnalyzeController.php` equals 1
    - `grep -c "public bool \$noAi" src/console/AnalyzeController.php` equals 1
    - `grep -c "public bool \$autoAcceptHigh" src/console/AnalyzeController.php` equals 1
    - `grep -c "public bool \$auditStrict" src/console/AnalyzeController.php` equals 1
    - `grep -c "'noAi', 'autoAcceptHigh', 'auditStrict'" src/console/AnalyzeController.php` equals 1
    - `grep -c "'entities', 'locales', 'since'" src/console/AnalyzeController.php` equals 1
    - `grep -c "'schemaDumper'      => SchemaDumper::class" src/Plugin.php` equals 1
    - `grep -c "'heuristicProposer' => HeuristicProposer::class" src/Plugin.php` equals 1
    - `grep -c "'llmClassifier'     => LlmClassifier::class" src/Plugin.php` equals 1
    - `grep -c "'reportBuilder'     => ReportBuilder::class" src/Plugin.php` equals 1
  </acceptance_criteria>
  <done>AnalyzeController orchestrates analyze pipeline with NeverProduction-first gate, locale preflight, schema dump (with streaming for samples), heuristic+LLM proposals, skip-existing merge, REPORT.md output. Plugin.php registers all 4 analyze components. composer test green.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| Production env → AnalyzeController | NeverProduction trait blocks legacy reads when CRAFT_ENVIRONMENT=production. |
| Legacy MySQL → SchemaDumper / LocalePreflight | Read-only. Can be huge — DoS via OOM is the threat. |
| Anthropic API → LlmClassifier | Outbound HTTPS. Sample values from legacy DB are sent to a third party. |
| Filesystem → mapping.yaml / schema-dump.json / REPORT.md | Atomic writes via MappingFile. Tampering during write = T threat (Plan 02 owns this). |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-2-09 | D (Denial of Service) | SchemaDumper sample collection | mitigate | Sample collection uses `LegacyDbService::streamQuery` with a `LIMIT {scanLimit}` cap (default 1000 rows). The Generator-based stream prevents loading the full table into memory. Verified by Task 2 acceptance criterion `grep streamQuery`. |
| T-2-10 | I (Information Disclosure) | LlmClassifier sample payloads | accept | Samples sent to Anthropic include legacy column values which may contain PII (names, addresses, body text). Operator awareness mitigation only — same posture as v1, documented in CONTEXT.md. No content filtering in v1.0; defer to a future privacy hardening pass if a real driver appears. |
| T-2-11 | I (Information Disclosure) | Anthropic API key in error messages | mitigate | LlmClassifier reads `$settings->anthropicApiKey` (Settings-then-env per Phase 1 / D-14). The exception message on missing key is `'ANTHROPIC_API_KEY is not set...'` — does NOT echo the value. The HTTPS request includes the key in the `x-api-key` header; we never log the headers. Verified by Task 1 acceptance criterion `grep -c 'getSettings()->anthropicApiKey'`. |
| T-2-12 | E (Elevation of Privilege) | AnalyzeController against production legacy DB | mitigate | First statement of `actionIndex()` is `enforceNeverProduction()` per Phase 1 / D-20. Verified by Task 4 acceptance criterion (gate-first ordering grep). |
| T-2-13 | T (Tampering) | mapping.yaml mid-write during analyze | mitigate | All writes go through `MappingFile::writeAtomic` / `writeAtomicJson` (Plan 02). tmp + rename pattern. Operator Ctrl+C never corrupts state. |
| T-2-14 | R (Repudiation) | Heuristic auto-drops (zero fill rate) lose context | mitigate | Per D-03, the `rationale` field carries drop-reason vocabulary. AnalyzeController's `statusForHeuristic` returns `'dropped'` for zero-fillRate; the rationale is set by HeuristicProposer in the proposal payload (e.g. `'fill-rate is 0 — no data in source'`). Persisted in mapping.yaml row. |
</threat_model>

<verification>
- `php -l` passes on all 5 new files + Plugin.php
- `composer test` exits 0 (Phase 1 PluginBootstrapTest still green; the test reflects on the components map and tolerates expansion)
- `Plugin::config()` registers exactly 8 components (1 Phase 1 + 7 Phase 2 across Plans 01–03)
- AnalyzeController's actionIndex first statement is the NeverProduction gate
- AnalyzeController declares all 6 CLI flags in `options()`: noAi, autoAcceptHigh, auditStrict, entities, locales, since
- HeuristicProposer + LlmClassifier preserve v1 defaults (claude-haiku-4-5, 4096 tokens, 60s timeout, 2023-06-01 anthropic-version, batch=10, sleep=20s)
- SchemaDumper uses streamQuery for sample collection (DoS mitigation)
- ReportBuilder produces a Markdown ## Locales section with paste-ready Craft sites: block when locales are unmapped
</verification>

<success_criteria>
1. `analyze` orchestration runs in order: NeverProduction → filters → LocalePreflight → SchemaDumper → write schema-dump.json → HeuristicProposer → LlmClassifier (or skip) → MappingFile::merge → write mapping.yaml → ReportBuilder → write REPORT.md.
2. The 9 deterministic heuristics from v1 (zero-fill / exact name / *_id / TEXT / *_image / *_date / *_url / *_email / Dutch alias) are preserved verbatim in HeuristicProposer.
3. LlmClassifier ports v1's batching (chunk=10, inter-batch sleep=20s, retry on 429 with exponential backoff).
4. LlmClassifier resolves API key via Settings::anthropicApiKey (which Phase 1 / D-14 already env-merges in init()).
5. Re-running `analyze` preserves operator-decided rows verbatim (D-04 skip-existing) — verified at runtime by Plan 06 PHPUnit test on MappingFile::merge.
6. REPORT.md emits a paste-ready Craft `sites:` block when locales are unmapped (D-17 LOC-01).
7. AnalyzeController declares all three filter flags + the three analyze-specific flags in `options()` (FILT-03 partial, plus MAP-05 `--auto-accept-high`, plus D-16 `--audit-strict`).
8. SchemaDumper uses `streamQuery` (not `queryAll`) for the SELECT * sample scan (T-2-09 mitigation).
</success_criteria>

<output>
After completion, create `.planning/phases/02-schema-mapping-filters/02-03-SUMMARY.md` documenting:
- Files created and their LOC
- Plugin.php diff summary (8 components after this plan)
- Confirmation that v1 defaults preserved in LlmClassifier (model, tokens, timeout, version header, batch, sleep)
- Confirmation that HeuristicProposer's 9-heuristic ordering and DUTCH_ALIASES const are byte-for-byte from v1
- Confirmation that streaming (T-2-09) is in place for sample collection
- The actual `autoMatch()` and `batchPropose()` method signatures observed in the ported v1 files (and any signature divergence the controller code adapted to)
- Note that `--audit-strict` is declared but its consumer (mappingAuditor->audit) lands in Plan 05
</output>
