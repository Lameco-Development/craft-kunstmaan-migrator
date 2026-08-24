<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\sites;

/**
 * Which Craft site each legacy locale writes to, for one environment.
 *
 * This used to be ambient state: `MigrateController::applySites()` wrote a
 * plain `locale => handle` array onto five long-lived services once per
 * environment, and each service re-derived what it needed from it. The failure
 * that shape produced is on the record — entries were switched per environment
 * while SEO, redirects, navigation and translations kept whatever the previous
 * environment had left behind, so DE and LV runs wrote those against COM's
 * sites.
 *
 * A value object passed per call cannot do that. It also cannot be
 * half-applied, which is the property the queue needs: two environments'
 * jobs may now interleave, and interleaving mutations of a shared singleton is
 * how a latent bug becomes a live one.
 *
 * The configured mapping is kept verbatim and in order — first locale is the
 * canonical one, which is what drives primary-first save ordering — separately
 * from the bindings, which only exist for locales whose Craft site was
 * actually found.
 */
final class SiteMap
{
    /**
     * @param array<string, string> $configured locale => Craft site handle, in order
     * @param list<SiteBinding>     $bindings   only locales with a resolved Craft site
     * @param list<string>          $unboundCraftHandles Craft sites no locale claims
     */
    private function __construct(
        private readonly array $configured,
        private readonly array $bindings,
        private readonly array $unboundCraftHandles,
    ) {
    }

    /**
     * Resolve a configured `locale => handle` mapping against Craft's sites.
     *
     * `$craftSites` is anything iterable carrying `id`, `handle` and
     * `language` — Craft's own Site models in production, plain objects in a
     * test. Nothing here touches Craft, which is the point.
     *
     * @param array<string, string> $localeToHandle
     * @param iterable<object>      $craftSites
     */
    public static function bind(array $localeToHandle, iterable $craftSites): self
    {
        $configured = [];
        foreach ($localeToHandle as $locale => $handle) {
            $locale = (string) $locale;
            $handle = is_string($handle) ? $handle : '';
            if ($locale !== '' && $handle !== '') {
                $configured[$locale] = $handle;
            }
        }

        // Index Craft's sites by handle and drive the join from the configured
        // locales, not the other way round. Reverse-looking-up each Craft site
        // with array_search finds only the FIRST locale that claims it, so a
        // site two locales share — Enreach points both `br` and `pt` at
        // comBrPt — bound one of them and silently dropped the other from
        // every pass keyed by locale.
        $byHandle = [];
        foreach ($craftSites as $site) {
            $byHandle[(string) $site->handle] = $site;
        }

        $bindings = [];
        $claimed = [];

        foreach ($configured as $locale => $handle) {
            $site = $byHandle[$handle] ?? null;

            if ($site === null) {
                continue;
            }

            $claimed[$handle] = true;

            // Configured order, which is also primary-first order — Craft's own
            // site order is set in the CP and can change under a run.
            $bindings[] = new SiteBinding(
                locale: $locale,
                handle: $handle,
                siteId: (int) $site->id,
                language: (string) $site->language,
            );
        }

        $unbound = [];
        foreach (array_keys($byHandle) as $handle) {
            if (!isset($claimed[$handle])) {
                $unbound[] = $handle;
            }
        }

        return new self($configured, $bindings, $unbound);
    }

    /** @return array<string, string> locale => handle, verbatim and in order */
    public function configured(): array
    {
        return $this->configured;
    }

    /** @return list<string> */
    public function locales(): array
    {
        return array_keys($this->configured);
    }

    /** @return list<string> configured handles, first one canonical */
    public function handles(): array
    {
        return array_values($this->configured);
    }

    public function isEmpty(): bool
    {
        return $this->configured === [];
    }

    public function handleForLocale(?string $locale): ?string
    {
        return $locale === null ? null : ($this->configured[$locale] ?? null);
    }

    public function localeForHandle(?string $handle): ?string
    {
        if ($handle === null) {
            return null;
        }

        $locale = array_search($handle, $this->configured, true);

        return $locale === false ? null : (string) $locale;
    }

    /** @return list<SiteBinding> */
    public function bindings(): array
    {
        return $this->bindings;
    }

    /**
     * @return array<string, int> locale => Craft site id, bound locales only
     */
    public function localeToSiteId(): array
    {
        $out = [];
        foreach ($this->bindings as $binding) {
            $out[$binding->locale] = $binding->siteId;
        }

        return $out;
    }

    /**
     * @return array<string, string> locale => Craft site language, bound locales only
     */
    public function localeToLanguage(): array
    {
        $out = [];
        foreach ($this->bindings as $binding) {
            $out[$binding->locale] = $binding->language;
        }

        return $out;
    }

    public function bindingForLocale(string $locale): ?SiteBinding
    {
        foreach ($this->bindings as $binding) {
            if ($binding->locale === $locale) {
                return $binding;
            }
        }

        return null;
    }

    public function siteIdForLocale(string $locale): ?int
    {
        return $this->bindingForLocale($locale)?->siteId;
    }

    /**
     * Craft sites no configured locale claims. The SEO pass reports these:
     * a site nobody mapped is usually an operator misconfiguration rather
     * than a deliberate omission.
     *
     * @return list<string>
     */
    public function unboundCraftHandles(): array
    {
        return $this->unboundCraftHandles;
    }
}
