<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\finalize;

use Lameco\KumaCompile\Mapping\Mapping;
use lameco\kunstmaanmigrator\load\MigrationOptions;
use lameco\kunstmaanmigrator\load\MigrationReport;
use lameco\kunstmaanmigrator\Plugin;
use lameco\kunstmaanmigrator\run\EnvironmentPipeline;

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
 * One loop, one set of per-environment state, three callers.
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
        $dsn = EnvironmentPipeline::dsnFromSettings();

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
            $spec = (array) $spec;

            // Repointing also drops the rewriter's lookup caches, which are keyed
            // on legacy ids that only mean anything inside one database.
            EnvironmentPipeline::pointLegacyDbAt($dsn, (string) ($spec['database'] ?? ''));
            EnvironmentPipeline::applyMediaRoots($spec, (string) $name, count($environments) > 1);

            $plugin->ckeditorFinalizeService->run(new MigrationOptions(dryRun: $dryRun), $report);

            $done++;

            if ($onEnvironment !== null) {
                $onEnvironment((string) $name, $done, $total);
            }
        }

        return $report;
    }
}
