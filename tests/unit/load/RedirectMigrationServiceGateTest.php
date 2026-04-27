<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\load;

use lameco\kunstmaanmigrator\load\RedirectMigrationService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Phase 4.1 / Plan 04.1-05 / Task 1 — characterization tests for the
 * Settings-disabled gate at the head of RedirectMigrationService::migrateAll().
 *
 * Mirrors SeoMigrationServiceGateTest. Locks the D-27 distinct-warn-copy
 * invariant on the testable static helper.
 */
final class RedirectMigrationServiceGateTest extends TestCase
{
    public function testDisabledWarnLineCopyMatchesD27(): void
    {
        $rm = new ReflectionMethod(RedirectMigrationService::class, 'disabledWarnLine');
        self::assertSame(
            'Retour adapter disabled (explicitly via Settings::retourEnabled); redirect migration skipped.',
            $rm->invoke(null),
        );
    }

    public function testDisabledWarnLineIsDistinctFromPluginAbsentCopy(): void
    {
        $rm = new ReflectionMethod(RedirectMigrationService::class, 'disabledWarnLine');
        $line = (string) $rm->invoke(null);
        self::assertStringContainsString('explicitly via Settings::retourEnabled', $line);
        // The plugin-not-installed copy says "Retour plugin not installed; redirect migration skipped."
        self::assertStringNotContainsString('plugin not installed', $line);
        self::assertStringNotContainsString('plugin not loaded', $line);
    }

    public function testDisabledWarnLineIsRecognisableForSkippedStagesAggregation(): void
    {
        $rm = new ReflectionMethod(RedirectMigrationService::class, 'disabledWarnLine');
        $line = (string) $rm->invoke(null);
        self::assertStringContainsString('Retour adapter disabled', $line);
    }
}
