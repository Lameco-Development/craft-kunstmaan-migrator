<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\load;

use Lameco\Kunstmaanmigrator\db\LegacyDbService;
use Lameco\Kunstmaanmigrator\load\AssetFolderPath;
use Lameco\Kunstmaanmigrator\load\AssetMigrationService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Where a kuma_media row lands in the target volume: targetFolderPath() plus
 * the kuma_folders chain walk behind the `legacy-tree` strategy. The walk is
 * a legacy-DB read the shared-reader refactor will move, so the chain
 * semantics — caching, cycle guard, soft fallbacks — are pinned here.
 */
final class AssetMigrationServiceTargetFolderTest extends TestCase
{
    public function testYearStrategyBucketsByTheFilesOwnCreatedDate(): void
    {
        $service = new AssetMigrationService();

        self::assertSame('migrated/2019', $this->path($service, ['created_at' => '2019-05-01 10:00:00']));
    }

    public function testAMissingCreatedDateFallsBackToTheUnknownBucket(): void
    {
        $service = new AssetMigrationService();

        self::assertSame('migrated/unknown', $this->path($service, []));
    }

    public function testAnEmptySubfolderPlacesTheYearAtTheVolumeRoot(): void
    {
        $service = new AssetMigrationService();
        $service->targetSubfolder = '';

        self::assertSame('2019', $this->path($service, ['created_at' => '2019-05-01 10:00:00']));
    }

    public function testLegacyTreeMirrorsTheKumaFolderChain(): void
    {
        $service = $this->legacyTreeService([
            5 => ['id' => 5, 'parent_id' => 2, 'name' => 'Visuals'],
            2 => ['id' => 2, 'parent_id' => null, 'name' => 'Media'],
        ]);

        self::assertSame(
            'migrated/Media/Visuals',
            $this->path($service, ['folder_id' => 5, 'created_at' => '2019-05-01 10:00:00']),
        );
    }

    public function testAMultiSourceCorpusPrefixesTheEnvironmentSegment(): void
    {
        $service = $this->legacyTreeService([
            5 => ['id' => 5, 'parent_id' => 0, 'name' => 'Media'],
        ]);
        $service->environmentName = 'DE';
        $service->prefixEnvironment = true;

        self::assertSame('migrated/DE/Media', $this->path($service, ['folder_id' => 5]));
    }

    public function testASingleSourceCorpusOmitsTheEnvironmentSegment(): void
    {
        $service = $this->legacyTreeService([
            5 => ['id' => 5, 'parent_id' => 0, 'name' => 'Media'],
        ]);
        $service->environmentName = 'DE';
        $service->prefixEnvironment = false;

        self::assertSame('migrated/Media', $this->path($service, ['folder_id' => 5]));
    }

    public function testTheChainIsWalkedOncePerFolderPerRun(): void
    {
        $service = $this->legacyTreeService([
            5 => ['id' => 5, 'parent_id' => 2, 'name' => 'Visuals'],
            2 => ['id' => 2, 'parent_id' => null, 'name' => 'Media'],
        ]);

        $this->path($service, ['folder_id' => 5]);
        $this->path($service, ['folder_id' => 5]);

        /** @var FolderChainDb $legacyDb */
        $legacyDb = $service->legacyDb;
        self::assertSame(2, $legacyDb->queryCount);
    }

    public function testTheCacheIsKeyedPerEnvironment(): void
    {
        $service = $this->legacyTreeService([
            5 => ['id' => 5, 'parent_id' => 0, 'name' => 'Media'],
        ]);

        $service->environmentName = 'COM';
        $this->path($service, ['folder_id' => 5]);
        $service->environmentName = 'DE';
        $this->path($service, ['folder_id' => 5]);

        /** @var FolderChainDb $legacyDb */
        $legacyDb = $service->legacyDb;
        self::assertSame(2, $legacyDb->queryCount);
    }

    public function testAnUnknownFolderFallsBackToTheYearBucketAndCachesTheMiss(): void
    {
        $service = $this->legacyTreeService([]);

        self::assertSame(
            'migrated/2020',
            $this->path($service, ['folder_id' => 99, 'created_at' => '2020-01-01 00:00:00']),
        );

        $this->path($service, ['folder_id' => 99, 'created_at' => '2020-01-01 00:00:00']);

        /** @var FolderChainDb $legacyDb */
        $legacyDb = $service->legacyDb;
        self::assertSame(1, $legacyDb->queryCount);
    }

    public function testARowWithoutAFolderNeverPaysForTheWalk(): void
    {
        $service = $this->legacyTreeService([
            5 => ['id' => 5, 'parent_id' => 0, 'name' => 'Media'],
        ]);

        self::assertSame('migrated/2020', $this->path($service, ['created_at' => '2020-01-01 00:00:00']));

        /** @var FolderChainDb $legacyDb */
        $legacyDb = $service->legacyDb;
        self::assertSame(0, $legacyDb->queryCount);
    }

    public function testWithoutALegacyDbTheChainFallsBackToTheYear(): void
    {
        $service = new AssetMigrationService();
        $service->folderStrategy = AssetFolderPath::STRATEGY_LEGACY_TREE;

        self::assertSame(
            'migrated/2020',
            $this->path($service, ['folder_id' => 5, 'created_at' => '2020-01-01 00:00:00']),
        );
    }

    public function testACyclicParentChainTerminatesAtTheGuard(): void
    {
        $service = $this->legacyTreeService([
            9 => ['id' => 9, 'parent_id' => 9, 'name' => 'Loop'],
        ]);

        $chain = (new ReflectionMethod($service, 'legacyFolderChain'))->invoke($service, 9);

        self::assertIsString($chain);
        self::assertCount(32, explode('/', $chain));

        /** @var FolderChainDb $legacyDb */
        $legacyDb = $service->legacyDb;
        self::assertSame(32, $legacyDb->queryCount);
    }

    public function testFolderNamesAreSanitizedPerSegment(): void
    {
        $service = $this->legacyTreeService([
            3 => ['id' => 3, 'parent_id' => 0, 'name' => 'A/B: draft?'],
        ]);

        self::assertSame('migrated/A-B draft', $this->path($service, ['folder_id' => 3]));
    }

    public function testANameThatSanitizesAwayEntirelyFallsBackToTheYear(): void
    {
        $service = $this->legacyTreeService([
            3 => ['id' => 3, 'parent_id' => 0, 'name' => '???'],
        ]);

        self::assertSame(
            'migrated/2021',
            $this->path($service, ['folder_id' => 3, 'created_at' => '2021-03-03 00:00:00']),
        );
    }

    /** @param array<int, array<string, mixed>> $foldersById */
    private function legacyTreeService(array $foldersById): AssetMigrationService
    {
        $service = new AssetMigrationService();
        $service->folderStrategy = AssetFolderPath::STRATEGY_LEGACY_TREE;
        $service->legacyDb = new FolderChainDb($foldersById);

        return $service;
    }

    /** @param array<string, mixed> $row */
    private function path(AssetMigrationService $service, array $row): string
    {
        return (string) (new ReflectionMethod($service, 'targetFolderPath'))->invoke($service, $row);
    }
}

/**
 * kuma_folders by id, so the walk's query count is observable.
 *
 * @internal
 */
final class FolderChainDb extends LegacyDbService
{
    public int $queryCount = 0;

    /** @param array<int, array<string, mixed>> $foldersById */
    public function __construct(private array $foldersById = [])
    {
        parent::__construct();
    }

    public function queryOne(string $sql, array $params = []): ?array
    {
        $this->queryCount++;

        return $this->foldersById[(int) ($params[':id'] ?? 0)] ?? null;
    }
}
