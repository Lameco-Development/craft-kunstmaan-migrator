<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\integration;

use lameco\kunstmaanmigrator\Plugin;
use lameco\kunstmaanmigrator\console\DoctorController;
use lameco\kunstmaanmigrator\console\LoadController;
use lameco\kunstmaanmigrator\console\StateController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * v2 loader prune — console-surface contract. This suite does not bootstrap
 * a Craft web application, so these tests inspect Plugin.php / composer.json
 * source instead.
 *
 * The CP Utility (KunstmaanMappingUtility) and its console-shell templates
 * are removed along with src/utilities/ and templates/ — the v2 loader core
 * has no CP surface at all. `doctor`, `load`, and `state` are the only
 * console controllers, exposing exactly five commands under the `kunstmaan-migrator`
 * handle (Task 7 rename, was `kunstmaan-migrator`): `load/entry`,
 * `load/fixup`, `load/redirects`, `state/export`, `doctor`.
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
        self::assertTrue(class_exists(StateController::class, true), 'StateController must autoload via PSR-4');
    }

    /**
     * Task 7 — the Craft console command prefix is the plugin's composer
     * `extra.handle`. Asserting it directly (rather than only the derived
     * `./craft kunstmaan-migrator/...` strings elsewhere) is what would have caught
     * this task's rename at the source.
     */
    public function testComposerExtraHandleAndNameAreKumaLoader(): void
    {
        $composer = $this->composerManifest();

        self::assertSame(
            'kunstmaan-migrator',
            $composer['extra']['handle'] ?? null,
            'Plugin handle drives the console command prefix — must be kunstmaan-migrator, not kunstmaan-migrator.',
        );
        self::assertSame(
            'Kunstmaan Migrator',
            $composer['extra']['name'] ?? null,
            'Plugin display name must be Kunstmaan Migrator.',
        );
        self::assertSame(
            'lameco/craft-kunstmaan-migrator',
            $composer['name'] ?? null,
            'Composer package name is unaffected by the handle rename — it stays lameco/craft-kunstmaan-migrator.',
        );
    }

    /**
     * Task 7 — the v2 loader core exposes exactly five console commands:
     * kunstmaan-migrator/load/entry, kunstmaan-migrator/load/fixup,
     * kunstmaan-migrator/load/redirects, kunstmaan-migrator/state/export, kunstmaan-migrator/doctor.
     * Enumerating every public actionXxx() method declared directly on the
     * three controllers (excluding inherited ones) locks that count so a
     * future addition/removal has to update this test deliberately.
     */
    public function testExactlyFiveConsoleActionsAcrossLoadStateAndDoctor(): void
    {
        $actions = [];
        foreach ([DoctorController::class, LoadController::class, StateController::class] as $fqcn) {
            $reflection = new ReflectionClass($fqcn);
            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() !== $fqcn) {
                    continue;
                }
                if (str_starts_with($method->getName(), 'action')) {
                    $actions[] = $fqcn . '::' . $method->getName();
                }
            }
        }
        sort($actions);

        self::assertSame(
            [
                DoctorController::class . '::actionIndex',
                LoadController::class . '::actionEntry',
                LoadController::class . '::actionFixup',
                LoadController::class . '::actionRedirects',
                StateController::class . '::actionExport',
            ],
            $actions,
            'Expected exactly the five kunstmaan-migrator commands: doctor, load/entry, load/fixup, load/redirects, state/export.',
        );
    }

    private function pluginSource(): string
    {
        return (string) file_get_contents((new ReflectionClass(Plugin::class))->getFileName());
    }

    /**
     * @return array<string, mixed>
     */
    private function composerManifest(): array
    {
        $path = dirname(__DIR__, 2) . '/composer.json';
        $decoded = json_decode((string) file_get_contents($path), true);

        self::assertIsArray($decoded, 'composer.json must decode to an array');

        return $decoded;
    }
}
