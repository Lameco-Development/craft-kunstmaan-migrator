<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\source;

use lameco\kunstmaanmigrator\source\CraftGraphContract;
use lameco\kunstmaanmigrator\source\KunstmaanGraphContract;
use lameco\kunstmaanmigrator\tests\support\GraphFixtureFactory;
use PHPUnit\Framework\TestCase;

final class GraphContractTest extends TestCase
{
    public function testKunstmaanContractDefinesVersionKeysIntentsAndRefs(): void
    {
        self::assertSame('kunstmaan-page-graph-v1', KunstmaanGraphContract::GRAPH_VERSION);
        self::assertSame('graphVersion', KunstmaanGraphContract::KEY_GRAPH_VERSION);
        self::assertSame('roots', KunstmaanGraphContract::KEY_ROOTS);
        self::assertSame('entities', KunstmaanGraphContract::KEY_ENTITIES);
        self::assertSame('relations', KunstmaanGraphContract::KEY_RELATIONS);
        self::assertSame('pageparts', KunstmaanGraphContract::KEY_PAGEPARTS);
        self::assertSame('pagepartUsages', KunstmaanGraphContract::KEY_PAGEPART_USAGES);
        self::assertSame('assets', KunstmaanGraphContract::KEY_ASSETS);
        self::assertSame('tables', KunstmaanGraphContract::KEY_TABLES);
        self::assertSame('samples', KunstmaanGraphContract::KEY_SAMPLES);
        self::assertSame('constraints', KunstmaanGraphContract::KEY_CONSTRAINTS);

        self::assertSame('reference', KunstmaanGraphContract::INTENT_REFERENCE);
        self::assertSame('promote', KunstmaanGraphContract::INTENT_PROMOTE);
        self::assertSame('embed', KunstmaanGraphContract::INTENT_EMBED);
        self::assertSame('drop', KunstmaanGraphContract::INTENT_DROP);
        self::assertSame('out_of_scope', KunstmaanGraphContract::INTENT_OUT_OF_SCOPE);

        self::assertSame(
            'kunstmaan.page:App\\Entity\\Pages\\ArticlePage',
            KunstmaanGraphContract::pageRootRef('\\App\\Entity\\Pages\\ArticlePage'),
        );
        self::assertSame(
            'kunstmaan.entity:App\\Entity\\Employee',
            KunstmaanGraphContract::entityRef('App\\Entity\\Employee'),
        );
        self::assertSame(
            'kunstmaan.pagepart:Kunstmaan\\PagePartBundle\\Entity\\TextPagePart',
            KunstmaanGraphContract::pagepartRef('Kunstmaan\\PagePartBundle\\Entity\\TextPagePart'),
        );
    }

    public function testCraftContractDefinesVersionKeysAndRefs(): void
    {
        self::assertSame('craft-entry-graph-v1', CraftGraphContract::GRAPH_VERSION);
        self::assertSame('graphVersion', CraftGraphContract::KEY_GRAPH_VERSION);
        self::assertSame('roots', CraftGraphContract::KEY_ROOTS);
        self::assertSame('entryTypes', CraftGraphContract::KEY_ENTRY_TYPES);
        self::assertSame('fields', CraftGraphContract::KEY_FIELDS);
        self::assertSame('matrixBlockTypes', CraftGraphContract::KEY_MATRIX_BLOCK_TYPES);
        self::assertSame('matrixUsages', CraftGraphContract::KEY_MATRIX_USAGES);
        self::assertSame('relationTargets', CraftGraphContract::KEY_RELATION_TARGETS);
        self::assertSame('assetVolumes', CraftGraphContract::KEY_ASSET_VOLUMES);
        self::assertSame('constraints', CraftGraphContract::KEY_CONSTRAINTS);

        self::assertSame('craft.entryType:newsPage', CraftGraphContract::craftEntryTypeRef('newsPage'));
        self::assertSame('craft.field:newsPage.image', CraftGraphContract::craftFieldRef('newsPage', 'image'));
        self::assertSame(
            'craft.matrixBlock:pageBuilder:textContentBlock',
            CraftGraphContract::matrixBlockRef('pageBuilder', 'textContentBlock'),
        );
    }

    public function testNewsEmployeeFixtureIsNormalizedWithInboundOwnerEvidence(): void
    {
        $graph = GraphFixtureFactory::kunstmaanNewsEmployeeGraph();
        $employeeRef = KunstmaanGraphContract::entityRef('App\\Entity\\Employee');

        self::assertSame(KunstmaanGraphContract::GRAPH_VERSION, $graph[KunstmaanGraphContract::KEY_GRAPH_VERSION]);
        self::assertArrayHasKey($employeeRef, $graph[KunstmaanGraphContract::KEY_ENTITIES]);
        self::assertCount(1, $graph[KunstmaanGraphContract::KEY_ENTITIES][$employeeRef]['inboundOwners']);
        self::assertSame(
            'employee_id',
            $graph[KunstmaanGraphContract::KEY_ENTITIES][$employeeRef]['inboundOwners'][0]['fkColumn'],
        );
        self::assertArrayHasKey(
            KunstmaanGraphContract::pageRootRef('App\\Entity\\Pages\\NewsPage') . '.employee_id',
            $graph[KunstmaanGraphContract::KEY_SAMPLES],
        );
    }

    public function testHomePagepartFixtureUsesPagepartUsages(): void
    {
        $graph = GraphFixtureFactory::kunstmaanHomePagepartGraph();

        self::assertSame(KunstmaanGraphContract::GRAPH_VERSION, $graph[KunstmaanGraphContract::KEY_GRAPH_VERSION]);
        self::assertNotEmpty($graph[KunstmaanGraphContract::KEY_PAGEPARTS]);
        self::assertNotEmpty($graph[KunstmaanGraphContract::KEY_PAGEPART_USAGES]);
        self::assertSame(
            'main',
            array_values($graph[KunstmaanGraphContract::KEY_PAGEPART_USAGES])[0]['context'],
        );
    }

    public function testCraftFixtureUsesMatrixUsagesRelationTargetsAndAssetVolumes(): void
    {
        $graph = GraphFixtureFactory::craftNewsHomeGraph();

        self::assertSame(CraftGraphContract::GRAPH_VERSION, $graph[CraftGraphContract::KEY_GRAPH_VERSION]);
        self::assertArrayHasKey(CraftGraphContract::craftEntryTypeRef('newsPage'), $graph[CraftGraphContract::KEY_ROOTS]);
        self::assertNotEmpty($graph[CraftGraphContract::KEY_MATRIX_USAGES]);
        self::assertNotEmpty($graph[CraftGraphContract::KEY_RELATION_TARGETS]);
        self::assertNotEmpty($graph[CraftGraphContract::KEY_ASSET_VOLUMES]);
        self::assertArrayHasKey(
            CraftGraphContract::matrixBlockRef('pageBuilder', 'textContentBlock'),
            $graph[CraftGraphContract::KEY_MATRIX_BLOCK_TYPES],
        );
    }
}
