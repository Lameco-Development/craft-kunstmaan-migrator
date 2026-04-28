<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\transform;

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
