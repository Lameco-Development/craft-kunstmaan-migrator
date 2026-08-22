<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\mapping;

use lameco\kunstmaanmigrator\mapping\MappingRow;
use lameco\kunstmaanmigrator\mapping\TargetCatalogue;
use lameco\kunstmaanmigrator\tests\support\InMemoryTargetCatalogue;
use PHPUnit\Framework\TestCase;

/**
 * What the editor shows about a row, and what it offers to change it to.
 */
final class MappingEditorTest extends TestCase
{
    /**
     * `ignore: [color, show_as_slider]` is the list form of a real decision
     * that predates reasons being required; a column in `unreviewed:` is one
     * nobody has looked at. Both carry no reason and they are not the same
     * thing — collapsing them is how a screen shows a decision as an oversight.
     */
    public function testADecisionWithoutAReasonIsNotAnOversight(): void
    {
        $row = MappingRow::fromSpec('Logo', [
            'ignore' => ['color', 'show_as_slider'],
            'unreviewed' => ['text_above_logos'],
        ]);

        self::assertSame(
            [
                'color' => ['ignored' => true, 'reason' => null],
                'show_as_slider' => ['ignored' => true, 'reason' => null],
                'text_above_logos' => ['ignored' => false, 'reason' => null],
            ],
            $row->columns(),
        );
    }

    public function testAReasonIsCarriedWhenTheFileGivesOne(): void
    {
        $row = MappingRow::fromSpec('Partner', [
            'ignore' => ['weight' => 'Craft orders the structure itself'],
        ]);

        self::assertSame(
            ['weight' => ['ignored' => true, 'reason' => 'Craft orders the structure itself']],
            $row->columns(),
        );
    }

    /**
     * A row with a target still has unplaced content, and a list that called
     * that finished would hide the remaining work.
     */
    public function testATargetIsNotEnoughToCallARowDecided(): void
    {
        $decided = MappingRow::fromSpec('Text', ['block' => 'contentBlock']);
        $open = MappingRow::fromSpec('Text', ['block' => 'contentBlock', 'unreviewed' => ['alignment']]);
        $dropped = MappingRow::fromSpec('RowStart', ['drop' => 'layout bracket']);

        self::assertSame(MappingRow::DECIDED, $decided->status());
        self::assertSame(MappingRow::OPEN, $open->status());
        self::assertSame(MappingRow::DROPPED, $dropped->status());
    }

    /**
     * Each lane names its target under a different key, and the screen should
     * not have to know which.
     */
    public function testEachLaneFindsItsTargetUnderItsOwnKey(): void
    {
        self::assertSame('contentBlock', MappingRow::fromSpec('Text', ['block' => 'contentBlock'])->target);
        self::assertSame('contentPage', MappingRow::fromSpec('ContentPage', ['entryType' => 'contentPage'])->target);
    }

    /**
     * A mapped column is not listed among the decisions still owed: it lives
     * inside an expression on the Craft field that eats it, and one expression
     * can consume several. Listing them again would invite two answers to one
     * question.
     */
    public function testMappedColumnsAreNotListedAsDecisions(): void
    {
        $row = MappingRow::fromSpec('Logo', [
            'map' => ['heading' => 'title | inlineHtml', 'link' => 'link(link_url, title)'],
            'unreviewed' => ['color'],
        ]);

        self::assertSame(['color'], array_keys($row->columns()));
    }

    /** The catalogue is a real seam: two adapters, one of them not Craft. */
    public function testTheCatalogueHasASecondAdapter(): void
    {
        $catalogue = new InMemoryTargetCatalogue(['contentPage', 'newsPage'], ['pages']);

        self::assertInstanceOf(TargetCatalogue::class, $catalogue);
        self::assertSame(['contentPage', 'newsPage'], $catalogue->entryTypes());
        self::assertSame(['pages'], $catalogue->sections());
    }
}
