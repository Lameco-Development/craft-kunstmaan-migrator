<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\mapping;

use lameco\kunstmaanmigrator\mapping\MappingFile;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Covers the row builders (nodeClass / taxonomy / dataProvider), the
 * proposed-status loader, and the atomic write/update surface.
 */
final class MappingFileRowBuildersAndWritesTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/mapping-file-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->dir);
    }

    public function testBuildNodeClassRowCarriesOptionalBlocksOnlyWhenPresent(): void
    {
        $file = new MappingFile();

        $bare = $file->buildNodeClassRow([
            'fqcn' => 'App\\Entity\\Pages\\HomePage',
            'sourceTable' => 'home_pages',
            'targetEntryType' => 'home',
            'targetSection' => 'home',
        ], 'proposed');

        self::assertSame('nodeClass', $bare['kind']);
        self::assertSame('proposed', $bare['status']);
        self::assertSame('medium', $bare['confidence']);
        self::assertArrayNotHasKey('headerBlock', $bare);
        self::assertArrayNotHasKey('bodyColumn', $bare);

        $full = $file->buildNodeClassRow([
            'fqcn' => 'App\\Entity\\Pages\\NewsPage',
            'headerBlock' => ['type' => 'header'],
            'mergeRelations' => ['employee' => ['mode' => 'flatten']],
            'bodyColumn' => 'content',
        ], 'accepted');

        self::assertSame(['type' => 'header'], $full['headerBlock']);
        self::assertSame('content', $full['bodyColumn']);
        self::assertArrayHasKey('mergeRelations', $full);
    }

    public function testBuildTaxonomyAndDataProviderRows(): void
    {
        $file = new MappingFile();

        $taxonomy = $file->buildTaxonomyRow([
            'fqcn' => 'App\\Entity\\Sector',
            'sourceTable' => 'sectors',
            'targetSection' => 'sectors',
            'targetEntryType' => 'sector',
            'confidence' => 'high',
        ], 'proposed');

        self::assertSame('taxonomy', $taxonomy['kind']);
        self::assertSame('high', $taxonomy['confidence']);
        self::assertSame('sectors', $taxonomy['targetSection']);

        $provider = $file->buildDataProviderRow([
            'fqcn' => 'App\\PageParts\\LatestNewsPagePart',
            'target' => 'latestNews',
            'configFields' => ['limit'],
        ], 'needs-review');

        self::assertSame('dataProvider', $provider['kind']);
        self::assertSame('latestNews', $provider['target']);
        self::assertSame(['limit'], $provider['configFields']);
        self::assertSame('needs-review', $provider['status']);
    }

    public function testLoadProposedReturnsOnlyProposedAndNeedsReviewRows(): void
    {
        $path = $this->dir . '/mapping.yaml';
        file_put_contents($path, Yaml::dump([
            'proposals' => [
                ['kind' => 'column', 'column' => 'a', 'status' => 'proposed'],
                ['kind' => 'column', 'column' => 'b', 'status' => 'accepted'],
                ['kind' => 'column', 'column' => 'c', 'status' => 'needs-review'],
                ['kind' => 'column', 'column' => 'd', 'status' => 'dropped'],
            ],
        ]));

        $rows = (new MappingFile())->loadProposed($path);

        self::assertSame(['a', 'c'], array_column($rows, 'column'));
    }

    public function testSetStatusAndUpdateRowRewriteAtomically(): void
    {
        $file = new MappingFile();
        $path = $this->dir . '/mapping.yaml';
        file_put_contents($path, Yaml::dump([
            'proposals' => [
                ['kind' => 'column', 'column' => 'title', 'status' => 'proposed'],
            ],
        ]));

        self::assertTrue($file->setStatus($path, 0, 'accepted', 'operator says so', 'heroTitle', 'plain'));
        $parsed = Yaml::parseFile($path);
        self::assertSame('accepted', $parsed['proposals'][0]['status']);
        self::assertSame('operator says so', $parsed['proposals'][0]['rationale']);
        self::assertSame('heroTitle', $parsed['proposals'][0]['targetHandle']);
        self::assertSame('plain', $parsed['proposals'][0]['handler']);

        self::assertTrue($file->updateRow($path, 0, ['status' => 'dropped', 'warningAccepted' => true]));
        $parsed = Yaml::parseFile($path);
        self::assertSame('dropped', $parsed['proposals'][0]['status']);
        self::assertTrue($parsed['proposals'][0]['warningAccepted']);

        // Out-of-range row index refuses without touching the file.
        self::assertFalse($file->updateRow($path, 99, ['status' => 'accepted']));
        self::assertFalse($file->setStatus($path, 99, 'accepted'));
    }

    public function testWriteAtomicJsonWritesPrettyJsonViaTmpRename(): void
    {
        $file = new MappingFile();
        $path = $this->dir . '/schema.json';

        self::assertTrue($file->writeAtomicJson($path, ['entities' => ['App\\Entity\\HomePage']]));

        $decoded = json_decode((string) file_get_contents($path), true);
        self::assertSame(['entities' => ['App\\Entity\\HomePage']], $decoded);
        self::assertSame([], glob($this->dir . '/*.tmp.*') ?: []);
    }
}
