<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\finalize;

use Lameco\Kunstmaanmigrator\finalize\CkeditorRewriterService;
use PHPUnit\Framework\TestCase;

/**
 * A `?token=[M<id>]` link names its file by the token, not by its path.
 *
 * Kunstmaan links a media file as `/uploads/media/<dir>/<file name when linked>?token=[M<id>]`
 * and renders the token, so live serves the media's current file, `kuma_media.url`. When an
 * editor replaces the file, the new one lands in the same `<dir>`, the old one stays on disk and
 * the old name stays in every saved href. Resolved by path first, such a link became its own
 * asset of the old file: Berkvens NL's CTA buttons to kuma_media 2622 linked the 2024
 * `berkvens-deurkozijnwijzer-geluid-20-02-2024-1.pdf`, while live serves the 2025 one.
 *
 * The token is resolved first and the path is the fallback, in rich text and in a Link field.
 */
final class MediaTokenBeforePathTest extends TestCase
{
    private const OLD = '/uploads/media/65d8699802921/berkvens-deurkozijnwijzer-geluid-20-02-2024-1.pdf';

    private function service(): CkeditorRewriterService
    {
        $svc = new CkeditorRewriterService();
        // The old file is still on disk, so its path resolves: to the stale asset.
        $svc->seedUrlIdCache([self::OLD => 10037]);
        $svc->seedKumaMediaIdCache([2622 => 10018]);

        return $svc;
    }

    public function testARichTextLinkResolvesItsTokenBeforeItsPath(): void
    {
        $svc = $this->service();

        $out = $svc->rewrite('<p><a href="' . self::OLD . '?token=[M2622]">Deurkozijnwijzer</a></p>', 1);

        self::assertSame('<p><a href="{asset:10018@1:url}">Deurkozijnwijzer</a></p>', $out);
        self::assertSame([], $svc->consumeUnresolvedDiagnostics());
    }

    public function testAnEncodedTokenIsResolvedFirstToo(): void
    {
        // Craft's CKEditor serializer percent-encodes the brackets on save.
        $out = $this->service()->rewrite('<a href="' . self::OLD . '?token=%5BM2622%5D">x</a>', 1);

        self::assertSame('<a href="{asset:10018@1:url}">x</a>', $out);
    }

    public function testATokenWithNoAssetFallsBackToThePathWithoutAReport(): void
    {
        $svc = new CkeditorRewriterService();
        $svc->seedUrlIdCache([self::OLD => 10037]);
        $svc->seedKumaMediaIdCache([]);

        self::assertSame('<a href="{asset:10037@1:url}">x</a>', $svc->rewrite('<a href="' . self::OLD . '?token=[M2622]">x</a>', 1));
        self::assertSame([], $svc->consumeUnresolvedDiagnostics());
    }

    public function testATokenResolvesALinkWhosePathFindsNothing(): void
    {
        // A soft-deleted media's file was renamed on delete, so the path is gone; the token was
        // rewritten inside the kept url before, which left `href="…?token={asset:…}"`.
        $svc = new CkeditorRewriterService();
        $svc->seedUrlIdCache([]);
        $svc->seedMediaUrlToKumaMediaIdCache(['/uploads/media/5f1/renamed.pdf' => -1]);
        $svc->seedKumaMediaIdCache([2622 => 10018]);

        $out = $svc->rewrite('<a href="/uploads/media/5f1/renamed.pdf?token=[M2622]">x</a>', 1);

        self::assertSame('<a href="{asset:10018@1:url}">x</a>', $out);
        self::assertStringNotContainsString('MIGRATION:UNRESOLVED', $out);
    }

    public function testALinkFieldResolvesItsTokenBeforeItsPath(): void
    {
        self::assertSame(
            ['type' => 'asset', 'value' => '{asset:10018@6:url}', 'label' => 'Download'],
            $this->service()->rewriteLink(
                ['value' => self::OLD . '?token=[M2622]', 'type' => 'url', 'label' => 'Download'],
                6,
                ['entry', 'url', 'asset'],
            ),
        );
    }

    public function testAPlainPathLinkStillResolvesByPath(): void
    {
        // Without a token live serves the file at that path, old or not.
        self::assertSame('<a href="{asset:10037@1:url}">x</a>', $this->service()->rewrite('<a href="' . self::OLD . '">x</a>', 1));
    }
}
