<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\kernel;

use Lameco\Kunstmaanmigrator\Mapping\Mapping;
use Lameco\Kunstmaanmigrator\Report\SpecDivergence;
use Lameco\Kunstmaanmigrator\Target\CraftSchema;
use Lameco\Kunstmaanmigrator\Target\SpecNotes;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SpecDivergenceTest extends TestCase
{
    /** @return list<string> */
    private function divergences(string $yaml, string $specMarkdown): array
    {
        $mappingPath = tempnam(sys_get_temp_dir(), 'kuma') . '.yaml';
        file_put_contents($mappingPath, $yaml);

        $specDir = sys_get_temp_dir() . '/kuma-specs-' . substr(md5($specMarkdown), 0, 8);
        @mkdir($specDir);
        file_put_contents($specDir . '/contentBlock.md', $specMarkdown);

        return (new SpecDivergence(
            Mapping::fromFile($mappingPath),
            SpecNotes::fromDirectory($specDir),
            CraftSchema::fromProjectConfig(__DIR__ . '/fixtures/craft'),
        ))->divergences();
    }

    private const SPEC = <<<'MD'
        # Content

        ## Migration notes (Kunstmaan → Craft)

        | Kunstmaan (`Text`) | New field |
        |---|---|
        | `backgroundColor` | `contentColumns` |
        | `linkText` / `linkUrl` | *(dropped — no Craft equivalent)* |
        MD;

    #[Test]
    public function a_dropped_column_the_spec_gives_a_target_for_is_a_divergence(): void
    {
        $found = $this->divergences(<<<'YAML'
            version: 1
            parts:
              Text:
                table: text_page_parts
                block: contentBlock
                ignore: [background_color]
            YAML, self::SPEC);

        self::assertCount(1, $found);
        self::assertStringContainsString('`background_color` is dropped', $found[0]);
        self::assertStringContainsString('contentBlock.md gives it a target', $found[0]);
    }

    #[Test]
    public function a_column_the_spec_also_drops_is_not_a_divergence(): void
    {
        // Both documents agree it goes nowhere. Agreement is the whole point.
        self::assertSame([], $this->divergences(<<<'YAML'
            version: 1
            parts:
              Text:
                table: text_page_parts
                block: contentBlock
                ignore: [link_text, link_url]
            YAML, self::SPEC));
    }

    #[Test]
    public function a_reasoned_ignore_still_diverges_from_a_spec_that_maps_it(): void
    {
        // A reason records that someone decided; it does not make the spec agree. Overriding a
        // spec means changing the spec, or the two documents describe different migrations.
        self::assertCount(1, $this->divergences(<<<'YAML'
            version: 1
            parts:
              Text:
                table: text_page_parts
                block: contentBlock
                ignore:
                  background_color: "design-driven"
            YAML, self::SPEC));
    }

    #[Test]
    public function an_unreviewed_column_is_checked_the_same_way(): void
    {
        self::assertCount(1, $this->divergences(<<<'YAML'
            version: 1
            parts:
              Text:
                table: text_page_parts
                block: contentBlock
                unreviewed: [background_color]
            YAML, self::SPEC));
    }

    #[Test]
    public function a_mapped_column_is_not_a_divergence(): void
    {
        self::assertSame([], $this->divergences(<<<'YAML'
            version: 1
            parts:
              Text:
                table: text_page_parts
                block: contentBlock
                map:
                  contentColumns[0].content: background_color
            YAML, self::SPEC));
    }
}
