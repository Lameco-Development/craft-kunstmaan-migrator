<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\console;

use lameco\kunstmaanmigrator\console\DoctorController;
use lameco\kunstmaanmigrator\NeverProductionTrait;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use yii\console\ExitCode;

/**
 * Captures stdout()/stderr() writes instead of hitting the real streams —
 * same technique LoadControllerTest's OutputCapturingLoadController uses,
 * applied here so DoctorController::checkNotProduction() (which calls
 * NeverProductionTrait::enforceNeverProduction(), which writes to stderr on
 * a production refusal) is exercisable without a booted Craft application.
 */
final class OutputCapturingDoctorController extends DoctorController
{
    public string $capturedStdout = '';
    public string $capturedStderr = '';

    public function stdout($string)
    {
        $this->capturedStdout .= $string;

        return strlen($string);
    }

    public function stderr($string)
    {
        $this->capturedStderr .= $string;

        return strlen($string);
    }
}

/**
 * Task 6 — DoctorController rewrite for the loader-only v2 world: a JSON
 * list of `{check, ok, detail}` rows, exit non-zero if any `ok` is false.
 *
 * Three of the four checks (state table, storage writable, Retour
 * presence) touch Craft::$app (plugins/db/path services) this repo's test
 * suite cannot boot — see FixupTest's docblock for why. Like
 * LoadControllerTest, this exercises the Craft-app-free surface directly:
 * the pure `exitCodeFor()` aggregation, and `checkNotProduction()` itself
 * (the one check that only needs NeverProductionTrait's `App::env()` read).
 */
final class DoctorControllerTest extends TestCase
{
    private function outputCapturingController(): OutputCapturingDoctorController
    {
        return (new ReflectionClass(OutputCapturingDoctorController::class))->newInstanceWithoutConstructor();
    }

    public function testUsesNeverProductionTrait(): void
    {
        self::assertContains(NeverProductionTrait::class, class_uses(DoctorController::class));
    }

    // --- exitCodeFor() — pure aggregation ----------------------------------

    public function testExitCodeIsOkWhenEveryCheckPasses(): void
    {
        $checks = [
            ['check' => 'state_table', 'ok' => true, 'detail' => 'ok'],
            ['check' => 'storage_writable', 'ok' => true, 'detail' => 'ok'],
            ['check' => 'not_production', 'ok' => true, 'detail' => 'ok'],
            ['check' => 'retour_presence', 'ok' => true, 'detail' => 'ok'],
        ];

        self::assertSame(ExitCode::OK, DoctorController::exitCodeFor($checks));
    }

    public function testExitCodeIsNonZeroWhenAnyCheckFails(): void
    {
        $checks = [
            ['check' => 'state_table', 'ok' => true, 'detail' => 'ok'],
            ['check' => 'not_production', 'ok' => false, 'detail' => 'CRAFT_ENVIRONMENT=production'],
        ];

        self::assertSame(ExitCode::UNSPECIFIED_ERROR, DoctorController::exitCodeFor($checks));
    }

    public function testExitCodeForEmptyChecksListIsOk(): void
    {
        self::assertSame(ExitCode::OK, DoctorController::exitCodeFor([]));
    }

    // --- JSON shape ---------------------------------------------------------

    public function testCheckRowsEncodeToTheDocumentedCheckOkDetailShape(): void
    {
        $checks = [['check' => 'x', 'ok' => true, 'detail' => 'y']];
        $json = json_encode($checks, JSON_UNESCAPED_SLASHES);

        self::assertIsString($json);
        $decoded = json_decode($json, true);
        self::assertSame(['check', 'ok', 'detail'], array_keys($decoded[0]));
    }

    // --- checkNotProduction() — the one Craft-app-free check ---------------

    public function testCheckNotProductionIsOkOutsideProduction(): void
    {
        $hadPrevious = array_key_exists('CRAFT_ENVIRONMENT', $_SERVER);
        $previous = $_SERVER['CRAFT_ENVIRONMENT'] ?? null;
        unset($_SERVER['CRAFT_ENVIRONMENT']);

        try {
            $controller = $this->outputCapturingController();
            $result = (new ReflectionMethod(DoctorController::class, 'checkNotProduction'))->invoke($controller);

            self::assertSame(['check', 'ok', 'detail'], array_keys($result));
            self::assertSame('not_production', $result['check']);
            self::assertTrue($result['ok']);
            self::assertSame('', $controller->capturedStderr);
        } finally {
            if ($hadPrevious) {
                $_SERVER['CRAFT_ENVIRONMENT'] = $previous;
            } else {
                unset($_SERVER['CRAFT_ENVIRONMENT']);
            }
        }
    }

    public function testCheckNotProductionFailsInProductionAndTheFailureFlipsTheOverallExitCode(): void
    {
        $hadPrevious = array_key_exists('CRAFT_ENVIRONMENT', $_SERVER);
        $previous = $_SERVER['CRAFT_ENVIRONMENT'] ?? null;
        $_SERVER['CRAFT_ENVIRONMENT'] = 'production';

        try {
            $controller = $this->outputCapturingController();
            $result = (new ReflectionMethod(DoctorController::class, 'checkNotProduction'))->invoke($controller);

            self::assertSame('not_production', $result['check']);
            self::assertFalse($result['ok']);
            self::assertStringContainsString('production', $result['detail']);
            self::assertStringContainsString(
                'Refusing to run against CRAFT_ENVIRONMENT=production',
                $controller->capturedStderr,
            );

            // This is the JSON-shape + non-zero-exit contract the brief asks
            // this test to cover: one failing check row flips the whole run
            // to a non-zero exit via the same exitCodeFor() every check uses.
            self::assertSame(ExitCode::UNSPECIFIED_ERROR, DoctorController::exitCodeFor([$result]));
        } finally {
            if ($hadPrevious) {
                $_SERVER['CRAFT_ENVIRONMENT'] = $previous;
            } else {
                unset($_SERVER['CRAFT_ENVIRONMENT']);
            }
        }
    }

    // --- removed / replaced checks (v2 loader prune) ------------------------

    public function testRemovedChecksAndStaleRemediationCopyAreGoneFromSource(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/src/console/DoctorController.php');

        self::assertStringNotContainsString(
            'checkLegacyDb',
            $source,
            'Legacy-DB reachability is orchestration-side now — doctor never reads the legacy DB.',
        );
        self::assertStringNotContainsString(
            'checkExtTranslations',
            $source,
            'ext_translations presence is analyze/taxonomy-stage machinery, removed from the loader-only doctor.',
        );
        self::assertStringNotContainsString(
            'checkAdapterPlugins',
            $source,
            'The SEOmatic informational check is removed — only Retour presence remains (Task 6 check set).',
        );
        self::assertStringNotContainsString(
            'migrate/install',
            $source,
            'Task 3 deleted the migrate/install command; doctor must not tell operators to run it.',
        );
        self::assertStringContainsString(
            'plugin/install',
            $source,
            "The state-table remediation must point at Craft's native plugin/install instead.",
        );
    }
}
