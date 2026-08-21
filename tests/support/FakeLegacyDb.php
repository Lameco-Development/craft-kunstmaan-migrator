<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\support;

use lameco\kunstmaanmigrator\db\LegacyDbService;

/**
 * Returns each queued result set in call order.
 *
 * @internal
 */
final class FakeLegacyDb extends LegacyDbService
{
    /** @param list<list<array<string, mixed>>> $resultSets */
    public function __construct(private array $resultSets = [])
    {
        parent::__construct();
    }

    public function queryAll(string $sql, array $params = []): array
    {
        return array_shift($this->resultSets) ?? [];
    }
}
