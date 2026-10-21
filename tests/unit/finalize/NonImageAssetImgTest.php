<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\finalize;

use Lameco\Kunstmaanmigrator\finalize\CkeditorRewriterService;
use Lameco\Kunstmaanmigrator\load\AssetMigrationService;
use Lameco\Kunstmaanmigrator\load\MigrationOptions;
use Lameco\Kunstmaanmigrator\tests\support\EnvironmentFactory;
use Lameco\Kunstmaanmigrator\tests\support\InMemoryElementWriter;
use PHPUnit\Framework\TestCase;

/**
 * An `<img>` is only written for an asset Craft treats as an image.
 *
 * Four Berkvens NL news Text blocks end with `<img src="[M3416]">`. Media 3416 is a Vimeo video:
 * it migrates as an embedded-video asset (`Elektrisch-transport_final.json`, kind `json`), so the
 * rewritten `<img src="{asset:8642@1:url}">` rendered a broken image pointing at a .json file. A
 * remote video has no url, so live printed `<img src="">`: no picture. The tag is dropped, and
 * the paragraph it was alone in goes with it.
 */
final class NonImageAssetImgTest extends TestCase
{
    /** @param array<int, string> $kinds asset id => kind */
    private function service(array $kinds): CkeditorRewriterService
    {
        $svc = new CkeditorRewriterService();
        $svc->seedUrlIdCache(['/uploads/media/5a1/berkvens-type-eproc.jpg' => 501, '/uploads/media/5a2/brochure.pdf' => 502]);
        $svc->seedKumaMediaIdCache([3416 => 8642, 3679 => 501]);
        $svc->assetResolver = new class($kinds) {
            /** @var list<int> */
            public array $asked = [];

            /** @param array<int, string> $kinds */
            public function __construct(private readonly array $kinds)
            {
            }

            public function resolveFromLegacyId(int $kumaMediaId): int
            {
                return 0;
            }

            public function assetKindOf(int $assetId): ?string
            {
                $this->asked[] = $assetId;

                return $this->kinds[$assetId] ?? null;
            }
        };

        return $svc;
    }

    public function testAVideoInAnImgTagIsDroppedWithItsParagraph(): void
    {
        $out = $this->service([8642 => 'json'])->rewrite('<p>Pilot elektrisch transport.</p><p><img src="[M3416]"></p>', 1);

        self::assertSame('<p>Pilot elektrisch transport.</p>', $out);
    }

    public function testAVideoInAnImgTagBesideTextLeavesTheText(): void
    {
        $out = $this->service([8642 => 'json'])->rewrite('<p>Kijk <img alt="" src="[M3416]"> hier.</p>', 1);

        self::assertSame('<p>Kijk  hier.</p>', $out);
    }

    public function testAnImageStaysAnImg(): void
    {
        $out = $this->service([501 => 'image'])->rewrite('<p><img alt="" src="[M3679]"></p>', 1);

        self::assertSame('<p><img alt="" src="{asset:501@1:url}"></p>', $out);
    }

    public function testAPathFormImgOfAFileThatIsNotAnImageIsDroppedToo(): void
    {
        $out = $this->service([502 => 'pdf'])->rewrite('<p>a</p><p><img src="/uploads/media/5a2/brochure.pdf"></p>', 1);

        self::assertSame('<p>a</p>', $out);
    }

    public function testALinkToANonImageAssetIsKept(): void
    {
        $out = $this->service([8642 => 'json', 502 => 'pdf'])->rewrite('<a href="[M3416]">video</a> <a href="/uploads/media/5a2/brochure.pdf">pdf</a>', 1);

        self::assertSame('<a href="{asset:8642@1:url}">video</a> <a href="{asset:502@1:url}">pdf</a>', $out);
    }

    public function testAnAssetOfUnknownKindIsKept(): void
    {
        $out = $this->service([])->rewrite('<p><img src="[M3416]"></p>', 1);

        self::assertSame('<p><img src="{asset:8642@1:url}"></p>', $out);
    }

    public function testTheKindOfOneAssetIsAskedOnce(): void
    {
        $svc = $this->service([8642 => 'json']);
        $svc->rewrite('<img src="[M3416]"><img src="[M3416]">', 1);
        $svc->rewrite('<img src="[M3416]">', 1);

        self::assertSame([8642], $svc->assetResolver->asked);
    }

    public function testTheBoundResolverAsksTheWriterForTheKind(): void
    {
        $writer = new InMemoryElementWriter();
        $writer->willHoldAsset(8642, 'berkvensNl', 'json');
        $assets = new AssetMigrationService();
        $assets->elementWriter = $writer;
        $resolver = $assets->resolverFor(EnvironmentFactory::make('NL'), new MigrationOptions());

        self::assertSame('json', $resolver->assetKindOf(8642));
        self::assertNull($resolver->assetKindOf(9999));
    }
}
