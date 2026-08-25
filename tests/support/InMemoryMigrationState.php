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
 * @internal
 */
final class InMemoryMigrationState extends MigrationStateService
{
    /** @var array<string, int> */
    private array $targets = [];

    /** @var array<string, array<string, mixed>> the row as `get()` returns it */
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
            'meta' => $meta,
        ];
    }

    public function getTargetId(string $source, string $key, ?int $siteId = null): ?int
    {
        return $this->targets[$source . '|' . $key] ?? null;
    }

    public function get(string $source, string $key, ?int $siteId = null): ?array
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
            'meta' => $meta ?? ($this->rows[$source . '|' . $key]['meta'] ?? null),
        ];
        $this->recorded[] = compact('source', 'key', 'targetType', 'targetId', 'meta');
    }

    public function updateMeta(string $source, string $key, ?int $siteId, array $meta): void
    {
        if (!isset($this->rows[$source . '|' . $key])) {
            return;
        }

        $current = $this->rows[$source . '|' . $key]['meta'] ?? [];
        $this->rows[$source . '|' . $key]['meta'] = array_merge(is_array($current) ? $current : [], $meta);
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
        $meta = $this->rows[$source . '|' . $key]['meta'] ?? null;

        return is_array($meta) ? $meta : null;
    }

    /** @return list<int> */
    public function recordedTargetIds(): array
    {
        return array_map(static fn(array $row): int => $row['targetId'], $this->recorded);
    }
}
