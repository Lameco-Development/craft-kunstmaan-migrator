<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\load;

use lameco\kunstmaanmigrator\load\NavigationMigrationService;
use lameco\kunstmaanmigrator\sites\SiteMap;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

/**
 * The NodeMenu pass read a variable it was never given.
 *
 * `$sites` was a property until the site map became a per-call value; the
 * refactor updated migrateAll()'s signature and left this method reading a name
 * that no longer existed in its scope. PHP raises a warning, Craft turns it into
 * an exception, and the adapter summariser catches it — so the pass reported
 * `error: Undefined variable $sites` and migrated nothing, while the run
 * reported no failure at all.
 *
 * A static check rather than a behavioural one, because behaviour here needs
 * verbb, a nav and a database. What it pins is the thing that actually broke:
 * the method must receive every collaborator it reads.
 */
final class NavigationNodeMenuScopeTest extends TestCase
{
    public function testTheNodeMenuPassReceivesTheSiteMapItReads(): void
    {
        $method = (new ReflectionClass(NavigationMigrationService::class))->getMethod('migrateNodeMenu');

        $types = [];

        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();
            $types[] = $type instanceof ReflectionNamedType ? $type->getName() : null;
        }

        self::assertContains(SiteMap::class, $types, 'migrateNodeMenu reads $sites; it must be given one.');
    }

}
