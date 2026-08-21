<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\models;

use lameco\kunstmaanmigrator\adapters\AdapterRegistry;
use PHPUnit\Framework\TestCase;

/**
 * The joins between the settings template and the code behind it.
 *
 * The template needs a booted control panel to render, so none of it runs
 * here. What does run is the handful of couplings that fail silently — a form
 * field named after a setting that was renamed, or an adapter row hard-coded
 * where it should come from the registry. Both look fine until someone opens
 * the screen.
 */
final class SettingsSurfaceTest extends TestCase
{
    private function template(): string
    {
        $path = dirname(__DIR__, 3) . '/src/templates/settings.twig';
        self::assertFileExists($path);

        return (string) file_get_contents($path);
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

    /**
     * The Kunstmaan schema is fixed, so these are constants on the services.
     * A settings surface for them was a knob nobody ever turned.
     */
    public function testLegacyTableNamesAreNotConfigurable(): void
    {
        $settings = (string) file_get_contents(dirname(__DIR__, 3) . '/src/models/Settings.php');

        foreach (['seoTableName', 'redirectsTableName', 'menuTableName', 'nodesTableName'] as $removed) {
            self::assertStringNotContainsString($removed, $settings);
            self::assertStringNotContainsString($removed, $this->template());
        }
    }

    /**
     * The mapping owns the topology — which databases exist, where each one's
     * uploads live, which locale writes to which site. The screen points at it
     * and shows it; it does not offer to edit it.
     */
    public function testTheEnvironmentsTableIsReadOnly(): void
    {
        $template = $this->template();

        self::assertStringContainsString("id: 'mappingPath'", $template);
        self::assertStringContainsString('for name, env in environments', $template);

        $start = strpos($template, '<h2>{{ "Environments"');
        self::assertNotFalse($start);
        $section = substr($template, $start);

        foreach (['forms.textField', 'forms.lightswitch', 'forms.autosuggestField({\n  label: "Database'] as $editable) {
            self::assertStringNotContainsString(
                $editable,
                substr($section, strpos($section, '<table')),
                'the environments table must not offer editable fields',
            );
        }
    }

    public function testTheCpSettingsScreenIsTurnedOn(): void
    {
        $plugin = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Plugin.php');

        self::assertStringContainsString('public bool $hasCpSettings = true;', $plugin);
    }
}
