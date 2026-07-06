<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\fields;

/**
 * Deferred-entry token format `entry:<stateSource>:<legacyId>` emitted by
 * RelationHandler when an entry-relation state lookup misses at transform
 * time. The migrator's pipeline runs extract → transform → load
 * sequentially: at transform time the state table is empty, so cross-page
 * entry relations on matrix blocks (e.g. servicePagePart.page →
 * App_Entity_Pages_ServicesPage) cannot be resolved synchronously.
 *
 * Resolution happens in two phases at load time:
 *
 *   1. Pre-save walker (AtomicMigrationService::ingestAndResolveEntryRelations)
 *      walks the perSite tree before each entry's save. Tokens whose target
 *      already saved earlier in this load (same-section parents, taxonomies)
 *      resolve immediately; unresolved tokens are recorded for post-load
 *      fixup and stripped from the payload so Craft's Entries field
 *      validator doesn't choke on string values.
 *
 *   2. Post-load fixup pass — meant to run after every entry has saved at
 *      least once, once the state table is fully populated, to look up
 *      pass-1's unresolved tokens and re-save the owning matrix block with
 *      proper relation IDs. v2 loader prune: the class that ran this pass
 *      (`MigrateWorkflow`) was removed; a replacement drain path
 *      (`load/fixup`) is planned for a later task. Until then,
 *      `AtomicMigrationService::$entryRelationFixupQueue` accumulates
 *      unresolved tokens undrained.
 *
 * The 3-part format (vs DeferredAssetToken's 2-part `asset:N`) carries the
 * stateSource alongside the legacy id because Page, PagePart, and Custom
 * entities all live in different state-table source-prefixes — the consumer
 * side needs the source to look up state.
 *
 * Scope: matrix-block-nested Entries fields. Top-level Entries fields on
 * Page/Custom entities don't need this — their resolver runs at load time
 * with state already populated for ancestors via hierarchy ordering.
 */
final class DeferredEntryToken
{
    /**
     * Build a token string from a state-table source key and a legacy
     * (Kunstmaan) entity row id.
     */
    public static function emit(string $stateSource, int $legacyId): string
    {
        return 'entry:' . $stateSource . ':' . $legacyId;
    }

    /**
     * True when the value is a string of the form `entry:<source>:<digits>`.
     */
    public static function isToken(mixed $value): bool
    {
        return is_string($value) && preg_match('/^entry:[A-Za-z0-9_]+:\d+$/', $value) === 1;
    }

    /**
     * Parse a token string into its (stateSource, legacyId) parts.
     * Returns null if the input doesn't match the token format.
     *
     * @return array{source: string, legacyId: int}|null
     */
    public static function parse(string $token): ?array
    {
        if (preg_match('/^entry:([A-Za-z0-9_]+):(\d+)$/', $token, $m) !== 1) {
            return null;
        }
        return ['source' => $m[1], 'legacyId' => (int) $m[2]];
    }
}

// PAIRED REGEX CONTRACT (load-bearing): the format defined here is
// consumed by AtomicMigrationService::ingestAndResolveEntryRelations().
// The other consumer (MigrateWorkflow's post-load fixup pass) was removed
// in the v2 loader prune; its planned replacement (`load/fixup`, a later
// task) MUST honor this same format when it's built.
