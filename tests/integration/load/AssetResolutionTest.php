<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\integration\load;

use craft\elements\Entry;
use Lameco\KumaCompile\Payload\Payload;
use Lameco\KumaCompile\Payload\SchemaGateway;
use Lameco\Kunstmaanmigrator\finalize\CkeditorRewriterService;
use Lameco\Kunstmaanmigrator\load\AssetMigrationService;
use Lameco\Kunstmaanmigrator\load\AssetPathResolver;
use Lameco\Kunstmaanmigrator\load\EntryMigrationService;
use Lameco\Kunstmaanmigrator\load\MigrationReport;
use Lameco\Kunstmaanmigrator\load\MigrationStateService;
use Lameco\Kunstmaanmigrator\payload\PayloadEntrySaver;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

require_once __DIR__ . '/_craft_shim.php';

/**
 * Task 8 — file-local fakes, same convention as PayloadEntrySaverTest's
 * SaverFakeSchemaGateway / InMemoryMigrationStateService /
 * FakeEntryMigrationService (each test file in this directory declares its
 * own prefixed copies rather than relying on cross-file load order — see
 * FixupTest's FixupFakeSchemaGateway for the established precedent).
 */
final class AssetResolutionFakeSchemaGateway implements SchemaGateway
{
    public function sectionByHandle(string $handle): ?array
    {
        return $handle === 'pages' ? ['id' => 1, 'handle' => 'pages'] : null;
    }

    public function entryTypeByHandle(string $handle): ?array
    {
        return $handle === 'contentPage' ? ['id' => 1, 'handle' => 'contentPage', 'hasTitleFormat' => false] : null;
    }

    public function primarySite(): array
    {
        return ['id' => 1, 'handle' => 'en'];
    }

    public function siteByHandle(string $handle): ?array
    {
        return $handle === 'en' ? ['id' => 1, 'handle' => 'en'] : null;
    }

    public function fieldHandlesFor(string $entryTypeHandle): array
    {
        return $entryTypeHandle === 'contentPage' ? ['media', 'body'] : [];
    }

    /** Derived from the same fixtures the other lookups use, so fakes stay consistent. */
    public function fieldSlotsFor(string $entryTypeHandle): array
    {
        $slots = [];

        foreach ($this->fieldHandlesFor($entryTypeHandle) as $handle) {
            $nested = $this->blockTypesFor($entryTypeHandle, $handle);
            $slots[$handle] = [
                'type' => $nested === [] ? 'PlainText' : 'Matrix',
                'required' => false,
                'nested' => $nested,
            ];
        }

        return $slots;
    }

    public function blockTypesFor(string $entryTypeHandle, string $fieldHandle): array
    {
        return [];
    }
}

final class AssetResolutionInMemoryMigrationStateService extends MigrationStateService
{
    /** @var array<string, array<string, mixed>> */
    private array $rows = [];

    private function rowKey(string $source, string $key, ?int $siteId): string
    {
        return $source . "\0" . $key . "\0" . ($siteId ?? '');
    }

    public function get(string $source, string $key, ?int $siteId = null): ?array
    {
        return $this->rows[$this->rowKey($source, $key, $siteId)] ?? null;
    }

    public function getTargetId(string $source, string $key, ?int $siteId = null): ?int
    {
        $row = $this->get($source, $key, $siteId);

        return ($row !== null && $row['targetId'] !== null) ? (int) $row['targetId'] : null;
    }

    public function getTargetUid(string $source, string $key, ?int $siteId = null): ?string
    {
        return $this->get($source, $key, $siteId)['targetUid'] ?? null;
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
        $rowKey = $this->rowKey($source, $key, $siteId);
        $existing = $this->rows[$rowKey] ?? null;
        $this->rows[$rowKey] = [
            'source' => $source,
            'sourceKey' => $key,
            'targetType' => $targetType,
            'targetId' => $targetId,
            'targetUid' => $targetUid ?? '',
            'siteId' => $siteId,
            'meta' => $meta !== null ? $meta : ($existing['meta'] ?? null),
        ];
    }

    public function updateMeta(string $source, string $key, ?int $siteId, array $meta): void
    {
        $rowKey = $this->rowKey($source, $key, $siteId);
        if (!isset($this->rows[$rowKey])) {
            return;
        }
        $current = $this->rows[$rowKey]['meta'] ?? [];
        if (!is_array($current)) {
            $current = [];
        }
        $this->rows[$rowKey]['meta'] = array_merge($current, $meta);
    }
}

/**
 * Fake save path — same contract as PayloadEntrySaverTest's
 * FakeEntryMigrationService: idempotent lookup-or-create keyed by
 * (stateSource, stateKey), recorded via the injected MigrationStateService.
 */
final class AssetResolutionFakeEntryMigrationService extends EntryMigrationService
{
    private int $nextId = 1000;

    /** @var array<string, mixed> */
    public array $lastPerSite = [];

    public function saveEntryForSites(
        int $sectionId,
        int $typeId,
        string $stateSource,
        string|int $stateKey,
        array $perSite,
        bool $force = false,
        ?MigrationReport $report = null,
    ): Entry {
        $this->lastPerSite = $perSite;

        /** @var Entry $entry */
        $entry = (new ReflectionClass(Entry::class))->newInstanceWithoutConstructor();

        $existingId = $this->stateService->getTargetId($stateSource, (string) $stateKey);
        if ($existingId !== null) {
            $entry->id = $existingId;
            $entry->uid = (string) $this->stateService->getTargetUid($stateSource, (string) $stateKey);

            return $entry;
        }

        $id = $this->nextId++;
        $entry->id = $id;
        $entry->uid = 'fake-uid-' . $id;
        $this->stateService->record($stateSource, (string) $stateKey, 'entry', $id, $entry->uid);

        return $entry;
    }
}

/**
 * Fakes the Craft/asset boundary per the Task 8 brief: `resolveFromLegacyUrl`
 * exercises the REAL `AssetPathResolver::resolveLocal` against a test-owned
 * temp media root (so "fixture file present" vs "missing file" is genuine
 * filesystem behaviour, not a canned bool) but stops short of the
 * Craft-dependent ingest (`Craft::$app->assets`/`saveElement`) that needs a
 * booted application — matching this repo's "no live Craft/DB in tests"
 * convention (PayloadEntrySaverTest's FakeEntryMigrationService docblock).
 */
final class FakeAssetMigrationService extends AssetMigrationService
{
    public string $mediaRoot = '';

    /** @var array<string, int> legacy _asset path → resolved Craft asset id, once the file is confirmed present */
    public array $resolvedUrlIds = [];

    /** @var array<int, int> legacy kuma_media id → resolved Craft asset id */
    public array $resolvedLegacyIds = [];

    public function resolveFromLegacyUrl(string $legacyUrl): int
    {
        if ($this->mediaRoot !== '' && AssetPathResolver::resolveLocal($legacyUrl, $this->mediaRoot) === null) {
            return 0;
        }

        return $this->resolvedUrlIds[$legacyUrl] ?? 0;
    }

    public function resolveFromLegacyId(int $legacyId): int
    {
        return $this->resolvedLegacyIds[$legacyId] ?? 0;
    }
}

final class AssetResolutionTest extends TestCase
{
    private string $tempMediaRoot;

    protected function setUp(): void
    {
        // AssetPathResolver::resolveLocal() strips the URL's "/uploads/media/"
        // prefix and joins the remainder directly onto $rootDir — the root
        // IS the file's parent, not a grandparent containing an uploads/media
        // subtree (matches LEGACY_MEDIA_PATH's real production meaning).
        $this->tempMediaRoot = sys_get_temp_dir() . '/kunstmaan-migrator-asset-root-' . uniqid();
        mkdir($this->tempMediaRoot, 0755, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tempMediaRoot . '/*') ?: []);
        @rmdir($this->tempMediaRoot);
    }

    private function makeSaver(
        EntryMigrationService $entryService,
        MigrationStateService $stateService,
        AssetMigrationService $assetService,
    ): PayloadEntrySaver {
        return new PayloadEntrySaver(
            new AssetResolutionFakeSchemaGateway(),
            $entryService,
            $stateService,
            $assetService,
            new CkeditorRewriterService(),
            static fn(callable $fn) => $fn(),
        );
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function payloadArray(string $sourceUid, array $overrides = []): array
    {
        return array_replace_recursive([
            'sourceUid' => $sourceUid,
            'section' => 'pages',
            'entryType' => 'contentPage',
            'sites' => [
                'en' => [
                    'enabled' => true,
                    'title' => 'Entry ' . $sourceUid,
                    'slug' => 'entry-' . str_replace(':', '-', $sourceUid),
                    'fieldValues' => [],
                ],
            ],
        ], $overrides);
    }

    public function testResolvableAssetPathSubstitutesTheResolvedIdIntoTheField(): void
    {
        file_put_contents($this->tempMediaRoot . '/present.jpg', 'fake-bytes');

        $state = new AssetResolutionInMemoryMigrationStateService();
        $entryService = new AssetResolutionFakeEntryMigrationService();
        $entryService->stateService = $state;
        $assetService = new FakeAssetMigrationService();
        $assetService->mediaRoot = $this->tempMediaRoot;
        $assetService->resolvedUrlIds = ['/uploads/media/present.jpg' => 501];
        $saver = $this->makeSaver($entryService, $state, $assetService);

        $payload = Payload::fromArray($this->payloadArray('kuma:COM:nt_page:400', [
            'sites' => ['en' => ['fieldValues' => ['media' => ['_asset' => '/uploads/media/present.jpg']]]],
        ]));

        $result = $saver->save($payload);

        // A relation field takes a list of element ids; a bare integer relates nothing.
        self::assertSame([501], $entryService->lastPerSite['en']['fieldValues']['media']);
        self::assertSame([], $result->unresolvedAssets);
    }

    public function testMissingAssetFileIsReportedAsUnresolvedAndTheEntryStillSaves(): void
    {
        // No fixture file written under $this->tempMediaRoot — genuinely missing on disk.
        $state = new AssetResolutionInMemoryMigrationStateService();
        $entryService = new AssetResolutionFakeEntryMigrationService();
        $entryService->stateService = $state;
        $assetService = new FakeAssetMigrationService();
        $assetService->mediaRoot = $this->tempMediaRoot;
        $saver = $this->makeSaver($entryService, $state, $assetService);

        $payload = Payload::fromArray($this->payloadArray('kuma:COM:nt_page:401', [
            'sites' => ['en' => ['fieldValues' => ['media' => ['_asset' => '/uploads/media/missing.jpg']]]],
        ]));

        $result = $saver->save($payload);

        // Entry still saves — an unresolved asset is reported, not fatal.
        self::assertSame(1000, $result->entryId);
        self::assertArrayNotHasKey('media', $entryService->lastPerSite['en']['fieldValues']);

        self::assertSame([[
            'field' => 'media',
            'site' => 'en',
            'path' => [],
            'asset' => '/uploads/media/missing.jpg',
        ]], $result->unresolvedAssets);
    }

    public function testUnresolvedAssetNestedInsideAMatrixBlockRecordsTheContainerPath(): void
    {
        $state = new AssetResolutionInMemoryMigrationStateService();
        $entryService = new AssetResolutionFakeEntryMigrationService();
        $entryService->stateService = $state;
        $assetService = new FakeAssetMigrationService();
        $assetService->mediaRoot = $this->tempMediaRoot;
        $saver = $this->makeSaver($entryService, $state, $assetService);

        $payload = Payload::fromArray($this->payloadArray('kuma:COM:nt_page:402', [
            'sites' => [
                'en' => [
                    'fieldValues' => [
                        'pageBuilder' => [
                            ['type' => 'contentMediaBlock', 'fields' => ['media' => ['_asset' => '/uploads/media/gone.jpg']]],
                        ],
                    ],
                ],
            ],
        ]));

        $result = $saver->save($payload);

        self::assertSame([[
            'field' => 'pageBuilder',
            'site' => 'en',
            'path' => ['pageBuilder', 0, 'fields'],
            'asset' => '/uploads/media/gone.jpg',
        ]], $result->unresolvedAssets);

        self::assertArrayNotHasKey(
            'media',
            $entryService->lastPerSite['en']['fieldValues']['pageBuilder'][0]['fields'],
        );
    }

    /**
     * Task 8 review (Finding 4) — parity with
     * testUnresolvedAssetNestedInsideAMatrixBlockRecordsTheContainerPath():
     * same nested shape, but the file IS present on disk, so the resolved id
     * must land in the exact nested slot rather than being dropped.
     */
    public function testResolvedAssetNestedInsideAMatrixBlockSubstitutesTheResolvedId(): void
    {
        file_put_contents($this->tempMediaRoot . '/present.jpg', 'fake-bytes');

        $state = new AssetResolutionInMemoryMigrationStateService();
        $entryService = new AssetResolutionFakeEntryMigrationService();
        $entryService->stateService = $state;
        $assetService = new FakeAssetMigrationService();
        $assetService->mediaRoot = $this->tempMediaRoot;
        $assetService->resolvedUrlIds = ['/uploads/media/present.jpg' => 501];
        $saver = $this->makeSaver($entryService, $state, $assetService);

        $payload = Payload::fromArray($this->payloadArray('kuma:COM:nt_page:403', [
            'sites' => [
                'en' => [
                    'fieldValues' => [
                        'pageBuilder' => [
                            ['type' => 'contentMediaBlock', 'fields' => ['media' => ['_asset' => '/uploads/media/present.jpg']]],
                        ],
                    ],
                ],
            ],
        ]));

        $result = $saver->save($payload);

        self::assertSame([], $result->unresolvedAssets);
        self::assertSame(
            [501],
            $entryService->lastPerSite['en']['fieldValues']['pageBuilder'][0]['fields']['media'],
        );
    }
}
