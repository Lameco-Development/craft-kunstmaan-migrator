<?php

declare(strict_types=1);

namespace tests\unit\console;

use PHPUnit\Framework\TestCase;

final class MigrateControllerPromotedTargetsTest extends TestCase
{
    public function testLoadOrderingPromotesSharedTargetsBeforeOwners(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/src/console/MigrateController.php');

        self::assertStringContainsString('isPromotedTargetPayloadFile', $source);
        self::assertStringContainsString('promotedTarget', $source);
        self::assertStringContainsString('stateSource', $source);
        self::assertStringContainsString('usort($files', $source);
        self::assertStringContainsString('? 0 : 1', $source);
    }
}
