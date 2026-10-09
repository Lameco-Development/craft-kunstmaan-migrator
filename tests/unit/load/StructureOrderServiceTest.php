<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\load;

use Lameco\Kunstmaanmigrator\load\StructureOrderService;
use Lameco\Kunstmaanmigrator\tests\support\InMemoryElementWriter;
use Lameco\Kunstmaanmigrator\tests\support\InMemoryMigrationState;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Settling a Structure's sibling order once an environment's entries exist.
 *
 * Entries arrive in load order; the compiler knows the order they should take
 * (`Compiler::structureOrder()`). Settling moves the entries no run has placed
 * yet into their slot, and leaves alone what an earlier run placed — an editor
 * may have moved it since — unless `--reorder` asks for the legacy order back.
 */
final class StructureOrderServiceTest extends TestCase
{
    private InMemoryMigrationState $state;

    private InMemoryElementWriter $elements;

    protected function setUp(): void
    {
        $this->state = new InMemoryMigrationState();
        $this->elements = new InMemoryElementWriter();
    }

    /** Target order A B C D: legacy ids 1..4, entry ids 101..104. */
    private const GROUP = [
        'section' => 'faqCategories',
        'parent' => null,
        'members' => ['kuma:NL:faq:1', 'kuma:NL:faq:2', 'kuma:NL:faq:3', 'kuma:NL:faq:4'],
    ];

    /** @param list<int> $placed legacy ids an earlier run already placed */
    private function loaded(array $placed = [], array $ids = [1, 2, 3, 4]): void
    {
        foreach ($ids as $id) {
            $this->state->willResolve('NL:faq', (string) $id, 100 + $id, in_array($id, $placed, true) ? ['structurePlaced' => true] : ['pendingRefs' => []]);
        }
    }

    private function settle(bool $reorder = false, array $group = self::GROUP): array
    {
        return (new StructureOrderService($this->state, $this->elements))->settle([$group], $reorder);
    }

    #[Test]
    public function a_fresh_load_in_any_order_ends_in_the_target_order(): void
    {
        $this->loaded();
        $this->elements->willHoldInStructure([104, 102, 101, 103]);

        $counts = $this->settle();

        self::assertSame([101, 102, 103, 104], $this->elements->structureOrder());
        self::assertSame(3, $counts['moved']);
        self::assertTrue($this->state->metaOf('NL:faq', '3')['structurePlaced'] ?? false);
        self::assertTrue($this->state->metaOf('NL:faq', '1')['structurePlaced'] ?? false);
    }

    #[Test]
    public function an_entry_an_earlier_run_placed_stays_where_an_editor_moved_it(): void
    {
        $this->loaded([1, 2, 3, 4]);
        $this->elements->willHoldInStructure([102, 101, 103, 104]);

        $counts = $this->settle();

        self::assertSame([102, 101, 103, 104], $this->elements->structureOrder());
        self::assertSame(0, $counts['moved']);
    }

    #[Test]
    public function reorder_puts_every_placed_entry_back_in_the_target_order(): void
    {
        $this->loaded([1, 2, 3, 4]);
        $this->elements->willHoldInStructure([104, 102, 101, 103]);

        $this->settle(reorder: true);

        self::assertSame([101, 102, 103, 104], $this->elements->structureOrder());
    }

    #[Test]
    public function a_new_entry_lands_after_its_nearest_placed_predecessor(): void
    {
        // C is new in legacy; the previous run placed A, B and D. C loaded last, at the end.
        $this->loaded([1, 2, 4]);
        $this->elements->willHoldInStructure([101, 102, 104, 103]);

        $this->settle();

        self::assertSame([101, 102, 103, 104], $this->elements->structureOrder());
    }

    #[Test]
    public function a_new_first_entry_lands_before_the_nearest_placed_successor(): void
    {
        $this->loaded([2, 3, 4]);
        $this->elements->willHoldInStructure([102, 103, 104, 101]);

        $this->settle();

        self::assertSame([101, 102, 103, 104], $this->elements->structureOrder());
    }

    #[Test]
    public function a_member_that_never_loaded_is_skipped(): void
    {
        $this->loaded([], [1, 3, 4]);
        $this->elements->willHoldInStructure([104, 103, 101]);

        $counts = $this->settle();

        self::assertSame([101, 103, 104], $this->elements->structureOrder());
        self::assertSame(1, $counts['unresolved']);
    }

    #[Test]
    public function a_move_craft_refuses_is_counted_and_left_for_the_next_run(): void
    {
        // The twin refuses a move whose anchor it does not hold: 101 is missing from the structure.
        $this->loaded([1]);
        $this->elements->willHoldInStructure([102]);

        $counts = $this->settle(group: [...self::GROUP, 'members' => ['kuma:NL:faq:1', 'kuma:NL:faq:2']]);

        self::assertSame(1, $counts['failed']);
        self::assertSame(0, $counts['moved']);
        self::assertArrayNotHasKey('structurePlaced', $this->state->metaOf('NL:faq', '2') ?? []);
    }

    #[Test]
    public function a_new_entry_skips_an_anchor_an_editor_moved_under_another_parent(): void
    {
        // A was placed, then an editor moved it under P (900). B is new; C was placed at the root.
        $this->loaded([1, 3]);
        $this->elements->willHoldInStructure([900, 103, 102]);
        $this->elements->willHoldInStructure([101], parentId: 900);

        $this->settle(group: [...self::GROUP, 'members' => ['kuma:NL:faq:1', 'kuma:NL:faq:2', 'kuma:NL:faq:3']]);

        self::assertSame([900, 102, 103], $this->elements->structureOrder());
        self::assertSame([101], $this->elements->structureOrder(parentId: 900));
    }

    #[Test]
    public function a_new_entry_with_no_anchor_left_under_its_parent_stays_under_that_parent(): void
    {
        $this->loaded([1]);
        $this->elements->willHoldInStructure([900, 102]);
        $this->elements->willHoldInStructure([101], parentId: 900);

        $counts = $this->settle(group: [...self::GROUP, 'members' => ['kuma:NL:faq:1', 'kuma:NL:faq:2']]);

        self::assertSame([900, 102], $this->elements->structureOrder());
        self::assertSame([101], $this->elements->structureOrder(parentId: 900));
        self::assertSame(0, $counts['failed']);
        self::assertTrue($this->state->metaOf('NL:faq', '2')['structurePlaced'] ?? false);
    }

    #[Test]
    public function a_group_under_a_parent_settles_beneath_that_parent_only(): void
    {
        // The members live under page 500; another Structure holds siblings of its own.
        $this->state->willResolve('NL:page', '500', 500, ['pendingRefs' => []]);
        $this->loaded();
        $this->elements->willHoldInStructure([500]);
        $this->elements->willHoldInStructure([104, 102, 101, 103], parentId: 500);
        $this->elements->willHoldInStructure([7, 8], section: 'other');

        $this->settle(group: [...self::GROUP, 'parent' => 'kuma:NL:page:500']);

        self::assertSame([101, 102, 103, 104], $this->elements->structureOrder(parentId: 500));
        self::assertSame([500], $this->elements->structureOrder());
        self::assertSame([7, 8], $this->elements->structureOrder(section: 'other'));
    }
}
