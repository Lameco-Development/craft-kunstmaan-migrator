<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\kernel;

use Lameco\Kunstmaanmigrator\Compile\Compiler;
use Lameco\Kunstmaanmigrator\Compile\Transforms;
use Lameco\Kunstmaanmigrator\Mapping\Mapping;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `file(<dir>)` — a column holding a file name under an uploads directory, not a
 * `kuma_media` id. Catalogue tables store `backend_image.name = "A12.jpg"` and the
 * site served it from `/uploads/models_import/A12.jpg`; the transform rebuilds that
 * path so the loader can find the file, the same `_asset` node `asset` emits.
 */
final class FileTransformTest extends TestCase
{
    #[Test]
    #[DataProvider('files')]
    public function a_file_name_becomes_an_asset_path_under_its_uploads_directory(string $transform, mixed $in, ?array $out): void
    {
        self::assertSame($out, (new Transforms())->apply($transform, $in));
    }

    /** @return array<string, array{0: string, 1: mixed, 2: ?array{_asset: string}}> */
    public static function files(): array
    {
        return [
            'bare name' => ['file(uploads/models_import)', 'A12.jpg', ['_asset' => '/uploads/models_import/A12.jpg']],
            'relative path below the dir' => ['file(uploads/documents)', 'brochures/x.pdf', ['_asset' => '/uploads/documents/brochures/x.pdf']],
            'leading and trailing slashes on the dir' => ['file(/uploads/documents/)', 'x.pdf', ['_asset' => '/uploads/documents/x.pdf']],
            'value already carries the dir' => ['file(uploads/documents)', 'uploads/documents/x.pdf', ['_asset' => '/uploads/documents/x.pdf']],
            'value already carries the dir, rooted' => ['file(uploads/documents)', '/uploads/documents/x.pdf', ['_asset' => '/uploads/documents/x.pdf']],
            'surrounding whitespace' => ['file(uploads/documents)', "  x.pdf\n", ['_asset' => '/uploads/documents/x.pdf']],
            'bare file takes a web-root-relative path' => ['file', 'uploads/model_photos/7.jpg', ['_asset' => '/uploads/model_photos/7.jpg']],
            'null' => ['file(uploads/documents)', null, null],
            'empty' => ['file(uploads/documents)', '  ', null],
        ];
    }

    #[Test]
    public function a_list_of_names_becomes_a_list_of_asset_paths(): void
    {
        // `m2m(...) | file(...)` reads a list; casting it to a string gave `/uploads/x/Array`.
        self::assertSame(
            [['_asset' => '/uploads/documents/a.pdf'], ['_asset' => '/uploads/documents/b.pdf']],
            (new Transforms())->apply('file(uploads/documents)', ['a.pdf', null, ' ', 'b.pdf']),
        );
        self::assertNull((new Transforms())->apply('file(uploads/documents)', []));
    }

    #[Test]
    public function the_editor_offers_it_beside_asset(): void
    {
        self::assertArrayHasKey('file', Transforms::available());
    }

    #[Test]
    public function a_mapping_pipes_a_column_through_it_into_an_assets_field(): void
    {
        $yaml = str_replace(
            'heroImage: header_image_id | asset',
            'heroImage: title | file(uploads/models_import)',
            PageFieldsLaneTest::MAPPING,
        );
        $path = tempnam(sys_get_temp_dir(), 'kuma') . '.yaml';
        file_put_contents($path, $yaml);

        $out = [];
        (new Compiler(Mapping::fromFile($path), new Transforms(), PageFieldsLaneTest::schema()))
            ->compile(PageFieldsLaneTest::db(), 'NL', static function(array $p) use (&$out): void {
                $out[(string) $p['sourceUid']] = $p;
            });

        $hero = array_column(array_column(array_column($out, 'sites'), 'berkvensNl'), 'fieldValues');

        self::assertContains(
            ['_asset' => '/uploads/models_import/Binnendeuren op maat'],
            array_column($hero, 'heroImage'),
        );
    }
}
