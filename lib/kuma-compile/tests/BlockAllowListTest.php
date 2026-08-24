<?php

declare(strict_types=1);

namespace Lameco\KumaCompile\Tests;

use Lameco\KumaCompile\Mapping\Mapping;
use Lameco\KumaCompile\Target\CraftSchema;
use Lameco\KumaCompile\Target\TargetCheck;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A Matrix names the entry types it accepts. A part whose block is not on that list compiles
 * cleanly, validates cleanly, and is dropped at write time — 44 blocks on the reference corpus,
 * found by reading a run report afterwards.
 */
final class BlockAllowListTest extends TestCase
{
    /** @return list<string> */
    private function errors(string $yaml): array
    {
        return $this->check($yaml)->blocksNoPageAccepts($this->mapping($yaml));
    }

    /** @return list<string> */
    private function warnings(string $yaml): array
    {
        return $this->check($yaml)->pagesWithNoBlockField($this->mapping($yaml));
    }

    private function check(string $yaml): TargetCheck
    {
        return new TargetCheck(CraftSchema::fromProjectConfig(__DIR__ . '/fixtures/craft'));
    }

    private function mapping(string $yaml): Mapping
    {
        $path = tempnam(sys_get_temp_dir(), 'kuma') . '.yaml';
        file_put_contents($path, $yaml);

        return Mapping::fromFile($path);
    }

    #[Test]
    public function a_block_the_only_hosting_matrix_rejects_is_an_error(): void
    {
        // `contentColumns` accepts `contentColumn` and nothing else, so every placement of this
        // part is lost — not sometimes, and not depending on the data.
        $errors = $this->errors(<<<'YAML'
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
            YAML);

        self::assertCount(1, $errors);
        self::assertStringContainsString('`calloutBlock`', $errors[0]);
    }

    #[Test]
    public function a_block_the_matrix_accepts_is_not_reported(): void
    {
        self::assertSame([], $this->errors(<<<'YAML'
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
              Column:
                table: column_page_parts
                block: contentColumn
            YAML));
    }

    #[Test]
    public function a_part_that_writes_no_block_is_not_reported(): void
    {
        // `drop:`, `manual:` and a part the sequence lane consumes never reach a Matrix, so the
        // allow-list has no opinion about them.
        self::assertSame([], $this->errors(<<<'YAML'
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
                drop: superseded by the sequence lane
            YAML));
    }

    #[Test]
    public function a_mapping_with_no_pages_cannot_be_judged(): void
    {
        // No hosting field means no evidence, and an error with no evidence behind it is how a
        // check gets switched off.
        self::assertSame([], $this->errors(<<<'YAML'
            version: 1
            parts:
              Callout:
                table: callout_page_parts
                block: calloutBlock
            YAML));
    }

    #[Test]
    public function a_page_whose_entry_type_has_no_block_field_at_all_is_warned_about(): void
    {
        // The largest single documented loss on the reference corpus: `casePage` has no page
        // builder, so every part on 72 pages is dropped, and the compiler says so once per node
        // into a run report two hours in.
        $warnings = $this->warnings(<<<'YAML'
            version: 1
            defaults:
              contexts:
                main: { field: notAField }
            pages:
              CasePage:
                table: case_pages
                entryType: contentBlock
                section: pages
            YAML);

        self::assertCount(1, $warnings);
        self::assertStringContainsString('notAField', $warnings[0]);
    }

    #[Test]
    public function a_page_whose_entry_type_has_the_field_is_not_warned_about(): void
    {
        self::assertSame([], $this->warnings(<<<'YAML'
            version: 1
            defaults:
              contexts:
                main: { field: contentColumns }
            pages:
              ContentPage:
                table: content_pages
                entryType: contentBlock
                section: pages
            YAML));
    }

    #[Test]
    public function it_is_a_warning_because_only_the_data_says_what_it_costs(): void
    {
        // On the reference corpus this fires for five page types. Four of them hold no live
        // pageparts at all — `PartnerPage` has 423 live pages and zero placements — so as an
        // error it would fail a mapping that works.
        self::assertSame([], $this->errors(<<<'YAML'
            version: 1
            defaults:
              contexts:
                main: { field: notAField }
            pages:
              CasePage:
                table: case_pages
                entryType: contentBlock
                section: pages
            parts:
              Callout:
                table: callout_page_parts
                block: calloutBlock
            YAML));
    }
}
