<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\controllers;

use lameco\kunstmaanmigrator\adapters\AdapterRegistry;
use lameco\kunstmaanmigrator\controllers\SettingsController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The joins between the settings template and the code behind it.
 *
 * The template needs a booted control panel to render, so none of it runs
 * here. What does run is the handful of string couplings that fail silently:
 * a button posting to an action that does not exist, or a form field named
 * after a setting that was renamed. Both look fine until someone opens the
 * screen.
 */
final class SettingsSurfaceTest extends TestCase
{
    private function template(): string
    {
        $path = dirname(__DIR__, 3) . '/src/templates/settings.twig';
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function testTheTestConnectionButtonPostsToAnActionThatExists(): void
    {
        preg_match("~sendActionRequest\('POST', '([^']+)'~", $this->template(), $matches);

        self::assertNotEmpty($matches, 'the template must post to a named action');
        self::assertSame('kunstmaan-migrator/settings/test-connection', $matches[1]);

        self::assertTrue(
            (new ReflectionClass(SettingsController::class))->hasMethod('actionTestConnection'),
            'kunstmaan-migrator/settings/test-connection resolves to SettingsController::actionTestConnection',
        );
    }

    /**
     * The controller namespace for web requests has to point at the directory
     * this controller lives in, or the action 404s with no other symptom.
     */
    public function testTheControllerIsWhereTheWebNamespacePoints(): void
    {
        $plugin = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Plugin.php');

        self::assertStringContainsString("'lameco\\\\kunstmaanmigrator\\\\controllers'", $plugin);
        self::assertSame(
            'lameco\kunstmaanmigrator\controllers',
            (new ReflectionClass(SettingsController::class))->getNamespaceName(),
        );
    }

    public function testEveryAdapterSwitchInTheTemplateIsDrivenByTheRegistry(): void
    {
        $template = $this->template();

        // The row is rendered from the registry rather than hard-coded, so the
        // template must not name any individual adapter's flag.
        foreach ((new AdapterRegistry())->all() as $adapter) {
            self::assertStringNotContainsString(
                "name: '" . $adapter->settingsFlag . "'",
                $template,
                sprintf(
                    'The adapter table hard-codes %s; a project registering its own adapter would get no row.',
                    $adapter->settingsFlag,
                ),
            );
        }

        self::assertStringContainsString('adapter.settingsFlag', $template);
    }

    public function testTheCredentialFieldsOfferEnvironmentVariables(): void
    {
        $template = $this->template();

        foreach (['legacyDbServer', 'legacyDbDatabase', 'legacyDbUser', 'legacyDbPassword'] as $field) {
            $offset = strpos($template, "id: '" . $field . "'");
            self::assertNotFalse($offset, sprintf('%s is missing from the settings screen', $field));

            $block = substr($template, max(0, $offset - 200), 400);
            self::assertStringContainsString(
                'suggestEnvVars: true',
                $block,
                sprintf('%s must take an environment variable name, not a value', $field),
            );
        }
    }

    public function testTheCpSettingsScreenIsTurnedOn(): void
    {
        $plugin = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Plugin.php');

        self::assertStringContainsString('public bool $hasCpSettings = true;', $plugin);
    }
}
