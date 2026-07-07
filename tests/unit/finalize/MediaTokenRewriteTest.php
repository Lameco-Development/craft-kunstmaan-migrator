<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\finalize;

use lameco\kunstmaanmigrator\finalize\CkeditorRewriterService;
use PHPUnit\Framework\TestCase;

/**
 * Task 8 — the loader-contract `{{kuma:media:<id>}}` payload token
 * (docs/loader-contract.md), distinct from CkeditorRewriterServiceTest's
 * `[M<id>]`/`%5BM<id>%5D` legacy CKEditor-plugin placeholder coverage.
 *
 * Same seam as CkeditorRewriterServiceTest: `seedKumaMediaIdCache()` drives
 * `rewrite()` end-to-end without a Craft container.
 */
final class MediaTokenRewriteTest extends TestCase
{
    private function service(): CkeditorRewriterService
    {
        return new CkeditorRewriterService();
    }

    private function decodeMarkerSource(string $out): string
    {
        self::assertMatchesRegularExpression('/<!-- MIGRATION:UNRESOLVED sourceB64=([A-Za-z0-9_-]+) -->/', $out);
        preg_match('/<!-- MIGRATION:UNRESOLVED sourceB64=([A-Za-z0-9_-]+) -->/', $out, $matches);
        $encoded = $matches[1];
        $padded = str_pad(strtr($encoded, '-_', '+/'), strlen($encoded) + ((4 - strlen($encoded) % 4) % 4), '=', STR_PAD_RIGHT);

        return (string) base64_decode($padded, true);
    }

    public function testResolvableCurlyMediaTokenIsRewrittenToAnAssetRefToken(): void
    {
        $svc = $this->service();
        $svc->seedKumaMediaIdCache([123 => 501]);

        $out = $svc->rewrite('<p>Some body {{kuma:media:123}}</p>', 7);

        self::assertStringContainsString('{asset:501@7:url}', $out);
        self::assertStringNotContainsString('{{kuma:media:123}}', $out);
    }

    public function testUnresolvableCurlyMediaTokenIsLeftAsAnInertVisibleMarkerAndReported(): void
    {
        $svc = $this->service();
        // No seed, no assetResolver — genuinely unresolvable.

        $out = $svc->rewrite('<p>Some body {{kuma:media:999}}</p>', 7);

        // Inert visible marker: the original curly-brace token survives as
        // literal text (not a Craft ref-tag, so it can never be mistaken for
        // a resolved token) plus a comment marker for grep/tooling.
        self::assertStringContainsString('{{kuma:media:999}}', $out);
        self::assertStringContainsString('<!-- MIGRATION:UNRESOLVED', $out);
        self::assertSame('kuma_media:999', $this->decodeMarkerSource($out));

        $diagnostics = $svc->consumeUnresolvedDiagnostics();
        self::assertCount(1, $diagnostics);
        self::assertSame('media_token', $diagnostics[0]['tokenFamily']);
        self::assertSame(999, $diagnostics[0]['legacyId']);
        self::assertSame(7, $diagnostics[0]['siteId']);
        self::assertSame('kuma_media:999', $diagnostics[0]['source']);
        self::assertSame([], $svc->consumeUnresolvedDiagnostics(), 'Diagnostics are consumed/reset per field.');
    }

    public function testMultipleTokensInTheSameStringAreEachResolvedIndependently(): void
    {
        $svc = $this->service();
        $svc->seedKumaMediaIdCache([1 => 11, 2 => 22]);

        $out = $svc->rewrite('<p>{{kuma:media:1}} and {{kuma:media:2}}</p>', 3);

        self::assertStringContainsString('{asset:11@3:url}', $out);
        self::assertStringContainsString('{asset:22@3:url}', $out);
    }

    public function testStringWithoutAnyCurlyMediaTokenIsReturnedUntouchedBySkippingTheRegexCheapEarly(): void
    {
        $svc = $this->service();

        $html = '<p>Nothing to resolve here.</p>';
        self::assertSame($html, $svc->rewrite($html, 1));
        self::assertSame([], $svc->consumeUnresolvedDiagnostics());
    }
}
