<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\Source;

/**
 * What one legacy environment actually contains, resolved to published content only.
 *
 * Separating this from the connection keeps the reporting side pure: coverage is computed
 * from plain counts, so it can be tested without a database.
 */
final readonly class LiveSnapshot
{
    /**
     * @param array<string, int> $partPlacements fully qualified pagepart class => live placements
     *        (`livePartPlacements()`); a mapping row claims each through `Mapping::partKey()`
     * @param array<string, int> $pageTypes      short page entity    => live pages
     * @param array<string, int> $pagesByLocale  legacy lang          => live pages
     * @param array<string, array<string, array<string, array<string, array{stacks: int, placements: int}>>>> $pageContextStacks
     *        short page entity => context => lang => the mix of fully qualified part classes one page
     *        translation holds there => how many stacks hold that mix, and their placements
     *        (`livePageContextStacks()`)
     */
    public function __construct(
        public string $environment,
        public array $partPlacements,
        public array $pageTypes,
        public array $pagesByLocale,
        public int $allPartRefs,
        public array $pageContextStacks = [],
    ) {
    }
}
