<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\kernel;

use Lameco\Kunstmaanmigrator\Source\MediaIndex;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MediaIndexTest extends TestCase
{
    private function index(): MediaIndex
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE kuma_media (id INTEGER, url TEXT, deleted INTEGER)');
        $pdo->exec("INSERT INTO kuma_media VALUES (4565, '/uploads/media/abc/one.png', 0)");
        $pdo->exec("INSERT INTO kuma_media VALUES (9, '/uploads/media/def/two.jpg', 1)");
        $pdo->exec("INSERT INTO kuma_media VALUES (10, '', 0)");

        return MediaIndex::load($pdo);
    }

    #[Test]
    public function a_media_id_resolves_to_the_path_the_loader_expects(): void
    {
        // The legacy column holds an id; the _asset contract takes a path. Emitting the id
        // produced references nothing could resolve.
        self::assertSame('/uploads/media/abc/one.png', $this->index()->pathFor(4565));
        self::assertSame('/uploads/media/abc/one.png', $this->index()->pathFor('4565'));
    }

    #[Test]
    public function deleted_empty_and_unknown_rows_resolve_to_nothing(): void
    {
        self::assertNull($this->index()->pathFor(9), 'deleted');
        self::assertNull($this->index()->pathFor(10), 'no url');
        self::assertNull($this->index()->pathFor(999), 'dangling reference');
        self::assertNull($this->index()->pathFor(null));
        self::assertSame(1, $this->index()->count());
    }

    #[Test]
    public function a_live_remote_video_resolves_to_its_media_id_rather_than_a_path(): void
    {
        // A YouTube or Vimeo row has no file and no url: Kunstmaan keeps the video code in
        // `metadata`. Only the loader can turn it into an embedded asset, and it finds the row
        // by id, so the reference names the id. Every live Berkvens video is one of these.
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE kuma_media (id INTEGER, url TEXT, deleted INTEGER, content_type TEXT)');
        $pdo->exec("INSERT INTO kuma_media VALUES (1381, NULL, 0, 'remote/video')");
        $pdo->exec("INSERT INTO kuma_media VALUES (2, NULL, 1, 'remote/video')");
        $pdo->exec("INSERT INTO kuma_media VALUES (7, '/uploads/media/deur.jpg', 0, 'image/jpeg')");
        $pdo->exec("INSERT INTO kuma_media VALUES (8, NULL, 0, 'image/jpeg')");

        $index = MediaIndex::load($pdo);

        self::assertSame('kuma:media:1381', $index->pathFor(1381));
        self::assertNull($index->pathFor(2), 'a deleted remote video is still a dangling reference');
        self::assertSame('/uploads/media/deur.jpg', $index->pathFor(7));
        self::assertNull($index->pathFor(8), 'a local file without a url has nothing to resolve');
    }
}
