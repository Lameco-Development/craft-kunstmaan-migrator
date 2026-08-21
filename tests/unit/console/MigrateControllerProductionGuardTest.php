<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\console;

use lameco\kunstmaanmigrator\console\MigrateController;
use lameco\kunstmaanmigrator\NeverProductionTrait;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use yii\console\ExitCode;

/**
 * `migrate` is the command that writes the whole corpus, repoints Craft's
 * `legacyDb` component and mutates five long-lived services. It was the only
 * write command with no production guard: the trait protected `load` and
 * `doctor`, and `state` documents why it does not need one.
 *
 * The controller is `final`, so the stderr-capturing subclass that
 * LoadControllerTest uses is not available here. The refusal message itself is
 * already covered by NeverProductionTraitTest; what these tests own is that
 * `migrate` is wired to the gate at all, and that a refusal survives Craft's
 * `ControllerTrait::runAction()` coercing a `null` result to `ExitCode::OK`.
 */
final class MigrateControllerProductionGuardTest extends TestCase
{
    private function uninitializedController(): MigrateController
    {
        return (new ReflectionClass(MigrateController::class))->newInstanceWithoutConstructor();
    }

    /** @param callable(): void $body */
    private function withEnvironment(?string $environment, callable $body): void
    {
        $hadPrevious = array_key_exists('CRAFT_ENVIRONMENT', $_SERVER);
        $previous = $_SERVER['CRAFT_ENVIRONMENT'] ?? null;

        if ($environment === null) {
            unset($_SERVER['CRAFT_ENVIRONMENT']);
        } else {
            $_SERVER['CRAFT_ENVIRONMENT'] = $environment;
        }

        try {
            $body();
        } finally {
            if ($hadPrevious) {
                $_SERVER['CRAFT_ENVIRONMENT'] = $previous;
            } else {
                unset($_SERVER['CRAFT_ENVIRONMENT']);
            }
        }
    }

    public function testMigrateUsesTheProductionGuard(): void
    {
        self::assertContains(
            NeverProductionTrait::class,
            class_uses(MigrateController::class),
            'migrate writes more than any other command and must not be the one without a guard.',
        );
    }

    public function testBeforeActionRefusesAndStashesANonZeroExitCodeInProduction(): void
    {
        $this->withEnvironment('production', function (): void {
            $controller = $this->uninitializedController();

            self::assertFalse(
                $controller->beforeAction('index'),
                'beforeAction() must refuse to run in production.',
            );

            $stashed = (new ReflectionProperty(MigrateController::class, 'neverProductionExitCode'))
                ->getValue($controller);

            self::assertSame(ExitCode::UNSPECIFIED_ERROR, $stashed);
            self::assertNotSame(ExitCode::OK, $stashed, 'a refusal that exits 0 is not a refusal.');
        });
    }

    public function testNothingIsStashedOutsideProduction(): void
    {
        $this->withEnvironment('dev', function (): void {
            $controller = $this->uninitializedController();

            $stashed = (new ReflectionProperty(MigrateController::class, 'neverProductionExitCode'))
                ->getValue($controller);

            self::assertNull($stashed, 'the gate must not stash an exit code it never set.');
        });
    }
}
