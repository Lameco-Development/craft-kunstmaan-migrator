<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\extract;

use Generator;
use lameco\kunstmaanmigrator\db\LegacyDbService;
use lameco\kunstmaanmigrator\extract\ExtractService;
use lameco\kunstmaanmigrator\filter\MigrationFilters;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/integration/load/_craft_shim.php';

/**
 * Phase 7 — implicit-content helper. Verifies the shared helper can produce
 * synthetic pagePart records for transform-time use without requiring extracted
 * JSON to pretend those records existed in Kunstmaan.
 */
final class ExtractServiceImplicitInjectionTest extends TestCase
{
    public function testRunKeepsImplicitContentOutOfExtractedPageParts(): void
    {
        $service = new ExtractService();
        $service->legacyDb = new SourceFaithfulImplicitLegacyDb();
        $storage = sys_get_temp_dir() . '/extract-implicit-' . bin2hex(random_bytes(4));
        $service->storagePath = $storage;

        try {
            $report = $service->run([
                'nodeClasses' => [
                    'App\\Entity\\Pages\\NewsPage' => [
                        'sourceTable' => 'news_pages',
                        'section' => 'newsPage',
                    ],
                ],
                'pageParts' => [
                    '__implicit_content__|NewsPage|main' => [
                        'target' => 'textContentBlock',
                        'fields' => ['html' => ['source' => 'content', 'handler' => 'ckeditor']],
                    ],
                ],
            ], new MigrationFilters());

            $this->assertSame(1, $report['nodesExtracted']);
            $jsonPath = $storage . '/extracted/App_Entity_Pages_NewsPage/97.json';
            $this->assertFileExists($jsonPath);
            $payload = json_decode((string) file_get_contents($jsonPath), true);
        } finally {
            self::removeTree($storage);
        }

        $this->assertIsArray($payload);
        $site = $payload['perSite']['nl'] ?? null;
        $this->assertIsArray($site);
        $this->assertSame(['id' => 42, 'content' => '<p>Hello</p>'], $site['detail']);
        $this->assertSame([], $site['pageParts']);
    }

    public function testEmitsSyntheticRecordPerImplicitKeyForMatchingFqcn(): void
    {
        $mapping = [
            'pageParts' => [
                '__implicit_content__|NewsPage|main' => [
                    'target' => 'textContentBlock',
                    'fields' => ['html' => ['source' => 'content', 'handler' => 'ckeditor']],
                ],
                '__implicit_content__|NewsPage|sidebar' => [
                    'target' => 'sidebarBlock',
                    'fields' => ['html' => ['source' => 'sidebar', 'handler' => 'ckeditor']],
                ],
            ],
        ];
        $detail = ['id' => 42, 'content' => '<p>Hello</p>', 'sidebar' => '<p>Side</p>'];

        $synthetic = ExtractService::buildImplicitContentPageParts(
            'App\\Entity\\Pages\\NewsPage',
            $detail,
            $mapping,
        );

        $this->assertCount(2, $synthetic);

        $byFqcn = [];
        foreach ($synthetic as $rec) {
            $byFqcn[$rec['fqcn']] = $rec;
        }
        $this->assertArrayHasKey('__implicit_content__|NewsPage|main', $byFqcn);
        $this->assertArrayHasKey('__implicit_content__|NewsPage|sidebar', $byFqcn);

        $main = $byFqcn['__implicit_content__|NewsPage|main'];
        $this->assertSame('main', $main['context']);
        $this->assertSame(42, $main['sourcePartId']);
        $this->assertSame($detail, $main['row']);
        $this->assertGreaterThanOrEqual(1_000_000, $main['sequence']);
    }

    public function testReturnsEmptyWhenNoImplicitKeysMatchFqcn(): void
    {
        $mapping = [
            'pageParts' => [
                '__implicit_content__|HomePage|main' => [
                    'target' => 'textContentBlock',
                    'fields' => ['html' => ['source' => 'content', 'handler' => 'ckeditor']],
                ],
            ],
        ];

        $synthetic = ExtractService::buildImplicitContentPageParts(
            'App\\Entity\\Pages\\NewsPage',
            ['id' => 1, 'content' => 'X'],
            $mapping,
        );

        $this->assertSame([], $synthetic);
    }

    public function testReturnsEmptyWhenMappingHasNoPagePartsBlock(): void
    {
        $synthetic = ExtractService::buildImplicitContentPageParts(
            'App\\Entity\\Pages\\NewsPage',
            ['id' => 1],
            [],
        );

        $this->assertSame([], $synthetic);
    }

    public function testIgnoresNonImplicitPagePartKeys(): void
    {
        $mapping = [
            'pageParts' => [
                'App\\Entity\\PageParts\\TextPart' => [
                    'target' => 'textBlock',
                    'fields' => ['html' => ['source' => 'text', 'handler' => 'ckeditor']],
                ],
            ],
        ];

        $synthetic = ExtractService::buildImplicitContentPageParts(
            'App\\Entity\\Pages\\NewsPage',
            ['id' => 1],
            $mapping,
        );

        $this->assertSame([], $synthetic);
    }

    public function testFallsBackToSequenceWhenDetailIdMissing(): void
    {
        $mapping = [
            'pageParts' => [
                '__implicit_content__|NewsPage|main' => [
                    'target' => 'textContentBlock',
                    'fields' => ['html' => ['source' => 'content', 'handler' => 'ckeditor']],
                ],
            ],
        ];

        $synthetic = ExtractService::buildImplicitContentPageParts(
            'App\\Entity\\Pages\\NewsPage',
            ['content' => 'X'], // no id
            $mapping,
        );

        $this->assertCount(1, $synthetic);
        $this->assertGreaterThanOrEqual(1_000_000, $synthetic[0]['sourcePartId']);
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $child = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($child)) {
                self::removeTree($child);
            } else {
                @unlink($child);
            }
        }
        @rmdir($path);
    }
}

final class SourceFaithfulImplicitLegacyDb extends LegacyDbService
{
    public function streamLiveNodes(string $entityClass): Generator
    {
        yield ['id' => 97, 'parent_id' => 3, 'ref_entity_name' => $entityClass];
    }

    public function translationsFor(int $nodeId): array
    {
        return [[
            'lang' => 'nl',
            'online' => 1,
            'title' => 'News',
            'slug' => 'news',
            'url' => 'news',
            'ref_id' => 42,
        ]];
    }

    public function queryOne(string $sql, array $params = []): ?array
    {
        return ['id' => 42, 'content' => '<p>Hello</p>'];
    }

    public function queryAll(string $sql, array $params = []): array
    {
        return [];
    }

    public function getDatabaseName(): string
    {
        return 'legacy';
    }
}
