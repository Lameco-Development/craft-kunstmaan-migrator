<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\kernel;

use Lameco\Kunstmaanmigrator\Mapping\Mapping;
use Lameco\Kunstmaanmigrator\Mapping\PageRow;
use Lameco\Kunstmaanmigrator\Mapping\PartRow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The typed rows are the one reading of the row grammar. Before them, eight consumers each
 * re-parsed `drop:` / `manual:` / `consumedBy:` and two of them (the compiler and the target
 * check) only took a part out of the blocks lane when it carried *both* `drop:` and `manual:`.
 * These tests pin the one truth table and the one fallback rule for contexts.
 */
final class MappingRowsTest extends TestCase
{
    private function mapping(string $yaml): Mapping
    {
        $path = tempnam(sys_get_temp_dir(), 'kuma') . '.yaml';
        file_put_contents($path, $yaml);

        return Mapping::fromFile($path);
    }

    /** @return iterable<string, array{0: string, 1: string, 2: bool}> */
    public static function dispositions(): iterable
    {
        yield 'block only' => ['block: textBlock', PartRow::BLOCKS, true];
        yield 'switch only' => ["switch: [{ when: 'x', block: textBlock }]", PartRow::BLOCKS, true];
        yield 'drop only' => ['drop: legacy', PartRow::DROPPED, false];
        yield 'manual only' => ['manual: rebuilt by hand', PartRow::MANUAL, false];
        yield 'consumed by sequence' => ['consumedBy: sequence', PartRow::SEQUENCE, false];
        yield 'block and drop' => ["block: textBlock\n    drop: legacy", PartRow::DROPPED, false];
        yield 'block and manual' => ["block: textBlock\n    manual: by hand", PartRow::MANUAL, false];
        yield 'nothing decided yet' => ['table: text_parts', PartRow::BLOCKS, true];
    }

    #[Test]
    #[DataProvider('dispositions')]
    public function a_part_has_one_disposition(string $keys, string $disposition, bool $compiles): void
    {
        // `drop:` OR `manual:` takes a part out of the blocks lane — either one, not both.
        $row = $this->mapping("version: 1\nparts:\n  Text:\n    {$keys}\n")->partRow('Text');

        self::assertNotNull($row);
        self::assertSame($disposition, $row->disposition());
        self::assertSame($compiles, $row->compilesToBlocks());
    }

    #[Test]
    public function a_sequence_consumed_part_is_migrated_but_not_by_the_blocks_lane(): void
    {
        $row = $this->mapping(<<<'YAML'
            version: 1
            parts:
              Header:
                table: header_parts
                consumedBy: sequence
            YAML)->partRow('Header');

        self::assertNotNull($row);
        self::assertTrue($row->isMigrated());
        self::assertFalse($row->compilesToBlocks());
    }

    #[Test]
    public function the_blocks_of_a_part_include_its_switch_cases_once(): void
    {
        $row = $this->mapping(<<<'YAML'
            version: 1
            parts:
              Media:
                table: media_parts
                switch:
                  - { when: "kind = 'video'", block: videoBlock }
                  - { when: "kind = 'image'", block: imageBlock }
                  - { when: "kind = 'gif'", block: imageBlock }
            YAML)->partRow('Media');

        self::assertNotNull($row);
        self::assertNull($row->block());
        self::assertSame(['videoBlock', 'imageBlock'], $row->blocks());
        self::assertTrue($row->isDecided());
    }

    #[Test]
    public function an_undecided_part_is_the_one_a_suggester_may_draft(): void
    {
        $mapping = $this->mapping(<<<'YAML'
            version: 1
            parts:
              Text: { table: text_parts }
              Quote: { table: quote_parts, drop: nobody used it }
              Video: ~
            YAML);

        self::assertFalse($mapping->partRow('Text')?->isDecided());
        self::assertTrue($mapping->partRow('Quote')?->isDecided());
        self::assertSame('nobody used it', $mapping->partRow('Quote')?->reason());
        self::assertFalse($mapping->partRow('Video')?->isDecided());
        self::assertNull($mapping->partRow('Unknown'));
    }

    #[Test]
    public function absorb_into_tells_a_refusal_from_a_nested_path(): void
    {
        $mapping = $this->mapping(<<<'YAML'
            version: 1
            parts:
              Quote: { table: q, block: calloutBlock, absorbInto: false }
              Columns: { table: c, block: columnsBlock, absorbInto: "columns[0]" }
              Text: { table: t, block: textBlock }
            YAML);

        self::assertTrue($mapping->partRow('Quote')?->refusesAbsorption());
        self::assertNull($mapping->partRow('Quote')?->absorbInto());
        self::assertFalse($mapping->partRow('Columns')?->refusesAbsorption());
        self::assertSame('columns[0]', $mapping->partRow('Columns')?->absorbInto());
        self::assertFalse($mapping->partRow('Text')?->refusesAbsorption());
        self::assertNull($mapping->partRow('Text')?->absorbInto());
    }

    #[Test]
    public function a_page_falls_back_to_the_defaults_and_then_to_the_compilers_own(): void
    {
        $withDefaults = $this->mapping(<<<'YAML'
            version: 1
            defaults:
              contexts:
                main: { field: pageBuilder }
                top: { field: heroBuilder, prepend: true }
            pages:
              ContentPage: { entryType: contentPage }
              CasePage:
                entryType: casePage
                contexts:
                  main: { field: caseBuilder }
            YAML);

        self::assertSame(['pageBuilder', 'heroBuilder'], $withDefaults->pageRow('ContentPage')?->contextFields());
        self::assertSame(['caseBuilder'], $withDefaults->pageRow('CasePage')?->contextFields());
        self::assertSame('pageBuilder', $withDefaults->pageRow('ContentPage')?->builderField());
        self::assertSame(
            ['main' => ['field' => 'pageBuilder'], 'top' => ['field' => 'heroBuilder', 'prepend' => true]],
            $withDefaults->pageRow('ContentPage')?->contexts(),
        );

        $withoutDefaults = $this->mapping(<<<'YAML'
            version: 1
            pages:
              ContentPage: { entryType: contentPage }
            YAML);

        self::assertSame(Mapping::DEFAULT_CONTEXTS, $withoutDefaults->defaultContexts());
        self::assertSame(['commonPageBuilder'], $withoutDefaults->pageRow('ContentPage')?->contextFields());
    }

    #[Test]
    public function a_context_with_no_field_streams_into_page_builder(): void
    {
        // The compiler's default for a context that names no field, now stated once.
        $row = $this->mapping(<<<'YAML'
            version: 1
            pages:
              ContentPage:
                entryType: contentPage
                contexts:
                  main: {}
                  top: { prepend: true }
            YAML)->pageRow('ContentPage');

        self::assertSame(['pageBuilder'], $row?->contextFields());
        self::assertSame(['main' => ['field' => 'pageBuilder'], 'top' => ['prepend' => true, 'field' => 'pageBuilder']], $row?->contexts());
    }

    #[Test]
    public function a_page_is_migrated_unless_dropped_or_manual_and_compiles_with_an_entry_type(): void
    {
        $mapping = $this->mapping(<<<'YAML'
            version: 1
            pages:
              ContentPage: { table: content_pages, entryType: contentPage }
              LegacyPage: { table: legacy_pages, drop: retired }
              SearchPage: { entryType: searchPage, manual: rebuilt in Craft }
              DraftPage: { table: draft_pages }
            YAML);

        self::assertSame(PageRow::PAGES, $mapping->pageRow('ContentPage')?->disposition());
        self::assertTrue($mapping->pageRow('ContentPage')?->compiles());
        self::assertSame(PageRow::DROPPED, $mapping->pageRow('LegacyPage')?->disposition());
        self::assertFalse($mapping->pageRow('LegacyPage')?->isMigrated());
        self::assertSame(PageRow::MANUAL, $mapping->pageRow('SearchPage')?->disposition());
        self::assertFalse($mapping->pageRow('SearchPage')?->compiles());
        self::assertTrue($mapping->pageRow('DraftPage')?->isMigrated());
        self::assertFalse($mapping->pageRow('DraftPage')?->compiles());
        self::assertSame('pages', $mapping->pageRow('ContentPage')?->section());

        self::assertSame(
            ['ContentPage' => 'pages', 'LegacyPage' => 'dropped', 'SearchPage' => 'manual', 'DraftPage' => 'pages'],
            $mapping->accountedPageTypes(),
        );
    }

    #[Test]
    public function an_entity_row_reads_its_switches_and_defaults(): void
    {
        $mapping = $this->mapping(<<<'YAML'
            version: 1
            entities:
              Category:
                table: categories
                section: categories
                entryType: category
                dedupe: true
                softDelete: deleted_at
                map: { slug: slug }
              Settings:
                table: settings
                section: settings
                entryType: settings
                single: true
                title: name
              Draft:
                section: drafts
            YAML);

        $category = $mapping->entityRow('Category');
        self::assertNotNull($category);
        self::assertTrue($category->compiles());
        self::assertTrue($category->dedupe());
        self::assertFalse($category->single());
        self::assertSame('deleted_at', $category->softDelete());
        self::assertSame('title', $category->titleColumn());
        self::assertSame(['slug' => 'slug'], $category->map());

        $settings = $mapping->entityRow('Settings');
        self::assertTrue($settings?->single());
        self::assertFalse($settings?->dedupe());
        self::assertSame('name', $settings?->titleColumn());
        self::assertNull($settings?->softDelete());

        self::assertFalse($mapping->entityRow('Draft')?->compiles());
    }

    #[Test]
    public function a_sidecar_is_migrated_unless_dropped_or_manual(): void
    {
        $rows = $this->mapping(<<<'YAML'
            version: 1
            sidecars:
              headerTab: { table: header_tabs, map: { heroTitle: title } }
              seoTab: { table: seo_tabs, drop: the SEO adapter owns it }
              footerTab: { table: footer_tabs, manual: by hand }
            YAML)->sidecarRows();

        self::assertTrue($rows['headerTab']->isMigrated());
        self::assertSame('header_tabs', $rows['headerTab']->table());
        self::assertFalse($rows['seoTab']->isMigrated());
        self::assertFalse($rows['footerTab']->isMigrated());
    }

    #[Test]
    public function the_lane_wide_facts_are_typed(): void
    {
        $mapping = $this->mapping(<<<'YAML'
            version: 1
            defaults:
              structuralEntryType: contentPage
            forms:
              context: contact
              emit: { block: formBlock, field: form }
              fields:
                SingleLineText: { table: single_line_text_parts, type: singleLineText }
            transforms:
              variant: { map: { boxed: boxed }, default: base }
            YAML);

        self::assertSame('contentPage', $mapping->structuralEntryType());
        self::assertTrue($mapping->forms()->declared);
        self::assertSame('contact', $mapping->forms()->context);
        self::assertSame('formBlock', $mapping->forms()->emitBlock);
        self::assertSame('form', $mapping->forms()->emitField);
        self::assertSame(['variant' => ['map' => ['boxed' => 'boxed'], 'default' => 'base']], $mapping->transforms());

        $bare = $this->mapping("version: 1\n");

        self::assertNull($bare->structuralEntryType());
        self::assertFalse($bare->forms()->declared);
        self::assertSame('form', $bare->forms()->context);
        self::assertNull($bare->forms()->emitBlock);
        self::assertSame([], $bare->transforms());
    }

    #[Test]
    public function accounted_parts_read_the_same_truth_table(): void
    {
        $mapping = $this->mapping(<<<'YAML'
            version: 1
            parts:
              Text: { table: t, block: textBlock }
              Quote: { table: q, drop: unused }
              Form: { table: f, manual: Formie }
              Header: { table: h, consumedBy: sequence }
              Odd: { table: o, block: textBlock, drop: both }
            YAML);

        self::assertSame(
            ['Text' => 'blocks', 'Quote' => 'dropped', 'Form' => 'manual', 'Header' => 'sequence', 'Odd' => 'dropped'],
            $mapping->accountedParts(),
        );
    }
}
