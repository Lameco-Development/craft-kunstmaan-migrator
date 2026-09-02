<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\Source;

use PDO;

/**
 * Legacy media id → the path its file lives at.
 *
 * A Kunstmaan relation column holds a `kuma_media` id, but the loader's `_asset` contract
 * takes a path. Emitting the id produced references nothing could resolve — 3,111 of them on
 * the first full compile, every one reported as unresolved and every image missing.
 *
 * Loaded once per environment: the whole table is a few thousand rows, and a per-reference
 * query would run tens of thousands of times.
 *
 * REMOTE VIDEOS HAVE NO `url` AT ALL — Kunstmaan's RemoteVideoHandler stores a Vimeo/YouTube/
 * Dailymotion embed as `{code, type}` in `kuma_media.metadata`, and leaves `url` null (see
 * `RemoteVideoUrl`). Without special handling those rows were absent from `$paths`, `pathFor()`
 * returned null for every one of them, and `BlockBuilder`'s `asset` transform treated that as a
 * dangling reference and dropped the field — silently, because a remote video row is not a
 * broken reference, it is a reference `pathFor()` was never able to answer. 281 live rows
 * across the Enreach corpus, none of which resolved to anything (Trello #183). `isRemoteVideo()`
 * lets `BlockBuilder` tell the two cases apart without a second query.
 */
final class MediaIndex
{
    /**
     * @param array<int, string> $paths media id => path
     * @param array<int, true> $remoteVideoIds media id => true, for ids classified as a remote
     *                                         (oEmbed) video — see the class doc
     */
    private function __construct(
        private readonly array $paths,
        private readonly array $remoteVideoIds = [],
    ) {
    }

    public static function empty(): self
    {
        return new self([]);
    }

    public static function load(PDO $pdo): self
    {
        $paths = [];
        $remoteVideoIds = [];

        // `url IS NOT NULL` used to gate the query itself; it now only gates which map a row
        // lands in, because a remote-video row (url NULL by construction) still needs to be
        // seen here to be classified rather than silently absent from both maps.
        try {
            $rows = $pdo->query('SELECT id, url, content_type, location FROM kuma_media WHERE deleted = 0');
        } catch (\PDOException) {
            return new self([]);
        }

        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $url = trim((string) ($row['url'] ?? ''));

            if ($url !== '') {
                $paths[$id] = $url;

                continue;
            }

            // Same classification `AssetMigrationService::ingestRow()` uses for the loader
            // side of this same row — kept in sync deliberately rather than shared, because
            // this side has no database access to fall back on if the two ever drift and this
            // one throws instead.
            $contentType = (string) ($row['content_type'] ?? '');
            $location = $row['location'] ?? null;
            $isRemoteVideo = str_starts_with($contentType, 'remote/')
                || (str_contains($contentType, 'video') && ($location === null || $location === ''));

            if ($isRemoteVideo) {
                $remoteVideoIds[$id] = true;
            }
        }

        return new self($paths, $remoteVideoIds);
    }

    /** Null when the id names a deleted or missing media row — a dangling legacy reference. */
    public function pathFor(int|string|null $id): ?string
    {
        if ($id === null || $id === '') {
            return null;
        }

        return $this->paths[(int) $id] ?? null;
    }

    /** True when $id names a kuma_media row with no local file — a remote (oEmbed) video. */
    public function isRemoteVideo(int|string|null $id): bool
    {
        if ($id === null || $id === '') {
            return false;
        }

        return isset($this->remoteVideoIds[(int) $id]);
    }

    public function count(): int
    {
        return count($this->paths);
    }
}
