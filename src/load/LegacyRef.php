<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\load;

use Lameco\Kunstmaanmigrator\Payload\SourceUid;

/**
 * The legacy entity a migrated entry's `kuma_seo` row hangs off — what `kuma_seo.ref_entity_name`
 * and `ref_id` hold — read from the entry's state row.
 *
 * Pure, so the SEO adapter's choice of which entries it may touch is testable without Craft.
 */
final class LegacyRef
{
    /**
     * Resolve the legacy class FQCN + entity id for a state row.
     *
     * Preferred path: read `meta.legacyClass` + `meta.legacyEntityId` that
     * the per-type migrators (news/cases/team/contentPages/singleton) write
     * during their pass.
     *
     * Fallback path (closes the stale-meta gap surfaced 2026-05-09 against
     * dewert-craft-smoke — 131 entries skipped because older state rows
     * lacked the meta keys): both values can be derived directly from the
     * state row.
     *   - `legacyClass` ← `state.source` with underscores → backslashes.
     *     The source is FQCN-derived per `EntryMigrationService`'s state-write
     *     convention (`App_Entity_Pages_TextPage` ↔ `App\Entity\Pages\TextPage`).
     *   - `legacyEntityId` ← `(int) sourceKey` directly. sourceKey is the
     *     FQCN entity row id (i.e., `kuma_<entity>.id`), identical to what
     *     `kuma_seo.ref_id` stores. The meta cache is redundant; falling
     *     back to the source/sourceKey pair recovers the same value.
     *
     * The previous `legacyRefRowForNode()` fallback was broken — it tried
     * `kuma_nodes WHERE id = sourceKey` on the assumption sourceKey was a
     * kuma_node_id, which it isn't. That helper has been removed; if any
     * state row genuinely needs a kuma_node-tree lookup, that's a job for
     * a different resolver, not this one.
     *
     * Singleton state rows store the section handle as sourceKey, so the
     * derivation produces a string id (`globalSettings`) that's not numeric
     * — they MUST carry meta and short-circuit at the existing branch.
     *
     * @param array<string, mixed>|string|null $meta
     * @return array{0: ?string, 1: int}
     */
    public static function fromState(string $source, string $sourceKey, mixed $meta): array
    {
        // Decode meta if a raw JSON string slipped through
        if (is_string($meta) && $meta !== '') {
            $decoded = json_decode($meta, true);
            $meta = is_array($decoded) ? $decoded : null;
        }

        if (is_array($meta)
            && !empty($meta['legacyClass'])
            && !empty($meta['legacyEntityId'])
        ) {
            return [(string) $meta['legacyClass'], (int) $meta['legacyEntityId']];
        }

        // Singletons — no fallback (sourceKey is a section handle, not a numeric id)
        if ($source === 'singleton') {
            return [null, 0];
        }

        // A compiled payload's source is `<ENV>:<table>` — never a class. Deriving one from it
        // (`FR:product\photo`) matched no `kuma_seo` row, and the "no legacy SEO" branch then
        // cleared whatever SEO the entry was saved with: an entity's own, compiled from a
        // `seomatic(...)` map. Such an entry has no `kuma_seo` row to copy, so it is left alone.
        if (SourceUid::parse(SourceUid::fromStateRow($source, $sourceKey)) !== null) {
            return [null, 0];
        }

        // Fallback: derive from source + sourceKey directly. Requires source
        // to be FQCN-shaped (contains underscores → backslashes) and sourceKey
        // to be numeric (the FQCN entity row id).
        if (!ctype_digit($sourceKey) || !str_contains($source, '_')) {
            return [null, 0];
        }

        $derivedClass = str_replace('_', '\\', $source);
        return [$derivedClass, (int) $sourceKey];
    }
}
