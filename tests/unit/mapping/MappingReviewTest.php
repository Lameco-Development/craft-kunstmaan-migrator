<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\mapping;

use lameco\kunstmaanmigrator\filter\MigrationFilters;
use lameco\kunstmaanmigrator\mapping\MappingReview;
use PHPUnit\Framework\TestCase;

final class MappingReviewTest extends TestCase
{
    public function testPageEntitiesComeFromNodeClassAndPagePartRows(): void
    {
        $rows = [
            [
                'kind' => 'nodeClass',
                'fqcn' => 'App\\Entity\\Pages\\NewsPage',
            ],
            [
                'kind' => 'pagePart',
                'parentPageClass' => 'App\\Entity\\Pages\\HomePage',
                'pagePartClass' => 'App\\Entity\\PageParts\\NewsPagePart',
            ],
            [
                'kind' => 'column',
                'table' => 'kuma_news_page',
            ],
        ];

        self::assertSame(['HomePage', 'NewsPage'], MappingReview::pageEntities($rows));
    }

    public function testCollectPageMappingRowsMatchesAllRowsForEntity(): void
    {
        $rows = [
            [
                'kind' => 'nodeClass',
                'fqcn' => 'App\\Entity\\Pages\\NewsPage',
            ],
            [
                'kind' => 'pagePart',
                'parentPageClass' => 'App\\Entity\\Pages\\NewsPage',
                'pagePartClass' => 'App\\Entity\\PageParts\\TextPagePart',
            ],
            [
                'kind' => 'column',
                'table' => 'kuma_news_page_translations',
                'column' => 'title',
            ],
            [
                'kind' => 'pagePart',
                'parentPageClass' => 'App\\Entity\\Pages\\HomePage',
                'pagePartClass' => 'App\\Entity\\PageParts\\ClientsPagePart',
            ],
        ];

        $matches = MappingReview::collectPageMappingRows($rows, new MigrationFilters(entities: ['NewsPage']));

        self::assertSame([0, 1, 2], array_column($matches, 'index'));
    }

    public function testCollectPageMappingRowsMatchesCustomBundleSourceTableFromNodeClass(): void
    {
        $rows = [
            [
                'kind' => 'nodeClass',
                'fqcn' => 'App\\Entity\\Pages\\CaseStudyPage',
                'sourceTable' => 'lameco_websitebundle_case_study_pages',
                'targetEntryType' => 'casePage',
            ],
            [
                'kind' => 'column',
                'table' => 'lameco_websitebundle_case_study_pages',
                'column' => 'employee_id',
                'targetEntryType' => 'casePage',
            ],
            [
                'kind' => 'column',
                'table' => 'other_table',
                'column' => 'employee_id',
                'targetEntryType' => 'teamMember',
            ],
        ];

        $matches = MappingReview::collectPageMappingRows($rows, new MigrationFilters(entities: ['CaseStudyPage']));

        self::assertSame([0, 1], array_column($matches, 'index'));
    }

    public function testSummaryLineDescribesEditableTarget(): void
    {
        $summary = MappingReview::summaryLine([
            'kind' => 'pagePart',
            'status' => 'needs-review',
            'pagePartClass' => 'App\\Entity\\PageParts\\TextPagePart',
            'context' => 'home',
            'targetEntryType' => 'homePage',
            'targetMatrixField' => 'pageBuilder',
            'targetBlockType' => 'generalContentBlock',
            'fields' => [
                ['targetHandle' => 'ckeditorDefault', 'sourceProperty' => 'content'],
            ],
        ]);

        self::assertSame(
            '[needs-review] pagePart TextPagePart:home -> homePage.pageBuilder:generalContentBlock {ckeditorDefault<-content}',
            $summary,
        );
    }

    public function testMappingReviewFilterOptionsAreDeterministic(): void
    {
        self::assertSame(
            ['all', 'proposed', 'needs-review', 'accepted', 'dropped', 'unsupported', 'warning'],
            MappingReview::statusFilterOptions(),
        );
        self::assertSame(
            ['all', 'column', 'pagePart', 'nodeClass', 'taxonomy', 'dataProvider'],
            MappingReview::kindFilterOptions(),
        );
        self::assertSame(
            ['all', 'fatal', 'warning', 'unsupported', 'none'],
            MappingReview::findingFilterOptions(),
        );
    }

    public function testFilterRowsByStatusKindFindingAndSearchQuery(): void
    {
        $rows = [
            [
                'index' => 0,
                'row' => [
                    'kind' => 'column',
                    'status' => 'accepted',
                    'table' => 'kuma_news_page',
                    'column' => 'title',
                    'targetHandle' => 'title',
                    'handler' => 'plain',
                ],
            ],
            [
                'index' => 1,
                'row' => [
                    'kind' => 'dataProvider',
                    'status' => 'warning',
                    'fqcn' => 'App\\Provider\\FeaturedArticlesProvider',
                    'target' => 'featuredArticles',
                    'findingSeverity' => 'warning',
                    'rationale' => 'Data provider needs operator review',
                ],
            ],
            [
                'index' => 2,
                'row' => [
                    'kind' => 'taxonomy',
                    'status' => 'unsupported',
                    'fqcn' => 'App\\Entity\\Tag',
                    'findingSeverity' => 'unsupported',
                    'rationale' => 'No compatible Craft section',
                ],
            ],
            [
                'index' => 3,
                'row' => [
                    'kind' => 'pagePart',
                    'status' => 'needs-review',
                    'pagePartClass' => 'App\\Entity\\PageParts\\UnsafePart',
                    'findings' => [
                        ['severity' => 'fatal', 'message' => 'Unsupported nested form'],
                    ],
                ],
            ],
        ];

        $filtered = MappingReview::filterRows($rows, [
            'statusFilter' => 'warning',
            'kindFilter' => 'dataProvider',
            'findingFilter' => 'warning',
            'searchQuery' => 'featured',
        ]);

        self::assertSame([1], array_column($filtered, 'index'));
        self::assertSame([0], array_column(MappingReview::filterRows($rows, ['findingFilter' => 'none']), 'index'));
        self::assertSame([3], array_column(MappingReview::filterRows($rows, ['findingFilter' => 'fatal']), 'index'));
    }

    public function testMappingControllerExposesFilterQueryVariables(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/src/controllers/MappingController.php');
        self::assertIsString($source);

        foreach (['status', 'kind', 'finding', 'q'] as $queryParam) {
            self::assertStringContainsString("getQueryParam('{$queryParam}'", $source);
        }

        foreach (['statusFilter', 'kindFilter', 'findingFilter', 'searchQuery'] as $viewVariable) {
            self::assertStringContainsString("'{$viewVariable}'", $source);
        }

        self::assertStringContainsString("\$params = ['tab' => 'mapping'];", $source);
    }

    public function testMappingTwigPreservesConsoleMappingTabAcrossFiltersAndPosts(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/templates/_mapping/index.twig');
        self::assertIsString($source);

        self::assertStringContainsString("url('utilities/kunstmaan-mapping', {tab: 'mapping'})", $source);
        self::assertStringContainsString("hiddenInput('tab', 'mapping')", $source);
        self::assertGreaterThanOrEqual(2, substr_count($source, "hiddenInput('tab', 'mapping')"));
    }

    public function testMappingControllerBatchActionUsesAdminPostValidationAndCanonicalUpdates(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/src/controllers/MappingController.php');
        self::assertIsString($source);

        self::assertStringContainsString('function actionBatch', $source);
        $batchStart = strpos($source, 'function actionBatch');
        self::assertIsInt($batchStart);
        $batchSource = substr($source, $batchStart, 2600);

        foreach (['requireCpRequest', 'requirePostRequest', 'requireAdmin'] as $guard) {
            self::assertStringContainsString('$this->' . $guard . '();', $batchSource);
        }
        foreach (['accept', 'needs-review', 'drop', 'accept-warnings'] as $batchAction) {
            self::assertStringContainsString("'{$batchAction}'", $batchSource);
        }
        foreach (['DROP SELECTED', 'ACCEPT WARNINGS'] as $phrase) {
            self::assertStringContainsString($phrase, $batchSource);
        }

        self::assertStringContainsString('updateRow($plugin->mappingFile->resolvePath()', $batchSource);
        self::assertStringNotContainsString('mapping-draft', $source);
        self::assertStringNotContainsString('file_put_contents', $source);
    }
}
