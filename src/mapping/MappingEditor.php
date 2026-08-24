<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\mapping;

use craft\helpers\App;
use Lameco\KumaCompile\Compile\Transforms;
use Lameco\KumaCompile\Legacy\LegacyCatalogue;
use Lameco\KumaCompile\Mapping\FieldProvenance;
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

    /** One inversion per request: every provenance question reads this snapshot. */
    private ?FieldProvenance $provenance = null;

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
     * What feeds every field of every mapped entry type — one snapshot, so a
     * screen can never disagree with another screen about the same mapping.
     */
    private function provenance(): FieldProvenance
    {
        return $this->provenance ??= FieldProvenance::of(
            $this->document()->mapping(),
            $this->target,
            $this->availableBlocks(),
        );
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

        usort($rows, static fn(MappingRow $a, MappingRow $b): int => $b->live <=> $a->live);

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
        $option = static fn(string $handle): array => ['label' => $handle, 'value' => $handle];

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
            static fn(string $handle): bool => !isset($grouped[$handle]),
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
        return $this->provenance()->pageFields();
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
     * @return array<string, list<array{sidecar: string, expression: string}>>
     */
    public function sidecarFillsFor(string $entryType): array
    {
        return $this->provenance()->sidecarFills($entryType);
    }

    /**
     * For each field a sidecar maps, which of the mapped entry types carry it.
     *
     * @return array<string, array{carried: int, total: int, missing: list<string>}>
     */
    public function sidecarCarriage(MappingRow $row): array
    {
        return $this->provenance()->carriage($row->key);
    }

    /**
     * Where every field of one target gets its content from, across lanes.
     *
     * @return array{kind: string, receives: list<string>, fields: array<string, array{required: bool, feeders: list<array{lane: string, name: string, expression: string}>, partsCount: ?int}>}
     */
    public function coverageFor(string $kind, string $handle): array
    {
        return $this->provenance()->coverage($kind, $handle);
    }

    /**
     * Everything that receives content — page entry types, entity entry
     * types, blocks — for the coverage picker.
     *
     * @return list<array{handle: string, kind: string}>
     */
    public function coverageTargets(): array
    {
        return $this->provenance()->targets();
    }

    /**
     * The distinct entry types the pages lane targets, live-volume first.
     *
     * @return list<string>
     */
    public function mappedEntryTypes(): array
    {
        return $this->provenance()->entryTypes();
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
     * The targets whose fields are not all fed — the roll-up that saves
     * clicking through every target to find the three with holes. An empty
     * return is the finished state.
     *
     * @return list<array{handle: string, kind: string, unfed: int, required: int}>
     */
    public function coverageGaps(): array
    {
        return $this->provenance()->gaps();
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
        // Row-scoped on purpose: a fresh skeleton fails whole-document
        // validation by design — every row still open — and this screen is
        // the advertised way to close them one at a time. Only damage to the
        // edited row itself refuses the save; completeness stays a progress
        // bar, and `validate()` stays the gate a run must pass in full.
        $document = $this->document()->patch($lane, $key, $changes);
        $errors = (new Schema())->validateRow($document->mapping(), $lane, $key);

        if ($errors !== []) {
            // The memo is the instance patch() just mutated; a refused edit
            // must not stay visible to later reads.
            $this->memo = null;
            $this->provenance = null;

            throw new MappingEditorException(sprintf(
                "That edit would make the mapping invalid:\n  · %s",
                implode("\n  · ", array_slice($errors, 0, 5)),
            ));
        }

        $document->save();

        // The write went through: the provenance snapshot predates it.
        $this->provenance = null;
    }
}
