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
 * Two pagepart classes in different namespaces can share a short name — the app's own
 * `TextPagePart` next to Kunstmaan's — and their ids overlap, because each has its own table.
 * A short-name row reads one table, so it once read the Kunstmaan placement from the app's row
 * with the same id: wrong text, no loss counted. A row keyed by the fully qualified class name
 * claims that class's placements; the short-name row keeps the rest.
 */
final class PartClassCollisionTest extends TestCase
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
        $pdo->exec('CREATE TABLE app_text_parts (id INTEGER, text TEXT)');
        $pdo->exec('CREATE TABLE kuma_text_page_parts (id INTEGER, content TEXT)');

        $pdo->exec("INSERT INTO kuma_nodes VALUES (17, NULL, 0, 1, 'App\\Entity\\Pages\\ContentPage')");
        $pdo->exec("INSERT INTO kuma_node_versions VALUES (91, 'App\\Entity\\Pages\\ContentPage', 100)");
        $pdo->exec("INSERT INTO kuma_node_translations VALUES (4, 17, 'en', 'About', 'about', NULL, NULL, 1, 91)");
        $pdo->exec('INSERT INTO content_pages VALUES (100)');
        // Both placements point at id 1, each in its own class's table.
        $pdo->exec("INSERT INTO kuma_page_part_refs VALUES
                    (1, 100, 'App\\\\Entity\\\\Pages\\\\ContentPage', 'main', 1, 1, 'App\\\\Entity\\\\PageParts\\\\TextPagePart'),
                    (2, 100, 'App\\\\Entity\\\\Pages\\\\ContentPage', 'main', 2, 1, 'Kunstmaan\\\\PagePartBundle\\\\Entity\\\\TextPagePart')");
        $pdo->exec("INSERT INTO app_text_parts VALUES (1, 'app text')");
        $pdo->exec("INSERT INTO kuma_text_page_parts VALUES (1, 'kunstmaan text')");

        // The Berkvens NL shape: the app's Header is the page's hero, Kunstmaan's is a heading block.
        $pdo->exec('CREATE TABLE app_header_parts (id INTEGER, title TEXT)');
        $pdo->exec('CREATE TABLE kuma_header_page_parts (id INTEGER, title TEXT)');
        $pdo->exec("INSERT INTO kuma_page_part_refs VALUES
                    (3, 100, 'App\\\\Entity\\\\Pages\\\\ContentPage', 'header', 1, 7, 'App\\\\Entity\\\\PageParts\\\\HeaderPagePart'),
                    (4, 100, 'App\\\\Entity\\\\Pages\\\\ContentPage', 'main', 3, 7, 'Kunstmaan\\\\PagePartBundle\\\\Entity\\\\HeaderPagePart')");
        $pdo->exec("INSERT INTO app_header_parts VALUES (7, 'hero title')");
        $pdo->exec("INSERT INTO kuma_header_page_parts VALUES (7, 'heading title')");

        return new LegacyDatabase($pdo, 'COM', 'com');
    }

    /** @return array{0: array<string, mixed>, 1: Compiler} */
    private function compile(string $parts): array
    {
        $path = tempnam(sys_get_temp_dir(), 'kuma') . '.yaml';
        file_put_contents($path, self::HEAD . $parts);
        $out = [];

        $compiler = new Compiler(Mapping::fromFile($path), new Transforms());
        $compiler->compile($this->db(), 'COM', static function(array $p) use (&$out): void {
            $out[] = $p;
        });

        self::assertCount(1, $out);

        return [$out[0], $compiler];
    }

    #[Test]
    public function each_placement_compiles_from_its_own_classs_table_when_ids_overlap(): void
    {
        [$entry] = $this->compile(<<<'YAML'
            parts:
              Text:
                table: app_text_parts
                block: textBlock
                map: { body: text }
              Kunstmaan\PagePartBundle\Entity\TextPagePart:
                table: kuma_text_page_parts
                block: kmTextBlock
                map: { body: content }
            YAML);

        self::assertSame(
            [
                ['type' => 'textBlock', 'fields' => ['body' => 'app text', '_sourcePartRef' => 'COM:app_text_parts:1']],
                ['type' => 'kmTextBlock', 'fields' => ['body' => 'kunstmaan text', '_sourcePartRef' => 'COM:kuma_text_page_parts:1']],
            ],
            $entry['sites']['comEnUs']['fieldValues']['pageBuilder'],
        );
    }

    #[Test]
    public function one_short_name_can_fill_the_page_from_one_class_and_build_a_block_from_the_other(): void
    {
        // One short-name row cannot be both `consumedBy: page` and a block; two rows can.
        $path = tempnam(sys_get_temp_dir(), 'kuma') . '.yaml';
        file_put_contents($path, str_replace(
            "    main: { field: pageBuilder }\n",
            "    main: { field: pageBuilder }\n    header: { target: page }\n",
            self::HEAD,
        ) . <<<'YAML'
            parts:
              Text:
                table: app_text_parts
                drop: not under test
              Kunstmaan\PagePartBundle\Entity\TextPagePart:
                table: kuma_text_page_parts
                drop: not under test
              Header:
                table: app_header_parts
                consumedBy: page
                map: { heroTitle: title }
              Kunstmaan\PagePartBundle\Entity\HeaderPagePart:
                table: kuma_header_page_parts
                block: headingBlock
                map: { heading: title }
            YAML);
        $out = [];

        (new Compiler(Mapping::fromFile($path), new Transforms()))
            ->compile($this->db(), 'COM', static function(array $p) use (&$out): void {
                $out[] = $p;
            });

        $fields = $out[0]['sites']['comEnUs']['fieldValues'];
        self::assertSame('hero title', $fields['heroTitle']);
        self::assertSame(
            [['type' => 'headingBlock', 'fields' => ['heading' => 'heading title', '_sourcePartRef' => 'COM:kuma_header_page_parts:7']]],
            $fields['pageBuilder'],
        );
    }
}
