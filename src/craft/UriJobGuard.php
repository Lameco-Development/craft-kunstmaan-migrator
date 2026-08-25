<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\craft;

/**
 * Craft's deferred URI maintenance, held off while a run settles URIs itself.
 *
 * Every migration save is a `resaving` one, and for a Structure entry Craft
 * answers it by queueing `UpdateElementSlugsAndUris` for the descendants — at
 * default priority, behind the whole 512 chain. The URI pass makes that work
 * redundant: it recomputes every Structure entry parents-first once the
 * parents have settled. What the queue still held after the reference run was
 * 5,084 of those jobs, each an element save that queues its own descendants,
 * waiting for the next `queue/run`.
 *
 * The seam covers only those jobs, for entries, and only while a run that
 * will execute the URI pass is in progress — search-index jobs are never
 * touched, and a run that skips the pass (`--entries-only`, `--dry-run`, an
 * interrupted one) keeps Craft's own maintenance. Arming is per process; a
 * batched queue run is many processes, which is why `release()` exists: the
 * jobs the guard was not there to veto are dropped once the pass has done
 * their work.
 */
interface UriJobGuard
{
    /** Veto every entry slug/URI job pushed from now until `disarm()`. */
    public function arm(): void;

    /** Stop vetoing; the number of pushes vetoed while armed. */
    public function disarm(): int;

    /**
     * Drop every entry slug/URI job still waiting in the queue; how many.
     * Reserved jobs, failed jobs and every other job type stay.
     */
    public function release(): int;
}
