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
}
