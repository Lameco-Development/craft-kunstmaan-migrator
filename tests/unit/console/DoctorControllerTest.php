<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\console;

use lameco\kunstmaanmigrator\console\DoctorController;
use lameco\kunstmaanmigrator\run\Diagnostics;
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
 * Four of the six checks (state table, storage writable, Retour presence,
 * legacy DB) touch Craft::$app / `Yii::$app` (plugins/db/path services,
 * `Plugin::getInstance()` itself resolves via `Yii::$app->loadedModules`)
 * this repo's test suite cannot boot — see FixupTest's docblock for why.
 * Like LoadControllerTest, this exercises the Craft-app-free surface
 * directly: the pure `exitCodeFor()` aggregation, `checkNotProduction()`
 * (the one original check that only needs NeverProductionTrait's
 * `App::env()` read), and Task 8's `checkLegacyMediaRoot()` (same
 * `App::env()` seam, no Craft/Yii touch at all).
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
            $result = (new ReflectionMethod(Diagnostics::class, 'checkNotProduction'))->invoke(new Diagnostics());

            self::assertSame(['check', 'ok', 'detail'], array_keys($result));
            self::assertSame('not_production', $result['check']);
            self::assertTrue($result['ok']);
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
            $result = (new ReflectionMethod(Diagnostics::class, 'checkNotProduction'))->invoke(new Diagnostics());

            self::assertSame('not_production', $result['check']);
            self::assertFalse($result['ok']);
            self::assertStringContainsString('production', $result['detail']);

            // Asking no longer refuses. ProductionGuard answers the question and
            // NeverProductionTrait writes the refusal, which is what lets the
            // control panel run doctor without pretending to be a terminal — the
            // refusal line itself is covered by NeverProductionTraitTest.

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
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/src/run/Diagnostics.php');

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

    // --- checkLegacyMediaRoot() — Craft-app-free (App::env() only) ---------

    public function testCheckLegacyMediaRootIsInformationalWhenEnvVarIsUnset(): void
    {
        $hadPrevious = array_key_exists('LEGACY_MEDIA_PATH', $_SERVER);
        $previous = $_SERVER['LEGACY_MEDIA_PATH'] ?? null;
        unset($_SERVER['LEGACY_MEDIA_PATH']);

        try {
            $controller = $this->outputCapturingController();
            $result = (new ReflectionMethod(Diagnostics::class, 'checkLegacyMediaRoot'))->invoke(new Diagnostics());

            self::assertSame(['check', 'ok', 'detail'], array_keys($result));
            self::assertSame('legacy_media_root', $result['check']);
            self::assertTrue($result['ok'], 'A no-asset site needs no LEGACY_MEDIA_PATH — absence must not fail doctor.');

            // Absence is the normal case, so the wording has to point at the
            // mapping's per-environment mediaRoot chains rather than read as a
            // misconfiguration. Doctor reported a green "not configured" on an
            // install that could not connect at all.
            self::assertStringContainsString('not set', $result['detail']);
            self::assertStringContainsString('mediaRoot', $result['detail']);
        } finally {
            if ($hadPrevious) {
                $_SERVER['LEGACY_MEDIA_PATH'] = $previous;
            } else {
                unset($_SERVER['LEGACY_MEDIA_PATH']);
            }
        }
    }

    public function testCheckLegacyMediaRootFailsWhenEnvVarPointsAtAMissingDirectory(): void
    {
        $hadPrevious = array_key_exists('LEGACY_MEDIA_PATH', $_SERVER);
        $previous = $_SERVER['LEGACY_MEDIA_PATH'] ?? null;
        $_SERVER['LEGACY_MEDIA_PATH'] = '/no/such/directory/kunstmaan-migrator-doctor-test';

        try {
            $controller = $this->outputCapturingController();
            $result = (new ReflectionMethod(Diagnostics::class, 'checkLegacyMediaRoot'))->invoke(new Diagnostics());

            self::assertSame('legacy_media_root', $result['check']);
            self::assertFalse($result['ok'], 'A configured-but-missing media root is a misconfiguration, not an absence.');
            self::assertStringContainsString('not a readable directory', $result['detail']);
        } finally {
            if ($hadPrevious) {
                $_SERVER['LEGACY_MEDIA_PATH'] = $previous;
            } else {
                unset($_SERVER['LEGACY_MEDIA_PATH']);
            }
        }
    }

    public function testCheckLegacyMediaRootPassesWhenEnvVarPointsAtAReadableDirectory(): void
    {
        $dir = sys_get_temp_dir() . '/kunstmaan-migrator-doctor-media-root-' . uniqid();
        mkdir($dir);
        $hadPrevious = array_key_exists('LEGACY_MEDIA_PATH', $_SERVER);
        $previous = $_SERVER['LEGACY_MEDIA_PATH'] ?? null;
        $_SERVER['LEGACY_MEDIA_PATH'] = $dir;

        try {
            $controller = $this->outputCapturingController();
            $result = (new ReflectionMethod(Diagnostics::class, 'checkLegacyMediaRoot'))->invoke(new Diagnostics());

            self::assertSame('legacy_media_root', $result['check']);
            self::assertTrue($result['ok']);
            self::assertStringContainsString('readable directory', $result['detail']);
        } finally {
            if ($hadPrevious) {
                $_SERVER['LEGACY_MEDIA_PATH'] = $previous;
            } else {
                unset($_SERVER['LEGACY_MEDIA_PATH']);
            }
            @rmdir($dir);
        }
    }

    // checkLegacyDb() — like checkStateTable()/checkStorageWritable()/
    // checkRetourPresence(), it resolves Plugin::getInstance() (which reads
    // Yii::$app->loadedModules) and so needs a real booted Craft app; left
    // to the target rehearsal, same as those three, rather than unit-tested
    // here.
}
