<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\filter;

use lameco\kunstmaanmigrator\filter\MigrationFilters;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * Characterization tests for the MigrationFilters value object (Plan 01).
 *
 * D-12: maxPerEntity must NOT exist on this class.
 * D-13: VO is immutable; readonly enforces this at the language level.
 *
 * Phase 4.1 / D-26 extends the VO with `noSeo` + `noRetour` readonly bool
 * properties (CLI override flags). The "exactly three properties" assertion
 * is therefore retired; the test now locks the (entities, locales, since,
 * noSeo, noRetour) shape — five properties, all readonly.
 */
final class MigrationFiltersTest extends TestCase
{
    public function testDefaultConstructorReturnsEmptyEntitiesAndLocalesWithNullSince(): void
    {
        $f = new MigrationFilters();
        self::assertSame([], $f->entities);
        self::assertSame([], $f->locales);
        self::assertNull($f->since);
    }

    public function testNamedArgConstructorPreservesValues(): void
    {
        $f = new MigrationFilters(
            entities: ['NewsPage', 'EventPage'],
            locales:  ['nl', 'fr'],
            since:    '2025-01-01',
        );
        self::assertSame(['NewsPage', 'EventPage'], $f->entities);
        self::assertSame(['nl', 'fr'], $f->locales);
        self::assertSame('2025-01-01', $f->since);
    }

    public function testClassHasExpectedPublicProperties(): void
    {
        // D-12: --max-per-entity is DROPPED.
        // Phase 4.1 / D-26: VO extended with noSeo + noRetour CLI override flags.
        $rc = new ReflectionClass(MigrationFilters::class);
        $publicProps = array_filter(
            $rc->getProperties(),
            static fn(ReflectionProperty $p): bool => $p->isPublic() && !$p->isStatic(),
        );
        $names = array_map(static fn(ReflectionProperty $p): string => $p->getName(), $publicProps);
        sort($names);
        self::assertSame(['entities', 'locales', 'noRetour', 'noSeo', 'since'], array_values($names));
    }

    public function testNoSeoAndNoRetourDefaultFalse(): void
    {
        // Phase 4.1 / D-26: defaults preserve Phase 2/3/4 callers (no behavior change
        // unless the operator explicitly sets the flag).
        $f = new MigrationFilters();
        self::assertFalse($f->noSeo);
        self::assertFalse($f->noRetour);
    }

    public function testNoSeoAndNoRetourPropertiesAreReadonly(): void
    {
        $rc = new ReflectionClass(MigrationFilters::class);
        self::assertTrue($rc->getProperty('noSeo')->isReadOnly());
        self::assertTrue($rc->getProperty('noRetour')->isReadOnly());
    }

    public function testNoMaxPerEntityProperty(): void
    {
        // D-12 explicit: max-per-entity is dropped. Hard-fail if it ever resurfaces.
        $rc = new ReflectionClass(MigrationFilters::class);
        self::assertFalse(
            $rc->hasProperty('maxPerEntity'),
            'D-12: --max-per-entity is dropped from v1.0; MigrationFilters must not declare maxPerEntity.',
        );
    }

    public function testEntitiesPropertyIsReadonly(): void
    {
        $rc = new ReflectionClass(MigrationFilters::class);
        self::assertTrue(
            $rc->getProperty('entities')->isReadOnly(),
            'D-13: VO must be immutable.',
        );
    }

    public function testLocalesPropertyIsReadonly(): void
    {
        $rc = new ReflectionClass(MigrationFilters::class);
        self::assertTrue($rc->getProperty('locales')->isReadOnly());
    }

    public function testSincePropertyIsReadonly(): void
    {
        $rc = new ReflectionClass(MigrationFilters::class);
        self::assertTrue($rc->getProperty('since')->isReadOnly());
    }

    public function testClassIsFinal(): void
    {
        $rc = new ReflectionClass(MigrationFilters::class);
        self::assertTrue($rc->isFinal(), 'MigrationFilters must be final.');
    }
}
