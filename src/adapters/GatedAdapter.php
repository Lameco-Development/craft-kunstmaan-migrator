<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\adapters;

use Lameco\Kunstmaanmigrator\craft\CraftPluginRegistry;
use Lameco\Kunstmaanmigrator\load\MigrationReport;
use Lameco\Kunstmaanmigrator\Plugin;

/**
 * The gate prologue every adapter opens with, written once.
 *
 * Each of the four carried its own copy: a nullable `$adapterGate`, a `gate()`
 * that lazily built one, and five lines resolving its own handle out of a
 * freshly constructed registry. The lazy construction is what makes the
 * property injection in Plugin::init() optional rather than load-bearing, so
 * it stays — the duplication does not.
 */
trait GatedAdapter
{
    /**
     * Wired in Plugin::init(); read through gate() so no call site has to cope
     * with "not wired yet".
     */
    public ?AdapterGate $adapterGate = null;

    private ?AdapterRegistry $adapterRegistry = null;

    /**
     * This adapter's own preferences, resolved.
     *
     * Read here rather than patched on from Plugin::init(), which is what made
     * an adapter's configuration something only Plugin could supply — and
     * therefore something a project's own adapter could not have at all.
     *
     * @return array<string, mixed>
     */
    protected function config(): array
    {
        $registry = $this->adapterRegistry ??= new AdapterRegistry();
        $adapter = $registry->byHandle($this->handle());

        if ($adapter === null) {
            return [];
        }

        return Plugin::getInstance()->getSettings()->forAdapter($adapter);
    }

    private function gate(): AdapterGate
    {
        return $this->adapterGate ??= new AdapterGate(
            new CraftPluginRegistry(),
            Plugin::getInstance()->getSettings(),
        );
    }

    /**
     * True when this adapter may run. A refusal writes its own reason into the
     * report — "you turned this off" and "the plugin is missing" are different
     * things to be told, which is the distinction GateStatus exists to keep.
     */
    private function isGateOpen(MigrationReport $report): bool
    {
        $registry = $this->adapterRegistry ??= new AdapterRegistry();
        $adapter = $registry->byHandle($this->handle());

        if ($adapter === null) {
            $report->warn(sprintf('No adapter is registered under "%s"; pass skipped.', $this->handle()));

            return false;
        }

        $gate = $this->gate()->check($adapter);

        if ($gate->isReady()) {
            return true;
        }

        $report->warn((string) $gate->reason());

        return false;
    }
}
