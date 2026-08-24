<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\load;

/**
 * Shared options for every migration service run.
 *
 * Populated by the MigrateController (added in Plan 04-10) from CLI flags
 * and passed through to each domain service so they all observe the same
 * dry-run / verbosity / batch-size policy during one invocation.
 *
 * `legacyClassFilter` scopes a single migrateAll() pass to a subset of
 * legacy classes — required by the operator subcommands `services` /
 * `trainings` / `text-pages` which all delegate to the same service but
 * each cover a distinct slice of the contentPages tree. Null by default
 * (process everything).
 *
 * @phpstan-type LegacyClassFilter list<string>|null
 */
final class MigrationOptions
{
    /**
     * @param list<string>|null $legacyClassFilter Optional whitelist of legacy
     *   class FQCNs (e.g. ['App\\Entity\\Pages\\FieldPage']) to scope the
     *   contentPages migration to. When null, every supported class is
     *   processed.
     */
    public function __construct(
        public bool $dryRun = false,
        public bool $force = false,
        public int $verbosity = 0,      // 0 = quiet, 1 = normal, 2 = verbose
        public int $batchSize = 50,
        public ?array $legacyClassFilter = null,
        public bool $skipAssets = false, // --skipAssets: skip the Assets stage
                                         // AND per-entry ingestOne() calls
                                         // during atomic load. Migrated entries
                                         // reference assets by legacy id; if
                                         // those assets aren't in Craft yet,
                                         // the asset handler resolves null and
                                         // leaves the field blank. Re-runnable
                                         // later without --skipAssets to
                                         // populate the missing refs.
    ) {
    }
}
