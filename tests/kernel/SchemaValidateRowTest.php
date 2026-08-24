<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\kernel;

use Lameco\Kunstmaanmigrator\Mapping\Mapping;
use Lameco\Kunstmaanmigrator\Mapping\Schema;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Row-scoped validation: damage blocks a save, unfinished work never does.
 *
 * The row screen saves work in progress — a target not yet chosen, columns
 * unreviewed, no disposition. Those are what the progress bar counts;
 * refusing to save them is how an editor fights its own workflow.
 */
final class SchemaValidateRowTest extends TestCase
{
    /** @return list<string> */
    private function validateRow(string $yaml, string $lane, string $key): array
    {
        $path = tempnam(sys_get_temp_dir(), 'kuma') . '.yaml';
        file_put_contents($path, $yaml);

        return (new Schema())->validateRow(Mapping::fromFile($path), $lane, $key);
    }

    #[Test]
    public function unfinished_work_is_not_damage(): void
    {
        $yaml = <<<'YAML'
            version: 1
            environments:
              COM: { database: legacy, locales: { en: siteEn } }
            pages:
              ContentPage: { table: content_pages, unreviewed: [title] }
            parts:
              Text: { table: text_parts, unreviewed: [content] }
            YAML;

        self::assertSame([], $this->validateRow($yaml, 'pages', 'ContentPage'), 'no entryType yet — progress, not damage');
        self::assertSame([], $this->validateRow($yaml, 'parts', 'Text'), 'no disposition yet — progress, not damage');
    }

    #[Test]
    public function malformation_still_refuses_the_row(): void
    {
        $yaml = <<<'YAML'
            version: 1
            environments:
              COM: { database: legacy, locales: { en: siteEn } }
            parts:
              Text:
                table: text_parts
                block: contentBlock
                drop: also dropped
                bogus: nope
            YAML;

        $errors = $this->validateRow($yaml, 'parts', 'Text');

        self::assertContains('part `Text`: unknown key `bogus`', $errors);
        self::assertContains('part `Text`: conflicting dispositions (block, drop)', $errors);
    }

    #[Test]
    public function only_the_named_row_is_judged(): void
    {
        $yaml = <<<'YAML'
            version: 1
            environments:
              COM: { database: legacy, locales: { en: siteEn } }
            parts:
              Broken: { table: t, bogus: nope }
              Fine: { table: text_parts, block: contentBlock, ignore: [] }
            YAML;

        self::assertSame([], $this->validateRow($yaml, 'parts', 'Fine'), "another row's damage is not this row's problem");
    }
}
