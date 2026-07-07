<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\payload;

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
     */
    public function __construct(
        public readonly string $sourceUid,
        public readonly int $entryId,
        public readonly bool $created,
        public readonly array $deferredRefs,
    ) {
    }
}
