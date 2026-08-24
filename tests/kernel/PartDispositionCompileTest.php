<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\kernel;

use Lameco\Kunstmaanmigrator\Compile\Compiler;
use Lameco\Kunstmaanmigrator\Compile\Transforms;
use Lameco\Kunstmaanmigrator\Mapping\Mapping;
use Lameco\Kunstmaanmigrator\Source\LegacyDatabase;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The compiler reads a part's disposition through `PartRow`, the same reading every check
 * uses. Before that it took a part out of the blocks lane only when the row carried *both*
 * `drop:` and `manual:` — `isset($spec['drop'], $spec['manual'])` — so a part with a block and
 * a `drop:` compiled anyway. The schema rejects that row as two dispositions, which is why it
 * never bit a validated mapping; the compiler does not validate, and the checks disagreed with
 * it about what a dropped part is.
 */
final class PartDispositionCompileTest extends TestCase
{
    private const HEAD = <<<'YAML'
        version: 1
        environments:
          COM:
            database: com
            locales: { en: comEnUs }
        defaults:
          contexts:
            main: { field: pageBuilder }
        pages:
          ContentPage:
            table: content_pages
            section: pages
            entryType: contentPage

        YAML;

    private function db(): LegacyDatabase
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE kuma_nodes (id INTEGER, parent_id INTEGER, deleted INTEGER, lft INTEGER, ref_entity_name TEXT)');
        $pdo->exec('CREATE TABLE kuma_node_versions (id INTEGER, ref_entity_name TEXT, ref_id INTEGER)');
        $pdo->exec('CREATE TABLE kuma_node_translations
                    (id INTEGER, node_id INTEGER, lang TEXT, title TEXT, slug TEXT, url TEXT,
                     created TEXT, online INTEGER, public_node_version_id INTEGER)');
        $pdo->exec('CREATE TABLE kuma_page_part_refs
                    (id INTEGER, pageId INTEGER, pageEntityname TEXT, context TEXT,
                     sequencenumber INTEGER, page_part_id INTEGER, page_part_entityname TEXT)');
        $pdo->exec('CREATE TABLE content_pages (id INTEGER)');
        $pdo->exec('CREATE TABLE text_parts (id INTEGER, content TEXT)');

        $pdo->exec("INSERT INTO kuma_nodes VALUES (17, NULL, 0, 1, 'App\\Entity\\Pages\\ContentPage')");
        $pdo->exec("INSERT INTO kuma_node_versions VALUES (91, 'App\\Entity\\Pages\\ContentPage', 100)");
        $pdo->exec("INSERT INTO kuma_node_translations VALUES (4, 17, 'en', 'About', 'about', NULL, NULL, 1, 91)");
        $pdo->exec('INSERT INTO content_pages VALUES (100)');
        // One part in `main`, one in `hero`. Doubled backslashes: the suite's sqlite convention
        // for LIKE-matched entity columns.
        $pdo->exec("INSERT INTO kuma_page_part_refs VALUES
                    (1, 100, 'App\\\\Entity\\\\Pages\\\\ContentPage', 'main', 1, 1, 'App\\\\Entity\\\\PageParts\\\\TextPagePart'),
                    (2, 100, 'App\\\\Entity\\\\Pages\\\\ContentPage', 'hero', 1, 2, 'App\\\\Entity\\\\PageParts\\\\TextPagePart')");
        $pdo->exec("INSERT INTO text_parts VALUES (1, 'body'), (2, 'hero')");

        return new LegacyDatabase($pdo, 'COM', 'com');
    }

    /** @return array{0: array<string, mixed>, 1: Compiler} the one entry, and the compiler that made it */
    private function compile(string $tail): array
    {
        $path = tempnam(sys_get_temp_dir(), 'kuma') . '.yaml';
        file_put_contents($path, self::HEAD . $tail);
        $out = [];

        $compiler = new Compiler(Mapping::fromFile($path), new Transforms());
        $compiler->compile($this->db(), 'COM', static function(array $p) use (&$out): void {
            $out[] = $p;
        });

        self::assertCount(1, $out);

        return [$out[0], $compiler];
    }

    #[Test]
    public function a_mapped_part_becomes_a_block(): void
    {
        [$entry, $compiler] = $this->compile(<<<'YAML'
            parts:
              Text:
                table: text_parts
                block: textBlock
                map: { content: content }
            YAML);

        self::assertSame(
            [['type' => 'textBlock', 'fields' => ['content' => 'body', '_sourcePartRef' => 'COM:text_parts:1']]],
            $entry['sites']['comEnUs']['fieldValues']['pageBuilder'],
        );
        self::assertSame(1, $compiler->blockCount());
    }

    #[Test]
    public function a_dropped_part_is_not_compiled_even_when_it_still_names_a_block(): void
    {
        // `drop:` alone takes the part out — not `drop:` and `manual:` together.
        [$entry, $compiler] = $this->compile(<<<'YAML'
            parts:
              Text:
                table: text_parts
                block: textBlock
                drop: nobody reads it
                map: { content: content }
            YAML);

        self::assertArrayNotHasKey('fieldValues', $entry['sites']['comEnUs']);
        self::assertSame(0, $compiler->blockCount());
    }

    #[Test]
    public function a_manual_part_is_not_compiled_even_when_it_still_names_a_block(): void
    {
        [$entry, $compiler] = $this->compile(<<<'YAML'
            parts:
              Text:
                table: text_parts
                block: textBlock
                manual: rebuilt by hand
                map: { content: content }
            YAML);

        self::assertArrayNotHasKey('fieldValues', $entry['sites']['comEnUs']);
        self::assertSame(0, $compiler->blockCount());
    }

    #[Test]
    public function a_part_with_no_block_is_a_hole_and_is_counted(): void
    {
        [$entry, $compiler] = $this->compile(<<<'YAML'
            parts:
              Text:
                table: text_parts
            YAML);

        self::assertArrayNotHasKey('fieldValues', $entry['sites']['comEnUs']);
        self::assertSame(['Text' => 1], $compiler->skipped());
    }

    #[Test]
    public function a_pages_own_contexts_override_the_defaults(): void
    {
        // `contexts:` on the page is what every check already assumed the compiler honoured;
        // the compiler read only `defaults.contexts`. One fallback rule now, on the row.
        [$entry] = $this->compile(<<<'YAML'
            parts:
              Text:
                table: text_parts
                block: textBlock
                map: { content: content }
            YAML);

        self::assertSame('body', $entry['sites']['comEnUs']['fieldValues']['pageBuilder'][0]['fields']['content']);

        $path = tempnam(sys_get_temp_dir(), 'kuma') . '.yaml';
        file_put_contents($path, str_replace(
            "entryType: contentPage\n",
            "entryType: contentPage\n    contexts:\n      hero: { field: heroBuilder }\n",
            self::HEAD,
        ) . <<<'YAML'
            parts:
              Text:
                table: text_parts
                block: textBlock
                map: { content: content }
            YAML);
        $out = [];

        (new Compiler(Mapping::fromFile($path), new Transforms()))
            ->compile($this->db(), 'COM', static function(array $p) use (&$out): void {
                $out[] = $p;
            });

        $fields = $out[0]['sites']['comEnUs']['fieldValues'];
        self::assertArrayNotHasKey('pageBuilder', $fields);
        self::assertSame('hero', $fields['heroBuilder'][0]['fields']['content']);
    }
}
