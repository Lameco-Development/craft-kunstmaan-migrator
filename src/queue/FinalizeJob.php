<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\queue;

use craft\queue\BaseJob;
use Lameco\KumaCompile\Mapping\Mapping;
use lameco\kunstmaanmigrator\finalize\FinalizePass;
use lameco\kunstmaanmigrator\ProductionGuard;
use lameco\kunstmaanmigrator\run\RunLog;
use RuntimeException;
use yii\queue\RetryableJobInterface;

/**
 * The finalize pass: `[NT<id>]` resolved to the entry it became, and
 * `/uploads/media/...` to the asset it became.
 *
 * Runs after every environment, because neither can be answered until the
 * entries and assets exist. The loop itself lives in FinalizePass, which the
 * console command shares — this job is the queue's way in, and nothing more.
 */
final class FinalizeJob extends BaseJob implements RetryableJobInterface
{
    public string $mappingPath = '';
    public bool $dryRun = false;

    /** @var array<string, int> */
    public array $counts = [];

    public function execute($queue): void
    {
        if (ProductionGuard::isProduction()) {
            throw new RuntimeException('Refusing to finalize against CRAFT_ENVIRONMENT=production');
        }

        if (!is_file($this->mappingPath)) {
            throw new RuntimeException(sprintf('Mapping file is gone: %s', $this->mappingPath));
        }

        RunLog::default()->track('finalize', ['dryRun' => $this->dryRun], function(array &$extra) use ($queue): void {
            $report = (new FinalizePass())->run(
                Mapping::fromFile($this->mappingPath),
                $this->dryRun,
                null,
                function(string $environment, int $done, int $total) use ($queue): void {
                    $this->setProgress($queue, $done / $total, $environment);
                },
            );

            $this->counts = $report->counts;
            $extra['counts'] = $report->counts;
        });
    }

    protected function defaultDescription(): string
    {
        return 'Fixing links and images inside text';
    }

    /**
     * The finalize pass rewrites every CKEditor field once per site.
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
