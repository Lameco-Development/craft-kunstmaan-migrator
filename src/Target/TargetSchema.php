<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\Target;

/**
 * The questions the compile side asks about the target Craft content model.
 *
 * Two implementations, deliberately, because the question is asked at two different times.
 * Authoring happens before a target is necessarily installed, so `CraftSchema` answers from
 * `config/project/**` with no Craft booted. A migration run happens against a live site, where
 * reading project config could lag an unapplied change, so the loader answers from the same
 * schema gateway its validator and saver already use.
 *
 * They were two classes with the same seven methods and no shared type, in two repositories, which
 * is how the compile path drifted from the authoring path without either side erroring.
 */
interface TargetSchema
{
    public function hasEntryType(string $handle): bool;

    public function hasSection(string $handle): bool;

    /** @return array<string, Slot> field handle => slot */
    public function slots(string $entryType): array;

    public function slot(string $entryType, string $field): ?Slot;

    /** @return list<string> field handles the entry type marks required */
    public function requiredFields(string $entryType): array;

    /**
     * Where a value like a heading lands on a block: `''` at block level, `<matrix>[0]` when the
     * field only exists on a nested entry type, null when the block has no such field at all.
     */
    public function pathFor(string $entryType, string $field): ?string;

    /** The single nested entry type of a Matrix field, when it is unambiguous. */
    public function nestedTypeOf(string $entryType, string $field): ?string;
}
