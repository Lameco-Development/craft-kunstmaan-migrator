<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\integration;

use lameco\kunstmaanmigrator\Plugin;
use lameco\kunstmaanmigrator\utilities\KunstmaanMappingUtility;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Phase 12 CP console registration contract.
 *
 * These tests intentionally inspect Plugin.php source because this suite does
 * not bootstrap a Craft web application. The goal is to lock the CP surface:
 * keep the existing Utility registration, wire Phase 12 siblings, and never
 * add a top-level CP nav section for the migration console.
 */
final class PluginConsoleRegistrationTest extends TestCase
{
    public function testUtilityRegistrationRemainsTheOnlyCpEntryPoint(): void
    {
        $source = $this->pluginSource();

        self::assertStringContainsString(
            'Utilities::EVENT_REGISTER_UTILITIES',
            $source,
            'Plugin::init() must keep registering the Craft Utility event.',
        );
        self::assertStringContainsString(
            'KunstmaanMappingUtility::class',
            $source,
            'KunstmaanMappingUtility must remain the registered CP Utility.',
        );
        self::assertStringNotContainsString(
            'EVENT_REGISTER_CP_NAV_ITEMS',
            $source,
            'Phase 12 must not register a top-level CP nav item.',
        );
        self::assertStringNotContainsString(
            'Cp::EVENT_REGISTER_CP_NAV_ITEMS',
            $source,
            'Phase 12 must not register a Craft CP section/nav event.',
        );
    }

    public function testUtilityRendersMigrationConsoleShell(): void
    {
        $utilitySource = (string) file_get_contents((new ReflectionClass(KunstmaanMappingUtility::class))->getFileName());
        $templateSource = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/_console/index.twig');

        self::assertStringContainsString('Kunstmaan Migration Console', $utilitySource);
        self::assertStringContainsString('shuffle', $utilitySource);
        self::assertStringContainsString('_console/index', $utilitySource);
        self::assertStringContainsString('MigrationConsoleController::utilityVariables', $utilitySource);
        self::assertStringContainsString('View::TEMPLATE_MODE_CP', $utilitySource);
        self::assertStringContainsString('Kunstmaan Migration Console', $templateSource);
        self::assertStringContainsString("{% include 'kunstmaan-migrator/_console/_tabs' ignore missing", $templateSource);
        self::assertStringContainsString('CLI remains the canonical workflow.', $templateSource);
    }

    public function testMigrationGateServiceSiblingDependenciesAreWired(): void
    {
        $source = $this->pluginSource();

        foreach ([
            'migrationGateService->migrationRunService = $this->migrationRunService',
            'migrationGateService->mappingFile = $this->mappingFile',
            'migrationGateService->settings = $settings',
            'migrationGateService->migrationSafety = $this->migrationSafety',
        ] as $wire) {
            self::assertStringContainsString(
                $wire,
                $source,
                "Plugin::init() must wire {$wire}.",
            );
        }
    }

    private function pluginSource(): string
    {
        return (string) file_get_contents((new ReflectionClass(Plugin::class))->getFileName());
    }
}
