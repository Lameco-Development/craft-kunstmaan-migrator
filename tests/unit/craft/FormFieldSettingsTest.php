<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\craft;

use Lameco\Kunstmaanmigrator\craft\FormFieldSettings;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FormFieldSettingsTest extends TestCase
{
    #[Test]
    public function a_list_of_strings_becomes_the_option_rows_formie_stores(): void
    {
        self::assertSame(
            [
                ['label' => '2 - 5', 'value' => '2 - 5', 'isDefault' => false],
                ['label' => '6 - 20', 'value' => '6 - 20', 'isDefault' => false],
            ],
            FormFieldSettings::options(['2 - 5', '6 - 20']),
        );
    }

    #[Test]
    public function an_already_shaped_option_list_is_left_alone(): void
    {
        $shaped = [['label' => 'Ja', 'value' => 'yes', 'isDefault' => true]];

        self::assertSame($shaped, FormFieldSettings::options($shaped));
    }

    #[Test]
    public function nothing_to_set_stays_nothing_to_set(): void
    {
        // Overwriting with an empty list would take away the placeholder Formie's own
        // Dropdown ships with and leave a select an editor cannot tell apart from a bug.
        self::assertNull(FormFieldSettings::options([]));
        self::assertNull(FormFieldSettings::options(null));
        self::assertNull(FormFieldSettings::options('2 - 5'));
    }

    #[Test]
    public function an_attribute_map_becomes_the_label_value_rows_formie_stores(): void
    {
        self::assertSame(
            [['label' => 'data-option', 'value' => '']],
            FormFieldSettings::inputAttributes(['data-option' => '']),
        );
    }

    #[Test]
    public function an_already_shaped_attribute_list_is_left_alone(): void
    {
        $shaped = [['label' => 'data-option', 'value' => '1']];

        self::assertSame($shaped, FormFieldSettings::inputAttributes($shaped));
    }

    #[Test]
    public function a_consent_sentence_with_a_link_keeps_the_link(): void
    {
        // The shape is read off a hand-built Agree field on the live site, so a migrated
        // consent field and one an editor made are the same row to Formie.
        self::assertSame(
            [[
                'type' => 'paragraph',
                'attrs' => ['textAlign' => 'start'],
                'content' => [
                    ['type' => 'text', 'text' => 'Ik ga akkoord met de '],
                    [
                        'type' => 'text',
                        'marks' => [[
                            'type' => 'link',
                            'attrs' => [
                                'href' => 'https://enreach.com/nl/privacy-statement',
                                'target' => null,
                                'rel' => null,
                                'class' => null,
                            ],
                        ]],
                        'text' => 'algemene voorwaarden',
                    ],
                    ['type' => 'text', 'text' => '.'],
                ],
            ]],
            FormFieldSettings::prose(
                'Ik ga akkoord met de <a href="https://enreach.com/nl/privacy-statement">algemene voorwaarden</a>.',
            ),
        );
    }

    #[Test]
    public function a_link_that_states_a_target_keeps_it(): void
    {
        $nodes = FormFieldSettings::prose('<a href="/terms" target="_blank" rel="noopener">Terms</a>');

        self::assertSame(
            ['href' => '/terms', 'target' => '_blank', 'rel' => 'noopener', 'class' => null],
            $nodes[0]['content'][0]['marks'][0]['attrs'],
        );
    }

    #[Test]
    public function emphasis_survives_as_a_mark(): void
    {
        $nodes = FormFieldSettings::prose('I agree to the <strong>terms</strong>');

        self::assertSame([['type' => 'bold']], $nodes[0]['content'][1]['marks']);
        self::assertSame('terms', $nodes[0]['content'][1]['text']);
    }

    #[Test]
    public function a_line_break_is_a_node_rather_than_a_new_paragraph(): void
    {
        $nodes = FormFieldSettings::prose('One<br>Two');

        self::assertCount(1, $nodes);
        self::assertSame('hardBreak', $nodes[0]['content'][1]['type']);
    }

    #[Test]
    public function a_tag_outside_the_subset_keeps_its_words(): void
    {
        // Legacy consent labels are plain text and anchors and nothing else, measured
        // across the corpus. A tag that is not in the subset must still not eat its text.
        self::assertSame(
            [[
                'type' => 'paragraph',
                'attrs' => ['textAlign' => 'start'],
                'content' => [['type' => 'text', 'text' => 'Accept the terms']],
            ]],
            FormFieldSettings::prose('<div><span class="x">Accept</span> the terms</div>'),
        );
    }

    #[Test]
    public function two_paragraphs_stay_two_paragraphs(): void
    {
        $nodes = FormFieldSettings::prose('<p>First</p><p>Second</p>');

        self::assertCount(2, $nodes);
        self::assertSame('Second', $nodes[1]['content'][0]['text']);
    }

    #[Test]
    public function text_with_no_words_is_no_description_at_all(): void
    {
        self::assertNull(FormFieldSettings::prose(''));
        self::assertNull(FormFieldSettings::prose('<p> </p>'));
        self::assertNull(FormFieldSettings::prose(null));
    }

    #[Test]
    public function entities_are_decoded_once_and_not_left_doubled(): void
    {
        $nodes = FormFieldSettings::prose('Terms &amp; conditions');

        self::assertSame('Terms & conditions', $nodes[0]['content'][0]['text']);
    }
}
