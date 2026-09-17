<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\kernel;

use Lameco\Kunstmaanmigrator\Compile\RowCondition;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RowConditionTest extends TestCase
{
    /** A legacy Choice: a consent checkbox, which is `expanded` + `multiple` + one option. */
    private const CONSENT = ['expanded' => 1, 'multiple' => 1, 'choices' => 'Hiermit willige ich ein.', 'label' => '_'];

    /** The same part configured as a radio group. */
    private const RADIO = ['expanded' => 1, 'multiple' => 0, 'choices' => "Ja\nNee\nWeet ik niet", 'label' => 'Interesse?'];

    #[Test]
    public function a_column_compared_to_a_number(): void
    {
        self::assertTrue(RowCondition::matches('expanded == 1', self::RADIO));
        self::assertFalse(RowCondition::matches('multiple == 1', self::RADIO));
        self::assertTrue(RowCondition::matches('multiple != 1', self::RADIO));
    }

    #[Test]
    public function conditions_join_with_and(): void
    {
        self::assertTrue(RowCondition::matches('expanded == 1 and multiple == 1', self::CONSENT));
        self::assertFalse(RowCondition::matches('expanded == 1 and multiple == 1', self::RADIO));
    }

    #[Test]
    public function lines_counts_the_entries_a_textarea_holds(): void
    {
        // The difference between a consent checkbox and a checkbox group is the number
        // of options, not the configuration — both are `expanded` and `multiple`.
        self::assertTrue(RowCondition::matches('lines(choices) == 1', self::CONSENT));
        self::assertFalse(RowCondition::matches('lines(choices) == 1', self::RADIO));
        self::assertTrue(RowCondition::matches('lines(choices) == 3', self::RADIO));
    }

    #[Test]
    public function lines_counts_a_crlf_corpus_the_same_way_the_transform_splits_it(): void
    {
        $row = ['choices' => "Ja\r\n\r\n Nee \r\n"];

        self::assertTrue(RowCondition::matches('lines(choices) == 2', $row), 'blanks are not options');
    }

    #[Test]
    public function a_column_compared_to_null_reads_an_empty_string_as_null_too(): void
    {
        // A legacy column is rarely NULL; it is an empty string, and a mapping that had
        // to know which would be describing the database rather than the content.
        self::assertTrue(RowCondition::matches('internal_name == null', ['internal_name' => '']));
        self::assertTrue(RowCondition::matches('internal_name == null', []));
        self::assertTrue(RowCondition::matches('internal_name != null', ['internal_name' => 'email']));
    }

    #[Test]
    public function a_column_compared_to_a_quoted_string(): void
    {
        self::assertTrue(RowCondition::matches("label == '_'", self::CONSENT));
        self::assertFalse(RowCondition::matches("label == '_'", self::RADIO));
    }

    #[Test]
    public function an_expression_it_cannot_read_never_matches(): void
    {
        // A `when:` that silently passed would pick the wrong widget for every row
        // rather than none, which is the harder failure to notice.
        self::assertFalse(RowCondition::matches('expanded > 1', self::RADIO));
        self::assertFalse(RowCondition::matches('children.any(item.icon_id != null)', self::RADIO));
        self::assertFalse(RowCondition::matches('', self::RADIO));
    }
}
