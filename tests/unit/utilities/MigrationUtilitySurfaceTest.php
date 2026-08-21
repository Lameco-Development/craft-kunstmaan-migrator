<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\utilities;

use craft\services\Utilities;
use lameco\kunstmaanmigrator\controllers\MigrationController;
use lameco\kunstmaanmigrator\ProductionGuard;
use lameco\kunstmaanmigrator\utilities\MigrationUtility;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The couplings between the utility, its template and the action behind the
 * button — all of which fail silently.
 *
 * The template needs a booted control panel to render, so none of it runs
 * here. A button posting to an action that does not exist looks fine until
 * someone presses it.
 */
final class MigrationUtilitySurfaceTest extends TestCase
{
    private function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private function template(): string
    {
        $path = $this->root() . '/src/templates/_utility.twig';
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /**
     * Every action the template reaches for, not just the one that existed
     * when this test was written — a button wired to a missing action looks
     * fine until someone presses it, and the screen keeps gaining buttons.
     */
    public function testEveryActionTheTemplateCallsExists(): void
    {
        $template = $this->template();

        preg_match_all("~sendActionRequest\('POST', '([^']+)'~", $template, $posts);
        preg_match_all("~actionUrl\('([^']+)'\)~", $template, $links);

        $routes = array_merge($posts[1], $links[1]);
        self::assertNotEmpty($routes, 'the utility is useless without actions');

        $reflection = new ReflectionClass(MigrationController::class);

        foreach ($routes as $route) {
            $parts = explode('/', $route);
            self::assertSame('kunstmaan-migrator', $parts[0], sprintf('%s is not this plugin\'s route', $route));
            self::assertSame('migration', $parts[1], sprintf('%s does not resolve to MigrationController', $route));

            $method = 'action' . str_replace(' ', '', ucwords(str_replace('-', ' ', $parts[2])));
            self::assertTrue(
                $reflection->hasMethod($method),
                sprintf('%s resolves to MigrationController::%s(), which does not exist', $route, $method),
            );
        }
    }

    public function testTheControllerIsWhereTheWebNamespacePoints(): void
    {
        $plugin = (string) file_get_contents($this->root() . '/src/Plugin.php');

        self::assertStringContainsString("'lameco\\\\kunstmaanmigrator\\\\controllers'", $plugin);
        self::assertSame(
            'lameco\kunstmaanmigrator\controllers',
            (new ReflectionClass(MigrationController::class))->getNamespaceName(),
        );
    }

    /**
     * The first version of this test asserted the event constant's NAME appeared
     * in Plugin.php, which is the "test the string, not the thing" mistake this
     * codebase has been removing all week: it passed happily against
     * EVENT_REGISTER_UTILITY_TYPES, which is Craft 4's name and does not exist
     * in Craft 5. The plugin-load smoke job caught it by booting a real Craft.
     *
     * Asserting the constant resolves is the check that would have failed.
     */
    public function testTheUtilityIsRegisteredAsAUtilityAndNotAsACpSection(): void
    {
        $plugin = (string) file_get_contents($this->root() . '/src/Plugin.php');

        preg_match('~Utilities::(EVENT_\\w+)~', $plugin, $matches);
        self::assertNotEmpty($matches, 'the utility must be registered through a Utilities event');
        self::assertTrue(
            defined(Utilities::class . '::' . $matches[1]),
            sprintf('craft\\services\\Utilities::%s does not exist in this Craft version', $matches[1]),
        );

        self::assertStringContainsString('MigrationUtility::class', $plugin);
        self::assertStringNotContainsString(
            'EVENT_REGISTER_CP_NAV_ITEMS',
            $plugin,
            'a tool used a handful of times per project does not belong in the nav beside Entries',
        );
    }

    /**
     * The permission the controller requires has to be the one Craft grants
     * for this utility, or the button 403s for everyone but an admin.
     */
    public function testTheActionRequiresThePermissionThisUtilityGrants(): void
    {
        $controller = (string) file_get_contents($this->root() . '/src/controllers/MigrationController.php');

        // Every action, not only the one that writes: a read the operator
        // cannot see is as broken as a write they cannot make.
        preg_match_all('~public function (action\w+)~', $controller, $actions);
        self::assertNotEmpty($actions[1]);

        self::assertSame(
            count($actions[1]),
            substr_count($controller, "requirePermission('utility:' . MigrationUtility::id())"),
            'every action must require the permission Craft grants for this utility',
        );
    }

    /**
     * Two independent refusals: the button says so before anything is queued,
     * and the job says so again in case something else enqueued it. Both ask
     * ProductionGuard rather than re-reading the environment, so there is one
     * definition of what production means.
     */
    public function testProductionIsRefusedAtTheButtonAsWellAsInTheJob(): void
    {
        $controller = (string) file_get_contents($this->root() . '/src/controllers/MigrationController.php');
        $job = (string) file_get_contents($this->root() . '/src/queue/MigrateEnvironmentJob.php');

        foreach ([$controller, $job] as $source) {
            self::assertStringContainsString('ProductionGuard::isProduction()', $source);
            self::assertStringNotContainsString(
                "CRAFT_ENVIRONMENT') === 'production'",
                $source,
                'the predicate belongs to ProductionGuard, not to each caller',
            );
        }

        self::assertTrue(
            method_exists(ProductionGuard::class, 'isProduction'),
            'ProductionGuard::isProduction() is what both of them call',
        );
    }

    public function testTheRunIsHandedToTheQueueRatherThanDoneInTheRequest(): void
    {
        $controller = (string) file_get_contents($this->root() . '/src/controllers/MigrationController.php');

        self::assertStringContainsString('getQueue()->push(new MigrateEnvironmentJob(', $controller);
        self::assertStringNotContainsString(
            'EnvironmentPipeline',
            $controller,
            'a migration is hours and a web request is seconds',
        );
    }

    public function testTheUtilityIdIsStable(): void
    {
        self::assertSame('kunstmaan-migration', MigrationUtility::id());
    }
}
