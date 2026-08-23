<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\mapping;

use craft\helpers\App;
use Lameco\KumaCompile\Compile\Transforms;
use Lameco\KumaCompile\Legacy\LegacyCatalogue;
use Lameco\KumaCompile\Mapping\MappingDocument;
use Lameco\KumaCompile\Mapping\Schema;
use lameco\kunstmaanmigrator\compile\TargetModel;
use lameco\kunstmaanmigrator\models\Settings;
use lameco\kunstmaanmigrator\payload\CraftSchemaGateway;
use lameco\kunstmaanmigrator\payload\SchemaGateway;
use lameco\kunstmaanmigrator\run\EnvironmentPipeline;
use Throwable;

/**
 * The mapping, as the control panel sees it.
 *
 * A mapping is a long file of decisions, and the two things that make deciding
 * hard are both things a file cannot do: tell you where the content actually
 * is, and tell you what you are allowed to say. This does both — rows come back
 * ordered by live volume with their state, and the targets on offer are read
 * from the Craft install rather than typed from memory.
 *
 * It edits the file, not a copy of it. The mapping is reviewed in a pull
 * request, so an edit that did not show up in the diff would not be reviewed.
 */
final class MappingEditor
{
    /** One parse per request: every screen asks document() several times over. */
    private ?MappingDocument $memo = null;

    public const LANES = ['parts', 'pages', 'entities', 'sidecars'];

    public function __construct(
        private readonly Settings $settings,
        private readonly SchemaGateway $schema,
        private readonly TargetModel $target,
        private readonly TargetCatalogue $catalogue,
    ) {
    }

    public static function create(Settings $settings): self
    {
        $schema = new CraftSchemaGateway();

        return new self($settings, $schema, new TargetModel($schema), new CraftTargetCatalogue());
    }

    public function path(): ?string
    {
        $path = App::parseEnv($this->settings->mappingPath);

        return is_string($path) && $path !== '' && is_file($path) ? $path : null;
    }

    public function document(): MappingDocument
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        $path = $this->path();

        if ($path === null) {
            throw new MappingEditorException('No mapping file is configured. Set one in the plugin settings.');
        }

        return $this->memo = MappingDocument::fromFile($path);
    }

    /**
     * Every row of a lane, with the state an operator is deciding about.
     *
     * Ordered by live volume, because that is where the content is and a list
     * ordered alphabetically buries the ten rows that matter under sixty that
     * do not.
     *
     * @return list<MappingRow>
     */
    public function rows(string $lane): array
    {
        $rows = [];

        foreach ($this->document()->lane($lane) as $key => $spec) {
            $rows[] = MappingRow::fromSpec((string) $key, is_array($spec) ? $spec : []);
        }

        usort($rows, static fn (MappingRow $a, MappingRow $b): int => $b->live <=> $a->live);

        return $rows;
    }

    /**
     * How far through a lane the decisions are.
     *
     * The mapping editor is where the actual work happens and it was the least
     * guided screen in the plugin: sixty rows, no sense of how many were done
     * or how many were left. A count is the difference between "a long list"
     * and "eleven to go".
     *
     * @return array{decided: int, dropped: int, open: int, total: int, percent: int}
     */
    public function progress(string $lane): array
    {
        $counts = [MappingRow::DECIDED => 0, MappingRow::DROPPED => 0, MappingRow::OPEN => 0];

        foreach ($this->rows($lane) as $row) {
            $counts[$row->status()]++;
        }

        $total = array_sum($counts);
        $settled = $counts[MappingRow::DECIDED] + $counts[MappingRow::DROPPED];

        return [
            'decided' => $counts[MappingRow::DECIDED],
            'dropped' => $counts[MappingRow::DROPPED],
            'open' => $counts[MappingRow::OPEN],
            'total' => $total,
            'percent' => $total === 0 ? 100 : (int) round($settled / $total * 100),
        ];
    }

    public function row(string $lane, string $key): ?MappingRow
    {
        $spec = $this->document()->row($lane, $key);

        return $spec === null ? null : MappingRow::fromSpec($key, $spec);
    }

    /**
     * The block handles this install actually offers for page-builder content.
     *
     * Read from the live schema rather than listed in the mapping, which is the
     * one thing the control panel can do that the file cannot: a handle typed
     * into YAML is checked when a migration runs, hours later; a handle chosen
     * from this list cannot be wrong.
     *
     * @return list<string>
     */
    public function availableBlocks(): array
    {
        $document = $this->document();
        $contexts = (array) ($document->all()['defaults']['contexts'] ?? []);
        $entryTypes = [];

        foreach ($document->lane('pages') as $page) {
            $entryType = is_array($page) ? ($page['entryType'] ?? null) : null;

            if (is_string($entryType) && $entryType !== '') {
                $entryTypes[$entryType] = true;
            }
        }

        $blocks = [];

        foreach (array_keys($entryTypes) as $entryType) {
            foreach ($contexts as $context) {
                $field = is_array($context) ? ($context['field'] ?? null) : null;

                if (!is_string($field) || $field === '') {
                    continue;
                }

                foreach ($this->schema->blockTypesFor($entryType, $field) as $block) {
                    $blocks[$block] = true;
                }
            }
        }

        $handles = array_keys($blocks);
        sort($handles);

        return $handles;
    }

    /**
     * The target dropdown's options, grouped where grouping means something.
     *
     * A `parts` row becomes a page-builder block; a `pages` or `entities` row
     * becomes an entry type. Entry types come grouped by the section that uses them — a flat list of
     * every handle in the install is a list you search, not read — with the
     * types no section uses (Matrix block types) together at the end, because
     * on the pages and entities lanes they are almost never the answer.
     *
     * @return list<array{label: string, value: string}|array{optgroup: string}>
     */
    public function targetOptions(string $lane): array
    {
        $option = static fn (string $handle): array => ['label' => $handle, 'value' => $handle];

        if ($lane === 'parts') {
            return array_map($option, $this->availableBlocks());
        }

        $options = [];
        $grouped = [];

        foreach ($this->catalogue->entryTypesBySection() as $section => $handles) {
            $options[] = ['optgroup' => $section];

            foreach ($handles as $handle) {
                $options[] = $option($handle);
                $grouped[$handle] = true;
            }
        }

        $rest = array_filter(
            $this->catalogue->entryTypes(),
            static fn (string $handle): bool => !isset($grouped[$handle]),
        );

        if ($rest !== []) {
            if ($options !== []) {
                $options[] = ['optgroup' => \Yii::t('kunstmaan-migrator', 'Not in a section')];
            }

            $options = [...$options, ...array_map($option, array_values($rest))];
        }

        return $options;
    }

    /**
     * The union of fields across the page entry types the mapping targets.
     *
     * A sidecar decorates every page carrying its ref, and the hero fields are
     * placed on some entry types and not others — the compiler drops and counts
     * a field the type lacks, so offering the union here is honest.
     *
     * @return list<string>
     */
    public function pageFields(): array
    {
        $fields = [];

        foreach ($this->mappedEntryTypes() as $entryType) {
            foreach (array_keys($this->target->slots($entryType)) as $handle) {
                $fields[(string) $handle] = true;
            }
        }

        $handles = array_keys($fields);
        sort($handles);

        return $handles;
    }

    /**
     * The fields a chosen block or entry type offers, so a column can be
     * pointed at one rather than spelled.
     *
     * @return list<string>
     */
    public function fieldsFor(string $block): array
    {
        return array_keys($this->target->slots($block));
    }

    /**
     * Which of an entry type's fields the sidecars already fill.
     *
     * A page row that shows `heroTitle — not filled —` while the headerTab
     * sidecar fills it on every decorated page is lying to the operator. The
     * page screen shows these as covered, and by whom; the page's own map
     * still wins a collision, so mapping a column on top is an override, not
     * a conflict.
     *
     * @return array<string, list<array{sidecar: string, expression: string}>>
     */
    public function sidecarFillsFor(string $entryType): array
    {
        $fields = array_flip($this->fieldsFor($entryType));
        $fills = [];

        foreach ($this->document()->lane('sidecars') as $name => $spec) {
            if (!is_array($spec) || isset($spec['drop']) || isset($spec['manual'])) {
                continue;
            }

            foreach ((array) ($spec['map'] ?? []) as $field => $expression) {
                if (isset($fields[(string) $field])) {
                    $fills[(string) $field][] = [
                        'sidecar' => (string) $name,
                        'expression' => (string) $expression,
                    ];
                }
            }
        }

        return $fills;
    }

    /**
     * For each field a sidecar maps, which of the mapped entry types carry it.
     *
     * The compiler drops a field the page's type lacks and counts it — hours
     * after the decision was made. The count belongs on the editing screen,
     * where "heroImage misses vacancyPage" is still cheap to act on.
     *
     * @return array<string, array{carried: int, total: int, missing: list<string>}>
     */
    public function sidecarCarriage(MappingRow $row): array
    {
        $entryTypes = $this->mappedEntryTypes();
        $fieldSets = [];

        foreach ($entryTypes as $entryType) {
            $fieldSets[$entryType] = array_flip($this->fieldsFor($entryType));
        }

        $carriage = [];

        foreach (array_keys($row->map) as $field) {
            $missing = array_values(array_filter(
                $entryTypes,
                static fn (string $entryType): bool => !isset($fieldSets[$entryType][(string) $field]),
            ));

            $carriage[(string) $field] = [
                'carried' => count($entryTypes) - count($missing),
                'total' => count($entryTypes),
                'missing' => $missing,
            ];
        }

        return $carriage;
    }

    /**
     * Where every field of an entry type gets its content from, across lanes.
     *
     * The mapping answers "what does this legacy thing become", one row at a
     * time; the question an operator actually verifies with is the inverse —
     * "is every field of contentPage fed, and by what". Pages fill from their
     * own table, sidecars decorate, the parts lane feeds the page-builder
     * context fields; a field none of them touch is the hole this view exists
     * to show.
     *
     * @return array{pageTypes: list<string>, fields: array<string, array{required: bool, pages: list<array{page: string, expression: string}>, sidecars: list<array{sidecar: string, expression: string}>, parts: ?int}>}
     */
    public function coverageFor(string $entryType): array
    {
        $document = $this->document();
        $pageTypes = [];
        $pageFills = [];

        foreach ($document->lane('pages') as $name => $spec) {
            if (!is_array($spec) || ($spec['entryType'] ?? null) !== $entryType) {
                continue;
            }

            $pageTypes[] = (string) $name;

            foreach ((array) ($spec['map'] ?? []) as $field => $expression) {
                $pageFills[(string) $field][] = ['page' => (string) $name, 'expression' => (string) $expression];
            }
        }

        $contextFields = [];

        foreach ((array) ($document->all()['defaults']['contexts'] ?? []) as $context) {
            $field = is_array($context) ? ($context['field'] ?? null) : null;

            if (is_string($field) && $field !== '') {
                $contextFields[$field] = true;
            }
        }

        $partsBlocks = 0;

        foreach ($document->lane('parts') as $spec) {
            if (is_array($spec) && isset($spec['block'])) {
                $partsBlocks++;
            }
        }

        $sidecarFills = $this->sidecarFillsFor($entryType);
        $required = array_flip($this->target->requiredFields($entryType));
        $fields = [];

        foreach ($this->fieldsFor($entryType) as $field) {
            $fields[$field] = [
                'required' => isset($required[$field]),
                'pages' => $pageFills[$field] ?? [],
                'sidecars' => $sidecarFills[$field] ?? [],
                'parts' => isset($contextFields[$field]) ? $partsBlocks : null,
            ];
        }

        return ['pageTypes' => $pageTypes, 'fields' => $fields];
    }

    /**
     * The distinct entry types the pages lane targets.
     *
     * @return list<string>
     */
    public function mappedEntryTypes(): array
    {
        $entryTypes = [];

        // Through rows() for its live-volume order: everything that lists
        // entry types (the coverage picker, the gaps roll-up) should lead
        // with where the content is, the same way the lanes do.
        foreach ($this->rows('pages') as $row) {
            if ($row->target !== null && $row->target !== '') {
                $entryTypes[$row->target] = true;
            }
        }

        return array_keys($entryTypes);
    }

    /**
     * The legacy columns a row's table actually has.
     *
     * Read from the first environment the mapping declares: a part's table has
     * the same shape in each, and connecting to all three to list columns would
     * make opening a row a three-connection affair.
     *
     * @return list<string>
     */
    public function columnsFor(MappingRow $row): array
    {
        if ($row->table === null || ($database = $this->firstDatabase()) === null) {
            return [];
        }

        try {
            return (new LegacyCatalogue(EnvironmentPipeline::dsnFromSettings()))->columns($database, $row->table);
        } catch (Throwable) {
            // A legacy database that is not reachable right now is a reason to
            // fall back to a text box, not to fail opening the row.
            return [];
        }
    }

    /**
     * The first environment's database — where columnsFor and samplesFor read,
     * because a table has the same shape in every environment and connecting
     * to all three to answer a metadata question would triple the cost.
     */
    private function firstDatabase(): ?string
    {
        $environments = $this->document()->lane('environments');
        $first = reset($environments);
        $database = (string) ((is_array($first) ? $first : [])['database'] ?? '');

        return $database !== '' ? $database : null;
    }

    /**
     * Up to three real values per column of a row's table.
     *
     * `title` next to `page_title` is a coin flip until you see what each one
     * holds; three samples turn the guess into a choice. Read from the first
     * environment like `columnsFor`, and empty when the database is not
     * reachable — a hint, never a requirement.
     *
     * @return array<string, list<string>>
     */
    public function samplesFor(MappingRow $row): array
    {
        if ($row->table === null || ($database = $this->firstDatabase()) === null) {
            return [];
        }

        try {
            $rows = (new LegacyCatalogue(EnvironmentPipeline::dsnFromSettings()))->sampleRows($database, $row->table);
        } catch (Throwable) {
            // sampleRows() swallows query failures itself; this guards the DSN
            // construction, which throws on unconfigured settings.
            return [];
        }

        return self::aggregateSamples($rows);
    }

    /**
     * Distinct, displayable values per column: markup stripped, whitespace
     * collapsed, cut at 40 characters — a glimpse of the content, not the
     * content.
     *
     * @param list<array<string, mixed>> $rows
     * @return array<string, list<string>>
     */
    public static function aggregateSamples(array $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            foreach ($row as $column => $value) {
                if (!is_scalar($value)) {
                    continue;
                }

                $value = trim((string) preg_replace('/\s+/', ' ', strip_tags((string) $value)));

                if ($value === '') {
                    continue;
                }

                if (mb_strlen($value) > 40) {
                    $value = mb_substr($value, 0, 40) . '…';
                }

                $column = (string) $column;
                $seen = $out[$column] ?? [];

                if (count($seen) < 3 && !in_array($value, $seen, true)) {
                    $out[$column][] = $value;
                }
            }
        }

        unset($out['id']);

        return $out;
    }

    /**
     * The entry types whose fields are not all fed, with how many and how many
     * of those the layout requires.
     *
     * The roll-up that saves clicking through every entry type to find the
     * three with holes. An empty return is the finished state.
     *
     * @return list<array{entryType: string, unfed: int, required: int}>
     */
    public function coverageGaps(): array
    {
        $gaps = [];

        foreach ($this->mappedEntryTypes() as $entryType) {
            $unfed = 0;
            $required = 0;

            foreach ($this->coverageFor($entryType)['fields'] as $state) {
                if ($state['pages'] !== [] || $state['sidecars'] !== [] || $state['parts'] !== null) {
                    continue;
                }

                $unfed++;

                if ($state['required']) {
                    $required++;
                }
            }

            if ($unfed > 0) {
                $gaps[] = ['entryType' => $entryType, 'unfed' => $unfed, 'required' => $required];
            }
        }

        return $gaps;
    }

    /**
     * The legacy connection the plugin settings describe — for the fast
     * metadata reads the editor's screens make (columns, prefill drafting).
     * A migration run never comes through here; it belongs to the queue.
     */
    public function legacyDsn(): \Lameco\KumaCompile\Legacy\Dsn
    {
        return EnvironmentPipeline::dsnFromSettings();
    }

    /** @return array<string, string> transform => what it does */
    public function transforms(): array
    {
        return Transforms::available();
    }

    /**
     * Apply an edit and write it, refusing anything that would leave the file
     * malformed.
     *
     * Validated before the write rather than after: the file is the migration,
     * and a control panel that can save a broken one has moved the failure from
     * a form to a two-hour run.
     *
     * @param array<string, mixed> $changes
     * @throws MappingEditorException
     */
    public function patch(string $lane, string $key, array $changes): void
    {
        // Only errors this edit introduces block the save. A fresh skeleton
        // fails whole-document validation by design — every row still open —
        // and this screen is the advertised way to close them one at a time.
        // Holding one row's save hostage to sixty untouched rows made the
        // editor unusable on exactly the file it exists for.
        $schema = new Schema();
        $before = $schema->validate($this->document()->mapping());

        // The diff keys on exact message strings: an edit that merely rewords
        // an existing error would read as introduced. Acceptable until Schema
        // can validate a single row — the honest depth this stands in for.
        $document = $this->document()->patch($lane, $key, $changes);
        $errors = array_values(array_diff($schema->validate($document->mapping()), $before));

        if ($errors !== []) {
            // The memo is the instance patch() just mutated; a refused edit
            // must not stay visible to later reads.
            $this->memo = null;

            throw new MappingEditorException(sprintf(
                "That edit would make the mapping invalid:\n  · %s",
                implode("\n  · ", array_slice($errors, 0, 5)),
            ));
        }

        $document->save();
    }
}
