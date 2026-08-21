<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\adapters;

use lameco\kunstmaanmigrator\load\MigrationOptions;
use lameco\kunstmaanmigrator\load\MigrationReport;
use lameco\kunstmaanmigrator\run\EnvironmentContext;

/**
 * A pass that runs after an environment's entries exist.
 *
 * Four services already had this exact signature, each with its own copy of the
 * same six-line gate prologue and its own literal handle. That is an interface
 * written four times without being declared — and because it was never
 * declared, the thing that ran them was a hard-coded array of four, so an
 * adapter registered through EVENT_REGISTER_ADAPTERS got a row on the settings
 * screen and was never called. The registry promised extensibility the
 * execution path could not honour.
 */
interface MigrationAdapter
{
    /** Matches the Adapter's handle in the registry, which is what gates it. */
    public function handle(): string;

    /**
     * The signature was `(MigrationOptions, SiteMap)`, which had nowhere to put
     * "which legacy database am I reading" or "which mapping am I compiling".
     * That is why `redirects` could not be an adapter and had to be a special
     * case inside the pipeline — and why the `forms:` and `globals:` lanes,
     * which have the same shape, had nothing to be written as. The site map
     * lives on the context now, with everything else about the environment.
     */
    public function migrateAll(MigrationOptions $opts, EnvironmentContext $context): MigrationReport;
}
