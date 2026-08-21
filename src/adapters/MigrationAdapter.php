<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\adapters;

use lameco\kunstmaanmigrator\load\MigrationOptions;
use lameco\kunstmaanmigrator\load\MigrationReport;
use lameco\kunstmaanmigrator\sites\SiteMap;

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

    public function migrateAll(MigrationOptions $opts, SiteMap $sites): MigrationReport;
}
