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

/**
 * Flat-row transform contract: AbstractConfig envelopes (no kuma_node) key
 * their migration state and payload filename on the source row's primary key,
 * and propagate null title/slug so an earlier contributor's values survive
 * on the merged globalSettings Single.
 */
final class TransformServiceFlatRowTest extends TestCase
{
    public function testFlatRowPayloadUsesRefIdStateKeyAndNullTitleSlug(): void
    {
        $registry = new FieldHandlerRegistry();
        $registry->register(new PlainTextHandler('plain'));
        $service = new TransformService();
        $service->handlerRegistry = $registry;
        $service->ckeditorRewriter = new CkeditorRewriterService();

        $rows = [
            [
                'fqcn' => 'App\\Entity\\Configuration',
                'kunstmaanSourceId' => 'App_Entity_Configuration:7',
                'kuma_node_id' => 0,
                'kuma_parent_id' => null,
                'ref_id' => 7,
                'flatRow' => true,
                'refIdsByLocale' => ['nl' => 7],
                'perSite' => [
                    'nl' => [
                        'online' => true,
                        'title' => null,
                        'slug' => null,
                        'refId' => 7,
                        'detail' => ['id' => 7, 'phone' => '013-1234567'],
                        'pageParts' => [],
                    ],
                ],
            ],
        ];
        $mapping = [
            'sites' => ['nl' => 'default'],
            'nodeClasses' => [
                'App\\Entity\\Configuration' => [
                    'sourceTable' => 'app_configuration',
                    'flatRow' => true,
                    'section' => 'globalSettings',
                    'fields' => [
                        'phoneNumber' => ['source' => 'phone', 'handler' => 'plain'],
                    ],
                ],
            ],
            'sections' => [
                'globalSettings' => ['section' => 'globalSettings', 'entryType' => 'globalSettings'],
            ],
        ];

        $payloads = iterator_to_array($service->run($rows, $mapping, new MigrationFilters()));
        $payload = $payloads[0];

        self::assertTrue($payload['flatRow']);
        self::assertSame(7, $payload['ref_id']);
        self::assertSame(0, $payload['kuma_node_id']);
        // State key falls back to the source row's primary key so re-runs
        // find the same migration_state record.
        self::assertSame(7, $payload['stateKey']);
        self::assertSame('App_Entity_Configuration', $payload['stateSource']);

        $site = $payload['perSite']['default'];
        self::assertNull($site['title']);
        self::assertNull($site['slug']);
        self::assertSame('013-1234567', $site['fieldValues']['phoneNumber']);
    }
}
