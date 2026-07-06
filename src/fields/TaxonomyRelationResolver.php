<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\fields;

use lameco\kunstmaanmigrator\load\MigrationOptions;
use lameco\kunstmaanmigrator\load\MigrationReport;

/**
 * Contract for the optional page-rooted taxonomy-relation delegate plugged
 * into {@see ResolverContext}. RelationHandler calls resolveReferenced() on
 * a non-empty state miss for a taxonomy-backed relation; it never creates
 * taxonomy entries itself.
 *
 * Extracted as an interface (rather than depending on a concrete service
 * class) in the v2 loader prune so ResolverContext/RelationHandler don't
 * hard-depend on any particular taxonomy-migration implementation.
 */
interface TaxonomyRelationResolver
{
    public function resolveReferenced(
        string $taxonomySource,
        int|string $legacyId,
        MigrationOptions $opts,
        ?MigrationReport $report = null,
    ): ?int;
}
