<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\extract;

use Generator;
use lameco\kunstmaanmigrator\db\LegacyDbService;
use lameco\kunstmaanmigrator\extract\ExtractService;
use lameco\kunstmaanmigrator\filter\MigrationFilters;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/integration/load/_craft_shim.php';

final class ExtractServicePromotedTargetsTest extends TestCase
{
    public function testPromotedTargetsExtractAsStandaloneRecordsAndOwnersStaySourceFaithful(): void
    {
        $service = new ExtractService();
        $service->legacyDb = new PromotedExtractLegacyDb();
        $storage = sys_get_temp_dir() . '/extract-promoted-' . bin2hex(random_bytes(4));
        $service->storagePath = $storage;

        try {
            $report = $service->run($this->mapping(), new MigrationFilters());

            self::assertSame(2, $report['nodesExtracted']);
            self::assertSame(1, $report['promotedTargets']);

            $owner = json_decode((string) file_get_contents($storage . '/extracted/App_Entity_Pages_NewsPage/97.json'), true);
            $promoted = json_decode((string) file_get_contents($storage . '/extracted/promoted_employee/12.json'), true);
        } finally {
            self::removeTree($storage);
        }

        self::assertSame(12, $owner['perSite']['nl']['detail']['employee_id']);
        self::assertArrayNotHasKey('_rel:employee.name', $owner['perSite']['nl']['detail']);
        self::assertArrayNotHasKey('employee_employees.name', $owner['perSite']['nl']['detail']);

        self::assertSame('promotedTarget', $promoted['kind']);
        self::assertSame('employee', $promoted['stateSource']);
        self::assertSame(12, $promoted['stateKey']);
        self::assertSame('Jane NL', $promoted['perSite']['nl']['detail']['name']);
        self::assertSame('Jane EN', $promoted['perSite']['en']['detail']['name']);
    }

    private function mapping(): array
    {
        return [
            'sites' => ['nl' => 'default', 'en' => 'en'],
            'nodeClasses' => [
                'App\\Entity\\Pages\\NewsPage' => ['sourceTable' => 'news_pages'],
            ],
            'promotedTargets' => [
                'employee' => [
                    'stateSource' => 'employee',
                    'sourceRef' => 'kunstmaan.entity:App\\Entity\\Employee',
                    'sourceTable' => 'employee_employees',
                    'targetSection' => 'team',
                    'targetEntryType' => 'teamMember',
                    'fields' => [],
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

final class PromotedExtractLegacyDb extends LegacyDbService
{
    public function streamLiveNodes(string $entityClass): Generator
    {
        yield ['id' => 97, 'parent_id' => null, 'updated_at' => '2024-01-01'];
    }

    public function translationsFor(int $nodeId): array
    {
        return [['lang' => 'nl', 'online' => 1, 'title' => 'News', 'slug' => 'news', 'url' => '/news', 'ref_id' => 97]];
    }

    public function queryOne(string $sql, array $params = []): ?array
    {
        if (str_contains($sql, '`news_pages`')) {
            return ['id' => 97, 'title' => 'News', 'employee_id' => 12];
        }
        return null;
    }

    public function queryAll(string $sql, array $params = []): array
    {
        return [];
    }

    public function streamQuery(string $sql, array $params = []): Generator
    {
        if (str_contains($sql, '`employee_employees`')) {
            yield ['id' => 12, 'name' => 'Jane', 'function' => 'CEO'];
        }
    }

    public function extTranslationsFor(string|array $fqcns, int $id): array
    {
        return ['nl' => ['name' => 'Jane NL'], 'en' => ['name' => 'Jane EN']];
    }
}
