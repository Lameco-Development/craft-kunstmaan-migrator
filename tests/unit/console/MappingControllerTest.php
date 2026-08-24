<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\console;

use Lameco\Kunstmaanmigrator\console\MappingController;
use Lameco\Kunstmaanmigrator\safety\NeverProductionTrait;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Making a mapping is part of the plugin now.
 *
 * The engine has existed since the DSL did, as a second binary shipped inside
 * the plugin. A plugin you install and then have to find a vendored CLI inside
 * is not one you can hand to somebody.
 */
final class MappingControllerTest extends TestCase
{
    public function testTheTwoCommandsThatStartAMigrationAreCraftCommands(): void
    {
        $class = new ReflectionClass(MappingController::class);

        self::assertTrue($class->hasMethod('actionInit'), 'discovery must be reachable without the vendored binary');
        self::assertTrue($class->hasMethod('actionCheck'), 'validating a mapping must be reachable too');
    }

    /**
     * Discovery reads legacy databases, so it is a legacy-reading command and
     * refuses to run against production like every other one.
     */
    public function testDiscoveryIsGuardedLikeEveryOtherLegacyReadingCommand(): void
    {
        self::assertContains(
            NeverProductionTrait::class,
            class_uses(MappingController::class) ?: [],
        );
    }

    /**
     * A Kunstmaan corpus is routinely several databases, and which locales each
     * one publishes is a per-database fact — so discovery has to see them all
     * at once to write the environments block.
     */
    public function testDiscoveryTakesEveryEnvironmentAtOnce(): void
    {
        $controller = (new ReflectionClass(MappingController::class))->newInstanceWithoutConstructor();

        self::assertContains('environments', $controller->options('init'));
        self::assertContains('source', $controller->options('init'));
        self::assertContains('introspection', $controller->options('init'));
    }

    /**
     * The mapping is the migration. An accidental `init` over a finished one is
     * hours of decisions gone, so it refuses rather than overwrites — a rule
     * that lives in the shared engine, so the vendored binary refuses too.
     */
    public function testInitRefusesToOverwriteAnExistingMapping(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/lib/kuma-compile/src/Mapping/MappingInit.php'
        );

        self::assertStringContainsString('refusing to overwrite a mapping', $source);
        self::assertStringContainsString(
            'MappingInit::write',
            (string) file_get_contents(dirname(__DIR__, 3) . '/src/console/MappingController.php'),
        );
    }
}
