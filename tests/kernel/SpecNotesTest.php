<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\kernel;

use Lameco\Kunstmaanmigrator\Target\SpecNotes;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SpecNotesTest extends TestCase
{
    private function notes(): array
    {
        return SpecNotes::fromDirectory(__DIR__ . '/fixtures/specs')->forBlock('demoBlock');
    }

    /** @return list<\Lameco\Kunstmaanmigrator\Target\Note> */
    private function notesFrom(string $markdown): array
    {
        $dir = sys_get_temp_dir() . '/kuma-specs-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/eventPage.md', "---\nhandle: eventPage\n---\n\n" . $markdown . "\n");

        return SpecNotes::fromDirectory($dir)->forBlock('eventPage');
    }

    #[Test]
    public function it_reads_only_the_migration_notes_table(): void
    {
        // The spec also has a Fields table with a `heading` handle in it; picking that up
        // would invent mappings from the wrong table.
        self::assertCount(6, $this->notes());
    }

    #[Test]
    public function a_plain_row_pairs_a_property_with_a_field(): void
    {
        $n = $this->notes()[0];

        self::assertSame('part', $n->scope);
        self::assertSame(['title'], $n->sources);
        self::assertSame(['heading'], $n->targets);
        self::assertTrue($n->isMapped());
    }

    #[Test]
    public function alternatives_and_bold_decision_notes_are_handled(): void
    {
        $n = $this->notes()[1];

        self::assertSame(['backgroundColor', 'color'], $n->sources, 'either column may carry it');
        self::assertSame(['colorScheme'], $n->targets, 'the decision commentary is not a field');
    }

    #[Test]
    public function item_scope_marks_a_child_collection_property(): void
    {
        $n = $this->notes()[2];

        self::assertSame('item', $n->scope);
        self::assertSame(['tabTitle'], $n->sources);
        self::assertSame(['tabLabel'], $n->targets, 'the nested-entry prefix is not part of the handle');
    }

    #[Test]
    public function ordering_drops_and_structural_moves_are_classified_not_mapped(): void
    {
        [$order, $dropped, $structural] = [$this->notes()[3], $this->notes()[4], $this->notes()[5]];

        self::assertSame(SpecNotes::ORDER, $order->kind);
        self::assertSame(SpecNotes::DROPPED, $dropped->kind);
        self::assertSame(SpecNotes::STRUCTURAL, $structural->kind);

        foreach ([$order, $dropped, $structural] as $n) {
            self::assertFalse($n->isMapped(), 'these must not become field maps');
        }
    }

    #[Test]
    public function a_dropped_row_does_not_borrow_the_field_named_in_its_reason(): void
    {
        // "(dropped — level comes from `titleLevel`)" must not map niv -> titleLevel.
        self::assertSame([], $this->notes()[4]->targets);
    }

    #[Test]
    public function a_tree_parent_target_names_a_section_not_a_field(): void
    {
        // `| \`landingPage\` (Node) | tree parent (child of \`eventOverviewPage\`) |` was read
        // as mapping the column to a field called `eventOverviewPage`, which no editorial
        // entry type has — one false divergence per editorial page type.
        $notes = $this->notesFrom(<<<'MD'
            # Event Page

            ## Migration notes (Kunstmaan → Craft)

            | Kunstmaan (`EventPage`) | New field |
            |---|---|
            | `landingPage` (Node) | tree parent (child of `eventOverviewPage`) |
            MD);

        self::assertSame([], $notes[0]->targets);
        self::assertFalse($notes[0]->isMapped());
    }
}
