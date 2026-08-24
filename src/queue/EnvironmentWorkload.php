<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\queue;

use craft\base\Batchable;

/**
 * The unit list one environment's batched migration walks.
 *
 * A unit is a small descriptor, not a payload: `['e', lane, offset, limit]`
 * for a window of an entity lane, `['n', nodeId]` for one page with its due
 * structural placeholders, `['t']` for the placeholders deeper than the last
 * page. The compile happens in `processItem()`, so a batch's cost is the
 * fifty units it processes — never the whole corpus.
 */
final class EnvironmentWorkload implements Batchable
{
    /** @param list<array<int, int|string>> $units */
    public function __construct(private readonly array $units)
    {
    }

    public function count(): int
    {
        return count($this->units);
    }

    public function getSlice(int $offset, int $limit): iterable
    {
        return array_slice($this->units, $offset, $limit);
    }
}
