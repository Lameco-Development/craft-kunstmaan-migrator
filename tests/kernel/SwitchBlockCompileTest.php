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
 * Trello #159: `Feature` maps unconditionally to `iconCardsBlock`, so a highlight item
 * carrying a real photo (`image_id`) lands in the block's 88px icon slot — the DSL already
 * documents a `switch:`/`when:`/`else:` construct for exactly this ("`Usp` and `ProductUsp`
 * each legitimately target two different blocks depending on their content"), but nothing
 * ever evaluated it: `BlockBuilder::build()` read `spec['block']` as a static string, and
 * `Compiler::blockFor()`'s "does this part have a block" gate read `PartRow::block()` (singular
 * — null for a switch-only part), so a switch part was skipped before `build()` ever ran.
 *
 * `switch:` cases probe the same child rows every case reads (one `when:` matching wins, in
 * order; `else:` is the catch-all) and each case's own `map:`/`children:` replace the part's
 * shared ones outright — the two blocks a switch chooses between rarely share a field's shape.
 */
final class SwitchBlockCompileTest extends TestCase
{
    private const MAPPING = <<<'YAML'
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
        parts:
          Feature:
            table: feature_parts
            switch:
              - when: children.any(item.icon_id != null)
                block: iconCardsBlock
                children:
                  iconCardsItems:
                    table: feature_items
                    fk: feature_id
                    order: weight
                    map:
                      heading: title
                      image:   "coalesce(image_id | asset, icon_id | asset)"
              - else: true
                block: cardsBlock
                children:
                  cards:
                    table: feature_items
                    fk: feature_id
                    order: weight
                    map:
                      heading: title
                      image:   image_id | asset
            map:
              heading: title
        YAML;

    private function db(int $iconId, int $imageId): LegacyDatabase
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
        $pdo->exec('CREATE TABLE feature_parts (id INTEGER, title TEXT)');
        $pdo->exec('CREATE TABLE feature_items (id INTEGER, feature_id INTEGER, weight INTEGER, title TEXT, icon_id INTEGER, image_id INTEGER)');

        $pdo->exec("INSERT INTO kuma_nodes VALUES (17, NULL, 0, 1, 'App\\Entity\\Pages\\ContentPage')");
        $pdo->exec("INSERT INTO kuma_node_versions VALUES (91, 'App\\Entity\\Pages\\ContentPage', 100)");
        $pdo->exec("INSERT INTO kuma_node_translations VALUES (4, 17, 'en', 'Page', 'page', NULL, NULL, 1, 91)");
        $pdo->exec('INSERT INTO content_pages VALUES (100)');
        $pdo->exec("INSERT INTO kuma_page_part_refs VALUES
                    (1, 100, 'App\\\\Entity\\\\Pages\\\\ContentPage', 'main', 1, 1, 'App\\\\Entity\\\\PageParts\\\\FeaturePagePart')");
        $pdo->exec("INSERT INTO feature_parts VALUES (1, 'Highlights')");
        $pdo->exec(sprintf(
            "INSERT INTO feature_items VALUES (1, 1, 1, 'Row', %s, %s)",
            $iconId > 0 ? (string) $iconId : 'NULL',
            $imageId > 0 ? (string) $imageId : 'NULL',
        ));

        return new LegacyDatabase($pdo, 'COM', 'com');
    }

    /** @return array<string, mixed> the one compiled entry */
    private function compile(LegacyDatabase $db): array
    {
        $path = tempnam(sys_get_temp_dir(), 'kuma') . '.yaml';
        file_put_contents($path, self::MAPPING);
        $out = [];

        (new Compiler(Mapping::fromFile($path), new Transforms()))
            ->compile($db, 'COM', static function(array $p) use (&$out): void {
                $out[] = $p;
            });

        self::assertCount(1, $out);

        return $out[0];
    }

    #[Test]
    public function an_item_with_only_an_icon_takes_the_icon_cards_case(): void
    {
        $entry = $this->compile($this->db(iconId: 42, imageId: 0));
        $blocks = $entry['sites']['comEnUs']['fieldValues']['pageBuilder'];

        self::assertCount(1, $blocks);
        self::assertSame('iconCardsBlock', $blocks[0]['type']);
    }

    #[Test]
    public function an_item_with_a_real_photo_takes_the_cards_case_instead(): void
    {
        $entry = $this->compile($this->db(iconId: 0, imageId: 99));
        $blocks = $entry['sites']['comEnUs']['fieldValues']['pageBuilder'];

        self::assertCount(1, $blocks);
        self::assertSame('cardsBlock', $blocks[0]['type']);
        self::assertSame('Row', $blocks[0]['fields']['cards'][0]['fields']['heading']);
    }

    #[Test]
    public function the_winning_case_map_replaces_the_shared_one_not_merges_with_it(): void
    {
        // The icon case's own `children:` targets `iconCardsItems`, not `cards` — proves the
        // switch is choosing a whole case, not layering one case's fields over another's.
        $entry = $this->compile($this->db(iconId: 7, imageId: 0));
        $fields = $entry['sites']['comEnUs']['fieldValues']['pageBuilder'][0]['fields'];

        self::assertArrayHasKey('iconCardsItems', $fields);
        self::assertArrayNotHasKey('cards', $fields);
    }
}
