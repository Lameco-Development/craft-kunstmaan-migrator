<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\audit;

use lameco\kunstmaanmigrator\audit\PageRootedSurfaceDiscovery;
use lameco\kunstmaanmigrator\tests\support\GraphFixtureFactory;
use PHPUnit\Framework\TestCase;

final class PageRootedSurfaceDiscoveryGraphRelationsTest extends TestCase
{
    public function testGraphRelationsBecomeEvidenceRows(): void
    {
        $rows = (new PageRootedSurfaceDiscovery())->discover(
            $this->mapping(),
            [],
            [],
            [],
            GraphFixtureFactory::kunstmaanNewsEmployeeGraph(),
        );

        $relationRows = array_values(array_filter($rows, static fn(array $row): bool => ($row['surfaceType'] ?? '') === 'relation'));

        self::assertCount(1, $relationRows);
        self::assertSame('kunstmaanGraph relation evidence', $relationRows[0]['sourceService']);
        self::assertStringContainsString('relation.unresolved', $relationRows[0]['reason']);
        self::assertSame('kunstmaan.entity:App\\Entity\\Employee', $relationRows[0]['targetRef']);
    }

    public function testGraphAbsenceDoesNotCreateNotDiscoveredSyntheticRelationBlockers(): void
    {
        $rows = (new PageRootedSurfaceDiscovery())->discover($this->mapping(), [], [], [], []);

        $identifiers = array_map(static fn(array $row): string => (string) ($row['sourceIdentifier'] ?? ''), $rows);

        self::assertNotContains('relation:not-discovered', $identifiers, 'not-discovered relation rows must not be synthesized without graph evidence');
    }

    private function mapping(): array
    {
        return [
            'nodeClasses' => [
                'App\\Entity\\Pages\\NewsPage' => [
                    'sourceTable' => 'news_pages',
                    'section' => 'news',
                    'entryType' => 'newsPage',
                ],
            ],
        ];
    }
}
