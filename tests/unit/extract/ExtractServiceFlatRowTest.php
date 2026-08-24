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
 * Flat-row extraction — AbstractConfig subclasses (berkvens `Configuration`)
 * have no kuma_node row; the nodeClass carries `flatRow: true` and extraction
 * SELECTs the source table directly, replicating the detail across every
 * locale from the mapping's sites: block.
 */
final class ExtractServiceFlatRowTest extends TestCase
{
    public function testFlatRowEmitsOneEnvelopePerRowAcrossMappingLocales(): void
    {
        $service = new ExtractService();
        $service->legacyDb = new FlatRowLegacyDb();
        $storage = sys_get_temp_dir() . '/extract-flatrow-' . bin2hex(random_bytes(4));
        $service->storagePath = $storage;

        try {
            $report = $service->run($this->mapping(), new MigrationFilters());

            self::assertSame(1, $report['nodesExtracted']);

            $envelope = json_decode(
                (string) file_get_contents($storage . '/extracted/App_Entity_Configuration/7.json'),
                true,
            );
        } finally {
            self::removeTree($storage);
        }

        self::assertTrue($envelope['flatRow']);
        self::assertSame(7, $envelope['ref_id']);
        self::assertSame(0, $envelope['kuma_node_id']);
        self::assertSame('app_configuration', $envelope['sourceTable']);

        // Locales come from mapping sites:, NOT a hardcoded fallback.
        self::assertSame(['nl' => 7, 'en' => 7], $envelope['refIdsByLocale']);
        self::assertSame(['nl', 'en'], array_keys($envelope['perSite']));

        foreach (['nl', 'en'] as $locale) {
            $site = $envelope['perSite'][$locale];
            self::assertTrue($site['online']);
            // null (not '') so load's firstNonEmpty preserves an existing
            // entry title set by an earlier contributor to the merged Single.
            self::assertNull($site['title']);
            self::assertNull($site['slug']);
            self::assertSame(7, $site['refId']);
            self::assertSame('013-1234567', $site['detail']['phone']);
        }
    }

    public function testFlatRowRejectsUnsafeSourceTableIdentifier(): void
    {
        $service = new ExtractService();
        $service->legacyDb = new FlatRowLegacyDb();
        $storage = sys_get_temp_dir() . '/extract-flatrow-' . bin2hex(random_bytes(4));
        $service->storagePath = $storage;

        $mapping = $this->mapping();
        $mapping['nodeClasses']['App\\Entity\\Configuration']['sourceTable'] = 'app_configuration; DROP';

        try {
            $this->expectException(\RuntimeException::class);
            $service->run($mapping, new MigrationFilters());
        } finally {
            self::removeTree($storage);
        }
    }

    /** @return array<string, mixed> */
    private function mapping(): array
    {
        return [
            'sites' => ['nl' => 'default', 'en' => 'en'],
            'nodeClasses' => [
                'App\\Entity\\Configuration' => [
                    'sourceTable' => 'app_configuration',
                    'flatRow' => true,
                ],
            ],
        ];
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}

final class FlatRowLegacyDb extends LegacyDbService
{
    public function streamQuery(string $sql, array $params = []): Generator
    {
        TestCase::assertStringContainsString('`app_configuration`', $sql);
        yield ['id' => 7, 'phone' => '013-1234567', 'address' => 'Tilburg'];
    }
}
