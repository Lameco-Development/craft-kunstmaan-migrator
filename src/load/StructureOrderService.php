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
 *
 * A move beside a sibling takes that sibling's parent, so a sibling an editor has since moved
 * under another parent is no anchor: the entry would follow it there. Only a sibling still
 * under the group's parent anchors; with none left, the entry goes to the start or end of
 * that parent's children.
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
     * @return array{moved: int, unresolved: int, failed: int}
     */
    public function settle(array $groups, bool $reorder): array
    {
        $counts = ['moved' => 0, 'unresolved' => 0, 'failed' => 0];

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

            [$moved, $failed] = $this->settleGroup($members, $this->intendedParent($group['parent']));
            $counts['moved'] += $moved;
            $counts['failed'] += $failed;
        }

        return $counts;
    }

    /**
     * @param list<array{uid: string, id: int, inPlace: bool}> $members in target order
     * @param int|false|null $parent the group's parent entry id, null for the root, false when
     *        it never loaded — then no sibling can be checked against it, and any anchors
     * @return array{0: int, 1: int} how many entries moved, and how many Craft refused to move
     */
    private function settleGroup(array $members, int|false|null $parent): array
    {
        $moved = 0;
        $failed = 0;

        foreach ($members as $i => $member) {
            if ($member['inPlace']) {
                continue;
            }

            $before = $this->placedSiblings($members, $i, -1);
            $after = $this->placedSiblings($members, $i, 1);

            // The first of its group to be placed needs no move: its siblings place around it.
            if ($before !== [] || $after !== []) {
                // A refused move is not a placement: the entry stays unmarked, so the next run
                // tries again, and its siblings do not take it as an anchor.
                if (!$this->place($member['id'], $before, $after, $parent)) {
                    $failed++;

                    continue;
                }

                $moved++;
            }

            $members[$i]['inPlace'] = true;
            $this->markPlaced($member['uid']);
        }

        return [$moved, $failed];
    }

    /**
     * After the nearest placed predecessor still under the group's parent, else before the
     * nearest such successor, else at the end of the parent's children — at the start when
     * nothing placed comes before it.
     *
     * @param list<int> $before placed predecessors, nearest first
     * @param list<int> $after placed successors, nearest first
     */
    private function place(int $id, array $before, array $after, int|false|null $parent): bool
    {
        foreach ([[$before, true], [$after, false]] as [$anchors, $isAfter]) {
            foreach ($anchors as $anchor) {
                if ($parent === false || $this->elements->parentInStructure($anchor) === $parent) {
                    return $this->elements->moveInStructure($id, $anchor, $isAfter);
                }
            }
        }

        return $parent !== false && $this->elements->placeInStructure($id, $parent, $before === []);
    }

    /**
     * @param list<array{uid: string, id: int, inPlace: bool}> $members
     * @return list<int> the placed siblings from `$from` in the direction of `$step`, nearest first
     */
    private function placedSiblings(array $members, int $from, int $step): array
    {
        $ids = [];

        for ($i = $from + $step; isset($members[$i]); $i += $step) {
            if ($members[$i]['inPlace']) {
                $ids[] = $members[$i]['id'];
            }
        }

        return $ids;
    }

    /** @return int|false|null the parent's entry id, null for the root, false when it never loaded */
    private function intendedParent(?string $uid): int|false|null
    {
        return $uid === null ? null : ($this->refs->resolve($uid) ?? false);
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
