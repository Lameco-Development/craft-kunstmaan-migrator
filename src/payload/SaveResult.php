<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\payload;

/**
 * Outcome of one `PayloadEntrySaver::save()` call.
 */
final class SaveResult
{
    /**
     * @param list<array{field: string, site: string, ref: string}> $deferredRefs
     *   Unresolved `_ref`/parent refs recorded this save — same shape
     *   persisted under the entry's state meta key `pendingRefs` for
     *   Task 5's fixup pass to drain.
     */
    public function __construct(
        public readonly string $sourceUid,
        public readonly int $entryId,
        public readonly bool $created,
        public readonly array $deferredRefs,
    ) {
    }
}
