<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\load;

use craft\elements\Entry;

/**
 * Default TaxonomyResolver: name-match lookup against a single Craft section.
 *
 * Generalises the older per-project category resolver pattern:
 *
 *   1. Lazy-load: on first resolve() / resolveAll(), query
 *      Entry::find()->section($handle)->site('*')->unique()->all()
 *      once, build a normalised-name → entry id map.
 *   2. Normalise: by default `trim(mb_strtolower($v))`. A custom callable
 *      can be injected for per-project rules (ligature folding, locale
 *      stripping, etc.).
 *   3. First-write-wins: duplicate titles in the Craft section are ignored
 *      after the first match (operator sees the first entry id).
 *   4. Fail-fast preflight: resolveAll() collects every miss and throws
 *      one \RuntimeException listing them all.
 *
 * The resolver is stateful (holds the cache) but idempotent — call it
 * N times in a request, the cache loads once. Construct a fresh instance
 * per migration run to pick up concurrent Craft edits.
 *
 * Per CONFIG-04 (Phase 5 Plan 06).
 */
final class BulkNameMatchTaxonomyResolver extends TaxonomyResolver
{
    /**
     * Normalised-name → Craft entry id.
     *
     * Stays null until first lookup triggers ensureCacheLoaded().
     *
     * @var array<string, int>|null
     */
    private ?array $cache = null;

    /** @var callable(string): string */
    private $normaliseFn;

    /**
     * @param string                           $craftSectionHandle  Craft section handle that owns the taxonomy entries.
     * @param (callable(string): string)|null  $normaliseFn         Normalisation callback; defaults to trim+mb_strtolower.
     */
    public function __construct(
        private readonly string $craftSectionHandle,
        ?callable $normaliseFn = null,
    ) {
        $this->normaliseFn = $normaliseFn ?? static fn(string $v): string => trim(mb_strtolower($v));
    }

    public function resolve(string $legacyValue): int
    {
        $this->ensureCacheLoaded();
        $key = ($this->normaliseFn)($legacyValue);

        if (!isset($this->cache[$key])) {
            throw new \RuntimeException(sprintf(
                "Taxonomy miss: legacy value '%s' (normalised '%s') not found in Craft section '%s'. "
                . "Create the entry in the Craft CP (section '%s') before re-running.",
                $legacyValue,
                $key,
                $this->craftSectionHandle,
                $this->craftSectionHandle,
            ));
        }

        return $this->cache[$key];
    }

    public function resolveAll(array $legacyValues): array
    {
        $this->ensureCacheLoaded();

        $result = [];
        $misses = [];

        foreach ($legacyValues as $v) {
            // Empty strings are silently skipped — callers may pass NULL-ish rows.
            if ($v === '') {
                continue;
            }
            $key = ($this->normaliseFn)($v);
            if (isset($this->cache[$key])) {
                $result[$v] = $this->cache[$key];
            } else {
                $misses[] = sprintf("'%s' (normalised '%s')", $v, $key);
            }
        }

        if ($misses !== []) {
            $shown = array_slice($misses, 0, 30);
            $suffix = count($misses) > 30 ? ', …' : '';
            throw new \RuntimeException(sprintf(
                "Taxonomy resolution misses in section '%s': %d value(s) not found in Craft: [%s%s]. "
                . "Create these entries in the Craft CP (section '%s') before re-running.",
                $this->craftSectionHandle,
                count($misses),
                implode(', ', $shown),
                $suffix,
                $this->craftSectionHandle,
            ));
        }

        return $result;
    }

    /**
     * Load the name→id map once per instance.
     *
     * Runs `->site('*')->unique()` so a multi-site section returns one row
     * per element (Craft dedupes on canonical element id). Entries with
     * empty titles are skipped. First-write-wins on normalised collisions.
     */
    private function ensureCacheLoaded(): void
    {
        if ($this->cache !== null) {
            return;
        }

        $entries = Entry::find()
            ->section($this->craftSectionHandle)
            ->site('*')
            ->unique()
            ->status(null)
            ->all();

        $map = [];
        foreach ($entries as $entry) {
            $title = (string) $entry->title;
            if ($title === '') {
                continue;
            }
            $key = ($this->normaliseFn)($title);
            if (!isset($map[$key])) {
                $map[$key] = (int) $entry->id;
            }
        }

        $this->cache = $map;
    }
}
