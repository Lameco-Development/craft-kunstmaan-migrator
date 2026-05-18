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
 * D-12: --max-per-entity is DROPPED from v1.0 scope.
 *
 * Phase 8 / D-04: when `entities` is non-empty, `allows()` auto-includes any FQCN
 * reachable from the explicit allow-list via `relationGraph`. This lets
 * `--entities=NewsPage` automatically pull in `NewsCategory` (taxonomy referenced
 * by NewsPage) without introducing a new `--taxonomies=` flag — preserving the
 * D-12 three-flag cap (`--entities`, `--locales`, `--since`).
 *
 * The relation graph is `Map<FQCN, list<related FQCN>>`. It is built by the source
 * scanner from each entity's relation columns and threaded into this VO via
 * `FilterFactory`. When the graph is empty (existing call sites), `allows()`
 * degrades cleanly to "explicit allow-list only".
 */
final class MigrationFilters
{
    /**
     * Memoized reachable-FQCN set keyed by FQCN. `null` until first `allows()` call.
     *
     * @var array<string, true>|null
     */
    private ?array $reachableCache = null;

    /**
     * @param list<string>                 $entities      Kunstmaan source class names (e.g. 'NewsPage'); empty = unbounded
     * @param list<string>                 $locales       Kunstmaan locale codes (e.g. ['nl', 'fr']); empty = unbounded
     * @param string|null                  $since         YYYY-MM-DD date floor; column-presence detection per D-11
     * @param bool                         $noSeo         Phase 4.1 / D-26 — CLI override; bypass SEOmatic adapter per-run
     * @param bool                         $noRetour      Phase 4.1 / D-26 — CLI override; bypass Retour adapter per-run
     * @param array<string, list<string>>  $relationGraph Phase 8 / D-04 — Map<FQCN, list<related FQCN>> for reachability auto-include
     */
    public function __construct(
        public readonly array $entities = [],
        public readonly array $locales = [],
        public readonly ?string $since = null,
        // Phase 4.1 / D-26 — CLI override flags. Default false preserves Phase 2/3/4 callers.
        public readonly bool $noSeo = false,
        public readonly bool $noRetour = false,
        // Phase 8 / D-04 — relation graph for taxonomy auto-include. Default empty preserves all
        // pre-Phase-8 call sites; the only behavioral change occurs when the scanner threads a
        // populated graph through FilterFactory.
        public readonly array $relationGraph = [],
    ) {
    }

    /**
     * Phase 8 / D-04: returns true if `$fqcn` is in scope for the current run.
     *
     * Decision matrix:
     *   - `entities === []` (no scoping)         → true for every FQCN.
     *   - FQCN is in `entities`                  → true (explicit allow).
     *   - FQCN is reachable from `entities`      → true (auto-include via relation graph).
     *   - Otherwise                              → false.
     *
     * Reachability is computed once and memoized for the lifetime of the VO.
     */
    public function allows(string $fqcn): bool
    {
        if ($this->entities === []) {
            return true;
        }

        return $this->identityInSet($fqcn, $this->reachable());
    }

    /**
     * Returns true when ANY scoping filter is active (entities, locales, or
     * since). Used by MigrateWorkflow to gate global-scope sidecar stages
     * (seo, retour, translations, navigation) — those rebuild from full
     * source tables (kuma_seo, kuma_redirects, kuma_translation, kuma_menu)
     * and would push duplicate rows when the operator is targeting an
     * entity slice. When narrowed, operators run the sidecar sub-actions
     * (`migrate/seo`, `migrate/retour`, etc.) standalone for full rebuilds.
     */
    public function isNarrowed(): bool
    {
        return $this->entities !== [] || $this->locales !== [] || $this->since !== null;
    }

    /**
     * Compute (and memoize) the set of FQCNs reachable from `$entities` via the
     * relation graph. Iterative DFS with a visited-set guard so cycles in the
     * graph (A → B → A) don't loop forever.
     *
     * @return array<string, true> map of reachable FQCN → true (used as a set)
     */
    private function reachable(): array
    {
        if ($this->reachableCache !== null) {
            return $this->reachableCache;
        }

        /** @var array<string, true> $reachable */
        $reachable = [];
        $stack = [];
        foreach ($this->entities as $fqcn) {
            if ($this->addIdentity($reachable, $fqcn)) {
                $stack[] = $fqcn;
            }
        }

        foreach ($this->relationGraph as $source => $_targets) {
            $source = (string) $source;
            if ($this->identityInSet($source, $reachable) && $this->addIdentity($reachable, $source)) {
                $stack[] = $source;
            }
        }

        while ($stack !== []) {
            $cur = array_pop($stack);
            foreach ($this->relationGraph[$cur] ?? [] as $rel) {
                if ($this->addIdentity($reachable, $rel)) {
                    $stack[] = $rel;
                }
            }
        }

        return $this->reachableCache = $reachable;
    }

    /**
     * Match Kunstmaan source identity in either exact FQCN or basename form.
     *
     * @param array<string, true> $set
     */
    private function identityInSet(string $fqcn, array $set): bool
    {
        if (isset($set[$fqcn])) {
            return true;
        }

        $basename = $this->sourceBasename($fqcn);

        return $basename !== $fqcn && isset($set[$basename]);
    }

    /**
     * @param array<string, true> $set
     */
    private function addIdentity(array &$set, string $fqcn): bool
    {
        if (isset($set[$fqcn])) {
            return false;
        }

        $set[$fqcn] = true;

        $basename = $this->sourceBasename($fqcn);
        if ($basename !== $fqcn) {
            $set[$basename] = true;
        }

        return true;
    }

    private function sourceBasename(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);

        return (string) end($parts);
    }
}
