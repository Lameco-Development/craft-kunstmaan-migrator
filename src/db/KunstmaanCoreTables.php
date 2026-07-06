<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\db;

/**
 * Kunstmaan core table-name constants.
 *
 * Single source of truth for the legacy Kunstmaan schema's table names.
 * `LegacyDbService` and any downstream Core\ query builder references these
 * constants instead of inlining `'kuma_*'` string literals (CORE-01).
 *
 * Relocated from src/source/ to src/db/ in the v2 loader prune — this is
 * shared DB-schema infrastructure consumed by LegacyDbService, DoctorController,
 * and the load-side adapters, not analyze-stage code.
 */
final class KunstmaanCoreTables
{
    public const NODES             = 'kuma_nodes';
    public const NODE_TRANSLATIONS = 'kuma_node_translations';
    public const NODE_VERSIONS     = 'kuma_node_versions';
    public const PAGE_PART_REFS    = 'kuma_page_part_refs';
    public const MEDIA             = 'kuma_media';
    public const SEO               = 'kuma_seo';
    public const REDIRECTS         = 'kuma_redirects';
    public const EXT_TRANSLATIONS  = 'ext_translations';  // Phase 8 / D-08 — Gedmo Translatable; NOT kuma_-prefixed (generic Doctrine extension).
}
