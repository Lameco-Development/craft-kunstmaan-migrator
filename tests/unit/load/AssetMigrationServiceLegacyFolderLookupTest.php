<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\load;

use Lameco\Kunstmaanmigrator\db\LegacyDbService;
use Lameco\Kunstmaanmigrator\load\AssetFolderPath;
use Lameco\Kunstmaanmigrator\load\AssetMigrationService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * A file the rewriter finds by path still belongs to a kuma_folders folder.
 *
 * `resolveFromLegacyUrl()` builds its row from the filesystem, which knows no folder. Before
 * the lookup below existed the row reached `targetFolderPath()` without a `folder_id`, so
 * `legacy-tree` found no chain and every asset fell into a year bucket — on a corpus whose
 * rich text references media by path, that was every asset in the migration.
 */
final class AssetMigrationServiceLegacyFolderLookupTest extends TestCase
{
    public function testLegacyTreeReadsTheFolderFromTheMatchingMediaRow(): void
    {
        $legacyDb = $this->legacyDb(['folder_id' => 42]);
        $service = new AssetMigrationService();
        $service->legacyDb = $legacyDb;
        $service->folderStrategy = AssetFolderPath::STRATEGY_LEGACY_TREE;

        self::assertSame(42, $this->lookup($service, '/uploads/media/5f08/5f19.png'));
        self::assertStringContainsString('FROM kuma_media WHERE url = :url', $legacyDb->queries[0]);
        self::assertSame('/uploads/media/5f08/5f19.png', $legacyDb->params[0][':url']);
    }

    public function testAGenuinelyOrphanedFileKeepsTheYearFallback(): void
    {
        $service = new AssetMigrationService();
        $service->legacyDb = $this->legacyDb(null);
        $service->folderStrategy = AssetFolderPath::STRATEGY_LEGACY_TREE;

        self::assertSame(0, $this->lookup($service, '/uploads/media/gone/gone.png'));
    }

    public function testTheYearStrategyNeverPaysForTheLookup(): void
    {
        $legacyDb = $this->legacyDb(['folder_id' => 42]);
        $service = new AssetMigrationService();
        $service->legacyDb = $legacyDb;
        $service->folderStrategy = AssetFolderPath::STRATEGY_YEAR;

        self::assertSame(0, $this->lookup($service, '/uploads/media/5f08/5f19.png'));
        self::assertSame([], $legacyDb->queries);
    }

    /** @param ?array<string, mixed> $row */
    private function legacyDb(?array $row): LegacyDbService
    {
        return new class($row) extends LegacyDbService {
            /** @var list<string> */
            public array $queries = [];

            /** @var list<array<string, mixed>> */
            public array $params = [];

            /** @param ?array<string, mixed> $row */
            public function __construct(private ?array $row)
            {
                parent::__construct();
            }

            /** @param array<string, mixed> $params */
            public function queryOne(string $sql, array $params = []): ?array
            {
                $this->queries[] = $sql;
                $this->params[] = $params;

                return $this->row;
            }
        };
    }

    private function lookup(AssetMigrationService $service, string $path): int
    {
        return (int) (new ReflectionMethod($service, 'legacyFolderIdForPath'))->invoke($service, $path);
    }
}
