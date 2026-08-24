<?php

declare(strict_types=1);

namespace Lameco\KumaCompile\Legacy;

/**
 * Kunstmaan core table-name constants — the shared home for both halves.
 *
 * The write half's `LegacyDbService` builds its SQL from these. The readers
 * in this package still carry the same names as literals inside their large
 * SQL heredocs (constants cannot interpolate there, and the names are fixed
 * by Kunstmaan itself, so drift is not a live risk) — this class is where a
 * name would change if Kunstmaan ever changed one, and the greppable index
 * of which tables the migrator reads.
 */
final class KunstmaanCoreTables
{
    public const NODES = 'kuma_nodes';
    public const NODE_TRANSLATIONS = 'kuma_node_translations';
    public const NODE_VERSIONS = 'kuma_node_versions';
    public const PAGE_PART_REFS = 'kuma_page_part_refs';
    public const MEDIA = 'kuma_media';
    public const SEO = 'kuma_seo';
    public const REDIRECTS = 'kuma_redirects';
    public const EXT_TRANSLATIONS = 'ext_translations';  // Phase 8 / D-08 — Gedmo Translatable; NOT kuma_-prefixed (generic Doctrine extension).
}
