<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\run;

use lameco\kunstmaanmigrator\sites\SiteMap;

/**
 * Whether the mapping's environments are actually migratable, before anyone
 * presses the button.
 *
 * Connecting costs nothing and a migration is hours, so every answer this can
 * give in a second is an hour someone does not lose. The checks are the three
 * things that have actually gone wrong: the wrong database, an uploads
 * directory that moved, and a locale pointing at a Craft site nobody created.
 */
final class MappingPreflight
{
    public function __construct(private readonly PreflightProbe $probe)
    {
    }

    /**
     * @param array<string, array<string, mixed>> $environments the mapping's `environments:` block
     * @param iterable<object>                    $craftSites   anything with id/handle/language
     * @return list<EnvironmentReadiness>
     */
    public function inspect(array $environments, iterable $craftSites): array
    {
        $sites = is_array($craftSites) ? $craftSites : iterator_to_array($craftSites);
        $out = [];

        foreach ($environments as $name => $spec) {
            $database = (string) ($spec['database'] ?? '');
            $reachable = $database !== '' && $this->probe->reachable($database);

            $out[] = new EnvironmentReadiness(
                name: (string) $name,
                database: $database,
                databaseReachable: $reachable,
                connectionError: $reachable ? null : $this->probe->connectionError($database),
                nodeCount: $reachable ? $this->probe->nodeCount($database) : null,
                mediaRoots: $this->mediaRoots($spec),
                localesWithoutSite: $this->localesWithoutSite($spec, $sites),
                localesNotMigrated: $this->localesNotMigrated($spec),
            );
        }

        return $out;
    }

    /**
     * `mediaRoot` is an ordered chain, not one path: an environment looks in
     * its own uploads directory first and falls back to another site's for
     * media that only ever lived there.
     *
     * @param array<string, mixed> $spec
     * @return list<array{path: string, readable: bool, fallback: bool}>
     */
    private function mediaRoots(array $spec): array
    {
        $roots = $spec['mediaRoot'] ?? null;
        $roots = is_array($roots) ? array_values($roots) : ($roots === null ? [] : [$roots]);
        $out = [];

        foreach ($roots as $index => $path) {
            $path = (string) $path;
            $out[] = [
                'path' => $path,
                'readable' => $this->probe->directoryReadable($path),
                'fallback' => $index > 0,
            ];
        }

        return $out;
    }

    /**
     * A locale named in the mapping whose Craft site was never created. The
     * run would fail on every entry for that locale with "unknown site handle",
     * which is a slow way to find out.
     *
     * @param array<string, mixed> $spec
     * @param list<object>         $craftSites
     * @return list<string>
     */
    private function localesWithoutSite(array $spec, array $craftSites): array
    {
        $map = SiteMap::bind((array) ($spec['locales'] ?? []), $craftSites);
        $missing = [];

        foreach ($map->locales() as $locale) {
            if ($map->siteIdForLocale($locale) === null) {
                $missing[] = $locale;
            }
        }

        return $missing;
    }

    /**
     * Locales the mapping marks `!unmapped` — a decision with a reason, which
     * the DSL resolves to null. Shown so the screen can say "we know", not
     * counted as a fault.
     *
     * @param array<string, mixed> $spec
     * @return list<string>
     */
    private function localesNotMigrated(array $spec): array
    {
        $out = [];

        foreach ((array) ($spec['locales'] ?? []) as $locale => $handle) {
            if (!is_string($handle) || $handle === '') {
                $out[] = (string) $locale;
            }
        }

        return $out;
    }
}
