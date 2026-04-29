<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\console;

use lameco\kunstmaanmigrator\console\AnalyzeController;
use PHPUnit\Framework\TestCase;

/**
 * B2 fix verification (Phase 02.1 / Plan 07): asserts AnalyzeController::buildKbMappingAdapter
 * reshapes v2's flat `proposals[]` array into the v1-shaped nested mapping that
 * KunstmaanKnowledgeBase::renderPagesMarkdown / renderPagePartsMarkdown read.
 *
 * Without this adapter, every per-row annotation would be silently lost — the LLM
 * call would replace the empty-string KB placeholders, but the rendered KB markdown
 * would carry no mapping overlay (KunstmaanKnowledgeBase reads $mapping['pageParts'] /
 * ['nodeClasses'] / ['sections'] nested keys, but v2 stores rows flat).
 *
 * The adapter is a public static helper specifically so this test can exercise it
 * without instantiating AnalyzeController (which extends craft\console\Controller
 * and requires Yii base wiring not available in the unit-test bootstrap).
 */
final class AnalyzeControllerKbAdapterTest extends TestCase
{
    public function testAdapterBucketsPagePartRowsByPagePartClass(): void
    {
        $proposals = [
            [
                'kind'             => 'pagePart',
                'pagePartClass'    => 'App\\Entity\\HeaderPagePart',
                'sourceTable'      => 'kuma_main_pageparts',
                'parentPageClass'  => 'NewsPage',
                'context'          => 'main',
                'targetEntryType'  => '',
                'status'           => 'needs-review',
            ],
        ];

        $adapter = AnalyzeController::buildKbMappingAdapter($proposals);

        self::assertArrayHasKey('pageParts', $adapter);
        self::assertArrayHasKey('App\\Entity\\HeaderPagePart', $adapter['pageParts']);
        $row = $adapter['pageParts']['App\\Entity\\HeaderPagePart'];
        self::assertSame('App\\Entity\\HeaderPagePart', $row['pagePartClass']);
        self::assertSame('NewsPage', $row['parentPageClass']);
        self::assertSame('main', $row['context']);
    }

    public function testAdapterBucketsColumnRowsBySectionTargetEntryType(): void
    {
        $proposals = [
            [
                'kind'            => 'column',
                'table'           => 'kuma_news',
                'column'          => 'title',
                'targetEntryType' => 'newsArticle',
                'targetHandle'    => 'title',
            ],
        ];

        $adapter = AnalyzeController::buildKbMappingAdapter($proposals);

        self::assertArrayHasKey('sections', $adapter);
        self::assertArrayHasKey('newsArticle', $adapter['sections']);
        self::assertCount(1, $adapter['sections']['newsArticle']);
        self::assertSame('title', $adapter['sections']['newsArticle'][0]['column']);
    }

    public function testAdapterMixesColumnAndPagePartRowsAndKeepsNodeClassesEmpty(): void
    {
        $proposals = [
            [
                'kind'             => 'pagePart',
                'pagePartClass'    => 'App\\Entity\\HeaderPagePart',
                'parentPageClass'  => 'NewsPage',
                'context'          => 'main',
            ],
            [
                'kind'            => 'column',
                'table'           => 'kuma_news',
                'column'          => 'title',
                'targetEntryType' => 'newsArticle',
            ],
            // Heuristic-matched rows lack an explicit `kind` — bucketer must default to column.
            [
                'table'           => 'kuma_news',
                'column'          => 'lead',
                'targetEntryType' => 'newsArticle',
            ],
            // Column row without targetEntryType lands in '_unmapped' bucket.
            [
                'kind'            => 'column',
                'table'           => 'kuma_orphan',
                'column'          => 'something',
                'targetEntryType' => '',
            ],
        ];

        $adapter = AnalyzeController::buildKbMappingAdapter($proposals);

        // pageParts bucket
        self::assertCount(1, $adapter['pageParts']);
        self::assertArrayHasKey('App\\Entity\\HeaderPagePart', $adapter['pageParts']);

        // sections bucket: 'newsArticle' gets both the explicit-kind and implicit-kind rows.
        self::assertArrayHasKey('newsArticle', $adapter['sections']);
        self::assertCount(2, $adapter['sections']['newsArticle']);
        self::assertSame('title', $adapter['sections']['newsArticle'][0]['column']);
        self::assertSame('lead',  $adapter['sections']['newsArticle'][1]['column']);

        // '_unmapped' bucket holds the orphan column row.
        self::assertArrayHasKey('_unmapped', $adapter['sections']);
        self::assertCount(1, $adapter['sections']['_unmapped']);
        self::assertSame('kuma_orphan', $adapter['sections']['_unmapped'][0]['table']);

        // nodeClasses is reserved for Page entity FQCN annotations (v1 sources from a
        // dedicated YAML key v2 doesn't ship). Empty here is the documented contract.
        self::assertSame([], $adapter['nodeClasses']);
    }

    public function testAdapterIgnoresPagePartRowsWithEmptyPagePartClass(): void
    {
        $proposals = [
            ['kind' => 'pagePart', 'pagePartClass' => '', 'parentPageClass' => 'NewsPage'],
        ];
        $adapter = AnalyzeController::buildKbMappingAdapter($proposals);
        self::assertSame([], $adapter['pageParts']);
    }

    public function testAdapterReturnsEmptyShapeForEmptyInput(): void
    {
        $adapter = AnalyzeController::buildKbMappingAdapter([]);
        self::assertSame(
            ['pageParts' => [], 'nodeClasses' => [], 'sections' => []],
            $adapter,
        );
    }
}
