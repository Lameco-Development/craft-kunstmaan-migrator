<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\kernel;

use Lameco\Kunstmaanmigrator\Mapping\Mapping;
use Lameco\Kunstmaanmigrator\Report\BlockPlacement;
use Lameco\Kunstmaanmigrator\Target\CraftSchema;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The partial allow-list question: a block that is fine on one page type and rejected by
 * another. Unanswerable statically without warning about pairings that never occur; exact once
 * the corpus says which pairings do.
 */
final class BlockPlacementTest extends TestCase
{
    private const MAPPING = <<<'YAML'
        version: 1
        defaults:
          contexts:
            main: { field: contentColumns }
        pages:
          ContentPage:
            table: content_pages
            entryType: contentBlock
            section: pages
        parts:
          Callout:
            table: callout_page_parts
            block: calloutBlock
          Column:
            table: column_page_parts
            block: contentColumn
        YAML;

    /** @param array<string, array<string, int>> $pairs */
    private function rejections(array $pairs, string $yaml = self::MAPPING): array
    {
        $path = tempnam(sys_get_temp_dir(), 'kuma') . '.yaml';
        file_put_contents($path, $yaml);

        return (new BlockPlacement(
            Mapping::fromFile($path),
            CraftSchema::fromProjectConfig(__DIR__ . '/fixtures/craft'),
        ))->rejections($pairs);
    }

    #[Test]
    public function a_pairing_that_occurs_and_is_rejected_is_reported_with_its_cost(): void
    {
        // `contentColumns` accepts `contentColumn` only, and 28 live Callouts sit on ContentPage.
        $rejections = $this->rejections(['ContentPage' => ['Callout' => 28]]);

        self::assertCount(1, $rejections);
        self::assertSame(28, $rejections[0]['placements']);
        self::assertSame('calloutBlock', $rejections[0]['block']);
        self::assertSame('contentColumns', $rejections[0]['field']);
    }

    #[Test]
    public function a_pairing_that_never_occurs_is_not_reported(): void
    {
        // The whole reason this is data-driven. Statically, `calloutBlock` is rejected by
        // `contentColumns` — but if no Callout ever sits on a ContentPage, that is the content
        // model working as designed and warning about it is noise on every project.
        self::assertSame([], $this->rejections(['ContentPage' => ['Column' => 100]]));
    }

    #[Test]
    public function a_page_the_mapping_does_not_migrate_is_not_reported(): void
    {
        self::assertSame([], $this->rejections(['SomeOtherPage' => ['Callout' => 28]]));
    }

    #[Test]
    public function one_accepting_context_field_is_enough(): void
    {
        // A page can stream into more than one context, and the content lands as long as one of
        // them takes the block. Only a block every hosting field rejects is lost.
        self::assertSame([], $this->rejections(['ContentPage' => ['Callout' => 5]], <<<'YAML'
            version: 1
            defaults:
              contexts:
                main:  { field: contentColumns }
                extra: { field: layout }
            pages:
              ContentPage:
                table: content_pages
                entryType: calloutBlock
                section: pages
            parts:
              Callout:
                table: callout_page_parts
                block: calloutBlock
            YAML));
    }

    #[Test]
    public function a_missing_context_field_belongs_to_the_other_check(): void
    {
        // `pagesWithNoBlockField()` reports a field that is not there. Counting the same
        // placements here as well would double-count them.
        self::assertSame([], $this->rejections(['ContentPage' => ['Callout' => 28]], <<<'YAML'
            version: 1
            defaults:
              contexts:
                main: { field: notAField }
            pages:
              ContentPage:
                table: content_pages
                entryType: contentBlock
                section: pages
            parts:
              Callout:
                table: callout_page_parts
                block: calloutBlock
            YAML));
    }

    #[Test]
    public function the_costliest_pairing_leads(): void
    {
        $rejections = $this->rejections(['ContentPage' => ['Callout' => 3]], <<<'YAML'
            version: 1
            defaults:
              contexts:
                main: { field: contentColumns }
            pages:
              ContentPage: { table: content_pages, entryType: contentBlock, section: pages }
            parts:
              Callout: { table: callout_page_parts, block: calloutBlock }
            YAML);

        self::assertSame(3, $rejections[0]['placements']);
    }
}
