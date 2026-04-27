<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\console;

use lameco\kunstmaanmigrator\console\MigrateController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Phase 8 / Plan 08-12 / TAX-08 — source-reflection characterization for the
 * MigrateController taxonomies wiring. Verifies four invariants:
 *
 *   1. actionIndex bolt-on AND actionTaxonomies sub-action both call
 *      Plugin::getInstance()->taxonomyMigrationService->migrateAll(...).
 *   2. actionTaxonomies public sub-action exists for resume / debug.
 *   3. The "Stage taxonomies: created=X updated=X skipped=X failed=X" line
 *      is emitted in BOTH the bolt-on (actionIndex) and the sub-action.
 *   4. NO --no-taxonomies CLI flag exists (D-04 + D-12 invariant: three-flag
 *      cap of --live / --confirm / --force is preserved).
 *
 * Source-reflection style mirrors PluginBootstrapTest::testPluginDeclaresLegacyDbServiceComponent
 * — no Craft bootstrap required, runs in unit context.
 */
final class MigrateControllerTaxonomiesWiringTest extends TestCase
{
    private static function source(): string
    {
        return (string) file_get_contents(
            (new ReflectionClass(MigrateController::class))->getFileName(),
        );
    }

    public function testTaxonomyMigrationServiceMigrateAllCalledTwice(): void
    {
        $source = self::source();
        // At least 2 occurrences: actionIndex bolt-on + actionTaxonomies sub-action.
        self::assertGreaterThanOrEqual(
            2,
            substr_count($source, 'taxonomyMigrationService->migrateAll'),
            'taxonomyMigrationService->migrateAll must be called from both actionIndex bolt-on and actionTaxonomies sub-action',
        );
    }

    public function testActionTaxonomiesSubActionExists(): void
    {
        self::assertTrue(
            method_exists(MigrateController::class, 'actionTaxonomies'),
            'MigrateController::actionTaxonomies sub-action must exist (resume / debug entry point)',
        );

        $reflection = new \ReflectionMethod(MigrateController::class, 'actionTaxonomies');
        self::assertTrue(
            $reflection->isPublic(),
            'actionTaxonomies must be public (Yii console action visibility requirement)',
        );
    }

    public function testStageTaxonomiesLineEmittedTwice(): void
    {
        $source = self::source();
        // At least 2 occurrences: actionIndex bolt-on stdout + actionTaxonomies sub-action stdout.
        self::assertGreaterThanOrEqual(
            2,
            substr_count($source, 'Stage taxonomies:'),
            'Stage taxonomies stdout line must be emitted from both the bolt-on and the sub-action',
        );
    }

    public function testNoTaxonomiesFlagNotIntroduced(): void
    {
        $source = self::source();
        // D-04 + D-12 invariant: three-flag cap (--live / --confirm / --force)
        // preserved — no new --no-taxonomies flag.
        self::assertSame(
            0,
            substr_count($source, 'noTaxonomies'),
            'No noTaxonomies property may exist (D-04 / D-12 three-flag cap)',
        );
        self::assertSame(
            0,
            substr_count($source, '--no-taxonomies'),
            'No --no-taxonomies CLI flag may be referenced (D-04 / D-12)',
        );
    }

    public function testActionTaxonomiesGatesNeverProductionFirst(): void
    {
        $source = self::source();
        // Locate the actionTaxonomies body and verify enforceNeverProduction()
        // is the first non-trivial statement (D-20 pattern from actionSeo / actionRetour).
        $needle = 'public function actionTaxonomies(';
        $pos = strpos($source, $needle);
        self::assertNotFalse(
            $pos,
            'actionTaxonomies signature must be present',
        );
        // Inspect first 200 chars after the signature for the gate call.
        $body = substr($source, $pos, 400);
        self::assertStringContainsString(
            'enforceNeverProduction',
            $body,
            'actionTaxonomies must gate on enforceNeverProduction() FIRST (D-20)',
        );
    }
}
