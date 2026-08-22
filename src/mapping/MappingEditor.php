<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\mapping;

use craft\helpers\App;
use Lameco\KumaCompile\Mapping\MappingDocument;
use Lameco\KumaCompile\Mapping\Schema;
use lameco\kunstmaanmigrator\compile\TargetModel;
use lameco\kunstmaanmigrator\models\Settings;
use lameco\kunstmaanmigrator\payload\CraftSchemaGateway;
use lameco\kunstmaanmigrator\payload\SchemaGateway;

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
        $path = $this->path();

        if ($path === null) {
            throw new MappingEditorException('No mapping file is configured. Set one in the plugin settings.');
        }

        return MappingDocument::fromFile($path);
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
     * The targets a lane may choose from, read from this install.
     *
     * A `parts` row becomes a page-builder block; a `pages` or `entities` row
     * becomes an entry type. Same question either way — what may I write here —
     * so the screen asks the editor rather than knowing per lane.
     *
     * @return list<string>
     */
    public function targetsFor(string $lane): array
    {
        return match ($lane) {
            'parts' => $this->availableBlocks(),
            default => $this->catalogue->entryTypes(),
        };
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
        $document = $this->document()->patch($lane, $key, $changes);
        $errors = (new Schema())->validate($document->mapping());

        if ($errors !== []) {
            throw new MappingEditorException(sprintf(
                "That edit would make the mapping invalid:\n  · %s",
                implode("\n  · ", array_slice($errors, 0, 5)),
            ));
        }

        $document->save();
    }
}
