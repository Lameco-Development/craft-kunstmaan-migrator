<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\compile;

use Lameco\KumaCompile\Legacy\MediaIndex;
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
}
