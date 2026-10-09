<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\kernel;

use Lameco\Kunstmaanmigrator\Compile\BlockBuilder;
use Lameco\Kunstmaanmigrator\Compile\EntityIndex;
use Lameco\Kunstmaanmigrator\Compile\Transforms;
use Lameco\Kunstmaanmigrator\Source\MediaIndex;
use Lameco\Kunstmaanmigrator\Source\PartReader;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `lookup(<table>.<column>)` reads a column off the row a value points at — in a declared
 * entity's table, or in any plain table the mapping names. A catalogue keeps its files that
 * way: a photo's path on `model_photos`, reached through the `models_photos` join table that has
 * no id of its own; a document's on `document`, reached through `document_id`. Neither table is
 * an entry, so neither can be an entity.
 */
final class LookupExpressionTest extends TestCase
{
    private function builder(?MediaIndex $media = null): BlockBuilder
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE models_photos (model_id INTEGER, model_photo_id INTEGER)');
        $pdo->exec('INSERT INTO models_photos VALUES (59, 174), (59, 172), (59, 173), (59, 175), (60, 176)');
        $pdo->exec('CREATE TABLE model_photos (id INTEGER, photo_order INTEGER, path TEXT, media_id INTEGER)');
        $pdo->exec("INSERT INTO model_photos VALUES
            (172, NULL, 'a.jpeg', 7), (173, 1, 'b.jpeg', 8), (174, NULL, 'c.jpeg', 9),
            (175, NULL, NULL, NULL), (176, NULL, 'z.jpeg', NULL)");
        $pdo->exec('CREATE TABLE document (id INTEGER, path TEXT)');
        $pdo->exec("INSERT INTO document VALUES (8, NULL), (10, 'f00.pdf')");
        $pdo->exec('CREATE TABLE countries (id INTEGER, code TEXT)');
        $pdo->exec("INSERT INTO countries VALUES (3, 'FR')");

        return new BlockBuilder(
            new PartReader($pdo),
            new Transforms(),
            'FR',
            null,
            null,
            $media,
            new EntityIndex(['Country' => ['table' => 'countries', 'dedupe' => false]]),
        );
    }

    /** @param array<string, mixed> $row */
    private function value(string $expression, array $row, ?MediaIndex $media = null): mixed
    {
        return $this->builder($media)->fieldsFrom(['target' => $expression], $row, 'Model')['target'] ?? null;
    }

    #[Test]
    public function a_foreign_key_into_a_plain_table_becomes_the_file_it_names(): void
    {
        self::assertSame(
            ['_asset' => '/uploads/documents/f00.pdf'],
            $this->value('document_id | lookup(document.path) | file(uploads/documents)', ['id' => 1, 'document_id' => 10]),
        );
    }

    #[Test]
    public function a_row_whose_column_is_empty_or_missing_yields_nothing(): void
    {
        self::assertNull($this->value('document_id | lookup(document.path) | file(uploads/documents)', ['id' => 1, 'document_id' => 8]));
        self::assertNull($this->value('document_id | lookup(document.path) | file(uploads/documents)', ['id' => 1, 'document_id' => 99]));
        self::assertNull($this->value('document_id | lookup(document.path) | file(uploads/documents)', ['id' => 1, 'document_id' => null]));
    }

    #[Test]
    public function a_join_table_selection_becomes_its_files_in_id_order(): void
    {
        // The join table has two foreign keys and no weight; the photo id is the legacy order.
        // A photo with no path is dropped rather than emitted as an empty asset.
        self::assertSame(
            [
                ['_asset' => '/uploads/models/a.jpeg'],
                ['_asset' => '/uploads/models/b.jpeg'],
                ['_asset' => '/uploads/models/c.jpeg'],
            ],
            $this->value(
                'm2m(models_photos, model_id, model_photo_id) | lookup(model_photos.path) | file(uploads/models)',
                ['id' => 59],
            ),
        );
    }

    #[Test]
    public function an_order_column_sorts_the_list_falling_back_to_the_id(): void
    {
        // As `children:` orders — `ORDER BY photo_order, id` — so an unset order sorts first.
        self::assertSame(
            ['a.jpeg', 'c.jpeg', 'b.jpeg'],
            $this->value('m2m(models_photos, model_id, model_photo_id) | lookup(model_photos.path, photo_order)', ['id' => 59]),
        );
    }

    #[Test]
    public function a_list_of_media_ids_becomes_a_list_of_assets(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE kuma_media (id INTEGER, url TEXT, deleted INTEGER)');
        $pdo->exec("INSERT INTO kuma_media VALUES (7, '/uploads/media/7/a.jpg', 0), (9, '/uploads/media/9/c.jpg', 0)");
        $media = MediaIndex::load($pdo);

        self::assertSame(
            [['_asset' => '/uploads/media/7/a.jpg'], ['_asset' => '/uploads/media/9/c.jpg']],
            $this->value('m2m(models_photos, model_id, model_photo_id) | lookup(model_photos.media_id) | asset', ['id' => 59], $media),
        );
    }

    #[Test]
    public function a_declared_entity_still_reads_its_own_table(): void
    {
        self::assertSame('FR', $this->value('country_id | lookup(Country.code)', ['id' => 1, 'country_id' => 3]));
    }
}
