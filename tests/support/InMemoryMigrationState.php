<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\support;

use lameco\kunstmaanmigrator\load\MigrationStateService;

/**
 * The source→target map, held in memory.
 *
 * The real service reads and writes a Craft table; the passes only ever ask
 * it two things, so those two are all this answers.
 *
 * @internal
 */
final class InMemoryMigrationState extends MigrationStateService
{
    /** @var array<string, int> */
    private array $targets = [];

    /** @var list<array{source: string, key: string, targetType: string, targetId: int, meta: array<string, mixed>|null}> */
    public array $recorded = [];

    public function willResolve(string $source, string $key, int $targetId): void
    {
        $this->targets[$source . '|' . $key] = $targetId;
    }

    public function getTargetId(string $source, string $key, ?int $siteId = null): ?int
    {
        return $this->targets[$source . '|' . $key] ?? null;
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
        $this->recorded[] = compact('source', 'key', 'targetType', 'targetId', 'meta');
    }

    /** @return list<int> */
    public function recordedTargetIds(): array
    {
        return array_map(static fn(array $row): int => $row['targetId'], $this->recorded);
    }
}
