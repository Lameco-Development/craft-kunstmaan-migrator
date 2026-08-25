<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\run;

use Lameco\Kunstmaanmigrator\craft\CraftElementWriter;
use Lameco\Kunstmaanmigrator\craft\CraftUriJobGuard;
use Lameco\Kunstmaanmigrator\craft\ElementWriter;
use Lameco\Kunstmaanmigrator\craft\UriJobGuard;

/**
 * The hold on Craft's per-save maintenance — entry-URI jobs vetoed, search
 * indexing deferred — for a run that ends in the closing passes that do
 * that work once: the URI pass and the index stage.
 *
 * The pipeline held this itself, which covered the entry loop and the
 * adapters and nothing after them. The corpus-wide fixup and finalize passes
 * save entries too, run before the URI pass and the index stage just the
 * same, and have no pipeline: the fixup job does not even carry a mapping.
 * On the reference corpus that gap grew `searchindex` by 23,973 rows during
 * the two passes, every one queued for indexing again by the index stage.
 * So the hold is its own value, and both callers arm it around every pass
 * that writes.
 */
final class MaintenanceGuard
{
    private bool $armed = false;

    public function __construct(
        private readonly UriJobGuard $uriJobs,
        private readonly ElementWriter $elements,
    ) {
    }

    public static function build(): self
    {
        return new self(new CraftUriJobGuard(), new CraftElementWriter());
    }

    /**
     * Run `$body` under the hold. Disarmed on the way out whatever happens
     * inside, so an exception never leaves a handler on the queue, or the
     * index off, for whatever the process does next.
     *
     * @param callable(): void $body
     */
    public function guard(RunSettings $settings, RunTally $tally, callable $body): void
    {
        $this->arm($settings);

        try {
            $body();
        } finally {
            $this->disarm($tally);
        }
    }

    /**
     * The arm half on its own, for the batched job: a batch arms when it
     * loads and disarms after its last item, with Craft's own loop in between.
     * A caller whose run will not reach the closing passes gets a no-op.
     */
    public function arm(RunSettings $settings): void
    {
        if (!$settings->settlesUris() || $this->armed) {
            return;
        }

        $this->uriJobs->arm();
        $this->elements->deferSearchIndexing();
        $this->armed = true;
    }

    /** The disarm half; what was vetoed and what went unindexed land on the tally. */
    public function disarm(RunTally $tally): void
    {
        if (!$this->armed) {
            return;
        }

        $this->armed = false;
        $tally->slugJobsVetoed += $this->uriJobs->disarm();
        $tally->searchIndexDeferred += $this->elements->resumeSearchIndexing();
    }
}
