<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\compile;

use lameco\kunstmaanmigrator\legacy\PartReader;

/**
 * Turns one legacy pagepart row into one Matrix block payload.
 *
 * Field maps are written as `column | transform`; `link(...)` gathers the four legacy link
 * columns that repeat across a dozen parts into one button. Anything a map does not read
 * must appear under `ignore:` — the mapping validator enforces that, so this class can
 * assume every column was accounted for by a human.
 */
final class BlockBuilder
{
    public function __construct(
        private readonly PartReader $parts,
        private readonly Transforms $transforms,
        private readonly string $environment,
        private readonly ?TargetModel $schema = null,
        private ?string $block = null,
    ) {
    }

    /**
     * @param array<string, mixed> $spec the mapping's entry for this part
     * @return array{type:string, fields:array<string,mixed>}|null
     */
    public function build(string $partClass, int $partId, array $spec): ?array
    {
        $table = $spec['table'] ?? null;
        $block = $spec['block'] ?? null;

        if ($table === null || $block === null) {
            return null;
        }

        $row = $this->parts->row((string) $table, $partId);

        if ($row === null) {
            return null;
        }

        $this->block = (string) $block;
        $fields = $this->fieldsFrom($spec['map'] ?? [], $row, $partClass);
        $fields['_sourcePartRef'] = $this->sourceRef((string) $table, $partId);

        foreach ($spec['children'] ?? [] as $field => $child) {
            $rows = $this->parts->children(
                (string) $child['table'],
                (string) $child['fk'],
                $partId,
                (string) ($child['order'] ?? 'weight'),
            );

            $blocks = [];

            foreach ($rows as $childRow) {
                // Refs are emitted only on top-level blocks, which is the level the loader
                // tracks. A child row's stability follows from its parent block's.
                $blocks[] = [
                    'type' => $this->childBlockType($field),
                    'fields' => $this->fieldsFrom($child['map'] ?? [], $childRow, $partClass . '.' . $field),
                ];
            }

            if ($blocks !== []) {
                $fields[$field] = $blocks;
            }
        }

        return ['type' => (string) $block, 'fields' => $fields];
    }

    /**
     * @param array<string, string> $map
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function fieldsFrom(array $map, array $row, string $context): array
    {
        $fields = [];

        foreach ($map as $target => $expression) {
            $value = $this->evaluate((string) $expression, $row, $context);

            if ($value !== null && $value !== '' && $value !== []) {
                $this->assign($fields, (string) $target, $value);
            }
        }

        return $fields;
    }

    /** `column`, `column | transform`, or `link(url, text, newWindow, type)`. */
    private function evaluate(string $expression, array $row, string $context): mixed
    {
        $expression = trim($expression);

        if (preg_match('/^link\((.*)\)$/', $expression, $m) === 1) {
            return $this->link(array_map('trim', explode(',', $m[1])), $row);
        }

        $parts = array_map('trim', explode('|', $expression));
        $column = array_shift($parts);
        $value = $row[$column] ?? null;

        foreach ($parts as $transform) {
            $value = $this->transforms->apply($transform, $value, $context);
        }

        return $value;
    }

    /**
     * The legacy link columns collapse into one button. A link with no URL is not a button,
     * it is an empty row an editor left behind.
     *
     * @param list<string> $columns
     */
    private function link(array $columns, array $row): ?array
    {
        [$urlCol, $textCol] = [$columns[0] ?? null, $columns[1] ?? null];
        $url = $urlCol !== null ? ($row[$urlCol] ?? null) : null;

        if ($url === null || trim((string) $url) === '') {
            return null;
        }

        $button = ['url' => (string) $url];

        if ($textCol !== null && ($row[$textCol] ?? null) !== null) {
            $button['label'] = (string) $row[$textCol];
        }

        if (isset($columns[2]) && (int) ($row[$columns[2]] ?? 0) === 1) {
            $button['target'] = '_blank';
        }

        if (isset($columns[3]) && ($row[$columns[3]] ?? null) !== null) {
            $button['type'] = (string) $row[$columns[3]];
        }

        return [$button];
    }

    /** `a.b` and `a[0].b` address nested Matrix positions. */
    private function assign(array &$fields, string $path, mixed $value): void
    {
        if (!str_contains($path, '.') && !str_contains($path, '[')) {
            $fields[$path] = $value;

            return;
        }

        if (preg_match('/^(\w+)\[(\d+)\]\.(.+)$/', $path, $m) === 1) {
            [$field, $index, $rest] = [$m[1], (int) $m[2], $m[3]];
            $fields[$field] ??= [];
            $fields[$field][$index] ??= ['type' => $this->childBlockType($field), 'fields' => []];
            $this->assign($fields[$field][$index]['fields'], $rest, $value);

            return;
        }

        [$head, $rest] = explode('.', $path, 2);
        $fields[$head] ??= [];
        $this->assign($fields[$head], $rest, $value);
    }

    /**
     * The nested entry type of a Matrix field, read from the target schema.
     *
     * This used to be a convention table — the singular of the field handle — which happened
     * to be right for most fields and silently wrong for the rest. The schema knows.
     */
    private function childBlockType(string $field): string
    {
        if ($this->schema !== null && $this->block !== null) {
            $nested = $this->schema->nestedTypeOf($this->block, $field);

            if ($nested !== null) {
                return $nested;
            }
        }

        return $field;
    }

    /**
     * A stable identity for one Matrix block, carried as `_sourcePartRef`.
     *
     * The loader strips this before saving, then uses it to match the blocks it just wrote
     * back to their source rows so the next run updates them in place. Without it Craft has
     * no way to recognise a block it already created, and a re-run appends instead of
     * replacing. The legacy row's own primary key is the natural identity: it is stable for
     * as long as the source database is.
     */
    private function sourceRef(string $table, int $id): string
    {
        return sprintf('%s:%s:%d', $this->environment, $table, $id);
    }

    public function environment(): string
    {
        return $this->environment;
    }
}
