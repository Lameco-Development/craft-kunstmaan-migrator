<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\load;

use craft\helpers\Assets as AssetsHelper;
use DateTimeImmutable;
use Throwable;

/**
 * Pure-function helpers for locating legacy media files on disk and
 * building safe target paths inside the new legacyMedia volume.
 *
 * Separated from AssetMigrationService so the path-safety logic is
 * unit-testable without a Craft bootstrap (see AssetPathResolverTest).
 *
 * Security: resolveLocal uses realpath() on both the root and the candidate
 * path, then enforces that the resolved candidate still lives under the
 * root — this defeats `..` / encoded traversal attempts in untrusted
 * kuma_media.url values (threat T-04-11 in the plan threat model).
 */
class AssetPathResolver
{
    /**
     * Resolves a legacy kuma_media.url to an absolute file path on the
     * source machine, rejecting anything that escapes $rootDir via realpath.
     *
     * Accepts both `/uploads/media/abc.jpg` (the canonical Kunstmaan URL
     * shape) and raw basenames like `abc.jpg` so callers can feed either
     * the full URL or a pre-stripped filename.
     *
     * @param string|null $kumaUrl kuma_media.url value (e.g. "/uploads/media/abc.jpg")
     * @param string      $rootDir absolute path to legacy media root (LEGACY_MEDIA_PATH)
     *
     * @return string|null absolute path within $rootDir, or null on missing/invalid
     */
    // T-04-11 path-traversal mitigation — preserves v1's realpath-on-both-sides + prefix-match.
    // DO NOT modify the realpath logic without re-evaluating the threat model.
    public static function resolveLocal(?string $kumaUrl, string $rootDir): ?string
    {
        if ($kumaUrl === null || $kumaUrl === '') {
            return null;
        }

        // Strip the "/uploads/media/" prefix if present; accept raw filenames too.
        $relative = preg_replace('#^/?uploads/media/#', '', $kumaUrl);
        $relative = ltrim($relative ?? '', '/');
        if ($relative === '') {
            return null;
        }

        $rootReal = realpath($rootDir);
        if ($rootReal === false) {
            return null;
        }

        $candidate = $rootReal . DIRECTORY_SEPARATOR . $relative;
        $candidateReal = realpath($candidate);
        if ($candidateReal === false) {
            return null;
        }

        // Ensure the resolved path is still under rootDir — defeats ../ traversal.
        $rootPrefix = $rootReal . DIRECTORY_SEPARATOR;
        $candidatePrefix = $candidateReal . DIRECTORY_SEPARATOR;
        if (!str_starts_with($candidatePrefix, $rootPrefix)) {
            return null;
        }

        if (!is_file($candidateReal)) {
            return null;
        }

        return $candidateReal;
    }

    /**
     * Returns a 4-digit year subfolder name from kuma_media.created_at,
     * or 'unknown' if the date is missing/malformed.
     */
    public static function targetYear(?string $createdAt): string
    {
        if ($createdAt === null || $createdAt === '') {
            return 'unknown';
        }
        try {
            $d = new DateTimeImmutable($createdAt);
            return $d->format('Y');
        } catch (Throwable) {
            return 'unknown';
        }
    }

    /**
     * Produces a safe filename using Craft's AssetsHelper (handles unicode,
     * reserved chars). The caller pairs this with `$asset->avoidFilenameConflicts
     * = true` so `-2`, `-3` suffixes are added on collision.
     *
     * Not unit-tested directly — AssetsHelper::prepareAssetName depends on
     * Craft's general config, so this is exercised by the integration run.
     */
    public static function sanitizeFilename(string $original): string
    {
        return AssetsHelper::prepareAssetName($original);
    }
}
