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
 * Trello #148: a two-column legacy row (`middle-left` + `middle-right`, side by side in
 * Kunstmaan's own template) compiled into a `contentBlock` whose `contentColumns` list read
 * "every middle-left entry, then every middle-right entry" instead of "row 1 of both, then
 * row 2 of both". `contentBlock.twig` lays that list out left-to-right in list order, wrapping
 * every two entries onto a new row — so a context-concatenated list pairs middle-left's OWN
 * rows with each other the moment either context holds more than one, instead of pairing each
 * context's row with its actual row-mate on the other side. Reproduced on Enreach's
 * "mobile-connect" page: "UNIQUE NUMBER" (middle-left row 1) landed beside "Unique voicemail"
 * (middle-left row 2) instead of "DEVICE SWITCH" (middle-right row 1), its real neighbour.
 *
 * `mergeColumnGroups()` in `Compiler.php` now interleaves row-by-row across the declared
 * contexts instead of concatenating context-by-context.
 */
final class ColumnGroupsCompileTest extends TestCase
{
    private const MAPPING = <<<'YAML'
        version: 1
        environments:
          COM:
            database: com
            locales: { en: comEnUs }
        defaults:
          contexts:
            middle-left:  { field: pageBuilder }
            middle-right: { field: pageBuilder }
        columnGroups:
          - contexts: [middle-left, middle-right]
            block: contentBlock
            column: contentColumns
            prepend: true
        pages:
          ContentPage:
            table: content_pages
            section: pages
            entryType: contentPage
        parts:
          Text:
            table: text_parts
            block: contentBlock
            map:
              contentColumns[0].content: content
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
        $pdo->exec("INSERT INTO kuma_node_translations VALUES (4, 17, 'en', 'Mobile Connect', 'mobile-connect', NULL, NULL, 1, 91)");
        $pdo->exec('INSERT INTO content_pages VALUES (100)');

        // middle-left carries two stacked rows (UNIQUE NUMBER, then Unique voicemail below it);
        // middle-right carries two stacked rows beside them (DEVICE SWITCH, then FREE CALLS).
        // Doubled backslashes: the suite's sqlite convention for LIKE-matched entity columns.
        $pdo->exec("INSERT INTO kuma_page_part_refs VALUES
                    (1, 100, 'App\\\\Entity\\\\Pages\\\\ContentPage', 'middle-left',  1, 1, 'App\\\\Entity\\\\PageParts\\\\TextPagePart'),
                    (2, 100, 'App\\\\Entity\\\\Pages\\\\ContentPage', 'middle-left',  2, 2, 'App\\\\Entity\\\\PageParts\\\\TextPagePart'),
                    (3, 100, 'App\\\\Entity\\\\Pages\\\\ContentPage', 'middle-right', 1, 3, 'App\\\\Entity\\\\PageParts\\\\TextPagePart'),
                    (4, 100, 'App\\\\Entity\\\\Pages\\\\ContentPage', 'middle-right', 2, 4, 'App\\\\Entity\\\\PageParts\\\\TextPagePart')");
        $pdo->exec("INSERT INTO text_parts VALUES
                    (1, 'UNIQUE NUMBER'), (2, 'Unique voicemail'),
                    (3, 'DEVICE SWITCH'), (4, 'FREE CALLS')");

        return new LegacyDatabase($pdo, 'COM', 'com');
    }

    /** @return array<string, mixed> the one compiled entry */
    private function compile(): array
    {
        $path = tempnam(sys_get_temp_dir(), 'kuma') . '.yaml';
        file_put_contents($path, self::MAPPING);
        $out = [];

        (new Compiler(Mapping::fromFile($path), new Transforms()))
            ->compile($this->db(), 'COM', static function(array $p) use (&$out): void {
                $out[] = $p;
            });

        self::assertCount(1, $out);

        return $out[0];
    }

    #[Test]
    public function two_column_contexts_merge_row_by_row_not_context_by_context(): void
    {
        $entry = $this->compile();
        $blocks = $entry['sites']['comEnUs']['fieldValues']['pageBuilder'];

        // One merged `contentBlock`, not two separate ones landing one after the other.
        self::assertCount(1, $blocks);
        self::assertSame('contentBlock', $blocks[0]['type']);

        $contents = array_map(
            static fn(array $column): mixed => $column['fields']['content'],
            $blocks[0]['fields']['contentColumns'],
        );

        // Row 1: middle-left's first row beside middle-right's first row. Row 2: their second
        // rows. NOT middle-left's two rows followed by middle-right's two — that ordering is
        // what put "Unique voicemail" (middle-left row 2) beside "UNIQUE NUMBER" (middle-left
        // row 1) instead of beside "FREE CALLS" (middle-right row 2), its real row-mate.
        self::assertSame(
            ['UNIQUE NUMBER', 'DEVICE SWITCH', 'Unique voicemail', 'FREE CALLS'],
            $contents,
        );
    }
}
