<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\utilities;

use craft\services\Utilities;
use lameco\kunstmaanmigrator\controllers\MigrationController;
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

    public function testTheQueueButtonPostsToAnActionThatExists(): void
    {
        preg_match("~sendActionRequest\('POST', '([^']+)'~", $this->template(), $matches);

        self::assertSame('kunstmaan-migrator/migration/queue', $matches[1] ?? null);
        self::assertTrue(
            (new ReflectionClass(MigrationController::class))->hasMethod('actionQueue'),
            'kunstmaan-migrator/migration/queue resolves to MigrationController::actionQueue',
        );
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

        self::assertStringContainsString("requirePermission('utility:" . MigrationUtility::id() . "')", $controller);
    }

    /**
     * Two independent refusals: the button says so before anything is queued,
     * and the job says so again in case something else enqueued it.
     */
    public function testProductionIsRefusedAtTheButtonAsWellAsInTheJob(): void
    {
        $controller = (string) file_get_contents($this->root() . '/src/controllers/MigrationController.php');
        $job = (string) file_get_contents($this->root() . '/src/queue/MigrateEnvironmentJob.php');

        foreach ([$controller, $job] as $source) {
            self::assertStringContainsString("App::env('CRAFT_ENVIRONMENT') === 'production'", $source);
        }
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
