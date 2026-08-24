<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\load;

use Generator;

/**
 * Streaming read-only view over the migration state table.
 *
 * Separate from MigrationStateReader because the two have different consumers:
 * a handler resolving one legacy id at a time needs `getTargetId`/`get` and
 * nothing else, while a cache-warming pass needs to walk whole sources and
 * never looks a single key up. Folding both into one interface would oblige
 * every lookup-only fake to implement streaming it does not use.
 *
 * Both methods already existed on MigrationStateService with these exact
 * signatures; this interface exists so CkeditorRewriterService can be typed to
 * the capability instead of to the concrete service, which is what made its
 * warm paths reachable from a unit test.
 */
interface MigrationStateStream
{
    /**
     * Streams every row recorded under `$source` (e.g. 'media', 'page', 'seo').
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function all(string $source): Generator;

    /**
     * Streams every entry-producing row, regardless of source alias — entries
     * are recorded under FQCN-derived source names, so a consumer that works
     * across all migrated entries must not enumerate sources itself.
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function entryRows(): Generator;
}
