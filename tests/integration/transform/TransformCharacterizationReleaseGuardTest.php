<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\integration\transform;

use PHPUnit\Framework\TestCase;

final class TransformCharacterizationReleaseGuardTest extends TestCase
{
    public function testReleaseModeRequiresLoudNonEmptyFixtureGuard(): void
    {
        $source = file_get_contents(__DIR__ . '/TransformCharacterizationTest.php');

        self::assertIsString($source);
        self::assertStringContainsString('RELEASE_REHEARSAL', $source);
        self::assertStringContainsString('Release rehearsal fixture corpus is empty', $source);
        self::assertStringContainsString('No transform fixtures present', $source);
    }
}
