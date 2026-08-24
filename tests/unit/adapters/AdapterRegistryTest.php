<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\adapters;

use lameco\kunstmaanmigrator\adapters\Adapter;
use lameco\kunstmaanmigrator\adapters\AdapterRegistry;
use lameco\kunstmaanmigrator\adapters\RegisterAdaptersEvent;
use lameco\kunstmaanmigrator\models\Settings;
use lameco\kunstmaanmigrator\tests\support\SettingsFactory;
use PHPUnit\Framework\TestCase;

/**
 * The registry is the list every other surface reads: the migration decides
 * what to run from it, and the settings screen will render it rather than
 * hard-coding four checkboxes.
 */
final class AdapterRegistryTest extends TestCase
{
    public function testTheBuiltInAdaptersAreRegistered(): void
    {
        $handles = array_map(
            static fn(Adapter $a): string => $a->handle,
            (new AdapterRegistry())->all(),
        );

        self::assertSame(['seo', 'redirects', 'navigation', 'forms', 'globals', 'translations'], $handles);
    }

    public function testEverySettingsFlagAnAdapterNamesActuallyExists(): void
    {
        $settings = SettingsFactory::make();

        foreach ((new AdapterRegistry())->all() as $adapter) {
            self::assertTrue(
                property_exists($settings, $adapter->settingsFlag),
                sprintf(
                    'Adapter "%s" names Settings::%s, which does not exist — the gate would read it as off.',
                    $adapter->handle,
                    $adapter->settingsFlag,
                ),
            );
        }
    }

    public function testAdapterHandlesAreUnique(): void
    {
        $handles = array_map(
            static fn(Adapter $a): string => $a->handle,
            (new AdapterRegistry())->all(),
        );

        self::assertSame($handles, array_unique($handles));
    }

    public function testOnlyTheTranslationPassRunsWithoutAThirdPartyPlugin(): void
    {
        $withoutPlugin = array_values(array_filter(
            (new AdapterRegistry())->all(),
            static fn(Adapter $a): bool => $a->pluginHandle === null,
        ));

        self::assertCount(1, $withoutPlugin);
        self::assertSame('translations', $withoutPlugin[0]->handle);
    }

    public function testByHandleFindsAnAdapterAndReturnsNullForAnUnknownOne(): void
    {
        $registry = new AdapterRegistry();

        self::assertSame('seomatic', $registry->byHandle('seo')?->pluginHandle);
        self::assertNull($registry->byHandle('nope'));
    }

    public function testAProjectCanRegisterItsOwnAdapter(): void
    {
        $registry = new AdapterRegistry();
        $registry->on(
            AdapterRegistry::EVENT_REGISTER_ADAPTERS,
            static function(RegisterAdaptersEvent $event): void {
                $event->adapters[] = new Adapter('forms', 'Forms', 'formsEnabled', 'formie');
            },
        );

        self::assertSame('formie', $registry->byHandle('forms')?->pluginHandle);
        self::assertCount(7, $registry->all());
    }

    public function testTheListIsBuiltOncePerRegistry(): void
    {
        $registry = new AdapterRegistry();
        $calls = 0;
        $registry->on(
            AdapterRegistry::EVENT_REGISTER_ADAPTERS,
            static function() use (&$calls): void {
                $calls++;
            },
        );

        $registry->all();
        $registry->all();
        $registry->byHandle('seo');

        self::assertSame(1, $calls, 'the migration asks for this list repeatedly');
    }
}
