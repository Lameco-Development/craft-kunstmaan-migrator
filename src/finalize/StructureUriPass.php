<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\finalize;

use Lameco\Kunstmaanmigrator\craft\ElementWriter;
use Lameco\Kunstmaanmigrator\craft\UriJobGuard;
use Lameco\Kunstmaanmigrator\Mapping\Mapping;

/**
 * The URI pass: every Structure section the mapping writes into, walked
 * parents-first, each entry's slug and URI recomputed on every site it has.
 *
 * A Structure entry's URI is `{parent.uri}/{slug}`, rendered at save time for
 * the site being saved. The migration can only get that right when the parent
 * was written first, and it is not: entity-lane units run before every node,
 * a `parentRef` that lands in `pendingRefs` is patched at the very end of the
 * corpus, and every save is a `resaving` one whose descendant maintenance
 * Craft hands to the queue at default priority — behind the whole 512 chain.
 * So a subtree written before its ancestor's per-site slugs settled kept a
 * stale prefix until an operator remembered `resave/entries`. On the
 * reference corpus that was the difference between 76.6% and 97.7% URL
 * fidelity, and it was a line in the README.
 *
 * This is the deterministic version of what the resave was doing by accident:
 * `elements_sites` updated directly, one row per site, parents before
 * children, no element save and no queue. It runs after the fixup pass has
 * patched the deferred parents, from committed state, outside any entry
 * transaction.
 *
 * Once the walk is done, the entry-URI jobs Craft queued for the same work
 * are released: the guard vetoes them while a process is armed, but a batched
 * queue run is many processes and the jobs its batches pushed unguarded are
 * still waiting — every one an element save that queues its descendants.
 */
final class StructureUriPass
{
    private int $releasedJobs = 0;

    public function __construct(
        private readonly ElementWriter $elements,
        private readonly UriJobGuard $jobs,
    ) {
    }

    /**
     * @param callable(string, int, int): void|null $onSection handle, done, total
     * @return array<string, int> section handle => entries whose URIs were recomputed
     */
    public function run(Mapping $mapping, ?callable $onSection = null): array
    {
        $handles = self::sectionHandles($mapping);
        $total = max(1, count($handles));
        $counts = [];

        foreach ($handles as $done => $handle) {
            $counts[$handle] = 0;

            foreach ($this->elements->structureEntries($handle) as $entry) {
                $this->elements->updateSlugAndUri($entry);
                $counts[$handle]++;
            }

            if ($onSection !== null) {
                $onSection($handle, $done + 1, $total);
            }
        }

        $this->releasedJobs = $this->jobs->release();

        return $counts;
    }

    /** The deferred entry-URI jobs the last `run()` dropped from the queue. */
    public function releasedJobs(): int
    {
        return $this->releasedJobs;
    }

    /**
     * The sections the run writes entries into, read off the kernel rows so a
     * page row that leaves `section:` to its default is counted — the raw spec
     * has no key for it.
     *
     * @return list<string>
     */
    private static function sectionHandles(Mapping $mapping): array
    {
        $handles = [];

        foreach ($mapping->pageRows() as $row) {
            if ($row->isMigrated()) {
                $handles[$row->section()] = true;
            }
        }

        foreach ($mapping->entityRows() as $row) {
            $section = $row->section();

            if ($section !== null && $row->compiles()) {
                $handles[$section] = true;
            }
        }

        return array_keys($handles);
    }
}
