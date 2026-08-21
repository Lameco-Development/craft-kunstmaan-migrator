<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\craft;

/**
 * What third-party plugins are present.
 *
 * The third seam, and the one that makes the adapter gate testable at all.
 * The gate's whole job is a decision about installed plugins, and while that
 * decision read `Craft::$app->plugins` directly it could not be reached from a
 * test — which is why four modules ended up testing a warning string through
 * Reflection instead of testing the gate.
 */
interface PluginRegistry
{
    public function isInstalled(string $handle): bool;

    /**
     * The installed version, or null when the plugin is absent. Nothing gates
     * on this yet; the settings screen shows it, and a version constraint on
     * an adapter is the obvious next use.
     */
    public function versionOf(string $handle): ?string;
}
