<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\load;

use lameco\kunstmaanmigrator\load\EntryExplanation;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EntryExplanationTest extends TestCase
{
    /** @return list<array{lang: string, context: string, part: string, entity: string, id: int, sequence: int}> */
    private function parts(array ...$rows): array
    {
        return array_map(static fn (array $r): array => [
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
}
