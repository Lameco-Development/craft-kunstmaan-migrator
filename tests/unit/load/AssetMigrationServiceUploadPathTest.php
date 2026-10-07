<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\load;

use Lameco\Kunstmaanmigrator\db\LegacyDbService;
use Lameco\Kunstmaanmigrator\load\AssetFolderPath;
use Lameco\Kunstmaanmigrator\load\AssetMigrationService;
use Lameco\Kunstmaanmigrator\load\MigrationOptions;
use Lameco\Kunstmaanmigrator\load\MigrationStateService;
use Lameco\Kunstmaanmigrator\run\EnvironmentContext;
use Lameco\Kunstmaanmigrator\tests\support\EnvironmentFactory;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * `_asset` paths outside `kuma_media` — the `/uploads/<dir>/<name>` a `file(<dir>)` transform
 * emits for catalogue tables that store a file name rather than a media id.
 *
 * They resolve against the same `mediaRoot` chain as `/uploads/media/…`, keyed by the file
 * found (`legacy_file:<sha1(realpath)>`), so one file referenced from many rows is one asset
 * and two checkouts holding the same relative path are two.
 */
final class AssetMigrationServiceUploadPathTest extends TestCase
{
    private const PATH = '/uploads/models_import/A12.jpg';

    private string $web;

    protected function setUp(): void
    {
        $this->web = sys_get_temp_dir() . '/kmig-upload-' . uniqid();
        mkdir($this->web . '/uploads/media', 0777, true);
        mkdir($this->web . '/uploads/models_import', 0777, true);
        file_put_contents($this->web . '/uploads/models_import/A12.jpg', 'jpg-bytes');
    }

    protected function tearDown(): void
    {
        foreach (['nl', 'fr'] as $checkout) {
            @unlink($this->web . '/' . $checkout . '/documents/montage.pdf');
            @rmdir($this->web . '/' . $checkout . '/documents');
            @rmdir($this->web . '/' . $checkout . '/media');
            @rmdir($this->web . '/' . $checkout);
        }
        @unlink($this->web . '/uploads/models_import/A12.jpg');
        @rmdir($this->web . '/uploads/models_import');
        @rmdir($this->web . '/uploads/media');
        @rmdir($this->web . '/uploads');
        @rmdir($this->web);
    }

    /**
     * A second legacy uploads directory, `<web>/<name>/{media,<file>}`.
     *
     * @return string the uploads directory
     */
    private function checkout(string $name, ?string $file): string
    {
        $uploads = $this->web . '/' . $name;
        @mkdir($uploads . '/media', 0777, true);

        if ($file !== null) {
            @mkdir(dirname($uploads . '/' . $file), 0777, true);
            file_put_contents($uploads . '/' . $file, $name . '-bytes');
        }

        return $uploads;
    }

    private function env(bool $prefix = false): EnvironmentContext
    {
        return EnvironmentFactory::make('NL', mediaRoots: [$this->web . '/uploads/media'], prefixEnvironment: $prefix);
    }

    public function testTheSamePathTwiceIsTheSameAsset(): void
    {
        $service = new AssetMigrationService();
        $service->migrationState = new UploadStateMap([
            'media|legacy_file:' . sha1((string) realpath($this->web . self::PATH)) => ['targetId' => 88],
        ]);

        self::assertSame(88, $service->resolveFromLegacyUrl(self::PATH, $this->env()));
        self::assertSame(88, $service->resolveFromLegacyUrl(self::PATH, $this->env()));
    }

    public function testTheSamePathInTwoCheckoutsIsTwoAssets(): void
    {
        // NL and FR are separate legacy installs migrated into one Craft database. Each has its
        // own `/uploads/documents/montage.pdf`; keyed by path alone, FR silently took NL's file.
        $nl = $this->checkout('nl', 'documents/montage.pdf');
        $fr = $this->checkout('fr', 'documents/montage.pdf');
        $service = new AssetMigrationService();
        $service->migrationState = new UploadStateMap([
            'media|legacy_file:' . sha1((string) realpath($nl . '/documents/montage.pdf')) => ['targetId' => 88],
            'media|legacy_file:' . sha1((string) realpath($fr . '/documents/montage.pdf')) => ['targetId' => 99],
        ]);

        $nlEnv = EnvironmentFactory::make('NL', mediaRoots: [$nl . '/media']);
        $frEnv = EnvironmentFactory::make('FR', mediaRoots: [$fr . '/media']);

        self::assertSame(88, $service->resolveFromLegacyUrl('/uploads/documents/montage.pdf', $nlEnv));
        self::assertSame(99, $service->resolveFromLegacyUrl('/uploads/documents/montage.pdf', $frEnv));
    }

    public function testAFileFoundUnderASharedRootIsOneAsset(): void
    {
        // The mediaRoots fallback chain: FR does not have the file, finds it under NL's root, and
        // is meant to reuse NL's asset rather than fetch a second copy of the same file.
        $nl = $this->checkout('nl', 'documents/montage.pdf');
        $fr = $this->checkout('fr', null);
        $service = new AssetMigrationService();
        $service->migrationState = new UploadStateMap([
            'media|legacy_file:' . sha1((string) realpath($nl . '/documents/montage.pdf')) => ['targetId' => 88],
        ]);

        $frEnv = EnvironmentFactory::make('FR', mediaRoots: [$fr . '/media', $nl . '/media']);

        self::assertSame(88, $service->resolveFromLegacyUrl('/uploads/documents/montage.pdf', $frEnv));
    }

    public function testAFileNameWithAHashOrQuestionMarkIsTakenLiterally(): void
    {
        // `file()` emits a file name, not a URL: `a#1.pdf` is the name on disk, and reading it
        // as a URL truncated it to `a`, a file that does not exist.
        $service = new AssetMigrationService();
        $state = [];

        foreach (['a#1.pdf' => 61, 'b?v=2.pdf' => 62] as $name => $id) {
            file_put_contents($this->web . '/uploads/models_import/' . $name, 'pdf-bytes');
            $state['media|legacy_file:' . sha1((string) realpath($this->web . '/uploads/models_import/' . $name))] = ['targetId' => $id];
        }
        $service->migrationState = new UploadStateMap($state);

        try {
            self::assertSame(61, $service->resolveFromLegacyUrl('/uploads/models_import/a#1.pdf', $this->env()));
            self::assertSame(62, $service->resolveFromLegacyUrl('/uploads/models_import/b?v=2.pdf', $this->env()));
        } finally {
            @unlink($this->web . '/uploads/models_import/a#1.pdf');
            @unlink($this->web . '/uploads/models_import/b?v=2.pdf');
        }
    }

    public function testAMissingFileResolvesToNothingAndRecordsNothing(): void
    {
        $service = new AssetMigrationService();
        $state = new UploadStateMap();
        $service->migrationState = $state;

        self::assertSame(0, $service->resolveFromLegacyUrl('/uploads/models_import/gone.jpg', $this->env()));
        self::assertSame([], $state->recorded);
    }

    public function testAFoundFileSkipsTheKumaMediaFolderLookup(): void
    {
        // A catalogue file has no kuma_media row, so asking for its folder is a wasted read.
        $legacyDb = new UploadLegacyDb(['folder_id' => 42]);
        $service = new AssetMigrationService();
        $service->legacyDb = $legacyDb;
        $service->folderStrategy = AssetFolderPath::STRATEGY_LEGACY_TREE;
        $service->migrationState = new UploadStateMap();

        self::assertSame(0, $service->resolveFromLegacyUrl(self::PATH, $this->env(), new MigrationOptions(skipAssets: true)));
        self::assertSame([], $legacyDb->queryOneCalls);
    }

    public function testTheRowItBuildsIsOneTheIngestCanFindOnDisk(): void
    {
        // The handoff: resolveFromLegacyUrl() passes the path as the row url and the media
        // root it matched; ingestRow() re-resolves the file from those two.
        $service = new AssetMigrationService();
        $service->migrationState = new UploadStateMap();
        $counts = [];

        (new ReflectionMethod($service, 'ingestRow'))->invokeArgs($service, [
            ['id' => 1, 'url' => self::PATH, 'content_type' => 'image/jpeg'],
            $this->web . '/uploads/media',
            new MigrationOptions(dryRun: true),
            &$counts,
            'legacy_url:' . sha1(self::PATH),
            $this->env(),
        ]);

        self::assertSame(['created' => 1], $counts);
    }

    public function testLegacyTreePlacesItUnderItsUploadsDirectory(): void
    {
        $service = new AssetMigrationService();
        $service->folderStrategy = AssetFolderPath::STRATEGY_LEGACY_TREE;
        $row = ['url' => self::PATH, 'created_at' => '2019-05-01 10:00:00'];

        self::assertSame('migrated/models_import', $this->folder($service, $row, $this->env()));
        self::assertSame('migrated/NL/models_import', $this->folder($service, $row, $this->env(prefix: true)));
        self::assertSame(
            'migrated/documents/brochures',
            $this->folder($service, ['url' => '/uploads/documents/brochures/x.pdf'], $this->env()),
        );
    }

    public function testTheYearStrategyAndMediaPathsKeepTheirPlacement(): void
    {
        $service = new AssetMigrationService();
        $row = ['url' => self::PATH, 'created_at' => '2019-05-01 10:00:00'];

        self::assertSame('migrated/2019', $this->folder($service, $row, $this->env()));

        $service->folderStrategy = AssetFolderPath::STRATEGY_LEGACY_TREE;

        self::assertSame(
            'migrated/2019',
            $this->folder($service, ['url' => '/uploads/media/orphan.png', 'created_at' => '2019-05-01'], $this->env()),
            'an unfiled kuma_media file still falls back to the year bucket',
        );
    }

    public function testMediaPathsResolveAsBefore(): void
    {
        $service = new AssetMigrationService();
        $service->migrationState = new UploadStateMap([
            'media|legacy_url:' . sha1('/uploads/media/pic.png') => ['targetId' => 5],
        ]);

        self::assertSame(5, $service->resolveFromLegacyUrl('/uploads/media/pic.png', $this->env()));
        self::assertSame(0, $service->resolveFromLegacyUrl('/other/A12.jpg', $this->env()));
    }

    /** @param array<string, mixed> $row */
    private function folder(AssetMigrationService $service, array $row, EnvironmentContext $env): string
    {
        return (string) (new ReflectionMethod($service, 'targetFolderPath'))->invoke($service, $row, $env);
    }
}

/**
 * File-local copy of the state map, per this directory's convention: a test file declares its
 * own fakes rather than leaning on another file's load order.
 *
 * @internal
 */
final class UploadStateMap extends MigrationStateService
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
final class UploadLegacyDb extends LegacyDbService
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
