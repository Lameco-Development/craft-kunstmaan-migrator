<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\load;

use Lameco\Kunstmaanmigrator\db\LegacyDbService;
use Lameco\Kunstmaanmigrator\load\AssetMigrationService;
use Lameco\Kunstmaanmigrator\load\MigrationStateService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The two JIT entry points — resolveFromLegacyId() and resolveFromLegacyUrl()
 * — are what every real run actually uses (FH-03: JIT default). These tests
 * pin their decision logic ahead of the legacy-DB-reader refactor: the state
 * fast path, URL normalisation, the media-root ladder, and the skip contract.
 *
 * Everything past folder/volume resolution needs a booted Craft, so the
 * ingest itself is cut off via $skipAssets — the branches up to that point
 * are the ones the refactor moves.
 */
final class AssetMigrationServiceJitResolveTest extends TestCase
{
    private string $mediaRoot;

    protected function setUp(): void
    {
        $this->mediaRoot = sys_get_temp_dir() . '/kmig-jit-' . uniqid();
        mkdir($this->mediaRoot . '/pics', 0777, true);
        file_put_contents($this->mediaRoot . '/pics/one.png', 'png-bytes');
    }

    protected function tearDown(): void
    {
        putenv(AssetMigrationService::LEGACY_MEDIA_ROOT_ENV);
        @unlink($this->mediaRoot . '/pics/one.png');
        @rmdir($this->mediaRoot . '/pics');
        @rmdir($this->mediaRoot);
    }

    public function testResolveFromLegacyIdReturnsTheStateTargetWithoutTouchingTheLegacyDb(): void
    {
        $service = new AssetMigrationService();
        // legacyDb deliberately left null — any DB read would fatal the test.
        $service->migrationState = new JitStateMap([
            'media|kuma_media:42' => ['targetId' => 123],
        ]);

        self::assertSame(123, $service->resolveFromLegacyId(42));
    }

    public function testResolveFromLegacyIdReturnsZeroWhenTheMediaRowIsGone(): void
    {
        $service = new AssetMigrationService();
        $service->legacyDb = new JitLegacyDb(null);
        $service->migrationState = new JitStateMap();
        $service->legacyMediaRoot = $this->mediaRoot;

        self::assertSame(0, $service->resolveFromLegacyId(999));
        self::assertStringContainsString('FROM kuma_media WHERE id = :id', $service->legacyDb->queryOneCalls[0][0]);
        self::assertSame([':id' => 999], $service->legacyDb->queryOneCalls[0][1]);
    }

    public function testResolveFromLegacyIdHonoursTheSkipAssetsProperty(): void
    {
        $service = new AssetMigrationService();
        $service->skipAssets = true;
        $service->legacyMediaRoot = $this->mediaRoot;
        $service->legacyDb = new JitLegacyDb([
            'id' => 5,
            'url' => '/uploads/media/pics/one.png',
            'content_type' => 'image/png',
        ]);
        $state = new JitStateMap();
        $service->migrationState = $state;

        self::assertSame(0, $service->resolveFromLegacyId(5));
        self::assertSame([], $state->recorded);
    }

    public function testResolveFromLegacyUrlRejectsUrlsOutsideTheMediaTree(): void
    {
        $service = new AssetMigrationService();

        self::assertSame(0, $service->resolveFromLegacyUrl(''));
        self::assertSame(0, $service->resolveFromLegacyUrl('/other/file.png'));
        self::assertSame(0, $service->resolveFromLegacyUrl('https://x.test/random.png'));
        self::assertSame(0, $service->resolveFromLegacyUrl('/uploads/media'));
    }

    public function testResolveFromLegacyUrlStripsHostAndQueryBeforeTheStateLookup(): void
    {
        $service = new AssetMigrationService();
        $service->migrationState = new JitStateMap([
            'media|legacy_url:' . sha1('/uploads/media/pics/one.png') => ['targetId' => 77],
        ]);

        self::assertSame(
            77,
            $service->resolveFromLegacyUrl('https://old.example/uploads/media/pics/one.png?v=3#frag'),
        );
    }

    public function testResolveFromLegacyUrlReturnsZeroWhenNoRootHoldsTheFile(): void
    {
        $service = new AssetMigrationService();
        $service->legacyMediaRoot = $this->mediaRoot;
        $service->migrationState = new JitStateMap();

        self::assertSame(0, $service->resolveFromLegacyUrl('/uploads/media/pics/missing.png'));
    }

    public function testResolveFromLegacyUrlWalksTheFallbackRootsForTheFile(): void
    {
        $emptyRoot = sys_get_temp_dir() . '/kmig-jit-empty-' . uniqid();
        mkdir($emptyRoot);

        try {
            $service = new AssetMigrationService();
            $service->legacyMediaRoot = $emptyRoot;
            $service->legacyMediaFallbackRoots = [$this->mediaRoot];
            // The file resolves against the fallback root and reaches
            // ingestRow, whose skip-assets guard then bails — the resolution
            // itself (the part the refactor moves) is what runs here.
            $service->skipAssets = true;
            $state = new JitStateMap();
            $service->migrationState = $state;

            self::assertSame(0, $service->resolveFromLegacyUrl('/uploads/media/pics/one.png'));
            self::assertSame([], $state->recorded);
        } finally {
            @rmdir($emptyRoot);
        }
    }

    public function testTheConfiguredMediaRootWinsOverTheEnvVar(): void
    {
        putenv(AssetMigrationService::LEGACY_MEDIA_ROOT_ENV . '=/from-env');

        $service = new AssetMigrationService();
        $mediaRoot = new ReflectionMethod($service, 'mediaRoot');

        self::assertSame('/from-env', $mediaRoot->invoke($service));

        $service->legacyMediaRoot = '/from-prop';
        self::assertSame('/from-prop', $mediaRoot->invoke($service));
    }
}

/**
 * State keyed by "{source}|{key}" so a test can prove WHICH state key a
 * lookup used, not just that one happened.
 *
 * @internal
 */
final class JitStateMap extends MigrationStateService
{
    /** @var list<array<string, mixed>> */
    public array $recorded = [];

    /** @param array<string, array<string, mixed>> $rows */
    public function __construct(private array $rows = [])
    {
        parent::__construct();
    }

    public function get(string $source, string $key, ?int $siteId = null): ?array
    {
        return $this->rows[$source . '|' . $key] ?? null;
    }

    public function record(
        string $source,
        string $key,
        string $targetType,
        int $targetId,
        ?string $targetUid = null,
        ?int $siteId = null,
        ?array $meta = null,
    ): void {
        $this->recorded[] = compact('source', 'key', 'targetType', 'targetId', 'meta');
    }
}

/**
 * @internal
 */
final class JitLegacyDb extends LegacyDbService
{
    /** @var list<array{0: string, 1: array<string, mixed>}> */
    public array $queryOneCalls = [];

    /** @param ?array<string, mixed> $row */
    public function __construct(private ?array $row = null)
    {
        parent::__construct();
    }

    public function queryOne(string $sql, array $params = []): ?array
    {
        $this->queryOneCalls[] = [$sql, $params];

        return $this->row;
    }
}
