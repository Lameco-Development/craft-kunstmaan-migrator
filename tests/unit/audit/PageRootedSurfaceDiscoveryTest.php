<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\audit;

use lameco\kunstmaanmigrator\audit\PageRootedSurfaceDiscovery;
use PHPUnit\Framework\TestCase;

final class PageRootedSurfaceDiscoveryTest extends TestCase
{
    public function testDiscoversEveryPageOwnedSurfaceWithStructuralMetadataOnly(): void
    {
        $rows = (new PageRootedSurfaceDiscovery())->discover(
            $this->mapping(),
            $this->pageStructure(),
            [
                'App\\Entity\\ArticlePage' => [
                    [
                        'property' => 'author',
                        'type' => 'ManyToOne',
                        'targetEntity' => 'App\\Entity\\Author',
                        'joinColumn' => 'author_id',
                        'sourceTable' => 'article_pages',
                    ],
                    [
                        'property' => 'categories',
                        'type' => 'ManyToMany',
                        'targetEntity' => 'App\\Entity\\Category',
                        'joinTable' => 'article_category',
                    ],
                    [
                        'property' => 'slides',
                        'type' => 'OneToMany',
                        'targetEntity' => 'App\\Entity\\Slide',
                        'mappedBy' => 'page',
                    ],
                ],
            ],
            [
                'assets' => [
                    ['pageFqcn' => 'App\\Entity\\ArticlePage', 'fieldHandle' => 'heroImage', 'assetId' => 42],
                ],
                'seo' => [
                    ['pageFqcn' => 'App\\Entity\\ArticlePage', 'adapter' => 'SeoMigrationService', 'table' => 'kuma_seo'],
                ],
                'redirects' => [
                    ['pageFqcn' => 'App\\Entity\\ArticlePage', 'adapter' => 'RedirectMigrationService', 'table' => 'kuma_redirects'],
                ],
                'ckeditorRefs' => [
                    ['pageFqcn' => 'App\\Entity\\ArticlePage', 'tokenType' => '[M]', 'assetId' => 99],
                    ['pageFqcn' => 'App\\Entity\\ArticlePage', 'tokenType' => '[NT]', 'nodeTranslationId' => 100],
                    ['pageFqcn' => 'App\\Entity\\ArticlePage', 'tokenType' => 'media-url', 'pathKind' => 'uploads-media'],
                ],
            ],
        );

        $types = array_values(array_unique(array_column($rows, 'surfaceType')));
        sort($types);

        self::assertSame([
            'asset',
            'ckeditor_ref',
            'direct_field',
            'implicit_content',
            'many_to_many',
            'many_to_one',
            'one_to_many',
            'pagepart',
            'redirect',
            'seo',
            'taxonomy_dataprovider',
        ], $types);

        foreach ($rows as $row) {
            self::assertSame('App\\Entity\\ArticlePage', $row['pageFqcn']);
            self::assertArrayHasKey('sourceService', $row);
            self::assertArrayHasKey('sourceIdentifier', $row);
            self::assertArrayNotHasKey('samples', $row);
            self::assertStringNotContainsString('<p>PRIVATE BODY</p>', json_encode($row, JSON_THROW_ON_ERROR));
        }
    }

    public function testMissingOptionalServiceInputsCreateExplicitClassifications(): void
    {
        $rows = (new PageRootedSurfaceDiscovery())->discover($this->mapping(), $this->pageStructure());

        $byType = [];
        foreach ($rows as $row) {
            $byType[$row['surfaceType']][] = $row;
        }

        self::assertArrayNotHasKey('asset', $byType);
        self::assertArrayNotHasKey('ckeditor_ref', $byType);
        self::assertArrayNotHasKey('many_to_one', $byType);
        self::assertArrayNotHasKey('many_to_many', $byType);
        self::assertArrayNotHasKey('one_to_many', $byType);
        self::assertArrayNotHasKey('seo', $byType);
        self::assertArrayNotHasKey('redirect', $byType);
    }

    public function testFkAndAssetColumnsWithEmptyMappingRemainEvidenceBackedWarnings(): void
    {
        $mapping = [
            'nodeClasses' => [
                'App\\Entity\\GenericPage' => [
                    'sourceTable' => 'generic_pages',
                    'section' => 'generic',
                    'entryType' => 'genericPage',
                    'fields' => [],
                ],
            ],
            'proposals' => [
                [
                    'kind' => 'nodeClass',
                    'fqcn' => 'App\\Entity\\GenericPage',
                    'sourceTable' => 'generic_pages',
                    'targetSection' => 'generic',
                    'targetEntryType' => 'genericPage',
                    'status' => 'accepted',
                ],
                [
                    'kind' => 'column',
                    'table' => 'generic_pages',
                    'column' => 'employee_id',
                    'targetEntryType' => 'genericPage',
                    'targetHandle' => '',
                    'handler' => '',
                    'status' => 'accepted',
                    'rationale' => 'FK relation evidence from source detail row.',
                ],
                [
                    'kind' => 'column',
                    'table' => 'generic_pages',
                    'column' => 'image_id',
                    'targetEntryType' => 'genericPage',
                    'targetHandle' => '',
                    'handler' => '',
                    'status' => 'accepted',
                    'rationale' => 'FK asset evidence from source detail row.',
                ],
            ],
        ];

        $rows = (new PageRootedSurfaceDiscovery())->discover($mapping, ['App\\Entity\\GenericPage' => ['tableName' => 'generic_pages']]);
        $byId = array_column($rows, null, 'sourceIdentifier');

        self::assertSame('warning', $byId['generic_pages.employee_id']['categoryHint']);
        self::assertSame('warning', $byId['generic_pages.image_id']['categoryHint']);
        self::assertStringContainsString('Evidence-backed source column is not mapped', $byId['generic_pages.employee_id']['reason']);
    }

    public function testTaxonomyAndDataProviderRowsRequirePageOwnedEvidence(): void
    {
        $mapping = $this->mapping();
        $mapping['nodeClasses']['App\\Entity\\OtherPage'] = [
            'sourceTable' => 'other_pages',
            'section' => 'others',
            'fields' => [],
        ];
        $mapping['proposals'][] = [
            'kind' => 'nodeClass',
            'fqcn' => 'App\\Entity\\OtherPage',
            'sourceTable' => 'other_pages',
            'targetSection' => 'others',
            'targetEntryType' => 'otherPage',
            'status' => 'accepted',
        ];

        $rows = (new PageRootedSurfaceDiscovery())->discover(
            $mapping,
            $this->pageStructure() + ['App\\Entity\\OtherPage' => ['tableName' => 'other_pages']],
            [
                'App\\Entity\\ArticlePage' => [
                    ['type' => 'ManyToOne', 'property' => 'category', 'targetEntity' => 'App\\Entity\\Category'],
                ],
            ],
        );

        $taxonomyRows = array_values(array_filter(
            $rows,
            static fn(array $row): bool => ($row['surfaceType'] ?? '') === 'taxonomy_dataprovider',
        ));

        self::assertCount(1, $taxonomyRows);
        self::assertSame('App\\Entity\\ArticlePage', $taxonomyRows[0]['pageFqcn']);
        self::assertSame('App\\Entity\\Category', $taxonomyRows[0]['sourceIdentifier']);
    }

    /** @return array<string, mixed> */
    private function mapping(): array
    {
        return [
            'nodeClasses' => [
                'App\\Entity\\ArticlePage' => [
                    'sourceTable' => 'article_pages',
                    'section' => 'articles',
                    'fields' => [
                        'title' => ['source' => 'title', 'handler' => 'plain'],
                        'body' => ['source' => 'body', 'handler' => 'ckeditor', 'sample' => '<p>PRIVATE BODY</p>'],
                    ],
                ],
            ],
            'pageParts' => [
                'App\\Entity\\TextPagePart' => ['target' => 'textBlock'],
                '__implicit_content__|ArticlePage|main' => ['target' => 'bodyBlock'],
            ],
            'taxonomies' => [
                'App\\Entity\\Category' => ['sourceTable' => 'categories'],
            ],
            'dataProviders' => [
                'App\\Provider\\FeaturedProvider' => ['target' => 'featured'],
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
                    'samples' => ['secret title'],
                ],
                [
                    'kind' => 'pagePart',
                    'pagePartClass' => 'App\\Entity\\TextPagePart',
                    'parentPageClass' => 'ArticlePage',
                    'context' => 'main',
                    'status' => 'accepted',
                ],
                [
                    'kind' => 'taxonomy',
                    'fqcn' => 'App\\Entity\\Category',
                    'sourceTable' => 'categories',
                    'status' => 'accepted',
                ],
                [
                    'kind' => 'dataProvider',
                    'fqcn' => 'App\\Provider\\FeaturedProvider',
                    'sourceTable' => 'featured_items',
                    'status' => 'dropped',
                    'rationale' => 'operator excluded',
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
                'contexts' => [
                    [
                        'name' => 'main',
                        'allowedPagePartClasses' => [
                            ['class' => 'App\\Entity\\TextPagePart', 'table' => 'text_page_parts'],
                        ],
                    ],
                ],
            ],
        ];
    }
}
