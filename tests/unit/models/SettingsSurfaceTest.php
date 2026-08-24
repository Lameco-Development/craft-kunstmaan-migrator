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

        // Both the state and the field name are resolved by Settings, using the
        // same rule AdapterGate applies — the screen showing "on" while the run
        // treats it as off is the failure this replaces.
        self::assertStringContainsString('settings.isAdapterEnabled(adapter)', $template);
        self::assertStringContainsString('settings.adapterEnabledInputName(adapter)', $template);
    }

    /**
     * An adapter's own preferences are rendered from what it declares. They used
     * to be literal Settings properties and fields typed into the template, so a
     * project's own adapter could be neither configured nor shown — and the nav
     * the navigation pass writes into, the most project-specific value in the
     * plugin, was reachable only from a PHP config file.
     */
    public function testAdapterPreferencesAreRenderedFromTheirDeclarations(): void
    {
        $template = $this->template();

        self::assertStringContainsString('settings.forAdapter(adapter)', $template);
        self::assertStringContainsString('for setting in adapter.settings', $template);

        foreach ((new AdapterRegistry())->all() as $adapter) {
            foreach ($adapter->settings as $setting) {
                self::assertStringNotContainsString(
                    "name: '" . $setting->handle . "'",
                    $template,
                    sprintf('%s.%s is hard-coded in the template', $adapter->handle, $setting->handle),
                );
            }
        }
    }

    /**
     * 2026-08-23 — the connection, the mapping path and asset placement moved
     * out of the form entirely: machine-local values and one-time operator
     * decisions live in config/kunstmaan-migrator.php + .env, and the screen
     * states what is in force instead of offering to edit it.
     */
    public function testMachineLevelValuesAreStatedNotOffered(): void
    {
        $template = $this->template();

        foreach (['legacyDbServer', 'legacyDbUser', 'legacyDbPassword', 'legacyMediaRoot', "id: 'mappingPath'", "id: 'specsPath'", 'coreSettings'] as $removed) {
            self::assertStringNotContainsString($removed, $template);
        }

        self::assertStringContainsString('config/kunstmaan-migrator.php', $template);
        self::assertStringContainsString('for row in effective', $template);
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
     * uploads live, which locale writes to which site. The Mapping screen and
     * the wizard show it where it is edited; the settings screen no longer
     * mirrors it at all.
     */
    public function testTheEnvironmentsAreNotOnTheSettingsScreen(): void
    {
        $template = $this->template();

        self::assertStringNotContainsString('for name, env in environments', $template);
        self::assertStringNotContainsString('"Environments"', $template);
    }
}
