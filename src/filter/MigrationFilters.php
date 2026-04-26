<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\filter;

/**
 * Legacy-side scoping for every Phase 3+ stage (extract / transform / load / verify).
 *
 * NOT v1's MigrationFilters — that's post-Craft (includeDeleted/Offline/Drafts).
 * v2 redesigns for Kunstmaan source filtering per CONTEXT.md D-09..D-13.
 *
 * Empty `entities` / `locales` mean unbounded; null `since` means no date floor.
 *
 * D-12: --max-per-entity is DROPPED from v1.0 scope. Three properties only.
 */
final class MigrationFilters
{
    /**
     * @param list<string> $entities Kunstmaan source class names (e.g. 'NewsPage'); empty = unbounded
     * @param list<string> $locales  Kunstmaan locale codes (e.g. ['nl', 'fr']); empty = unbounded
     * @param string|null  $since    YYYY-MM-DD date floor; column-presence detection per D-11
     * @param bool         $noSeo    Phase 4.1 / D-26 — CLI override; bypass SEOmatic adapter per-run
     * @param bool         $noRetour Phase 4.1 / D-26 — CLI override; bypass Retour adapter per-run
     */
    public function __construct(
        public readonly array $entities = [],
        public readonly array $locales = [],
        public readonly ?string $since = null,
        // Phase 4.1 / D-26 — CLI override flags. Default false preserves Phase 2/3/4 callers.
        public readonly bool $noSeo = false,
        public readonly bool $noRetour = false,
    ) {
    }
}
