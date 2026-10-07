<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\load;

use Lameco\Kunstmaanmigrator\load\EntryExplanation;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EntryExplanationTest extends TestCase
{
    /** @return list<array{lang: string, context: string, part: string, entity: string, id: int, sequence: int}> */
    private function parts(array ...$rows): array
    {
        return array_map(static fn(array $r): array => [
            'lang' => $r[3] ?? 'en',
            'context' => $r[2],
            'part' => $r[0],
            'entity' => 'App\\Entity\\PageParts\\' . $r[0] . 'PagePart',
            'id' => $r[1],
            'sequence' => 1,
        ], $rows);
    }

    #[Test]
    public function a_part_that_became_a_block_is_not_reported(): void
    {
        $result = EntryExplanation::reconcile(
            'COM',
            ['comEnUs' => ['COM:text_page_parts:5' => '900']],
            $this->parts(['Text', 5, 'main']),
            ['Text' => 'blocks'],
            ['Text' => 'text_page_parts'],
        );

        self::assertSame([], $result['unexplained']);
        self::assertSame(1, $result['written']);
    }

    #[Test]
    public function a_nested_row_is_not_counted_as_a_second_part(): void
    {
        // `...:5#buttons[0]` is a row inside the block written for `...:5`. Counting it would
        // report more parts migrated than the page ever held.
        $result = EntryExplanation::reconcile(
            'COM',
            ['comEnUs' => ['COM:text_page_parts:5' => '900', 'COM:text_page_parts:5#buttons[0]' => '901']],
            $this->parts(['Text', 5, 'main']),
            ['Text' => 'blocks'],
            ['Text' => 'text_page_parts'],
        );

        self::assertSame(1, $result['written']);
    }

    #[Test]
    public function a_part_the_blocks_lane_claims_but_never_wrote_is_unexplained(): void
    {
        $result = EntryExplanation::reconcile(
            'COM',
            ['comEnUs' => []],
            $this->parts(['Text', 5, 'main']),
            ['Text' => 'blocks'],
            ['Text' => 'text_page_parts'],
        );

        self::assertCount(1, $result['unexplained']);
        self::assertSame([], $result['accountedFor']);
    }

    #[Test]
    public function a_part_another_lane_owns_is_missing_by_decision(): void
    {
        $result = EntryExplanation::reconcile(
            'COM',
            ['comEnUs' => []],
            $this->parts(['SingleLineText', 8, 'form']),
            ['SingleLineText' => 'forms'],
            [],
        );

        self::assertSame([], $result['unexplained']);
        self::assertCount(1, $result['accountedFor']);
    }

    #[Test]
    public function a_part_in_an_undeclared_context_is_missing_by_decision(): void
    {
        // On a corpus where `form` and eight `footer-*` contexts are deliberately left out,
        // this is most of what would otherwise land in the defect list.
        $result = EntryExplanation::reconcile(
            'COM',
            ['comEnUs' => []],
            $this->parts(['Text', 5, 'footer-top']),
            ['Text' => 'blocks'],
            ['Text' => 'text_page_parts'],
            ['main', 'text'],
        );

        self::assertSame([], $result['unexplained']);
        self::assertStringContainsString('footer-top', (string) $result['accountedFor'][0]['why']);
    }

    #[Test]
    public function a_class_no_lane_names_says_so(): void
    {
        $result = EntryExplanation::reconcile('COM', [], $this->parts(['Mystery', 1, 'main']), [], []);

        self::assertStringContainsString('coverage', (string) $result['unexplained'][0]['why']);
    }

    #[Test]
    public function one_placement_live_in_several_locales_is_one_part(): void
    {
        $result = EntryExplanation::reconcile(
            'COM',
            ['comEnUs' => []],
            $this->parts(['Text', 5, 'main', 'en'], ['Text', 5, 'main', 'nl']),
            ['Text' => 'blocks'],
            ['Text' => 'text_page_parts'],
        );

        self::assertCount(1, $result['unexplained']);
    }

    #[Test]
    public function a_part_live_only_in_a_locale_with_no_craft_site_is_missing_by_decision(): void
    {
        // COM:sp on the reference corpus — 335 live pages, declared `!unmapped` with a reason.
        // Counted as loss it put a client decision at the top of the defect list, and it was
        // 5 of the first 14 findings the tool produced.
        $result = EntryExplanation::reconcile(
            'COM',
            ['comEnUs' => []],
            $this->parts(['Text', 5, 'main', 'sp']),
            ['Text' => 'blocks'],
            ['Text' => 'text_page_parts'],
            ['main'],
            ['en', 'fr'],
        );

        self::assertSame([], $result['unexplained']);
        self::assertStringContainsString('sp', (string) $result['accountedFor'][0]['why']);
    }

    #[Test]
    public function a_part_live_in_one_migrated_locale_and_one_stranded_one_is_still_a_defect(): void
    {
        // The langs have to be collected across the whole group before the verdict: judging on
        // whichever row came first would excuse a real loss because the part also exists in a
        // locale nobody is migrating.
        $result = EntryExplanation::reconcile(
            'COM',
            ['comEnUs' => []],
            $this->parts(['Text', 5, 'main', 'sp'], ['Text', 5, 'main', 'en']),
            ['Text' => 'blocks'],
            ['Text' => 'text_page_parts'],
            ['main'],
            ['en', 'fr'],
        );

        self::assertCount(1, $result['unexplained']);
        self::assertSame([], $result['accountedFor']);
    }

    #[Test]
    public function a_page_part_is_reported_as_written_to_page_fields_and_a_second_one_as_a_loss(): void
    {
        $parts = $this->parts(['Header', 3, 'header', 'nl'], ['Header', 4, 'header', 'nl']);
        $parts[1]['sequence'] = 2;

        $result = EntryExplanation::reconcile(
            'NL',
            [],
            $parts,
            ['Header' => 'page'],
            ['Header' => 'header_page_parts'],
            ['main'],
            ['nl'],
            ['header'],
        );

        self::assertSame([], $result['unexplained']);
        self::assertSame(
            [
                ['Header', 3, 'written to the page\'s own fields from the `header` page context, not as a block'],
                ['Header', 4, 'not written: the `header` page context fills the page\'s fields from its first part only'],
            ],
            array_map(static fn(array $r): array => [$r['part'], $r['id'], $r['why']], $result['accountedFor']),
        );
    }

    #[Test]
    public function a_page_part_outside_a_page_context_is_said_to_be_unwritten(): void
    {
        $result = EntryExplanation::reconcile(
            'NL',
            [],
            $this->parts(['Header', 3, 'main', 'nl']),
            ['Header' => 'page'],
            ['Header' => 'header_page_parts'],
            ['main'],
            ['nl'],
            ['header'],
        );

        self::assertSame(
            'not written: `main` is not a `target: page` context, and a `consumedBy: page` part becomes no block',
            $result['accountedFor'][0]['why'],
        );
    }
}
