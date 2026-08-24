<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\kernel;

use Lameco\Kunstmaanmigrator\Mapping\Mapping;
use Lameco\Kunstmaanmigrator\Report\Readiness;
use Lameco\Kunstmaanmigrator\Report\Requirement;
use Lameco\Kunstmaanmigrator\Target\CraftSchema;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ReadinessTest extends TestCase
{
    private function requirements(string $yaml): array
    {
        return $this->readiness($yaml)->requirements();
    }

    /** @return list<Requirement> */
    private function unfilled(string $yaml): array
    {
        return $this->readiness($yaml)->unfilled();
    }

    private function readiness(string $yaml): Readiness
    {
        $path = tempnam(sys_get_temp_dir(), 'kuma') . '.yaml';
        file_put_contents($path, $yaml);

        return new Readiness(
            Mapping::fromFile($path),
            CraftSchema::fromProjectConfig(__DIR__ . '/fixtures/craft'),
        );
    }

    /** @param list<Requirement> $requirements */
    private function find(array $requirements, string $target, string $field): ?Requirement
    {
        foreach ($requirements as $requirement) {
            if ($requirement->target === $target && $requirement->field === $field) {
                return $requirement;
            }
        }

        return null;
    }

    #[Test]
    public function a_required_field_on_a_nested_entry_type_is_reported(): void
    {
        // The case the blocks-only check cannot see: `content` is required on the nested row, not
        // on the block, so a mapping that fills only the heading produces an entry Craft rejects.
        $found = $this->find($this->requirements(<<<'YAML'
            version: 1
            parts:
              Header:
                table: header_page_parts
                block: contentBlock
                map:
                  contentColumns[0].heading: title
            YAML), 'contentBlock.contentColumns[]', 'content');

        self::assertNotNull($found);
        self::assertSame(Requirement::MISSING, $found->verdict());
    }

    #[Test]
    public function addressing_into_a_matrix_supplies_the_matrix_itself(): void
    {
        // `contentColumns` is required on the block. Reading the map keys literally would report
        // it unfilled on exactly the parts that do fill it.
        $found = $this->find($this->requirements(<<<'YAML'
            version: 1
            parts:
              Text:
                table: text_page_parts
                block: contentBlock
                map:
                  contentColumns[0].content: content
            YAML), 'contentBlock', 'contentColumns');

        self::assertNotNull($found);
        self::assertSame(Requirement::OK, $found->verdict());
    }

    #[Test]
    public function a_transform_that_manufactures_a_value_needs_no_fill_rate(): void
    {
        $found = $this->find($this->requirements(<<<'YAML'
            version: 1
            transforms:
              colorScheme:
                map:
                  "": white
            parts:
              Text:
                table: text_page_parts
                block: contentBlock
                map:
                  contentColumns[0].content: background_color | colorScheme
            YAML), 'contentBlock.contentColumns[]', 'content');

        self::assertNotNull($found);
        self::assertTrue($found->totalTransform, 'colorScheme turns an empty column into `white`');
        self::assertNull($found->measurableColumn(), 'measuring the raw column would report a blocker that is not one');
    }

    #[Test]
    public function a_column_that_can_be_empty_is_measurable(): void
    {
        $found = $this->find($this->requirements(<<<'YAML'
            version: 1
            parts:
              Text:
                table: text_page_parts
                block: contentBlock
                map:
                  contentColumns[0].content: body | ckeditor
            YAML), 'contentBlock.contentColumns[]', 'content');

        self::assertNotNull($found);
        self::assertSame('body', $found->measurableColumn());
    }

    #[Test]
    public function a_link_gather_is_not_a_single_column(): void
    {
        $found = $this->find($this->requirements(<<<'YAML'
            version: 1
            parts:
              Text:
                table: text_page_parts
                block: contentBlock
                map:
                  contentColumns[0].content: "link(link_url, link_text)"
            YAML), 'contentBlock.contentColumns[]', 'content');

        self::assertNotNull($found);
        self::assertNull($found->measurableColumn());
    }

    #[Test]
    public function a_heading_the_sequence_lane_absorbs_is_credited_to_it(): void
    {
        // Not "missing": a Header preceding the block fills it. The supplier is recorded rather
        // than the requirement dropped, because how often a Header actually precedes is a
        // question only the fill pass can answer.
        $found = $this->find($this->requirements(<<<'YAML'
            version: 1
            sequence:
            - id: heading-absorb
              match: Header > *
              action: absorb
              map:
                heading: head.title
            parts:
              Quote:
                table: quote_page_parts
                block: calloutBlock
            YAML), 'calloutBlock', 'heading');

        self::assertNotNull($found);
        self::assertSame('sequence', $found->supplier);
    }

    #[Test]
    public function a_part_that_refuses_absorption_gets_no_credit_for_it(): void
    {
        // `absorbInto: false` opts out, so nothing fills the heading and the block is a blocker.
        $found = $this->find($this->requirements(<<<'YAML'
            version: 1
            sequence:
            - id: heading-absorb
              match: Header > *
              action: absorb
              map:
                heading: head.title
            parts:
              Quote:
                table: quote_page_parts
                block: calloutBlock
                absorbInto: false
            YAML), 'calloutBlock', 'heading');

        self::assertNotNull($found);
        self::assertSame(Requirement::MISSING, $found->verdict());
    }

    #[Test]
    public function a_required_field_with_a_craft_default_is_not_a_blocker(): void
    {
        // The field carries `default: true` on one of its options, so Craft writes it on a fresh
        // element when the payload omits it. Reporting it as missing sends someone to fix a
        // mapping that does not need fixing.
        $found = $this->find($this->requirements(<<<'YAML'
            version: 1
            parts:
              Quote:
                table: quote_page_parts
                block: calloutBlock
            YAML), 'calloutBlock', 'layout');

        self::assertNotNull($found);
        self::assertSame('interactive', $found->craftDefault);
        self::assertSame(Requirement::DEFAULTED, $found->verdict());
        self::assertStringContainsString('Craft writes `interactive`', $found->action());
    }

    #[Test]
    public function a_default_also_catches_the_rows_a_mapped_column_leaves_empty(): void
    {
        // Empty values never reach the payload, so an empty column and no column are the same
        // thing to Craft: both land on the default.
        $requirement = $this->find($this->requirements(<<<'YAML'
            version: 1
            parts:
              Quote:
                table: quote_page_parts
                block: calloutBlock
                map:
                  layout: legacy_layout
            YAML), 'calloutBlock', 'layout');

        self::assertNotNull($requirement);

        $requirement->rows = 100;
        $requirement->empty = 40;

        self::assertSame(Requirement::DEFAULTED, $requirement->verdict());
    }

    #[Test]
    public function a_required_field_without_a_default_stays_a_blocker(): void
    {
        $found = $this->find($this->requirements(<<<'YAML'
            version: 1
            parts:
              Quote:
                table: quote_page_parts
                block: calloutBlock
            YAML), 'calloutBlock', 'heading');

        self::assertNotNull($found);
        self::assertNull($found->craftDefault);
        self::assertSame(Requirement::MISSING, $found->verdict());
    }

    #[Test]
    public function a_dropped_part_writes_nothing_and_needs_nothing(): void
    {
        self::assertSame([], $this->requirements(<<<'YAML'
            version: 1
            parts:
              Dead:
                table: dead_page_parts
                block: contentBlock
                drop: "1 live placement"
            YAML));
    }

    #[Test]
    public function an_optional_field_no_lane_fills_is_reported(): void
    {
        // The hero case: `heading` on the nested row is optional, so it never reached the required
        // report, and nothing else asked. On the reference corpus that is 37 hero field instances
        // empty on every migrated entry, with no report naming one of them.
        $found = $this->find($this->unfilled(<<<'YAML'
            version: 1
            parts:
              Text:
                table: text_page_parts
                block: contentBlock
                map:
                  contentColumns[0].content: content
            YAML), 'contentBlock.contentColumns[]', 'heading');

        self::assertNotNull($found);
        self::assertFalse($found->required);
    }

    #[Test]
    public function an_optional_field_the_mapping_does_fill_is_not_reported(): void
    {
        // The report is only worth reading if it stays quiet about what is handled. An optional
        // field with a source is not a hole.
        self::assertNull($this->find($this->unfilled(<<<'YAML'
            version: 1
            parts:
              Text:
                table: text_page_parts
                block: contentBlock
                map:
                  contentColumns[0].heading: title
                  contentColumns[0].content: content
            YAML), 'contentBlock.contentColumns[]', 'heading'));
    }

    #[Test]
    public function the_matrix_a_page_streams_its_blocks_into_is_not_a_hole(): void
    {
        // `contexts:` names the field the blocks lane fills. Read it off the page's `map:` alone
        // and every page entry type reads as never filling its own page builder.
        self::assertNull($this->find($this->unfilled(<<<'YAML'
            version: 1
            defaults:
              contexts:
                main: { field: contentColumns }
            pages:
              ContentPage:
                table: content_pages
                entryType: contentBlock
                section: pages
            YAML), 'contentBlock', 'contentColumns'));
    }

    #[Test]
    public function the_required_report_is_unchanged_by_the_wider_walk(): void
    {
        // `requirements()` now filters a walk over every slot rather than over the required ones.
        // Same set, or the widening broke the report it was built on.
        $requirements = $this->requirements(<<<'YAML'
            version: 1
            parts:
              Text:
                table: text_page_parts
                block: contentBlock
                map:
                  contentColumns[0].content: content
            YAML);

        foreach ($requirements as $requirement) {
            self::assertTrue($requirement->required, $requirement->target . '.' . $requirement->field);
        }

        self::assertNotNull($this->find($requirements, 'contentBlock', 'contentColumns'));
    }
}
