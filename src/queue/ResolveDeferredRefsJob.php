<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\queue;

use Craft;
use craft\queue\BaseJob;
use lameco\kunstmaanmigrator\payload\FixupService;
use lameco\kunstmaanmigrator\Plugin;
use lameco\kunstmaanmigrator\ProductionGuard;
use lameco\kunstmaanmigrator\run\RunLog;
use RuntimeException;

/**
 * The fixup pass: references a payload named before the entry existed.
 *
 * A payload can name a parent or a relation nothing had been written for yet,
 * and the load pass parks those as `pendingRefs` rather than failing. Something
 * has to come back for them.
 */
final class ResolveDeferredRefsJob extends BaseJob
{
    /** @var array<string, mixed> */
    public array $report = [];

    public function execute($queue): void
    {
        if (ProductionGuard::isProduction()) {
            throw new RuntimeException('Refusing to resolve references against CRAFT_ENVIRONMENT=production');
        }

        $plugin = Plugin::getInstance();

        RunLog::default()->track('fixup', [], function (array &$extra) use ($plugin): void {
            $this->report = (new FixupService(
                $plugin->migrationStateService,
                $plugin->entryMigrationService,
            ))->run();

            $extra['counts'] = $this->report;
        });
    }

    protected function defaultDescription(): string
    {
        return 'Linking pages to each other';
    }
}
