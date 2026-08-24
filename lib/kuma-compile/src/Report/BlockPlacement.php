<?php

declare(strict_types=1);

namespace Lameco\KumaCompile\Report;

use Lameco\KumaCompile\Mapping\Mapping;
use Lameco\KumaCompile\Target\TargetSchema;

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
     * @param array<string, array<string, int>> $livePairs page entity => pagepart class => placements
     * @return list<array{page: string, entryType: string, field: string, part: string, block: string, placements: int}>
     */
    public function rejections(array $livePairs): array
    {
        $defaults = $this->mapping->all()['defaults']['contexts'] ?? [];
        $out = [];

        foreach ($livePairs as $page => $parts) {
            $spec = $this->mapping->pages()[$page] ?? null;

            if (!is_array($spec) || isset($spec['manual']) || isset($spec['unmapped'])) {
                continue;
            }

            $entryType = (string) ($spec['entryType'] ?? '');

            if ($entryType === '' || !$this->schema->hasEntryType($entryType)) {
                continue;
            }

            foreach ($parts as $part => $placements) {
                foreach ($this->blocksOf($part) as $block) {
                    $field = $this->rejectedBy($entryType, $block, $spec['contexts'] ?? $defaults);

                    if ($field !== null) {
                        $out[] = [
                            'page' => (string) $page,
                            'entryType' => $entryType,
                            'field' => $field,
                            'part' => (string) $part,
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

    /**
     * The field that rejects this block, or null when some field accepts it.
     *
     * A page can stream into more than one context field, and one of them accepting the block is
     * enough for the content to land. Only a block every hosting field rejects is lost.
     *
     * @param array<string, mixed> $contexts
     */
    private function rejectedBy(string $entryType, string $block, array $contexts): ?string
    {
        $rejecting = null;

        foreach ($contexts as $target) {
            $field = is_array($target) ? (string) ($target['field'] ?? '') : '';
            $slot = $field !== '' ? $this->schema->slot($entryType, $field) : null;

            // A field that is not there is `pagesWithNoBlockField()`'s finding, not this one.
            // Reporting it here too would double-count the same placements.
            if ($slot === null) {
                continue;
            }

            if ($slot->nested === [] || in_array($block, $slot->nested, true)) {
                return null;
            }

            $rejecting ??= $field;
        }

        return $rejecting;
    }

    /** @return list<string> */
    private function blocksOf(string $part): array
    {
        $spec = $this->mapping->parts()[$part] ?? null;

        if (!is_array($spec) || isset($spec['drop']) || isset($spec['manual'])) {
            return [];
        }

        if (($spec['consumedBy'] ?? null) === 'sequence') {
            return [];
        }

        $blocks = [];

        if (isset($spec['block']) && is_string($spec['block'])) {
            $blocks[] = $spec['block'];
        }

        foreach ($spec['switch'] ?? [] as $case) {
            if (isset($case['block']) && is_string($case['block'])) {
                $blocks[] = $case['block'];
            }
        }

        return array_values(array_unique($blocks));
    }
}
