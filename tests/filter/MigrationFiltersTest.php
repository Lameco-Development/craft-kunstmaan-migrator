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
 * D-12: VO has THREE properties — entities, locales, since. NOT four.
 *       maxPerEntity must NOT exist on this class.
 *
 * D-13: VO is immutable; readonly enforces this at the language level.
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

    public function testClassHasExactlyThreePublicProperties(): void
    {
        // D-12: --max-per-entity is DROPPED. The VO must have exactly 3 properties.
        $rc = new ReflectionClass(MigrationFilters::class);
        $publicProps = array_filter(
            $rc->getProperties(),
            static fn(ReflectionProperty $p): bool => $p->isPublic() && !$p->isStatic(),
        );
        $names = array_map(static fn(ReflectionProperty $p): string => $p->getName(), $publicProps);
        sort($names);
        self::assertSame(['entities', 'locales', 'since'], array_values($names));
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
