<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\Source;

use PDO;

/**
 * Legacy media id → the reference the loader resolves: the path its file lives at, or, for a
 * remote video, `kuma:media:<id>`.
 *
 * A Kunstmaan relation column holds a `kuma_media` id, but the loader's `_asset` contract
 * takes a path. Emitting the id produced references nothing could resolve — 3,111 of them on
 * the first full compile, every one reported as unresolved and every image missing.
 *
 * A remote video (`content_type` `remote/…`: YouTube, Vimeo) has no file and no url — the
 * video code sits in the serialized `metadata` — so there is no path to hand over. The loader
 * finds such a row by id and turns it into an embedded asset, so the reference names the id.
 * Before this every live Berkvens video (10 FR, 38 NL) compiled to an empty block.
 *
 * Loaded once per environment: the whole table is a few thousand rows, and a per-reference
 * query would run tens of thousands of times.
 */
final class MediaIndex
{
    /** The `_asset` form of a media row the loader resolves by id rather than by path. */
    private const ID_REFERENCE_PREFIX = 'kuma:media:';

    /** @param array<int, string> $paths media id => path */
    private function __construct(private readonly array $paths)
    {
    }

    public static function empty(): self
    {
        return new self([]);
    }

    public static function load(PDO $pdo): self
    {
        $paths = [];

        // `SELECT *` rather than naming `content_type`: a table without it still indexes its
        // files, and the remote branch is simply never taken.
        try {
            $rows = $pdo->query('SELECT * FROM kuma_media WHERE deleted = 0');
        } catch (\PDOException) {
            return new self([]);
        }

        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $url = trim((string) ($row['url'] ?? ''));

            if ($url !== '') {
                $paths[$id] = $url;
            } elseif (str_starts_with((string) ($row['content_type'] ?? ''), 'remote/')) {
                $paths[$id] = self::idReference($id);
            }
        }

        return new self($paths);
    }

    /**
     * The `_asset` reference for a media id: a path, or `kuma:media:<id>` for a remote video.
     * Null when the id names a deleted or missing media row — a dangling legacy reference.
     */
    public function pathFor(int|string|null $id): ?string
    {
        if ($id === null || $id === '') {
            return null;
        }

        return $this->paths[(int) $id] ?? null;
    }

    /** The `_asset` reference for a media row resolved by id: `kuma:media:<id>`. */
    public static function idReference(int $id): string
    {
        return self::ID_REFERENCE_PREFIX . $id;
    }

    /** The media id an `_asset` reference names, or null when it is a path. */
    public static function mediaIdOf(string $reference): ?int
    {
        if (!str_starts_with($reference, self::ID_REFERENCE_PREFIX)) {
            return null;
        }

        $id = substr($reference, strlen(self::ID_REFERENCE_PREFIX));

        return ctype_digit($id) ? (int) $id : null;
    }

    public function count(): int
    {
        return count($this->paths);
    }
}
