<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\console;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use lameco\kunstmaanmigrator\filter\FilterFactory;
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
        $filters = $plugin->filterFactory->fromCli(
            entitiesArg: $this->entities,
            localesArg: $this->locales,
            sinceArg: $this->since,
            relationGraph: $this->loadRuntimeRelationGraph(),
        );

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
            if (!self::matchesEntitiesFilter($row, $filters)) { continue; }
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

            // Phase 02.1 / D-34: branch on row kind FIRST, then keypress. Page-part rows
            // render via their own compact block + block-type picker; column rows preserve
            // the existing Phase 2 / D-05 + D-06 flow verbatim.
            $kind = (string) ($row['kind'] ?? 'column');
            if ($kind === 'pagePart') {
                $exit = $this->dispatchPagePartRow($path, $rowIndex, $row, $position, count($walkOrder));
            } else {
                $exit = $this->dispatchColumnRow($path, $rowIndex, $row, $position, count($walkOrder));
            }
            if ($exit !== null) {
                return $exit;
            }
        }

        $this->stdout("\nMap: PASS — walked {$total} rows.\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    /**
     * Dispatch one column-kind row. Existing Phase 2 / D-05 + D-06 flow preserved verbatim.
     * Returns ExitCode::OK if operator quit; null to continue the walk.
     *
     * @param array<string, mixed> $row
     */
    private function dispatchColumnRow(string $path, int $rowIndex, array $row, int $position, int $total): ?int
    {
        $plugin = Plugin::getInstance();

        $this->stdout($this->renderRowBlock($row, $position, $total));

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
        return null;
    }

    /**
     * Dispatch one pagePart-kind row (Phase 02.1 / D-34). Mirrors the column-row dispatch
     * in shape but uses the page-part renderer + block-type picker. Same a/d/s/q semantics
     * (accept, drop, skip, quit); [r]emap leads into runRemapPickerForPagePart instead of
     * the handler+handle picker. D-07 atomic per-keypress writes preserved.
     *
     * Returns ExitCode::OK if operator quit; null to continue the walk.
     *
     * @param array<string, mixed> $row
     */
    private function dispatchPagePartRow(string $path, int $rowIndex, array $row, int $position, int $total): ?int
    {
        $plugin = Plugin::getInstance();

        $this->stdout($this->renderPagePartRowBlock($row, $position, $total));

        $action = strtolower((string) $this->select(
            '  action',
            ['a' => 'accept', 'd' => 'drop', 'r' => 'remap (block-type)', 's' => 'skip', 'q' => 'quit'],
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
                    'default'  => 'no Craft block-type target — operator-decided drop in map loop',
                ]);
                if ($plugin->mappingFile->setStatus($path, $rowIndex, 'dropped', $rationale)) {
                    $this->stdout("    → dropped\n\n", Console::FG_YELLOW);
                } else {
                    $this->stdout("    FAIL: could not write mapping.yaml — row not modified\n\n", Console::FG_RED);
                }
                break;
            case 'r':
                $updated = $this->runRemapPickerForPagePart($row);
                $newBlockType = (string) ($updated['targetBlockType'] ?? '');
                $rowBlockType = (string) ($row['targetBlockType'] ?? '');
                if ($newBlockType !== '' && $newBlockType !== $rowBlockType) {
                    // setStatus only mutates a fixed key set; persist the new targetBlockType
                    // by re-reading + writing the full file via writeAtomic (same atomic
                    // primitive setStatus uses, per Phase 2 / D-07).
                    $written = $this->writePagePartBlockType($path, $rowIndex, $newBlockType);
                    if ($written) {
                        $this->stdout("    → block-type set to {$newBlockType} (accepted)\n\n", Console::FG_GREEN);
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
        return null;
    }

    /**
     * Persist a new targetBlockType + flip status to accepted on a pagePart row.
     * MappingFile::setStatus only mutates a fixed key set (status/rationale/targetHandle/handler),
     * so page-part-specific fields need a direct YAML rewrite via the same atomic primitive
     * (writeAtomic — Phase 2 / D-07 tmp+rename).
     */
    private function writePagePartBlockType(string $path, int $rowIndex, string $targetBlockType): bool
    {
        $plugin = Plugin::getInstance();
        $parsed = \Symfony\Component\Yaml\Yaml::parseFile($path) ?? [];
        if (!is_array($parsed) || !isset($parsed['proposals'][$rowIndex]) || !is_array($parsed['proposals'][$rowIndex])) {
            return false;
        }
        $parsed['proposals'][$rowIndex]['targetBlockType'] = $targetBlockType;
        $parsed['proposals'][$rowIndex]['status']          = 'accepted';
        $yaml = \Symfony\Component\Yaml\Yaml::dump($parsed, 4, 2, \Symfony\Component\Yaml\Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
        return $plugin->mappingFile->writeAtomic($path, $yaml);
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
     * Phase 02.1 / D-34 compact one-screen renderer for kind=pagePart rows.
     * Mirrors renderRowBlock's D-05 60-char-separator style but tailored to page-part fields:
     * shows pagePartClass + parent context, the proposed Matrix block target, the rationale,
     * and each fields[] mapping inline.
     *
     * @param array<string, mixed> $row
     */
    private function renderPagePartRowBlock(array $row, int $position, int $total): string
    {
        $sep = str_repeat('─', 60) . "\n";
        $pagePartClass    = (string) ($row['pagePartClass'] ?? '');
        $parentPageClass  = (string) ($row['parentPageClass'] ?? '');
        $context          = (string) ($row['context'] ?? '');
        $sourceTable      = (string) ($row['sourceTable'] ?? '');
        $entryType        = (string) ($row['targetEntryType'] ?? '');
        $matrixField      = (string) ($row['targetMatrixField'] ?? '');
        $blockType        = (string) ($row['targetBlockType'] ?? '');
        $confidence       = (string) ($row['confidence'] ?? '');
        $rationale        = (string) ($row['rationale'] ?? '');
        $fields           = (array)  ($row['fields'] ?? []);

        $out  = "[{$position}/{$total}] {$pagePartClass} in {$parentPageClass} context '{$context}'\n";
        $out .= $sep;
        $entryDisplay = $entryType !== '' ? $entryType : '(unset)';
        $matrixDisplay = $matrixField !== '' ? $matrixField : '(unset)';
        $blockDisplay = $blockType !== '' ? $blockType : '(unset)';
        $out .= "Proposed: → {$entryDisplay}.{$matrixDisplay} (block: {$blockDisplay})  (confidence: {$confidence})\n";
        $out .= "Source table: {$sourceTable}\n";
        $out .= "Rationale: {$rationale}\n";
        if ($fields !== []) {
            $out .= "Fields:\n";
            foreach ($fields as $f) {
                if (!is_array($f)) { continue; }
                $sourceProperty = (string) ($f['sourceProperty'] ?? '');
                $targetHandle   = (string) ($f['targetHandle'] ?? '');
                $handler        = (string) ($f['handler'] ?? '');
                $out .= "  {$targetHandle} ← {$sourceProperty} ({$handler})\n";
            }
        }
        $out .= $sep;
        return $out;
    }

    /**
     * Phase 02.1 / D-34 block-type picker for pagePart rows.
     *
     * Numbered list of available Matrix block-types on the parent entry type's
     * targetMatrixField, plus [t]ype manually + [b]ack. Returns the row with
     * targetBlockType updated, or the row unchanged on cancel.
     *
     * Per-field handle pickers (the nested `fields[]` array) are out of scope for v1.0 —
     * operator can edit those manually in mapping.yaml. Phase 3 may revisit.
     * TODO(phase-3): per-field handle pickers for page-part fields[].
     *
     * Re-prompts in `while(true)` on invalid input until valid or back (T-2-18 mitigation).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function runRemapPickerForPagePart(array $row): array
    {
        $entryType   = (string) ($row['targetEntryType'] ?? '');
        $matrixField = (string) ($row['targetMatrixField'] ?? '');

        if ($entryType === '' || $matrixField === '') {
            $this->stdout(
                "  pagePart row missing targetEntryType or targetMatrixField — fill them in mapping.yaml first.\n",
                Console::FG_YELLOW,
            );
            return $row;
        }

        $blockTypes = $this->blockTypeHandlesForMatrixField($entryType, $matrixField);

        // Build picker — numbered block-type entries + [t]ype manually + [b]ack.
        $choices = [];
        foreach ($blockTypes as $bt) {
            $choices[$bt] = $bt;
        }
        $choices['t'] = '[type manually]';
        $choices['b'] = '[back]';

        if ($blockTypes === []) {
            // No block-types discoverable — fall straight through to type-manually.
            return $this->typeManuallyBlockType($row, $blockTypes);
        }

        $picked = (string) $this->select(
            "  target block-type for {$entryType}.{$matrixField}?",
            $choices,
        );
        if ($picked === 'b') {
            return $row;
        }
        if ($picked === 't') {
            return $this->typeManuallyBlockType($row, $blockTypes);
        }
        $row['targetBlockType'] = $picked;
        return $row;
    }

    /**
     * [t]ype manually fallback for the page-part block-type picker. Validates the operator's
     * input against the live FieldLayout-discovered block-type list (T-2-18 mitigation:
     * invalid handles never land in mapping.yaml). Empty input or "back" cancels.
     *
     * @param array<string, mixed> $row
     * @param list<string>         $valid
     * @return array<string, mixed>
     */
    private function typeManuallyBlockType(array $row, array $valid): array
    {
        while (true) {
            $h = (string) $this->prompt('  type a block-type handle (or "back"):', ['required' => false]);
            if ($h === '' || strtolower($h) === 'back') {
                return $row;
            }
            // If we couldn't discover any block-types (empty $valid), accept any non-empty
            // operator input — they're authoring against a Matrix field that may not yet
            // have its block-types provisioned in Craft.
            if ($valid === [] || in_array($h, $valid, true)) {
                $row['targetBlockType'] = $h;
                return $row;
            }
            $this->stdout("  invalid block-type. Available: " . implode(', ', $valid) . "\n", Console::FG_YELLOW);
            // loop and re-prompt
        }
    }

    /**
     * Live FieldLayout walk: enumerate the block-type handles on a Matrix field belonging
     * to a given entry type. Returns [] if the entry type, layout, or field is missing —
     * the picker degrades to the type-manually fallback in that case.
     *
     * @return list<string>
     */
    private function blockTypeHandlesForMatrixField(string $entryType, string $matrixField): array
    {
        if ($entryType === '' || $matrixField === '') {
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
        foreach ($layout->getCustomFields() as $f) {
            if ((string) $f->handle !== $matrixField) {
                continue;
            }
            // Matrix field — enumerate its entry types (Craft 5 Matrix block-types are
            // entry types under the hood). Defensive: not every field has getEntryTypes().
            if (!method_exists($f, 'getEntryTypes')) {
                return [];
            }
            $out = [];
            foreach ($f->getEntryTypes() as $et) {
                $h = (string) ($et->handle ?? '');
                if ($h !== '') {
                    $out[] = $h;
                }
            }
            return $out;
        }
        return [];
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
            if (self::matchesEntitiesFilter($r, $filters)) {
                $out[] = $r;
            }
        }
        return $out;
    }

    /**
     * Decide whether a mapping row matches the operator's `--entities=` filter.
     *
     * Column rows match by table prefix (`kuma_<snake_case_entity>` against the row's
     * `table` field — Phase 2 / D-08 behavior preserved). PagePart rows (Phase 02.1 /
     * D-34) match by parent-page-class basename: an `--entities=NewsPage` invocation
     * walks every page-part row whose `parentPageClass` ends in `\NewsPage` (or equals
     * `NewsPage` if unnamespaced). Without this branch every pagePart row was silently
     * dropped because `$row['table']` is empty for pagePart rows (WR-01).
     *
     * Public + static so the test suite can exercise it without instantiating
     * MapController (which extends craft\console\Controller and needs Yii bootstrap).
     *
     * @param array<string, mixed> $row
     */
    public static function matchesEntitiesFilter(array $row, MigrationFilters $filters): bool
    {
        if ($filters->entities === []) {
            return true;
        }

        $kind = (string) ($row['kind'] ?? 'column');
        if ($kind === 'pagePart') {
            $parent = (string) ($row['parentPageClass'] ?? '');
            if ($parent === '') {
                return false;
            }
            return $filters->allows($parent);
        }

        $table = (string) ($row['table'] ?? '');
        foreach ($filters->entities as $e) {
            // Pattern is a literal — preg_replace can only return null if
            // PCRE itself is broken. Assert documents the invariant; the
            // `?? $e` keeps the strtolower call type-safe regardless.
            $replaced = preg_replace('/(?<!^)[A-Z]/', '_$0', $e);
            assert(is_string($replaced), 'preg_replace returned null for literal snake-case pattern');
            $snake = strtolower($replaced ?? $e);
            if (str_starts_with($table, 'kuma_' . $snake)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array<string, list<string>>
     */
    private function loadRuntimeRelationGraph(): array
    {
        $path = Craft::$app->path->getStoragePath() . '/migration/relation-graph.json';
        if (!is_file($path)) {
            return [];
        }

        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded)
            ? FilterFactory::relationGraphFromArtifact($decoded)
            : [];
    }
}
