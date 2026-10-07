<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\load;

use Lameco\Kunstmaanmigrator\load\RemoteVideoUrl;
use PHPUnit\Framework\Attributes\DataProvider;
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
        // A query on a code is dropped, never carried: the player parameters editors pasted
        // (`?rel=0`, `?share=copy`) are not part of the video's identity.
        self::assertSame(
            'https://www.youtube.com/watch?v=a',
            RemoteVideoUrl::fromRow(['metadata' => serialize(['code' => 'a?autoplay=1', 'type' => 'youtube'])]),
        );
        self::assertNull(RemoteVideoUrl::fromRow(['metadata' => serialize(['code' => 'https://evil.example/x', 'type' => 'youtube'])]));
    }

    /**
     * What editors actually typed into the code box, measured on the Berkvens NL and FR corpora:
     * every live FR video carries `?rel=0`, and NL holds pasted URLs, some under the wrong
     * provider. Each of these resolved to nothing and its block came out empty.
     *
     * @return iterable<string, array{string, string, ?string}>
     */
    public static function pastedCodes(): iterable
    {
        yield 'a player parameter on a YouTube id' => ['mvvpliuFMko?rel=0', 'youtube', 'https://www.youtube.com/watch?v=mvvpliuFMko'];
        yield 'a share parameter on a Vimeo id' => ['1006881301?share=copy', 'vimeo', 'https://vimeo.com/1006881301'];
        yield 'the tail of a watch URL' => ['watch?v=YJewRGXG-iI', 'youtube', 'https://www.youtube.com/watch?v=YJewRGXG-iI'];
        yield 'a youtu.be short link' => ['https://youtu.be/p0Rj5DG2P0Y', 'youtube', 'https://www.youtube.com/watch?v=p0Rj5DG2P0Y'];
        yield 'a YouTube URL filed as Vimeo' => ['https://www.youtube.com/watch?v=QzdoQXxlK6I', 'vimeo', 'https://www.youtube.com/watch?v=QzdoQXxlK6I'];
        yield 'a youtu.be link filed as Vimeo' => ['https://youtu.be/z-kYq_4PpMg', 'vimeo', 'https://www.youtube.com/watch?v=z-kYq_4PpMg'];
        yield 'a Vimeo URL with a share parameter' => ['https://vimeo.com/1111624337?share=copy', 'vimeo', 'https://vimeo.com/1111624337'];
        yield 'a YouTube embed URL' => ['https://www.youtube.com/embed/RsInP5y1k-4?rel=0', 'youtube', 'https://www.youtube.com/watch?v=RsInP5y1k-4'];
        yield 'an iframe from a host with no provider' => [
            '<iframe allowfullscreen frameborder="0" src="https://multimedia.europarl.europa.eu/nl/share/N01?autoplay=off"></iframe>',
            'vimeo',
            null,
        ];
    }

    #[DataProvider('pastedCodes')]
    public function testAPastedCodeBecomesTheVideosOwnUrl(string $code, string $type, ?string $expected): void
    {
        self::assertSame($expected, RemoteVideoUrl::fromRow(['metadata' => serialize(['code' => $code, 'type' => $type])]));
    }

    public function testAnObjectInTheBlobIsRefusedNotInstantiated(): void
    {
        $row = ['metadata' => 'O:8:"stdClass":0:{}'];

        self::assertNull(RemoteVideoUrl::fromRow($row));
    }
}
