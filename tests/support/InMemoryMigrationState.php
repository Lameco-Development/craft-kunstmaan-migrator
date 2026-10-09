<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\support;

use Generator;
use Lameco\Kunstmaanmigrator\load\MigrationStateService;

/**
 * The source→target map, held in memory.
 *
 * The real service reads and writes a Craft table; the passes ask it four
 * things — resolve, record, read a row, update its meta — so those four are
 * all this answers.
 *
 * A row's `meta` is held as MySQL hands it back — a JSON string — and read
 * through the real `get()`, so a caller that forgets the column is JSON fails
 * here rather than on a second run against a real database.
 *
 * @internal
 */
final class InMemoryMigrationState extends MigrationStateService
{
    /** @var array<string, int> */
    private array $targets = [];

    /** @var array<string, array<string, mixed>> the row as the database returns it, `meta` a JSON string */
    private array $rows = [];

    /** @var list<array{source: string, key: string, targetType: string, targetId: int, meta: array<string, mixed>|null}> */
    public array $recorded = [];

    public function willResolve(string $source, string $key, int $targetId, ?array $meta = null): void
    {
        $this->targets[$source . '|' . $key] = $targetId;
        $this->rows[$source . '|' . $key] = [
            'source' => $source,
            'sourceKey' => $key,
            'targetId' => $targetId,
            'meta' => self::stored($meta),
        ];
    }

    /** @param array<string, mixed>|null $meta */
    private static function stored(?array $meta): ?string
    {
        return $meta === null ? null : json_encode($meta, JSON_THROW_ON_ERROR);
    }

    public function getTargetId(string $source, string $key, ?int $siteId = null): ?int
    {
        return $this->targets[$source . '|' . $key] ?? null;
    }

    protected function fetchRow(string $source, string $key, ?int $siteId): ?array
    {
        return $this->rows[$source . '|' . $key] ?? null;
    }

    public function record(
        string $source,
        string $key,
        string $targetType,
        int $targetId,
        ?string $targetUid = null,
        ?int $siteId = null,
        ?array $meta = null,
    ): void {
        $this->targets[$source . '|' . $key] = $targetId;
        $this->rows[$source . '|' . $key] = [
            'source' => $source,
            'sourceKey' => $key,
            'targetType' => $targetType,
            'targetId' => $targetId,
            'targetUid' => $targetUid,
            'meta' => $meta !== null ? self::stored($meta) : ($this->rows[$source . '|' . $key]['meta'] ?? null),
        ];
        $this->recorded[] = compact('source', 'key', 'targetType', 'targetId', 'meta');
    }

    public function forget(string $source, string $key, ?int $siteId = null): void
    {
        unset($this->targets[$source . '|' . $key], $this->rows[$source . '|' . $key]);
    }

    public function updateMeta(string $source, string $key, ?int $siteId, array $meta): void
    {
        if (!isset($this->rows[$source . '|' . $key])) {
            return;
        }

        $current = self::decodeMeta($this->rows[$source . '|' . $key]['meta'] ?? null) ?? [];
        $this->rows[$source . '|' . $key]['meta'] = self::stored(array_merge($current, $meta));
    }

    public function targetIds(string $targetType): Generator
    {
        $seen = [];

        foreach ($this->rows as $row) {
            $id = (int) ($row['targetId'] ?? 0);

            if (($row['targetType'] ?? null) === $targetType && $id > 0 && !isset($seen[$id])) {
                $seen[$id] = true;

                yield $id;
            }
        }
    }

    /** @return array<string, mixed>|null the meta a row carries, as the next run would read it */
    public function metaOf(string $source, string $key): ?array
    {
        return self::decodeMeta($this->rows[$source . '|' . $key]['meta'] ?? null);
    }

    /** @return list<int> */
    public function recordedTargetIds(): array
    {
        return array_map(static fn(array $row): int => $row['targetId'], $this->recorded);
    }
}
