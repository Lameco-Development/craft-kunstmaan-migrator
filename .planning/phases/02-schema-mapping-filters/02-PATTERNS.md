# Phase 2: Schema, Mapping & Filters - Pattern Map

**Mapped:** 2026-04-25
**Files analyzed:** 14 new + 2 modified
**Analogs found:** 16 / 16 (100% coverage — Phase 2 is largely a reshape of v1 brownfield + Phase 1 idioms)

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|-------------------|------|-----------|----------------|---------------|
| `src/console/AnalyzeController.php` | controller (console) | request-response (CLI) | `src/console/DoctorController.php` (Phase 1) + `~/Sites/craft-kunstmaan-migrator/src/bridge/console/controllers/AnalyzeController.php::actionIndex()` (v1) | exact (idiom) + role+flow (algorithm) |
| `src/console/MapController.php` | controller (console, interactive) | event-driven (keypress loop) | `src/console/DoctorController.php` (idiom) + v1 `AnalyzeController::actionApplyProposals` (lines 812-1240) for `select()`/`prompt()` shape | role-match (greenfield UX, no exact analog) |
| `src/console/DoctorController.php` (modify) | controller (console) | request-response | self — Phase 1 file. Add `checkMappingFile()` as 4th private method, slot into `actionIndex()` after `checkStorageDir()` | self-modification |
| `src/Plugin.php` (modify) | plugin entrypoint | config | self — Phase 1 file. Extend `config()` `components` map | self-modification |
| `src/analyze/SchemaDumper.php` | service | batch (read-many SQL → JSON) | v1 `AnalyzeController::buildSchemaDump()` (lines 1942-2050ish) | role+flow (extract algorithm into a Component) |
| `src/analyze/HeuristicProposer.php` | service | transform (pure, no I/O) | v1 `bridge/services/HeuristicProposer.php` (407 LOC) | exact (port verbatim, namespace flatten only) |
| `src/analyze/LlmClassifier.php` | service | request-response (HTTP, batched) | v1 `bridge/services/LlmClassifier.php` (481 LOC) | exact (port near-verbatim; status-on-row tweak) |
| `src/analyze/ReportBuilder.php` | service | transform (dump → markdown) | v1 `AnalyzeController::renderReportMarkdown()` + `actionDetectLocales()` (lines 352-420 for the paste-ready locales block per D-17) | role+flow (greenfield merge of two v1 surfaces) |
| `src/mapping/MappingFile.php` | service (reader+writer) | file-I/O (atomic) | v1 `MappingDraftReader.php` (303 LOC, indexing + parse) **+** v1 `MappingDraftWriter::writeAtomic` + `buildDraftPayload` (lines 34-95) | role-match (consolidates 2 v1 services; reshape for status-on-row) |
| `src/mapping/CoverageAuditor.php` | service | transform (dump+mapping → violations) | v1 `coverageAuditor` (referenced in `AnalyzeController::actionCoverage` lines 437-491) | role+flow (greenfield D-14 STRUCTURAL_IGNORE constant; v1 audit shape ports) |
| `src/mapping/MappingAuditor.php` | service | transform (mapping ↔ live FieldLayout) | v1 `AnalyzeController::collectMappingDrifts()` (lines 1769-1888) **+** v1 `MappingValidator.php` (647 LOC handler-classification rules) | role+flow (port drift logic; simplify for single-file format) |
| `src/filter/MigrationFilters.php` | model (value object) | n/a (immutable VO) | v1 `models/MigrationFilters.php` (48 LOC — **shape only**, scope is different) **+** Phase 1 `Settings.php` for `readonly` PHP 8.3 idiom | role-match (different semantics; v1 is post-Craft, v2 is legacy-side) |
| `src/filter/FilterFactory.php` | service | transform (Settings + CLI args → VO) | no v1 analog. Closest: Phase 1 `Settings::init()` (env-merge pattern) | partial (greenfield; idiom from Settings::init `??=` fallback) |
| `src/locale/LocalePreflight.php` | service | request-response (gate check) | v1 `AnalyzeController::actionDetectLocales` (lines 352-420) for the detect SQL; D-17 LOC-02 hard-fail logic is greenfield | role+flow (detect ports; preflight is new) |

## Pattern Assignments

### `src/console/AnalyzeController.php` (controller, request-response)

**Analogs:** Phase 1 `src/console/DoctorController.php` (idiom shape), v1 `bridge/console/controllers/AnalyzeController.php` (algorithmic content)

**Imports + class header pattern** — copy from `src/console/DoctorController.php` lines 1-27:

```php
<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\console;

use Craft;
use craft\console\Controller;
use craft\helpers\App;
use craft\helpers\Console;
use lameco\kunstmaanmigrator\NeverProductionTrait;
use lameco\kunstmaanmigrator\Plugin;
use Throwable;
use yii\console\ExitCode;

class AnalyzeController extends Controller
{
    use NeverProductionTrait;
```

**NeverProduction gate idiom** (Phase 1 / D-20) — `DoctorController.php:32-34`:

```php
public function actionIndex(): int
{
    if (($gate = $this->enforceNeverProduction()) !== null) {
        return $gate;
    }
    // ... real work below
```

This is the FIRST statement in every legacy-reading `actionXxx`. Copy verbatim.

**`options()` flag declarations** — copy SHAPE from v1 `bridge/console/controllers/AnalyzeController.php:70-87`. v2's `analyze` declares: `noAi` (D-17 / `--no-ai`), `autoAcceptHigh` (D-02 / `--auto-accept-high`), `auditStrict` (D-16 / `--audit-strict`), plus the three filter flags (`entities`, `locales`, `since`):

```php
public bool $noAi = false;
public bool $autoAcceptHigh = false;
public bool $auditStrict = false;
public ?string $entities = null;
public ?string $locales  = null;
public ?string $since    = null;

public function options($actionID): array
{
    return array_merge(parent::options($actionID), [
        'noAi', 'autoAcceptHigh', 'auditStrict',
        'entities', 'locales', 'since',
    ]);
}
```

**Single-action orchestration** — copy STRUCTURE from v1 `actionProposeMappings` lines 537-660 + `actionIndex` lines 1897-1934, but COLLAPSE into one `actionIndex()` per CONTEXT.md (PROJECT.md operator workflow ~5 commands surface):

```php
// Steps in order: NeverProduction gate → LocalePreflight → SchemaDumper.dump()
// → write schema-dump.json → HeuristicProposer.autoMatch() → LlmClassifier.batchPropose()
// (skip when --no-ai) → MappingFile.merge(skip-existing per D-04)
// → CoverageAuditor.audit() → MappingAuditor.audit() → ReportBuilder.render() → write REPORT.md
//
// Each step prints "  OK ..." / "  FAIL ..." / "  WARN ..." (ANSI colors per Phase 1 / D-19).
// Exit ExitCode::OK on green; ExitCode::UNSPECIFIED_ERROR on FAIL; auditStrict elevates audit WARNs to FAIL.
```

**stdout/stderr formatting** (Phase 1 / D-19) — `DoctorController.php:36, 46-48, 60, 66`:

```php
$this->stdout("Analyze: scanning legacy schema\n", Console::FG_CYAN);
// per-step:
$this->stdout("  OK   schema dumped ({$count} tables, {$colCount} columns)\n", Console::FG_GREEN);
$this->stderr("  FAIL legacyDb unreachable: {$e->getMessage()}\n", Console::FG_RED);
$this->stdout("  WARN {$n} drift entries detected\n", Console::FG_YELLOW);
```

Two-space indent on per-step lines; the action-header line is unindented + cyan.

---

### `src/console/MapController.php` (controller, event-driven keypress loop)

**Analogs:** Phase 1 `DoctorController.php` (idiom), v1 `actionApplyProposals` lines 978-1240 (interactive `select()`/`prompt()` shape — greenfield otherwise)

**Class header + NeverProduction gate** — same as AnalyzeController above.

**`options()`** — `--auto-accept-high` (D-08) + the three filter flags:

```php
public bool $autoAcceptHigh = false;
public ?string $entities = null;
public ?string $locales  = null;
public ?string $since    = null;

public function options($actionID): array
{
    return array_merge(parent::options($actionID), [
        'autoAcceptHigh', 'entities', 'locales', 'since',
    ]);
}
```

**Interactive prompt shape** — copy from v1 `AnalyzeController.php:978-988`:

```php
$action = strtolower((string) $this->select(
    '  action',
    [
        'a' => 'accept',
        'd' => 'drop',
        'r' => 'remap',
        's' => 'skip',
        'q' => 'quit',
    ],
));
```

**Free-text prompt** — v1 `AnalyzeController.php:1001-1004`:

```php
$rationale = (string) $this->prompt('  rationale (enter for default):', [
    'required' => false,
    'default'  => 'no Craft target — operator-decided drop in map loop',
]);
```

**Numbered handle picker (D-06 step 2)** — v1 `AnalyzeController.php:1027-1044`:

```php
$choices = [];
foreach ($allowed as $f) {
    $h = (string) ($f['handle'] ?? '');
    if ($h === '') { continue; }
    $choices[$h] = sprintf('%s (%s)', $h, (string) ($f['classification'] ?? ''));
}
$pickedHandle = (string) $this->select('  target field', $choices);
```

**Greenfield (D-05/D-07/D-08):**
- Compact one-screen renderer per row (80×24, `[N/M] table.column` header, sample truncation to 60 chars × max 3 samples) — write a private `renderRowBlock(array $row, int $idx, int $total): string`.
- Atomic write per keypress: after each `[a]`/`[d]`/`[r]` → call `MappingFile::writeAtomic()` (see MappingFile pattern below). No buffering across rows.
- Stateless resume: `actionIndex()` body is a foreach over `MappingFile::loadProposed()` (rows where `status ∈ {proposed, needs-review}`). No `.map-session.json` sidecar.
- Live-FieldLayout validation for `[t]ype manually` free-text input — call `Craft::$app->fields->getLayoutByType(EntryType::class)` (see MappingAuditor pattern) and reject invalid handles with the picker re-shown.

---

### `src/console/DoctorController.php` (modify — add 4th check)

**Analog:** self. The 3 existing checks (`checkLegacyDb`, `checkApiKey`, `checkStorageDir`) are the template for `checkMappingFile()`.

**Pattern to copy** — `DoctorController.php:97-119` (`checkStorageDir`) — same try/catch, same `OK`/`FAIL` line shape:

```php
private function checkMappingFile(): bool
{
    $path = (string) (Plugin::getInstance()->getSettings()->mappingPath ?? '')
        ?: Craft::$app->path->getStoragePath() . '/migration/mapping.yaml';
    try {
        if (!is_file($path)) {
            $this->stdout("  WARN mapping.yaml not found at {$path} (run analyze first)\n", Console::FG_YELLOW);
            return true; // WARN-only — file is created by analyze, not by doctor
        }
        // Parse YAML; verify top-level `proposals:` key exists.
        $parsed = \Symfony\Component\Yaml\Yaml::parseFile($path);
        if (!is_array($parsed) || !array_key_exists('proposals', $parsed)) {
            $this->stderr("  FAIL mapping.yaml at {$path} missing top-level 'proposals:' key\n", Console::FG_RED);
            return false;
        }
        $this->stdout("  OK   mapping.yaml at {$path} (" . count($parsed['proposals']) . " rows)\n", Console::FG_GREEN);
        return true;
    } catch (Throwable $e) {
        $this->stderr("  FAIL mapping.yaml parse error: {$e->getMessage()}\n", Console::FG_RED);
        return false;
    }
}
```

**Wiring into `actionIndex()`** — `DoctorController.php:40-43`:

```php
$ok = true;
$ok = $this->checkLegacyDb()    && $ok;
$ok = $this->checkApiKey()      && $ok;
$ok = $this->checkStorageDir()  && $ok;
$ok = $this->checkMappingFile() && $ok;   // NEW (Phase 2)
```

---

### `src/Plugin.php` (modify — register Phase 2 components)

**Analog:** self. `Plugin.php:30-38` is the template:

```php
public static function config(): array
{
    return [
        'components' => [
            'legacyDbService'    => LegacyDbService::class,        // Phase 1
            'schemaDumper'       => SchemaDumper::class,           // Phase 2 NEW
            'heuristicProposer'  => HeuristicProposer::class,      // Phase 2 NEW
            'llmClassifier'      => LlmClassifier::class,          // Phase 2 NEW
            'reportBuilder'      => ReportBuilder::class,          // Phase 2 NEW
            'mappingFile'        => MappingFile::class,            // Phase 2 NEW
            'coverageAuditor'    => CoverageAuditor::class,        // Phase 2 NEW
            'mappingAuditor'     => MappingAuditor::class,         // Phase 2 NEW
            'filterFactory'      => FilterFactory::class,          // Phase 2 NEW
            'localePreflight'    => LocalePreflight::class,        // Phase 2 NEW
        ],
    ];
}
```

Plus `@property-read` PHPDoc lines on the class header (line 18) for IDE/static-analysis completion. `MigrationFilters` is a value object — instantiated by `FilterFactory`, NOT registered as a component.

---

### `src/analyze/SchemaDumper.php` (service, batch SQL→JSON)

**Analog:** v1 `AnalyzeController::buildSchemaDump()` lines 1942-2050+ (extract from controller into a `yii\base\Component`).

**Class header** — copy LegacyDbService idiom (`src/db/LegacyDbService.php:1-24`):

```php
<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\analyze;

use Craft;
use lameco\kunstmaanmigrator\Plugin;
use lameco\kunstmaanmigrator\db\LegacyDbService;
use Throwable;
use yii\base\Component;
use yii\db\Query;

final class SchemaDumper extends Component
{
```

**Algorithm** — port from v1 `actionIndex` lines 1942-2050. Returns an array (not a file), shape:

```php
[
    'generatedAt' => date('c'),
    'driver' => $conn->getDriverName(),
    'tables' => [/* table => rowCount */],
    'columns' => [/* table => [ {column, sqlType, fillRate, samples}, ... ] */],
    'locales' => [/* distinct kuma_node_translations.lang values */],
]
```

**SQL access pattern** — use `Plugin::getInstance()->legacyDbService` (`src/db/LegacyDbService.php:34-69`):

```php
$db = Plugin::getInstance()->legacyDbService;
$rows = $db->queryAll(
    'SELECT table_name, column_name, data_type FROM information_schema.columns WHERE table_schema = :s AND table_name LIKE :p',
    [':s' => $schemaName, ':p' => $prefix . '%'],
);
```

**Atomic JSON write** — DO NOT have SchemaDumper write the file. Pure transform (return array). The controller calls `MappingFile::writeAtomicJson()` (a sibling helper, parallel to `writeAtomic` for YAML) to persist.

**Filter integration (D-13):** SchemaDumper takes `MigrationFilters` as a method arg. When `entities` is non-empty, `WHERE table_name IN (mapped tables only)`. When `locales` is non-empty, the locale detection step is scoped (but locale detection itself is the source of locale truth, so most of the time locales filter is a no-op here — see LocalePreflight).

---

### `src/analyze/HeuristicProposer.php` (service, pure transform)

**Analog:** v1 `bridge/services/HeuristicProposer.php` (407 LOC) — **port near-verbatim**.

**Action:** copy the entire file. Two changes only:

1. **Namespace flatten:** `lameco\kunstmaanmigrator\bridge\services` → `lameco\kunstmaanmigrator\analyze`.
2. **Output reshape (D-02):** the `buildProposal()` helper (v1 lines 385-407) currently emits a flat array with `decision` + `confidence`. v2 must add `status` per the D-02 confidence-tier table. Easiest: have HeuristicProposer keep emitting `decision`+`confidence` (unchanged) and have the `MappingFile::merge()` step apply the status assignment — keeps HeuristicProposer pure and testable.

**Keep verbatim:**
- The `DUTCH_ALIASES` const (lines 42-56) — **DO NOT REORDER** the 9 heuristics or rename Dutch keys without an explicit driver.
- The 9 heuristics in `autoMatch()` order (lines 79-197): zero-fill → exact name → `*_id` → TEXT → `*_image` → `*_date` → `*_url` → `*_email` → Dutch alias.
- `handlerForClassification()` match expression (lines 366-379) — the canonical handler vocabulary.

**Tests:** Phase 5 will need fixtures here. Phase 2 ships the port; characterization tests come later (TST-02).

---

### `src/analyze/LlmClassifier.php` (service, batched HTTP request-response)

**Analog:** v1 `bridge/services/LlmClassifier.php` (481 LOC) — **port near-verbatim**.

**Defaults to keep verbatim:**

```php
public string $defaultModel    = 'claude-haiku-4-5';
public int    $maxTokens       = 4096;
public int    $timeoutSeconds  = 60;
private const ANTHROPIC_ENDPOINT       = 'https://api.anthropic.com/v1/messages';
private const ANTHROPIC_VERSION_HEADER = '2023-06-01';
```

**Batching strategy** (v1 lines 92-122): group residual by `targetEntryType`, chunk by 10, `sleep(20)` between chunks. **Keep all three numbers** (D-CONTEXT — "Keep v1's defaults unless there's a reason to change").

**API key resolution** — v1 reads `App::env('ANTHROPIC_API_KEY')` directly (line 77). v2 must use the Settings-then-env fallback per Phase 1 / D-14:

```php
$settings = Plugin::getInstance()->getSettings();
$apiKey = (string) ($settings->anthropicApiKey ?? '');
if ($apiKey === '') {
    throw new MappingProposalException(
        'ANTHROPIC_API_KEY is not set. Set it in .env or plugin settings, or re-run with --no-ai.',
    );
}
```

(`Settings::init()` already pulls `ANTHROPIC_API_KEY` env into `$this->anthropicApiKey ??=`, so reading the property is enough — see `src/models/Settings.php:81`.)

**Timeout/model resolution** — same pattern. Read from `Settings::llmModel` / `Settings::llmTimeout` first (Phase 1 / D-15 already declared these), fall back to defaults.

**Retry logic** (v1 lines 404-452 `callWithBackoff`): port verbatim. 3 retries on 429, exponential backoff 15s/30s/45s, honors `retry-after` header.

**Status assignment (D-02):** LLM-high → `status: proposed`; LLM-medium/low → `status: needs-review`. As with HeuristicProposer, do this in `MappingFile::merge()` — keep the classifier's output pure.

**`--no-ai` semantics** (D-CONTEXT): when `--no-ai` is set OR API key missing, residual columns get `status: needs-review` with rationale `'LLM call skipped (--no-ai or no API key)'`. The orchestrator (AnalyzeController) handles this fall-through; LlmClassifier just throws when key missing.

---

### `src/analyze/ReportBuilder.php` (service, transform)

**Analogs:** v1 `AnalyzeController::renderReportMarkdown()` (referenced near line 1922) + v1 `actionDetectLocales` lines 386-417 for the paste-ready locales block.

**Key requirement (D-17):** the locales section is paste-ready Craft `sites:` config. Copy the suggestion-heuristic from v1 `actionDetectLocales` lines 401-411:

```php
// Suggest a Craft site handle for each detected locale.
$craftHandles = [];
foreach (Craft::$app->getSites()->getAllSites() as $s) {
    $craftHandles[] = (string) $s->handle;
}
$primaryHandle = (string) (Craft::$app->getSites()->getPrimarySite()->handle ?? 'default');

$lines = ['sites:'];
foreach ($detected as $locale) {
    $suggested = in_array($locale, $craftHandles, true) ? $locale : $primaryHandle;
    $lines[] = sprintf('  %s: %s', $locale, $suggested);
}
```

But wrap in markdown per the D-17 spec (CONTEXT.md lines 191-211):

```markdown
## Locales

Detected Kunstmaan locales: nl, fr, en, de
Currently mapped in Settings::defaultLocales: nl
Unmapped: fr, en, de

Add these to your Craft `config/sites.php`:

\`\`\`php
return [
    'nl' => ['language' => 'nl-BE', 'baseUrl' => 'https://example.com/'],
    ...
];
\`\`\`

Re-run analyze after the sites are mapped.
```

**Other sections** (Claude's Discretion per CONTEXT.md): per-table row counts, per-column fillRate distribution, heuristic+LLM proposal summary (counts per status). Aim one screenful per section.

**Output:** ReportBuilder returns a string. Controller writes it via `MappingFile::writeAtomic($path, $contents)` — same atomic helper pattern.

---

### `src/mapping/MappingFile.php` (service, file-I/O atomic)

**Analogs:** v1 `MappingDraftReader.php` (303 LOC, parse + index) + v1 `MappingDraftWriter::writeAtomic` (`MappingDraftWriter.php:34-50`) + `buildDraftPayload` (lines 76-95).

**Atomic write pattern** — port verbatim from v1 `MappingDraftWriter.php:34-50` (the load-bearing pattern for D-07):

```php
public function writeAtomic(string $path, string $contents): bool
{
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    $bytes = @file_put_contents($tmp, $contents);
    if ($bytes === false) {
        return false;
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    return true;
}
```

**Row payload shape** — port from v1 `MappingDraftWriter::buildDraftPayload()` lines 76-95, but **replace `'pending'` with the D-02 status** and **add the row to a flat `proposals:` list** (D-01, no nodeClasses/pageParts groups):

```php
public function buildRow(array $proposal, string $initialStatus): array
{
    return [
        'table'           => (string) ($proposal['table'] ?? ''),
        'column'          => (string) ($proposal['column'] ?? ''),
        'targetEntryType' => (string) ($proposal['targetEntryType'] ?? ''),
        'targetHandle'    => (string) ($proposal['targetHandle'] ?? ''),
        'handler'         => (string) ($proposal['handler'] ?? ''),
        'confidence'      => (string) ($proposal['confidence'] ?? 'medium'),
        'rationale'       => (string) ($proposal['rationale'] ?? ''),
        'fillRate'        => (float)  ($proposal['fillRate'] ?? 0),
        'sqlType'         => (string) ($proposal['sqlType'] ?? ''),
        'samples'         => array_slice((array) ($proposal['samples'] ?? []), 0, 3),
        'status'          => $initialStatus,    // D-02 confidence-tier mapping
    ];
}
```

YAML dump flags — v1 `MappingDraftWriter.php:61` is canonical:

```php
$yaml = Yaml::dump(['proposals' => $rows], 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
```

**Skip-existing merge (D-04)** — port indexing strategy from v1 `MappingDraftWriter::mergePreservingEdits()` lines 146-173, but key by `(table, column, targetEntryType)` tuple (D-04) instead of v1's `(table, column)`:

```php
public function merge(array $existing, array $incoming): array
{
    $existingByKey = [];
    foreach (($existing['proposals'] ?? []) as $row) {
        if (!is_array($row)) { continue; }
        $key = ($row['table'] ?? '') . '|' . ($row['column'] ?? '') . '|' . ($row['targetEntryType'] ?? '');
        $existingByKey[$key] = $row;
    }
    $merged = [];
    $seen = [];
    // Preserve every existing row verbatim — operator decisions are sacred (CONTEXT.md MAP-04).
    foreach (($existing['proposals'] ?? []) as $row) {
        $key = ($row['table'] ?? '') . '|' . ($row['column'] ?? '') . '|' . ($row['targetEntryType'] ?? '');
        $merged[] = $row;
        $seen[$key] = true;
    }
    // Append only NEW tuples from incoming.
    foreach ($incoming as $row) {
        $key = ($row['table'] ?? '') . '|' . ($row['column'] ?? '') . '|' . ($row['targetEntryType'] ?? '');
        if (!isset($seen[$key])) {
            $merged[] = $row;
            $seen[$key] = true;
        }
    }
    return ['proposals' => $merged];
}
```

**Per-row mutation for `map` loop** — port from v1 `MappingDraftReader::mutateStatusInPlace()` lines 248-285, simplified for the flat-list shape:

```php
public function setStatus(string $path, int $rowIndex, string $status, ?string $rationale = null, ?string $targetHandle = null, ?string $handler = null): bool
{
    $parsed = Yaml::parseFile($path) ?? [];
    if (!isset($parsed['proposals'][$rowIndex]) || !is_array($parsed['proposals'][$rowIndex])) {
        return false;
    }
    $parsed['proposals'][$rowIndex]['status'] = $status;
    if ($rationale !== null)    { $parsed['proposals'][$rowIndex]['rationale']    = $rationale; }
    if ($targetHandle !== null) { $parsed['proposals'][$rowIndex]['targetHandle'] = $targetHandle; }
    if ($handler !== null)      { $parsed['proposals'][$rowIndex]['handler']      = $handler; }
    $yaml = Yaml::dump($parsed, 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    return $this->writeAtomic($path, $yaml);
}
```

**Path resolution** — read from `Settings::mappingPath` first, fall back to `Craft::$app->path->getStoragePath() . '/migration/mapping.yaml'`. Same fallback shape as `DoctorController::checkStorageDir` (line 101).

---

### `src/mapping/CoverageAuditor.php` (service, transform)

**Analog:** referenced in v1 `AnalyzeController::actionCoverage()` lines 437-491 (the `$module->coverageAuditor->audit($mapping)` call). The actual v1 service is at `src/bridge/mapping/CoverageAuditor.php` (not read here — but the call shape is documented).

**STRUCTURAL_IGNORE constant** (D-14) — flesh out from v1 brownfield + Kunstmaan core schema. The CONTEXT.md seed list:

```php
private const STRUCTURAL_IGNORE = [
    'id', 'parent_id', 'lft', 'rgt', 'lvl', 'tree_root',
    'created', 'updated', 'createdBy', 'updatedBy',
    'internal_name', 'discr', 'public', 'hidden_from_nav',
    'children_index', 'sequencenumber', 'ref',
    // Researcher will flesh out from v1 brownfield + KunstmaanCoreTables.
];
```

**Algorithm** (D-14): a column is "data-bearing" if `fillRate > 0` AND name not in `STRUCTURAL_IGNORE`. A column is "covered" if mapping.yaml has at least one row with matching `(table, column)` and `status ∈ {accepted, dropped}`. Returns a list of `{table, column, fillRate, rows}` violation rows.

**Output format** — match v1 `actionCoverage` lines 473-488 stderr layout for ergonomic grep-friendliness:

```
FAIL <table>: N unmapped data-bearing column(s)
     - <col> (fill=NN.N%, rows=M)
```

**Dual-mode gate (D-15):** CoverageAuditor is the verdict producer. The two consumer code paths (Phase 3's `migrate --live` vs `migrate --dry-run`) call it identically and just choose how to react to a non-empty violation list (hard-fail vs WARN-and-continue).

---

### `src/mapping/MappingAuditor.php` (service, transform)

**Analogs:** v1 `AnalyzeController::collectMappingDrifts()` lines 1769-1888 (drift detection algorithm) + v1 `MappingValidator.php` (647 LOC handler-classification rules — port the canonical handler list).

**Excluded handles (do not flag drift)** — copy verbatim from v1 `AnalyzeController.php:1794-1802`:

```php
$excludedHandles = [
    'kunstmaanSourceId',  // set programmatically at load time
    'title', 'slug', 'postDate', 'expiryDate', 'enabled', 'authorId',  // native Element props
];
```

**FieldLayout walk** — copy from v1 lines 1827-1837:

```php
$entryType = Craft::$app->entries->getEntryTypeByHandle($entryTypeHandle);
if ($entryType === null) {
    $drifts[] = sprintf("DRIFT: entryType '%s' (referenced by %s) not found in Craft", $entryTypeHandle, $fqcn);
    continue;
}
$layout = $entryType->getFieldLayout();
$validHandles = [];
foreach ($layout->getCustomFields() as $field) {
    $validHandles[(string) $field->handle] = true;
}
```

**Handler vocabulary cross-check** — port the canonical list from v1 `MappingValidator.php:56-70`:

```php
private const CANONICAL_HANDLERS = [
    'asset', 'ckeditor', 'dropdown', 'link', 'matrix', 'plain',
    'relation', 'seomatic', 'splitName',
];
private const HANDLER_ALIASES = [
    'plainText' => 'plain',
];
```

**Output (D-16):** structured findings as a list of `[table, column, targetEntryType, targetHandle, kind]` where `kind ∈ {missing-entry-type, missing-handle, handler-classification-mismatch}`. Returns an array; consumer renders to console + `MAPPING-AUDIT.md`.

**Strict vs warn-only (D-16):** MappingAuditor returns the verdict. `analyze` prints WARN-only by default. `analyze --audit-strict` and `migrate --live` (always strict) elevate to fail-state. The auditor itself is mode-agnostic.

---

### `src/filter/MigrationFilters.php` (model, immutable VO)

**Analogs:** v1 `models/MigrationFilters.php` (48 LOC — **shape only, NOT semantics**; v1 is post-Craft filtering), Phase 1 `Settings.php` for PHP 8.3 readonly idiom.

**v2 shape (D-12 — three properties, not four):**

```php
<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\filter;

/**
 * Legacy-side scoping for every Phase 3+ stage (extract / transform / load / verify).
 *
 * NOT v1's MigrationFilters — that's post-Craft (includeDeleted/Offline/Drafts).
 * v2 redesigns for Kunstmaan source filtering per CONTEXT.md D-09..D-13.
 *
 * Empty `entities` / `locales` mean unbounded; null `since` means no date floor.
 */
final class MigrationFilters
{
    public function __construct(
        /** @var list<string> Kunstmaan source class names (e.g. 'NewsPage') */
        public readonly array $entities = [],
        /** @var list<string> Kunstmaan locale codes (e.g. ['nl', 'fr']) */
        public readonly array $locales = [],
        /** YYYY-MM-DD date floor; column-presence detection per D-11 */
        public readonly ?string $since = null,
    ) {
    }
}
```

**No `forSection()` resolver** — that was v1's section-override surface. v2 has no per-section overrides in v1.0 scope (per D-13: "Filters apply uniformly through every stage").

**`--max-per-entity` is DROPPED** (D-12): no fourth property. Patches required: REQUIREMENTS.md FILT-01 and ROADMAP.md Phase 2 success criterion 5 (per CONTEXT.md doc-patches block).

---

### `src/filter/FilterFactory.php` (service, transform)

**Analog:** no v1 analog. Closest pattern: Phase 1 `Settings::init()` (`src/models/Settings.php:56-82`) for the env-fallback / `??=` merge idiom.

**Pattern:** the factory accepts CLI flag values (strings from `$controller->entities`, `$controller->locales`, `$controller->since`) and merges with `Settings::default*` per D-10:

```php
final class FilterFactory extends Component
{
    public function fromCli(?string $entitiesArg, ?string $localesArg, ?string $sinceArg): MigrationFilters
    {
        $settings = Plugin::getInstance()->getSettings();

        // D-10: empty-string CLI value clears the default; null/unset falls through.
        $entities = $entitiesArg !== null
            ? ($entitiesArg === '' ? [] : array_filter(array_map('trim', explode(',', $entitiesArg))))
            : (array) $settings->defaultEntities;

        $locales = $localesArg !== null
            ? ($localesArg === '' ? [] : array_filter(array_map('trim', explode(',', $localesArg))))
            : (array) $settings->defaultLocales;

        $since = $sinceArg !== null
            ? ($sinceArg === '' ? null : $sinceArg)
            : $settings->defaultSince;

        return new MigrationFilters(
            entities: array_values($entities),
            locales:  array_values($locales),
            since:    $since,
        );
    }
}
```

**Wiring (D-13):** every Phase 2 controller (`AnalyzeController`, `MapController`) calls this in `beforeAction()` or at the top of `actionIndex()`:

```php
public function actionIndex(): int
{
    if (($gate = $this->enforceNeverProduction()) !== null) { return $gate; }
    $filters = Plugin::getInstance()->filterFactory->fromCli($this->entities, $this->locales, $this->since);
    // ... pass $filters into every service call
}
```

`doctor` accepts the flags (FILT-03) but ignores `$filters` — doctor doesn't read legacy data.

---

### `src/locale/LocalePreflight.php` (service, gate check)

**Analog:** v1 `AnalyzeController::actionDetectLocales()` lines 352-419 for the detect-half SQL; greenfield for the LOC-02 hard-fail logic.

**Detection** — copy from v1 `actionDetectLocales` lines 366-371 (via the v1 `extractService->detectLocales()` call which queries `kuma_node_translations`):

```php
public function detect(): array
{
    $rows = Plugin::getInstance()->legacyDbService->queryAll(
        'SELECT DISTINCT lang FROM kuma_node_translations ORDER BY lang',
    );
    return array_values(array_filter(array_map(fn($r) => (string) ($r['lang'] ?? ''), $rows)));
}
```

**Preflight (LOC-02 D-17 — greenfield):**

```php
/**
 * Returns null on pass, or list of unmapped-locale strings on fail.
 * `analyze`, `map`, `migrate`, `verify` call this before any legacy read.
 *
 * @return list<string>|null
 */
public function ensure(MigrationFilters $filters): ?array
{
    $detected = $this->detect();
    $mappedHandles = array_map(fn($s) => (string) $s->handle, Craft::$app->getSites()->getAllSites());
    $settingsLocales = (array) Plugin::getInstance()->getSettings()->defaultLocales;

    // If --locales explicitly set, scope check to that subset (operator-scoped run).
    $checkSet = $filters->locales !== [] ? $filters->locales : $detected;

    $unmapped = [];
    foreach ($checkSet as $locale) {
        if (!in_array($locale, $mappedHandles, true) && !in_array($locale, $settingsLocales, true)) {
            $unmapped[] = $locale;
        }
    }
    return $unmapped === [] ? null : $unmapped;
}
```

**Caller pattern** — every legacy-reading controller `actionIndex()` step 2 (after NeverProduction gate, before any DB read):

```php
$unmapped = Plugin::getInstance()->localePreflight->ensure($filters);
if ($unmapped !== null) {
    $this->stderr("FAIL unmapped Kunstmaan locales: " . implode(', ', $unmapped) . "\n", Console::FG_RED);
    // Print the same paste-ready sites: block as REPORT.md (delegate to ReportBuilder).
    return ExitCode::CONFIG;
}
```

---

## Shared Patterns

### NeverProduction Gate
**Source:** `src/NeverProductionTrait.php:9-19` + `src/console/DoctorController.php:32-34`
**Apply to:** Every controller action that touches legacy DB or writes mapping.yaml — i.e. `AnalyzeController::actionIndex`, `MapController::actionIndex`, `DoctorController::actionIndex` (already), and any future `MigrateController::actionXxx` action body (Phase 3).

```php
use lameco\kunstmaanmigrator\NeverProductionTrait;

class XxxController extends Controller
{
    use NeverProductionTrait;

    public function actionIndex(): int
    {
        if (($gate = $this->enforceNeverProduction()) !== null) {
            return $gate;
        }
        // ...
    }
}
```

This is the FIRST statement of every `action*` method. No exceptions.

---

### Atomic File Write
**Source:** v1 `MappingDraftWriter.php:34-50` (port verbatim into `src/mapping/MappingFile.php`)
**Apply to:** Every write to `mapping.yaml`, `schema-dump.json`, `REPORT.md`, `MAPPING-AUDIT.md`.

```php
$tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
@file_put_contents($tmp, $contents);
@rename($tmp, $path);
```

Operator can Ctrl+C any time. Per CONTEXT.md / D-07 + CLAUDE.md "atomic-always-on".

---

### OK / FAIL / WARN Console Output
**Source:** `src/console/DoctorController.php:36, 60, 66` (Phase 1 / D-19)
**Apply to:** Every controller action in Phase 2.

```php
$this->stdout("Doctor: preflight diagnostics\n", Console::FG_CYAN);  // header (no indent)
$this->stdout("  OK   <message>\n", Console::FG_GREEN);              // pass step
$this->stderr("  FAIL <message>\n", Console::FG_RED);                // fail step
$this->stdout("  WARN <message>\n", Console::FG_YELLOW);             // soft-warn
$this->stdout("\nDoctor: PASS\n", Console::FG_GREEN);                // final verdict
```

Two-space indent on per-step lines; cyan + unindented on action header. Exit `ExitCode::OK` on green; `ExitCode::UNSPECIFIED_ERROR` on any FAIL.

---

### Settings-then-Env Fallback
**Source:** `src/models/Settings.php:56-82` (Phase 1 / D-12 + D-14)
**Apply to:** Every place Phase 2 reads `Settings::defaultEntities`, `Settings::defaultLocales`, `Settings::defaultSince`, `Settings::mappingPath`, `Settings::llmModel`, `Settings::llmTimeout`, `Settings::anthropicApiKey`.

```php
$settings = Plugin::getInstance()->getSettings();
$value = $settings->llmModel;     // already null-coalesced from env via Settings::init()
```

`Settings::init()` does the env merge ONCE at boot. Don't re-read env vars from Phase 2 services — go through Settings.

---

### Service Component Idiom
**Source:** `src/db/LegacyDbService.php:24` + `src/Plugin.php:33-37`
**Apply to:** Every Phase 2 service.

- Extends `yii\base\Component`
- Registered in `Plugin::config()` `components` map
- Resolved via `Plugin::getInstance()->myService` (magic `@property-read` annotation on `Plugin.php` for IDE)
- `final` class declaration where possible (HeuristicProposer, LlmClassifier are `final` in v1)

---

### YAML I/O
**Source:** Symfony YAML 6+ (already in composer per Phase 1 — confirm in `composer.json` if not)
**Dump flags:** `Yaml::dump($data, 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK)` — v1 `MappingDraftWriter.php:61`. The `4`/`2` are inline-level/indent; `DUMP_MULTI_LINE_LITERAL_BLOCK` keeps long sample strings readable.
**Parse:** `Yaml::parseFile($path) ?? []` — v1 `MappingDraftReader.php:105`.

---

## No Analog Found

All Phase 2 files have at least a partial analog. The two greenfield items below are flagged because their core logic is new even though the surrounding idiom is not:

| File | Role | Data Flow | Greenfield justification |
|------|------|-----------|--------------------------|
| `src/console/MapController.php` (rubber-stamp loop body — D-05/D-06/D-07/D-08) | controller (interactive) | event-driven | v1 had no rubber-stamp CLI loop. The compact-block UX, two-step `[r]emap` picker, and stateless-resume design are all new. Idiom (NeverProduction gate, options(), select/prompt) ports from Phase 1 + v1 `actionApplyProposals`; the loop core is fresh. |
| `src/locale/LocalePreflight::ensure()` (LOC-02 hard-fail gate) | service (gate) | request-response | v1's `actionDetectLocales` is detection-only; the gating preflight (compare detected vs Settings::defaultLocales + Craft sites) is new. |

## Metadata

**Analog search scope:**
- Phase 1 idioms: `/Users/macbook25/Sites/craft-kunstmaan-migrator-revisited/src/` (Plugin, NeverProductionTrait, console/, db/, models/, migrations/)
- v1 brownfield: `/Users/macbook25/Sites/craft-kunstmaan-migrator/src/bridge/services/` (HeuristicProposer, LlmClassifier, MappingDraftReader, MappingDraftWriter, ProposalRouter), `bridge/mapping/` (MappingValidator, MappingLoader), `bridge/console/controllers/AnalyzeController.php` (single 2138-LOC reference for the v1 multi-action analyze surface)
- v1 reference-only (different scope): `src/models/MigrationFilters.php` (post-Craft filtering, NOT to port — only the readonly VO shape)

**Files scanned:** 13 (8 in this repo + 5 in v1 brownfield)
**Pattern extraction date:** 2026-04-25

---

## PATTERN MAPPING COMPLETE

**Phase:** 02 - schema-mapping-filters
**Files classified:** 14 new + 2 modified (16 total)
**Analogs found:** 16 / 16

### Coverage
- Files with exact analog (port verbatim or near-verbatim): **5** (HeuristicProposer, LlmClassifier, MappingFile.writeAtomic, DoctorController.checkMappingFile, Plugin.config())
- Files with role-match analog (port shape, write fresh body): **9** (AnalyzeController, MapController, SchemaDumper, ReportBuilder, MappingFile.merge/setStatus, CoverageAuditor, MappingAuditor, MigrationFilters, LocalePreflight.detect)
- Files with partial analog (greenfield body, established idiom): **2** (FilterFactory, MapController rubber-stamp loop)
- Files with no analog: **0**

### Key Patterns Identified
- **Console controllers** — flat `src/console/`, single `controllerNamespace`, NeverProduction gate as first statement, two-space indented `OK`/`FAIL`/`WARN` ANSI output (Phase 1 / D-03 + D-19 + D-20)
- **Atomic write** — v1 `MappingDraftWriter::writeAtomic` (tmp + rename) is THE pattern for every Phase 2 file output (mapping.yaml, schema-dump.json, REPORT.md, MAPPING-AUDIT.md). Per-keypress write in MapController per D-07.
- **Settings-then-env** — Phase 1's `Settings::init()` already env-merges once at boot. Phase 2 just reads properties.
- **Service registration** — `yii\base\Component` + `Plugin::config()` components map + `Plugin::getInstance()->serviceName` access. Final classes where v1 was final.
- **Symfony YAML** — `Yaml::dump($data, 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK)` is the canonical dump shape (byte-stable diffs).
- **Aggressive v1 reuse** — 5 v1 services (~1900 LOC) port near-verbatim. Phase 2 is largely a reshape, not a redesign.

### File Created
`/Users/macbook25/Sites/craft-kunstmaan-migrator-revisited/.planning/phases/02-schema-mapping-filters/02-PATTERNS.md`

### Ready for Planning
Pattern mapping complete. Planner can now reference exact analog files + line ranges in PLAN.md actions.
