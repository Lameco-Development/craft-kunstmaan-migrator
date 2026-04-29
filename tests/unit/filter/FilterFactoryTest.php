<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\filter;

use lameco\kunstmaanmigrator\filter\FilterFactory;
use lameco\kunstmaanmigrator\filter\MigrationFilters;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;

/**
 * Source-level characterization for FilterFactory (Plan 01).
 *
 * Why source-level instead of behavior-level: fromCli() reads Plugin::getInstance()
 * which requires a Craft bootstrap (out of scope for Phase 2 unit suite per D-21).
 * We assert the structural contract here; Phase 5 / TST-01 + TST-02 will exercise
 * fromCli end-to-end against a real Plugin instance.
 *
 * Test surface: D-10 merge semantics live in the source; we verify the source
 * contains the load-bearing patterns (null fall-through, empty-string clear,
 * comma-split with trim, three calls into Settings::default*).
 */
final class FilterFactoryTest extends TestCase
{
    public function testClassIsLoadable(): void
    {
        self::assertTrue(class_exists(FilterFactory::class));
    }

    public function testFromCliMethodSignature(): void
    {
        // Phase 4.1 / D-26 extended fromCli with noSeo + noRetour args (default false).
        $rc = new ReflectionClass(FilterFactory::class);
        self::assertTrue($rc->hasMethod('fromCli'));
        $m = new ReflectionMethod(FilterFactory::class, 'fromCli');
        self::assertCount(6, $m->getParameters(), 'fromCli takes 6 args: entitiesArg, localesArg, sinceArg, noSeo, noRetour, relationGraph.');
        $names = array_map(static fn(ReflectionParameter $p): string => $p->getName(), $m->getParameters());
        self::assertSame(['entitiesArg', 'localesArg', 'sinceArg', 'noSeo', 'noRetour', 'relationGraph'], $names);
        self::assertSame(
            MigrationFilters::class,
            (string) $m->getReturnType(),
            'fromCli must return MigrationFilters (FQCN).',
        );
    }

    public function testSourceImplementsD10MergeSemantics(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(FilterFactory::class))->getFileName());

        // null fall-through — '!== null' branches with ?: explode patterns
        self::assertStringContainsString(
            "\$entitiesArg !== null",
            $source,
            'D-10: null CLI arg must fall through to Settings default.',
        );
        self::assertStringContainsString("\$localesArg !== null", $source);
        self::assertStringContainsString("\$sinceArg !== null", $source);

        // empty-string clears the default
        self::assertStringContainsString(
            "=== ''",
            $source,
            "D-10: empty CLI arg ('') must clear the default.",
        );

        // comma-split with trim for entities and locales
        self::assertStringContainsString("explode(',', \$entitiesArg)", $source);
        self::assertStringContainsString("explode(',', \$localesArg)", $source);
        self::assertStringContainsString("array_map('trim',", $source);

        // Reads from Settings::default* via Plugin::getInstance()
        self::assertStringContainsString('defaultEntities', $source);
        self::assertStringContainsString('defaultLocales', $source);
        self::assertStringContainsString('defaultSince', $source);
        self::assertStringContainsString('Plugin::getInstance()->getSettings()', $source);
        self::assertStringContainsString('relationGraph: $relationGraph', $source);
    }

    public function testReturnsNewMigrationFilters(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(FilterFactory::class))->getFileName());
        self::assertStringContainsString(
            'new MigrationFilters',
            $source,
            'fromCli must construct and return a MigrationFilters.',
        );
    }

    public function testNormalizesSourceEntityFiltersDeterministically(): void
    {
        self::assertSame(
            ['NewsPage', 'App\\Entity\\Pages\\CaseStudyPage', 'CaseStudyPage', 'App\\Entity\\NewsPage'],
            FilterFactory::normalizeEntityFilters([
                ' NewsPage ',
                'App\\Entity\\Pages\\CaseStudyPage',
                'NewsPage',
                '',
                'App\\Entity\\NewsPage',
            ]),
            'D-14: entity filters are source identities, keep exact FQCNs, add basenames, and de-dupe deterministically.',
        );
    }

    public function testExplicitEntityFiltersMatchSourceFqcnAndBasenameForms(): void
    {
        $fqcnScoped = new MigrationFilters(
            entities: FilterFactory::normalizeEntityFilters(['App\\Entity\\Pages\\CaseStudyPage']),
        );
        self::assertTrue($fqcnScoped->allows('App\\Entity\\Pages\\CaseStudyPage'));
        self::assertTrue($fqcnScoped->allows('CaseStudyPage'));
        self::assertFalse($fqcnScoped->allows('caseStudy'), 'D-14: Craft-style handles are not source entity identities.');

        $basenameScoped = new MigrationFilters(
            entities: FilterFactory::normalizeEntityFilters(['NewsPage']),
        );
        self::assertTrue($basenameScoped->allows('NewsPage'));
        self::assertTrue($basenameScoped->allows('App\\Entity\\Pages\\NewsPage'));
        self::assertFalse($basenameScoped->allows('news'), 'D-14: do not infer Craft handles from source entity filters.');
    }

}
