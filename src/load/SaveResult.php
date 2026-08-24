<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\load;

/**
 * Outcome of one `PayloadEntrySaver::save()` call.
 */
final class SaveResult
{
    /**
     * @param list<array{field: string, site: string, ref: string, path: list<int|string>}> $deferredRefs
     *   Unresolved `_ref`/parent refs recorded this save — same shape
     *   persisted under the entry's state meta key `pendingRefs` for
     *   Task 5's fixup pass to drain. `path` is the ordered list of array
     *   keys/indices from the site's `fieldValues` root down to the
     *   container holding the unresolved `_ref` (empty for `parentRef`,
     *   which lives outside `fieldValues`) — see
     *   docs/loader-contract.md "Two-pass `_ref` resolution semantics".
     * @param list<array{field: string, site: string, path: list<int|string>, asset: string}> $unresolvedAssets
     *   Task 8 — unresolved `_asset` nodes recorded this save. Same `path`
     *   convention as `deferredRefs` (container, not the dropped node's own
     *   slot). Unlike `_ref`, there is no later fixup pass: a missing legacy
     *   media file doesn't become resolvable by re-running load in a
     *   different order, so this is a terminal report, not a deferred one.
     * @param list<array<string, mixed>> $mediaTokenIssues
     *   Task 8 — one entry per unresolvable `{{kuma:media:<id>}}` token
     *   encountered in a string fieldValue, merging `field`/`site`/`path`
     *   context with `CkeditorRewriterService::consumeUnresolvedDiagnostics()`'s
     *   `tokenFamily`/`legacyId`/`siteId`/`token`/`source`/`reason` shape.
     */
    public function __construct(
        public readonly string $sourceUid,
        public readonly int $entryId,
        public readonly bool $created,
        public readonly array $deferredRefs,
        public readonly array $unresolvedAssets = [],
        public readonly array $mediaTokenIssues = [],
        /**
         * Addresses this save could not write, because a Craft Address supports the primary
         * site and no other, and the payload never names that site. A silent drop here reads
         * as a clean run with no address, which is the failure this records rather than hides.
         *
         * @var list<array{field: string, site: string}>
         */
        public readonly array $droppedAddresses = [],
    ) {
    }
}
