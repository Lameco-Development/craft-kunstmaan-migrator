<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\transform;

use lameco\kunstmaanmigrator\db\LegacyDbService;
use lameco\kunstmaanmigrator\fields\FieldHandlerRegistry;
use lameco\kunstmaanmigrator\fields\handlers\PlainTextHandler;
use lameco\kunstmaanmigrator\filter\MigrationFilters;
use lameco\kunstmaanmigrator\finalize\CkeditorRewriterService;
use lameco\kunstmaanmigrator\transform\TransformService;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/integration/load/_craft_shim.php';

final class TransformServicePromotedTargetsTest extends TestCase
{
    public function testPromotedTargetTransformsUnderOwnStateSource(): void
    {
        $service = $this->service();
        $rows = [
            [
                'kind' => 'promotedTarget',
                'fqcn' => 'App\\Entity\\Employee',
                'stateSource' => 'employee',
                'stateKey' => 12,
                'refIdsByLocale' => ['nl' => 12],
                'promotedTarget' => [
                    'stateSource' => 'employee',
                    'sourceRef' => 'kunstmaan.entity:App\\Entity\\Employee',
                    'targetRef' => 'craft.entryType:teamMember',
                    'targetSection' => 'team',
                    'targetEntryType' => 'teamMember',
                    'relationIntent' => 'promote',
                    'fields' => [
                        'jobTitle' => ['source' => 'function', 'handler' => 'plain'],
                    ],
                ],
                'perSite' => [
                    'nl' => [
                        'online' => true,
                        'title' => 'Jane',
                        'slug' => 'jane',
                        'detail' => ['id' => 12, 'name' => 'Jane', 'function' => 'CEO'],
                        'pageParts' => [],
                    ],
                ],
            ],
        ];

        $payloads = iterator_to_array($service->run($rows, ['sites' => ['nl' => 'default']], new MigrationFilters()));
        $payload = $payloads[0];

        self::assertSame('promotedTarget', $payload['kind']);
        self::assertSame('employee', $payload['stateSource']);
        self::assertSame('12', $payload['stateKey']);
        self::assertSame('team', $payload['section']);
        self::assertSame('teamMember', $payload['entryType']);
        self::assertSame('CEO', $payload['perSite']['default']['fieldValues']['jobTitle']);
        self::assertSame('promote', $payload['relationIntent']);
    }

    public function testOwnerReferenceKeepsRawEmployeeIdSemantics(): void
    {
        $service = $this->service();
        $rows = [
            [
                'fqcn' => 'App\\Entity\\Pages\\NewsPage',
                'kunstmaanSourceId' => 'App_Entity_Pages_NewsPage:97',
                'kuma_node_id' => 97,
                'refIdsByLocale' => ['nl' => 97],
                'perSite' => [
                    'nl' => [
                        'online' => true,
                        'title' => 'News',
                        'slug' => 'news',
                        'detail' => ['id' => 97, 'employee_id' => 12],
                        'pageParts' => [],
                    ],
                ],
            ],
        ];
        $mapping = [
            'sites' => ['nl' => 'default'],
            'nodeClasses' => [
                'App\\Entity\\Pages\\NewsPage' => [
                    'section' => 'news',
                    'fields' => ['employeeRelation' => ['source' => 'employee_id', 'handler' => 'plain']],
                ],
            ],
            'sections' => ['news' => ['section' => 'news', 'entryType' => 'newsPage']],
        ];

        $payloads = iterator_to_array($service->run($rows, $mapping, new MigrationFilters()));
        $payload = $payloads[0];

        self::assertSame('12', $payload['perSite']['default']['fieldValues']['employeeRelation']);
        self::assertArrayNotHasKey('_rel:employee.name', $payload['perSite']['default']['fieldValues']);
    }

    public function testPageRootedMergeRelationFlattensRelatedEntityIntoOwnerEntry(): void
    {
        $service = $this->service();
        $service->legacyDb = new class extends LegacyDbService {
            public function queryOne(string $sql, array $params = []): ?array
            {
                TestCase::assertStringContainsString('lameco_websitebundle_employee_employees', $sql);
                TestCase::assertSame([':pk' => 31], $params);

                return [
                    'id' => 31,
                    'real_name' => 'Bram Kranenburg',
                    'job_title' => 'Principal Consultant',
                    'email' => 'kranenburg@cqm.nl',
                ];
            }
        };

        $rows = [
            [
                'fqcn' => 'App\\Entity\\Pages\\EmployeePage',
                'kunstmaanSourceId' => 'App_Entity_Pages_EmployeePage:76',
                'kuma_node_id' => 76,
                'refIdsByLocale' => ['nl' => 76],
                'perSite' => [
                    'nl' => [
                        'online' => true,
                        'title' => 'Bram Kranenburg',
                        'slug' => 'bram-kranenburg',
                        'detail' => ['id' => 76, 'employee_id' => 31],
                        'pageParts' => [],
                    ],
                ],
            ],
        ];
        $mapping = [
            'sites' => ['nl' => 'default'],
            'nodeClasses' => [
                'App\\Entity\\Pages\\EmployeePage' => [
                    'section' => 'teamMember',
                    'mergeRelations' => [
                        'employee' => [
                            'mode' => 'flatten',
                            'table' => 'lameco_websitebundle_employee_employees',
                            'fk' => 'employee_id',
                            'pk' => 'id',
                        ],
                    ],
                    'fields' => [
                        'firstName' => ['source' => '_rel:employee.real_name', 'handler' => 'plain'],
                        'role' => ['source' => '_rel:employee.job_title', 'handler' => 'plain'],
                        'linkEmail' => ['source' => '_rel:employee.email', 'handler' => 'plain'],
                    ],
                ],
            ],
            'sections' => [
                'teamMember' => ['section' => 'teamMembers', 'entryType' => 'teamMember'],
            ],
        ];

        $payloads = iterator_to_array($service->run($rows, $mapping, new MigrationFilters()));
        $fields = $payloads[0]['perSite']['default']['fieldValues'];

        self::assertSame('Bram Kranenburg', $fields['firstName']);
        self::assertSame('Principal Consultant', $fields['role']);
        self::assertSame('kranenburg@cqm.nl', $fields['linkEmail']);
    }

    private function service(): TransformService
    {
        $registry = new FieldHandlerRegistry();
        $registry->register(new PlainTextHandler('plain'));

        $service = new TransformService();
        $service->handlerRegistry = $registry;
        $service->ckeditorRewriter = new CkeditorRewriterService();

        return $service;
    }
}
