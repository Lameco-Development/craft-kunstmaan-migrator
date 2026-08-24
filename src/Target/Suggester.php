<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\Target;

use Lameco\Kunstmaanmigrator\Mapping\Mapping;
use Lameco\Kunstmaanmigrator\Source\LegacyDatabase;

/**
 * Drafts field maps from the content model's own migration notes.
 *
 * The spec markdown already says which legacy parts each block covers
 * (`migrationSource:`) and which property becomes which field (the migration-notes
 * table). This pairs that against the real database columns and the real Craft
 * fields, so a fresh mapping opens as a reviewed-nothing draft instead of sixty
 * empty rows. A draft is not a decision: prefilled rows keep their leftover
 * columns as `unreviewed`, so every row stays open until somebody looks at it.
 */
final class Suggester
{
    /** Legacy relation columns carry an `_id` suffix the specs omit. */
    private const RELATION_SUFFIX = '_id';

    public function __construct(
        private readonly SpecNotes $notes,
        private readonly TargetSchema $schema,
    ) {
    }

    /**
     * Patches that turn every undecided part into a spec-drafted row.
     *
     * @return array{drafted: array<string, array<string, mixed>>, skipped: array<string, string>}
     */
    public function prefill(Mapping $mapping, LegacyDatabase $db): array
    {
        $drafted = [];
        $skipped = [];

        foreach ($mapping->partRows() as $part => $row) {
            if ($row->isDecided()) {
                continue;
            }

            $blocks = array_values(array_filter(
                $this->notes->blocksForPart((string) $part),
                fn(string $block): bool => $this->schema->hasEntryType($block),
            ));

            if ($blocks === []) {
                $skipped[(string) $part] = 'no spec names it';

                continue;
            }

            if (count($blocks) > 1) {
                $skipped[(string) $part] = sprintf('named by several specs (%s) — decide by hand', implode(', ', $blocks));

                continue;
            }

            $patch = $this->draft((string) $part, $row->spec, $blocks[0], $db);

            if ($patch === null) {
                $skipped[(string) $part] = sprintf('%s.md has no resolvable field rows for its columns', $blocks[0]);

                continue;
            }

            $drafted[(string) $part] = $patch;
        }

        return ['drafted' => $drafted, 'skipped' => $skipped];
    }

    /**
     * One part's draft: the block, the field maps the spec resolves, the columns
     * the spec deliberately drops (as reasoned ignores — that decision *was*
     * made, in the spec), and everything else left `unreviewed`.
     *
     * @param array<string, mixed> $spec
     * @return array<string, mixed>|null patch for MappingDocument, or null when nothing resolves
     */
    public function draft(string $part, array $spec, string $block, LegacyDatabase $db): ?array
    {
        $table = (string) ($spec['table'] ?? '');

        if ($table === '' || !$db->hasTable($table)) {
            return null;
        }

        $columns = $db->columns($table);
        $partMap = [];
        $itemMap = [];
        $dropped = [];

        [$childField, $childSpec] = $this->childOf($spec);
        $childColumns = $childSpec !== null && $db->hasTable((string) $childSpec['table'])
            ? $db->columns((string) $childSpec['table'])
            : [];

        foreach ($this->notes->forBlock($block) as $note) {
            if ($note->kind === SpecNotes::DROPPED) {
                $dropped = [...$dropped, ...$note->sources];

                continue;
            }

            if (!$note->isMapped()) {
                continue;
            }

            if ($note->scope === 'part') {
                $this->resolveInto($note, $columns, $block, null, $partMap);

                continue;
            }

            if ($childField !== null) {
                $nested = $this->schema->nestedTypeOf($block, $childField);
                $this->resolveInto($note, $childColumns, $block, $nested, $itemMap);
            }
        }

        if ($partMap === [] && $itemMap === []) {
            return null;
        }

        $droppedColumns = $this->asColumns($dropped, $columns);
        $consumed = array_map(static fn(string $e): string => trim((string) explode('|', $e)[0]), $partMap);
        $leftover = array_values(array_diff($columns, ['id'], $consumed, $droppedColumns));

        $patch = [
            'block' => $block,
            'note' => sprintf('drafted from %s.md — review every line, then clear unreviewed', $block),
            'unreviewed' => $leftover !== [] ? $leftover : null,
        ];

        if ($partMap !== []) {
            $patch['map'] = $partMap;
        }

        if ($droppedColumns !== []) {
            $patch['ignore'] = array_fill_keys($droppedColumns, sprintf('the spec drops it (%s.md)', $block));
        }

        if ($itemMap !== [] && $childField !== null && is_array($childSpec)) {
            $childConsumed = array_map(static fn(string $e): string => trim((string) explode('|', $e)[0]), $itemMap);
            $childLeftover = array_values(array_diff($childColumns, ['id', (string) ($childSpec['fk'] ?? '')], $childConsumed));

            $children = $spec['children'];
            $children[$childField]['map'] = $itemMap;
            unset($children[$childField]['unreviewed']);

            if ($childLeftover !== []) {
                $children[$childField]['unreviewed'] = $childLeftover;
            }

            $patch['children'] = $children;
        }

        return $patch;
    }

    /**
     * Pair one note's legacy properties with its Craft fields, keeping only what
     * exists at both ends — the same rules `suggest` prints with.
     *
     * @param list<string> $columns
     * @param array<string, string> $map
     */
    private function resolveInto(Note $note, array $columns, string $block, ?string $nested, array &$map): void
    {
        $owner = $nested ?? $block;

        // A row listing the same number of sources and targets is a parallel
        // list — pairs in order, not "whichever is set".
        $paired = count($note->sources) > 1 && count($note->sources) === count($note->targets);

        foreach ($note->targets as $i => $target) {
            if (isset($map[$target])) {
                continue;
            }

            $slot = $this->schema->slot($owner, $target);

            if ($slot === null) {
                continue;
            }

            foreach ($paired ? [$note->sources[$i]] : $note->sources as $source) {
                $column = $this->column($source, $columns);

                if ($column !== null) {
                    $map[$target] = $this->expression($target, $column, $slot);

                    break;
                }
            }
        }
    }

    /** @param list<string> $columns */
    private function column(string $property, array $columns): ?string
    {
        $snake = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $property));

        foreach ([$snake, $snake . self::RELATION_SUFFIX, $property] as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The spec's dropped properties, as the real columns this table has.
     *
     * @param list<string> $properties
     * @param list<string> $columns
     * @return list<string>
     */
    private function asColumns(array $properties, array $columns): array
    {
        $out = [];

        foreach ($properties as $property) {
            $column = $this->column($property, $columns);

            if ($column !== null) {
                $out[] = $column;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * The mapping's child collection for this part.
     *
     * @param array<string, mixed> $spec
     * @return array{0: ?string, 1: ?array<string, mixed>}
     */
    private function childOf(array $spec): array
    {
        foreach ($spec['children'] ?? [] as $field => $child) {
            if (is_array($child) && ($child['table'] ?? '') !== '') {
                return [(string) $field, $child];
            }
        }

        return [null, null];
    }

    /**
     * Attach the transform the field's shape implies, so the draft is usable as
     * written — an Assets field wants `asset`, a relation wants `ref`.
     */
    private function expression(string $field, string $column, ?Slot $slot = null): string
    {
        $relational = in_array($slot?->type, ['Entries', 'Categories', 'Users', 'Tags'], true);

        return match (true) {
            str_ends_with($column, '_id') && $relational => $column . ' | ref',
            str_ends_with($column, '_id') && $slot?->type === 'Assets' => $column . ' | asset',
            str_ends_with($column, '_id') && $slot !== null => $column,
            in_array($field, ['content', 'text', 'quote'], true) => $column . ' | ckeditor',
            in_array($field, ['heading', 'label', 'eyebrow', 'tabLabel', 'paneTitle', 'personName'], true)
                                                                              => $column . ' | inlineHtml',
            $field === 'colorScheme' => $column . ' | colorScheme',
            $field === 'titleLevel' => $column . ' | titleLevel',
            default => $column,
        };
    }
}
