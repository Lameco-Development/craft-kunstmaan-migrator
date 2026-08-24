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
     * @param array<string, int> $partPlacements short pagepart class => live placements
     * @param array<string, int> $pageTypes      short page entity    => live pages
     * @param array<string, int> $pagesByLocale  legacy lang          => live pages
     */
    public function __construct(
        public string $environment,
        public array $partPlacements,
        public array $pageTypes,
        public array $pagesByLocale,
        public int $allPartRefs,
    ) {
    }
}
