<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\adapters;

use lameco\kunstmaanmigrator\craft\PluginRegistry;
use lameco\kunstmaanmigrator\models\Settings;

/**
 * Decides whether one adapter runs.
 *
 * Order matters and is deliberate: the operator's switch is checked before the
 * plugin is looked for. Someone who has turned SEO off should be told they
 * turned it off, not that SEOmatic is missing — the second is true but it is
 * not the reason, and acting on it wastes an afternoon installing a plugin
 * that was never the problem.
 */
final class AdapterGate
{
    public function __construct(
        private readonly PluginRegistry $plugins,
        private readonly Settings $settings,
    ) {
    }

    public function check(Adapter $adapter): GateResult
    {
        if (!$this->isEnabled($adapter)) {
            return GateResult::disabledByOperator($adapter);
        }

        if ($adapter->pluginHandle !== null && !$this->plugins->isInstalled($adapter->pluginHandle)) {
            return GateResult::pluginMissing($adapter);
        }

        return GateResult::ready($adapter);
    }

    private function isEnabled(Adapter $adapter): bool
    {
        $flag = $adapter->settingsFlag;

        // An adapter naming a property Settings does not have is a wiring
        // mistake, not an operator choice — treat it as off rather than
        // silently running something nobody asked for.
        if (!property_exists($this->settings, $flag)) {
            return false;
        }

        return (bool) $this->settings->$flag;
    }
}
