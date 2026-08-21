<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\queue;

use craft\queue\BaseJob;
use Lameco\KumaCompile\Mapping\Mapping;
use lameco\kunstmaanmigrator\load\MigrationOptions;
use lameco\kunstmaanmigrator\load\MigrationReport;
use lameco\kunstmaanmigrator\Plugin;
use lameco\kunstmaanmigrator\ProductionGuard;
use lameco\kunstmaanmigrator\run\EnvironmentPipeline;
use RuntimeException;

/**
 * The finalize pass: `[NT<id>]` resolved to the entry it became, and
 * `/uploads/media/...` to the asset it became.
 *
 * Runs after every environment, because neither can be answered until the
 * entries and assets exist. It repoints the legacy database per environment
 * like everything else — resolving a media reference goes through `kuma_media`,
 * and a reference COM cannot answer is simply retried under DE.
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

        $plugin = Plugin::getInstance();
        $mapping = Mapping::fromFile($this->mappingPath);
        $report = new MigrationReport();
        $dsn = EnvironmentPipeline::dsnFromSettings();
        $environments = $mapping->environments();
        $done = 0;

        foreach ($environments as $spec) {
            EnvironmentPipeline::pointLegacyDbAt($dsn, (string) ($spec['database'] ?? ''));
            $plugin->ckeditorRewriterService->resetLookupCaches();
            $plugin->ckeditorFinalizeService->run(new MigrationOptions(dryRun: $this->dryRun), $report);

            $this->setProgress($queue, ++$done / max(1, count($environments)));
        }

        $this->counts = $report->counts;
    }

    protected function defaultDescription(): string
    {
        return 'Resolving legacy links and media references';
    }
}
