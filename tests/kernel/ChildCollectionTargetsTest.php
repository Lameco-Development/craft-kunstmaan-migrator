<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\kernel;

use Lameco\Kunstmaanmigrator\Compile\Compiler;
use Lameco\Kunstmaanmigrator\Compile\Transforms;
use Lameco\Kunstmaanmigrator\Mapping\Mapping;
use Lameco\Kunstmaanmigrator\Report\Readiness;
use Lameco\Kunstmaanmigrator\Report\Requirement;
use Lameco\Kunstmaanmigrator\Source\LegacyDatabase;
use Lameco\Kunstmaanmigrator\Target\Slot;
use Lameco\Kunstmaanmigrator\Target\TargetCheck;
use Lameco\Kunstmaanmigrator\Target\TargetSchema;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A `children:` collection fills whatever kind of field it names, not only a Matrix.
 *
 * Image-only legacy collections — slider slides, gallery items, header tabs — have nothing to
 * become but an ordered list of assets, and the structured-data tables (opening hours, contact
 * points) are rows of a Craft Table field. The target field's type decides the shape: one
 * `{_asset}` per child row for an Assets field, one row map keyed by column handle for a Table.
 */
final class ChildCollectionTargetsTest extends TestCase
{
    public const MAPPING = <<<'YAML'
        version: 1
        environments:
          NL:
            database: nl
            locales: { nl: berkvensNl }
        defaults:
          contexts:
            slider: { target: page }
            main: { field: pageBuilder }
        pages:
          ProjectPage:
            table: project_pages
            section: pages
            entryType: projectPage
            ignore: []
            children:
              galleryImages:
                table: project_images
                fk: project_page_id
                map: { image: media_id | asset }
        parts:
          ImageGallery:
            table: image_gallery_page_parts
            block: imageGalleryBlock
            ignore: []
            children:
              images:
                table: gallery_items
                fk: image_gallery_page_part_id
                map: { image: media_id | asset }
          ImageSlider:
            table: image_slider_page_parts
            consumedBy: page
            children:
              sliderImages:
                table: image_slides
                fk: image_slider_page_part_id
                map: { image: media_id | asset }
        sidecars:
          structuredData:
            table: structured_data
            ignore: []
            children:
              openingHours:
                table: structureddata_openinghour
                fk: structured_data_id
                map: { day: day, opening: opening, closing: closing }
        YAML;

    /** @param array<string, array<string, Slot>> $extra further slots, merged into each entry type */
    public static function schema(array $extra = []): TargetSchema
    {
        return new class($extra) implements TargetSchema {
            /** @var array<string, array<string, Slot>> */
            private array $types;

            /** @param array<string, array<string, Slot>> $extra */
            public function __construct(array $extra)
            {
                $this->types = [
                    'projectPage' => [
                        'pageBuilder' => new Slot('pageBuilder', 'Matrix', false, ['imageGalleryBlock']),
                        'sliderImages' => new Slot('sliderImages', 'Assets', false),
                        'galleryImages' => new Slot('galleryImages', 'Assets', false),
                        'openingHours' => new Slot('openingHours', 'Table', false, columns: ['day', 'opening', 'closing']),
                    ],
                    'imageGalleryBlock' => [
                        'images' => new Slot('images', 'Assets', false),
                    ],
                ];

                foreach ($extra as $type => $slots) {
                    $this->types[$type] = [...$this->types[$type] ?? [], ...$slots];
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
     * One project page (node 17, entity 100) holding a gallery block in `main` and a slider in the
     * `slider` page context, with a structured-data sidecar and gallery images of its own. Every
     * child table is inserted out of `weight` order, and each holds one row whose media id does
     * not resolve.
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
        $pdo->exec('CREATE TABLE project_pages (id INTEGER)');
        $pdo->exec('CREATE TABLE project_images (id INTEGER, project_page_id INTEGER, weight INTEGER, media_id INTEGER)');
        $pdo->exec('CREATE TABLE image_gallery_page_parts (id INTEGER)');
        $pdo->exec('CREATE TABLE gallery_items (id INTEGER, image_gallery_page_part_id INTEGER, weight INTEGER, media_id INTEGER)');
        $pdo->exec('CREATE TABLE image_slider_page_parts (id INTEGER)');
        $pdo->exec('CREATE TABLE image_slides (id INTEGER, image_slider_page_part_id INTEGER, weight INTEGER, media_id INTEGER)');
        $pdo->exec('CREATE TABLE structured_data (id INTEGER, ref_id INTEGER, ref_entity_name TEXT)');
        $pdo->exec('CREATE TABLE structureddata_openinghour
                    (id INTEGER, structured_data_id INTEGER, weight INTEGER, day TEXT, opening TEXT, closing TEXT)');

        $page = 'App\\Entity\\Pages\\ProjectPage';
        $pdo->exec("INSERT INTO kuma_nodes VALUES (17, NULL, 0, 1, '$page')");
        $pdo->exec("INSERT INTO kuma_node_versions VALUES (91, '$page', 100)");
        $pdo->exec("INSERT INTO kuma_node_translations VALUES (1, 17, 'nl', 'Project', 'project', NULL, NULL, 1, 91)");
        $pdo->exec('INSERT INTO project_pages VALUES (100)');
        $pdo->exec("INSERT INTO kuma_media VALUES
                    (1, '/uploads/media/een.jpg', 0), (2, '/uploads/media/twee.jpg', 0), (3, '/uploads/media/drie.jpg', 0)");

        // Doubled backslashes: the suite's sqlite convention for LIKE-matched entity columns.
        $p = 'App\\\\Entity\\\\Pages\\\\';
        $gallery = 'App\\\\Entity\\\\PageParts\\\\ImageGalleryPagePart';
        $slider = 'App\\\\Entity\\\\PageParts\\\\ImageSliderPagePart';
        $pdo->exec("INSERT INTO kuma_page_part_refs VALUES
                    (1, 100, '{$p}ProjectPage', 'main', 1, 5, '$gallery'),
                    (2, 100, '{$p}ProjectPage', 'slider', 1, 6, '$slider')");

        $pdo->exec('INSERT INTO image_gallery_page_parts VALUES (5)');
        $pdo->exec('INSERT INTO gallery_items VALUES (11, 5, 3, 3), (12, 5, 1, 1), (13, 5, 2, 99), (14, 5, 2, 2)');
        $pdo->exec('INSERT INTO image_slider_page_parts VALUES (6)');
        $pdo->exec('INSERT INTO image_slides VALUES (21, 6, 2, 1), (22, 6, 1, 3), (23, 6, 3, 2)');
        $pdo->exec('INSERT INTO project_images VALUES (31, 100, 2, 2), (32, 100, 1, 1)');
        $pdo->exec("INSERT INTO structured_data VALUES (41, 100, '{$p}ProjectPage')");
        $pdo->exec("INSERT INTO structureddata_openinghour VALUES
                    (51, 41, 2, 'Saturday', '10:00', '16:00'),
                    (52, 41, 1, 'Monday', '09:00', '17:30'),
                    (53, 41, 3, 'Sunday', NULL, NULL)");

        return new LegacyDatabase($pdo, 'NL', 'nl');
    }

    public static function mappingFile(string $yaml = self::MAPPING): string
    {
        $path = tempnam(sys_get_temp_dir(), 'kuma') . '.yaml';
        file_put_contents($path, $yaml);

        return $path;
    }

    /** @return array{0: array<string, mixed>, 1: Compiler} node 17's NL field values */
    private function compile(string $yaml = self::MAPPING, ?TargetSchema $schema = null): array
    {
        $out = [];
        $compiler = new Compiler(Mapping::fromFile(self::mappingFile($yaml)), new Transforms(), $schema ?? self::schema());
        $compiler->compile(self::db(), 'NL', static function(array $p) use (&$out): void {
            $out[] = $p;
        });

        return [$out[0]['sites']['berkvensNl']['fieldValues'] ?? [], $compiler];
    }

    #[Test]
    public function a_parts_child_rows_fill_an_assets_field_in_order(): void
    {
        [$fields] = $this->compile();

        self::assertSame(
            [
                ['type' => 'imageGalleryBlock', 'fields' => [
                    '_sourcePartRef' => 'NL:image_gallery_page_parts:5',
                    'images' => [
                        ['_asset' => '/uploads/media/een.jpg'],
                        ['_asset' => '/uploads/media/twee.jpg'],
                        ['_asset' => '/uploads/media/drie.jpg'],
                    ],
                ]],
            ],
            $fields['pageBuilder'],
        );
    }

    /** @return list<array{type: string, fields: array<string, mixed>}> the gallery's four items, in `weight` order */
    private static function galleryBlocks(string $type): array
    {
        return [
            ['type' => $type, 'fields' => ['image' => ['_asset' => '/uploads/media/een.jpg'], '_sourcePartRef' => 'NL:gallery_items:12']],
            // media 99 is not in kuma_media: the block stays, its image does not.
            ['type' => $type, 'fields' => ['_sourcePartRef' => 'NL:gallery_items:13']],
            ['type' => $type, 'fields' => ['image' => ['_asset' => '/uploads/media/twee.jpg'], '_sourcePartRef' => 'NL:gallery_items:14']],
            ['type' => $type, 'fields' => ['image' => ['_asset' => '/uploads/media/drie.jpg'], '_sourcePartRef' => 'NL:gallery_items:11']],
        ];
    }

    #[Test]
    public function a_matrix_collection_still_compiles_to_blocks_of_its_nested_type(): void
    {
        [$fields] = $this->compile(schema: self::schema([
            'imageGalleryBlock' => ['images' => new Slot('images', 'Matrix', false, ['galleryItem'])],
            'galleryItem' => ['image' => new Slot('image', 'Assets', false)],
        ]));

        self::assertSame(
            [['type' => 'imageGalleryBlock', 'fields' => [
                '_sourcePartRef' => 'NL:image_gallery_page_parts:5',
                'images' => self::galleryBlocks('galleryItem'),
            ]]],
            $fields['pageBuilder'],
        );
    }

    #[Test]
    public function a_collection_the_schema_does_not_know_keeps_the_matrix_shape(): void
    {
        [$fields] = $this->compile(str_replace("      images:\n", "      items:\n", self::MAPPING));

        self::assertSame(
            [['type' => 'imageGalleryBlock', 'fields' => [
                '_sourcePartRef' => 'NL:image_gallery_page_parts:5',
                'items' => self::galleryBlocks('items'),
            ]]],
            $fields['pageBuilder'],
        );
    }

    #[Test]
    public function a_page_part_in_a_page_context_fills_the_pages_assets_field(): void
    {
        [$fields] = $this->compile();

        self::assertSame(
            [
                ['_asset' => '/uploads/media/drie.jpg'],
                ['_asset' => '/uploads/media/een.jpg'],
                ['_asset' => '/uploads/media/twee.jpg'],
            ],
            $fields['sliderImages'],
        );
    }

    #[Test]
    public function a_pages_own_child_rows_fill_its_assets_field(): void
    {
        [$fields] = $this->compile();

        self::assertSame(
            [['_asset' => '/uploads/media/een.jpg'], ['_asset' => '/uploads/media/twee.jpg']],
            $fields['galleryImages'],
        );
    }

    #[Test]
    public function a_sidecars_child_rows_fill_a_table_field_keyed_by_column_handle(): void
    {
        [$fields] = $this->compile();

        self::assertSame(
            [
                ['day' => 'Monday', 'opening' => '09:00', 'closing' => '17:30'],
                ['day' => 'Saturday', 'opening' => '10:00', 'closing' => '16:00'],
                ['day' => 'Sunday'],
            ],
            $fields['openingHours'],
        );
    }

    #[Test]
    public function the_batched_unit_path_writes_the_same_collected_values(): void
    {
        [$fields] = $this->compile();

        $compiler = new Compiler(Mapping::fromFile(self::mappingFile()), new Transforms(), self::schema());
        $run = $compiler->begin(self::db(), 'NL');
        $batched = [];
        $compiler->compileNodeUnit($run, 17, static function(array $p) use (&$batched): void {
            $batched[] = $p;
        });

        self::assertSame($fields, $batched[0]['sites']['berkvensNl']['fieldValues']);
    }

    #[Test]
    public function a_child_collection_with_no_rows_writes_nothing(): void
    {
        [$fields] = $this->compile(str_replace('fk: project_page_id', 'fk: weight', self::MAPPING));

        self::assertArrayNotHasKey('galleryImages', $fields);
    }

    #[Test]
    public function the_target_check_accepts_assets_and_table_children(): void
    {
        self::assertSame([], (new TargetCheck(self::schema()))->check(Mapping::fromFile(self::mappingFile())));
    }

    #[Test]
    public function the_target_check_wants_exactly_one_value_per_asset_row(): void
    {
        $yaml = str_replace(
            "fk: image_gallery_page_part_id\n        map: { image: media_id | asset }",
            "fk: image_gallery_page_part_id\n        map: { image: media_id | asset, caption: weight }",
            self::MAPPING,
        );

        self::assertSame(
            ['part `ImageGallery`: `imageGalleryBlock.images` is an Assets field, so its `map:` holds exactly one value — it holds 2'],
            (new TargetCheck(self::schema()))->check(Mapping::fromFile(self::mappingFile($yaml))),
        );
    }

    /** @return array<string, array{0: string}> */
    public static function expressionsThatYieldNoAsset(): array
    {
        return [
            // A raw legacy id: Craft relates whatever asset happens to carry that id.
            'a bare column' => ['media_id'],
            // A list of entry refs, nested inside the asset list the loader flattens.
            'an entry ref' => ['media_id | ref(Media)'],
            'another transform' => ['media_id | url'],
        ];
    }

    #[Test]
    #[DataProvider('expressionsThatYieldNoAsset')]
    public function the_target_check_wants_an_asset_rows_value_to_be_an_asset(string $expression): void
    {
        $yaml = str_replace(
            "fk: image_gallery_page_part_id\n        map: { image: media_id | asset }",
            "fk: image_gallery_page_part_id\n        map: { image: " . $expression . " }",
            self::MAPPING,
        );

        self::assertSame(
            [sprintf('part `ImageGallery`: `imageGalleryBlock.images` is an Assets field, so `image: %s` must end in an asset transform (`| asset`)', $expression)],
            (new TargetCheck(self::schema()))->check(Mapping::fromFile(self::mappingFile($yaml))),
        );
    }

    #[Test]
    public function the_target_check_accepts_a_coalesce_of_assets(): void
    {
        $yaml = str_replace(
            "fk: image_gallery_page_part_id\n        map: { image: media_id | asset }",
            "fk: image_gallery_page_part_id\n        map: { image: 'coalesce(media_id | asset, weight | asset)' }",
            self::MAPPING,
        );

        self::assertSame([], (new TargetCheck(self::schema()))->check(Mapping::fromFile(self::mappingFile($yaml))));
    }

    #[Test]
    public function the_target_check_rejects_a_column_the_table_lacks(): void
    {
        $yaml = str_replace(
            "galleryImages:\n        table: project_images\n        fk: project_page_id\n        map: { image: media_id | asset }",
            "openingHours:\n        table: structureddata_openinghour\n        fk: structured_data_id\n        map: { day: day, opens: opening }",
            self::MAPPING,
        );

        self::assertSame(
            ['page `ProjectPage`: Table `projectPage.openingHours` has no column `opens`'],
            (new TargetCheck(self::schema()))->check(Mapping::fromFile(self::mappingFile($yaml))),
        );
    }

    #[Test]
    public function the_target_check_rejects_any_other_kind_of_field(): void
    {
        $yaml = str_replace('sliderImages:', 'heroTitle:', self::MAPPING);
        $schema = self::schema(['projectPage' => ['heroTitle' => new Slot('heroTitle', 'PlainText', false)]]);

        self::assertSame(
            ['part `ImageSlider`: `projectPage.heroTitle` is PlainText — a `children:` collection fills a Matrix, Assets or Table field'],
            (new TargetCheck($schema))->check(Mapping::fromFile(self::mappingFile($yaml))),
        );
    }

    #[Test]
    public function the_target_check_rejects_a_sidecar_column_the_table_lacks(): void
    {
        $yaml = str_replace('map: { day: day, opening: opening, closing: closing }', 'map: { day: day, opens: opening }', self::MAPPING);

        self::assertSame(
            ['sidecar `structuredData`: Table `projectPage.openingHours` has no column `opens`'],
            (new TargetCheck(self::schema()))->check(Mapping::fromFile(self::mappingFile($yaml))),
        );
    }

    #[Test]
    public function the_target_check_rejects_a_sidecar_collection_no_page_entry_type_has(): void
    {
        $yaml = str_replace("      openingHours:\n        table: structureddata_openinghour", "      openingHour:\n        table: structureddata_openinghour", self::MAPPING);

        self::assertSame(
            ['sidecar `structuredData`: `projectPage` has no field `openingHour`'],
            (new TargetCheck(self::schema()))->check(Mapping::fromFile(self::mappingFile($yaml))),
        );
    }

    #[Test]
    public function the_target_check_wants_exactly_one_value_per_sidecar_asset_row(): void
    {
        $yaml = str_replace(
            'map: { day: day, opening: opening, closing: closing }',
            'map: { day: day, opening: opening, closing: closing }' . "\n      heroImages:\n        table: header_tabs\n        fk: structured_data_id\n        map: { image: media_id | asset, alt: title }",
            self::MAPPING,
        );
        $schema = self::schema(['projectPage' => ['heroImages' => new Slot('heroImages', 'Assets', false)]]);

        self::assertSame(
            ['sidecar `structuredData`: `projectPage.heroImages` is an Assets field, so its `map:` holds exactly one value — it holds 2'],
            (new TargetCheck($schema))->check(Mapping::fromFile(self::mappingFile($yaml))),
        );
    }

    #[Test]
    public function readiness_credits_every_field_a_collection_fills(): void
    {
        $readiness = new Readiness(Mapping::fromFile(self::mappingFile()), self::schema());

        // Every slot the fixture's two entry types carry is written: a page's own collection,
        // a page part's, a sidecar's and a block's — and no Table column or asset row reads as
        // a nested entry type of its own.
        self::assertSame(
            [
                'pages ProjectPage projectPage.pageBuilder <- blocks',
                'pages ProjectPage projectPage.sliderImages <- page-parts',
                'pages ProjectPage projectPage.galleryImages <- children',
                'pages ProjectPage projectPage.openingHours <- sidecars',
                'parts ImageGallery imageGalleryBlock.images <- children',
            ],
            array_map(
                static fn(Requirement $r): string => sprintf('%s %s %s.%s <- %s', $r->lane, $r->subject, $r->target, $r->field, $r->supplier),
                $readiness->all(),
            ),
        );
    }
}
