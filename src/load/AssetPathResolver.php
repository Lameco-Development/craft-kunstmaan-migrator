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
     * Resolves any legacy `/uploads/…` path against one configured media root.
     *
     * `/uploads/media/…` and bare names go through `resolveLocal()` unchanged. Anything else
     * under `/uploads/` — a catalogue file at `/uploads/models_import/A12.jpg`, which has no
     * `kuma_media` row — is a sibling of the media directory: a root named `…/uploads/media`
     * (the Kunstmaan convention every mapping states) is read from its parent, and a root
     * named anywhere else is taken to be the uploads directory itself. The traversal guard is
     * `resolveLocal()`'s, against whichever directory that is.
     *
     * @param string $mediaRoot one entry of the environment's `mediaRoot` chain
     */
    public static function resolveUpload(?string $url, string $mediaRoot): ?string
    {
        if ($url !== null && preg_match('#^/?uploads/(?!media/)(.+)$#', $url, $m) === 1) {
            $root = rtrim($mediaRoot, '/' . DIRECTORY_SEPARATOR);
            $uploads = basename($root) === 'media' ? dirname($root) : $root;

            return self::resolveLocal($m[1], $uploads);
        }

        return self::resolveLocal($url, $mediaRoot);
    }

    /**
     * The uploads-relative directory of a path outside `kuma_media` — `models_import` for
     * `/uploads/models_import/A12.jpg` — or null for a media path or a loose file.
     *
     * It is the only organisation such a file ever had, so `legacy-tree` mirrors it where a
     * `kuma_media` row would have named a `kuma_folders` chain.
     */
    public static function uploadDir(string $url): ?string
    {
        if (preg_match('#^/?uploads/(?!media/)(.+)/[^/]+$#', $url, $m) !== 1) {
            return null;
        }

        return $m[1];
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
