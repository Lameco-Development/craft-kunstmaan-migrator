<?php

declare(strict_types=1);

namespace tests\unit\analyze;

use lameco\kunstmaanmigrator\analyze\KunstmaanSchemaDumper;
use lameco\kunstmaanmigrator\filter\MigrationFilters;
use lameco\kunstmaanmigrator\source\DoctrineEntityInfo;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class KunstmaanSchemaDumperEntityFilterTest extends TestCase
{
    public function testScopedFilterUsesDoctrineEntityTableNames(): void
    {
        $tables = [
            'lameco_websitebundle_newspages',
            'lameco_websitebundle_employee_employees',
            'lameco_websitebundle_homepages',
        ];
        $entities = [
            'App\\Entity\\Pages\\NewsPage' => new DoctrineEntityInfo(
                'App\\Entity\\Pages\\NewsPage',
                'lameco_websitebundle_newspages',
                [],
                [],
            ),
            'App\\Entity\\Employee' => new DoctrineEntityInfo(
                'App\\Entity\\Employee',
                'lameco_websitebundle_employee_employees',
                [],
                [],
            ),
            'App\\Entity\\Pages\\HomePage' => new DoctrineEntityInfo(
                'App\\Entity\\Pages\\HomePage',
                'lameco_websitebundle_homepages',
                [],
                [],
            ),
        ];
        $filters = new MigrationFilters(
            entities: ['NewsPage'],
            relationGraph: [
                'App\\Entity\\Pages\\NewsPage' => ['App\\Entity\\Employee'],
            ],
        );

        $filtered = $this->applyEntitiesFilter($tables, $filters, $entities);

        self::assertSame([
            'lameco_websitebundle_newspages',
            'lameco_websitebundle_employee_employees',
        ], $filtered);
    }

    /**
     * @param list<string> $tables
     * @param array<string, DoctrineEntityInfo> $entities
     * @return list<string>
     */
    private function applyEntitiesFilter(array $tables, MigrationFilters $filters, array $entities): array
    {
        $method = new ReflectionMethod(KunstmaanSchemaDumper::class, 'applyEntitiesFilter');

        /** @var list<string> $result */
        $result = $method->invoke(new KunstmaanSchemaDumper(), $tables, $filters, $entities);

        return $result;
    }
}
