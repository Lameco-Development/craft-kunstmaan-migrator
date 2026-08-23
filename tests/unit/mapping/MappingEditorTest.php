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
        $catalogue = new InMemoryTargetCatalogue(['contentPage', 'newsPage'], ['Pages' => ['contentPage']]);

        self::assertInstanceOf(TargetCatalogue::class, $catalogue);
        self::assertSame(['contentPage', 'newsPage'], $catalogue->entryTypes());
        self::assertSame(['Pages' => ['contentPage']], $catalogue->entryTypesBySection());
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

    /**
     * Un-deciding is a decision too: clearing a chosen target puts the row
     * back to open. The old whole-document diff refused exactly this — the
     * cleared target raised a "new" completeness error and the save bounced.
     */
    public function testClearingATargetIsASaveNotAnError(): void
    {
        $path = $this->mappingFile();
        $editor = $this->editor($path);

        $editor->patch('pages', 'ContentPage', ['entryType' => 'contentPage']);
        $editor->patch('pages', 'ContentPage', ['entryType' => null]);

        self::assertStringNotContainsString('entryType: contentPage', (string) file_get_contents($path));
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


    /**
     * The compiler drops a sidecar field the page's entry type lacks and
     * counts it — hours later. The editing screen states the same fact up
     * front: which mapped entry types carry each field, and which will drop it.
     */
    public function testSidecarCarriageNamesTheEntryTypesThatDropAField(): void
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
              NewsPage: { entryType: newsPage }
            sidecars:
              headerTab:
                table: header_tabs
                map: { heroTitle: title }
            YAML);

        $schema = $this->createStub(SchemaGateway::class);
        $schema->method('fieldSlotsFor')->willReturnCallback(static fn (string $entryType): array => match ($entryType) {
            'contentPage' => ['heroTitle' => ['type' => 'plaintext', 'required' => false, 'nested' => []]],
            default => [],
        });

        $editor = new MappingEditor(
            SettingsFactory::make(['mappingPath' => $path]),
            $schema,
            new TargetModel($schema),
            new InMemoryTargetCatalogue(),
        );

        self::assertSame(
            ['heroTitle' => ['carried' => 1, 'total' => 2, 'missing' => ['newsPage']]],
            $editor->sidecarCarriage(MappingRow::fromSpec('headerTab', ['map' => ['heroTitle' => 'title']])),
        );
    }

    /**
     * The lanes answer "what does this legacy thing become"; the operator
     * verifies with the inverse — every field of one entry type and what
     * feeds it: page maps, sidecars, the parts lane through a context field.
     * A required field nothing feeds is the hole the view exists to show.
     */
    public function testCoverageAnswersWhatFeedsEachFieldOfAnEntryType(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'mapping') . '.yaml';

        file_put_contents($path, <<<'YAML'
            version: 1
            environments:
              COM:
                database: legacy
                locales: { en: siteEn }
            defaults:
              contexts:
                main: { field: pageBuilder }
            pages:
              ContentPage:
                entryType: contentPage
                map: { summary: 'summary | ckeditor' }
              NewsPage: { entryType: newsPage }
            parts:
              Text: { block: contentBlock }
              RowStart: { drop: layout bracket }
            sidecars:
              headerTab:
                table: header_tabs
                map: { heroTitle: 'title | inlineHtml' }
            YAML);

        $schema = $this->createStub(SchemaGateway::class);
        $schema->method('fieldSlotsFor')->willReturn([
            'pageBuilder' => ['type' => 'matrix', 'required' => false, 'nested' => ['contentBlock']],
            'summary' => ['type' => 'ckeditor', 'required' => false, 'nested' => []],
            'heroTitle' => ['type' => 'plaintext', 'required' => false, 'nested' => []],
            'orphan' => ['type' => 'plaintext', 'required' => true, 'nested' => []],
        ]);

        $editor = new MappingEditor(
            SettingsFactory::make(['mappingPath' => $path]),
            $schema,
            new TargetModel($schema),
            new InMemoryTargetCatalogue(),
        );

        $coverage = $editor->coverageFor('contentPage');

        self::assertSame(['ContentPage'], $coverage['pageTypes']);
        self::assertSame(1, $coverage['fields']['pageBuilder']['parts'], 'one part becomes a block; a dropped part feeds nothing');
        self::assertSame(
            [['page' => 'ContentPage', 'expression' => 'summary | ckeditor']],
            $coverage['fields']['summary']['pages'],
        );
        self::assertSame(
            [['sidecar' => 'headerTab', 'expression' => 'title | inlineHtml']],
            $coverage['fields']['heroTitle']['sidecars'],
        );
        self::assertTrue($coverage['fields']['orphan']['required']);
        self::assertSame(
            ['required' => true, 'pages' => [], 'sidecars' => [], 'parts' => null],
            $coverage['fields']['orphan'],
            'nothing feeds it, and the screen must say so',
        );
    }


    /**
     * Samples exist to make `title` vs `page_title` a choice instead of a coin
     * flip — so they must be displayable: markup stripped, whitespace
     * collapsed, cut at 40 characters, three distinct values at most, and no
     * `id` column (nobody maps the primary key by sample).
     */
    public function testSamplesAreDistinctDisplayableGlimpses(): void
    {
        $samples = MappingEditor::aggregateSamples([
            ['id' => 1, 'title' => '<p>Over  ons</p>', 'body' => str_repeat('x', 60), 'empty' => '', 'blob' => null],
            ['id' => 2, 'title' => 'Over ons', 'body' => 'short', 'empty' => '   ', 'blob' => null],
            ['id' => 3, 'title' => 'Contact', 'body' => 'short', 'empty' => '', 'blob' => null],
            ['id' => 4, 'title' => 'Vacatures', 'body' => 'b', 'empty' => '', 'blob' => null],
            ['id' => 5, 'title' => 'Nieuws', 'body' => 'c', 'empty' => '', 'blob' => null],
        ]);

        self::assertSame(['Over ons', 'Contact', 'Vacatures'], $samples['title'], 'distinct, stripped, capped at three');
        self::assertSame(str_repeat('x', 40) . '…', $samples['body'][0], 'long values are cut, and say so');
        self::assertArrayNotHasKey('empty', $samples);
        self::assertArrayNotHasKey('blob', $samples);
        self::assertArrayNotHasKey('id', $samples);
    }

    /**
     * The roll-up that saves clicking through every entry type: only the types
     * with unfed fields appear, and an empty list is the finished state.
     */
    public function testCoverageGapsListOnlyTheEntryTypesWithHoles(): void
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
              NewsPage: { entryType: newsPage }
            sidecars:
              headerTab:
                table: header_tabs
                map: { heroTitle: 'title | inlineHtml' }
            YAML);

        $schema = $this->createStub(SchemaGateway::class);
        $schema->method('fieldSlotsFor')->willReturnCallback(static fn (string $entryType): array => match ($entryType) {
            'contentPage' => ['heroTitle' => ['type' => 'plaintext', 'required' => false, 'nested' => []]],
            'newsPage' => [
                'heroTitle' => ['type' => 'plaintext', 'required' => false, 'nested' => []],
                'intro' => ['type' => 'plaintext', 'required' => true, 'nested' => []],
            ],
            default => [],
        });

        $editor = new MappingEditor(
            SettingsFactory::make(['mappingPath' => $path]),
            $schema,
            new TargetModel($schema),
            new InMemoryTargetCatalogue(),
        );

        self::assertSame(
            [['entryType' => 'newsPage', 'unfed' => 1, 'required' => 1]],
            $editor->coverageGaps(),
            'contentPage is fully fed by the sidecar and stays out of the list',
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
