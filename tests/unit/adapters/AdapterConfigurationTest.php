<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\adapters;

use Lameco\Kunstmaanmigrator\adapters\Adapter;
use Lameco\Kunstmaanmigrator\adapters\AdapterRegistry;
use Lameco\Kunstmaanmigrator\adapters\AdapterSetting;
use Lameco\Kunstmaanmigrator\tests\support\SettingsFactory;
use PHPUnit\Framework\TestCase;

/**
 * An adapter owns its preferences.
 *
 * They used to be literal properties on the global Settings model, which meant
 * configuring an adapter required editing a class it does not own — so an
 * adapter a project ships could not be configured at all, and the four built-in
 * ones had their most project-specific values (the target nav, the translation
 * domains) invisible on the settings screen and editable only from PHP.
 */
final class AdapterConfigurationTest extends TestCase
{
    private function adapter(AdapterSetting ...$settings): Adapter
    {
        return new Adapter('acme', 'Acme', 'acmeEnabled', null, null, $settings);
    }

    public function testADeclaredDefaultIsUsedWhenNothingIsStored(): void
    {
        $adapter = $this->adapter(
            new AdapterSetting('navHandle', 'Nav', AdapterSetting::TYPE_STRING, 'footerMain'),
        );

        self::assertSame(['navHandle' => 'footerMain'], SettingsFactory::make()->forAdapter($adapter));
    }

    public function testAStoredValueBeatsTheDefault(): void
    {
        $adapter = $this->adapter(
            new AdapterSetting('navHandle', 'Nav', AdapterSetting::TYPE_STRING, 'footerMain'),
        );

        $settings = SettingsFactory::make();
        $settings->adapters = ['acme' => ['navHandle' => 'footerBottom']];

        self::assertSame(['navHandle' => 'footerBottom'], $settings->forAdapter($adapter));
    }

    /**
     * A project already configured through config/kunstmaan-migrator.php must
     * keep working. Only a property the operator actually changed counts —
     * otherwise a legacy default would silently outrank the declared one.
     */
    public function testACustomisedLegacyPropertyStillWins(): void
    {
        $adapter = $this->adapter(new AdapterSetting(
            'navHandle',
            'Nav',
            AdapterSetting::TYPE_STRING,
            'headerMain',
            legacyProperty: 'nodeMenuNavHandle',
        ));

        $settings = SettingsFactory::make();
        $settings->nodeMenuNavHandle = 'somethingElse';

        self::assertSame('somethingElse', $settings->forAdapter($adapter)['navHandle']);
    }

    public function testAnUntouchedLegacyPropertyDoesNotOutrankTheDeclaredDefault(): void
    {
        $adapter = $this->adapter(new AdapterSetting(
            'domains',
            'Domains',
            AdapterSetting::TYPE_LIST,
            ['messages', 'validators'],
            legacyProperty: 'translationDomains',
        ));

        self::assertSame(['messages', 'validators'], SettingsFactory::make()->forAdapter($adapter)['domains']);
    }

    public function testAListAcceptsACommaSeparatedStringFromAFormField(): void
    {
        $adapter = $this->adapter(
            new AdapterSetting('excluded', 'Excluded', AdapterSetting::TYPE_LIST, ['settings']),
        );

        $settings = SettingsFactory::make();
        $settings->adapters = ['acme' => ['excluded' => 'settings, dienst , ']];

        self::assertSame(['settings', 'dienst'], $settings->forAdapter($adapter)['excluded']);
    }

    /**
     * The values that used to be hard-coded on Settings, now declared by the
     * adapters that use them — and therefore reachable from the settings screen.
     */
    public function testTheBuiltInAdaptersDeclareTheirProjectSpecificValues(): void
    {
        $registry = new AdapterRegistry();

        $navigation = $registry->byHandle('navigation');
        self::assertNotNull($navigation);
        self::assertSame(
            ['navHandle', 'excludedInternalNames'],
            array_map(static fn(AdapterSetting $s): string => $s->handle, $navigation->settings),
        );

        $translations = $registry->byHandle('translations');
        self::assertNotNull($translations);
        self::assertSame(
            ['domains'],
            array_map(static fn(AdapterSetting $s): string => $s->handle, $translations->settings),
        );
    }

    /**
     * The screen and the run must resolve the switch the same way. Showing "on"
     * while the gate treats it as off is the failure mode this pins.
     */
    public function testTheSwitchResolvesTheSameWayForTheScreenAndTheGate(): void
    {
        $settings = SettingsFactory::make();
        $acme = $this->adapter();

        self::assertTrue($settings->isAdapterEnabled($acme));
        self::assertSame('adapters[acme][acmeEnabled]', $settings->adapterEnabledInputName($acme));

        $settings->adapters = ['acme' => ['acmeEnabled' => false]];
        self::assertFalse($settings->isAdapterEnabled($acme));

        $seo = (new AdapterRegistry())->byHandle('seo');
        self::assertNotNull($seo);
        self::assertSame('seoEnabled', $settings->adapterEnabledInputName($seo));
    }
}
