<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\load;

use Lameco\Kunstmaanmigrator\craft\ElementWriter;

/**
 * Settles the sibling order an `order:` key asks for, once an environment's entries exist.
 *
 * Craft places a new Structure entry at the end of its parent's children, so load order
 * becomes the structure order. The compiler knows the order the siblings should take
 * (`Compiler::structureOrder()`); this walks each group in that order and moves an entry no
 * run has placed yet directly after the nearest sibling already in place — or, when it comes
 * before all of them, directly before the nearest one after it. Whatever order the entries
 * loaded in, and wherever a batch ended, the placed siblings end up in the target order.
 *
 * An entry is placed once. Its state row remembers it (`structurePlaced`), and a later run
 * leaves it where it is: an editor may have moved it since, and a re-run that put it back
 * would undo their work. `--reorder` treats every member as unplaced, which is how the legacy
 * order is restored on purpose. A new entry joining an existing structure still lands in its
 * slot, beside the siblings it was placed between.
 */
final class StructureOrderService
{
    public const PLACED = 'structurePlaced';

    private readonly RefResolver $refs;

    public function __construct(
        private readonly MigrationStateService $state,
        private readonly ElementWriter $elements,
    ) {
        $this->refs = new RefResolver($state);
    }

    /**
     * @param list<array{section: string, parent: ?string, members: list<string>}> $groups
     * @return array{moved: int, unresolved: int}
     */
    public function settle(array $groups, bool $reorder): array
    {
        $counts = ['moved' => 0, 'unresolved' => 0];

        foreach ($groups as $group) {
            $members = [];

            foreach ($group['members'] as $uid) {
                $id = $this->refs->resolve($uid);

                // Never loaded — failed, or outside a narrowed run. Its siblings settle without it.
                if ($id === null) {
                    $counts['unresolved']++;

                    continue;
                }

                $members[] = ['uid' => $uid, 'id' => $id, 'inPlace' => !$reorder && $this->isPlaced($uid)];
            }

            $counts['moved'] += $this->settleGroup($members);
        }

        return $counts;
    }

    /**
     * @param list<array{uid: string, id: int, inPlace: bool}> $members in target order
     * @return int how many entries moved
     */
    private function settleGroup(array $members): int
    {
        $moved = 0;

        foreach ($members as $i => $member) {
            if ($member['inPlace']) {
                continue;
            }

            $anchor = $this->nearestInPlace($members, $i, -1);
            $after = true;

            if ($anchor === null) {
                $anchor = $this->nearestInPlace($members, $i, 1);
                $after = false;
            }

            // The first of its group to be placed: wherever it is, its siblings place around it.
            if ($anchor !== null && $this->elements->moveInStructure($member['id'], $anchor, $after)) {
                $moved++;
            }

            $members[$i]['inPlace'] = true;
            $this->markPlaced($member['uid']);
        }

        return $moved;
    }

    /** @param list<array{uid: string, id: int, inPlace: bool}> $members */
    private function nearestInPlace(array $members, int $from, int $step): ?int
    {
        for ($i = $from + $step; isset($members[$i]); $i += $step) {
            if ($members[$i]['inPlace']) {
                return $members[$i]['id'];
            }
        }

        return null;
    }

    private function isPlaced(string $uid): bool
    {
        $parsed = RefResolver::parse($uid);
        $meta = $parsed === null ? null : ($this->state->get($parsed['source'], $parsed['key'])['meta'] ?? null);

        if (is_string($meta)) {
            $meta = json_decode($meta, true);
        }

        return is_array($meta) && ($meta[self::PLACED] ?? false) === true;
    }

    private function markPlaced(string $uid): void
    {
        $parsed = RefResolver::parse($uid);

        if ($parsed !== null) {
            $this->state->updateMeta($parsed['source'], $parsed['key'], null, [self::PLACED => true]);
        }
    }
}
