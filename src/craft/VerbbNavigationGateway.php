<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\craft;

use Craft;
use verbb\navigation\elements\Node as NavNode;
use verbb\navigation\Navigation;

/**
 * The production adapter over verbb/navigation.
 *
 * Deliberately thin, for the same reason CraftElementWriter is: every line of
 * behaviour here is a line no test can reach.
 */
final class VerbbNavigationGateway implements NavigationGateway
{
    public function isAvailable(): bool
    {
        return Craft::$app->plugins->getPlugin('navigation') !== null
            && class_exists(Navigation::class)
            && Navigation::$plugin !== null;
    }

    public function navIdByHandle(string $handle): ?int
    {
        $nav = Navigation::$plugin->getNavs()->getNavByHandle($handle);

        return $nav?->id === null ? null : (int) $nav->id;
    }

    /** @param list<NavNode> $nodes */
    public function registerTempNodes(array $nodes): void
    {
        Navigation::$plugin->getNodes()->setTempNodes($nodes);
    }
}
