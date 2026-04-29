<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\integration;

use lameco\kunstmaanmigrator\Plugin;
use lameco\kunstmaanmigrator\db\LegacyDbService;
use lameco\kunstmaanmigrator\models\Settings;
use lameco\kunstmaanmigrator\runs\MigrationRunService;
use lameco\kunstmaanmigrator\safety\MigrationGateService;
use lameco\kunstmaanmigrator\safety\MigrationSafety;
use lameco\kunstmaanmigrator\workflow\AnalyzeWorkflow;
use lameco\kunstmaanmigrator\workflow\CompileWorkflow;
use lameco\kunstmaanmigrator\workflow\MigrateWorkflow;
use lameco\kunstmaanmigrator\workflow\VerifyWorkflow;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * D-21: non-empty smoke test on day one. Asserts the Plugin entrypoint and the
 * Phase 1 service / model classes autoload via PSR-4, and that the Plugin's
 * config() declares the legacyDbService component.
 *
 * Full Craft-bootstrapped tests are deliberately deferred — half-bootstrapping
 * Craft from a unit-test context is a known dead end (see v1's
 * testFullCircularDiCheckIsDeferredToConsumerRehearsal pattern). Phase 5 adds
 * the plugin-load smoke test in a real Craft install (TST-03).
 */
final class PluginBootstrapTest extends TestCase
{
    public function testPluginClassIsLoadable(): void
    {
        self::assertTrue(class_exists(Plugin::class, true), 'Plugin must autoload via PSR-4');
    }

    public function testKeyServiceClassesAreLoadable(): void
    {
        $missing = [];
        foreach ([LegacyDbService::class, Settings::class] as $fqcn) {
            if (!class_exists($fqcn, true)) {
                $missing[] = $fqcn;
            }
        }
        self::assertSame([], $missing, 'Key Phase 1 service / model classes must autoload');
    }

    public function testPluginDeclaresLegacyDbServiceComponent(): void
    {
        // Source-level reflection — no Craft container in unit context.
        // We assert the literal config() declaration so a refactor that drops
        // the legacyDbService component fails this test loudly.
        $source = (string) file_get_contents((new ReflectionClass(Plugin::class))->getFileName());
        self::assertStringContainsString(
            "'legacyDbService' => LegacyDbService::class",
            $source,
            'Plugin::config() must declare legacyDbService component',
        );
    }

    /**
     * Phase 8 / Plan 12 / TAX-08 — Plugin DI must register the
     * TaxonomyMigrationService component slot, fan its three sibling deps
     * (legacyDb / migrationState / mappingFile) at init() time, and expose
     * the @property-read docblock so static analyzers and the
     * `Plugin::getInstance()->taxonomyMigrationService` call site type-check.
     */
    public function testPluginDeclaresTaxonomyMigrationServiceWiring(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(Plugin::class))->getFileName());

        // Component slot.
        self::assertStringContainsString(
            "'taxonomyMigrationService'",
            $source,
            'Plugin::config() must declare taxonomyMigrationService component slot',
        );
        self::assertMatchesRegularExpression(
            "/'taxonomyMigrationService'\s*=>\s*TaxonomyMigrationService::class/",
            $source,
            'taxonomyMigrationService component must map to TaxonomyMigrationService::class',
        );

        // DI fanout — three public sibling slots wired in init().
        self::assertStringContainsString(
            'taxonomyMigrationService->legacyDb',
            $source,
            'Plugin::init() must fan legacyDb into taxonomyMigrationService',
        );
        self::assertStringContainsString(
            'taxonomyMigrationService->migrationState',
            $source,
            'Plugin::init() must fan migrationState into taxonomyMigrationService',
        );
        self::assertStringContainsString(
            'taxonomyMigrationService->mappingFile',
            $source,
            'Plugin::init() must fan mappingFile into taxonomyMigrationService',
        );

        // @property-read docblock.
        self::assertStringContainsString(
            '@property-read TaxonomyMigrationService $taxonomyMigrationService',
            $source,
            'Plugin class header must expose @property-read TaxonomyMigrationService',
        );
    }

    /**
     * Phase 12 / Plan 07 — CP console and queued workflow services must be
     * first-class plugin components so CLI, CP, queue jobs, and gates share the
     * same runtime graph. This remains a source-level assertion because Craft is
     * not bootstrapped in the PHPUnit integration suite.
     */
    public function testPluginDeclaresPhase12ServiceComponents(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(Plugin::class))->getFileName());

        $expectedComponents = [
            'migrationRunService' => MigrationRunService::class,
            'migrationSafety' => MigrationSafety::class,
            'migrationGateService' => MigrationGateService::class,
            'analyzeWorkflow' => AnalyzeWorkflow::class,
            'compileWorkflow' => CompileWorkflow::class,
            'migrateWorkflow' => MigrateWorkflow::class,
            'verifyWorkflow' => VerifyWorkflow::class,
        ];

        foreach ($expectedComponents as $id => $fqcn) {
            $short = substr($fqcn, strrpos($fqcn, '\\') + 1);
            self::assertMatchesRegularExpression(
                "/'{$id}'\s*=>\s*{$short}::class/",
                $source,
                "{$id} component must map to {$short}::class",
            );
            self::assertStringContainsString(
                "@property-read {$short} \${$id}",
                $source,
                "Plugin class header must expose @property-read {$short} \${$id}",
            );
        }
    }
}
