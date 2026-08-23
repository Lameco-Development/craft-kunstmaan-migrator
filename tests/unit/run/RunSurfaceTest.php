<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\run;

use lameco\kunstmaanmigrator\controllers\MigrationController;
use lameco\kunstmaanmigrator\ProductionGuard;
use lameco\kunstmaanmigrator\Plugin;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The couplings between the run screen, its template and the action behind
 * the button — all of which fail silently.
 *
 * The template needs a booted control panel to render, so none of it runs
 * here. A button posting to an action that does not exist looks fine until
 * someone presses it.
 */
final class RunSurfaceTest extends TestCase
{
    private function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private function template(): string
    {
        $path = $this->root() . '/src/templates/_run-panel.twig';
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
        self::assertNotEmpty($routes, 'the run screen is useless without actions');

        $reflection = new ReflectionClass(MigrationController::class);

        // Craft's own actions are fair game — the run screen reads job progress
        // from the queue rather than reinventing it — but only the ones named
        // here, so a typo in a core route still fails rather than being waved
        // through as "probably Craft's".
        $craftRoutes = ['queue/get-job-info'];

        foreach ($routes as $route) {
            if (in_array($route, $craftRoutes, true)) {
                continue;
            }

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
     * The run used to be a Utility, and its screens lived in two nav areas.
     * Now it is a page of the section: the route, the subnav item and the
     * action all have to agree, and no Utilities registration may creep back —
     * that would put the workflow in two places again.
     */
    public function testTheRunIsAPageOfTheSectionAndNotAUtility(): void
    {
        $plugin = (string) file_get_contents($this->root() . '/src/Plugin.php');

        self::assertStringContainsString("'kunstmaan-migrator/run'", $plugin);
        self::assertStringContainsString("'kunstmaan-migrator/migration/run'", $plugin);
        self::assertStringNotContainsString('Utilities::', $plugin);
        self::assertTrue(
            (new ReflectionClass(MigrationController::class))->hasMethod('actionRun'),
            'the run route resolves to MigrationController::actionRun(), which does not exist',
        );
    }

    /**
     * The permission the controllers require has to be the one Craft grants
     * for this plugin's CP section, or every screen 403s for non-admins.
     */
    public function testEveryActionRequiresTheSectionPermission(): void
    {
        $controller = (string) file_get_contents($this->root() . '/src/controllers/MigrationController.php');

        // Every action, not only the one that writes: a read the operator
        // cannot see is as broken as a write they cannot make.
        preg_match_all('~public function (action\w+)~', $controller, $actions);
        self::assertNotEmpty($actions[1]);

        self::assertSame(
            count($actions[1]),
            substr_count($controller, 'requirePermission(Plugin::PERMISSION)'),
            'every action must require the permission Craft grants for this section',
        );

        self::assertSame('accessPlugin-kunstmaan-migrator', Plugin::PERMISSION);
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
}
