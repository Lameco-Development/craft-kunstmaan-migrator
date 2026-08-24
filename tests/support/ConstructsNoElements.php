<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\support;

use craft\elements\Entry;
use LogicException;

/**
 * The construction half of the ElementWriter seam, for a test double that is
 * only about saves and lookups — the navigation passes never build an entry.
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
}
