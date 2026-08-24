<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\load;

use Lameco\Kunstmaanmigrator\db\LegacyDbService;
use Lameco\Kunstmaanmigrator\load\AssetMigrationService;
use Lameco\Kunstmaanmigrator\load\MigrationOptions;
use Lameco\Kunstmaanmigrator\load\MigrationStateService;
use Lameco\Kunstmaanmigrator\run\EnvironmentContext;
use Lameco\Kunstmaanmigrator\tests\support\EnvironmentFactory;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The two JIT entry points — resolveFromLegacyId() and resolveFromLegacyUrl()
 * — are what every real run actually uses (FH-03: JIT default). These tests
 * pin their decision logic: the state fast path, URL normalisation, the
 * media-root ladder the environment carries, and the skip contract.
 *
 * Everything past folder/volume resolution needs a booted Craft, so the
 * ingest itself is cut off via `skipAssets` — the branches up to that point
 * are the ones that matter here.
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

    /** @param list<string> $roots */
    private function env(array $roots = []): EnvironmentContext
    {
        return EnvironmentFactory::make('COM', mediaRoots: $roots);
    }

    public function testResolveFromLegacyIdReturnsTheStateTargetWithoutTouchingTheLegacyDb(): void
    {
        $service = new AssetMigrationService();
        // legacyDb deliberately left null — any DB read would fatal the test.
        $service->migrationState = new JitStateMap([
            'media|kuma_media:42' => ['targetId' => 123],
        ]);

        self::assertSame(123, $service->resolveFromLegacyId(42, $this->env()));
    }

    public function testResolveFromLegacyIdReturnsZeroWhenTheMediaRowIsGone(): void
    {
        $service = new AssetMigrationService();
        $service->legacyDb = new JitLegacyDb(null);
        $service->migrationState = new JitStateMap();

        self::assertSame(0, $service->resolveFromLegacyId(999, $this->env([$this->mediaRoot])));
        self::assertStringContainsString('FROM kuma_media WHERE id = :id', $service->legacyDb->queryOneCalls[0][0]);
        self::assertSame([':id' => 999], $service->legacyDb->queryOneCalls[0][1]);
    }

    public function testResolveFromLegacyIdHonoursSkipAssets(): void
    {
        $service = new AssetMigrationService();
        $service->legacyDb = new JitLegacyDb([
            'id' => 5,
            'url' => '/uploads/media/pics/one.png',
            'content_type' => 'image/png',
        ]);
        $state = new JitStateMap();
        $service->migrationState = $state;

        self::assertSame(0, $service->resolveFromLegacyId(5, $this->env([$this->mediaRoot]), new MigrationOptions(skipAssets: true)));
        self::assertSame([], $state->recorded);
    }

    public function testTheBoundResolverCarriesTheEnvironmentAndTheRunsOptions(): void
    {
        // What the CKEditor rewriter is handed: the same two lookups, with the
        // environment and options fixed at the point the environment was opened.
        $service = new AssetMigrationService();
        $service->legacyDb = new JitLegacyDb([
            'id' => 5,
            'url' => '/uploads/media/pics/one.png',
            'content_type' => 'image/png',
        ]);
        $state = new JitStateMap(['media|kuma_media:42' => ['targetId' => 123]]);
        $service->migrationState = $state;

        $resolver = $service->resolverFor($this->env([$this->mediaRoot]), new MigrationOptions(skipAssets: true));

        self::assertSame(123, $resolver->resolveFromLegacyId(42));
        self::assertSame(0, $resolver->resolveFromLegacyId(5), 'skipAssets travels with the resolver');
        self::assertSame(0, $resolver->resolveFromLegacyUrl('/uploads/media/pics/one.png'));
        self::assertSame([], $state->recorded);
    }

    public function testIngestOneWithoutAWiredConnectionIsAWarnedMissNotAFatal(): void
    {
        // Same failure mode as ingestReferenced: before the guard this was an
        // uncaught null-dereference three frames into a CKEditor rewrite.
        if (!class_exists(\Craft::class, false)) {
            require dirname(__DIR__, 3) . '/vendor/craftcms/cms/src/Craft.php';
        }

        $service = new AssetMigrationService();

        self::assertNull($service->ingestOne(5, new MigrationOptions(), $this->env()));
    }

    public function testResolveFromLegacyUrlRejectsUrlsOutsideTheMediaTree(): void
    {
        $service = new AssetMigrationService();

        self::assertSame(0, $service->resolveFromLegacyUrl('', $this->env()));
        self::assertSame(0, $service->resolveFromLegacyUrl('/other/file.png', $this->env()));
        self::assertSame(0, $service->resolveFromLegacyUrl('https://x.test/random.png', $this->env()));
        self::assertSame(0, $service->resolveFromLegacyUrl('/uploads/media', $this->env()));
    }

    public function testResolveFromLegacyUrlStripsHostAndQueryBeforeTheStateLookup(): void
    {
        $service = new AssetMigrationService();
        $service->migrationState = new JitStateMap([
            'media|legacy_url:' . sha1('/uploads/media/pics/one.png') => ['targetId' => 77],
        ]);

        self::assertSame(
            77,
            $service->resolveFromLegacyUrl('https://old.example/uploads/media/pics/one.png?v=3#frag', $this->env()),
        );
    }

    public function testResolveFromLegacyUrlReturnsZeroWhenNoRootHoldsTheFile(): void
    {
        $service = new AssetMigrationService();
        $service->migrationState = new JitStateMap();

        self::assertSame(0, $service->resolveFromLegacyUrl('/uploads/media/pics/missing.png', $this->env([$this->mediaRoot])));
    }

    public function testResolveFromLegacyUrlWalksTheFallbackRootsForTheFile(): void
    {
        $emptyRoot = sys_get_temp_dir() . '/kmig-jit-empty-' . uniqid();
        mkdir($emptyRoot);

        try {
            $service = new AssetMigrationService();
            $state = new JitStateMap();
            $service->migrationState = $state;

            // The file resolves against the fallback root and reaches
            // ingestRow, whose skip-assets guard then bails — the resolution
            // itself is what runs here.
            self::assertSame(0, $service->resolveFromLegacyUrl(
                '/uploads/media/pics/one.png',
                $this->env([$emptyRoot, $this->mediaRoot]),
                new MigrationOptions(skipAssets: true),
            ));
            self::assertSame([], $state->recorded);
        } finally {
            @rmdir($emptyRoot);
        }
    }

    public function testTheEnvironmentsMediaRootsWinOverTheEnvVar(): void
    {
        putenv(AssetMigrationService::LEGACY_MEDIA_ROOT_ENV . '=/from-env');

        $service = new AssetMigrationService();
        $mediaRoots = new ReflectionMethod($service, 'mediaRoots');

        self::assertSame(['/from-env'], $mediaRoots->invoke($service, $this->env()));
        self::assertSame(['/from-mapping', '/fallback'], $mediaRoots->invoke($service, $this->env(['/from-mapping', '/fallback'])));
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
