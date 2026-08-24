<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator;

use Craft;
use craft\base\PluginInterface;

/**
 * Presence checks for the optional adapter plugins (runtime detection is a
 * locked decision — these never move to composer require). The constants are
 * the point: handles are easy to typo (`embeddedassets` has no hyphen per the
 * plugin's composer.json — a hyphenated probe shipped broken once) and a
 * mistyped handle fails silently as "not installed".
 */
final class OptionalPlugins
{
    public const SEOMATIC = 'seomatic';
    public const RETOUR = 'retour';
    public const NAVIGATION = 'navigation';
    public const EMBEDDED_ASSETS = 'embeddedassets';
    public const ENUPAL_TRANSLATE = 'enupal-translate';

    public static function has(string $handle): bool
    {
        return self::get($handle) !== null;
    }

    public static function get(string $handle): ?PluginInterface
    {
        return Craft::$app->getPlugins()->getPlugin($handle);
    }
}
