<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\queue;

use lameco\kunstmaanmigrator\console\DoctorController;
use lameco\kunstmaanmigrator\console\LoadController;
use lameco\kunstmaanmigrator\console\StateController;
use lameco\kunstmaanmigrator\controllers\MigrationController;
use lameco\kunstmaanmigrator\queue\FinalizeJob;
use lameco\kunstmaanmigrator\queue\MigrateEnvironmentJob;
use lameco\kunstmaanmigrator\queue\ResolveDeferredRefsJob;
use lameco\kunstmaanmigrator\ProductionGuard;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * Everything the console can do, the control panel can do.
 *
 * The migrator was console-only, which meant every operation needed a terminal
 * and a remembered flag. These pin the parity so a pass added to one side does
 * not quietly stay there.
 */
final class MigrationPassCoverageTest extends TestCase
{
    private function source(string $relative): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/' . $relative);
    }

    public function testEveryConsoleActionHasAControlPanelRoute(): void
    {
        $controller = $this->source('src/controllers/MigrationController.php');
        $template = $this->source('src/templates/_utility.twig');

        $coverage = [
            'doctor' => 'actionDoctor',
            'state/export' => 'actionExport',
            'migrate' => 'actionQueue',
        ];

        $reflection = new ReflectionClass(MigrationController::class);

        foreach ($coverage as $consoleCommand => $method) {
            self::assertTrue(
                $reflection->hasMethod($method),
                sprintf('`%s` has no control-panel equivalent', $consoleCommand),
            );
        }

        // Every pass `migrate` exposes as a flag is reachable from the run
        // screen. How it is offered is the screen's business — a full run is
        // one button and the recovery passes live behind a disclosure — but a
        // pass that is reachable only from a terminal is not.
        foreach (['full', 'entries', 'fixup', 'finalize'] as $pass) {
            self::assertMatchesRegularExpression(
                sprintf('~(?:value: \'%1$s\'|data-pass="%1$s"|queue\(\'%1$s\')~', $pass),
                $template,
                sprintf('the run screen offers no "%s" pass', $pass),
            );
            self::assertStringContainsString(sprintf("'%s'", $pass), $controller);
        }
    }

    public function testTheThreeQueueablePassesHaveJobs(): void
    {
        foreach ([MigrateEnvironmentJob::class, FinalizeJob::class, ResolveDeferredRefsJob::class] as $job) {
            self::assertTrue(class_exists($job));
            self::assertTrue((new ReflectionClass($job))->hasMethod('execute'));
        }
    }

    /**
     * Every job refuses independently. A job is reached by whatever put it on
     * the queue, and that is not always the button that checks.
     */
    public function testEveryJobRefusesProduction(): void
    {
        foreach (['MigrateEnvironmentJob', 'FinalizeJob', 'ResolveDeferredRefsJob'] as $job) {
            self::assertStringContainsString(
                'ProductionGuard::isProduction()',
                $this->source('src/queue/' . $job . '.php'),
                sprintf('%s must refuse production on its own', $job),
            );
        }
    }

    public function testTheFinalizeJobRefusesProduction(): void
    {
        $had = array_key_exists('CRAFT_ENVIRONMENT', $_SERVER);
        $previous = $_SERVER['CRAFT_ENVIRONMENT'] ?? null;
        $_SERVER['CRAFT_ENVIRONMENT'] = 'production';

        try {
            $job = (new ReflectionClass(FinalizeJob::class))->newInstanceWithoutConstructor();

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Refusing to finalize');

            $job->execute(null);
        } finally {
            if ($had) {
                $_SERVER['CRAFT_ENVIRONMENT'] = $previous;
            } else {
                unset($_SERVER['CRAFT_ENVIRONMENT']);
            }
        }
    }

    public function testTheFixupJobRefusesProduction(): void
    {
        $had = array_key_exists('CRAFT_ENVIRONMENT', $_SERVER);
        $previous = $_SERVER['CRAFT_ENVIRONMENT'] ?? null;
        $_SERVER['CRAFT_ENVIRONMENT'] = 'production';

        try {
            $job = (new ReflectionClass(ResolveDeferredRefsJob::class))->newInstanceWithoutConstructor();

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Refusing to resolve references');

            $job->execute(null);
        } finally {
            if ($had) {
                $_SERVER['CRAFT_ENVIRONMENT'] = $previous;
            } else {
                unset($_SERVER['CRAFT_ENVIRONMENT']);
            }
        }
    }

    /**
     * The console doctor and the control-panel doctor answer the same six
     * questions because they call the same service. The checks used to be
     * private methods on a console controller, which is why asking required a
     * terminal.
     */
    public function testConsoleAndControlPanelDoctorShareOneImplementation(): void
    {
        $console = $this->source('src/console/DoctorController.php');
        $controller = $this->source('src/controllers/MigrationController.php');

        foreach ([$console, $controller] as $source) {
            self::assertStringContainsString('Diagnostics()', $source);
        }

        self::assertStringNotContainsString(
            'private function checkStateTable',
            $console,
            'the checks live in Diagnostics now, not on the console controller',
        );
    }

    /**
     * `state/export` builds its rows from a public static that both callers
     * use, so the file the control panel offers is byte-identical to the one
     * the console writes.
     */
    public function testTheExportIsTheSameRowsTheConsoleWrites(): void
    {
        self::assertTrue(
            (new ReflectionClass(StateController::class))->getMethod('buildExportRows')->isStatic(),
        );
        self::assertStringContainsString(
            'StateController::buildExportRows(',
            $this->source('src/controllers/MigrationController.php'),
        );
    }

    /**
     * `load/entry` and `load/redirects` read a payload file that `--dump`
     * writes. They stay console-only deliberately: the input is a path on the
     * machine running the command, and the file is a debugging artifact rather
     * than something a migration produces on its own.
     */
    public function testThePayloadFileCommandsAreDeliberatelyConsoleOnly(): void
    {
        $reflection = new ReflectionClass(LoadController::class);

        foreach (['actionEntry', 'actionRedirects'] as $method) {
            self::assertTrue($reflection->hasMethod($method));
        }

        self::assertFalse(
            (new ReflectionClass(MigrationController::class))->hasMethod('actionLoadPayload'),
            'if this gains a control-panel route, this test should say so rather than be deleted',
        );
    }

    public function testProductionIsOneDefinition(): void
    {
        self::assertTrue(method_exists(ProductionGuard::class, 'isProduction'));

        self::assertStringContainsString(
            'ProductionGuard::isProduction()',
            $this->source('src/NeverProductionTrait.php'),
            'the console refusal reads the same predicate the checks do',
        );
        self::assertStringContainsString(
            'ProductionGuard::isProduction()',
            $this->source('src/run/Diagnostics.php'),
        );
        self::assertTrue((new ReflectionClass(DoctorController::class))->hasMethod('actionIndex'));
    }
}
