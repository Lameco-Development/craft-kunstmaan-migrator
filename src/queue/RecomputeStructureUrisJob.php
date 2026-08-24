<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\queue;

use craft\queue\BaseJob;
use Lameco\Kunstmaanmigrator\craft\CraftElementWriter;
use Lameco\Kunstmaanmigrator\finalize\StructureUriPass;
use Lameco\Kunstmaanmigrator\Mapping\Mapping;
use Lameco\Kunstmaanmigrator\run\RunLog;
use Lameco\Kunstmaanmigrator\safety\ProductionGuard;
use RuntimeException;
use yii\queue\RetryableJobInterface;

/**
 * The URI pass: every Structure section the mapping writes into, walked
 * parents-first, each entry's URI settled on every site.
 *
 * Last in the chain, after the fixup pass has patched the parents a payload
 * named before they existed. The walk lives in StructureUriPass, which the
 * console command shares — this job is the queue's way in, and nothing more.
 */
final class RecomputeStructureUrisJob extends BaseJob implements RetryableJobInterface
{
    public string $mappingPath = '';

    /** @var array<string, int> */
    public array $counts = [];

    public function execute($queue): void
    {
        if (ProductionGuard::isProduction()) {
            throw new RuntimeException('Refusing to recompute URIs against CRAFT_ENVIRONMENT=production');
        }

        if (!is_file($this->mappingPath)) {
            throw new RuntimeException(sprintf('Mapping file is gone: %s', $this->mappingPath));
        }

        RunLog::default()->track('uris', [], function(array &$extra) use ($queue): void {
            $this->counts = (new StructureUriPass(new CraftElementWriter()))->run(
                Mapping::fromFile($this->mappingPath),
                function(string $section, int $done, int $total) use ($queue): void {
                    $this->setProgress($queue, $done / $total, $section);
                },
            );

            $extra['counts'] = $this->counts;
        });
    }

    protected function defaultDescription(): string
    {
        return 'Settling page URLs';
    }

    /**
     * One row per entry per site across every Structure section the mapping
     * writes into. Declared on the job because TTR must survive every push
     * path — the CP run screen, the console --queue mode, and the queue
     * manager's Retry, which re-activates the row with the channel default of
     * 300 seconds.
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
