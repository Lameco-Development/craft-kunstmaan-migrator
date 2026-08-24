<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\console;

/**
 * One export: the rows that round-trip, and the count of those that did not.
 *
 * The count used to travel by reference with a comment pleading that "neither
 * caller may stay silent" — the weakest contract PHP offers. Here the duty is
 * the signature: a caller holding an ExportResult is holding the warning too.
 */
final class ExportResult
{
    /** @param list<array{sourceUid: string, entryId: ?int, targetType: string, alias_of: ?string}> $rows */
    public function __construct(
        public readonly array $rows,
        public readonly int $excluded,
    ) {
    }

    /** What the exclusion count means — null when nothing was excluded. */
    public function warning(): ?string
    {
        if ($this->excluded === 0) {
            return null;
        }

        return sprintf(
            'state/export: excluded %d state row(s) whose reconstructed sourceUid does not round-trip through RefResolver::parse() (composite-key bookkeeping rows, e.g. seo_meta).',
            $this->excluded,
        );
    }
}
