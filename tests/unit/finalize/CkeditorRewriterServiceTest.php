<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\finalize;

use lameco\kunstmaanmigrator\finalize\CkeditorRewriterService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Phase 5 / TST-01 / D-10 — direct unit tests for CkeditorRewriterService's
 * deterministic helpers (regex-driven token rewriters, kma-class stripping,
 * paragraph normalization).
 *
 * Strategy: the class exposes public seed-cache test seams
 * (seedUrlIdCache / seedKumaMediaIdCache / seedNtToEntryCache) which let us
 * drive the public `rewrite()` end-to-end without a Craft container, with the
 * caches pre-populated so no warming runs. Where a private helper isolates a
 * single transformation, Reflection is used directly (PATTERNS Shared Patterns
 * analog — tests/unit/load/AssetMigrationServiceRcaTest).
 *
 * The warming paths themselves are covered by CkeditorRewriterCacheWarmingTest,
 * which drives them through MigrationStateStream.
 */
final class CkeditorRewriterServiceTest extends TestCase
{
    private function service(): CkeditorRewriterService
    {
        // Construct without injecting LegacyDbService / MigrationStateService:
        // the cache-warmers guard on `class_exists(Craft, false)` and the
        // `migrationState !== null` check, so an unbootstrapped instance is
        // safe — every cache is warmed via the seed seams in tests below.
        return new CkeditorRewriterService();
    }

    private function callPrivate(CkeditorRewriterService $svc, string $method, mixed ...$args): mixed
    {
        $rm = new ReflectionMethod($svc, $method);
        return $rm->invoke($svc, ...$args);
    }

    private function decodeMarkerSource(string $out): string
    {
        self::assertMatchesRegularExpression('/<!-- MIGRATION:UNRESOLVED sourceB64=([A-Za-z0-9_-]+) -->/', $out);
        preg_match('/<!-- MIGRATION:UNRESOLVED sourceB64=([A-Za-z0-9_-]+) -->/', $out, $matches);
        $encoded = $matches[1];
        $padded = str_pad(strtr($encoded, '-_', '+/'), strlen($encoded) + ((4 - strlen($encoded) % 4) % 4), '=', STR_PAD_RIGHT);

        return (string) base64_decode($padded, true);
    }

    public function testEmptyHtmlReturnsEmptyString(): void
    {
        $svc = $this->service();
        self::assertSame('', $svc->rewrite(null, 1));
        self::assertSame('', $svc->rewrite('', 1));
    }

    public function testRewritesImgSrcWithLegacyMediaUrlToAssetRefToken(): void
    {
        $svc = $this->service();
        $svc->seedUrlIdCache(['/uploads/media/picture.jpg' => 42]);

        $html = '<img src="/uploads/media/picture.jpg" alt="x">';
        $out = $svc->rewrite($html, 7);

        self::assertStringContainsString('src="{asset:42@7:url}"', $out);
        self::assertStringNotContainsString('/uploads/media/picture.jpg', $out);
    }

    public function testRewritesAnchorHrefWithLegacyMediaUrlToAssetRefToken(): void
    {
        $svc = $this->service();
        $svc->seedUrlIdCache(['/uploads/media/doc.pdf' => 99]);

        $html = '<a href="/uploads/media/doc.pdf">Download</a>';
        $out = $svc->rewrite($html, 1);

        self::assertStringContainsString('href="{asset:99@1:url}"', $out);
    }

    public function testLegacyMediaUrlCanMaterialiseThroughKumaMediaLookupFallback(): void
    {
        $svc = $this->service();
        $svc->seedUrlIdCache([]);
        $svc->seedMediaUrlToKumaMediaIdCache(['/uploads/media/doc.pdf' => 493]);
        $svc->assetResolver = new class() {
            public function resolveFromLegacyId(int $kumaMediaId): int
            {
                return $kumaMediaId === 493 ? 9001 : 0;
            }
        };

        $out = $svc->rewrite('<a href="/uploads/media/doc.pdf?v=2">Download</a>', 1);

        self::assertStringContainsString('href="{asset:9001@1:url}"', $out);
        self::assertStringNotContainsString('/uploads/media/doc.pdf', $out);
        self::assertStringNotContainsString('MIGRATION:UNRESOLVED', $out);
    }

    public function testLegacyMediaUrlCanMaterialiseThroughRawUrlFallbackWhenNoKumaMediaRowExists(): void
    {
        $svc = $this->service();
        $svc->seedUrlIdCache([]);
        $svc->assetResolver = new class() {
            public function resolveFromLegacyUrl(string $legacyUrl): int
            {
                return $legacyUrl === '/uploads/media/orphan.png' ? 9002 : 0;
            }
        };

        $out = $svc->rewrite('<img src="/uploads/media/orphan.png?cache=1">', 1);

        self::assertStringContainsString('src="{asset:9002@1:url}"', $out);
        self::assertStringNotContainsString('MIGRATION:UNRESOLVED', $out);
    }

    public function testEmitsUnresolvedMarkerForMissingAssetUrl(): void
    {
        $svc = $this->service();
        // Cache warmed (seeded) but the URL is not present → unresolved marker.
        $svc->seedUrlIdCache([]);

        $html = '<img src="/uploads/media/missing.jpg">';
        $out = $svc->rewrite($html, 1);

        self::assertStringContainsString('/uploads/media/missing.jpg', $out);
        self::assertStringContainsString(
            '<!-- MIGRATION:UNRESOLVED sourceB64=L3VwbG9hZHMvbWVkaWEvbWlzc2luZy5qcGc -->',
            $out,
        );
        self::assertSame('/uploads/media/missing.jpg', $this->decodeMarkerSource($out));
        $diagnostics = $svc->consumeUnresolvedDiagnostics();
        self::assertCount(1, $diagnostics);
        self::assertSame('media_url', $diagnostics[0]['tokenFamily']);
        self::assertSame(0, $diagnostics[0]['legacyId']);
        self::assertSame('/uploads/media/missing.jpg', $diagnostics[0]['token']);
        self::assertSame('/uploads/media/missing.jpg', $diagnostics[0]['source']);
        self::assertSame(1, $diagnostics[0]['siteId']);
        self::assertSame('no matching Craft asset id for legacy media URL', $diagnostics[0]['reason']);
        self::assertSame([], $svc->consumeUnresolvedDiagnostics(), 'Diagnostics are consumed/reset per field.');
    }

    public function testUnresolvedMarkerEncodesMaliciousLegacyUrlCommentPayload(): void
    {
        $svc = $this->service();
        $svc->seedUrlIdCache([]);

        $url = '/uploads/media/missing--> <script data-x=&quot;quote&quot;>.jpg';
        $html = '<img src="' . $url . '" alt="x">';
        $out = $svc->rewrite($html, 1);

        self::assertStringContainsString('src="' . $url . '"', $out);
        self::assertStringContainsString('<!-- MIGRATION:UNRESOLVED sourceB64=', $out);
        self::assertSame($url, $this->decodeMarkerSource($out));

        preg_match('/<!-- MIGRATION:UNRESOLVED sourceB64=([A-Za-z0-9_-]+) -->/', $out, $matches);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $matches[1]);
        self::assertStringNotContainsString('-->', $matches[1]);
        self::assertStringNotContainsString('<script', $matches[1]);
        self::assertStringNotContainsString('&quot;', $matches[1]);

        $diagnostics = $svc->consumeUnresolvedDiagnostics();
        self::assertSame('media_url', $diagnostics[0]['tokenFamily']);
        self::assertStringNotContainsString('<script', $diagnostics[0]['token']);
        self::assertStringNotContainsString('-->', $diagnostics[0]['token']);
    }

    public function testRewritesKumaMediaPlaceholderToAssetRefToken(): void
    {
        $svc = $this->service();
        $svc->seedKumaMediaIdCache([482 => 1234]);

        $html = '<a href="[M482]">brochure</a>';
        $out = $svc->rewrite($html, 3);

        self::assertStringContainsString('{asset:1234@3:url}', $out);
        self::assertStringNotContainsString('[M482]', $out);
    }

    public function testKumaMediaPlaceholderUrlEncodedFormIsAlsoRewritten(): void
    {
        $svc = $this->service();
        $svc->seedKumaMediaIdCache([482 => 1234]);

        $html = '<a href="%5BM482%5D">x</a>';
        $out = $svc->rewrite($html, 3);

        self::assertStringContainsString('{asset:1234@3:url}', $out);
        self::assertStringNotContainsString('%5BM482%5D', $out);
        self::assertStringNotContainsString('MIGRATION:UNRESOLVED', $out);
    }

    public function testRawAndEncodedNodeTranslationPlaceholdersResolveWithoutLegacyLiterals(): void
    {
        $svc = $this->service();
        $svc->seedNtToEntryCache([80 => 555]);

        $out = $svc->rewrite('<a href="[NT80]">raw</a><a href="%5BNT80%5D">encoded</a>', 2);

        self::assertSame(2, substr_count($out, '{entry:555@2:url}'));
        self::assertStringNotContainsString('[NT80]', $out);
        self::assertStringNotContainsString('%5BNT80%5D', $out);
        self::assertStringNotContainsString('MIGRATION:UNRESOLVED', $out);
        self::assertSame([], $svc->consumeUnresolvedDiagnostics());
    }

    public function testRawAndEncodedMediaPlaceholdersResolveWithoutLegacyLiterals(): void
    {
        $svc = $this->service();
        $svc->seedKumaMediaIdCache([482 => 1234]);

        $out = $svc->rewrite('<a href="[M482]">raw</a><a href="%5BM482%5D">encoded</a>', 3);

        self::assertSame(2, substr_count($out, '{asset:1234@3:url}'));
        self::assertStringNotContainsString('[M482]', $out);
        self::assertStringNotContainsString('%5BM482%5D', $out);
        self::assertStringNotContainsString('MIGRATION:UNRESOLVED', $out);
        self::assertSame([], $svc->consumeUnresolvedDiagnostics());
    }

    public function testUnresolvedKumaMediaPlaceholderPreservesLiteralWithMarker(): void
    {
        $svc = $this->service();
        $svc->seedKumaMediaIdCache([]); // warmed, but [M999] missing

        $html = '<a href="[M999]">x</a>';
        $out = $svc->rewrite($html, 1);

        // Literal preserved (so REPORT.md grep can find it) + marker comment.
        self::assertStringContainsString('[M999]', $out);
        self::assertStringContainsString(
            '<!-- MIGRATION:UNRESOLVED sourceB64=a3VtYV9tZWRpYTo5OTk -->',
            $out,
        );
        self::assertSame('kuma_media:999', $this->decodeMarkerSource($out));
        $diagnostics = $svc->consumeUnresolvedDiagnostics();
        self::assertSame('media', $diagnostics[0]['tokenFamily']);
        self::assertSame(999, $diagnostics[0]['legacyId']);
        self::assertSame('[M999]', $diagnostics[0]['token']);
        self::assertSame(1, $diagnostics[0]['siteId']);
        self::assertArrayHasKey('reason', $diagnostics[0]);
    }

    public function testOutOfScopeKumaMediaPlaceholderDoesNotEmitBlockingMarker(): void
    {
        $svc = $this->service();
        $svc->seedKumaMediaIdCache([]);
        $svc->seedOutOfScopeMediaReasons([2805 => 'legacy media row is HTML']);

        $out = $svc->rewrite('<a href="%5BM2805%5D">legacy html</a>', 1);

        self::assertStringContainsString('%5BM2805%5D', $out);
        self::assertStringNotContainsString('MIGRATION:UNRESOLVED', $out);
        self::assertSame([], $svc->consumeUnresolvedDiagnostics());
        $diagnostics = $svc->consumeOutOfScopeDiagnostics();
        self::assertSame('media', $diagnostics[0]['tokenFamily']);
        self::assertSame(2805, $diagnostics[0]['legacyId']);
        self::assertSame('legacy media row is HTML', $diagnostics[0]['reason']);
    }

    public function testRewritesNodeTranslationPlaceholderToEntryRefToken(): void
    {
        $svc = $this->service();
        $svc->seedNtToEntryCache([80 => 555]);

        $html = '<a href="[NT80]">read more</a>';
        $out = $svc->rewrite($html, 2);

        self::assertStringContainsString('{entry:555@2:url}', $out);
        self::assertStringNotContainsString('[NT80]', $out);
    }

    public function testUnresolvedNodeTranslationPlaceholderPreservesLiteralWithMarker(): void
    {
        $svc = $this->service();
        $svc->seedNtToEntryCache([]);

        $html = '<a href="[NT404]">x</a>';
        $out = $svc->rewrite($html, 1);

        self::assertStringContainsString('[NT404]', $out);
        self::assertStringContainsString(
            '<!-- MIGRATION:UNRESOLVED sourceB64=a3VtYV9ub2RlX3RyYW5zbGF0aW9uOjQwNA -->',
            $out,
        );
        self::assertSame('kuma_node_translation:404', $this->decodeMarkerSource($out));
        $diagnostics = $svc->consumeUnresolvedDiagnostics();
        self::assertSame('nt', $diagnostics[0]['tokenFamily']);
        self::assertSame(404, $diagnostics[0]['legacyId']);
        self::assertSame('[NT404]', $diagnostics[0]['token']);
        self::assertSame(1, $diagnostics[0]['siteId']);
        self::assertArrayHasKey('reason', $diagnostics[0]);
        self::assertSame([], $svc->consumeUnresolvedDiagnostics(), 'Diagnostics are consumed/reset per field.');
    }

    public function testOutOfScopeNodeTranslationDoesNotEmitBlockingMarker(): void
    {
        $svc = $this->service();
        $svc->seedNtToEntryCache([]);
        $svc->seedOutOfScopeNtReasons([101 => 'legacy node translation is offline']);

        $out = $svc->rewrite('<a href="%5BNT101%5D">offline page</a>', 1);

        self::assertStringContainsString('%5BNT101%5D', $out);
        self::assertStringNotContainsString('MIGRATION:UNRESOLVED', $out);
        self::assertSame([], $svc->consumeUnresolvedDiagnostics());
        $diagnostics = $svc->consumeOutOfScopeDiagnostics();
        self::assertSame('nt', $diagnostics[0]['tokenFamily']);
        self::assertSame(101, $diagnostics[0]['legacyId']);
        self::assertSame('legacy node translation is offline', $diagnostics[0]['reason']);
    }

    public function testPureNtCacheBuilderMapsSourceKeyAndMetaKumaNodeId(): void
    {
        $svc = $this->service();
        $method = new ReflectionMethod(CkeditorRewriterService::class, 'buildNtToEntryCacheFromRows');
        $result = $method->invoke(null, [
            [
                'source' => 'App_Entity_GenericPage',
                'sourceKey' => '10',
                'targetType' => 'entry',
                'targetId' => 1000,
                'meta' => [],
            ],
            [
                'source' => 'App_Entity_MetaPage',
                'sourceKey' => '99',
                'targetType' => 'entry',
                'targetId' => 2000,
                'meta' => json_encode(['kumaNodeId' => 600], JSON_THROW_ON_ERROR),
            ],
        ], [
            ['ref_entity_name' => 'App\\Entity\\GenericPage', 'ref_id' => 10, 'node_id' => 500],
        ], [
            ['nt_id' => 80, 'node_id' => 500],
            ['nt_id' => 81, 'node_id' => 600],
        ]);

        self::assertSame([80 => 1000, 81 => 2000], $result);
    }

    public function testLegacyUrlFallbackIsReachedWhenAMediaIdMapsToNoAsset(): void
    {
        // The regression that made every image on a real corpus unresolvable. The fallback used
        // to run only when the legacy-database lookup failed outright, so finding a kuma_media id
        // and then failing to map it to an asset returned null and stopped. It is the normal
        // case, not the exception: the id→asset cache is warmed only from `kuma_media:` state
        // keys, and AssetMigrationService writes `legacy_url:sha1(path)` ones.
        $svc = $this->service();
        $svc->seedUrlIdCache([]);
        $svc->seedMediaUrlToKumaMediaIdCache(['/uploads/media/known.png' => 1384]);
        $svc->seedKumaMediaIdCache([]); // the id resolves to no asset
        $svc->assetResolver = new class() {
            // The id path genuinely fails — that is the case under test.
            public function resolveFromLegacyId(int $kumaMediaId): int
            {
                return 0;
            }

            public function resolveFromLegacyUrl(string $legacyUrl): int
            {
                return $legacyUrl === '/uploads/media/known.png' ? 7331 : 0;
            }
        };

        $out = $svc->rewrite('<img src="/uploads/media/known.png?token=%5BM1384%5D">', 3);

        self::assertStringContainsString('src="{asset:7331@3:url}"', $out);
        self::assertStringNotContainsString('MIGRATION:UNRESOLVED', $out);
    }

    public function testAMediaIdThatMapsNowhereAndHasNoUrlFallbackIsReportedNotDropped(): void
    {
        $svc = $this->service();
        $svc->seedUrlIdCache([]);
        $svc->seedMediaUrlToKumaMediaIdCache(['/uploads/media/gone.png' => 4242]);
        $svc->seedKumaMediaIdCache([]);

        $out = $svc->rewrite('<img src="/uploads/media/gone.png">', 1);

        self::assertStringContainsString('/uploads/media/gone.png', $out, 'the reference is preserved, never silently dropped');
        self::assertNotSame([], $svc->consumeUnresolvedDiagnostics());
    }

    public function testKumaClassesAreStripped(): void
    {
        $svc = $this->service();

        $out = $svc->rewrite('<p class="kma-lead kma-x">Body</p>', 1);

        self::assertStringNotContainsString('kma-', $out);
        self::assertStringContainsString('Body', $out);
    }

    public function testEmptyParagraphsAreRemoved(): void
    {
        $svc = $this->service();

        $out = $svc->rewrite('<p>Kept</p><p></p><p>&nbsp;</p>', 1);

        self::assertStringContainsString('Kept', $out);
        self::assertStringNotContainsString('<p></p>', $out);
    }

    public function testResetLookupCachesForgetsASeededMapping(): void
    {
        // The finalize pass repoints `legacyDb` per environment and resets between passes; a
        // cache surviving that would answer one environment's question with another's data.
        $svc = $this->service();
        $svc->seedUrlIdCache(['/uploads/media/a.png' => 11]);
        self::assertStringContainsString('{asset:11@1:url}', $svc->rewrite('<img src="/uploads/media/a.png">', 1));

        $svc->resetLookupCaches();
        $svc->seedUrlIdCache([]);

        self::assertStringNotContainsString('{asset:11@1:url}', $svc->rewrite('<img src="/uploads/media/a.png">', 1));
    }

    public function testConsumeDiagnosticsDrainsTheBuffer(): void
    {
        $svc = $this->service();
        $svc->seedUrlIdCache([]);
        $svc->rewrite('<img src="/uploads/media/missing.png">', 1);

        self::assertNotSame([], $svc->consumeUnresolvedDiagnostics());
        self::assertSame([], $svc->consumeUnresolvedDiagnostics(), 'a second consume returns nothing');
    }

    public function testRewritesInternalEntryLinksWhenMapProvided(): void
    {
        $svc = $this->service();
        $html = '<a href="/about-us">About</a>';
        $out = $svc->rewrite($html, 4, ['/about-us' => 12]);

        self::assertStringContainsString('href="{entry:12@4:url}"', $out);
    }

    public function testEntryLinkRewriteSkipsLegacyMediaUrls(): void
    {
        $svc = $this->service();
        $svc->seedUrlIdCache(['/uploads/media/file.pdf' => 7]);
        $html = '<a href="/uploads/media/file.pdf">x</a>';
        $out = $svc->rewrite($html, 1, ['/uploads/media/file.pdf' => 99]);

        // Asset rewrite must win — entry-link rewrite skips media URLs.
        self::assertStringContainsString('{asset:7@1:url}', $out);
        self::assertStringNotContainsString('{entry:99', $out);
    }

    public function testStripsKumaClassesPreservingOtherClasses(): void
    {
        $svc = $this->service();
        $html = '<p class="kma-callout important">Hi</p>';
        $out = $svc->rewrite($html, 1);

        self::assertStringContainsString('class="important"', $out);
        self::assertStringNotContainsString('kma-callout', $out);
    }

    public function testDropsClassAttributeEntirelyWhenAllClassesAreKumaPrefixed(): void
    {
        $svc = $this->service();
        $html = '<div class="kma-foo kma-bar">content</div>';
        $out = $svc->rewrite($html, 1);

        self::assertStringNotContainsString('class=', $out);
        self::assertStringNotContainsString('kma-', $out);
    }

    public function testRemovesEmptyParagraphs(): void
    {
        $svc = $this->service();
        $html = '<p></p><p>kept</p><p>&nbsp;</p><p> </p><p><br></p><p><br/></p>';
        $out = $svc->rewrite($html, 1);

        self::assertStringContainsString('<p>kept</p>', $out);
        self::assertStringNotContainsString('<p></p>', $out);
        self::assertStringNotContainsString('<p>&nbsp;</p>', $out);
        self::assertStringNotContainsString('<p><br>', $out);
    }

    public function testPassThroughBodyWithNoTokensOrClassesOrEmptyParagraphs(): void
    {
        $svc = $this->service();
        $html = '<p>plain content with <strong>emphasis</strong></p>';
        $out = $svc->rewrite($html, 1);

        self::assertSame($html, $out);
    }

    public function testIdempotenceRewriteIsStableAcrossMultiplePasses(): void
    {
        $svc = $this->service();
        $svc->seedKumaMediaIdCache([1 => 100]);
        $svc->seedNtToEntryCache([2 => 200]);

        $html = '<a href="[M1]">a</a><a href="[NT2]">b</a><p class="kma-x">x</p><p></p>';
        $first = $svc->rewrite($html, 1);
        $second = $svc->rewrite($first, 1);

        self::assertSame($first, $second);
    }

    public function testCoexistingNtAndMediaPlaceholdersBothResolveIndependently(): void
    {
        $svc = $this->service();
        $svc->seedKumaMediaIdCache([5 => 105]);
        $svc->seedNtToEntryCache([9 => 209]);

        $html = '<p>see <a href="[NT9]">page</a> and <a href="[M5]">file</a></p>';
        $out = $svc->rewrite($html, 1);

        self::assertStringContainsString('{entry:209@1:url}', $out);
        self::assertStringContainsString('{asset:105@1:url}', $out);
    }

    public function testResetLookupCachesClearsStaleSeededMissesBeforeFinalizeWarmup(): void
    {
        $svc = $this->service();
        $svc->seedNtToEntryCache([9 => 209]);

        self::assertStringContainsString('{entry:209@1:url}', $svc->rewrite('<a href="[NT9]">page</a>', 1));

        $svc->resetLookupCaches();
        $out = $svc->rewrite('<a href="[NT9]">page</a>', 1);

        self::assertStringContainsString('[NT9]', $out);
        self::assertStringContainsString('MIGRATION:UNRESOLVED', $out);
    }

    public function testResetLookupCachesClearsLegacyMediaUrlLookupFallbackCache(): void
    {
        $svc = $this->service();
        $svc->seedUrlIdCache([]);
        $svc->seedMediaUrlToKumaMediaIdCache(['/uploads/media/doc.pdf' => 493]);
        $svc->assetResolver = new class() {
            public function resolveFromLegacyId(int $kumaMediaId): int
            {
                return $kumaMediaId === 493 ? 9001 : 0;
            }
        };

        self::assertStringContainsString(
            '{asset:9001@1:url}',
            $svc->rewrite('<a href="/uploads/media/doc.pdf">Download</a>', 1),
        );

        $svc->resetLookupCaches();
        $out = $svc->rewrite('<a href="/uploads/media/doc.pdf">Download</a>', 1);

        self::assertStringContainsString('/uploads/media/doc.pdf', $out);
        self::assertStringContainsString('MIGRATION:UNRESOLVED', $out);
    }

    public function testRewriteStripsStaleUnresolvedMarkersBeforeReclassifying(): void
    {
        $svc = $this->service();
        $svc->seedNtToEntryCache([]);
        $svc->seedOutOfScopeNtReasons([101 => 'legacy node translation is offline']);

        $out = $svc->rewrite(
            '<a href="%5BNT101%5D">offline page</a><!-- MIGRATION:UNRESOLVED sourceB64=a3VtYV9ub2RlX3RyYW5zbGF0aW9uOjEwMQ -->',
            1,
        );

        self::assertStringContainsString('%5BNT101%5D', $out);
        self::assertStringNotContainsString('MIGRATION:UNRESOLVED', $out);
    }

    public function testStrippedQueryAndFragmentFallbackResolvesAssetUrl(): void
    {
        $svc = $this->service();
        // Exact match misses; stripped (no query/fragment) matches.
        $svc->seedUrlIdCache(['/uploads/media/file.pdf' => 11]);

        $html = '<a href="/uploads/media/file.pdf?v=2#anchor">x</a>';
        $out = $svc->rewrite($html, 1);

        self::assertStringContainsString('{asset:11@1:url}', $out);
    }

    public function testAssetResolverFallbackMaterialisesOnCacheMiss(): void
    {
        // Cache miss + assetResolver wired → resolver produces an id, which is
        // written back to the per-request cache.
        $svc = $this->service();
        $svc->seedKumaMediaIdCache([]); // warm but empty
        $svc->assetResolver = new class() {
            public function resolveFromLegacyId(int $kumaMediaId): int
            {
                return $kumaMediaId === 7 ? 700 : 0;
            }
        };

        $html = '<a href="[M7]">x</a>';
        $out = $svc->rewrite($html, 1);

        self::assertStringContainsString('{asset:700@1:url}', $out);
    }

    public function testAssetResolverReturningZeroLeavesPlaceholderUnresolved(): void
    {
        $svc = $this->service();
        $svc->seedKumaMediaIdCache([]);
        $svc->assetResolver = new class() {
            public function resolveFromLegacyId(int $kumaMediaId): int
            {
                return 0;
            }
        };

        $html = '<a href="[M88]">x</a>';
        $out = $svc->rewrite($html, 1);

        self::assertStringContainsString('[M88]', $out);
        self::assertStringContainsString('MIGRATION:UNRESOLVED sourceB64=a3VtYV9tZWRpYTo4OA', $out);
        self::assertSame('kuma_media:88', $this->decodeMarkerSource($out));
    }

    public function testWithoutKumaPlaceholdersTheRewriteIsAFastNoOp(): void
    {
        // Helper internals: rewriteMediaPlaceholders / rewriteNodeTranslationPlaceholders
        // both short-circuit when no `[M` / `[NT` substring is present. Cover the
        // fast-path branch by passing HTML with no placeholders at all.
        $svc = $this->service();
        $html = '<p>nothing to do</p>';
        $out = $svc->rewrite($html, 1);

        self::assertSame($html, $out);
    }

    public function testStripKumaClassesOnHtmlWithoutClassAttributesIsNoOp(): void
    {
        $svc = $this->service();
        $out = $this->callPrivate($svc, 'stripKumaClasses', '<p>no classes</p>');
        self::assertSame('<p>no classes</p>', $out);
    }

    public function testRemoveEmptyParagraphsLeavesNonEmptyParagraphsUntouched(): void
    {
        $svc = $this->service();
        $out = $this->callPrivate($svc, 'removeEmptyParagraphs', '<p>real content</p>');
        self::assertSame('<p>real content</p>', $out);
    }
}
