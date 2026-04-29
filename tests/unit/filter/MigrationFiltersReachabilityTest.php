<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\filter;

use lameco\kunstmaanmigrator\filter\FilterFactory;
use lameco\kunstmaanmigrator\filter\MigrationFilters;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Phase 8 / Plan 10 / D-04: --entities= scoping auto-includes taxonomies referenced by allowed FQCNs.
 *
 * Reachability is computed from the relation graph (FK index) the constructor accepts;
 * NO new --taxonomies= flag (D-12 three-flag cap preserved).
 *
 * Behaviors locked here:
 *   - Empty entities (no scoping) → allows() returns true for any FQCN (unchanged).
 *   - Non-empty entities + FQCN in allow-list → allows() returns true (explicit allow).
 *   - Non-empty entities + FQCN reachable via relation graph → allows() returns true (auto-include).
 *   - Non-empty entities + FQCN NOT in allow-list AND NOT reachable → allows() returns false.
 *   - Reachability is transitive (multi-hop walk).
 *   - Cycles in the graph do not blow up (visited-set guard).
 */
final class MigrationFiltersReachabilityTest extends TestCase
{
    public function testAllowsMethodExists(): void
    {
        $rc = new ReflectionClass(MigrationFilters::class);
        self::assertTrue(
            $rc->hasMethod('allows'),
            'D-04: MigrationFilters must expose an allows(string $fqcn): bool reachability accessor.',
        );

        $m = new ReflectionMethod(MigrationFilters::class, 'allows');
        self::assertCount(1, $m->getParameters(), 'allows() takes a single FQCN argument.');
        self::assertSame('fqcn', $m->getParameters()[0]->getName());
        self::assertSame('bool', (string) $m->getReturnType());
    }

    public function testConstructorAcceptsRelationGraph(): void
    {
        $rc = new ReflectionClass(MigrationFilters::class);
        $ctor = $rc->getConstructor();
        self::assertNotNull($ctor);
        $names = array_map(static fn(\ReflectionParameter $p): string => $p->getName(), $ctor->getParameters());
        self::assertContains(
            'relationGraph',
            $names,
            'D-04: MigrationFilters constructor must accept a relationGraph (Map<FQCN, list<related FQCN>>).',
        );
    }

    public function testEmptyEntitiesAllowsEveryFqcn(): void
    {
        // Existing behavior: without --entities= scoping, every FQCN passes.
        $f = new MigrationFilters();
        self::assertTrue($f->allows('App\\Entity\\NewsPage'));
        self::assertTrue($f->allows('App\\Entity\\AnythingElse'));
        self::assertTrue($f->allows('completely-unknown'));
    }

    public function testEntitiesAllowListExplicitMatch(): void
    {
        $f = new MigrationFilters(
            entities: ['App\\Entity\\NewsPage', 'App\\Entity\\CaseStudyPage'],
        );
        self::assertTrue($f->allows('App\\Entity\\NewsPage'));
        self::assertTrue($f->allows('App\\Entity\\CaseStudyPage'));
    }

    public function testEntitiesAllowListRejectsUnreachable(): void
    {
        $f = new MigrationFilters(
            entities: ['App\\Entity\\NewsPage'],
            relationGraph: [
                'App\\Entity\\NewsPage' => ['App\\Entity\\NewsCategory'],
            ],
        );
        // Not in allow-list, not reachable from NewsPage → blocked.
        self::assertFalse($f->allows('App\\Entity\\UnrelatedSettingsBundle'));
    }

    public function testReachabilityAutoIncludesRelatedTaxonomy(): void
    {
        // D-04: scoping NewsPage auto-includes NewsCategory if NewsPage's relation graph
        // points to it, even though NewsCategory is not in --entities=.
        $f = new MigrationFilters(
            entities: ['App\\Entity\\NewsPage'],
            relationGraph: [
                'App\\Entity\\NewsPage' => ['App\\Entity\\NewsCategory', 'App\\Entity\\Author'],
            ],
        );
        self::assertTrue($f->allows('App\\Entity\\NewsCategory'));
        self::assertTrue($f->allows('App\\Entity\\Author'));
    }

    public function testReachabilityIsTransitive(): void
    {
        // NewsPage → NewsCategory → CategoryGroup. Scoping NewsPage must reach both.
        $f = new MigrationFilters(
            entities: ['App\\Entity\\NewsPage'],
            relationGraph: [
                'App\\Entity\\NewsPage'     => ['App\\Entity\\NewsCategory'],
                'App\\Entity\\NewsCategory' => ['App\\Entity\\CategoryGroup'],
            ],
        );
        self::assertTrue($f->allows('App\\Entity\\NewsCategory'));
        self::assertTrue($f->allows('App\\Entity\\CategoryGroup'));
    }

    public function testReachabilityHandlesCycles(): void
    {
        // A → B → A self-cycle must not loop forever.
        $f = new MigrationFilters(
            entities: ['App\\Entity\\A'],
            relationGraph: [
                'App\\Entity\\A' => ['App\\Entity\\B'],
                'App\\Entity\\B' => ['App\\Entity\\A', 'App\\Entity\\C'],
            ],
        );
        self::assertTrue($f->allows('App\\Entity\\A'));
        self::assertTrue($f->allows('App\\Entity\\B'));
        self::assertTrue($f->allows('App\\Entity\\C'));
        self::assertFalse($f->allows('App\\Entity\\Unrelated'));
    }

    public function testUnrelatedFqcnInPopulatedGraphIsBlocked(): void
    {
        // The graph contains entries for A, but the operator scoped only A.
        // FQCNs only reachable from a different root must NOT pass.
        $f = new MigrationFilters(
            entities: ['App\\Entity\\A'],
            relationGraph: [
                'App\\Entity\\A'        => ['App\\Entity\\AChild'],
                'App\\Entity\\OtherRoot' => ['App\\Entity\\OtherChild'],
            ],
        );
        self::assertTrue($f->allows('App\\Entity\\A'));
        self::assertTrue($f->allows('App\\Entity\\AChild'));
        self::assertFalse($f->allows('App\\Entity\\OtherRoot'));
        self::assertFalse($f->allows('App\\Entity\\OtherChild'));
    }

    public function testRelationGraphDefaultsToEmpty(): void
    {
        // Existing call sites that don't pass relationGraph keep working;
        // with an empty graph, only the explicit allow-list is honored.
        $f = new MigrationFilters(entities: ['App\\Entity\\NewsPage']);
        self::assertTrue($f->allows('App\\Entity\\NewsPage'));
        self::assertFalse($f->allows('App\\Entity\\NewsCategory'));
    }

    public function testFilterFactoryFromCliAcceptsOptionalRelationGraph(): void
    {
        $m = new ReflectionMethod(FilterFactory::class, 'fromCli');
        $names = array_map(static fn(\ReflectionParameter $p): string => $p->getName(), $m->getParameters());

        self::assertSame(
            ['entitiesArg', 'localesArg', 'sinceArg', 'noSeo', 'noRetour', 'relationGraph'],
            $names,
            'D-15: fromCli must keep existing arguments and append an optional relationGraph seam.',
        );
        self::assertTrue($m->getParameters()[5]->isDefaultValueAvailable());
        self::assertSame([], $m->getParameters()[5]->getDefaultValue());
    }

    public function testBasenameScopedRootReachesFqcnGraphDependencies(): void
    {
        $f = new MigrationFilters(
            entities: ['NewsPage'],
            relationGraph: [
                'App\\Entity\\Pages\\NewsPage' => ['App\\Entity\\Taxonomy\\NewsCategory'],
            ],
        );

        self::assertTrue($f->allows('App\\Entity\\Pages\\NewsPage'));
        self::assertTrue($f->allows('App\\Entity\\Taxonomy\\NewsCategory'));
        self::assertTrue($f->allows('NewsCategory'));
    }

}
