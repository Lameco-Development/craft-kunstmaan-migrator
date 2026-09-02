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
        $pdo->exec('CREATE TABLE kuma_media (id INTEGER, url TEXT, deleted INTEGER, content_type TEXT, location TEXT)');
        $pdo->exec("INSERT INTO kuma_media VALUES (4565, '/uploads/media/abc/one.png', 0, 'image/png', 'local')");
        $pdo->exec("INSERT INTO kuma_media VALUES (9, '/uploads/media/def/two.jpg', 1, 'image/jpeg', 'local')");
        $pdo->exec("INSERT INTO kuma_media VALUES (10, '', 0, 'image/png', 'local')");
        // A Vimeo/YouTube/Dailymotion oEmbed row: no `url` at all, `content_type` prefixed
        // `remote/` — the shape RemoteVideoHandler leaves behind (Trello #183).
        $pdo->exec("INSERT INTO kuma_media VALUES (3605, NULL, 0, 'remote/video', NULL)");
        // The second classification path: a `video` content type with no `location`, url still
        // null — some flavours of the legacy data carry it this way instead of `remote/*`.
        $pdo->exec("INSERT INTO kuma_media VALUES (3606, NULL, 0, 'video/mp4', '')");
        // A row with neither a url nor a video content type — genuinely dangling, not a
        // remote video misclassified as one.
        $pdo->exec("INSERT INTO kuma_media VALUES (3607, NULL, 0, 'application/octet-stream', NULL)");

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
    public function a_remote_video_row_has_no_path_but_is_still_classified(): void
    {
        // No `url` — `pathFor()` still (correctly) answers null, the same as any other
        // reference `MediaIndex` cannot place a file for.
        self::assertNull($this->index()->pathFor(3605));
        self::assertNull($this->index()->pathFor(3606));

        // But `isRemoteVideo()` tells the two "no path" cases apart: one is a video whose file
        // lives on Vimeo/YouTube/Dailymotion, not a broken reference — see BlockBuilder's
        // `asset` transform, which uses this to avoid dropping the field outright (Trello #183).
        self::assertTrue($this->index()->isRemoteVideo(3605), 'remote/video content type');
        self::assertTrue($this->index()->isRemoteVideo('3605'), 'string id, same as pathFor()');
        self::assertTrue($this->index()->isRemoteVideo(3606), 'video content type, no location');
    }

    #[Test]
    public function a_row_with_no_url_and_no_video_content_type_is_not_a_remote_video(): void
    {
        self::assertFalse($this->index()->isRemoteVideo(3607), 'no url, but not a video either — a genuine dangling reference');
        self::assertFalse($this->index()->isRemoteVideo(10), 'empty url, non-video content type');
        self::assertFalse($this->index()->isRemoteVideo(999), 'unknown id');
        self::assertFalse($this->index()->isRemoteVideo(null));
        self::assertFalse($this->index()->isRemoteVideo(4565), 'a row WITH a url is never classified as remote video, whatever its content type');
    }
}
