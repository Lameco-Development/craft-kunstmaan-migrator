<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\load;

/**
 * Where a migrated asset lands inside the target volume.
 *
 * Pure: the folder chain and the year are handed in already resolved, so the decision this
 * class makes — which segments, in which order, with what fallback — is testable without a
 * Craft application or a legacy database. The database walk that produces a chain stays in
 * `AssetMigrationService`, which is the part that needs a connection.
 */
final class AssetFolderPath
{
    public const STRATEGY_YEAR = 'year';
    public const STRATEGY_LEGACY_TREE = 'legacy-tree';

    /**
     * @param string  $strategy   `year` or `legacy-tree`
     * @param string  $subfolder  volume-relative root, '' for the volume root
     * @param ?string $chain      resolved kuma_folders chain, root first, or null
     * @param string  $year       fallback bucket, from the file's own created date
     * @param ?string $env        legacy environment name
     * @param bool    $prefixEnv  whether the corpus has more than one legacy source
     */
    public static function compose(
        string $strategy,
        string $subfolder,
        ?string $chain,
        string $year,
        ?string $env = null,
        bool $prefixEnv = false,
    ): string {
        $segments = [];

        if ($strategy === self::STRATEGY_LEGACY_TREE && $chain !== null && $chain !== '') {
            if ($prefixEnv && $env !== null && $env !== '') {
                $segments[] = self::sanitizeSegment($env);
            }

            $segments[] = $chain;
        }

        // Nothing resolved — the year bucket, never the volume root. An unfiled file is one to
        // go and look at, not one to scatter across the top of the client's media library.
        if ($segments === []) {
            $segments[] = $year;
        }

        if ($subfolder !== '') {
            array_unshift($segments, $subfolder);
        }

        return implode('/', array_filter($segments, static fn(string $s): bool => $s !== ''));
    }

    /**
     * One path segment, safe for a Craft volume folder.
     *
     * Names travel as the client wrote them — `Afbeeldingen` stays `Afbeeldingen`. Translating
     * them would invent a folder the client has never seen, and the point of mirroring the tree
     * is that they recognise it.
     */
    public static function sanitizeSegment(string $name): string
    {
        $name = trim(str_replace(['/', '\\'], '-', $name));
        $name = preg_replace('/[\x00-\x1F\x7F:*?"<>|]/u', '', $name) ?? '';

        return trim($name, ". \t\n\r\0\x0B");
    }
}
