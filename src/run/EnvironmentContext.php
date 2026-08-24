<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\run;

use Lameco\Kunstmaanmigrator\Mapping\Mapping;
use Lameco\Kunstmaanmigrator\sites\SiteMap;
use Lameco\Kunstmaanmigrator\Source\LegacyDatabase;

/**
 * Which environment is running, as a value.
 *
 * A migration walks three legacy databases, and everything about one of them —
 * its database, its uploads directories, which legacy locale writes to which
 * Craft site — was ambient. `SiteMap` made this trip already; the rest was
 * still written onto long-lived singletons per environment:
 *
 *     $plugin->navigationMigrationService->environment      = $env;
 *     $plugin->assetMigrationService->legacyMediaRoot       = ...;
 *     $plugin->assetMigrationService->legacyMediaFallbackRoots = ...;
 *
 * Which is how a cache came to outlive its database, and why anything running
 * outside EnvironmentPipeline::run() — the finalize pass, a queue job picking
 * up a second environment — inherited whatever the last one left behind, or
 * nothing at all.
 *
 * It is also what made the adapter interface unable to express half the lanes.
 * `migrateAll(MigrationOptions, SiteMap)` has nowhere to put "which legacy
 * database am I reading" or "which mapping am I compiling", so `redirects` —
 * whose records come from the mapping rather than from a table — could not be
 * an adapter at all and had to be a special case inside the pipeline. The
 * `forms:` and `globals:` lanes have exactly the same shape. One value carrying
 * the environment is what lets all three be ordinary adapters.
 */
final class EnvironmentContext
{
    /**
     * @param string        $name       the mapping's key for this environment, e.g. 'COM'
     * @param string        $database   the legacy database it reads
     * @param SiteMap       $sites      legacy locale => Craft site, for this environment only
     * @param list<string>  $mediaRoots uploads directories, most specific first
     * @param Mapping|null  $mapping    for a lane that compiles from the mapping
     * @param LegacyDatabase|null $legacy an open connection, for a lane that reads tables
     * @param list<string>|null $only    restrict to these entities, mirroring --only
     */
    public function __construct(
        public readonly string $name,
        public readonly string $database,
        public readonly SiteMap $sites,
        public readonly array $mediaRoots = [],
        public readonly ?Mapping $mapping = null,
        public readonly ?LegacyDatabase $legacy = null,
        public readonly ?array $only = null,
    ) {
    }

    /**
     * The lane's own block of the mapping, or an empty array when the mapping
     * does not declare one — which is the normal case for a lane a project has
     * not configured yet, not an error.
     *
     * @return array<string, mixed>
     */
    public function lane(string $key): array
    {
        $all = $this->mapping?->all() ?? [];
        $lane = $all[$key] ?? null;

        return is_array($lane) ? $lane : [];
    }
}
