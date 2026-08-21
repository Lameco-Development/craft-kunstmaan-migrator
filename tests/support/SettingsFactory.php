<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\support;

use lameco\kunstmaanmigrator\models\Settings;
use ReflectionClass;

/**
 * A Settings model without a booted Craft.
 *
 * `Settings::init()` resolves `CRAFT_LEGACY_DB_*` through `App::env()` and
 * attaches EnvAttributeParserBehavior, neither of which exists in this tier.
 * Skipping the constructor still applies every declared property default,
 * which is all the adapter switches are.
 */
final class SettingsFactory
{
    /** @param array<string, mixed> $overrides */
    public static function make(array $overrides = []): Settings
    {
        $settings = (new ReflectionClass(Settings::class))->newInstanceWithoutConstructor();

        foreach ($overrides as $property => $value) {
            $settings->$property = $value;
        }

        return $settings;
    }
}
