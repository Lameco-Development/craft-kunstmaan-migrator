<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\craft;

use Craft;

/**
 * The production adapter: Craft's own plugin service, and nothing else.
 */
final class CraftPluginRegistry implements PluginRegistry
{
    public function isInstalled(string $handle): bool
    {
        return Craft::$app->plugins->getPlugin($handle) !== null;
    }

    public function versionOf(string $handle): ?string
    {
        return Craft::$app->plugins->getPlugin($handle)?->getVersion();
    }
}
