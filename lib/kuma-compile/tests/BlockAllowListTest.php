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
        $path = tempnam(sys_get_temp_dir(), 'kuma') . '.yaml';
        file_put_contents($path, $yaml);

        return (new TargetCheck(CraftSchema::fromProjectConfig(__DIR__ . '/fixtures/craft')))
            ->blocksNoPageAccepts(Mapping::fromFile($path));
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
}
