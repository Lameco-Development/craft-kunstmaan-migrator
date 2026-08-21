<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\adapters;

/**
 * What one migration adapter needs in order to run.
 *
 * Four modules used to answer these questions in their own words — is the
 * operator's switch on, is the third-party plugin installed, and what do we
 * say when it isn't. Each answered them with about twenty lines of prologue
 * and a bespoke warn-line helper. Declaring the answers instead means the gate
 * can be one module, and it means something other than the migration itself
 * can ask: the settings screen renders this list rather than hard-coding four
 * checkboxes.
 */
final class Adapter
{
    /**
     * @param string      $handle       stable key, e.g. 'seo'
     * @param string      $label        what an operator calls it, e.g. 'SEO'
     * @param string      $settingsFlag the Settings property carrying the switch
     * @param string|null $pluginHandle the third-party plugin required, or null
     *                                  when the adapter is built in — the
     *                                  translation pass writes Craft's own
     *                                  catalogs and needs nothing installed
     */
    public function __construct(
        public readonly string $handle,
        public readonly string $label,
        public readonly string $settingsFlag,
        public readonly ?string $pluginHandle = null,
    ) {
    }
}
