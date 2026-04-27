<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\mapping;

use lameco\kunstmaanmigrator\mapping\CoverageAuditor;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CoverageAuditor (Plan 05) — D-14 data-bearing-column rule.
 */
final class CoverageAuditorTest extends TestCase
{
    public function testZeroFillRateColumnIsNotAViolation(): void
    {
        $a = new CoverageAuditor();
        $dump = [
            'tables'  => ['kuma_news_page' => 100],
            'columns' => [
                'kuma_news_page' => [
                    ['column' => 'body',     'sqlType' => 'TEXT',    'fillRate' => 0.0, 'samples' => []],
                ],
            ],
        ];
        // No mapping rows. fillRate=0 columns are NOT data-bearing → no violation.
        self::assertSame([], $a->audit($dump, []));
    }

    public function testStructuralColumnsAreIgnoredEvenWhenFilled(): void
    {
        $a = new CoverageAuditor();
        $dump = [
            'tables'  => ['kuma_news_page' => 100],
            'columns' => [
                'kuma_news_page' => [
                    ['column' => 'id',         'sqlType' => 'int',  'fillRate' => 1.0],
                    ['column' => 'parent_id',  'sqlType' => 'int',  'fillRate' => 0.5],
                    ['column' => 'lft',        'sqlType' => 'int',  'fillRate' => 1.0],
                    ['column' => 'rgt',        'sqlType' => 'int',  'fillRate' => 1.0],
                    ['column' => 'created',    'sqlType' => 'datetime', 'fillRate' => 1.0],
                ],
            ],
        ];
        // No mapping rows, but all columns are structural — no violations.
        self::assertSame([], $a->audit($dump, []));
    }

    public function testDataBearingColumnWithoutMappingRowIsAViolation(): void
    {
        $a = new CoverageAuditor();
        $dump = [
            'tables'  => ['kuma_news_page' => 100],
            'columns' => [
                'kuma_news_page' => [
                    ['column' => 'body', 'sqlType' => 'TEXT', 'fillRate' => 0.94],
                ],
            ],
        ];
        $violations = $a->audit($dump, []);
        self::assertCount(1, $violations);
        self::assertSame('kuma_news_page', $violations[0]['table']);
        self::assertSame('body', $violations[0]['column']);
        self::assertSame(0.94, $violations[0]['fillRate']);
        self::assertSame(100, $violations[0]['rows']);
    }

    public function testAcceptedRowCoversTheColumn(): void
    {
        $a = new CoverageAuditor();
        $dump = [
            'tables'  => ['kuma_news_page' => 100],
            'columns' => [
                'kuma_news_page' => [
                    ['column' => 'body', 'sqlType' => 'TEXT', 'fillRate' => 0.94],
                ],
            ],
        ];
        $proposals = [
            ['table' => 'kuma_news_page', 'column' => 'body', 'targetEntryType' => 'newsArticle', 'status' => 'accepted'],
        ];
        self::assertSame([], $a->audit($dump, $proposals));
    }

    public function testDroppedRowCoversTheColumn(): void
    {
        $a = new CoverageAuditor();
        $dump = [
            'tables'  => ['kuma_news_page' => 100],
            'columns' => [
                'kuma_news_page' => [
                    ['column' => 'body', 'sqlType' => 'TEXT', 'fillRate' => 0.94],
                ],
            ],
        ];
        $proposals = [
            ['table' => 'kuma_news_page', 'column' => 'body', 'targetEntryType' => 'newsArticle', 'status' => 'dropped', 'rationale' => 'no Craft target'],
        ];
        self::assertSame([], $a->audit($dump, $proposals), 'D-14: dropped also counts as covered.');
    }

    public function testProposedRowDoesNotCoverTheColumn(): void
    {
        $a = new CoverageAuditor();
        $dump = [
            'tables'  => ['kuma_news_page' => 100],
            'columns' => [
                'kuma_news_page' => [
                    ['column' => 'body', 'sqlType' => 'TEXT', 'fillRate' => 0.94],
                ],
            ],
        ];
        $proposals = [
            ['table' => 'kuma_news_page', 'column' => 'body', 'targetEntryType' => 'newsArticle', 'status' => 'proposed'],
        ];
        $violations = $a->audit($dump, $proposals);
        self::assertCount(1, $violations, 'D-14: proposed/needs-review do NOT count as covered.');
    }

    public function testNeedsReviewDoesNotCoverTheColumn(): void
    {
        $a = new CoverageAuditor();
        $dump = [
            'tables'  => ['kuma_news_page' => 100],
            'columns' => [
                'kuma_news_page' => [
                    ['column' => 'body', 'sqlType' => 'TEXT', 'fillRate' => 0.94],
                ],
            ],
        ];
        $proposals = [
            ['table' => 'kuma_news_page', 'column' => 'body', 'targetEntryType' => 'newsArticle', 'status' => 'needs-review'],
        ];
        self::assertCount(1, $a->audit($dump, $proposals));
    }

    public function testRenderViolationsProducesGroupedStderrBlock(): void
    {
        $a = new CoverageAuditor();
        $violations = [
            ['table' => 'kuma_news_page', 'column' => 'body', 'fillRate' => 0.94, 'rows' => 100],
            ['table' => 'kuma_news_page', 'column' => 'lead', 'fillRate' => 0.50, 'rows' => 100],
            ['table' => 'kuma_event_page', 'column' => 'body', 'fillRate' => 0.80, 'rows' => 50],
        ];
        $rendered = $a->renderViolations($violations);
        self::assertStringContainsString('FAIL kuma_news_page: 2 unmapped data-bearing column(s)', $rendered);
        self::assertStringContainsString('FAIL kuma_event_page: 1 unmapped data-bearing column(s)', $rendered);
        self::assertStringContainsString('- body (fill=94.0%, rows=100)', $rendered);
    }
}
