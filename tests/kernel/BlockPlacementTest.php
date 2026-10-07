<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\kernel;

use Lameco\Kunstmaanmigrator\Mapping\Mapping;
use Lameco\Kunstmaanmigrator\Report\BlockPlacement;
use Lameco\Kunstmaanmigrator\Target\CraftSchema;
use Lameco\Kunstmaanmigrator\Target\Slot;
use Lameco\Kunstmaanmigrator\Target\TargetSchema;
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

    private const TWO_FIELDS = <<<'YAML'
        version: 1
        defaults:
          contexts:
            main:  { field: mainBuilder }
            extra: { field: sideBuilder }
        pages:
          ContentPage:
            table: content_pages
            entryType: contentPage
            section: pages
        parts:
          Callout:
            table: callout_page_parts
            block: calloutBlock
        YAML;

    /** @param array<string, array<string, array<string, int>>> $pairs page => context => part => placements */
    private function rejections(array $pairs, string $yaml = self::MAPPING, ?TargetSchema $schema = null): array
    {
        $path = tempnam(sys_get_temp_dir(), 'kuma') . '.yaml';
        file_put_contents($path, $yaml);

        return (new BlockPlacement(
            Mapping::fromFile($path),
            $schema ?? CraftSchema::fromProjectConfig(__DIR__ . '/fixtures/craft'),
        ))->rejections($pairs);
    }

    /** A page with two Matrix fields: the main one takes columns, the side one callouts. */
    private function twoFields(): TargetSchema
    {
        return new class() implements TargetSchema {
            public function hasEntryType(string $handle): bool
            {
                return $handle === 'contentPage';
            }

            public function hasSection(string $handle): bool
            {
                return true;
            }

            public function sectionType(string $handle): ?string
            {
                return null;
            }

            public function sectionEntryTypes(string $handle): ?array
            {
                return null;
            }

            public function slots(string $entryType): array
            {
                return $entryType === 'contentPage' ? [
                    'mainBuilder' => new Slot('mainBuilder', 'Matrix', false, ['contentColumn']),
                    'sideBuilder' => new Slot('sideBuilder', 'Matrix', false, ['calloutBlock']),
                ] : [];
            }

            public function slot(string $entryType, string $field): ?Slot
            {
                return $this->slots($entryType)[$field] ?? null;
            }

            public function requiredFields(string $entryType): array
            {
                return [];
            }

            public function pathFor(string $entryType, string $field): ?string
            {
                return null;
            }

            public function nestedTypeOf(string $entryType, string $field): ?string
            {
                return null;
            }
        };
    }

    #[Test]
    public function a_pairing_that_occurs_and_is_rejected_is_reported_with_its_cost(): void
    {
        // `contentColumns` accepts `contentColumn` only, and 28 live Callouts sit on ContentPage.
        $rejections = $this->rejections(['ContentPage' => ['main' => ['Callout' => 28]]]);

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
        self::assertSame([], $this->rejections(['ContentPage' => ['main' => ['Column' => 100]]]));
    }

    #[Test]
    public function a_page_the_mapping_does_not_migrate_is_not_reported(): void
    {
        self::assertSame([], $this->rejections(['SomeOtherPage' => ['main' => ['Callout' => 28]]]));
    }

    #[Test]
    public function each_context_is_judged_by_its_own_field(): void
    {
        // Each context writes to its own field (#89), so another field accepting the block does
        // not save it: the Callouts in `main` are dropped by `mainBuilder` while the ones in
        // `extra` land in `sideBuilder`. The report used to call both fine.
        $rejections = $this->rejections(['ContentPage' => [
            'main' => ['Callout' => 5],
            'extra' => ['Callout' => 7],
        ]], self::TWO_FIELDS, $this->twoFields());

        self::assertCount(1, $rejections);
        self::assertSame('main', $rejections[0]['context']);
        self::assertSame('mainBuilder', $rejections[0]['field']);
        self::assertSame(5, $rejections[0]['placements']);
    }

    #[Test]
    public function a_context_the_page_does_not_stream_is_not_reported(): void
    {
        // Nothing compiles a context the mapping does not stream; its parts are not dropped by
        // any allow-list, and `state/explain` already names them as left out by decision.
        self::assertSame([], $this->rejections(['ContentPage' => ['sidebar' => ['Callout' => 9]]], self::TWO_FIELDS, $this->twoFields()));
    }

    #[Test]
    public function a_pages_own_contexts_decide_its_fields(): void
    {
        $rejections = $this->rejections(['ContentPage' => ['extra' => ['Callout' => 4]]], str_replace(
            "    section: pages\n",
            "    section: pages\n    contexts:\n      extra: { field: mainBuilder }\n",
            self::TWO_FIELDS,
        ), $this->twoFields());

        self::assertSame('mainBuilder', $rejections[0]['field'] ?? null);
    }

    #[Test]
    public function a_missing_context_field_belongs_to_the_other_check(): void
    {
        // `pagesWithNoBlockField()` reports a field that is not there. Counting the same
        // placements here as well would double-count them.
        self::assertSame([], $this->rejections(['ContentPage' => ['main' => ['Callout' => 28]]], <<<'YAML'
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
        $rejections = $this->rejections(['ContentPage' => ['main' => ['Callout' => 3]]], <<<'YAML'
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

    #[Test]
    public function a_class_reported_by_its_qualified_name_is_judged_by_the_row_that_claims_it(): void
    {
        // Where two live classes share a short name the corpus reports each by its qualified
        // name; the app's falls back to the short-name row, Kunstmaan's has its own.
        $yaml = self::MAPPING . "\n" . <<<'YAML'
              Kunstmaan\PagePartBundle\Entity\CalloutPagePart:
                table: kuma_callout_page_parts
                block: contentColumn
            YAML;

        $rejections = $this->rejections(['ContentPage' => ['main' => [
            'App\\Entity\\PageParts\\CalloutPagePart' => 40,
            'Kunstmaan\\PagePartBundle\\Entity\\CalloutPagePart' => 12,
        ]]], $yaml);

        self::assertCount(1, $rejections);
        self::assertSame('App\\Entity\\PageParts\\CalloutPagePart', $rejections[0]['part']);
        self::assertSame('calloutBlock', $rejections[0]['block']);
        self::assertSame(40, $rejections[0]['placements']);
    }
    #[Test]
    public function a_class_the_corpus_names_by_its_qualified_name_alone_is_judged_by_its_short_name_row(): void
    {
        // The corpus names every class by its qualified name, collision or not; the `Callout`
        // row claims the one class of that name, and the report names it by the short name.
        $rejections = $this->rejections(['ContentPage' => ['main' => [
            'App\\Entity\\PageParts\\CalloutPagePart' => 28,
            'App\\Entity\\PageParts\\ColumnPagePart' => 9,
        ]]]);

        self::assertCount(1, $rejections);
        self::assertSame(['Callout', 'calloutBlock', 28], [$rejections[0]['part'], $rejections[0]['block'], $rejections[0]['placements']]);
    }
}
