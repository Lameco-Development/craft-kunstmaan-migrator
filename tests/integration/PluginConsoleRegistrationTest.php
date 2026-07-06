<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\integration;

use lameco\kunstmaanmigrator\Plugin;
use lameco\kunstmaanmigrator\console\DoctorController;
use lameco\kunstmaanmigrator\console\LoadController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * v2 loader prune — console-surface contract. This suite does not bootstrap
 * a Craft web application, so these tests inspect Plugin.php source instead.
 *
 * The CP Utility (KunstmaanMappingUtility) and its console-shell templates
 * are removed along with src/utilities/ and templates/ — the v2 loader core
 * has no CP surface at all. `doctor` and `load` are the only console
 * commands (Task 3 replaces `migrate` with the payload-driven `load/entry`
 * command and deletes MigrateController entirely).
 */
final class PluginConsoleRegistrationTest extends TestCase
{
    public function testNoCpSurfaceIsRegistered(): void
    {
        $source = $this->pluginSource();

        self::assertStringContainsString(
            'public bool $hasCpSettings = false',
            $source,
            'v2 loader core has no CP settings page.',
        );
        self::assertStringNotContainsString(
            'Utilities::EVENT_REGISTER_UTILITIES',
            $source,
            'The CP Utility (KunstmaanMappingUtility) is removed — no CP Utility event registration should remain.',
        );
        self::assertStringNotContainsString(
            'EVENT_REGISTER_CP_TEMPLATE_ROOTS',
            $source,
            'templates/ is removed — no CP template roots should be registered.',
        );
        self::assertStringNotContainsString(
            'EVENT_REGISTER_CP_NAV_ITEMS',
            $source,
            'The v2 loader core must not register a top-level CP nav item.',
        );
        self::assertStringNotContainsString(
            'Cp::EVENT_REGISTER_CP_NAV_ITEMS',
            $source,
            'The v2 loader core must not register a Craft CP section/nav event.',
        );
    }

    public function testConsoleRequestsGetTheConsoleControllerNamespace(): void
    {
        $source = $this->pluginSource();

        self::assertStringContainsString(
            "\$this->controllerNamespace = 'lameco\\\\kunstmaanmigrator\\\\console'",
            $source,
            'Console requests must resolve controllers under lameco\\kunstmaanmigrator\\console.',
        );
    }

    public function testDoctorAndLoadConsoleCommandsAreLoadable(): void
    {
        self::assertTrue(class_exists(DoctorController::class, true), 'DoctorController must autoload via PSR-4');
        self::assertTrue(class_exists(LoadController::class, true), 'LoadController must autoload via PSR-4');
    }

    private function pluginSource(): string
    {
        return (string) file_get_contents((new ReflectionClass(Plugin::class))->getFileName());
    }
}
