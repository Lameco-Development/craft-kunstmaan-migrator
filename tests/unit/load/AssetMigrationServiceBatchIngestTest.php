<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\load {
    use Lameco\Kunstmaanmigrator\db\LegacyDbService;
    use Lameco\Kunstmaanmigrator\load\AssetMigrationService;
    use Lameco\Kunstmaanmigrator\load\MigrationOptions;
    use Lameco\Kunstmaanmigrator\load\MigrationStateService;
    use Lameco\Kunstmaanmigrator\tests\support\InMemoryElementWriter;
    use Lameco\Kunstmaanmigrator\tests\support\ThrowingLegacyDb;
    use PHPUnit\Framework\TestCase;
    use ReflectionMethod;
    use RuntimeException;

    /**
     * The batch surfaces — ingestReferenced() (--preload-assets) and
     * ingestBatch() — around their legacy-DB reads: the media-root gate, the
     * id-keyed IN binding, batch-lookup failure tolerance, and the D-66 RCA
     * accumulation on per-row failure. These queries are exactly what moves
     * into the shared reader, so their observable behavior is pinned here.
     */
    final class AssetMigrationServiceBatchIngestTest extends TestCase
    {
        private string $mediaRoot;

        protected function setUp(): void
        {
            $this->mediaRoot = sys_get_temp_dir() . '/kmig-batch-' . uniqid();
            mkdir($this->mediaRoot);
            file_put_contents($this->mediaRoot . '/pic.png', 'png-bytes');
        }

        protected function tearDown(): void
        {
            @unlink($this->mediaRoot . '/pic.png');
            @rmdir($this->mediaRoot);
        }

        public function testIngestReferencedBailsWhenTheMediaRootIsMissing(): void
        {
            $service = $this->service(new ThrowingLegacyDb());
            $service->legacyMediaRoot = '/definitely/not/a/dir';

            // A DB query would throw — the root gate must run first.
            $service->ingestReferenced(new MigrationOptions(dryRun: true), [1, 2]);

            self::assertSame([], $service->assetRcaRows);
        }

        public function testIngestReferencedSurvivesABatchLookupFailure(): void
        {
            $service = $this->service(new ThrowingLegacyDb());

            $service->ingestReferenced(new MigrationOptions(dryRun: true), [1, 2]);

            self::assertSame([], $service->assetRcaRows);
        }

        public function testIngestReferencedNormalizesAndBindsTheReferencedIds(): void
        {
            $legacyDb = new RecordingBatchDb();
            $service = $this->service($legacyDb);

            $service->ingestReferenced(new MigrationOptions(dryRun: true), [7, 3, 7, 0, -2]);

            self::assertCount(1, $legacyDb->calls);
            self::assertSame('SELECT * FROM kuma_media WHERE id IN (:kid3,:kid7)', $legacyDb->calls[0][0]);
            self::assertSame([':kid3' => 3, ':kid7' => 7], $legacyDb->calls[0][1]);
        }

        public function testIngestReferencedAccumulatesRcaRowsWhenARowFails(): void
        {
            $service = $this->service(new RecordingBatchDb([
                ['id' => 11, 'location' => 'img/a.png', 'url' => '/uploads/media/img/a.png'],
                ['id' => 12, 'location' => 'img/b.png', 'url' => '/uploads/media/img/b.png'],
            ]));
            $service->migrationState = new ThrowingBatchState('No such file or directory');
            $writer = new InMemoryElementWriter();
            $service->elementWriter = $writer;

            $service->ingestReferenced(new MigrationOptions(dryRun: true, batchSize: 1), [11, 12]);

            self::assertSame(
                [
                    ['legacyId' => 11, 'reason' => 'filesystem_404', 'path' => 'img/a.png'],
                    ['legacyId' => 12, 'reason' => 'filesystem_404', 'path' => 'img/b.png'],
                ],
                $service->assetRcaRows,
            );
            // batchSize=1: the element caches are flushed after every row.
            self::assertSame(2, $writer->cacheInvalidations);
        }

        public function testIngestBatchBailsWhenTheMediaRootIsMissing(): void
        {
            $legacyDb = new RecordingBatchDb();
            $service = $this->service($legacyDb);
            $service->legacyMediaRoot = '/definitely/not/a/dir';

            $service->ingestBatch([4], new MigrationOptions(dryRun: true));

            self::assertSame([], $legacyDb->calls);
        }

        public function testIngestBatchDoesNothingForAnEmptyIdSet(): void
        {
            $legacyDb = new RecordingBatchDb();
            $service = $this->service($legacyDb);

            $service->ingestBatch([], new MigrationOptions(dryRun: true));

            self::assertSame([], $legacyDb->calls);
        }

        public function testIngestBatchSurvivesABatchLookupFailure(): void
        {
            $service = $this->service(new ThrowingLegacyDb());

            $service->ingestBatch([4], new MigrationOptions(dryRun: true));

            self::assertSame([], $service->assetRcaRows);
        }

        public function testIngestBatchQueriesTheIdsAndProcessesResolvableRows(): void
        {
            $legacyDb = new RecordingBatchDb([
                ['id' => 8, 'url' => '/uploads/media/pic.png', 'content_type' => 'image/png'],
            ]);
            $service = $this->service($legacyDb);

            $service->ingestBatch([8], new MigrationOptions(dryRun: true));

            self::assertSame('SELECT * FROM kuma_media WHERE id IN (:kid8)', $legacyDb->calls[0][0]);
            self::assertSame([':kid8' => 8], $legacyDb->calls[0][1]);
            self::assertSame([], $service->assetRcaRows);
        }

        public function testIngestBatchAccumulatesRcaRowsWhenARowFails(): void
        {
            $service = $this->service(new RecordingBatchDb([
                ['id' => 21, 'location' => 'x/y.pdf', 'url' => '/uploads/media/x/y.pdf'],
            ]));
            $service->migrationState = new ThrowingBatchState('invalid mime');

            $service->ingestBatch([21], new MigrationOptions(dryRun: true));

            self::assertSame(
                [['legacyId' => 21, 'reason' => 'mime_mismatch', 'path' => 'x/y.pdf']],
                $service->assetRcaRows,
            );
        }

        public function testNormalizeReferencedIdsDedupesSortsAndDropsNonPositives(): void
        {
            $m = new ReflectionMethod(AssetMigrationService::class, 'normalizeReferencedIds');

            self::assertSame([3, 9], $m->invoke(null, [9, 3, 3, 0, -1]));
            self::assertSame([], $m->invoke(null, []));
        }

        public function testOrphanReportIsAnEmptyStubForNow(): void
        {
            self::assertSame([], (new AssetMigrationService())->orphanReport());
        }

        public function testAFailingFolderLookupFallsBackToNoFolder(): void
        {
            $service = new AssetMigrationService();
            $service->folderStrategy = 'legacy-tree';
            $service->legacyDb = new class() extends LegacyDbService {
                public function queryOne(string $sql, array $params = []): ?array
                {
                    throw new RuntimeException('legacy database is unreachable');
                }
            };

            $folderId = (new ReflectionMethod($service, 'legacyFolderIdForPath'))
                ->invoke($service, '/uploads/media/x.png');

            self::assertSame(0, $folderId);
        }

        private function service(LegacyDbService $legacyDb): AssetMigrationService
        {
            $service = new AssetMigrationService();
            $service->legacyDb = $legacyDb;
            $service->legacyMediaRoot = $this->mediaRoot;
            $service->migrationState = new NullBatchState();

            return $service;
        }
    }

    /**
     * @internal
     */
    final class RecordingBatchDb extends LegacyDbService
    {
        /** @var list<array{0: string, 1: array<string, mixed>}> */
        public array $calls = [];

        /** @param list<array<string, mixed>> $rows */
        public function __construct(private array $rows = [])
        {
            parent::__construct();
        }

        public function queryAll(string $sql, array $params = []): array
        {
            $this->calls[] = [$sql, $params];

            return $this->rows;
        }
    }

    /**
     * @internal
     */
    final class NullBatchState extends MigrationStateService
    {
        public function get(string $source, string $key, ?int $siteId = null): ?array
        {
            return null;
        }
    }

    /**
     * The row-level failure seam: any throw inside ingestRow must land in the
     * caller's RCA catch, and the state read is the first thing a row does.
     *
     * @internal
     */
    final class ThrowingBatchState extends MigrationStateService
    {
        public function __construct(private string $message)
        {
            parent::__construct();
        }

        public function get(string $source, string $key, ?int $siteId = null): ?array
        {
            throw new RuntimeException($this->message);
        }
    }
}

// The batch catch blocks log through the global Craft class, which the unit
// bootstrap never loads. Load the real helper class (precedent set by
// NavigationMenuBundlePassTest) so warning/info/error resolve to Yii's
// logger without a booted application.
namespace {
    if (!class_exists(\Craft::class, false)) {
        require dirname(__DIR__, 3) . '/vendor/craftcms/cms/src/Craft.php';
    }
}
