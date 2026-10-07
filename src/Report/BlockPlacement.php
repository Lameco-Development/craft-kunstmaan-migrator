<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\Report;

use Lameco\Kunstmaanmigrator\Mapping\Mapping;
use Lameco\Kunstmaanmigrator\Source\PartClass;
use Lameco\Kunstmaanmigrator\Target\TargetSchema;

/**
 * Parts whose block the page they sit on will not accept.
 *
 * `TargetCheck::blocksNoPageAccepts()` answers the total question — a block *no* page accepts —
 * because that much is knowable from two YAML files. This answers the partial one, which is the
 * question that actually bites: `contactCardBlock` is fine on a `contentPage` and rejected by
 * `blogPage`, whose page builder allows ten block types where the general one allows twenty-four.
 *
 * The partial question cannot be answered statically without warning that `caseBlock` is not
 * allowed on `blogPage` — which is the content model working as designed, on a pairing that never
 * occurs. It is answerable exactly once the corpus is in hand: only pairings that actually occur
 * are considered, and every one of them is a measured number of placements that will be dropped
 * at write time.
 *
 * Measured on the reference corpus: 41 placements on `blogPage` alone, discovered by sweeping
 * migrated entries after the fact rather than by any check before the run.
 */
final class BlockPlacement
{
    public function __construct(
        private readonly Mapping $mapping,
        private readonly TargetSchema $schema,
    ) {
    }

    /**
     * @param array<string, array<string, array<string, int>>> $livePairs page entity => context =>
     *        fully qualified pagepart class => placements (`livePlacementsByPageType()`); each is
     *        judged by the row that claims it (`Mapping::partKey()`) and reported by its short name,
     *        or its qualified one where another live class shares that (`PartClass::reportNames()`)
     * @return list<array{page: string, entryType: string, context: string, field: string, part: string, block: string, placements: int}>
     */
    public function rejections(array $livePairs): array
    {
        $out = [];
        $names = $this->reportNames($livePairs);

        foreach ($livePairs as $page => $contexts) {
            $row = $this->mapping->pageRow((string) $page);

            if ($row === null || !$row->compiles()) {
                continue;
            }

            $entryType = (string) $row->entryType();

            if (!$this->schema->hasEntryType($entryType)) {
                continue;
            }

            $streamed = $row->contexts();

            foreach ($contexts as $context => $parts) {
                // A context the page does not stream compiles to nothing; no allow-list drops it.
                if (!isset($streamed[$context])) {
                    continue;
                }

                // Each context writes to its own field (#89): that field's allow-list is the one
                // that decides, however many other fields on the page would take the block.
                $field = (string) $streamed[$context]['field'];

                foreach ($parts as $class => $placements) {
                    foreach ($this->blocksOf($this->mapping->partKey((string) $class)) as $block) {
                        if (!$this->rejects($entryType, $field, $block)) {
                            continue;
                        }

                        $out[] = [
                            'page' => (string) $page,
                            'entryType' => $entryType,
                            'context' => (string) $context,
                            'field' => $field,
                            'part' => $names[(string) $class],
                            'block' => $block,
                            'placements' => (int) $placements,
                        ];
                    }
                }
            }
        }

        usort($out, static fn(array $a, array $b): int => $b['placements'] <=> $a['placements']);

        return $out;
    }

    /** Whether the field is there and leaves the block off its allow-list. */
    private function rejects(string $entryType, string $field, string $block): bool
    {
        $slot = $this->schema->slot($entryType, $field);

        // A field that is not there is `pagesWithNoBlockField()`'s finding, not this one.
        // Reporting it here too would double-count the same placements.
        if ($slot === null) {
            return false;
        }

        return $slot->nested !== [] && !in_array($block, $slot->nested, true);
    }

    /**
     * @param array<string, array<string, array<string, int>>> $livePairs
     * @return array<string, string> fully qualified class => the name a report gives it
     */
    private function reportNames(array $livePairs): array
    {
        $classes = [];

        foreach ($livePairs as $contexts) {
            foreach ($contexts as $parts) {
                $classes += array_fill_keys(array_map(strval(...), array_keys($parts)), true);
            }
        }

        return PartClass::reportNames(array_keys($classes));
    }

    /** @return list<string> */
    private function blocksOf(string $key): array
    {
        $row = $this->mapping->partRow($key);

        return $row !== null && $row->compilesToBlocks() ? $row->blocks() : [];
    }
}
