<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\integration\transform;

use lameco\kunstmaanmigrator\fields\FieldHandlerRegistry;
use lameco\kunstmaanmigrator\fields\handlers\AssetHandler;
use lameco\kunstmaanmigrator\fields\handlers\MatrixHandler;
use lameco\kunstmaanmigrator\fields\handlers\PlainTextHandler;
use lameco\kunstmaanmigrator\fields\handlers\RelationHandler;
use lameco\kunstmaanmigrator\fields\handlers\SplitNameHandler;
use lameco\kunstmaanmigrator\filter\MigrationFilters;
use lameco\kunstmaanmigrator\finalize\CkeditorRewriterService;
use lameco\kunstmaanmigrator\transform\TransformService;
use PHPUnit\Framework\TestCase;

/**
 * Phase 7 — end-to-end loop closure for the implicit-content pipeline.
 *
 * Drives TransformService::run() with source-faithful extracted data (no real
 * page-parts) plus an implicit-content mapping shaped exactly as
 * MappingCompiler::compileImplicitBlocks() emits. Asserts transform-time
 * synthesis still emits a Matrix block with the right type + _sourcePartRef.
 */
final class TransformImplicitContentTest extends TestCase
{
    public function testSyntheticImplicitPagePartProducesMatrixBlock(): void
    {
        $fqcn = 'App\\Entity\\Pages\\HomePage';
        $implicitKey = '__implicit_content__|HomePage|main';

        $mapping = [
            'sites' => ['nl' => 'default'],
            'sections' => [
                'homePage' => ['entryType' => 'homePage', 'section' => 'homePages'],
            ],
            'nodeClasses' => [
                $fqcn => [
                    'sourceTable' => 'home_pages',
                    'section' => 'homePage',
                    'fields' => [],
                    'pageBuilderHandle' => 'pageBuilder',
                    'pageBuilderContexts' => ['main'],
                    'bodyColumn' => '',
                    'headerBlock' => null,
                    'bodyWrapBlock' => null,
                    'joins' => [],
                ],
            ],
            'pageParts' => [
                $implicitKey => [
                    'target' => 'textContentBlock',
                    'fields' => [
                        'html' => ['source' => 'content', 'handler' => 'plain'],
                    ],
                ],
            ],
        ];

        // Source-faithful extracted shape: the page has no real Kunstmaan
        // page-parts, but transform still synthesizes implicit content from
        // the page detail row when mapping.pageParts declares it.
        $detail = ['id' => 42, 'content' => '<p>Welcome to the homepage.</p>'];
        $extracted = [
            'kunstmaanSourceId' => 'App_Entity_Pages_HomePage:42',
            'fqcn' => $fqcn,
            'kuma_node_id' => 100,
            'kuma_parent_id' => null,
            'ref_id' => 42,
            'refIdsByLocale' => ['nl' => 42],
            'sourceTable' => 'home_pages',
            'perSite' => [
                'nl' => [
                    'online' => true,
                    'title' => 'Home',
                    'slug' => 'home',
                    'url' => '/',
                    'refId' => 42,
                    'detail' => $detail,
                    'pageParts' => [],
                ],
            ],
        ];

        $payloads = [];
        foreach ($this->makeService()->run([$extracted], $mapping, new MigrationFilters(), []) as $yielded) {
            if (!is_array($yielded) || array_key_exists('__report', $yielded)) {
                continue;
            }
            $payloads[] = $yielded;
        }

        $this->assertCount(1, $payloads);

        // perSite is keyed by Craft site handle (mapping.sites['nl'] => 'default'),
        // not the legacy locale code.
        $perSite = (array) ($payloads[0]['perSite'] ?? []);
        $this->assertArrayHasKey('default', $perSite, 'Expected default-site payload: ' . json_encode($payloads[0]));
        $fieldValues = (array) ($perSite['default']['fieldValues'] ?? []);
        $this->assertNotEmpty($fieldValues, 'Expected non-empty fieldValues: ' . json_encode($perSite['default']));

        $this->assertArrayHasKey('pageBuilder', $fieldValues);
        $blocks = (array) $fieldValues['pageBuilder'];
        $this->assertCount(1, $blocks);

        $block = (array) reset($blocks);
        $this->assertSame('textContentBlock', $block['type']);
        $this->assertTrue($block['enabled']);
        $this->assertSame('Home', $block['title']);
        $this->assertSame('<p>Welcome to the homepage.</p>', $block['fields']['html']);
        $this->assertStringContainsString(
            '__implicit_content__|HomePage|main:42',
            (string) $block['fields']['_sourcePartRef'],
        );
    }

    public function testSyntheticImplicitPagePartDoesNotDuplicateTitleWhenHeaderBlockOwnsHeading(): void
    {
        $fqcn = 'App\\Entity\\Pages\\NewsPage';
        $implicitKey = '__implicit_content__|NewsPage|main';

        $mapping = [
            'sites' => ['nl' => 'default'],
            'sections' => [
                'newsPage' => ['entryType' => 'newsPage', 'section' => 'newsPages'],
            ],
            'nodeClasses' => [
                $fqcn => [
                    'sourceTable' => 'news_pages',
                    'section' => 'newsPage',
                    'fields' => [],
                    'pageBuilderHandle' => 'pageBuilderCondensed',
                    'pageBuilderContexts' => ['main'],
                    'bodyColumn' => '',
                    'headerBlock' => [
                        'fieldHandle' => 'header',
                        'blockType' => 'headerDefault',
                        'title' => '{title}',
                        'fields' => [
                            'header.ckeditorDefault' => ['source' => 'summary', 'handler' => 'plain'],
                        ],
                    ],
                    'bodyWrapBlock' => null,
                    'joins' => [],
                ],
            ],
            'pageParts' => [
                $implicitKey => [
                    'target' => 'textContentBlock',
                    'fields' => [
                        'html' => ['source' => 'content', 'handler' => 'plain'],
                    ],
                ],
            ],
        ];

        $extracted = [
            'kunstmaanSourceId' => 'App_Entity_Pages_NewsPage:78',
            'fqcn' => $fqcn,
            'kuma_node_id' => 97,
            'kuma_parent_id' => null,
            'ref_id' => 78,
            'refIdsByLocale' => ['nl' => 78],
            'sourceTable' => 'news_pages',
            'perSite' => [
                'nl' => [
                    'online' => true,
                    'title' => 'News title',
                    'slug' => 'news-title',
                    'url' => '/news/news-title',
                    'refId' => 78,
                    'detail' => [
                        'id' => 78,
                        'title' => 'News title',
                        'summary' => 'Summary',
                        'content' => '<p>Article body.</p>',
                    ],
                    'pageParts' => [],
                ],
            ],
        ];

        $payloads = [];
        foreach ($this->makeService()->run([$extracted], $mapping, new MigrationFilters(), []) as $yielded) {
            if (!is_array($yielded) || array_key_exists('__report', $yielded)) {
                continue;
            }
            $payloads[] = $yielded;
        }

        $fieldValues = (array) ($payloads[0]['perSite']['default']['fieldValues'] ?? []);
        $headerBlocks = (array) $fieldValues['header'];
        $headerBlock = (array) reset($headerBlocks);
        self::assertSame('News title', $headerBlock['title']);
        self::assertSame('Summary', $headerBlock['fields']['ckeditorDefault']);
        self::assertArrayNotHasKey('header.ckeditorDefault', $headerBlock['fields']);

        $bodyBlocks = (array) $fieldValues['pageBuilderCondensed'];
        $bodyBlock = (array) reset($bodyBlocks);
        self::assertArrayNotHasKey('title', $bodyBlock);
        self::assertTrue($bodyBlock['fields']['_suppressNativeTitleFallback']);
        self::assertSame('<p>Article body.</p>', $bodyBlock['fields']['html']);
    }

    public function testReviewedMatrixFieldWinsOverDuplicateHeaderBlockSource(): void
    {
        $fqcn = 'App\\Entity\\Pages\\CaseStudyPage';
        $mapping = [
            'sites' => ['nl' => 'default'],
            'sections' => [
                'casePage' => ['entryType' => 'casePage', 'section' => 'casePages'],
            ],
            'nodeClasses' => [
                $fqcn => [
                    'sourceTable' => 'case_study_pages',
                    'section' => 'casePage',
                    'fields' => [
                        'caseIntro.ckeditorDefault' => ['source' => 'intro', 'handler' => 'plain'],
                    ],
                    'pageBuilderHandle' => 'pageBuilder',
                    'bodyColumn' => '',
                    'headerBlock' => [
                        'fieldHandle' => 'headerCase',
                        'blockType' => 'headerCaseHero',
                        'title' => '{title}',
                        'fields' => [
                            'ckeditorDefault' => ['source' => 'intro', 'handler' => 'plain'],
                            'image' => ['source' => 'image_id', 'handler' => 'plain'],
                        ],
                    ],
                    'bodyWrapBlock' => null,
                    'joins' => [],
                ],
            ],
            'pageParts' => [],
        ];

        $extracted = [
            'kunstmaanSourceId' => 'App_Entity_Pages_CaseStudyPage:8',
            'fqcn' => $fqcn,
            'kuma_node_id' => 48,
            'kuma_parent_id' => null,
            'ref_id' => 8,
            'refIdsByLocale' => ['nl' => 8],
            'sourceTable' => 'case_study_pages',
            'perSite' => [
                'nl' => [
                    'online' => true,
                    'title' => 'AgroEnergy',
                    'slug' => 'agroenergy',
                    'url' => '/cases/agroenergy',
                    'refId' => 8,
                    'detail' => [
                        'id' => 8,
                        'title' => 'AgroEnergy',
                        'intro' => '<p>Intro belongs in caseIntro only.</p>',
                        'image_id' => 123,
                    ],
                    'pageParts' => [],
                ],
            ],
        ];

        $service = $this->makeService();
        $service->matrixInnerTypeMap = ['caseIntro' => 'generalContentBlock'];
        $payloads = [];
        foreach ($service->run([$extracted], $mapping, new MigrationFilters(), []) as $yielded) {
            if (!is_array($yielded) || array_key_exists('__report', $yielded)) {
                continue;
            }
            $payloads[] = $yielded;
        }

        $fieldValues = (array) ($payloads[0]['perSite']['default']['fieldValues'] ?? []);
        $introBlock = (array) reset($fieldValues['caseIntro']);
        self::assertSame('<p>Intro belongs in caseIntro only.</p>', $introBlock['fields']['ckeditorDefault']);
        self::assertTrue($introBlock['fields']['_suppressNativeTitleFallback']);

        $headerBlock = (array) reset($fieldValues['headerCase']);
        self::assertSame('', $headerBlock['fields']['ckeditorDefault']);
        self::assertSame('123', $headerBlock['fields']['image']);
    }

    public function testBodyWrapBlockWithoutSourceBackedTitleStaysUntitled(): void
    {
        $fqcn = 'App\\Entity\\Pages\\VacancyPage';
        $mapping = [
            'sites' => ['nl' => 'default'],
            'sections' => [
                'contentPage' => ['entryType' => 'contentPage', 'section' => 'contentPages'],
            ],
            'nodeClasses' => [
                $fqcn => [
                    'sourceTable' => 'vacancy_pages',
                    'section' => 'contentPage',
                    'fields' => [],
                    'pageBuilderHandle' => 'pageBuilder',
                    'pageBuilderContexts' => [],
                    'bodyColumn' => 'summary',
                    'headerBlock' => null,
                    'bodyWrapBlock' => [
                        'blockType' => 'generalContentBlock',
                        'fieldHandle' => 'ckeditorDefault',
                    ],
                    'joins' => [],
                ],
            ],
            'pageParts' => [],
        ];

        $extracted = [
            'kunstmaanSourceId' => 'App_Entity_Pages_VacancyPage:21',
            'fqcn' => $fqcn,
            'kuma_node_id' => 720,
            'kuma_parent_id' => null,
            'ref_id' => 21,
            'refIdsByLocale' => ['nl' => 21],
            'sourceTable' => 'vacancy_pages',
            'perSite' => [
                'nl' => [
                    'online' => true,
                    'title' => 'Senior Consultant Logistiek',
                    'slug' => 'senior-consultant-logistiek',
                    'url' => '/vacatures/senior-consultant-logistiek',
                    'refId' => 21,
                    'detail' => [
                        'id' => 21,
                        'summary' => '<p>Vacancy summary.</p>',
                    ],
                    'pageParts' => [],
                ],
            ],
        ];

        $payloads = [];
        foreach ($this->makeService()->run([$extracted], $mapping, new MigrationFilters(), []) as $yielded) {
            if (!is_array($yielded) || array_key_exists('__report', $yielded)) {
                continue;
            }
            $payloads[] = $yielded;
        }

        $fieldValues = (array) ($payloads[0]['perSite']['default']['fieldValues'] ?? []);
        $blocks = (array) ($fieldValues['pageBuilder'] ?? []);
        $block = (array) reset($blocks);

        self::assertArrayNotHasKey('title', $block);
        self::assertTrue($block['fields']['_suppressNativeTitleFallback']);
        self::assertSame('<p>Vacancy summary.</p>', $block['fields']['ckeditorDefault']);
    }

    private function makeService(): TransformService
    {
        $registry = new FieldHandlerRegistry();
        $registry->register(new PlainTextHandler('plain'));
        $registry->register(new PlainTextHandler('ckeditor'));
        $registry->register(new PlainTextHandler('link'));
        $registry->register(new PlainTextHandler('dropdown'));
        $registry->register(new AssetHandler());
        $registry->register(new RelationHandler());
        $registry->register(new MatrixHandler());
        $registry->register(new SplitNameHandler());

        $service = new TransformService();
        $service->handlerRegistry = $registry;
        $service->ckeditorRewriter = new CkeditorRewriterService();
        return $service;
    }
}
