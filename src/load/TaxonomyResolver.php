<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\load;

/**
 * Resolve legacy taxonomy string values into Craft entry ids.
 *
 * Fail-fast preflight pattern: resolveAll() loads the full set of legacy
 * names, looks each up in Craft, and throws ONE exception listing ALL
 * misses at once. This lets operators fix the Craft-side taxonomy in one
 * pass rather than discovering misses run-by-run.
 *
 * Default implementation: {@see BulkNameMatchTaxonomyResolver}
 * (trim + mb_strtolower match, section-scoped entry lookup).
 *
 * Projects subclass for custom rules (aliases, per-locale resolution, etc.).
 * Per CONFIG-04 (Phase 5 Plan 06).
 */
abstract class TaxonomyResolver
{
    /**
     * Resolve one legacy value → Craft entry id.
     *
     * @throws \RuntimeException
     *         when the legacy value has no Craft match.
     */
    abstract public function resolve(string $legacyValue): int;

    /**
     * Bulk-resolve legacy values.
     *
     * MUST accumulate misses and raise exactly ONE exception listing them
     * all — never short-circuit on the first miss. The return map is
     * keyed by the original (non-normalised) legacy value so callers can
     * round-trip without having to replicate the normalisation.
     *
     * @param  list<string>        $legacyValues
     * @return array<string, int>  legacyValue => craftEntryId
     *
     * @throws \RuntimeException
     *         when any value has no Craft match (message lists all misses).
     */
    abstract public function resolveAll(array $legacyValues): array;
}
