<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\load;

use Lameco\Kunstmaanmigrator\db\LegacyDbService;
use Lameco\Kunstmaanmigrator\load\AssetMigrationService;
use Lameco\Kunstmaanmigrator\load\AssetResolver;
use Lameco\Kunstmaanmigrator\load\MigrationOptions;
use Lameco\Kunstmaanmigrator\load\SeomaticPayloadBuilder;
use Lameco\Kunstmaanmigrator\run\EnvironmentContext;
use Lameco\Kunstmaanmigrator\Source\LegacyDatabase;
use Lameco\Kunstmaanmigrator\tests\support\EnvironmentFactory;
use Lameco\Kunstmaanmigrator\tests\support\InMemoryElementWriter;
use Lameco\Kunstmaanmigrator\tests\support\InMemoryMigrationState;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * A page's og:image and Twitter image resolve the way every other media reference does.
 *
 * The SEO pass looked an image up only under `<ENV>:kuma_media:<id>`. Since the `legacy_url:`
 * keys were scoped by volume, most files are ingested under `legacy_url:<volume>:<sha1(url)>`
 * (Berkvens NL kuma_media 3769 is asset 1759 under that key only), and a file only `kuma_seo`
 * names was never ingested at all (NL 1616 `sfeerbeeld-berkvens-zwart.jpg`). No Berkvens NL page
 * got an og:image; 239 live ones have one. The pass now hands the builder the environment's
 * bound asset resolver: the id key, then the file's path key, then a just-in-time ingest.
 */
final class SeoImageResolutionTest extends TestCase
{
    private const OG_URL = '/uploads/media/5c34b5e3a0f7d/berkvens-deursystemen-referentie.jpg';
    private const SEO_ONLY_URL = '/uploads/media/5d0a1f3c8e2b4/sfeerbeeld-berkvens-zwart.jpg';
    private const DELETED_URL = '/uploads/media/5b7e2a9c1d3f0/kozijnen-technische-documentatie.jpg';

    private PDO $legacy;

    protected function setUp(): void
    {
        $this->legacy = new PDO('sqlite::memory:');
        $this->legacy->exec('CREATE TABLE kuma_media (id INTEGER, url TEXT, deleted INTEGER, content_type TEXT, location TEXT, folder_id INTEGER, created_at TEXT)');
        $this->legacy->exec("INSERT INTO kuma_media VALUES (3769, '" . self::OG_URL . "', 0, 'image/jpeg', 'local', 0, '2019-01-08 12:00:00')");
        $this->legacy->exec("INSERT INTO kuma_media VALUES (1616, '" . self::SEO_ONLY_URL . "', 0, 'image/jpeg', 'local', 0, '2019-06-19 12:00:00')");
        $this->legacy->exec("INSERT INTO kuma_media VALUES (382, '" . self::DELETED_URL . "', 1, 'image/jpeg', 'local', 0, '2018-03-02 12:00:00')");
    }

    private function env(): EnvironmentContext
    {
        return new EnvironmentContext(
            name: 'NL',
            database: 'legacy_nl',
            sites: EnvironmentFactory::sites(),
            legacy: new LegacyDatabase($this->legacy, 'NL', 'legacy_nl'),
        );
    }

    private function resolver(InMemoryMigrationState $state, MigrationOptions $opts): AssetResolver
    {
        $assets = new AssetMigrationService();
        $assets->targetVolume = 'berkvensNl';
        $assets->migrationState = $state;
        $assets->elementWriter = new InMemoryElementWriter();

        return $assets->resolverFor($this->env(), $opts);
    }

    public function testAnOgImageIngestedByItsPathResolves(): void
    {
        $state = new InMemoryMigrationState();
        $state->willResolve('media', 'legacy_url:berkvensNl:' . sha1(self::OG_URL), 1759, ['originalUrl' => self::OG_URL]);

        $builder = new SeomaticPayloadBuilder();
        $builder->migrationState = $state;
        $payload = $builder->build(
            ['meta_title' => 'T', 'og_image_id' => 3769],
            1,
            'NL',
            $this->resolver($state, new MigrationOptions(skipAssets: true)),
        );

        self::assertSame([1759], $payload['metaBundleSettings']['seoImageIds'] ?? null);
        self::assertSame('sameAsSeo', $payload['metaBundleSettings']['ogImageSource'] ?? null);
        self::assertSame('1759', $payload['metaGlobalVars']['ogImage']);
    }

    public function testATwitterImageIngestedByItsPathResolves(): void
    {
        $state = new InMemoryMigrationState();
        $state->willResolve('media', 'NL:kuma_media:1616', 1801);
        $state->willResolve('media', 'legacy_url:berkvensNl:' . sha1(self::OG_URL), 1759, ['originalUrl' => self::OG_URL]);

        $builder = new SeomaticPayloadBuilder();
        $payload = $builder->build(
            ['meta_title' => 'T', 'og_image_id' => 1616, 'twitter_image_id' => 3769],
            1,
            'NL',
            $this->resolver($state, new MigrationOptions(skipAssets: true)),
        );

        self::assertSame([1801], $payload['metaBundleSettings']['seoImageIds'] ?? null);
        self::assertSame([1759], $payload['metaBundleSettings']['twitterImageIds'] ?? null);
    }

    public function testAnImageNoPageUsesGoesToTheJustInTimeIngest(): void
    {
        // Nothing in state: the resolver reads the row and hands it to the ingest, which
        // `skipAssets` stops before any file is touched. The old lookup stopped at the state miss.
        $legacyDb = new SeoMediaRowDb(['id' => 1616, 'url' => self::SEO_ONLY_URL, 'content_type' => 'image/jpeg']);
        $assets = new AssetMigrationService();
        $assets->targetVolume = 'berkvensNl';
        $assets->migrationState = new InMemoryMigrationState();
        $assets->elementWriter = new InMemoryElementWriter();
        $assets->legacyDb = $legacyDb;

        $payload = (new SeomaticPayloadBuilder())->build(
            ['meta_title' => 'T', 'og_image_id' => 1616],
            1,
            'NL',
            $assets->resolverFor(EnvironmentFactory::make('NL'), new MigrationOptions(skipAssets: true)),
        );

        self::assertArrayNotHasKey('seoImageIds', $payload['metaBundleSettings']);
        self::assertSame([[':id' => 1616]], $legacyDb->params);
    }

    public function testAnImageThatFailsToIngestCostsTheImageAndNotTheRestOfTheSeo(): void
    {
        // The ingest can throw (a failed copy or save, the starter kit's size cap), and the pass
        // catches per entry: the title and description of every site of the entry went with it.
        $assets = new AssetMigrationService();
        $assets->migrationState = new InMemoryMigrationState();
        $assets->elementWriter = new InMemoryElementWriter();
        $assets->legacyDb = new SeoMediaRowDb(null);

        $payload = (new SeomaticPayloadBuilder())->build(
            ['meta_title' => 'Deuren', 'og_image_id' => 1616],
            1,
            'NL',
            $assets->resolverFor(EnvironmentFactory::make('NL'), new MigrationOptions()),
        );

        self::assertSame('Deuren', $payload['metaGlobalVars']['seoTitle']);
        self::assertArrayNotHasKey('seoImageIds', $payload['metaBundleSettings']);
    }

    public function testASoftDeletedImageTheProjectBroughtBackResolves(): void
    {
        // Deleted media migrate only by the project's own decision; an asset that exists is one
        // it made on purpose, under either key.
        $byPath = new InMemoryMigrationState();
        $byPath->willResolve('media', 'legacy_url:berkvensNl:' . sha1(self::DELETED_URL), 1770, ['originalUrl' => self::DELETED_URL]);
        $byId = new InMemoryMigrationState();
        $byId->willResolve('media', 'NL:kuma_media:382', 1771);

        foreach ([[$byPath, 1770], [$byId, 1771]] as [$state, $assetId]) {
            $payload = (new SeomaticPayloadBuilder())->build(
                ['meta_title' => 'T', 'og_image_id' => 382],
                1,
                'NL',
                $this->resolver($state, new MigrationOptions()),
            );

            self::assertSame([$assetId], $payload['metaBundleSettings']['seoImageIds'] ?? null);
        }
    }

    public function testASoftDeletedImageWithNoAssetIsNotIngested(): void
    {
        // The file is on disk, so an ingest would reach Craft's volumes, which this test has not
        // booted: a 0 without an error is the ingest that did not happen.
        $root = sys_get_temp_dir() . '/kmig-seo-deleted-' . uniqid();
        mkdir($root . '/5b7e2a9c1d3f0', 0777, true);
        file_put_contents($root . '/5b7e2a9c1d3f0/kozijnen-technische-documentatie.jpg', 'jpg-bytes');
        if (!class_exists(\Craft::class, false)) {
            require dirname(__DIR__, 3) . '/vendor/craftcms/cms/src/Craft.php';
        }

        try {
            $state = new InMemoryMigrationState();
            $assets = new AssetMigrationService();
            $assets->targetVolume = 'berkvensNl';
            $assets->migrationState = $state;
            $assets->elementWriter = new InMemoryElementWriter();
            $env = new EnvironmentContext(
                name: 'NL',
                database: 'legacy_nl',
                sites: EnvironmentFactory::sites(),
                mediaRoots: [$root],
                legacy: new LegacyDatabase($this->legacy, 'NL', 'legacy_nl'),
            );

            self::assertSame(0, $assets->resolveFromLegacyId(382, $env, new MigrationOptions(), ingestDeleted: false));
            self::assertSame([], $state->recorded);

            $payload = (new SeomaticPayloadBuilder())->build(
                ['meta_title' => 'T', 'og_image_id' => 382],
                1,
                'NL',
                $assets->resolverFor($env, new MigrationOptions()),
            );
            self::assertArrayNotHasKey('seoImageIds', $payload['metaBundleSettings']);
            self::assertSame('T', $payload['metaGlobalVars']['seoTitle']);
        } finally {
            @unlink($root . '/5b7e2a9c1d3f0/kozijnen-technische-documentatie.jpg');
            @rmdir($root . '/5b7e2a9c1d3f0');
            @rmdir($root);
        }
    }

    public function testWithoutAResolverTheStateLookupIsUnchanged(): void
    {
        $state = new InMemoryMigrationState();
        $state->willResolve('media', 'NL:kuma_media:3769', 900);

        $builder = new SeomaticPayloadBuilder();
        $builder->migrationState = $state;

        self::assertSame([900], $builder->build(['og_image_id' => 3769], 1, 'NL')['metaBundleSettings']['seoImageIds'] ?? null);
    }
}

/**
 * One kuma_media row, and the parameters every read asked for it with.
 *
 * @internal
 */
final class SeoMediaRowDb extends LegacyDbService
{
    /** @var list<array<string, mixed>> */
    public array $params = [];

    /** @param array<string, mixed>|null $row null: the read throws */
    public function __construct(private readonly ?array $row)
    {
        parent::__construct();
    }

    public function queryOne(string $sql, array $params = []): ?array
    {
        $this->params[] = $params;

        return $this->row ?? throw new \RuntimeException('legacy database is unreachable');
    }
}
