<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\kernel;

use Lameco\Kunstmaanmigrator\Compile\Compiler;
use Lameco\Kunstmaanmigrator\Compile\Transforms;
use Lameco\Kunstmaanmigrator\Mapping\FieldProvenance;
use Lameco\Kunstmaanmigrator\Mapping\Mapping;
use Lameco\Kunstmaanmigrator\Report\BlockPlacement;
use Lameco\Kunstmaanmigrator\Report\Coverage;
use Lameco\Kunstmaanmigrator\Report\CoverageReport;
use Lameco\Kunstmaanmigrator\Report\Readiness;
use Lameco\Kunstmaanmigrator\Source\LegacyDatabase;
use Lameco\Kunstmaanmigrator\Source\LiveSnapshot;
use Lameco\Kunstmaanmigrator\Target\Slot;
use Lameco\Kunstmaanmigrator\Target\TargetCheck;
use Lameco\Kunstmaanmigrator\Target\TargetSchema;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A `target: page` context writes its first `consumedBy: page` part onto the page's own fields (#90).
 *
 * Mirrors the Berkvens NL model: the legacy `header` context holds a `HeaderPagePart` (an image
 * hero) or a `HeaderSliderPagePart` (a slider hero), and the target keeps the hero as fields on
 * a Hero tab — `heroType`, `heroTitle`, `heroTitleTag`, `heroSubtitle`, `heroSubtitleLevel`,
 * `heroImage`, `heroSlides` — never as a block in `pageBuilderBerkvensNl`.
 */
final class PageFieldsLaneTest extends TestCase
{
    public const MAPPING = <<<'YAML'
        version: 1
        environments:
          NL:
            database: nl
            locales: { nl: berkvensNl }
        defaults:
          contexts:
            header: { target: page }
            main: { field: pageBuilderBerkvensNl }
        pages:
          TextPage:
            table: text_pages
            section: pages
            entryType: berkvensNlContentPage
            ignore: []
          LandingPage:
            table: landing_pages
            section: pages
            entryType: berkvensNlContentPage
            map:
              heroTitle: heading
        parts:
          Header:
            table: header_page_parts
            consumedBy: page
            map:
              heroType: "'image'"
              heroTitle: title
              heroTitleTag: title_type
              heroSubtitle: subtitle
              heroSubtitleLevel: subtitle_niv
              heroImage: header_image_id | asset
          HeaderSlider:
            table: header_slider_page_parts
            consumedBy: page
            requires: [heroSlides]
            map:
              heroType: "'slider'"
            children:
              heroSlides:
                table: header_slider_slides
                fk: header_slider_page_part_id
                map:
                  slideTitle: title
          Text:
            table: text_parts
            block: textBlock
            map: { content: content }

        YAML;

    /**
     * @param list<string>                      $without `entryType.field` slots to leave out
     * @param array<string, array<string, Slot>> $extra   further entry types
     */
    public static function schema(array $without = [], array $extra = []): TargetSchema
    {
        return new class($without, $extra) implements TargetSchema {
            /** @var array<string, array<string, Slot>> */
            private array $types;

            /**
             * @param list<string>                      $without
             * @param array<string, array<string, Slot>> $extra
             */
            public function __construct(array $without, array $extra)
            {
                $this->types = [
                    'berkvensNlContentPage' => [
                        'pageBuilderBerkvensNl' => new Slot('pageBuilderBerkvensNl', 'Matrix', false, ['textBlock']),
                        'heroType' => new Slot('heroType', 'Dropdown', false),
                        'heroTitle' => new Slot('heroTitle', 'PlainText', false),
                        'heroTitleTag' => new Slot('heroTitleTag', 'Dropdown', false),
                        'heroSubtitle' => new Slot('heroSubtitle', 'PlainText', false),
                        'heroSubtitleLevel' => new Slot('heroSubtitleLevel', 'Dropdown', false),
                        'heroImage' => new Slot('heroImage', 'Assets', false),
                        'heroSlides' => new Slot('heroSlides', 'Matrix', false, ['heroSlide']),
                    ],
                    'heroSlide' => ['slideTitle' => new Slot('slideTitle', 'PlainText', false)],
                    'textBlock' => ['content' => new Slot('content', 'CKEditor', false)],
                ] + $extra;

                foreach ($without as $slot) {
                    [$type, $field] = explode('.', $slot, 2);
                    unset($this->types[$type][$field]);
                }
            }

            public function hasEntryType(string $handle): bool
            {
                return isset($this->types[$handle]);
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
                return $this->types[$entryType] ?? [];
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
                return $this->slot($entryType, $field) !== null ? '' : null;
            }

            public function nestedTypeOf(string $entryType, string $field): ?string
            {
                $slot = $this->slot($entryType, $field);

                return $slot !== null && count($slot->nested) === 1 ? $slot->nested[0] : null;
            }
        };
    }

    /**
     * Six pages, one per case:
     * 17 image hero + body text · 18 slider hero · 19 landing page whose own map writes `heroTitle`
     * · 20 two header parts · 21 a slider with no slides · 22 an empty slider, then an image hero.
     */
    public static function db(): LegacyDatabase
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
        $pdo->exec('CREATE TABLE kuma_media (id INTEGER, url TEXT, deleted INTEGER)');
        $pdo->exec('CREATE TABLE text_pages (id INTEGER)');
        $pdo->exec('CREATE TABLE landing_pages (id INTEGER, heading TEXT)');
        $pdo->exec('CREATE TABLE header_page_parts
                    (id INTEGER, title TEXT, title_type TEXT, subtitle TEXT, subtitle_niv TEXT, header_image_id INTEGER)');
        $pdo->exec('CREATE TABLE header_slider_page_parts (id INTEGER)');
        $pdo->exec('CREATE TABLE header_slider_slides (id INTEGER, header_slider_page_part_id INTEGER, weight INTEGER, title TEXT)');
        $pdo->exec('CREATE TABLE text_parts (id INTEGER, content TEXT)');

        $text = 'App\\Entity\\Pages\\TextPage';
        $landing = 'App\\Entity\\Pages\\LandingPage';
        $pdo->exec("INSERT INTO kuma_nodes VALUES
                    (17, NULL, 0, 1, '$text'), (18, NULL, 0, 3, '$text'), (19, NULL, 0, 5, '$landing'),
                    (20, NULL, 0, 7, '$text'), (21, NULL, 0, 9, '$text'), (22, NULL, 0, 11, '$text')");
        $pdo->exec("INSERT INTO kuma_node_versions VALUES
                    (91, '$text', 100), (92, '$text', 101), (93, '$landing', 200),
                    (94, '$text', 102), (95, '$text', 103), (96, '$text', 104)");
        $pdo->exec("INSERT INTO kuma_node_translations VALUES
                    (1, 17, 'nl', 'Binnendeuren', 'binnendeuren', NULL, NULL, 1, 91),
                    (2, 18, 'nl', 'Showroom', 'showroom', NULL, NULL, 1, 92),
                    (3, 19, 'nl', 'Actie', 'actie', NULL, NULL, 1, 93),
                    (4, 20, 'nl', 'Dubbel', 'dubbel', NULL, NULL, 1, 94),
                    (5, 21, 'nl', 'Leeg', 'leeg', NULL, NULL, 1, 95),
                    (6, 22, 'nl', 'Terugval', 'terugval', NULL, NULL, 1, 96)");
        $pdo->exec('INSERT INTO text_pages VALUES (100), (101), (102), (103), (104)');
        $pdo->exec("INSERT INTO landing_pages VALUES (200, 'Landingskop')");
        $pdo->exec("INSERT INTO kuma_media VALUES (7, '/uploads/media/deur.jpg', 0)");

        // Doubled backslashes: the suite's sqlite convention for LIKE-matched entity columns.
        $p = 'App\\\\Entity\\\\Pages\\\\';
        $header = 'App\\\\Entity\\\\PageParts\\\\HeaderPagePart';
        $slider = 'App\\\\Entity\\\\PageParts\\\\HeaderSliderPagePart';
        $body = 'App\\\\Entity\\\\PageParts\\\\TextPagePart';

        $pdo->exec("INSERT INTO kuma_page_part_refs VALUES
                    (1, 100, '{$p}TextPage', 'main', 1, 1, '$body'),
                    (2, 100, '{$p}TextPage', 'header', 1, 1, '$header'),
                    (3, 101, '{$p}TextPage', 'header', 1, 1, '$slider'),
                    (4, 200, '{$p}LandingPage', 'header', 1, 2, '$header'),
                    (5, 102, '{$p}TextPage', 'header', 1, 3, '$header'),
                    (6, 102, '{$p}TextPage', 'header', 2, 4, '$header'),
                    (7, 103, '{$p}TextPage', 'header', 1, 2, '$slider'),
                    (8, 104, '{$p}TextPage', 'header', 1, 3, '$slider'),
                    (9, 104, '{$p}TextPage', 'header', 2, 5, '$header')");
        $pdo->exec("INSERT INTO header_page_parts VALUES
                    (1, 'Binnendeuren op maat', 'h1', 'Vakmanschap sinds 1919', '3', 7),
                    (2, 'Kop uit de part', 'h2', 'Actie-ondertitel', '2', NULL),
                    (3, 'Eerste kop', 'h1', NULL, NULL, NULL),
                    (4, 'Tweede kop', 'h1', NULL, NULL, NULL),
                    (5, 'Terugvalkop', 'h1', NULL, NULL, NULL)");
        $pdo->exec('INSERT INTO header_slider_page_parts VALUES (1), (2), (3)');
        $pdo->exec("INSERT INTO header_slider_slides VALUES (1, 1, 1, 'Dia een'), (2, 1, 2, 'Dia twee')");
        $pdo->exec("INSERT INTO text_parts VALUES (1, 'Onze deuren')");

        return new LegacyDatabase($pdo, 'NL', 'nl');
    }

    private static function mappingFile(string $yaml): string
    {
        $path = tempnam(sys_get_temp_dir(), 'kuma') . '.yaml';
        file_put_contents($path, $yaml);

        return $path;
    }

    /** @return array{0: array<int, array<string, mixed>>, 1: Compiler} entries keyed by node id */
    private function compile(string $yaml = self::MAPPING, ?TargetSchema $schema = null): array
    {
        $out = [];
        $compiler = new Compiler(Mapping::fromFile(self::mappingFile($yaml)), new Transforms(), $schema ?? self::schema());
        $compiler->compile(self::db(), 'NL', static function(array $p) use (&$out): void {
            $out[(int) substr((string) $p['sourceUid'], strrpos((string) $p['sourceUid'], ':') + 1)] = $p;
        });

        return [$out, $compiler];
    }

    /** @return array<string, mixed> */
    private static function fieldsOf(array $entry): array
    {
        return $entry['sites']['berkvensNl']['fieldValues'] ?? [];
    }

    #[Test]
    public function a_header_part_fills_the_image_hero_and_no_block(): void
    {
        [$entries] = $this->compile();

        self::assertSame(
            [
                'heroType' => 'image',
                'heroTitle' => 'Binnendeuren op maat',
                'heroTitleTag' => 'h1',
                'heroSubtitle' => 'Vakmanschap sinds 1919',
                'heroSubtitleLevel' => '3',
                'heroImage' => ['_asset' => '/uploads/media/deur.jpg'],
                'pageBuilderBerkvensNl' => [
                    ['type' => 'textBlock', 'fields' => ['content' => 'Onze deuren', '_sourcePartRef' => 'NL:text_parts:1']],
                ],
            ],
            self::fieldsOf($entries[17]),
        );
    }

    #[Test]
    public function a_remote_video_compiles_to_a_media_id_reference_and_is_no_loss(): void
    {
        // The Berkvens video blocks: `media_id | asset` on a YouTube row (no url, the code in
        // `metadata`) compiled to nothing and recorded the media as unresolved, though the
        // loader can make an embedded asset of it — by id.
        $db = self::db();
        $db->pdo()->exec('ALTER TABLE kuma_media ADD COLUMN content_type TEXT');
        $db->pdo()->exec("UPDATE kuma_media SET url = NULL, content_type = 'remote/video' WHERE id = 7");

        $transforms = new Transforms();
        $out = [];
        $compiler = new Compiler(Mapping::fromFile(self::mappingFile(self::MAPPING)), $transforms, self::schema());
        $compiler->compile($db, 'NL', static function(array $p) use (&$out): void {
            $out[] = $p;
        });

        self::assertSame(['_asset' => 'kuma:media:7'], self::fieldsOf($out[0])['heroImage'] ?? null);
        self::assertArrayNotHasKey('asset', $transforms->losses());
    }

    #[Test]
    public function a_header_slider_part_fills_the_slider_hero(): void
    {
        [$entries] = $this->compile();

        self::assertSame(
            [
                'heroType' => 'slider',
                'heroSlides' => [
                    ['type' => 'heroSlide', 'fields' => ['slideTitle' => 'Dia een', '_sourcePartRef' => 'NL:header_slider_slides:1']],
                    ['type' => 'heroSlide', 'fields' => ['slideTitle' => 'Dia twee', '_sourcePartRef' => 'NL:header_slider_slides:2']],
                ],
            ],
            self::fieldsOf($entries[18]),
        );
    }

    #[Test]
    public function the_pages_own_map_wins_a_collision(): void
    {
        [$entries] = $this->compile();
        $fields = self::fieldsOf($entries[19]);

        // LandingPage maps `heading` to `heroTitle` itself; the part fills the rest.
        self::assertSame('Landingskop', $fields['heroTitle']);
        self::assertSame('image', $fields['heroType']);
        self::assertSame('Actie-ondertitel', $fields['heroSubtitle']);
    }

    #[Test]
    public function a_second_header_part_is_counted_not_written(): void
    {
        [$entries, $compiler] = $this->compile();

        self::assertSame(['heroType' => 'image', 'heroTitle' => 'Eerste kop', 'heroTitleTag' => 'h1'], self::fieldsOf($entries[20]));
        self::assertSame(
            1,
            $compiler->skipped()['page context header on berkvensNlContentPage: Header not written — Header already filled the page'] ?? null,
        );
    }

    #[Test]
    public function a_slider_with_no_slides_is_reported_not_written(): void
    {
        [$entries, $compiler] = $this->compile();

        // No `heroType: slider` over an empty `heroSlides`: Craft would refuse the entry.
        self::assertSame([], self::fieldsOf($entries[21]));
        self::assertSame(
            2,
            $compiler->skipped()['page part HeaderSlider on berkvensNlContentPage: heroSlides is empty — not written'] ?? null,
        );
    }

    #[Test]
    public function a_part_that_cannot_be_written_lets_the_next_one_fill_the_page(): void
    {
        [$entries] = $this->compile();

        self::assertSame(['heroType' => 'image', 'heroTitle' => 'Terugvalkop', 'heroTitleTag' => 'h1'], self::fieldsOf($entries[22]));
    }

    #[Test]
    public function a_part_with_no_page_mapping_in_a_page_context_is_counted(): void
    {
        $db = self::db();
        $db->pdo()->exec("UPDATE kuma_page_part_refs SET context = 'header' WHERE id = 1");

        $compiler = new Compiler(Mapping::fromFile(self::mappingFile(self::MAPPING)), new Transforms(), self::schema());
        $out = [];
        $compiler->compile($db, 'NL', static function(array $p) use (&$out): void {
            $out[] = $p;
        });

        // The body text now sits in the header ahead of the hero: it is no block and no hero field.
        self::assertSame(1, $compiler->skipped()['page context header on berkvensNlContentPage: Text is not a `consumedBy: page` part'] ?? null);
        self::assertSame('Binnendeuren op maat', $out[0]['sites']['berkvensNl']['fieldValues']['heroTitle']);
        self::assertArrayNotHasKey('pageBuilderBerkvensNl', $out[0]['sites']['berkvensNl']['fieldValues']);
    }

    #[Test]
    public function a_target_the_entry_type_lacks_is_dropped_and_counted(): void
    {
        $yaml = str_replace('heroSubtitleLevel: subtitle_niv', "heroSubtitleLevel: subtitle_niv\n      heroTitleStyle: title_type", self::MAPPING);
        [$entries, $compiler] = $this->compile($yaml);

        self::assertArrayNotHasKey('heroTitleStyle', self::fieldsOf($entries[17]));
        self::assertSame(4, $compiler->skipped()['page part Header: heroTitleStyle not on berkvensNlContentPage'] ?? null);
    }

    #[Test]
    public function a_required_field_the_entry_type_lacks_fails_the_requirement(): void
    {
        // `heroSlides` is dropped as off the type, so the slider has nothing to show: no
        // `heroType: slider` over nothing, and the image hero behind the empty slider still wins.
        [$entries, $compiler] = $this->compile(schema: self::schema(['berkvensNlContentPage.heroSlides']));

        self::assertSame([], self::fieldsOf($entries[18]));
        self::assertSame(['heroType' => 'image', 'heroTitle' => 'Terugvalkop', 'heroTitleTag' => 'h1'], self::fieldsOf($entries[22]));
        // Nodes 18, 21 and 22: every slider, with or without slides in the legacy data.
        self::assertSame(
            3,
            $compiler->skipped()['page part HeaderSlider on berkvensNlContentPage: heroSlides is empty — not written'] ?? null,
        );
    }

    #[Test]
    public function a_part_whose_every_target_is_dropped_lets_the_next_one_fill_the_page(): void
    {
        // The slider maps only a field the type lacks: it writes nothing, so it fills nothing.
        $yaml = str_replace(
            "    requires: [heroSlides]\n    map:\n      heroType: \"'slider'\"\n    children:\n      heroSlides:\n"
            . "        table: header_slider_slides\n        fk: header_slider_page_part_id\n        map:\n          slideTitle: title\n",
            "    map:\n      heroVideo: \"'slider'\"\n",
            self::MAPPING,
        );
        self::assertStringContainsString('heroVideo', $yaml);
        [$entries, $compiler] = $this->compile($yaml);

        self::assertSame(['heroType' => 'image', 'heroTitle' => 'Terugvalkop', 'heroTitleTag' => 'h1'], self::fieldsOf($entries[22]));
        self::assertSame(3, $compiler->skipped()['page part HeaderSlider on berkvensNlContentPage: no field it maps is on the entry type — not written'] ?? null);
    }

    #[Test]
    public function compile_names_the_part_each_page_context_fills(): void
    {
        // What `state/explain` asks: the same selection compile makes, per legacy lang.
        $compiler = new Compiler(Mapping::fromFile(self::mappingFile(self::MAPPING)), new Transforms(), self::schema());
        $run = $compiler->begin(self::db(), 'NL');

        self::assertSame(['nl' => ['header' => ['part' => 'Header', 'id' => 3]]], $compiler->pageContextFills($run, 20));
        self::assertSame(['nl' => ['header' => null]], $compiler->pageContextFills($run, 21));
        self::assertSame(['nl' => ['header' => ['part' => 'Header', 'id' => 5]]], $compiler->pageContextFills($run, 22));
        self::assertNull($compiler->pageContextFills($run, 999));
    }

    #[Test]
    public function the_batched_unit_path_writes_the_same_page_fields(): void
    {
        [$entries, $whole] = $this->compile();

        $compiler = new Compiler(Mapping::fromFile(self::mappingFile(self::MAPPING)), new Transforms(), self::schema());
        $run = $compiler->begin(self::db(), 'NL');
        $batched = [];

        foreach ([17, 18, 19, 20, 21, 22] as $nodeId) {
            $compiler->compileNodeUnit($run, $nodeId, static function(array $p) use (&$batched, $nodeId): void {
                $batched[$nodeId] = $p;
            });
        }

        self::assertSame($entries, $batched);
        self::assertSame($whole->skipped(), $compiler->skipped());
    }

    #[Test]
    public function the_target_check_accepts_page_fields_the_entry_type_has(): void
    {
        $check = new TargetCheck(self::schema());
        $mapping = Mapping::fromFile(self::mappingFile(self::MAPPING));

        self::assertSame([], $check->check($mapping));
        // A page context names no Matrix, so it cannot be a builder the entry type lacks.
        self::assertSame([], $check->pagesWithNoBlockField($mapping));
    }

    #[Test]
    public function the_target_check_rejects_a_page_field_the_entry_type_lacks(): void
    {
        $yaml = str_replace(
            ['heroSubtitleLevel: subtitle_niv', 'slideTitle: title'],
            ["heroSubtitleLevel: subtitle_niv\n      heroTitleStyle: title_type", 'slideCaption: title'],
            self::MAPPING,
        );

        self::assertSame(
            [
                'part `Header`: page entry type `berkvensNlContentPage` has no field `heroTitleStyle`',
                'part `HeaderSlider`: nested `heroSlide` has no field `slideCaption`',
            ],
            (new TargetCheck(self::schema()))->check(Mapping::fromFile(self::mappingFile($yaml))),
        );
    }

    #[Test]
    public function a_page_type_that_merely_lacks_a_page_part_field_is_a_warning(): void
    {
        // The landing page has its own type, with no `heroImage` and no `heroSlides`. TextPage's type
        // carries both, so the mapping is not wrong — those pages just lose the image and the slides.
        $yaml = str_replace(
            "    entryType: berkvensNlContentPage\n    map:\n      heroTitle: heading",
            "    entryType: berkvensNlLandingPage\n    map:\n      heroTitle: heading",
            self::MAPPING,
        );
        $schema = self::schema(extra: ['berkvensNlLandingPage' => [
            'pageBuilderBerkvensNl' => new Slot('pageBuilderBerkvensNl', 'Matrix', false, ['textBlock']),
            'heroType' => new Slot('heroType', 'Dropdown', false),
            'heroTitle' => new Slot('heroTitle', 'PlainText', false),
            'heroTitleTag' => new Slot('heroTitleTag', 'Dropdown', false),
            'heroSubtitle' => new Slot('heroSubtitle', 'PlainText', false),
            'heroSubtitleLevel' => new Slot('heroSubtitleLevel', 'Dropdown', false),
        ]]);
        $check = new TargetCheck($schema);
        $mapping = Mapping::fromFile(self::mappingFile($yaml));

        self::assertSame([], $check->check($mapping));
        self::assertSame(
            [
                'part `Header` writes `heroImage`, which page entry type `berkvensNlLandingPage` does not have — dropped on those pages',
                'part `HeaderSlider` writes `heroSlides`, which page entry type `berkvensNlLandingPage` does not have — dropped on those pages',
            ],
            $check->pagesWithNoBlockField($mapping),
        );
    }

    #[Test]
    public function header_placements_are_not_judged_against_a_matrix_allow_list(): void
    {
        $placement = new BlockPlacement(Mapping::fromFile(self::mappingFile(self::MAPPING)), self::schema());

        self::assertSame([], $placement->rejections([
            'TextPage' => ['header' => ['Header' => 5, 'HeaderSlider' => 2], 'main' => ['Text' => 1]],
        ]));
    }

    /** Three live TextPages: two Headers and a HeaderSlider, a lone Header, a lone Text in the header. */
    private static function stacksDb(): PDO
    {
        // Single backslashes: this query joins on equality, as MySQL holds the names.
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE kuma_nodes (id INTEGER, deleted INTEGER)');
        $pdo->exec('CREATE TABLE kuma_node_versions (id INTEGER, ref_entity_name TEXT, ref_id INTEGER)');
        $pdo->exec('CREATE TABLE kuma_node_translations (node_id INTEGER, lang TEXT, online INTEGER, public_node_version_id INTEGER)');
        $pdo->exec('CREATE TABLE kuma_page_part_refs (pageId INTEGER, pageEntityname TEXT, context TEXT, page_part_entityname TEXT)');
        $pdo->exec('INSERT INTO kuma_nodes VALUES (17, 0), (18, 0), (19, 0)');
        $pdo->exec("INSERT INTO kuma_node_versions VALUES
                    (91, 'App\\Entity\\Pages\\TextPage', 100), (92, 'App\\Entity\\Pages\\TextPage', 101),
                    (93, 'App\\Entity\\Pages\\TextPage', 102)");
        $pdo->exec("INSERT INTO kuma_node_translations VALUES (17, 'nl', 1, 91), (18, 'nl', 1, 92), (19, 'nl', 1, 93)");
        $pdo->exec("INSERT INTO kuma_page_part_refs VALUES
                    (100, 'App\\Entity\\Pages\\TextPage', 'header', 'App\\Entity\\PageParts\\HeaderPagePart'),
                    (100, 'App\\Entity\\Pages\\TextPage', 'header', 'App\\Entity\\PageParts\\HeaderPagePart'),
                    (100, 'App\\Entity\\Pages\\TextPage', 'header', 'App\\Entity\\PageParts\\HeaderSliderPagePart'),
                    (101, 'App\\Entity\\Pages\\TextPage', 'header', 'App\\Entity\\PageParts\\HeaderPagePart'),
                    (101, 'App\\Entity\\Pages\\TextPage', 'main', 'App\\Entity\\PageParts\\TextPagePart'),
                    (101, 'App\\Entity\\Pages\\TextPage', 'main', 'App\\Entity\\PageParts\\TextPagePart'),
                    (102, 'App\\Entity\\Pages\\TextPage', 'header', 'App\\Entity\\PageParts\\TextPagePart')");

        return $pdo;
    }

    #[Test]
    public function a_page_context_stack_read_from_the_corpus_is_judged_by_the_short_name_rows(): void
    {
        // The corpus names every class by its qualified name; `Header` and `Text` are short-name
        // rows, and the mix is resolved through them as compile resolves each placement.
        $coverage = new Coverage(Mapping::fromFile(self::mappingFile(self::MAPPING)));
        $coverage->ingest((new LegacyDatabase(self::stacksDb(), 'NL', 'nl'))->snapshot());

        self::assertSame(
            [['page' => 'TextPage', 'context' => 'header', 'placements' => 3]],
            $coverage->pageContextLosses(),
        );
    }

    #[Test]
    public function live_context_stacks_are_read_per_page_type_and_part_mix(): void
    {
        $pdo = self::stacksDb();

        // One entry per mix of classes a context holds on a page: how many such stacks, and how
        // many placements in them. Whether a stack loses anything is the mapping's call.
        self::assertSame(
            ['TextPage' => [
                'header' => ['nl' => [
                    'App\\Entity\\PageParts\\HeaderPagePart,App\\Entity\\PageParts\\HeaderSliderPagePart' => ['stacks' => 1, 'placements' => 3],
                    'App\\Entity\\PageParts\\HeaderPagePart' => ['stacks' => 1, 'placements' => 1],
                    'App\\Entity\\PageParts\\TextPagePart' => ['stacks' => 1, 'placements' => 1],
                ]],
                'main' => ['nl' => ['App\\Entity\\PageParts\\TextPagePart' => ['stacks' => 1, 'placements' => 2]]],
            ]],
            (new LegacyDatabase($pdo, 'NL', 'nl'))->livePageContextStacks(),
        );
    }

    #[Test]
    public function coverage_reports_what_a_page_context_drops_as_losses(): void
    {
        $coverage = new Coverage(Mapping::fromFile(self::mappingFile(self::MAPPING)));
        $coverage->ingest(new LiveSnapshot(
            'NL',
            ['Header' => 9, 'HeaderSlider' => 2, 'Text' => 40],
            ['TextPage' => 6, 'LandingPage' => 1],
            ['nl' => 7],
            51,
            [
                'TextPage' => [
                    // Three pages with a lone hero lose nothing; two pages stacking two lose one each.
                    'header' => [
                        'nl' => [
                            'Header' => ['stacks' => 3, 'placements' => 3],
                            'Header,HeaderSlider' => ['stacks' => 2, 'placements' => 4],
                            // A body text ahead of a hero: the text is dropped, the hero still writes.
                            'Header,Text' => ['stacks' => 1, 'placements' => 2],
                            // A lone body text in the header is no hero and no block.
                            'Text' => ['stacks' => 2, 'placements' => 2],
                        ],
                        // A locale with no Craft site is stranded whole — `strandedLocales()` says so —
                        // so what its header stacks would drop is not counted a second time here.
                        'es' => ['Header' => ['stacks' => 1, 'placements' => 3]],
                    ],
                    'main' => ['nl' => ['Text' => ['stacks' => 6, 'placements' => 30]]],
                ],
                'LandingPage' => ['header' => ['nl' => ['Header' => ['stacks' => 1, 'placements' => 2]]]],
            ],
        ));

        // `main` streams into a Matrix, where a stack is just a page with several blocks.
        self::assertSame(
            [
                ['page' => 'TextPage', 'context' => 'header', 'placements' => 5],
                ['page' => 'LandingPage', 'context' => 'header', 'placements' => 1],
            ],
            $coverage->pageContextLosses(),
        );
        self::assertSame(['blocks' => 40, 'page' => 11], $coverage->placementsByLane());
        self::assertFalse($coverage->hasHoles());

        $report = new CoverageReport($coverage);
        self::assertSame($coverage->pageContextLosses(), $report->toArray()['pageContextLosses']);
        self::assertStringContainsString('| `TextPage` | `header` | 5 |', $report->markdown('kuma-compile coverage', '2026-10-07'));
    }

    #[Test]
    public function readiness_credits_the_hero_fields_to_the_page_lane(): void
    {
        $readiness = new Readiness(Mapping::fromFile(self::mappingFile(self::MAPPING)), self::schema());
        $suppliers = [];

        foreach ($readiness->all() as $requirement) {
            if ($requirement->lane === 'pages' && $requirement->subject === 'TextPage') {
                $suppliers[$requirement->field] = $requirement->supplier;
            }
        }

        self::assertSame('page-parts', $suppliers['heroType']);
        self::assertSame('page-parts', $suppliers['heroSlides']);
        self::assertSame('page-parts', $suppliers['heroImage']);
        self::assertSame('blocks', $suppliers['pageBuilderBerkvensNl']);
    }

    #[Test]
    public function provenance_names_the_page_part_feeding_a_hero_field(): void
    {
        $provenance = FieldProvenance::of(Mapping::fromFile(self::mappingFile(self::MAPPING)), self::schema());
        $fields = $provenance->coverage('page', 'berkvensNlContentPage')['fields'];

        self::assertSame(
            [
                ['lane' => 'page-parts', 'name' => 'Header', 'expression' => "'image'"],
                ['lane' => 'page-parts', 'name' => 'HeaderSlider', 'expression' => "'slider'"],
            ],
            $fields['heroType']['feeders'],
        );
        self::assertSame(
            [['lane' => 'page-parts', 'name' => 'HeaderSlider', 'expression' => 'children of header_slider_slides']],
            $fields['heroSlides']['feeders'],
        );
    }

    /** The image hero also writing the main builder — a field its page's block stream fills. */
    private static function headerWritesTheBuilder(): string
    {
        return str_replace(
            "      heroImage: header_image_id | asset\n",
            "      heroImage: header_image_id | asset\n      pageBuilderBerkvensNl: title\n",
            self::MAPPING,
        );
    }

    #[Test]
    public function blocks_replacing_a_page_part_value_are_counted(): void
    {
        [$entries, $compiler] = $this->compile(self::headerWritesTheBuilder());

        self::assertSame(
            [['type' => 'textBlock', 'fields' => ['content' => 'Onze deuren', '_sourcePartRef' => 'NL:text_parts:1']]],
            self::fieldsOf($entries[17])['pageBuilderBerkvensNl'],
        );
        self::assertSame(
            1,
            $compiler->skipped()['berkvensNlContentPage.pageBuilderBerkvensNl: blocks replace a value from map/children/sidecars/page parts'] ?? null,
        );
    }

    #[Test]
    public function the_target_check_rejects_a_page_part_writing_a_block_field(): void
    {
        self::assertSame(
            ['part `Header`: `pageBuilderBerkvensNl` is a block field on page `TextPage` — the blocks replace the page part\'s value',
                'part `Header`: `pageBuilderBerkvensNl` is a block field on page `LandingPage` — the blocks replace the page part\'s value', ],
            (new TargetCheck(self::schema()))->check(Mapping::fromFile(self::mappingFile(self::headerWritesTheBuilder()))),
        );
    }
}
