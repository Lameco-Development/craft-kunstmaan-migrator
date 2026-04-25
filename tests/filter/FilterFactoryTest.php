<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\filter;

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
        $rc = new ReflectionClass(FilterFactory::class);
        self::assertTrue($rc->hasMethod('fromCli'));
        $m = new ReflectionMethod(FilterFactory::class, 'fromCli');
        self::assertCount(3, $m->getParameters(), 'fromCli takes 3 args: entitiesArg, localesArg, sinceArg.');
        $names = array_map(static fn(ReflectionParameter $p): string => $p->getName(), $m->getParameters());
        self::assertSame(['entitiesArg', 'localesArg', 'sinceArg'], $names);
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
}
