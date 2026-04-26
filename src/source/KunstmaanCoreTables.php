<?php

namespace lameco\kunstmaanmigrator\source;

/**
 * Kunstmaan core table-name constants.
 *
 * Single source of truth for the legacy Kunstmaan schema's table names.
 * `LegacyDbService` and any downstream Core\ query builder references these
 * constants instead of inlining `'kuma_*'` string literals (CORE-01).
 *
 * Introduced in Phase 5 Plan 02 during the pure-Core infra extraction
 * (D-01 Step 1). Table names are fixed by the Kunstmaan schema; if a
 * consuming project uses a non-standard prefix, override at the query
 * level — this constants class intentionally carries only the canonical
 * upstream names.
 *
 * Ported verbatim under v2's flat `source` namespace per D-41.
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
}
