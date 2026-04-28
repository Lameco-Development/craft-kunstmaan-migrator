<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\audit;

use lameco\kunstmaanmigrator\audit\PageRootedCoverageAuditor;
use PHPUnit\Framework\TestCase;

final class PageRootedCoverageAuditorTest extends TestCase
{
    public function testClassifiesPageRootedCoverageRowsDeterministically(): void
    {
        $rows = (new PageRootedCoverageAuditor())->audit(
            $this->discoveryRows(),
            $this->mapping(),
            $this->pageStructure(),
            ['Relation articles.category unsupported: missing stateSource'],
        );

        self::assertSame($rows, (new PageRootedCoverageAuditor())->audit(
            array_reverse($this->discoveryRows()),
            $this->mapping(),
            $this->pageStructure(),
            ['Relation articles.category unsupported: missing stateSource'],
        ));

        $byIdentifier = [];
        foreach ($rows as $row) {
            $byIdentifier[$row['surfaceType'] . '|' . $row['sourceIdentifier']] = $row;
            self::assertContains($row['category'], ['migrated', 'dropped', 'out_of_scope', 'unsupported', 'warning']);
            self::assertSame('App\\Entity\\ArticlePage', $row['pageFqcn']);
            self::assertSame('article_pages', $row['sourceTable']);
            self::assertSame('articles', $row['targetCraft']['section']);
            self::assertSame('articlePage', $row['targetCraft']['entryType']);
            self::assertArrayNotHasKey('samples', $row);
            self::assertStringNotContainsString('PRIVATE', json_encode($row, JSON_THROW_ON_ERROR));
        }

        self::assertSame('migrated', $byIdentifier['direct_field|article_pages.title']['category']);
        self::assertSame('dropped', $byIdentifier['direct_field|article_pages.legacyTeaser']['category']);
        self::assertSame('unsupported', $byIdentifier['many_to_many|categories']['category']);
        self::assertStringContainsString('missing stateSource', $byIdentifier['many_to_many|categories']['reason']);
        self::assertSame('out_of_scope', $byIdentifier['seo|seo:not-discovered']['category']);
        self::assertSame('out_of_scope', $byIdentifier['ckeditor_ref|ckeditor_ref:not-discovered']['category']);
    }

    public function testAcceptedAndDroppedMappingRowsDriveCoverageWhenDiscoveryHasWarnings(): void
    {
        $mapping = $this->mapping();
        $mapping['proposals'][] = [
            'kind' => 'column',
            'table' => 'article_pages',
            'column' => 'employee_id',
            'targetHandle' => 'employee',
            'handler' => '',
            'status' => 'accepted',
        ];

        $rows = (new PageRootedCoverageAuditor())->audit(
            [
                [
                    'pageFqcn' => 'App\\Entity\\ArticlePage',
                    'surfaceType' => 'direct_field',
                    'categoryHint' => 'warning',
                    'sourceIdentifier' => 'article_pages.title',
                    'sourceService' => 'compiled nodeClasses.fields',
                    'sourceTable' => 'article_pages',
                    'property' => 'title',
                ],
                [
                    'pageFqcn' => 'App\\Entity\\ArticlePage',
                    'surfaceType' => 'direct_field',
                    'categoryHint' => 'warning',
                    'sourceIdentifier' => 'article_pages.legacyTeaser',
                    'sourceService' => 'mapping proposals',
                    'sourceTable' => 'article_pages',
                    'property' => 'legacyTeaser',
                ],
                [
                    'pageFqcn' => 'App\\Entity\\ArticlePage',
                    'surfaceType' => 'direct_field',
                    'categoryHint' => 'warning',
                    'sourceIdentifier' => 'article_pages.employee_id',
                    'sourceService' => 'mapping proposals',
                    'sourceTable' => 'article_pages',
                    'property' => 'employee_id',
                ],
            ],
            $mapping,
            $this->pageStructure(),
        );

        $categories = array_column($rows, 'category', 'sourceIdentifier');
        self::assertSame('migrated', $categories['article_pages.title']);
        self::assertSame('dropped', $categories['article_pages.legacyTeaser']);
        self::assertSame('warning', $categories['article_pages.employee_id']);
    }

    /** @return list<array<string, mixed>> */
    private function discoveryRows(): array
    {
        return [
            [
                'pageFqcn' => 'App\\Entity\\ArticlePage',
                'surfaceType' => 'many_to_many',
                'categoryHint' => 'unsupported',
                'sourceIdentifier' => 'categories',
                'sourceService' => 'Doctrine relation metadata + MappingCompiler relation warnings/options',
                'property' => 'categories',
                'relationType' => 'ManyToMany',
            ],
            [
                'pageFqcn' => 'App\\Entity\\ArticlePage',
                'surfaceType' => 'direct_field',
                'categoryHint' => 'migrated',
                'sourceIdentifier' => 'article_pages.title',
                'sourceService' => 'mapping proposals',
                'sourceTable' => 'article_pages',
                'property' => 'title',
                'fieldHandle' => 'title',
            ],
            [
                'pageFqcn' => 'App\\Entity\\ArticlePage',
                'surfaceType' => 'direct_field',
                'categoryHint' => 'dropped',
                'sourceIdentifier' => 'article_pages.legacyTeaser',
                'sourceService' => 'mapping proposals',
                'sourceTable' => 'article_pages',
                'property' => 'legacyTeaser',
                'reason' => 'obsolete marketing copy',
            ],
            [
                'pageFqcn' => 'App\\Entity\\ArticlePage',
                'surfaceType' => 'seo',
                'categoryHint' => 'out_of_scope',
                'sourceIdentifier' => 'seo:not-discovered',
                'sourceService' => 'SeoMigrationService source lookup/adapters',
                'reason' => 'No structural discovery input provided for this surface.',
            ],
            [
                'pageFqcn' => 'App\\Entity\\ArticlePage',
                'surfaceType' => 'ckeditor_ref',
                'categoryHint' => 'out_of_scope',
                'sourceIdentifier' => 'ckeditor_ref:not-discovered',
                'sourceService' => 'CkeditorRewriterService token/media reference scanner',
                'reason' => 'No structural discovery input provided for this surface.',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function mapping(): array
    {
        return [
            'nodeClasses' => [
                'App\\Entity\\ArticlePage' => [
                    'sourceTable' => 'article_pages',
                    'section' => 'articles',
                    'entryType' => 'articlePage',
                ],
            ],
            'proposals' => [
                [
                    'kind' => 'nodeClass',
                    'fqcn' => 'App\\Entity\\ArticlePage',
                    'sourceTable' => 'article_pages',
                    'targetSection' => 'articles',
                    'targetEntryType' => 'articlePage',
                    'status' => 'accepted',
                ],
                [
                    'kind' => 'column',
                    'table' => 'article_pages',
                    'column' => 'title',
                    'targetHandle' => 'title',
                    'handler' => 'plain',
                    'status' => 'accepted',
                    'samples' => ['PRIVATE'],
                ],
                [
                    'kind' => 'column',
                    'table' => 'article_pages',
                    'column' => 'legacyTeaser',
                    'status' => 'dropped',
                    'rationale' => 'obsolete marketing copy',
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function pageStructure(): array
    {
        return [
            'App\\Entity\\ArticlePage' => [
                'tableName' => 'article_pages',
            ],
        ];
    }
}
