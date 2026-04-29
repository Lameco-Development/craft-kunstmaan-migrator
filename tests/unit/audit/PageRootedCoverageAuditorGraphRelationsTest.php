<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\audit;

use lameco\kunstmaanmigrator\audit\PageRootedCoverageAuditor;
use lameco\kunstmaanmigrator\audit\PageRootedSurfaceDiscovery;
use lameco\kunstmaanmigrator\load\MigrationReport;
use lameco\kunstmaanmigrator\tests\support\GraphFixtureFactory;
use PHPUnit\Framework\TestCase;

final class PageRootedCoverageAuditorGraphRelationsTest extends TestCase
{
    public function testRelationEvidenceWithoutIntentIsBlockingUnresolved(): void
    {
        $rows = $this->coverageRows([]);

        $relation = $this->firstRelation($rows);

        self::assertSame('warning', $relation['category']);
        self::assertStringContainsString('relation.unresolved', $relation['reason']);

        $report = new MigrationReport();
        $report->recordRelationCoverage($rows);
        self::assertSame(1, $report->counts['relation.unresolved']);
    }

    public function testDropOutOfScopeAndPromoteIntentsAreClassifiedSeparately(): void
    {
        foreach ([
            'drop' => ['category' => 'dropped', 'bucket' => 'relation.intent.drop'],
            'out_of_scope' => ['category' => 'out_of_scope', 'bucket' => 'relation.intent.out_of_scope'],
            'promote' => ['category' => 'migrated', 'bucket' => 'relation.promoted'],
        ] as $intent => $expect) {
            $rows = $this->coverageRows([
                [
                    'kind' => 'promotedTarget',
                    'status' => 'accepted',
                    'sourceRef' => 'kunstmaan.entity:App\\Entity\\Employee',
                    'targetRef' => 'craft.entryType:teamMember',
                    'relationIntent' => $intent,
                ],
            ]);
            $relation = $this->firstRelation($rows);
            self::assertSame($expect['category'], $relation['category']);
            self::assertStringContainsString((string) $expect['bucket'], $relation['reason']);

            $report = new MigrationReport();
            $report->recordRelationCoverage($rows);
            self::assertSame(1, $report->counts[$expect['bucket']]);
        }
    }

    /** @param list<array<string, mixed>> $proposals */
    private function coverageRows(array $proposals): array
    {
        $mapping = [
            'nodeClasses' => [
                'App\\Entity\\Pages\\NewsPage' => [
                    'sourceTable' => 'news_pages',
                    'section' => 'news',
                    'entryType' => 'newsPage',
                ],
            ],
            'proposals' => $proposals,
        ];

        $discovery = (new PageRootedSurfaceDiscovery())->discover(
            $mapping,
            [],
            [],
            [],
            GraphFixtureFactory::kunstmaanNewsEmployeeGraph(),
        );

        return (new PageRootedCoverageAuditor())->audit($discovery, $mapping);
    }

    /** @param list<array<string, mixed>> $rows */
    private function firstRelation(array $rows): array
    {
        foreach ($rows as $row) {
            if (($row['surfaceType'] ?? '') === 'relation') {
                return $row;
            }
        }
        self::fail('Expected a relation coverage row.');
    }
}
