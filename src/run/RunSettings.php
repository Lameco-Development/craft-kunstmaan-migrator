<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\run;

/**
 * The flags one migration run was started with.
 *
 * These lived on MigrateController as public properties, which meant the
 * pipeline could only be driven by a console command. A queue job cannot read
 * a controller's flags — it gets scalars off a serialised job — so the flags
 * became a value the pipeline takes rather than state it reaches for.
 */
final class RunSettings
{
    /** @param list<string>|null $only */
    public function __construct(
        public readonly bool $dryRun = false,
        public readonly bool $force = false,
        public readonly ?int $limit = null,
        public readonly bool $entriesOnly = false,
        public readonly ?array $only = null,
        public readonly ?string $dumpDir = null,
        /** Skip the asset stage entirely. */
        public readonly bool $skipAssets = false,
    ) {
    }

    /**
     * Whether this run ends in the URI pass — the same two flags both callers
     * gate the pass on. Only such a run may veto Craft's own deferred URI
     * maintenance; any other leaves the queue to settle what it wrote.
     */
    public function settlesUris(): bool
    {
        return !$this->dryRun && !$this->entriesOnly;
    }
}
