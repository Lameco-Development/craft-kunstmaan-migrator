<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\verify;

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
        self::assertCount(4, $rm->getParameters(), 'run() must accept 4 args: expectedCounts, tolerance, filters, translatedScope.');
        $names = array_map(static fn(ReflectionParameter $p): string => $p->getName(), $rm->getParameters());
        self::assertSame(['expectedCounts', 'tolerance', 'filters', 'translatedScope'], $names);

        // Third parameter must be optional (BC: pre-Phase-4.1 callers pass 2 args).
        $filtersParam = $rm->getParameters()[2];
        self::assertTrue($filtersParam->isOptional(), 'filters arg must default to null for BC.');
        self::assertTrue($filtersParam->allowsNull(), 'filters arg must accept null.');

        // Fourth parameter must be optional (BC: pre-09-02B callers pass 3 args).
        $scopeParam = $rm->getParameters()[3];
        self::assertTrue($scopeParam->isOptional(), 'translatedScope arg must default to null for BC.');
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

    public function testIsSectionFilteredOutUsesTranslatedCraftSectionScope(): void
    {
        // D-17: source entity filters must not be compared directly to Craft
        // section handles. The translated Craft scope is the only allow-list.
        $f = new MigrationFilters(entities: ['App\\Entity\\Pages\\ArticlePage']);
        $scope = [
            'sectionHandles' => ['articles'],
            'entryTypeHandles' => ['articlePage'],
            'unmappedSourceEntities' => [],
        ];

        self::assertFalse(CountGateService::isSectionFilteredOut('articles', $f, $scope));
        self::assertTrue(CountGateService::isSectionFilteredOut('events', $f, $scope));
        self::assertTrue(CountGateService::isSectionFilteredOut('ArticlePage', $f, $scope));
    }

    public function testIsSectionFilteredOutDoesNotCompareRawSourceFiltersToSections(): void
    {
        $f = new MigrationFilters(entities: ['ArticlePage']);
        self::assertFalse(
            CountGateService::isSectionFilteredOut('articles', $f),
            'Without a translated Craft scope, CountGateService must not guess by comparing source entities to section handles.',
        );
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

    public function testVerifyControllerLoadsCompiledMappingForEntityFilterTranslation(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../../src/console/VerifyController.php');

        self::assertStringContainsString('MappingFilterTranslator', $source);
        self::assertStringContainsString('loadTranslatedScopeForEntityFilters', $source);
        self::assertStringContainsString('mappingFile->load', $source);
        self::assertStringContainsString('unmappedSourceEntities', $source);
        self::assertStringContainsString('sourceParityExpectedCounts', $source);
        self::assertStringContainsString('countGateService->run($sourceExpectedCounts, (float) $tolerance, $filters, $translatedScope)', $source);
        self::assertStringContainsString('DOMAIN_CRAFT_BASELINE_CURRENT_DRIFT', $source);
        self::assertStringContainsString('DOMAIN_MIGRATION_CREATED_STATE_COUNTS', $source);
        self::assertStringContainsString('DOMAIN_SOURCE_TRANSFORMED_PARITY', $source);
    }

    public function testFinalizeWalkerUsesTranslatedCraftScopeForEntryQueries(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../../src/finalize/FinalizeWalker.php');

        self::assertStringContainsString('MappingFilterTranslator', $source);
        self::assertStringContainsString('loadTranslatedScopeForEntityFilters', $source);
        self::assertStringContainsString('section($translatedScope[\'sectionHandles\'])', $source);
        self::assertStringContainsString('type($translatedScope[\'entryTypeHandles\'])', $source);
        self::assertStringContainsString('unmappedSourceEntities', $source);
    }
}
