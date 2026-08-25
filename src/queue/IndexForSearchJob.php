<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\queue;

use craft\queue\BaseJob;
use Lameco\Kunstmaanmigrator\craft\CraftElementWriter;
use Lameco\Kunstmaanmigrator\finalize\SearchIndexPass;
use Lameco\Kunstmaanmigrator\Plugin;
use Lameco\Kunstmaanmigrator\run\RunLog;
use Lameco\Kunstmaanmigrator\safety\ProductionGuard;
use RuntimeException;
use yii\queue\RetryableJobInterface;

/**
 * The index stage: every element the migration wrote, handed to Craft's own
 * search indexing in chunks, once.
 *
 * Last in the chain, after the URI pass — the run saved with indexing
 * deferred, and this is where that work is done. The stage itself lives in
 * SearchIndexPass, which the console command shares; this job is the queue's
 * way in, and nothing more. What it pushes is Craft's `UpdateSearchIndex`,
 * so the indexing proper is Craft's job and Craft's progress bar.
 */
final class IndexForSearchJob extends BaseJob implements RetryableJobInterface
{
    /** @var array<string, int> */
    public array $counts = [];

    public int $jobs = 0;

    public function execute($queue): void
    {
        if (ProductionGuard::isProduction()) {
            throw new RuntimeException('Refusing to index for search against CRAFT_ENVIRONMENT=production');
        }

        RunLog::default()->track('search', [], function(array &$extra) use ($queue): void {
            $pass = new SearchIndexPass(new CraftElementWriter(), Plugin::getInstance()->migrationStateService);

            $this->counts = $pass->run(function(string $type, int $done, int $total) use ($queue): void {
                $this->setProgress($queue, $done / $total, $type);
            });
            $this->jobs = $pass->jobs();

            $extra['counts'] = $this->counts;
            $extra['searchIndexQueued'] = array_sum($this->counts);
            $extra['searchIndexJobs'] = $this->jobs;
        });
    }

    protected function defaultDescription(): string
    {
        return 'Indexing content for search';
    }

    /**
     * Reads the state table and pushes jobs; the indexing runs in those.
     * Declared on the job because TTR must survive every push path.
     */
    public function getTtr(): int
    {
        return 3600;
    }

    /**
     * A failed migration pass is retried by an operator who has read the
     * error, not by the queue on a loop.
     */
    public function canRetry($attempt, $error): bool
    {
        return false;
    }
}
