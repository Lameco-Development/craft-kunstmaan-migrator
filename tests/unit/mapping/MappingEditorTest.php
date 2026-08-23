<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\mapping;

use lameco\kunstmaanmigrator\mapping\MappingEditor;
use lameco\kunstmaanmigrator\mapping\MappingEditorException;
use lameco\kunstmaanmigrator\mapping\MappingRow;
use lameco\kunstmaanmigrator\mapping\TargetCatalogue;
use lameco\kunstmaanmigrator\compile\TargetModel;
use lameco\kunstmaanmigrator\payload\SchemaGateway;
use lameco\kunstmaanmigrator\tests\support\InMemoryTargetCatalogue;
use lameco\kunstmaanmigrator\tests\support\SettingsFactory;
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

    /**
     * A row with a target still has unplaced columns, so "decided" has to mean
     * decided — otherwise a progress bar reads 100% while a third of the
     * content still has nowhere to go, which is worse than no bar at all.
     */
    public function testProgressCountsOnlyRowsThatAreActuallySettled(): void
    {
        $rows = [
            MappingRow::fromSpec('Text', ['block' => 'contentBlock']),
            MappingRow::fromSpec('Header', ['block' => 'headingBlock', 'unreviewed' => ['niv']]),
            MappingRow::fromSpec('RowStart', ['drop' => 'layout bracket']),
            MappingRow::fromSpec('Quote', []),
        ];

        $counts = array_count_values(array_map(
            static fn (MappingRow $row): string => $row->status(),
            $rows,
        ));

        self::assertSame(1, $counts[MappingRow::DECIDED]);
        self::assertSame(1, $counts[MappingRow::DROPPED]);
        self::assertSame(2, $counts[MappingRow::OPEN], 'a row with unplaced columns is not finished');
    }

    /**
     * A fresh skeleton fails whole-document validation by design — every row
     * still open — and the row screen is the advertised way to close them one
     * at a time. A save may only be refused for the damage it does itself.
     */
    public function testASaveIsNotBlockedByRowsItDidNotTouch(): void
    {
        $path = $this->mappingFile();

        $this->editor($path)->patch('pages', 'ContentPage', ['entryType' => 'contentPage']);

        self::assertStringContainsString('entryType: contentPage', (string) file_get_contents($path));
    }

    public function testASaveThatBreaksItsOwnRowStillFails(): void
    {
        $path = $this->mappingFile();

        try {
            $this->editor($path)->patch('pages', 'ContentPage', ['bogus' => 'x']);
            self::fail('an edit introducing an error must not be written');
        } catch (MappingEditorException $e) {
            self::assertStringContainsString('bogus', $e->getMessage());
        }

        self::assertStringNotContainsString('bogus', (string) file_get_contents($path));
    }

    /**
     * Sixty flat handles is a list you search, not read: entry types come
     * grouped by section, with the types no section uses closing the list.
     */
    public function testTargetOptionsGroupEntryTypesBySection(): void
    {
        $editor = $this->editor($this->mappingFile(), new InMemoryTargetCatalogue(
            ['article', 'contentPage', 'quote'],
            ['news', 'pages'],
            ['News' => ['article'], 'Pages' => ['contentPage']],
        ));

        self::assertSame(
            [
                ['optgroup' => 'News'],
                ['label' => 'article', 'value' => 'article'],
                ['optgroup' => 'Pages'],
                ['label' => 'contentPage', 'value' => 'contentPage'],
                ['optgroup' => 'Not in a section'],
                ['label' => 'quote', 'value' => 'quote'],
            ],
            $editor->targetOptions('pages'),
        );
    }


    /**
     * A page row that shows `heroTitle — not filled —` while the headerTab
     * sidecar fills it on every decorated page is lying to the operator: the
     * page screen names the sidecar that covers each field. Dropped and manual
     * sidecars cover nothing, and a field the entry type lacks is not covered.
     */
    public function testThePageRowKnowsWhichFieldsTheSidecarsFill(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'mapping') . '.yaml';

        file_put_contents($path, <<<'YAML'
            version: 1
            environments:
              COM:
                database: legacy
                locales: { en: siteEn }
            pages:
              ContentPage: { entryType: contentPage }
            sidecars:
              headerTab:
                table: header_tabs
                map:
                  heroTitle: title | inlineHtml
                  applyType: apply_type
              footerTab:
                table: footer_tabs
                manual: decide later
            YAML);

        $schema = $this->createStub(SchemaGateway::class);
        $schema->method('fieldSlotsFor')->willReturn([
            'heroTitle' => ['type' => 'plaintext', 'required' => false, 'nested' => []],
        ]);

        $editor = new MappingEditor(
            SettingsFactory::make(['mappingPath' => $path]),
            $schema,
            new TargetModel($schema),
            new InMemoryTargetCatalogue(),
        );

        self::assertSame(
            ['heroTitle' => [['sidecar' => 'headerTab', 'expression' => 'title | inlineHtml']]],
            $editor->sidecarFillsFor('contentPage'),
        );
    }

    /** Two open pages, as `mapping/init` leaves them. */
    private function mappingFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mapping') . '.yaml';

        file_put_contents($path, <<<'YAML'
            version: 1
            environments:
              COM:
                database: legacy
                locales: { en: siteEn }
            pages:
              ContentPage: {}
              BlogPage: {}
            YAML);

        return $path;
    }

    private function editor(string $path, ?TargetCatalogue $catalogue = null): MappingEditor
    {
        $schema = $this->createStub(SchemaGateway::class);

        return new MappingEditor(
            SettingsFactory::make(['mappingPath' => $path]),
            $schema,
            new TargetModel($schema),
            $catalogue ?? new InMemoryTargetCatalogue(),
        );
    }
}
