<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\legacy;

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
 */
final class MediaIndex
{
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

        try {
            $rows = $pdo->query('SELECT id, url FROM kuma_media WHERE deleted = 0 AND url IS NOT NULL');
        } catch (\PDOException) {
            return new self([]);
        }

        foreach ($rows as $row) {
            $url = trim((string) $row['url']);

            if ($url !== '') {
                $paths[(int) $row['id']] = $url;
            }
        }

        return new self($paths);
    }

    /** Null when the id names a deleted or missing media row — a dangling legacy reference. */
    public function pathFor(int|string|null $id): ?string
    {
        if ($id === null || $id === '') {
            return null;
        }

        return $this->paths[(int) $id] ?? null;
    }

    public function count(): int
    {
        return count($this->paths);
    }
}
