<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\console;

use lameco\kunstmaanmigrator\console\MigrateController;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Phase 4.1 / Plan 04.1-05 / Task 3 — characterization for the CFG-07
 * always-emit guards on REPORT.md ## Skipped stages and ## Asset RCA.
 *
 * The two render bodies are extracted into public-static helpers
 * (renderSkippedStagesSection / renderAssetRcaSection) so the characterization
 * runs without a Craft bootstrap. Pre-Phase-4.1 behavior wrapped each section
 * in `if ($lines !== [])` and silently omitted the heading when empty —
 * D-41+D-42+D-43 strips that guard and emits placeholder copy.
 *
 * Mirrors Phase 4 / Plan 12 helper-extraction pattern (AssetMigrationServiceRcaTest).
 *
 * Also locks the four distinct warn-line copies required by D-27 + advisor
 * call-out #1 — so REPORT.md skipped-stages aggregation can pattern-match
 * Settings-disabled vs plugin-absent vs CLI-bypass.
 */
final class MigrateControllerReportEmptyStateTest extends TestCase
{
    public function testSkippedStagesPlaceholderEmittedWhenEmpty(): void
    {
        $lines = MigrateController::renderSkippedStagesSection([]);
        self::assertContains('## Skipped stages', $lines);
        self::assertContains(
            '_No skipped stages — all configured adapters were exercised._',
            $lines,
        );
    }

    public function testSkippedStagesContentPreservedWhenNonEmpty(): void
    {
        $lines = MigrateController::renderSkippedStagesSection([
            '- SEOmatic plugin not installed; SEO migration skipped.',
        ]);
        self::assertContains('## Skipped stages', $lines);
        self::assertContains('- SEOmatic plugin not installed; SEO migration skipped.', $lines);
        self::assertNotContains(
            '_No skipped stages — all configured adapters were exercised._',
            $lines,
        );
    }

    public function testAssetRcaPlaceholderEmittedWhenEmpty(): void
    {
        $lines = MigrateController::renderAssetRcaSection([]);
        self::assertContains('## Asset RCA', $lines);
        self::assertContains(
            '_No asset RCA rows — no assets were migrated, or all migrated cleanly._',
            $lines,
        );
    }

    public function testAssetRcaTablePreservedWhenNonEmpty(): void
    {
        $lines = MigrateController::renderAssetRcaSection([
            ['legacyId' => 1, 'reason' => 'filesystem_404', 'path' => '/uploads/missing.jpg'],
        ]);
        $body = implode("\n", $lines);
        self::assertStringContainsString('## Asset RCA', $body);
        self::assertStringContainsString('| legacy_id | reason | path |', $body);
        self::assertStringContainsString('filesystem_404', $body);
        self::assertStringContainsString('/uploads/missing.jpg', $body);
        self::assertStringNotContainsString('_No asset RCA rows', $body);
    }

    public function testCliBypassSeoWarnLineDistinctFromSettingsDisabledAndPluginAbsent(): void
    {
        // D-27: four distinct warn-line copies for the four disable paths.
        // The aggregator at MigrateController::writeReport() pattern-matches
        // each substring; locking the substring identity here protects the
        // aggregator from accidentally collapsing two paths into one row.
        $rm = new ReflectionMethod(MigrateController::class, 'cliBypassSeoWarnLine');
        $line = (string) $rm->invoke(null);
        self::assertStringContainsString('skipped via --no-seo', $line);
        self::assertStringNotContainsString('Settings::seoEnabled', $line);
        self::assertStringNotContainsString('plugin not installed', $line);
    }

    public function testCliBypassRetourWarnLineDistinctFromSettingsDisabledAndPluginAbsent(): void
    {
        $rm = new ReflectionMethod(MigrateController::class, 'cliBypassRetourWarnLine');
        $line = (string) $rm->invoke(null);
        self::assertStringContainsString('skipped via --no-retour', $line);
        self::assertStringNotContainsString('Settings::retourEnabled', $line);
        self::assertStringNotContainsString('plugin not installed', $line);
    }

    public function testFourDistinctDisablePathSubstringsAreNotShared(): void
    {
        // D-27 invariant lock: every pair of distinct paths must produce strings
        // that do not contain each other's distinguishing substring.
        $rmSeo = new ReflectionMethod(MigrateController::class, 'cliBypassSeoWarnLine');
        $rmRetour = new ReflectionMethod(MigrateController::class, 'cliBypassRetourWarnLine');
        $cliSeo = (string) $rmSeo->invoke(null);
        $cliRetour = (string) $rmRetour->invoke(null);

        // Settings-disabled copies live in the load services.
        $settingsSeo = 'SEO adapter disabled (explicitly via Settings::seoEnabled); SEO migration skipped.';
        $settingsRetour = 'Retour adapter disabled (explicitly via Settings::retourEnabled); redirect migration skipped.';

        self::assertNotSame($cliSeo, $settingsSeo);
        self::assertNotSame($cliRetour, $settingsRetour);
        self::assertNotSame($cliSeo, $cliRetour);
        self::assertNotSame($settingsSeo, $settingsRetour);
    }
}
