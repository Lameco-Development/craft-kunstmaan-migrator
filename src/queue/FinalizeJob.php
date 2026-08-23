<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\queue;

use Craft;
use craft\queue\BaseJob;
use Lameco\KumaCompile\Mapping\Mapping;
use lameco\kunstmaanmigrator\finalize\FinalizePass;
use lameco\kunstmaanmigrator\ProductionGuard;
use lameco\kunstmaanmigrator\run\RunLog;
use RuntimeException;

/**
 * The finalize pass: `[NT<id>]` resolved to the entry it became, and
 * `/uploads/media/...` to the asset it became.
 *
 * Runs after every environment, because neither can be answered until the
 * entries and assets exist. The loop itself lives in FinalizePass, which the
 * console command shares — this job is the queue's way in, and nothing more.
 */
final class FinalizeJob extends BaseJob
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

        $log = RunLog::default();
        $log->append(['event' => 'started', 'job' => 'finalize', 'dryRun' => $this->dryRun]);

        try {
            $report = (new FinalizePass())->run(
                Mapping::fromFile($this->mappingPath),
                $this->dryRun,
                null,
                function (string $environment, int $done, int $total) use ($queue): void {
                    $this->setProgress($queue, $done / $total, $environment);
                },
            );
        } catch (\Throwable $e) {
            $log->append(['event' => 'failed', 'job' => 'finalize', 'message' => $e->getMessage()]);

            throw $e;
        }

        $this->counts = $report->counts;
        $log->append(['event' => 'finished', 'job' => 'finalize', 'dryRun' => $this->dryRun, 'counts' => $report->counts]);
    }

    protected function defaultDescription(): string
    {
        return 'Fixing links and images inside text';
    }
}
