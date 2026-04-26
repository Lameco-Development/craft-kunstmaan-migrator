<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\console;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use lameco\kunstmaanmigrator\NeverProductionTrait;
use lameco\kunstmaanmigrator\Plugin;
use lameco\kunstmaanmigrator\filter\MigrationFilters;
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
    // Phase 02.1 / D-32: declared-only here. Plan 7's two-mode walker may consult
    // this flag for page-part drift signals (out of scope for Plan 01).
    public bool    $sourceStrict   = false;
    public ?string $entities       = null;
    public ?string $locales        = null;
    public ?string $since          = null;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), [
            'autoAcceptHigh', 'sourceStrict',
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
    private function runInteractiveLoop(string $path, MigrationFilters $filters): int
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
                    if ($plugin->mappingFile->setStatus($path, $rowIndex, 'accepted')) {
                        $this->stdout("    → accepted\n\n", Console::FG_GREEN);
                    } else {
                        $this->stdout("    FAIL: could not write mapping.yaml — row not modified\n\n", Console::FG_RED);
                    }
                    break;
                case 'd':
                    $rationale = (string) $this->prompt('  rationale (enter for default):', [
                        'required' => false,
                        'default'  => 'no Craft target — operator-decided drop in map loop',
                    ]);
                    if ($plugin->mappingFile->setStatus($path, $rowIndex, 'dropped', $rationale)) {
                        $this->stdout("    → dropped\n\n", Console::FG_YELLOW);
                    } else {
                        $this->stdout("    FAIL: could not write mapping.yaml — row not modified\n\n", Console::FG_RED);
                    }
                    break;
                case 'r':
                    [$newHandler, $newHandle] = $this->runRemapPicker($row);
                    if ($newHandler !== null && $newHandle !== null) {
                        $written = $plugin->mappingFile->setStatus(
                            $path, $rowIndex, 'accepted',
                            null,
                            $newHandle,
                            $newHandler,
                        );
                        if ($written) {
                            $this->stdout("    → remapped to {$newHandler}.{$newHandle} (accepted)\n\n", Console::FG_GREEN);
                        } else {
                            $this->stdout("    FAIL: could not write mapping.yaml — row not modified\n\n", Console::FG_RED);
                        }
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
    private function applyEntitiesFilter(array $rows, MigrationFilters $filters): array
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
    private function matchesEntitiesFilter(array $row, MigrationFilters $filters): bool
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
