<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\finalize;

use Generator;
use Lameco\Kunstmaanmigrator\finalize\CkeditorRewriterService;
use Lameco\Kunstmaanmigrator\load\MigrationStateStream;
use Lameco\Kunstmaanmigrator\tests\support\FakeLegacyDb;
use Lameco\Kunstmaanmigrator\tests\support\ThrowingLegacyDb;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The three cache-warming paths, driven through MigrationStateStream.
 *
 * These were unreachable from a unit test while the property was typed to the
 * concrete MigrationStateService: every warm method opened with a
 * `class_exists(Craft::class, false)` guard and short-circuited, which cost
 * the module its coverage gate. Typing the property to MigrationStateStream
 * makes a fake sufficient, so the warming logic is now asserted on rather
 * than skipped.
 */
final class CkeditorRewriterCacheWarmingTest extends TestCase
{
    private function callPrivate(CkeditorRewriterService $svc, string $method, mixed ...$args): mixed
    {
        return (new ReflectionMethod($svc, $method))->invoke($svc, ...$args);
    }

    public function testKumaMediaCacheWarmsFromStateRows(): void
    {
        $svc = new CkeditorRewriterService();
        $svc->assetResolver = new FakeEnvResolver('COM');
        $svc->migrationState = new FakeStateStream(media: [
            ['sourceKey' => 'COM:kuma_media:123', 'targetType' => 'asset', 'targetId' => 55],
            ['sourceKey' => 'COM:kuma_media:124', 'targetType' => 'asset', 'targetId' => 56],
        ]);

        self::assertSame(55, $this->callPrivate($svc, 'resolveKumaMediaId', 123));
        self::assertSame(56, $this->callPrivate($svc, 'resolveKumaMediaId', 124));
    }

    public function testKumaMediaWarmIgnoresAnotherEnvironmentsRows(): void
    {
        // This cache is keyed by the bare legacy id, which is only unique within
        // one environment. Warming it from every row let DE's media 123 answer a
        // COM lookup — the same collision the scoped state key exists to stop.
        $svc = new CkeditorRewriterService();
        $svc->assetResolver = new FakeEnvResolver('COM');
        $svc->migrationState = new FakeStateStream(media: [
            ['sourceKey' => 'DE:kuma_media:123', 'targetType' => 'asset', 'targetId' => 55],
            ['sourceKey' => 'kuma_media:124', 'targetType' => 'asset', 'targetId' => 56],
        ]);

        self::assertNull($this->callPrivate($svc, 'resolveKumaMediaId', 123), 'DE row must not answer for COM');
        self::assertNull($this->callPrivate($svc, 'resolveKumaMediaId', 124), 'a pre-scoping bare row is nobodys');
    }

    public function testKumaMediaWarmStaysColdWithoutAnEnvironment(): void
    {
        // No resolver means no environment to scope against. Adopting every row
        // would be the pre-fix behaviour, so the cache stays empty instead.
        $svc = new CkeditorRewriterService();
        $svc->migrationState = new FakeStateStream(media: [
            ['sourceKey' => 'COM:kuma_media:123', 'targetType' => 'asset', 'targetId' => 55],
        ]);

        self::assertNull($this->callPrivate($svc, 'resolveKumaMediaId', 123));
    }

    public function testKumaMediaWarmSkipsRowsThatAreNotResolvedAssets(): void
    {
        $svc = new CkeditorRewriterService();
        $svc->assetResolver = new FakeEnvResolver('COM');
        $svc->migrationState = new FakeStateStream(media: [
            ['sourceKey' => 'COM:kuma_media:1', 'targetType' => 'entry', 'targetId' => 9],
            ['sourceKey' => 'COM:kuma_media:2', 'targetType' => 'asset', 'targetId' => null],
            ['sourceKey' => 'something_else:3', 'targetType' => 'asset', 'targetId' => 7],
        ]);

        self::assertNull($this->callPrivate($svc, 'resolveKumaMediaId', 1));
        self::assertNull($this->callPrivate($svc, 'resolveKumaMediaId', 2));
        self::assertNull($this->callPrivate($svc, 'resolveKumaMediaId', 3));
    }

    public function testKumaMediaCacheIsWarmedOnce(): void
    {
        $stream = new FakeStateStream(media: [
            ['sourceKey' => 'COM:kuma_media:5', 'targetType' => 'asset', 'targetId' => 42],
        ]);
        $svc = new CkeditorRewriterService();
        $svc->assetResolver = new FakeEnvResolver('COM');
        $svc->migrationState = $stream;

        $this->callPrivate($svc, 'resolveKumaMediaId', 5);
        $this->callPrivate($svc, 'resolveKumaMediaId', 5);
        $this->callPrivate($svc, 'resolveKumaMediaId', 6);

        self::assertSame(1, $stream->allCalls, 'the state table must be scanned once per request');
    }

    public function testUrlCacheWarmsFromStateMetaOriginalUrl(): void
    {
        $svc = new CkeditorRewriterService();
        $svc->assetResolver = new FakeEnvResolver('COM');
        $svc->migrationState = new FakeStateStream(media: [
            [
                'sourceKey' => 'COM:kuma_media:71',
                'targetType' => 'asset',
                'targetId' => 71,
                'meta' => ['originalUrl' => 'uploads/media/report.pdf'],
            ],
        ]);

        self::assertSame(71, $this->callPrivate($svc, 'resolveMediaIdForUrl', '/uploads/media/report.pdf'));
    }

    public function testUrlCacheAcceptsMetaStoredAsJsonAndIgnoresRowsWithoutAnOriginalUrl(): void
    {
        $svc = new CkeditorRewriterService();
        $svc->assetResolver = new FakeEnvResolver('COM');
        $svc->migrationState = new FakeStateStream(media: [
            [
                'sourceKey' => 'legacy_url:uploads:' . sha1('/uploads/media/photo.png'),
                'targetType' => 'asset',
                'targetId' => 88,
                'meta' => json_encode(['originalUrl' => '/uploads/media/photo.png']),
            ],
            ['sourceKey' => 'COM:kuma_media:89', 'targetType' => 'asset', 'targetId' => 89, 'meta' => ['size' => 12]],
            ['sourceKey' => 'COM:kuma_media:90', 'targetType' => 'asset', 'targetId' => 90, 'meta' => null],
        ]);

        self::assertSame(88, $this->callPrivate($svc, 'resolveMediaIdForUrl', '/uploads/media/photo.png'));
        self::assertNull($this->callPrivate($svc, 'resolveMediaIdForUrl', '/uploads/media/absent.png'));
    }

    public function testUrlCacheWarmsOnlyFromThisEnvironmentsAndThisVolumesRows(): void
    {
        // The cache is keyed by URL, and Berkvens NL and FR share `/uploads/media/<hash>/…`
        // paths across two volumes. Warmed from every row, NL's assets answered FR's rich text
        // and Link fields. What it cannot vouch for is left to resolveFromLegacyUrl(), which
        // checks the volume.
        $own = '/uploads/media/a/own.jpg';
        $svc = new CkeditorRewriterService();
        $svc->assetResolver = new FakeEnvResolver('FR', 'berkvensFr');
        $svc->migrationState = new FakeStateStream(media: [
            ['sourceKey' => 'FR:kuma_media:870', 'targetType' => 'asset', 'targetId' => 10, 'meta' => ['originalUrl' => $own]],
            ['sourceKey' => 'legacy_url:berkvensFr:' . sha1('/uploads/media/a/scoped.jpg'), 'targetType' => 'asset', 'targetId' => 11, 'meta' => ['originalUrl' => '/uploads/media/a/scoped.jpg']],
            ['sourceKey' => 'NL:kuma_media:870', 'targetType' => 'asset', 'targetId' => 20, 'meta' => ['originalUrl' => '/uploads/media/a/nl-id.jpg']],
            ['sourceKey' => 'legacy_url:berkvensNl:' . sha1('/uploads/media/a/nl.jpg'), 'targetType' => 'asset', 'targetId' => 21, 'meta' => ['originalUrl' => '/uploads/media/a/nl.jpg']],
            ['sourceKey' => 'legacy_url:' . sha1('/uploads/media/a/unscoped.jpg'), 'targetType' => 'asset', 'targetId' => 22, 'meta' => ['originalUrl' => '/uploads/media/a/unscoped.jpg']],
            ['sourceKey' => 'legacy_file:' . sha1('/srv/nl/uploads/documents/montage.pdf'), 'targetType' => 'asset', 'targetId' => 23, 'meta' => ['originalUrl' => '/uploads/documents/montage.pdf']],
            ['sourceKey' => 'kuma_media:870', 'targetType' => 'asset', 'targetId' => 24, 'meta' => ['originalUrl' => '/uploads/media/a/bare.jpg']],
        ]);

        self::assertSame(10, $this->callPrivate($svc, 'resolveMediaIdForUrl', $own));
        self::assertSame(11, $this->callPrivate($svc, 'resolveMediaIdForUrl', '/uploads/media/a/scoped.jpg'));
        self::assertNull($this->callPrivate($svc, 'resolveMediaIdForUrl', '/uploads/media/a/nl-id.jpg'), 'another environment');
        self::assertNull($this->callPrivate($svc, 'resolveMediaIdForUrl', '/uploads/media/a/nl.jpg'), 'another volume');
        self::assertNull($this->callPrivate($svc, 'resolveMediaIdForUrl', '/uploads/media/a/unscoped.jpg'), 'a pre-scoping row names no volume');
        self::assertNull($this->callPrivate($svc, 'resolveMediaIdForUrl', '/uploads/documents/montage.pdf'), 'a file key names a checkout, not a URL');
        self::assertNull($this->callPrivate($svc, 'resolveMediaIdForUrl', '/uploads/media/a/bare.jpg'));
    }

    public function testUrlCacheStaysColdWithoutAnEnvironment(): void
    {
        $svc = new CkeditorRewriterService();
        $svc->migrationState = new FakeStateStream(media: [
            ['sourceKey' => 'COM:kuma_media:1', 'targetType' => 'asset', 'targetId' => 10, 'meta' => ['originalUrl' => '/uploads/media/x.jpg']],
        ]);

        self::assertNull($this->callPrivate($svc, 'resolveMediaIdForUrl', '/uploads/media/x.jpg'));
    }

    public function testNtCacheWarmsFromStateRowsJoinedToLegacyNodeTranslations(): void
    {
        $svc = new CkeditorRewriterService();
        $svc->migrationState = new FakeStateStream(entries: [
            ['source' => 'App_Entity_NewsPage', 'targetType' => 'entry', 'targetId' => 900, 'sourceKey' => 12],
            ['source' => 'App_Entity_TeamPage', 'targetType' => 'entry', 'targetId' => 901, 'sourceKey' => 0, 'meta' => ['kumaNodeId' => 44]],
        ]);
        $svc->legacyDb = new FakeLegacyDb([
            // ref_id 12 of NewsPage is kuma_nodes.id 33
            [['node_id' => 33, 'ref_id' => 12, 'ref_entity_name' => 'App\\Entity\\NewsPage']],
            // node 33 is NT 80; node 44 is NT 81
            [['nt_id' => 80, 'node_id' => 33], ['nt_id' => 81, 'node_id' => 44]],
        ]);

        self::assertSame(900, $this->callPrivate($svc, 'resolveNodeTranslationId', 80));
        self::assertSame(901, $this->callPrivate($svc, 'resolveNodeTranslationId', 81));
        self::assertNull($this->callPrivate($svc, 'resolveNodeTranslationId', 82));
    }

    /**
     * `seo_meta` rows are entry rows keyed `<entryId>:<siteId>`. Read as an int,
     * `'1204:2'` is node 1204, so when it came after node 1204's own row it won
     * and `[NT1206]` (producten/verdi) linked to entry 1204 (a dealer page).
     * Row order must not matter: the node's own row is the only authority.
     *
     * @return iterable<string, array{bool}>
     */
    public static function rowOrders(): iterable
    {
        yield 'node row first' => [false];
        yield 'node row last' => [true];
    }

    #[DataProvider('rowOrders')]
    public function testNtWarmIgnoresSeoMetaCompositeKeysThatLookLikeNodeIds(bool $nodeRowLast): void
    {
        $rows = [
            ['source' => 'NL:kuma_nodes', 'targetType' => 'entry', 'targetId' => 5000, 'sourceKey' => '1204'],
            ['source' => 'seo_meta', 'targetType' => 'entry', 'targetId' => 1204, 'sourceKey' => '1204:2'],
        ];
        $svc = new CkeditorRewriterService();
        $svc->assetResolver = new FakeEnvResolver('NL');
        $svc->migrationState = new FakeStateStream(entries: $nodeRowLast ? array_reverse($rows) : $rows);
        $svc->legacyDb = new FakeLegacyDb([
            [],
            [['nt_id' => 1206, 'node_id' => 1204]],
        ]);

        self::assertSame(5000, $this->callPrivate($svc, 'resolveNodeTranslationId', 1206));
    }

    public function testNtWarmIgnoresAnotherEnvironmentsNodeRowsAndAliasRows(): void
    {
        // Node ids restart per legacy database, and an alias row is keyed by its
        // own table's id. Neither is this environment's node.
        $svc = new CkeditorRewriterService();
        $svc->assetResolver = new FakeEnvResolver('NL');
        $svc->migrationState = new FakeStateStream(entries: [
            ['source' => 'NL:kuma_nodes', 'targetType' => 'entry', 'targetId' => 5000, 'sourceKey' => '1204'],
            ['source' => 'FR:kuma_nodes', 'targetType' => 'entry', 'targetId' => 7000, 'sourceKey' => '1204'],
            ['source' => 'NL:app_product_pages', 'targetType' => 'entry', 'targetId' => 7001, 'sourceKey' => '1204'],
            ['source' => 'FR:kuma_nodes', 'targetType' => 'entry', 'targetId' => 7002, 'sourceKey' => '1300'],
            ['source' => 'NL:app_product_pages', 'targetType' => 'entry', 'targetId' => 7003, 'sourceKey' => '1301'],
        ]);
        $svc->legacyDb = new FakeLegacyDb([
            [],
            [['nt_id' => 1206, 'node_id' => 1204], ['nt_id' => 1302, 'node_id' => 1300], ['nt_id' => 1303, 'node_id' => 1301]],
        ]);

        self::assertSame(5000, $this->callPrivate($svc, 'resolveNodeTranslationId', 1206));
        self::assertNull($this->callPrivate($svc, 'resolveNodeTranslationId', 1302), 'FR node 1300 is not NL node 1300');
        self::assertNull($this->callPrivate($svc, 'resolveNodeTranslationId', 1303), 'an alias key is not a node id');
    }

    public function testNtWarmPrefersTheNodesOwnRowOverLegacyShapedRows(): void
    {
        // A legacy-shaped row bridged to node 1204, and another environment's
        // meta claiming it, both after the node's own row: the node row wins
        // and the other environment's meta is never read.
        $svc = new CkeditorRewriterService();
        $svc->assetResolver = new FakeEnvResolver('NL');
        $svc->migrationState = new FakeStateStream(entries: [
            ['source' => 'NL:kuma_nodes', 'targetType' => 'entry', 'targetId' => 5000, 'sourceKey' => '1204'],
            ['source' => 'App_Entity_ProductPage', 'targetType' => 'entry', 'targetId' => 6000, 'sourceKey' => '12'],
            ['source' => 'FR:kuma_nodes', 'targetType' => 'entry', 'targetId' => 7000, 'sourceKey' => '9', 'meta' => ['kumaNodeId' => 1300]],
        ]);
        $svc->legacyDb = new FakeLegacyDb([
            [['node_id' => 1204, 'ref_id' => 12, 'ref_entity_name' => 'App\\Entity\\ProductPage']],
            [['nt_id' => 1206, 'node_id' => 1204], ['nt_id' => 1302, 'node_id' => 1300]],
        ]);

        self::assertSame(5000, $this->callPrivate($svc, 'resolveNodeTranslationId', 1206));
        self::assertNull($this->callPrivate($svc, 'resolveNodeTranslationId', 1302));
    }

    public function testNtWarmWithoutAnEnvironmentTrustsNoEnvironmentScopedRow(): void
    {
        $svc = new CkeditorRewriterService();
        $svc->migrationState = new FakeStateStream(entries: [
            ['source' => 'NL:kuma_nodes', 'targetType' => 'entry', 'targetId' => 5000, 'sourceKey' => '1204'],
        ]);
        $svc->legacyDb = new FakeLegacyDb([
            [],
            [['nt_id' => 1206, 'node_id' => 1204]],
        ]);

        self::assertNull($this->callPrivate($svc, 'resolveNodeTranslationId', 1206));
    }

    public function testNtWarmSurvivesALegacyDbThatThrows(): void
    {
        $svc = new CkeditorRewriterService();
        $svc->migrationState = new FakeStateStream();
        $svc->legacyDb = new ThrowingLegacyDb();

        self::assertNull($this->callPrivate($svc, 'resolveNodeTranslationId', 80));
    }

    public function testWarmingIsANoOpWhenNothingIsWired(): void
    {
        $svc = new CkeditorRewriterService();

        self::assertNull($this->callPrivate($svc, 'resolveKumaMediaId', 1));
        self::assertNull($this->callPrivate($svc, 'resolveMediaIdForUrl', '/uploads/media/x.png'));
        self::assertNull($this->callPrivate($svc, 'resolveNodeTranslationId', 1));
    }

    public function testNtWarmIsANoOpWithAStateStreamButNoLegacyDb(): void
    {
        $svc = new CkeditorRewriterService();
        $svc->migrationState = new FakeStateStream();

        self::assertNull($this->callPrivate($svc, 'resolveNodeTranslationId', 80));
    }

    public function testStateEntryRowsComesFromTheStreamRatherThanADirectQuery(): void
    {
        $svc = new CkeditorRewriterService();
        $svc->migrationState = new FakeStateStream(entries: [
            ['source' => 'App_Entity_NewsPage', 'targetType' => 'entry', 'targetId' => 1],
        ]);

        $rows = $this->callPrivate($svc, 'stateEntryRows');

        self::assertSame([['source' => 'App_Entity_NewsPage', 'targetType' => 'entry', 'targetId' => 1]], $rows);
    }
}

/**
 * @internal
 */
final class FakeStateStream implements MigrationStateStream
{
    public int $allCalls = 0;

    /**
     * @param list<array<string, mixed>> $media
     * @param list<array<string, mixed>> $entries
     */
    public function __construct(
        private readonly array $media = [],
        private readonly array $entries = [],
    ) {
    }

    public function all(string $source): Generator
    {
        $this->allCalls++;
        yield from $source === 'media' ? $this->media : [];
    }

    public function entryRows(): Generator
    {
        yield from $this->entries;
    }

    public function targetIds(string $targetType): Generator
    {
        yield from [];
    }
}

/**
 * Stands in for the AssetResolver the pipeline binds per environment. The
 * rewriter has no environment of its own; the resolver is where it comes from.
 *
 * @internal
 */
final class FakeEnvResolver
{
    public function __construct(
        private readonly string $environment = 'COM',
        private readonly string $volume = 'uploads',
    ) {
    }

    public function environmentName(): string
    {
        return $this->environment;
    }

    public function targetVolume(): string
    {
        return $this->volume;
    }

    public function resolveFromLegacyId(int $kumaMediaId): int
    {
        return 0;
    }
}
