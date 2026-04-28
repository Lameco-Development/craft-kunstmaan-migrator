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

        self::assertSame('warning', $byType['asset'][0]['categoryHint']);
        self::assertSame('out_of_scope', $byType['seo'][0]['categoryHint']);
        self::assertSame('out_of_scope', $byType['redirect'][0]['categoryHint']);
        self::assertSame('warning', $byType['ckeditor_ref'][0]['categoryHint']);
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
