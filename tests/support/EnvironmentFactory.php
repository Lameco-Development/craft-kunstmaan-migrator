<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\support;

use Lameco\Kunstmaanmigrator\run\EnvironmentContext;
use Lameco\Kunstmaanmigrator\sites\SiteMap;

/**
 * An environment a test can hand to a pass, bound against plain site objects
 * rather than Craft's.
 *
 * @internal
 */
final class EnvironmentFactory
{
    /**
     * @param array<string, string> $localeToHandle legacy locale => Craft site handle
     * @param array<string, array{0: int, 1: string, 2?: bool}> $craftSites handle => [id, language, primary]
     */
    public static function sites(
        array $localeToHandle = ['nl' => 'default', 'en' => 'en'],
        array $craftSites = ['default' => [1, 'nl-NL', true], 'en' => [2, 'en-GB']],
    ): SiteMap {
        $sites = [];

        foreach ($craftSites as $handle => $facts) {
            $sites[] = (object) [
                'id' => $facts[0],
                'handle' => (string) $handle,
                'language' => $facts[1],
                'primary' => $facts[2] ?? false,
            ];
        }

        return SiteMap::bind($localeToHandle, $sites);
    }

    /**
     * @param array<string, string> $localeToHandle
     * @param array<string, array{0: int, 1: string, 2?: bool}> $craftSites
     * @param list<string> $mediaRoots
     */
    public static function make(
        string $name = 'COM',
        array $localeToHandle = ['nl' => 'default', 'en' => 'en'],
        array $craftSites = ['default' => [1, 'nl-NL', true], 'en' => [2, 'en-GB']],
        array $mediaRoots = [],
        bool $prefixEnvironment = false,
    ): EnvironmentContext {
        return new EnvironmentContext(
            name: $name,
            database: 'legacy_' . strtolower($name),
            sites: self::sites($localeToHandle, $craftSites),
            mediaRoots: $mediaRoots,
            prefixEnvironment: $prefixEnvironment,
        );
    }
}
