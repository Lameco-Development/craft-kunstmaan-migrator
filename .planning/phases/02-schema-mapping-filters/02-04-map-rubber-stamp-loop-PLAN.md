---
phase: 02-schema-mapping-filters
plan: 04
type: execute
wave: 3
depends_on:
  - "02-01"
  - "02-02"
files_modified:
  - src/console/MapController.php
autonomous: true
requirements:
  - MAP-05
  - FILT-03
requirements_addressed:
  - MAP-05
  - FILT-03
must_haves:
  truths:
    - "MapController::actionIndex walks every mapping.yaml row with status in {proposed, needs-review} (D-08 stateless resume)"
    - "Each row renders as a compact one-screen block with [N/total] header, samples truncated to 60 chars × max 3 (D-05)"
    - "Operator chooses a/d/r/s/q per row (D-05 prompt vocabulary)"
    - "[r]emap is a two-step picker: handler enum, then numbered handle list filtered by handler classification (D-06)"
    - "[t]ype manually fallback validates against live Craft FieldLayout (D-06)"
    - "Atomic write per keypress via MappingFile::setStatus → writeAtomic (D-07)"
    - "[s]kip does NOT mutate the row; [q]uit exits cleanly"
    - "--auto-accept-high non-interactively promotes confidence: high + status: proposed → status: accepted, then exits (MAP-05)"
    - "MapController accepts --entities, --locales, --since (FILT-03 — used to scope which rows the loop walks)"
    - "NeverProduction gate is FIRST statement of actionIndex (D-20)"
  artifacts:
    - path: "src/console/MapController.php"
      provides: "Interactive rubber-stamp loop over mapping.yaml proposed/needs-review rows"
      contains: "class MapController extends Controller"
  key_links:
    - from: "src/console/MapController.php"
      to: "src/mapping/MappingFile.php"
      via: "Plugin::getInstance()->mappingFile->loadProposed / setStatus / resolvePath"
      pattern: "mappingFile->(loadProposed|setStatus|resolvePath)"
    - from: "src/console/MapController.php"
      to: "src/locale/LocalePreflight.php"
      via: "Plugin::getInstance()->localePreflight->ensure(\$filters)"
      pattern: "localePreflight->ensure"
    - from: "src/console/MapController.php"
      to: "Craft::\\$app->entries"
      via: "getEntryTypeByHandle and FieldLayout walk for [r]emap picker validation"
      pattern: "getEntryTypeByHandle"
---

<objective>
Ship the `kunstmaan-migrator/map` interactive rubber-stamp loop — the highest-value greenfield element of Phase 2.

Purpose: Walk every mapping.yaml row with `status ∈ {proposed, needs-review}` in file order, render the compact one-screen block (D-05), prompt the operator for `a/d/r/s/q`, persist each decision atomically via `MappingFile::setStatus` (D-07). `[r]emap` opens a two-step picker (handler enum → numbered handle list filtered by classification) with `[t]ype manually` fallback validated against the live Craft FieldLayout (D-06). `--auto-accept-high` is a non-interactive promotion path that mirrors `analyze --auto-accept-high` for CI/re-run convenience (MAP-05). Stateless across invocations — mapping.yaml is the only state (D-08).

Output: 1 new file `src/console/MapController.php`. NO Plugin.php changes (this controller consumes services already registered by Plans 01 and 02).
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/PROJECT.md
@.planning/phases/02-schema-mapping-filters/02-CONTEXT.md
@.planning/phases/02-schema-mapping-filters/02-PATTERNS.md

@src/console/DoctorController.php
@src/console/MigrateController.php
@src/Plugin.php
@src/mapping/MappingFile.php
@src/locale/LocalePreflight.php
@src/filter/FilterFactory.php
@src/filter/MigrationFilters.php

<interfaces>
<!-- v1 brownfield reference (read-only) — interactive prompt shapes -->
- ~/Sites/craft-kunstmaan-migrator/src/bridge/console/controllers/AnalyzeController.php
  - lines 978-988: $this->select() with a/d/r/s/q-style action map
  - lines 1001-1004: $this->prompt() for free-text rationale
  - lines 1027-1044: numbered handle picker via $this->select()

<!-- From src/console/DoctorController.php (Phase 1 idiom) -->
class DoctorController extends Controller {
    use NeverProductionTrait;
    public function actionIndex(): int {
        if (($gate = $this->enforceNeverProduction()) !== null) { return $gate; }
        $this->stdout("Doctor: preflight diagnostics\n", Console::FG_CYAN);
        // ...
    }
}

<!-- From src/mapping/MappingFile.php (Plan 02) -->
public function resolvePath(): string;
public function load(?string $path = null): array;                    // ['proposals' => [...]]
public function loadProposed(?string $path = null): array;            // rows where status ∈ {proposed, needs-review}
public function setStatus(string $path, int $rowIndex, string $status, ?string $rationale = null, ?string $targetHandle = null, ?string $handler = null): bool;

<!-- From src/locale/LocalePreflight.php (Plan 01) -->
public function ensure(MigrationFilters $filters): ?array;            // null = pass; list of unmapped on fail

<!-- From src/filter/FilterFactory.php (Plan 01) -->
public function fromCli(?string $entities, ?string $locales, ?string $since): MigrationFilters;
</interfaces>
</context>

<tasks>

<task type="auto">
  <name>Task 1: Create MapController with rubber-stamp loop, two-step picker, and --auto-accept-high path</name>
  <files>src/console/MapController.php</files>
  <read_first>
    - .planning/phases/02-schema-mapping-filters/02-CONTEXT.md (D-05 compact-block UX with full ASCII layout; D-06 two-step picker; D-07 atomic per-keypress; D-08 stateless resume; MAP-05 --auto-accept-high semantics)
    - .planning/phases/02-schema-mapping-filters/02-PATTERNS.md ("src/console/MapController.php" section, lines 113–177)
    - src/console/DoctorController.php (NeverProduction gate idiom; ANSI color usage; ExitCode shape; class header)
    - src/console/MigrateController.php (controller idiom — namespace, gate-first, options())
    - ~/Sites/craft-kunstmaan-migrator/src/bridge/console/controllers/AnalyzeController.php (lines 978-988 select shape; lines 1001-1004 prompt shape; lines 1027-1044 picker shape)
    - src/mapping/MappingFile.php (loadProposed + setStatus + resolvePath signatures)
    - src/Plugin.php (Plugin::getInstance() pattern; magic property reads for mappingFile / filterFactory / localePreflight)
  </read_first>
  <action>
Create `src/console/MapController.php` (~250 LOC). Full content:

```php
<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\console;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use lameco\kunstmaanmigrator\NeverProductionTrait;
use lameco\kunstmaanmigrator\Plugin;
use yii\console\ExitCode;

/**
 * Map — interactive rubber-stamp loop over mapping.yaml's proposed/needs-review rows.
 *
 * Greenfield in v2 — v1 had no equivalent (its CP MappingDraftUtility and
 * `analyze/apply-proposals` non-interactive command are both dropped per PROJECT.md).
 *
 * Loop body per row (D-05):
 *   1. Render compact one-screen block ([N/total] table.column header, separator,
 *      proposal summary, fillRate, sqlType, up to 3 samples truncated to 60 chars).
 *   2. Prompt: [a]ccept / [d]rop / [r]emap / [s]kip / [q]uit.
 *   3. Apply keypress; if mutating ([a]/[d]/[r]), persist atomically via
 *      MappingFile::setStatus (which calls writeAtomic — D-07 tmp+rename).
 *
 * Stateless resume (D-08): mapping.yaml is the only state. Each invocation re-reads
 * loadProposed() and walks current proposed/needs-review rows in file order.
 *
 * --auto-accept-high (MAP-05): non-interactively promote every confidence: high +
 * status: proposed row to status: accepted, then exit. Mirrors analyze's flag.
 */
class MapController extends Controller
{
    use NeverProductionTrait;

    public bool    $autoAcceptHigh = false;
    public ?string $entities       = null;
    public ?string $locales        = null;
    public ?string $since          = null;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), [
            'autoAcceptHigh',
            'entities', 'locales', 'since',
        ]);
    }

    public function actionIndex(): int
    {
        // FND-04 / D-20: NeverProduction guard FIRST.
        if (($gate = $this->enforceNeverProduction()) !== null) {
            return $gate;
        }

        $plugin  = Plugin::getInstance();
        $filters = $plugin->filterFactory->fromCli($this->entities, $this->locales, $this->since);

        // Locale preflight (D-17 LOC-02 — every legacy-reading command checks).
        // map() doesn't read legacy DB directly, but the rubber-stamp loop is downstream
        // of an analyze run that did — preflighting again here keeps the gate consistent
        // and catches the case where Craft sites changed since the last analyze.
        $unmapped = $plugin->localePreflight->ensure($filters);
        if ($unmapped !== null) {
            $this->stderr(
                "  FAIL unmapped Kunstmaan locales: " . implode(', ', $unmapped) . "\n"
                . "       Add them to config/sites.php or Settings::defaultLocales, then re-run.\n",
                Console::FG_RED,
            );
            return ExitCode::CONFIG;
        }

        $path = $plugin->mappingFile->resolvePath();
        if (!is_file($path)) {
            $this->stderr("  FAIL mapping.yaml not found at {$path} — run analyze first.\n", Console::FG_RED);
            return ExitCode::CONFIG;
        }

        // --auto-accept-high non-interactive path (MAP-05).
        if ($this->autoAcceptHigh) {
            return $this->runAutoAcceptHigh($path);
        }

        // Interactive rubber-stamp loop.
        return $this->runInteractiveLoop($path, $filters);
    }

    /**
     * Non-interactive: promote every status:proposed + confidence:high row → status:accepted.
     */
    private function runAutoAcceptHigh(string $path): int
    {
        $plugin = Plugin::getInstance();
        $data = $plugin->mappingFile->load($path);
        $promoted = 0;
        foreach ($data['proposals'] as $idx => $row) {
            if (($row['status'] ?? '') === 'proposed' && ($row['confidence'] ?? '') === 'high') {
                if ($plugin->mappingFile->setStatus($path, $idx, 'accepted')) {
                    $promoted++;
                }
            }
        }
        $this->stdout("Map: promoted {$promoted} high-confidence proposals → accepted\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    /**
     * Interactive walk: render, prompt, apply, repeat. Stateless — re-reads after each
     * mutation so the loop sees the current file state.
     */
    private function runInteractiveLoop(string $path, \lameco\kunstmaanmigrator\filter\MigrationFilters $filters): int
    {
        $plugin = Plugin::getInstance();
        $rows = $plugin->mappingFile->loadProposed($path);
        $rows = $this->applyEntitiesFilter($rows, $filters);
        $total = count($rows);
        if ($total === 0) {
            $this->stdout("Map: nothing to review (no rows with status proposed/needs-review).\n", Console::FG_GREEN);
            return ExitCode::OK;
        }

        $this->stdout("Map: walking {$total} rows. [a]ccept / [d]rop / [r]emap / [s]kip / [q]uit\n\n", Console::FG_CYAN);

        // We iterate by file index (not by filtered list index) so setStatus mutates the right row.
        // Re-read the full file to get every row's index, then walk only the proposed/needs-review subset.
        $allData = $plugin->mappingFile->load($path);
        $allRows = $allData['proposals'];
        $walkOrder = [];
        foreach ($allRows as $idx => $row) {
            $status = (string) ($row['status'] ?? '');
            if ($status !== 'proposed' && $status !== 'needs-review') { continue; }
            if (!$this->matchesEntitiesFilter($row, $filters)) { continue; }
            $walkOrder[] = $idx;
        }

        $position = 0;
        foreach ($walkOrder as $rowIndex) {
            $position++;
            // Re-read row each iteration in case the file was mutated externally.
            $current = $plugin->mappingFile->load($path);
            $row = $current['proposals'][$rowIndex] ?? null;
            if (!is_array($row)) { continue; }
            $status = (string) ($row['status'] ?? '');
            if ($status !== 'proposed' && $status !== 'needs-review') { continue; }

            $this->stdout($this->renderRowBlock($row, $position, count($walkOrder)));

            $action = strtolower((string) $this->select(
                '  action',
                ['a' => 'accept', 'd' => 'drop', 'r' => 'remap', 's' => 'skip', 'q' => 'quit'],
            ));

            switch ($action) {
                case 'a':
                    $plugin->mappingFile->setStatus($path, $rowIndex, 'accepted');
                    $this->stdout("    → accepted\n\n", Console::FG_GREEN);
                    break;
                case 'd':
                    $rationale = (string) $this->prompt('  rationale (enter for default):', [
                        'required' => false,
                        'default'  => 'no Craft target — operator-decided drop in map loop',
                    ]);
                    $plugin->mappingFile->setStatus($path, $rowIndex, 'dropped', $rationale);
                    $this->stdout("    → dropped\n\n", Console::FG_YELLOW);
                    break;
                case 'r':
                    [$newHandler, $newHandle] = $this->runRemapPicker($row);
                    if ($newHandler !== null && $newHandle !== null) {
                        $plugin->mappingFile->setStatus(
                            $path, $rowIndex, 'accepted',
                            null,
                            $newHandle,
                            $newHandler,
                        );
                        $this->stdout("    → remapped to {$newHandler}.{$newHandle} (accepted)\n\n", Console::FG_GREEN);
                    } else {
                        $this->stdout("    → remap cancelled (skipped, status unchanged)\n\n", Console::FG_YELLOW);
                    }
                    break;
                case 's':
                    // D-07: skip does NOT mutate the row.
                    $this->stdout("    → skipped\n\n", Console::FG_CYAN);
                    break;
                case 'q':
                    $this->stdout("Map: exit (any decisions made are persisted).\n", Console::FG_CYAN);
                    return ExitCode::OK;
            }
        }

        $this->stdout("\nMap: PASS — walked {$total} rows.\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    /**
     * D-05 compact one-screen renderer (80×24 target).
     *
     * @param array<string, mixed> $row
     */
    private function renderRowBlock(array $row, int $position, int $total): string
    {
        $sep = str_repeat('─', 60) . "\n";
        $table  = (string) ($row['table'] ?? '');
        $column = (string) ($row['column'] ?? '');
        $entry  = (string) ($row['targetEntryType'] ?? '');
        $handle = (string) ($row['targetHandle'] ?? '');
        $handler= (string) ($row['handler'] ?? '');
        $confidence = (string) ($row['confidence'] ?? '');
        $rationale  = (string) ($row['rationale'] ?? '');
        $fillRate   = (float)  ($row['fillRate'] ?? 0);
        $sqlType    = (string) ($row['sqlType'] ?? '');
        $samples    = (array)  ($row['samples'] ?? []);

        $out  = "[{$position}/{$total}] {$table}.{$column}\n";
        $out .= $sep;
        $out .= "Proposed: {$entry}.{$handle}  (handler: {$handler}, confidence: {$confidence})\n";
        $out .= "Rationale: {$rationale}\n";
        $out .= sprintf("Fill rate: %d%%        SQL type: %s\n", (int) round($fillRate * 100), $sqlType);
        if ($samples !== []) {
            $out .= "Samples:\n";
            $i = 0;
            foreach (array_slice($samples, 0, 3) as $s) {
                $i++;
                $line = is_string($s) ? $s : (string) json_encode($s);
                if (mb_strlen($line) > 60) {
                    $line = mb_substr($line, 0, 60) . '…';
                }
                $out .= "  {$i}. {$line}\n";
            }
        }
        $out .= $sep;
        return $out;
    }

    /**
     * D-06 two-step [r]emap picker.
     *   Step 1: handler enum.
     *   Step 2: numbered list of Craft fields filtered to handler classification, with
     *           [t]ype manually fallback (validated against the live FieldLayout).
     *
     * Returns [handler|null, handle|null]. null/null = cancelled.
     *
     * @param array<string, mixed> $row
     * @return array{0: string|null, 1: string|null}
     */
    private function runRemapPicker(array $row): array
    {
        $handlers = [
            'a' => 'asset', 'c' => 'ckeditor', 'd' => 'date', 'e' => 'email',
            'l' => 'link', 'm' => 'matrix', 'p' => 'plain', 'r' => 'relation',
            'u' => 'url', 'b' => 'back',
        ];
        $picked = strtolower((string) $this->select('  handler?', $handlers));
        if ($picked === 'b') {
            return [null, null];
        }
        $newHandler = $handlers[$picked] ?? null;
        if ($newHandler === null || $newHandler === 'back') {
            return [null, null];
        }

        $entryType = (string) ($row['targetEntryType'] ?? '');
        $candidateHandles = $this->fieldHandlesForEntryType($entryType);

        if ($candidateHandles === []) {
            // No fields — fall straight through to type-manually.
            return $this->typeManuallyHandle($entryType, $newHandler);
        }

        // Build picker entries (handle => label).
        $choices = [];
        foreach ($candidateHandles as $h) {
            $choices[$h] = $h;
        }
        $choices['t'] = '[type manually]';
        $choices['b'] = '[back]';
        $picked = (string) $this->select(
            "  target handle for {$entryType}?",
            $choices,
        );
        if ($picked === 'b') {
            return [null, null];
        }
        if ($picked === 't') {
            return $this->typeManuallyHandle($entryType, $newHandler);
        }
        return [$newHandler, $picked];
    }

    /**
     * [t]ype manually fallback. Validates against the live FieldLayout per D-06.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function typeManuallyHandle(string $entryType, string $handler): array
    {
        while (true) {
            $h = (string) $this->prompt('  type a handle (or "back"):', ['required' => false]);
            if ($h === '' || strtolower($h) === 'back') {
                return [null, null];
            }
            $valid = $this->fieldHandlesForEntryType($entryType);
            if (in_array($h, $valid, true)) {
                return [$handler, $h];
            }
            $this->stdout("  invalid handle. Available: " . implode(', ', $valid) . "\n", Console::FG_YELLOW);
            // loop and re-prompt
        }
    }

    /**
     * Live FieldLayout walk for D-06 picker + free-text validation.
     *
     * @return list<string>
     */
    private function fieldHandlesForEntryType(string $entryType): array
    {
        if ($entryType === '') {
            return [];
        }
        $type = Craft::$app->entries->getEntryTypeByHandle($entryType);
        if ($type === null) {
            return [];
        }
        $layout = $type->getFieldLayout();
        if ($layout === null) {
            return [];
        }
        $out = [];
        foreach ($layout->getCustomFields() as $f) {
            $h = (string) $f->handle;
            if ($h !== '') {
                $out[] = $h;
            }
        }
        return $out;
    }

    /**
     * Filter rows by --entities allow-list (D-13). Empty list = unbounded.
     *
     * Heuristic: NewsPage matches kuma_news_page* tables.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function applyEntitiesFilter(array $rows, \lameco\kunstmaanmigrator\filter\MigrationFilters $filters): array
    {
        if ($filters->entities === []) {
            return $rows;
        }
        $out = [];
        foreach ($rows as $r) {
            if ($this->matchesEntitiesFilter($r, $filters)) {
                $out[] = $r;
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function matchesEntitiesFilter(array $row, \lameco\kunstmaanmigrator\filter\MigrationFilters $filters): bool
    {
        if ($filters->entities === []) {
            return true;
        }
        $table = (string) ($row['table'] ?? '');
        foreach ($filters->entities as $e) {
            $snake = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $e) ?? $e);
            if (str_starts_with($table, 'kuma_' . $snake)) {
                return true;
            }
        }
        return false;
    }
}
```

Notes:
- The class uses Yii's `Controller::select()` and `Controller::prompt()` for interactive input — both are inherited from `craft\console\Controller`. They handle stdin reads and noninteractive-default semantics.
- Per D-07, every mutating action calls `MappingFile::setStatus` which is itself an atomic write (tmp+rename). One keypress → one full file rewrite. This is fine — mapping.yaml is small (hundreds of KB at most for the CQM rehearsal corpus).
- Per D-08, the loop re-reads the row state inside the foreach so external file mutations don't corrupt the walk. The `$walkOrder` is computed once at the top to lock in iteration order, but each iteration re-validates the row's current status before prompting.
- The `--locales` and `--since` filter flags are accepted (FILT-03) but the map loop's row-walk doesn't use `$since` (mapping rows don't have dates). They're forwarded to the LocalePreflight for the locale-mapping check; `--since` is captured in the VO but is a no-op at this surface (Phase 3+ stages will use it).
- The `[t]ype manually` validation re-prompts in a `while (true)` loop until the operator either types a valid handle or types `back` to cancel — keeps invalid input out of mapping.yaml without crashing.
  </action>
  <verify>
    <automated>php -l src/console/MapController.php</automated>
  </verify>
  <acceptance_criteria>
    - `php -l src/console/MapController.php` exits 0
    - `grep -c 'class MapController extends Controller' src/console/MapController.php` equals 1
    - `grep -c 'use NeverProductionTrait;' src/console/MapController.php` equals 1
    - `grep -nE 'enforceNeverProduction' src/console/MapController.php` shows the gate is the FIRST statement of actionIndex (gate-first idiom — verifiable by inspecting line numbers)
    - `grep -c "public bool \$autoAcceptHigh" src/console/MapController.php` equals 1
    - `grep -c "public ?string \$entities" src/console/MapController.php` equals 1
    - `grep -c "public ?string \$locales" src/console/MapController.php` equals 1
    - `grep -c "public ?string \$since" src/console/MapController.php` equals 1
    - `grep -c "'autoAcceptHigh'" src/console/MapController.php` equals 1
    - `grep -c "'entities', 'locales', 'since'" src/console/MapController.php` equals 1
    - `grep -c 'mappingFile->setStatus' src/console/MapController.php` is 2 or more (atomic per-keypress writes)
    - `grep -c 'mappingFile->loadProposed\|mappingFile->load' src/console/MapController.php` is 2 or more
    - `grep -c 'localePreflight->ensure' src/console/MapController.php` equals 1
    - `grep -c "'a' => 'accept'" src/console/MapController.php` equals 1
    - `grep -c "'d' => 'drop'" src/console/MapController.php` equals 1
    - `grep -c "'r' => 'remap'" src/console/MapController.php` equals 1
    - `grep -c "'s' => 'skip'" src/console/MapController.php` equals 1
    - `grep -c "'q' => 'quit'" src/console/MapController.php` equals 1
    - `grep -c 'getEntryTypeByHandle' src/console/MapController.php` equals 1
    - `grep -c 'getCustomFields' src/console/MapController.php` equals 1
    - `composer test` exits 0
  </acceptance_criteria>
  <done>MapController interactive loop with two-step picker, atomic per-keypress writes, --auto-accept-high path, FieldLayout-validated free-text fallback. NeverProduction gate first; PHP lint clean; composer test green.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| Operator stdin → MapController | Untrusted operator-supplied keypresses + free-text rationale + free-text handles. Must validate handles against live FieldLayout before persistence (D-06). |
| MapController → mapping.yaml | Atomic-per-keypress writes via MappingFile::setStatus → writeAtomic. Tampering during write covered by Plan 02 mitigation. |
| Production env → MapController | NeverProduction trait blocks the loop when CRAFT_ENVIRONMENT=production. |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-2-15 | T (Tampering) | mapping.yaml row mutation per keypress | mitigate | Each `[a]/[d]/[r]` keypress calls `MappingFile::setStatus` which calls `writeAtomic` (tmp + rename). Operator Ctrl+C between bytes-written and rename leaves the previous mapping.yaml intact. Verified by Task 1 acceptance criterion `grep -c 'mappingFile->setStatus'`. |
| T-2-16 | E (Elevation of Privilege) | MapController against production legacy DB | mitigate | First statement of `actionIndex()` is `enforceNeverProduction()` per Phase 1 / D-20. Verified by Task 1 acceptance criterion (gate-first ordering grep). |
| T-2-17 | R (Repudiation) | Drop reasons must persist for audit | mitigate | The `[d]rop` branch calls `setStatus(..., 'dropped', $rationale)` where `$rationale` is operator-supplied (with a default fallback). The rationale field is part of the row schema (D-03 + Plan 02 buildRow); persisted by atomic write. |
| T-2-18 | T (Tampering) | Free-text [t]ype manually handle injection | mitigate | The `typeManuallyHandle` method re-prompts in a `while (true)` loop until the operator types a handle that exists in `Craft::$app->entries->getEntryTypeByHandle($entryType)->getFieldLayout()->getCustomFields()`. Invalid handles are rejected with the available-handles list re-shown — no invalid handle ever lands in mapping.yaml. |
</threat_model>

<verification>
- `php -l` passes on src/console/MapController.php
- `composer test` exits 0 (this controller does not affect Phase 1 PluginBootstrapTest)
- `enforceNeverProduction()` is the first statement of actionIndex()
- All 6 select-options (a/d/r/s/q + b for back) are present in the prompt
- `[r]emap` triggers a two-step picker that filters to FieldLayout-valid handles + offers a `[t]ype manually` fallback
- `--auto-accept-high` runs a non-interactive promotion path that does not call `select()` / `prompt()`
- All four CLI flags (autoAcceptHigh, entities, locales, since) are declared in `options()` (FILT-03)
</verification>

<success_criteria>
1. `kunstmaan-migrator/map` walks every mapping.yaml row with status proposed/needs-review in file order (D-08).
2. Each row renders as a compact one-screen block with [N/total] header, separator, proposal summary, fillRate, sqlType, samples truncated to 60 chars max 3 (D-05).
3. `[a]ccept` mutates row status to `accepted` and atomically writes (D-07).
4. `[d]rop` prompts for free-text rationale (with default), mutates row status to `dropped` with the rationale persisted (D-03), atomically writes.
5. `[r]emap` opens a two-step picker: handler enum, then numbered handle list filtered to entry-type fields, with `[t]ype manually` validated against live FieldLayout (D-06).
6. `[s]kip` does NOT mutate the row.
7. `[q]uit` exits cleanly with all prior decisions persisted.
8. `--auto-accept-high` non-interactively promotes confidence:high + status:proposed → status:accepted (MAP-05).
9. `--entities` / `--locales` / `--since` are declared (FILT-03); `--entities` filters which rows the loop walks; `--locales` is forwarded to LocalePreflight; `--since` is captured (no-op at this surface).
10. NeverProduction gate is the first statement of `actionIndex()` (D-20).
</success_criteria>

<output>
After completion, create `.planning/phases/02-schema-mapping-filters/02-04-SUMMARY.md` documenting:
- File created and LOC
- Confirmation that no Plugin.php changes were made (services consumed are already registered by Plans 01 + 02)
- Confirmation of D-05 / D-06 / D-07 / D-08 mapping to specific code blocks
- Note on `--since` being a no-op at this surface (forwarded to MigrationFilters but not consumed by the row-walk)
- Any deviation from action text (should be none)
</output>
