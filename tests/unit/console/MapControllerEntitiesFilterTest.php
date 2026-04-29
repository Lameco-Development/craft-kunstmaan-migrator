<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\console;

use lameco\kunstmaanmigrator\console\MapController;
use lameco\kunstmaanmigrator\filter\MigrationFilters;
use PHPUnit\Framework\TestCase;

/**
 * WR-01 fix verification (Phase 02.1 / Plan 06 follow-up): asserts that
 * MapController::matchesEntitiesFilter handles `kind: pagePart` rows by matching
 * against `parentPageClass` basename, not `table` (which is empty for pagePart rows).
 *
 * Pre-fix: every pagePart row was silently dropped by `--entities=NewsPage` because
 * matchesEntitiesFilter only checked $row['table']. Post-fix: pagePart rows match
 * by parent-page-class basename.
 *
 * The method is public + static so this test can exercise it without instantiating
 * MapController (which extends craft\console\Controller and needs Yii bootstrap).
 */
final class MapControllerEntitiesFilterTest extends TestCase
{
    public function testEmptyEntitiesFilterMatchesEverything(): void
    {
        $filters = new MigrationFilters(entities: []);

        self::assertTrue(MapController::matchesEntitiesFilter(
            ['kind' => 'column', 'table' => 'kuma_news_page', 'column' => 'title'],
            $filters,
        ));
        self::assertTrue(MapController::matchesEntitiesFilter(
            ['kind' => 'pagePart', 'parentPageClass' => 'App\\Entity\\Pages\\NewsPage'],
            $filters,
        ));
    }

    public function testColumnRowMatchesByTablePrefix(): void
    {
        $filters = new MigrationFilters(entities: ['NewsPage']);

        self::assertTrue(MapController::matchesEntitiesFilter(
            ['kind' => 'column', 'table' => 'kuma_news_page', 'column' => 'title'],
            $filters,
        ));
        self::assertTrue(MapController::matchesEntitiesFilter(
            ['kind' => 'column', 'table' => 'kuma_news_page_translations', 'column' => 'slug'],
            $filters,
        ));
    }

    public function testColumnRowSkippedWhenTableDoesNotMatch(): void
    {
        $filters = new MigrationFilters(entities: ['NewsPage']);

        self::assertFalse(MapController::matchesEntitiesFilter(
            ['kind' => 'column', 'table' => 'kuma_blog_post', 'column' => 'title'],
            $filters,
        ));
    }

    public function testColumnRowDefaultsToColumnKindWhenAbsent(): void
    {
        // Backward compatibility: rows without an explicit `kind:` default to column.
        $filters = new MigrationFilters(entities: ['NewsPage']);

        self::assertTrue(MapController::matchesEntitiesFilter(
            ['table' => 'kuma_news_page', 'column' => 'title'],
            $filters,
        ));
    }

    public function testPagePartRowMatchesByParentPageClassBasename(): void
    {
        // WR-01 regression test: pre-fix this returned false because matchesEntitiesFilter
        // read $row['table'] which is empty for pagePart rows.
        $filters = new MigrationFilters(entities: ['NewsPage']);

        self::assertTrue(MapController::matchesEntitiesFilter(
            [
                'kind'            => 'pagePart',
                'pagePartClass'   => 'App\\Entity\\PageParts\\HeaderPagePart',
                'parentPageClass' => 'App\\Entity\\Pages\\NewsPage',
                'context'         => 'main',
            ],
            $filters,
        ));
    }

    public function testPagePartRowSkippedWhenParentPageClassDoesNotMatch(): void
    {
        $filters = new MigrationFilters(entities: ['NewsPage']);

        self::assertFalse(MapController::matchesEntitiesFilter(
            [
                'kind'            => 'pagePart',
                'pagePartClass'   => 'App\\Entity\\PageParts\\HeaderPagePart',
                'parentPageClass' => 'App\\Entity\\Pages\\BlogPage',
                'context'         => 'main',
            ],
            $filters,
        ));
    }

    public function testPagePartRowSkippedWhenParentPageClassEmpty(): void
    {
        $filters = new MigrationFilters(entities: ['NewsPage']);

        self::assertFalse(MapController::matchesEntitiesFilter(
            ['kind' => 'pagePart', 'parentPageClass' => '', 'context' => 'main'],
            $filters,
        ));
    }

    public function testPagePartRowMatchesUnnamespacedParentPageClass(): void
    {
        $filters = new MigrationFilters(entities: ['NewsPage']);

        self::assertTrue(MapController::matchesEntitiesFilter(
            ['kind' => 'pagePart', 'parentPageClass' => 'NewsPage', 'context' => 'main'],
            $filters,
        ));
    }

    public function testPagePartRowMatchesAnyOfMultipleEntities(): void
    {
        $filters = new MigrationFilters(entities: ['BlogPage', 'NewsPage', 'EventPage']);

        self::assertTrue(MapController::matchesEntitiesFilter(
            ['kind' => 'pagePart', 'parentPageClass' => 'App\\Entity\\Pages\\NewsPage'],
            $filters,
        ));
    }

    public function testNodeClassRowMatchesByFqcnBasename(): void
    {
        $filters = new MigrationFilters(entities: ['NewsPage']);

        self::assertTrue(MapController::matchesEntitiesFilter(
            ['kind' => 'nodeClass', 'fqcn' => 'App\\Entity\\Pages\\NewsPage'],
            $filters,
        ));
    }
}
