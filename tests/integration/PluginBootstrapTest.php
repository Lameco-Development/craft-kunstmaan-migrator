<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\integration;

use lameco\kunstmaanmigrator\Plugin;
use lameco\kunstmaanmigrator\db\LegacyDbService;
use lameco\kunstmaanmigrator\models\Settings;
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

    public function testPluginSchemaVersionMatchesComposerJson(): void
    {
        $pluginSource = (string) file_get_contents((new ReflectionClass(Plugin::class))->getFileName());
        $composerSource = (string) file_get_contents(dirname(__DIR__, 2) . '/composer.json');

        self::assertStringContainsString("public string \$schemaVersion = '1.1.0'", $pluginSource);
        self::assertStringContainsString('"schemaVersion": "1.1.0"', $composerSource);
    }
}
