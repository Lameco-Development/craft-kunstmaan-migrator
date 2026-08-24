<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\finalize;

use Lameco\Kunstmaanmigrator\load\MigrationOptions;
use Lameco\Kunstmaanmigrator\load\MigrationReport;
use Lameco\Kunstmaanmigrator\Mapping\Mapping;
use Lameco\Kunstmaanmigrator\Plugin;
use Lameco\Kunstmaanmigrator\run\EnvironmentPipeline;
use Lameco\Kunstmaanmigrator\run\RunSettings;

/**
 * The finalize pass over every environment the mapping declares.
 *
 * `[NT<id>]` resolves to the entry a legacy node translation became, and
 * `/uploads/media/...` to the asset a legacy file became. Neither can be
 * answered until the entries and assets exist, so this runs last — and it runs
 * per environment, because both answers come out of that environment's legacy
 * database.
 *
 * There were three copies of this loop and they disagreed. `migrate` ran the
 * pass **once**, against whichever database its environment loop happened to
 * end on, so a three-environment corpus was finalized against one third of it.
 * `--finalizeOnly` and the queue job looped correctly but never set the media
 * roots, which is what lets an unmigrated image be ingested on demand — so the
 * pass they ran could rewrite a link and not an image. Between them the COM
 * corpus rewrote 863 links and 24 of 177 images.
 *
 * One loop, one environment opened the way the pipeline opens it, three callers.
 */
final class FinalizePass
{
    /**
     * @param callable(string, int, int): void|null $onEnvironment name, done, total
     */
    public function run(
        Mapping $mapping,
        bool $dryRun,
        ?string $onlyEnvironment = null,
        ?callable $onEnvironment = null,
    ): MigrationReport {
        $plugin = Plugin::getInstance();
        $report = new MigrationReport();
        $settings = new RunSettings(dryRun: $dryRun);

        $environments = $mapping->environments();

        if ($onlyEnvironment !== null) {
            $environments = array_filter(
                $environments,
                static fn(string $name): bool => $name === $onlyEnvironment,
                ARRAY_FILTER_USE_KEY,
            );
        }

        $total = max(1, count($environments));
        $done = 0;

        foreach ($environments as $name => $spec) {
            // The database answers `[NT<id>]`; the media roots let an image no
            // payload pulled in be ingested on demand. Opening the environment
            // the way the pipeline does is what keeps both together.
            EnvironmentPipeline::open($mapping, (string) $name, (array) $spec, $settings);

            $plugin->ckeditorFinalizeService->run(new MigrationOptions(dryRun: $dryRun), $report);

            $done++;

            if ($onEnvironment !== null) {
                $onEnvironment((string) $name, $done, $total);
            }
        }

        return $report;
    }
}
