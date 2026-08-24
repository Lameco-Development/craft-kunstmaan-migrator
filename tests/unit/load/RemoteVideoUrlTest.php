<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\load;

use Lameco\Kunstmaanmigrator\load\RemoteVideoUrl;
use PHPUnit\Framework\TestCase;

/**
 * The metadata blob is Kunstmaan's RemoteVideoHandler serialization — the
 * exact bytes measured on the Enreach corpus, where 281 live remote-video
 * rows previously resolved to nothing.
 */
final class RemoteVideoUrlTest extends TestCase
{
    public function testYoutubeMetadataBecomesAWatchUrl(): void
    {
        $row = ['metadata' => 'a:3:{s:4:"code";s:11:"WPx-Oe2WrUE";s:4:"type";s:7:"youtube";s:13:"thumbnail_url";s:44:"https://img.youtube.com/vi/WPx-Oe2WrUE/0.jpg";}'];

        self::assertSame('https://www.youtube.com/watch?v=WPx-Oe2WrUE', RemoteVideoUrl::fromRow($row));
    }

    public function testVimeoMetadataBecomesAVimeoUrl(): void
    {
        $row = ['metadata' => serialize(['code' => '76979871', 'type' => 'vimeo'])];

        self::assertSame('https://vimeo.com/76979871', RemoteVideoUrl::fromRow($row));
    }

    public function testARowUrlIsTheFallbackWhenMetadataSaysNothing(): void
    {
        self::assertSame(
            'https://www.youtube.com/watch?v=abc',
            RemoteVideoUrl::fromRow(['metadata' => null, 'url' => 'https://www.youtube.com/watch?v=abc']),
        );
    }

    public function testAnUnknownProviderAndABarePathResolveToNothing(): void
    {
        self::assertNull(RemoteVideoUrl::fromRow(['metadata' => serialize(['code' => 'x', 'type' => 'wistia'])]));
        self::assertNull(RemoteVideoUrl::fromRow(['metadata' => '', 'url' => '/uploads/media/foo.mp4']));
    }

    public function testAMaliciousCodeNeverReachesAUrl(): void
    {
        // A code is a provider id, not a place to smuggle query strings or hosts.
        self::assertNull(RemoteVideoUrl::fromRow(['metadata' => serialize(['code' => 'x"onload="evil', 'type' => 'youtube'])]));
        self::assertNull(RemoteVideoUrl::fromRow(['metadata' => serialize(['code' => 'a?autoplay=1', 'type' => 'youtube'])]));
    }

    public function testAnObjectInTheBlobIsRefusedNotInstantiated(): void
    {
        $row = ['metadata' => 'O:8:"stdClass":0:{}'];

        self::assertNull(RemoteVideoUrl::fromRow($row));
    }
}
