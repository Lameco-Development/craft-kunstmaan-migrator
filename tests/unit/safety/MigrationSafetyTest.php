<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\safety;

use lameco\kunstmaanmigrator\safety\MigrationSafety;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MigrationSafetyTest extends TestCase
{
    private const PRODUCTION_MESSAGE = 'Migration actions are disabled in production. Run this workflow only from a development or staging Craft environment.';

    private bool $hadPreviousEnvironment = false;
    private mixed $previousEnvironment = null;

    protected function setUp(): void
    {
        $this->hadPreviousEnvironment = array_key_exists('CRAFT_ENVIRONMENT', $_SERVER);
        $this->previousEnvironment = $_SERVER['CRAFT_ENVIRONMENT'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->hadPreviousEnvironment) {
            $_SERVER['CRAFT_ENVIRONMENT'] = $this->previousEnvironment;
        } else {
            unset($_SERVER['CRAFT_ENVIRONMENT']);
        }
    }

    public function testReportsProductionUsingNeverProductionEnvironmentSemantics(): void
    {
        $_SERVER['CRAFT_ENVIRONMENT'] = 'production';

        $safety = new MigrationSafety();

        self::assertTrue($safety->isProduction());
        self::assertSame('production', $safety->environmentName());
    }

    public function testReportsDevelopmentAsNonProduction(): void
    {
        $_SERVER['CRAFT_ENVIRONMENT'] = 'dev';

        $safety = new MigrationSafety();

        self::assertFalse($safety->isProduction());
        self::assertSame('dev', $safety->environmentName());
    }

    public function testCpProductionAssertionUsesOperatorFacingRefusalCopy(): void
    {
        $_SERVER['CRAFT_ENVIRONMENT'] = 'production';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(self::PRODUCTION_MESSAGE);

        (new MigrationSafety())->assertNotProductionForCp();
    }

    public function testJobProductionAssertionUsesOperatorFacingRefusalCopy(): void
    {
        $_SERVER['CRAFT_ENVIRONMENT'] = 'production';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(self::PRODUCTION_MESSAGE);

        (new MigrationSafety())->assertNotProductionForJob();
    }

    public function testAssertionsAllowStaging(): void
    {
        $_SERVER['CRAFT_ENVIRONMENT'] = 'staging';

        $safety = new MigrationSafety();

        $safety->assertNotProductionForCp();
        $safety->assertNotProductionForJob();

        self::assertFalse($safety->isProduction());
    }
}
