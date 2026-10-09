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
 * A row's `order:` key: the order its entries take among their siblings in a Structure.
 *
 * Load order is the structure order without it — `lft` for pages, id for an entity lane — and
 * the legacy admin's drag order lives in `weight`. The compiler turns the key into sibling
 * groups, each already sorted, read from the whole source so a batch boundary cannot change them.
 */
final class StructureOrderTest extends TestCase
{
    private const MAPPING = <<<'YAML'
        version: 1
        environments:
          XI:
            database: xi
            locales: { en: xidoorEn, nl: xidoorNl }
        defaults:
          structuralSection: pages
        entities:
          FaqCategory:
            table: faq_category
            section: faqCategories
            entryType: faqCategory
            title: title
            dedupe: false
            order: weight
          Tag:
            table: tag
            section: tags
            entryType: tag
            title: title
            dedupe: false
        pages:
          HomePage:
            table: home_pages
            section: homePage
            entryType: homePage
          ContentPage:
            table: content_pages
            section: pages
            entryType: contentPage
          ServicePage:
            table: service_pages
            section: services
            entryType: servicePage
            order: weight
        YAML;

    private function mapping(string $yaml = self::MAPPING): Mapping
    {
        $path = tempnam(sys_get_temp_dir(), 'kuma') . '.yaml';
        file_put_contents($path, $yaml);

        return Mapping::fromFile($path);
    }

    private function db(): LegacyDatabase
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE kuma_nodes (id INTEGER, parent_id INTEGER, deleted INTEGER, lft INTEGER, ref_entity_name TEXT)');
        $pdo->exec('CREATE TABLE kuma_node_versions (id INTEGER, ref_entity_name TEXT, ref_id INTEGER)');
        $pdo->exec('CREATE TABLE kuma_node_translations
                    (id INTEGER, node_id INTEGER, lang TEXT, title TEXT, slug TEXT, url TEXT,
                     created TEXT, online INTEGER, public_node_version_id INTEGER, weight INTEGER)');
        $pdo->exec('CREATE TABLE kuma_page_part_refs
                    (id INTEGER, pageId INTEGER, pageEntityname TEXT, context TEXT,
                     sequencenumber INTEGER, page_part_id INTEGER, page_part_entityname TEXT)');
        $pdo->exec('CREATE TABLE kuma_media (id INTEGER, url TEXT, deleted INTEGER)');
        $pdo->exec('CREATE TABLE home_pages (id INTEGER)');
        $pdo->exec('CREATE TABLE content_pages (id INTEGER)');
        $pdo->exec('CREATE TABLE service_pages (id INTEGER)');
        $pdo->exec('CREATE TABLE faq_category (id INTEGER, title TEXT, weight INTEGER)');
        $pdo->exec('CREATE TABLE tag (id INTEGER, title TEXT)');

        // Weights 6, NULL, 1, NULL, 3: weighted rows first, then the NULLs by id. Row 9 has no
        // title, so the lane emits nothing for it and it has no place to take.
        $pdo->exec("INSERT INTO faq_category VALUES
                    (2, 'Verdi', 6), (4, 'Montage', NULL), (5, 'Kleur', 1), (7, 'Prijs', NULL),
                    (8, 'Levering', 3), (9, '', 0)");
        $pdo->exec("INSERT INTO tag VALUES (1, 'A'), (2, 'B')");
        $pdo->exec('INSERT INTO home_pages VALUES (500)');
        $pdo->exec('INSERT INTO content_pages VALUES (700)');
        $pdo->exec('INSERT INTO service_pages VALUES (619), (620), (621), (628), (629), (630)');

        // Home 1 → overview 7 (a content page, another section) → services 19, 20, 21, 28 in
        // lft order. Service 30 sits under 28, its own structure parent. 29 is a root sibling
        // under the home itself — re-rooted like the others, since the home is a Single.
        $pdo->exec("INSERT INTO kuma_nodes VALUES
                    (1,  NULL, 0, 1,  'App\\Entity\\Pages\\HomePage'),
                    (7,  1,    0, 2,  'App\\Entity\\Pages\\ContentPage'),
                    (19, 7,    0, 3,  'App\\Entity\\Pages\\ServicePage'),
                    (20, 7,    0, 5,  'App\\Entity\\Pages\\ServicePage'),
                    (21, 7,    0, 7,  'App\\Entity\\Pages\\ServicePage'),
                    (28, 7,    0, 9,  'App\\Entity\\Pages\\ServicePage'),
                    (30, 28,   0, 10, 'App\\Entity\\Pages\\ServicePage'),
                    (29, 1,    0, 13, 'App\\Entity\\Pages\\ServicePage')");

        $pdo->exec("INSERT INTO kuma_node_versions VALUES
                    (90, 'App\\Entity\\Pages\\HomePage', 500),
                    (91, 'App\\Entity\\Pages\\ContentPage', 700),
                    (92, 'App\\Entity\\Pages\\ServicePage', 619),
                    (93, 'App\\Entity\\Pages\\ServicePage', 620),
                    (94, 'App\\Entity\\Pages\\ServicePage', 621),
                    (95, 'App\\Entity\\Pages\\ServicePage', 628),
                    (96, 'App\\Entity\\Pages\\ServicePage', 629),
                    (97, 'App\\Entity\\Pages\\ServicePage', 630)");

        // EN is the first mapped locale and decides: 20, 21, 19, 28, 29. NL disagrees (its
        // weights would put 19 first) and is ignored. Node 21 has no EN translation, so its NL
        // weight stands in.
        $pdo->exec("INSERT INTO kuma_node_translations VALUES
                    (1,  1,  'en', 'Home',     NULL,  NULL, NULL, 1, 90, 0),
                    (2,  7,  'en', 'Offering', 'our-offering', NULL, NULL, 1, 91, 0),
                    (3,  19, 'en', 'Paint',    'paint', NULL, NULL, 1, 92, 3),
                    (4,  19, 'nl', 'Verf',     'verf',  NULL, NULL, 1, 92, 0),
                    (5,  20, 'en', 'Doors',    'doors', NULL, NULL, 1, 93, 1),
                    (6,  20, 'nl', 'Deuren',   'deuren', NULL, NULL, 1, 93, 5),
                    (7,  21, 'nl', 'Kozijnen', 'kozijnen', NULL, NULL, 1, 94, 2),
                    (8,  28, 'en', 'Hinges',   'hinges', NULL, NULL, 1, 95, 4),
                    (9,  29, 'en', 'Locks',    'locks', NULL, NULL, 1, 96, 5),
                    (10, 30, 'en', 'Small',    'small', NULL, NULL, 1, 97, 0)");

        return new LegacyDatabase($pdo, 'XI', 'xi');
    }

    /** @return list<array{section: string, parent: ?string, members: list<string>}> */
    private function siblingGroups(string $yaml = self::MAPPING): array
    {
        $compiler = new Compiler($this->mapping($yaml), new Transforms());

        return $compiler->structureOrder($compiler->begin($this->db(), 'XI'));
    }

    /** @return array<string, list<string>> "section|parent" => members */
    private function bySection(array $groups): array
    {
        $out = [];

        foreach ($groups as $group) {
            $out[$group['section'] . '|' . ($group['parent'] ?? '')] = $group['members'];
        }

        return $out;
    }

    #[Test]
    public function an_entity_lane_is_sorted_by_its_key_with_null_keys_last_in_id_order(): void
    {
        self::assertSame(
            [
                'kuma:XI:faq_category:5',
                'kuma:XI:faq_category:8',
                'kuma:XI:faq_category:2',
                'kuma:XI:faq_category:4',
                'kuma:XI:faq_category:7',
            ],
            $this->bySection($this->siblingGroups())['faqCategories|'],
        );
    }

    #[Test]
    public function a_row_without_the_key_forms_no_group(): void
    {
        $sections = array_column($this->siblingGroups(), 'section');

        self::assertNotContains('tags', $sections);
        self::assertNotContains('pages', $sections);
    }

    #[Test]
    public function page_siblings_are_sorted_by_the_first_mapped_locale_that_has_the_page(): void
    {
        $groups = $this->bySection($this->siblingGroups());

        // Root siblings of the `services` structure: their legacy parents (7, the home) are in
        // other sections, so all of them land at the root together.
        self::assertSame(
            ['kuma:XI:kuma_nodes:20', 'kuma:XI:kuma_nodes:21', 'kuma:XI:kuma_nodes:19', 'kuma:XI:kuma_nodes:28', 'kuma:XI:kuma_nodes:29'],
            $groups['services|'],
        );
        self::assertSame(['kuma:XI:kuma_nodes:30'], $groups['services|kuma:XI:kuma_nodes:28']);
    }

    #[Test]
    public function a_mapping_without_any_order_key_has_no_groups(): void
    {
        $yaml = (string) preg_replace('/^ +order: weight$\n?/m', '', self::MAPPING);

        self::assertSame([], $this->siblingGroups($yaml));
    }

    #[Test]
    public function the_key_changes_no_payload(): void
    {
        $compile = function(string $yaml): string {
            $out = [];
            (new Compiler($this->mapping($yaml), new Transforms()))->compile($this->db(), 'XI', static function(array $p) use (&$out): void {
                $out[] = $p;
            });

            return (string) json_encode($out);
        };

        self::assertSame(
            $compile((string) preg_replace('/^ +order: weight$\n?/m', '', self::MAPPING)),
            $compile(self::MAPPING),
        );
    }

    #[Test]
    public function the_groups_do_not_depend_on_how_far_the_walk_has_got(): void
    {
        // A batch settles from a run that has compiled every unit, the console from a fresh one.
        $compiler = new Compiler($this->mapping(), new Transforms());
        $fresh = $compiler->structureOrder($compiler->begin($this->db(), 'XI'));

        $run = $compiler->begin($this->db(), 'XI');
        $compiler->compileEntitySlice($run, 'FaqCategory', 0, 2, static function(): void {
        });

        foreach (array_keys($run->nodesById) as $nodeId) {
            $compiler->compileNodeUnit($run, $nodeId, static function(): void {
            });
        }

        $compiler->finishStructural($run, static function(): void {
        });

        self::assertSame($fresh, $compiler->structureOrder($run));
    }
}
