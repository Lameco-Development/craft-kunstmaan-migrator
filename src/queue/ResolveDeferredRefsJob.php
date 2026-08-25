<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\queue;

use craft\queue\BaseJob;
use Lameco\Kunstmaanmigrator\load\FixupService;
use Lameco\Kunstmaanmigrator\Plugin;
use Lameco\Kunstmaanmigrator\run\MaintenanceGuard;
use Lameco\Kunstmaanmigrator\run\RunLog;
use Lameco\Kunstmaanmigrator\run\RunSettings;
use Lameco\Kunstmaanmigrator\run\RunTally;
use Lameco\Kunstmaanmigrator\safety\ProductionGuard;
use RuntimeException;
use yii\queue\RetryableJobInterface;

/**
 * The fixup pass: references a payload named before the entry existed.
 *
 * A payload can name a parent or a relation nothing had been written for yet,
 * and the load pass parks those as `pendingRefs` rather than failing. Something
 * has to come back for them.
 */
final class ResolveDeferredRefsJob extends BaseJob implements RetryableJobInterface
{
    /** @var array<string, mixed> */
    public array $report = [];

    /**
     * Whether the chain that ends here walked every environment and every node. Only then may
     * a ref whose target has no state row be classified as unresolvable rather than pending;
     * the chain's first job knows, and passes it down. The run screen's stand-alone fixup
     * button does not, and leaves everything pending.
     */
    public bool $fullCorpus = false;

    /**
     * Whether the URI pass and the index stage follow this job. Only then may its
     * patch saves hold Craft's maintenance off; the run screen's stand-alone fixup
     * button queues this job alone, and a hold there would leave what it patched
     * unsettled and unindexed for good.
     */
    public bool $chainCorpusPasses = false;

    public function execute($queue): void
    {
        if (ProductionGuard::isProduction()) {
            throw new RuntimeException('Refusing to resolve references against CRAFT_ENVIRONMENT=production');
        }

        $plugin = Plugin::getInstance();

        RunLog::default()->track('fixup', ['fullCorpus' => $this->fullCorpus], function(array &$extra) use ($plugin): void {
            $tally = new RunTally();
            $pass = function() use ($plugin): void {
                $this->report = (new FixupService(
                    $plugin->migrationStateService,
                    $plugin->entryMigrationService,
                ))->run($this->fullCorpus);
            };

            if ($this->chainCorpusPasses) {
                MaintenanceGuard::build()->guard(new RunSettings(), $tally, $pass);
            } else {
                $pass();
            }

            $extra['counts'] = $this->report;
            $extra['slugJobsVetoed'] = $tally->slugJobsVetoed;
            $extra['searchIndexDeferred'] = $tally->searchIndexDeferred;
        });
    }

    protected function defaultDescription(): string
    {
        return 'Linking pages to each other';
    }

    /**
     * The fixup pass walks every entry state row.
     * Declared on the job because TTR must survive every push path — the CP
     * run screen, the console --queue mode, and the queue manager's Retry,
     * which re-activates the row with the channel default of 300 seconds and
     * killed the first CP-driven DE run at five minutes.
     */
    public function getTtr(): int
    {
        return 7200;
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
