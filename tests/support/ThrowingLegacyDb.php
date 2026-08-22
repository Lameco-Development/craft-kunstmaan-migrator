<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\support;

use lameco\kunstmaanmigrator\db\LegacyDbService;

/**
 * @internal
 */
final class ThrowingLegacyDb extends LegacyDbService
{
    public function queryAll(string $sql, array $params = []): array
    {
        throw new \RuntimeException('legacy database is unreachable');
    }
}
