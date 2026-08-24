<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\load {
    use craft\elements\Asset;
    use Lameco\Kunstmaanmigrator\load\AssetMigrationService;
    use Lameco\Kunstmaanmigrator\load\MigrationOptions;
    use Lameco\Kunstmaanmigrator\load\MigrationStateService;
    use Lameco\Kunstmaanmigrator\tests\support\InMemoryEmbedGateway;
    use PHPUnit\Framework\TestCase;
    use ReflectionMethod;
    use RuntimeException;

    /**
     * ingestRow() decision logic up to the Craft element boundary: the
     * skip-assets guard, the state-table fast path (including the --force
     * size-fingerprint contract), remote-video classification, and the local
     * dry-run/miss paths. Everything from volume resolution on needs a booted
     * Craft and stays with the integration suite.
     *
     * Safety net for the reader refactor: these are the branches that read
     * kuma_media row shapes directly.
     */
    final class AssetMigrationServiceIngestRowTest extends TestCase
    {
        private string $mediaRoot;

        protected function setUp(): void
        {
            $this->mediaRoot = sys_get_temp_dir() . '/kmig-row-' . uniqid();
            mkdir($this->mediaRoot);
            file_put_contents($this->mediaRoot . '/pic.png', 'png-bytes');
        }

        protected function tearDown(): void
        {
            @unlink($this->mediaRoot . '/pic.png');
            @rmdir($this->mediaRoot);
        }

        public function testSkipAssetsShortCircuitsBeforeAnyStateRead(): void
        {
            $service = new AssetMigrationService();
            // A state read would throw — proving the guard runs first.
            $service->migrationState = new ThrowingIngestRowState('state must not be read');
            $counts = [];

            $result = $this->ingest($service, ['id' => 1], new MigrationOptions(skipAssets: true), $counts);

            self::assertNull($result);
            self::assertSame(['skipped' => 1], $counts);
        }

        public function testAnAlreadyMigratedRowIsSkippedWithoutForce(): void
        {
            $service = $this->service(['targetId' => 9, 'meta' => json_encode(['file_size' => 10])]);
            $counts = [];

            $result = $this->ingest(
                $service,
                ['id' => 1, 'file_size' => 999, 'url' => '/uploads/media/pic.png'],
                new MigrationOptions(dryRun: true),
                $counts,
            );

            self::assertNull($result);
            self::assertSame(['skipped' => 1], $counts);
        }

        public function testForceStillSkipsWhenTheStoredSizeMatchesTheSource(): void
        {
            $service = $this->service(['targetId' => 9, 'meta' => json_encode(['file_size' => 10])]);
            $counts = [];

            $result = $this->ingest(
                $service,
                ['id' => 1, 'file_size' => 10, 'url' => '/uploads/media/pic.png'],
                new MigrationOptions(dryRun: true, force: true),
                $counts,
            );

            self::assertNull($result);
            self::assertSame(['skipped' => 1], $counts);
        }

        public function testForceReprocessesWhenTheStoredSizeDiffers(): void
        {
            // Meta handed over as an array, not JSON — the is_string branch
            // must cope with both shapes the state layer produces.
            $service = $this->service(['targetId' => 9, 'meta' => ['file_size' => 10]]);
            $counts = [];

            $result = $this->ingest(
                $service,
                ['id' => 1, 'file_size' => 20, 'url' => '/uploads/media/pic.png', 'content_type' => 'image/png'],
                new MigrationOptions(dryRun: true, force: true),
                $counts,
            );

            self::assertNull($result);
            self::assertSame(['created' => 1], $counts);
        }

        public function testForceReprocessesWhenTheStoredSizeIsUnknown(): void
        {
            $service = $this->service(['targetId' => 9, 'meta' => null]);
            $counts = [];

            $this->ingest(
                $service,
                ['id' => 1, 'file_size' => 20, 'url' => '/uploads/media/pic.png', 'content_type' => 'image/png'],
                new MigrationOptions(dryRun: true, force: true),
                $counts,
            );

            self::assertSame(['created' => 1], $counts);
        }

        public function testAMissingLocalFileIsSkipped(): void
        {
            $service = $this->service();
            $counts = [];

            $result = $this->ingest(
                $service,
                ['id' => 2, 'url' => '/uploads/media/gone.png', 'content_type' => 'image/png'],
                new MigrationOptions(dryRun: true),
                $counts,
            );

            self::assertNull($result);
            self::assertSame(['skipped' => 1], $counts);
        }

        public function testADryRunCountsAResolvableLocalFileAsCreated(): void
        {
            $service = $this->service();
            $counts = [];

            $result = $this->ingest(
                $service,
                ['id' => 3, 'url' => '/uploads/media/pic.png', 'content_type' => 'image/png'],
                new MigrationOptions(dryRun: true),
                $counts,
            );

            self::assertNull($result);
            self::assertSame(['created' => 1], $counts);
        }

        public function testARemoteVideoWithEmbedsAvailableCountsCreatedOnDryRun(): void
        {
            $service = $this->service();
            $embeds = new InMemoryEmbedGateway();
            $service->embeds = $embeds;
            $counts = [];

            $result = $this->ingest(
                $service,
                [
                    'id' => 4,
                    'content_type' => 'remote/video',
                    'metadata' => serialize(['code' => 'abc123', 'type' => 'youtube']),
                ],
                new MigrationOptions(dryRun: true),
                $counts,
            );

            self::assertNull($result);
            self::assertSame(['created' => 1], $counts);
            // Dry run stops before the gateway is asked to fetch anything.
            self::assertSame([], $embeds->created);
        }

        public function testARemoteVideoFallsBackToTheDecoderWhenEmbedsAreUnavailable(): void
        {
            $service = $this->service();
            $service->embeds = new InMemoryEmbedGateway(false);
            $service->serializedDecoder = new class() {
                public function decode(string $blob): mixed
                {
                    return unserialize($blob, ['allowed_classes' => false]);
                }

                public function extractVideoId(mixed $meta): ?string
                {
                    return is_array($meta) ? ($meta['code'] ?? null) : null;
                }
            };
            $counts = [];

            $result = $this->ingest(
                $service,
                [
                    'id' => 5,
                    'url' => 'https://www.youtube.com/watch?v=abc123',
                    'content_type' => 'remote/video',
                    'metadata' => serialize(['code' => 'abc123', 'type' => 'youtube']),
                ],
                new MigrationOptions(),
                $counts,
            );

            // Remote videos never yield an Asset element — only a state row.
            self::assertNull($result);
            self::assertSame(['created' => 1], $counts);

            /** @var IngestRowState $state */
            $state = $service->migrationState;
            self::assertCount(1, $state->recorded);
            self::assertSame('media', $state->recorded[0]['source']);
            self::assertSame('kuma_media:5', $state->recorded[0]['key']);
            self::assertSame('video', $state->recorded[0]['targetType']);
            self::assertSame(0, $state->recorded[0]['targetId']);
            self::assertSame('remote-video', $state->recorded[0]['meta']['kind']);
            self::assertSame('abc123', $state->recorded[0]['meta']['videoId']);
        }

        public function testARemoteVideoWithoutAnyUsableIdIsSkipped(): void
        {
            $service = $this->service();
            $service->embeds = new InMemoryEmbedGateway(false);
            $counts = [];

            $result = $this->ingest(
                $service,
                // 'video' content type + empty location is the second remote
                // classification clause (MIGRATION-PLAN §15.1).
                ['id' => 6, 'content_type' => 'video/mp4', 'location' => '', 'url' => 'not-a-url'],
                new MigrationOptions(),
                $counts,
            );

            self::assertNull($result);
            self::assertSame(['skipped' => 1], $counts);

            /** @var IngestRowState $state */
            $state = $service->migrationState;
            self::assertSame([], $state->recorded);
        }

        public function testALocalVideoFileIsTreatedAsARegularFile(): void
        {
            $service = $this->service();
            $counts = [];

            $this->ingest(
                $service,
                ['id' => 7, 'content_type' => 'video/mp4', 'location' => 'local', 'url' => '/uploads/media/pic.png'],
                new MigrationOptions(dryRun: true),
                $counts,
            );

            self::assertSame(['created' => 1], $counts);
        }

        /**
         * @param array<string, mixed> $row
         * @param array<string, int>   $counts
         */
        private function ingest(
            AssetMigrationService $service,
            array $row,
            MigrationOptions $opts,
            array &$counts,
        ): ?Asset {
            $m = new ReflectionMethod($service, 'ingestRow');

            return $m->invokeArgs($service, [$row, $this->mediaRoot, $opts, &$counts]);
        }

        /** @param ?array<string, mixed> $stateRow returned for every get() */
        private function service(?array $stateRow = null): AssetMigrationService
        {
            $service = new AssetMigrationService();
            $service->migrationState = new IngestRowState($stateRow);

            return $service;
        }
    }

    /**
     * @internal
     */
    final class IngestRowState extends MigrationStateService
    {
        /** @var list<array<string, mixed>> */
        public array $recorded = [];

        /** @param ?array<string, mixed> $row */
        public function __construct(private ?array $row = null)
        {
            parent::__construct();
        }

        public function get(string $source, string $key, ?int $siteId = null): ?array
        {
            return $this->row;
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
    final class ThrowingIngestRowState extends MigrationStateService
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

// The warning/skip paths in ingestRow() log through the global Craft class, which the unit
// bootstrap never loads. Load the real helper class (precedent set by
// NavigationMenuBundlePassTest) so warning/info/error resolve to Yii's
// logger without a booted application.
namespace {
    if (!class_exists(\Craft::class, false)) {
        require dirname(__DIR__, 3) . '/vendor/craftcms/cms/src/Craft.php';
    }
}
