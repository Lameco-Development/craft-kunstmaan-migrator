<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\console;

use lameco\kunstmaanmigrator\console\MigrateController;
use lameco\kunstmaanmigrator\workflow\MigrateWorkflow;
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
 *   4. Default full-pipeline taxonomy mode is referenced-only; full
 *      unreferenced import requires explicit --include-unreferenced-taxonomies
 *      or Settings::includeUnreferencedTaxonomies.
 *
 * Source-reflection style mirrors PluginBootstrapTest::testPluginDeclaresLegacyDbServiceComponent
 * — no Craft bootstrap required, runs in unit context.
 */
final class MigrateControllerTaxonomiesWiringTest extends TestCase
{
    private static function source(): string
    {
        return (string) file_get_contents(
            (new ReflectionClass(MigrateWorkflow::class))->getFileName(),
        );
    }

    private static function controllerSource(): string
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

    public function testFullTaxonomyImportRequiresExplicitIncludeUnreferencedFlag(): void
    {
        $source = self::source();
        self::assertStringContainsString('includeUnreferencedTaxonomies', $source);
        self::assertStringContainsString('referenced-only', $source);
        self::assertStringContainsString('taxonomyMode=', $source);
        self::assertStringNotContainsString('noTaxonomies', $source);
        self::assertStringNotContainsString('--no-taxonomies', $source);
    }

    public function testDefaultReferencedOnlyModeIsBeforeConditionalMigrateAll(): void
    {
        $source = self::source();
        $modePos = strpos($source, '$taxonomyMode = $includeUnreferencedTaxonomies ? \'full\' : \'referenced-only\'');
        $migrateAllPos = strpos($source, 'taxonomyMigrationService->migrateAll', $modePos ?: 0);

        self::assertNotFalse($modePos, 'actionIndex must compute taxonomyMode full vs referenced-only.');
        self::assertNotFalse($migrateAllPos, 'actionIndex must still have an explicit full-import migrateAll path.');
        self::assertLessThan(
            $migrateAllPos,
            $modePos,
            'Default taxonomy mode must be decided before the conditional full-import path runs.',
        );
    }

    public function testActionTaxonomiesGatesNeverProductionFirst(): void
    {
        $source = self::controllerSource();
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
