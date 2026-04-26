<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\verify;

use lameco\kunstmaanmigrator\filter\MigrationFilters;
use lameco\kunstmaanmigrator\verify\CountGateService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;

/**
 * Phase 4.1 / Plan 04.1-05 / Task 2 — characterization for the filter-aware
 * portion of CountGateService::run() (VER-04 plumb-through).
 *
 * Like the existing CountGateServiceTest, full DB-coupled run() integration
 * (Entry::find(), Site::getSiteByHandle(), etc.) is exercised in Phase 5 /
 * TST-02 with a real Craft bootstrap. This file covers:
 *   - the filter-aware run() signature is preserved (BC: 3rd arg is optional)
 *   - the pure decision in isSectionFilteredOut() (entities allow-list)
 *
 * Locale → siteId scoping is exercised by reading the source file directly
 * (the implementation pattern is locked but Site::getSiteByHandle requires
 * a Craft bootstrap to invoke).
 */
final class CountGateServiceFiltersTest extends TestCase
{
    public function testRunSignatureAcceptsOptionalFiltersParameter(): void
    {
        $rm = new ReflectionMethod(CountGateService::class, 'run');
        self::assertCount(3, $rm->getParameters(), 'run() must accept 3 args: expectedCounts, tolerance, filters.');
        $names = array_map(static fn(ReflectionParameter $p): string => $p->getName(), $rm->getParameters());
        self::assertSame(['expectedCounts', 'tolerance', 'filters'], $names);

        // Third parameter must be optional (BC: pre-Phase-4.1 callers pass 2 args).
        $filtersParam = $rm->getParameters()[2];
        self::assertTrue($filtersParam->isOptional(), 'filters arg must default to null for BC.');
        self::assertTrue($filtersParam->allowsNull(), 'filters arg must accept null.');
    }

    public function testIsSectionFilteredOutReturnsFalseForNullFilters(): void
    {
        // BC: pre-Phase-4.1 behavior preserved when no filters supplied.
        self::assertFalse(CountGateService::isSectionFilteredOut('blogPosts', null));
    }

    public function testIsSectionFilteredOutReturnsFalseForEmptyEntitiesList(): void
    {
        // Empty entities allow-list means "no scoping" — every section is in scope.
        $f = new MigrationFilters(entities: []);
        self::assertFalse(CountGateService::isSectionFilteredOut('blogPosts', $f));
    }

    public function testIsSectionFilteredOutReturnsTrueWhenSectionNotInAllowList(): void
    {
        // D-28: section excluded by entities allow-list → SKIPPED row, not 0/expected fail.
        $f = new MigrationFilters(entities: ['blogPosts']);
        self::assertTrue(CountGateService::isSectionFilteredOut('events', $f));
        self::assertTrue(CountGateService::isSectionFilteredOut('contentPages', $f));
    }

    public function testIsSectionFilteredOutReturnsFalseWhenSectionInAllowList(): void
    {
        $f = new MigrationFilters(entities: ['blogPosts', 'events']);
        self::assertFalse(CountGateService::isSectionFilteredOut('blogPosts', $f));
        self::assertFalse(CountGateService::isSectionFilteredOut('events', $f));
    }

    public function testSourceWiresFiltersIntoEntryQueryViaSiteIdScoping(): void
    {
        // D-28: locale → siteId scoping pattern. Source-level lock (Craft bootstrap
        // would be needed for behavior-level test).
        $source = (string) file_get_contents((new ReflectionClass(CountGateService::class))->getFileName());
        self::assertStringContainsString('resolveScopeSiteIds', $source, 'D-28 locale→siteId helper must exist.');
        self::assertStringContainsString('siteId($scopeSiteIds)', $source, 'Entry query must apply scoped siteIds.');
        self::assertStringContainsString('localeMap', $source, 'Locale resolution goes through Settings::$localeMap.');
    }
}
