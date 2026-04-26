<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\verify;

use lameco\kunstmaanmigrator\filter\MigrationFilters;
use lameco\kunstmaanmigrator\verify\BaselineCounterService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Phase 4.1 / Plan 04.1-05 / Task 2 — characterization for the
 * filterScope JSON header shape that BaselineCounterService::capture()
 * embeds (D-29). The doctor 8th check (D-30, Task 4) reads this header.
 *
 * Full capture() body needs a Craft bootstrap (Entry::find() etc.); we
 * exercise the pure buildFilterScope() helper that produces the JSON
 * header value directly.
 */
final class BaselineCounterServiceFiltersTest extends TestCase
{
    public function testCaptureSignatureAcceptsOptionalFiltersParameter(): void
    {
        $rm = new ReflectionMethod(BaselineCounterService::class, 'capture');
        self::assertCount(1, $rm->getParameters());
        $p = $rm->getParameters()[0];
        self::assertSame('filters', $p->getName());
        self::assertTrue($p->isOptional(), 'filters arg must default to null for BC.');
        self::assertTrue($p->allowsNull());
    }

    public function testBuildFilterScopeReturnsNullForNullFilters(): void
    {
        // D-29: filterScope key is always present in the JSON; null when no filters
        // (so downstream consumers don't need to handle missing-key).
        self::assertNull(BaselineCounterService::buildFilterScope(null));
    }

    public function testBuildFilterScopeEmbedsEntitiesLocalesAndSinceWhenFiltersProvided(): void
    {
        $filters = new MigrationFilters(
            entities: ['blogPosts', 'events'],
            locales: ['nl', 'fr'],
            since: '2026-01-01',
        );
        $scope = BaselineCounterService::buildFilterScope($filters);

        self::assertIsArray($scope);
        self::assertSame(['blogPosts', 'events'], $scope['entities']);
        self::assertSame(['nl', 'fr'], $scope['locales']);
        self::assertSame('2026-01-01', $scope['since']);
    }

    public function testBuildFilterScopeEmbedsEmptyArraysWhenFiltersAreEmpty(): void
    {
        // D-29: the operator can capture with default filters — entities=[] etc.
        // The shape is preserved; doctor's compare uses set-equality which treats
        // [] as the canonical "all" sentinel.
        $filters = new MigrationFilters();
        $scope = BaselineCounterService::buildFilterScope($filters);

        self::assertIsArray($scope);
        self::assertSame([], $scope['entities']);
        self::assertSame([], $scope['locales']);
        self::assertNull($scope['since']);
    }

    public function testBuildFilterScopeKeysMatchDoctorComparatorExpectations(): void
    {
        // D-30: the doctor 8th check reads $captured['entities'], ['locales'], ['since'].
        // Lock the key names so a refactor here doesn't silently break that consumer.
        $scope = BaselineCounterService::buildFilterScope(new MigrationFilters());
        self::assertIsArray($scope);
        self::assertArrayHasKey('entities', $scope);
        self::assertArrayHasKey('locales', $scope);
        self::assertArrayHasKey('since', $scope);
    }
}
