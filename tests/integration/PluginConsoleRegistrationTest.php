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
 * The v2 prune removed the CP surface entirely. A settings screen has since
 * come back deliberately, so the plugin's own settings — legacy database,
 * adapter switches, table names — are reachable without editing a config file.
 * What has NOT come back is a top-level section: a tool used a handful of times
 * per project has no business sitting in the nav beside Entries, so these tests
 * still hold that line.
 *
 * `doctor`, `load`, and `state` remain the only console controllers, exposing
 * exactly five commands under the `kunstmaan-migrator` handle.
 */
final class PluginConsoleRegistrationTest extends TestCase
{
    public function testTheSettingsScreenIsRegistered(): void
    {
        $source = $this->pluginSource();

        self::assertStringContainsString(
            'public bool $hasCpSettings = true',
            $source,
            'The settings screen is how the legacy database and adapter switches are reached.',
        );
        self::assertStringContainsString(
            'protected function settingsHtml()',
            $source,
            'hasCpSettings without settingsHtml() renders an empty pane.',
        );
    }

    public function testNoTopLevelCpSectionIsRegistered(): void
    {
        $source = $this->pluginSource();

        self::assertStringNotContainsString(
            'EVENT_REGISTER_CP_TEMPLATE_ROOTS',
            $source,
            'The settings template resolves under the plugin handle; no extra CP template root is needed.',
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
            "'lameco\\\\kunstmaanmigrator\\\\console'",
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
     * Task 7 — the v2 loader core exposes exactly six console commands:
     * kunstmaan-migrator/load/entry, kunstmaan-migrator/load/fixup,
     * kunstmaan-migrator/load/redirects, kunstmaan-migrator/state/export,
     * kunstmaan-migrator/state/diff, kunstmaan-migrator/doctor.
     * Enumerating every public actionXxx() method declared directly on the
     * three controllers (excluding inherited ones) locks that count so a
     * future addition/removal has to update this test deliberately.
     */
    public function testExactlySixConsoleActionsAcrossLoadStateAndDoctor(): void
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
                StateController::class . '::actionDiff',
                StateController::class . '::actionExport',
            ],
            $actions,
            'Expected exactly the six kunstmaan-migrator commands: doctor, load/entry, load/fixup, load/redirects, state/diff, state/export.',
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
