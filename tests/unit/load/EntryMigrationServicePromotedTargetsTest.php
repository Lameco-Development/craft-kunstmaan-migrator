<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\load;

use lameco\kunstmaanmigrator\load\EntryMigrationService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class EntryMigrationServicePromotedTargetsTest extends TestCase
{
    public function testPromotedTargetWrapperUsesStateSourceStateKeySavePath(): void
    {
        $file = (string) (new ReflectionClass(EntryMigrationService::class))->getFileName();
        $source = (string) file_get_contents($file);

        self::assertStringContainsString('savePromotedTargetForSites', $source);
        self::assertStringContainsString('stateSource', $source);
        self::assertStringContainsString('stateKey', $source);
        self::assertStringContainsString('saveEntryForSites($sectionId, $typeId, $stateSource, $stateKey', $source);
    }
}
