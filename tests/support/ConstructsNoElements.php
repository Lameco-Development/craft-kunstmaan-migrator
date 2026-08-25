<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\support;

use craft\elements\Entry;
use LogicException;

/**
 * The parts of the ElementWriter seam a test double that is only about saves
 * and lookups never exercises — construction, site rows, search indexing. The
 * navigation passes never build an entry and never index one.
 *
 * @internal
 */
trait ConstructsNoElements
{
    public function createEntry(int $sectionId, int $typeId, int $siteId): Entry
    {
        throw new LogicException('this writer does not construct entries');
    }

    public function singleEntry(int $sectionId, int $siteId): ?Entry
    {
        return null;
    }

    public function livesOnAnySite(int $elementId, array $siteIds): bool
    {
        return false;
    }

    public function siteIdsOf(int $elementId): array
    {
        return [];
    }

    public function deferSearchIndexing(): void
    {
    }

    public function resumeSearchIndexing(): int
    {
        return 0;
    }

    public function nestedEntryIds(array $ownerIds): array
    {
        return [];
    }

    public function queueSearchIndex(string $elementType, array $elementIds): void
    {
    }
}
