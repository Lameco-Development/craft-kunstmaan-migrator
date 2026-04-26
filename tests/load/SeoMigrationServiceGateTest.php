<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\load;

use lameco\kunstmaanmigrator\load\SeoMigrationService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Phase 4.1 / Plan 04.1-05 / Task 1 — characterization tests for the
 * Settings-disabled gate at the head of SeoMigrationService::migrateAll().
 *
 * The gate decision body is exercised end-to-end in Phase 5 / TST-02 with a
 * real Craft bootstrap; here we lock the D-27 invariant on the testable
 * helper:
 *   - the warn-line copy is exact (so REPORT.md aggregation can str_contains it)
 *   - it is distinct from the existing 'plugin not installed' copy
 *
 * Mirrors the Reflection-on-private-helper pattern from Phase 4 / Plan 12
 * (AssetMigrationServiceRcaTest).
 */
final class SeoMigrationServiceGateTest extends TestCase
{
    public function testDisabledWarnLineCopyMatchesD27(): void
    {
        $rm = new ReflectionMethod(SeoMigrationService::class, 'disabledWarnLine');
        self::assertSame(
            'SEO adapter disabled (explicitly via Settings::seoEnabled); SEO migration skipped.',
            $rm->invoke(null),
        );
    }

    public function testDisabledWarnLineIsDistinctFromPluginAbsentCopy(): void
    {
        $rm = new ReflectionMethod(SeoMigrationService::class, 'disabledWarnLine');
        $line = (string) $rm->invoke(null);
        self::assertStringContainsString('explicitly via Settings::seoEnabled', $line);
        // The plugin-not-installed copy says "SEOmatic plugin not installed; SEO migration skipped."
        self::assertStringNotContainsString('plugin not installed', $line);
    }

    public function testDisabledWarnLineIsRecognisableForSkippedStagesAggregation(): void
    {
        // D-27 + D-43: REPORT.md skipped-stages aggregation matches via str_contains
        // on a stable substring; lock that the 'SEO adapter disabled' substring
        // (which a future MigrateController aggregator can match on) is present.
        $rm = new ReflectionMethod(SeoMigrationService::class, 'disabledWarnLine');
        $line = (string) $rm->invoke(null);
        self::assertStringContainsString('SEO adapter disabled', $line);
    }
}
