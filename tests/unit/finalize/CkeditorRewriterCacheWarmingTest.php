<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\finalize;

use Generator;
use Lameco\Kunstmaanmigrator\finalize\CkeditorRewriterService;
use Lameco\Kunstmaanmigrator\load\MigrationStateStream;
use Lameco\Kunstmaanmigrator\tests\support\FakeLegacyDb;
use Lameco\Kunstmaanmigrator\tests\support\ThrowingLegacyDb;
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
        $svc->migrationState = new FakeStateStream(media: [
            ['sourceKey' => 'kuma_media:123', 'targetType' => 'asset', 'targetId' => 55],
            ['sourceKey' => 'kuma_media:124', 'targetType' => 'asset', 'targetId' => 56],
        ]);

        self::assertSame(55, $this->callPrivate($svc, 'resolveKumaMediaId', 123));
        self::assertSame(56, $this->callPrivate($svc, 'resolveKumaMediaId', 124));
    }

    public function testKumaMediaWarmSkipsRowsThatAreNotResolvedAssets(): void
    {
        $svc = new CkeditorRewriterService();
        $svc->migrationState = new FakeStateStream(media: [
            ['sourceKey' => 'kuma_media:1', 'targetType' => 'entry', 'targetId' => 9],
            ['sourceKey' => 'kuma_media:2', 'targetType' => 'asset', 'targetId' => null],
            ['sourceKey' => 'something_else:3', 'targetType' => 'asset', 'targetId' => 7],
        ]);

        self::assertNull($this->callPrivate($svc, 'resolveKumaMediaId', 1));
        self::assertNull($this->callPrivate($svc, 'resolveKumaMediaId', 2));
        self::assertNull($this->callPrivate($svc, 'resolveKumaMediaId', 3));
    }

    public function testKumaMediaCacheIsWarmedOnce(): void
    {
        $stream = new FakeStateStream(media: [
            ['sourceKey' => 'kuma_media:5', 'targetType' => 'asset', 'targetId' => 42],
        ]);
        $svc = new CkeditorRewriterService();
        $svc->migrationState = $stream;

        $this->callPrivate($svc, 'resolveKumaMediaId', 5);
        $this->callPrivate($svc, 'resolveKumaMediaId', 5);
        $this->callPrivate($svc, 'resolveKumaMediaId', 6);

        self::assertSame(1, $stream->allCalls, 'the state table must be scanned once per request');
    }

    public function testUrlCacheWarmsFromStateMetaOriginalUrl(): void
    {
        $svc = new CkeditorRewriterService();
        $svc->migrationState = new FakeStateStream(media: [
            [
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
        $svc->migrationState = new FakeStateStream(media: [
            [
                'targetType' => 'asset',
                'targetId' => 88,
                'meta' => json_encode(['originalUrl' => '/uploads/media/photo.png']),
            ],
            ['targetType' => 'asset', 'targetId' => 89, 'meta' => ['size' => 12]],
            ['targetType' => 'asset', 'targetId' => 90, 'meta' => null],
        ]);

        self::assertSame(88, $this->callPrivate($svc, 'resolveMediaIdForUrl', '/uploads/media/photo.png'));
        self::assertNull($this->callPrivate($svc, 'resolveMediaIdForUrl', '/uploads/media/absent.png'));
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
