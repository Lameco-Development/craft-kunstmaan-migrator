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

    /**
     * The operator's switch, wherever it lives.
     *
     * This used to be `property_exists($settings, $flag)` and nothing else,
     * which meant an adapter whose flag Settings does not literally declare was
     * gated **permanently off** — and told the operator they had disabled it via
     * a property that does not exist. Since only the four built-ins have literal
     * properties, every registered adapter was in that state: it rendered a row
     * on the settings screen, resolved to a runnable service, and could never
     * run. The registry's promise stopped one method short of true.
     *
     * The four built-in flags keep their properties, so nothing about them
     * changes. Anything else reads from the generic bag, defaulting to on —
     * registering an adapter is the act of asking for it, and an adapter that
     * ships disabled by an accident of storage is the bug this replaces.
     */
    private function isEnabled(Adapter $adapter): bool
    {
        $flag = $adapter->settingsFlag;

        if (property_exists($this->settings, $flag)) {
            return (bool) $this->settings->$flag;
        }

        $stored = $this->settings->adapters[$adapter->handle][$flag] ?? null;

        return $stored === null ? true : (bool) $stored;
    }
}
