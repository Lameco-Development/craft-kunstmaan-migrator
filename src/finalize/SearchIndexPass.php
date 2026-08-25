<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\finalize;

use craft\elements\Asset;
use craft\elements\Entry;
use Lameco\Kunstmaanmigrator\craft\ElementWriter;
use Lameco\Kunstmaanmigrator\load\MigrationStateStream;

/**
 * The index stage: every element the state table says the migration wrote,
 * handed to Craft's own search indexing in chunks, once, after everything
 * else has committed.
 *
 * The run saves with indexing deferred (`ElementWriter::deferSearchIndexing()`):
 * Craft would otherwise extract keywords inline on every owner save and every
 * block save — and re-index the owner for each nested entry whose field is
 * searchable — for content nobody searches until the migration is done. What
 * the deferral skipped, this stage does once, and not by re-saving anything:
 * one `UpdateSearchIndex` job per chunk of ids, which loads each element on
 * every site it has and indexes its attributes. No element save, so nothing
 * propagates and nothing queues further maintenance.
 *
 * Nested entries get their own ids. Craft indexes a nested entry as an element
 * in its own right, not only as keywords folded into its owner's row, so an
 * owner-only rebuild would leave the block rows empty.
 *
 * Last in both callers, after the URI pass: it runs from committed state,
 * outside any entry transaction (ADR-0011), and the ids come from the state
 * table rather than from the run, so a resumed or narrowed run rebuilds the
 * same index a clean one does.
 */
final class SearchIndexPass
{
    /**
     * Ids per job. A job loads its chunk on every site at once, so the chunk
     * bounds memory; nine sites of two hundred owners is well under what one
     * batch of the entry pass already holds.
     */
    public const CHUNK = 200;

    /**
     * The state-table target types that are elements, and what to index them
     * as. Redirects and site translations are rows, not elements.
     */
    private const ELEMENT_TYPES = [
        'entry' => Entry::class,
        'asset' => Asset::class,
        'navigation_node' => 'verbb\navigation\elements\Node',
    ];

    private int $jobs = 0;

    public function __construct(
        private readonly ElementWriter $elements,
        private readonly MigrationStateStream $state,
        private readonly int $chunk = self::CHUNK,
    ) {
    }

    /**
     * @param callable(string, int, int): void|null $onType target type, done, total
     * @return array<string, int> target type => elements handed to the queue
     */
    public function run(?callable $onType = null): array
    {
        $this->jobs = 0;
        $types = array_filter(self::ELEMENT_TYPES, class_exists(...));
        $total = max(1, count($types));
        $counts = [];
        $done = 0;

        foreach ($types as $targetType => $class) {
            $ids = array_values(array_unique(iterator_to_array($this->state->targetIds($targetType), false)));

            if ($targetType === 'entry') {
                $ids = [...$ids, ...$this->nestedUnder($ids)];
            }

            foreach (array_chunk($ids, $this->chunk) as $chunk) {
                $this->elements->queueSearchIndex($class, $chunk);
                $this->jobs++;
            }

            $counts[$targetType] = count($ids);

            if ($onType !== null) {
                $onType($targetType, ++$done, $total);
            }
        }

        return $counts;
    }

    /** The jobs the last `run()` pushed. */
    public function jobs(): int
    {
        return $this->jobs;
    }

    /**
     * Every nested entry under these owners, however deep: a block's own
     * Matrix holds blocks of its own.
     *
     * @param list<int> $ownerIds
     * @return list<int>
     */
    private function nestedUnder(array $ownerIds): array
    {
        $found = [];
        $seen = [];

        while ($ownerIds !== []) {
            $level = [];

            foreach (array_chunk($ownerIds, 1000) as $chunk) {
                foreach ($this->elements->nestedEntryIds($chunk) as $id) {
                    if (!isset($seen[$id])) {
                        $seen[$id] = true;
                        $level[] = $id;
                    }
                }
            }

            $found = [...$found, ...$level];
            $ownerIds = $level;
        }

        return $found;
    }
}
