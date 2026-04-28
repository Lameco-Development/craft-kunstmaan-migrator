<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\load;

use lameco\kunstmaanmigrator\console\MigrateController;
use lameco\kunstmaanmigrator\db\LegacyDbService;
use lameco\kunstmaanmigrator\filter\MigrationFilters;
use lameco\kunstmaanmigrator\load\AssetMigrationService;
use lameco\kunstmaanmigrator\load\MigrationOptions;
use lameco\kunstmaanmigrator\load\MigrationStateService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Phase 9 / Plan 09-05 — --preload-assets must remain page-driven and
 * referenced-only. It must never pre-walk every row in kuma_media.
 */
final class AssetMigrationServiceReferencedOnlyPreloadTest extends TestCase
{
    public function testIngestReferencedQueriesOnlyExplicitReferencedIds(): void
    {
        putenv('LEGACY_MEDIA_PATH=' . dirname(__DIR__, 3));

        $legacyDb = new class extends LegacyDbService {
            /** @var list<string> */
            public array $queries = [];

            /**
             * @param array<string, mixed> $params
             * @return list<array<string, mixed>>
             */
            public function queryAll(string $sql, array $params = []): array
            {
                $this->queries[] = $sql;
                return [
                    [
                        'id' => 3,
                        'url' => 'README.md',
                        'location' => 'README.md',
                        'content_type' => 'text/plain',
                        'created_at' => '2026-01-01 00:00:00',
                    ],
                    [
                        'id' => 7,
                        'url' => 'README.md',
                        'location' => 'README.md',
                        'content_type' => 'text/plain',
                        'created_at' => '2026-01-01 00:00:00',
                    ],
                ];
            }
        };

        $state = new class extends MigrationStateService {
            public function get(string $source, string $key, ?int $siteId = null): ?array
            {
                return null;
            }
        };

        $service = new AssetMigrationService();
        $service->legacyDb = $legacyDb;
        $service->migrationState = $state;

        $service->ingestReferenced(
            new MigrationOptions(dryRun: true),
            new MigrationFilters(since: '2026-01-01'),
            [7, 3, 7],
        );

        self::assertNotSame([], $legacyDb->queries);
        self::assertStringNotContainsString('SELECT id FROM kuma_media', implode("\n", $legacyDb->queries));
        self::assertStringContainsString('SELECT * FROM kuma_media WHERE id IN', implode("\n", $legacyDb->queries));
        self::assertCount(1, $legacyDb->queries, 'Only the referenced id batch should be queried.');
    }

    public function testIngestReferencedDoesNotQueryWhenNoReferencedIdsAreKnown(): void
    {
        putenv('LEGACY_MEDIA_PATH=' . dirname(__DIR__, 3));

        $legacyDb = new class extends LegacyDbService {
            /** @var list<string> */
            public array $queries = [];

            public function queryAll(string $sql, array $params = []): array
            {
                $this->queries[] = $sql;
                return [];
            }
        };

        $service = new AssetMigrationService();
        $service->legacyDb = $legacyDb;
        $service->migrationState = new class extends MigrationStateService {
            public function get(string $source, string $key, ?int $siteId = null): ?array
            {
                return null;
            }
        };

        $service->ingestReferenced(new MigrationOptions(dryRun: true), new MigrationFilters(), []);

        self::assertSame([], $legacyDb->queries);
    }

    public function testPayloadCollectorFindsDeferredAndCkeditorAssetTokens(): void
    {
        $method = new ReflectionMethod(MigrateController::class, 'collectReferencedAssetIdsFromPayload');
        $payload = [
            'fieldValues' => [
                'nl' => [
                    'heroImage' => ['asset:42'],
                    'body' => '<p>[M99] and repeated asset:42</p>',
                    'matrix' => [
                        ['fields' => ['image' => ['asset:7']]],
                    ],
                ],
            ],
            'referencedMediaIds' => [123, '456'],
            'ignored' => '[NT12]',
        ];

        self::assertSame([7, 42, 99, 123, 456], $method->invoke(null, $payload));
    }

    public function testMigrateControllerPassesCollectedIdsToPreload(): void
    {
        $source = (string) file_get_contents(
            (new \ReflectionClass(MigrateController::class))->getFileName(),
        );

        self::assertStringContainsString('collectReferencedAssetIdsFromPayloadDirectory($transformedDir)', $source);
        self::assertStringContainsString('ingestReferenced($opts, $filters, $referencedAssetIds)', $source);
    }
}
