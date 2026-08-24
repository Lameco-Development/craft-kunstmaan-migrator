<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\console;

use lameco\kunstmaanmigrator\console\LoadController;
use lameco\kunstmaanmigrator\payload\PayloadValidator;
use lameco\kunstmaanmigrator\payload\SchemaGateway;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use yii\console\ExitCode;

/**
 * Permissive fake gateway: everything resolves, nothing is flagged unknown.
 * Used where the test is about report shape / control flow, not validation
 * rule coverage (PayloadValidatorTest already owns that).
 */
final class AllowAllSchemaGateway implements SchemaGateway
{
    public function sectionByHandle(string $handle): ?array
    {
        return ['id' => 1, 'handle' => $handle];
    }

    public function entryTypeByHandle(string $handle): ?array
    {
        return ['id' => 1, 'handle' => $handle, 'hasTitleFormat' => false];
    }

    public function primarySite(): array
    {
        return ['id' => 11, 'handle' => 'en'];
    }

    public function siteByHandle(string $handle): ?array
    {
        return ['id' => 1, 'handle' => $handle];
    }

    public function fieldHandlesFor(string $entryTypeHandle): array
    {
        return ['pageBuilder', 'relatedPages', 'body'];
    }

    /** Derived from the same fixtures the other lookups use, so fakes stay consistent. */
    public function fieldSlotsFor(string $entryTypeHandle): array
    {
        $slots = [];

        foreach ($this->fieldHandlesFor($entryTypeHandle) as $handle) {
            $nested = $this->blockTypesFor($entryTypeHandle, $handle);
            $slots[$handle] = [
                'type' => $nested === [] ? 'PlainText' : 'Matrix',
                'required' => false,
                'nested' => $nested,
            ];
        }

        return $slots;
    }

    public function blockTypesFor(string $entryTypeHandle, string $fieldHandle): array
    {
        return [];
    }
}

/**
 * Captures stdout()/stderr() writes into properties instead of hitting the
 * real streams (both ultimately `fwrite(STDOUT|STDERR, ...)`, which PHPUnit's
 * output buffering can't intercept) — mirrors NeverProductionFixture's
 * technique in NeverProductionTraitTest, applied to a real LoadController
 * instance so control-flow paths that write to the console are assertable.
 */
final class OutputCapturingLoadController extends LoadController
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
 * Task 3 — arg-parsing + report-shape coverage for LoadController.
 *
 * `LoadController` extends `craft\console\Controller`, whose constructor
 * chain resolves Yii application components (`Instance::ensure($this->request, ...)`)
 * that don't exist in this bare test environment. We never construct the
 * controller via `new` — `ReflectionClass::newInstanceWithoutConstructor()`
 * gives us a real instance (default property values applied, `options()`
 * callable) without running `init()`. The actual dry-run logic
 * (`buildReport()`/`exitCodeFor()`/file reading) is implemented as static
 * methods for exactly this reason: they need no controller instance at all.
 */
final class LoadControllerTest extends TestCase
{
    private function uninitializedController(): LoadController
    {
        return (new ReflectionClass(LoadController::class))->newInstanceWithoutConstructor();
    }

    private function outputCapturingController(): OutputCapturingLoadController
    {
        return (new ReflectionClass(OutputCapturingLoadController::class))->newInstanceWithoutConstructor();
    }

    private function validatorAllowingAll(): PayloadValidator
    {
        return new PayloadValidator(new AllowAllSchemaGateway());
    }

    private function writeTemp(string $suffix, string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'kunstmaan-migrator-') . $suffix;
        file_put_contents($path, $contents);

        return $path;
    }

    protected function tearDown(): void
    {
        foreach (glob(sys_get_temp_dir() . '/kunstmaan-migrator-*') ?: [] as $file) {
            @unlink($file);
        }
    }

    // --- arg parsing -----------------------------------------------------

    public function testDefaultPayloadOptionIsNull(): void
    {
        self::assertNull($this->uninitializedController()->payload);
    }

    public function testDefaultDryRunOptionIsFalse(): void
    {
        self::assertFalse($this->uninitializedController()->dryRun);
    }

    public function testOptionsDeclaresPayloadAndDryRun(): void
    {
        $options = $this->uninitializedController()->options('entry');

        self::assertContains('payload', $options);
        self::assertContains('dryRun', $options);
    }

    public function testUsesNeverProductionTrait(): void
    {
        self::assertContains(
            \lameco\kunstmaanmigrator\NeverProductionTrait::class,
            class_uses(LoadController::class),
        );
    }

    // --- report shape ------------------------------------------------------

    public function testBuildReportShapeOnAllValidJsonSingleObject(): void
    {
        $path = $this->writeTemp('.json', json_encode($this->validPayloadArray('kuma:COM:nt_page:1')));

        $report = LoadController::buildReport($path, $this->validatorAllowingAll());

        self::assertSame(['processed', 'violations', 'saved', 'failed'], array_keys($report));
        self::assertSame(1, $report['processed']);
        self::assertSame([], $report['violations']);
        self::assertSame(0, $report['saved']);
        self::assertSame([], $report['failed']);
    }

    public function testExitCodeForCleanReportIsOk(): void
    {
        $report = ['processed' => 1, 'violations' => [], 'saved' => 0, 'failed' => []];

        self::assertSame(ExitCode::OK, LoadController::exitCodeFor($report));
    }

    public function testExitCodeForReportWithViolationsIsUnspecifiedError(): void
    {
        $report = ['processed' => 1, 'violations' => [['sourceUid' => 'x', 'code' => 'BAD_REF', 'message' => 'm']], 'saved' => 0, 'failed' => []];

        self::assertSame(ExitCode::UNSPECIFIED_ERROR, LoadController::exitCodeFor($report));
    }

    public function testExitCodeForReportWithFailedIsUnspecifiedError(): void
    {
        $report = ['processed' => 1, 'violations' => [], 'saved' => 0, 'failed' => ['kuma:COM:nt_page:1']];

        self::assertSame(ExitCode::UNSPECIFIED_ERROR, LoadController::exitCodeFor($report));
    }

    public function testExitCodeForFixupWithNoOrphansIsOk(): void
    {
        self::assertSame(ExitCode::OK, LoadController::exitCodeForFixup(['patched' => 1, 'orphans' => []]));
    }

    public function testExitCodeForFixupWithOrphansIsUnspecifiedError(): void
    {
        $report = ['patched' => 0, 'orphans' => [['sourceUid' => 'x', 'field' => 'f', 'ref' => 'r', 'path' => []]]];

        self::assertSame(ExitCode::UNSPECIFIED_ERROR, LoadController::exitCodeForFixup($report));
    }

    // --- actionEntry() wiring, exercised directly (bypasses Yii dispatch) ---

    public function testActionEntryReturnsUsageExitCodeWhenPayloadOptionMissing(): void
    {
        $controller = $this->uninitializedController();
        $controller->dryRun = true;

        self::assertSame(ExitCode::USAGE, $controller->actionEntry());
    }

    /**
     * Task 4 removed the `NotSupportedException('live load lands in Task 4')`
     * stub — `actionEntry()`'s live branch now wires a real
     * `PayloadEntrySaver` via `Plugin::getInstance()`, which needs a booted
     * Craft application this bare test environment doesn't provide (same
     * reason `beforeAction()`'s full dispatch isn't exercised here either —
     * see the class docblock). `LoadControllerTest` therefore stops at
     * `buildReport()`/`exitCodeFor()`/the pre-`Plugin::getInstance()` guard
     * clauses; `buildLiveReport()`'s actual save-loop control flow (batch
     * validation gate, fail-forward per payload) is exercised directly,
     * Craft-app-free, in `tests/integration/load/PayloadEntrySaverTest.php`.
     */

    /**
     * A typo'd/deleted --payload path is a plausible operator mistake, not a
     * per-record data problem — it must degrade the same way the missing
     * --payload flag does (USAGE + stderr), not crash with an uncaught
     * InvalidArgumentException out of readRecords().
     */
    public function testActionEntryReturnsUsageExitCodeWhenPayloadFileDoesNotExist(): void
    {
        $controller = $this->outputCapturingController();
        $controller->payload = sys_get_temp_dir() . '/kunstmaan-migrator-does-not-exist-' . uniqid() . '.json';
        $controller->dryRun = true;

        $exitCode = $controller->actionEntry();

        self::assertSame(ExitCode::USAGE, $exitCode);
        self::assertStringContainsString('Payload file not found', $controller->capturedStderr);
        self::assertSame('', $controller->capturedStdout);
    }

    // --- production guard (NeverProductionTrait integration) ---------------

    /**
     * Craft's own `ControllerTrait::runAction()` would coerce a bare
     * `beforeAction()` `false` into `ExitCode::OK` — `runAction()`'s override
     * (see class docblock) re-asserts the stashed exit code instead, which is
     * what makes the refusal observable. This repo never boots a live Craft
     * console dispatch in tests (see PluginBootstrapTest), so — per the
     * reviewer's note that this guard is a plain env-var read that never
     * touches `$action` — this exercises `beforeAction()` directly and reads
     * back the stashed code `runAction()` returns, rather than the full
     * dispatch chain (which needs a booted `Yii::$app`).
     */
    public function testBeforeActionRefusesAndStashesNonZeroExitCodeWhenEnvironmentIsProduction(): void
    {
        $hadPrevious = array_key_exists('CRAFT_ENVIRONMENT', $_SERVER);
        $previous = $_SERVER['CRAFT_ENVIRONMENT'] ?? null;
        $_SERVER['CRAFT_ENVIRONMENT'] = 'production';

        try {
            $controller = $this->outputCapturingController();
            $controller->payload = $this->writeTemp('.json', json_encode($this->validPayloadArray('kuma:COM:nt_page:1')));
            $controller->dryRun = true;

            self::assertFalse($controller->beforeAction('entry'), 'beforeAction() must refuse to run in production.');
            self::assertStringContainsString(
                'Refusing to run against CRAFT_ENVIRONMENT=production',
                $controller->capturedStderr,
            );

            $stashedExitCode = (new ReflectionProperty(LoadController::class, 'neverProductionExitCode'))
                ->getValue($controller);

            self::assertSame(ExitCode::UNSPECIFIED_ERROR, $stashedExitCode);
            self::assertNotSame(ExitCode::OK, $stashedExitCode);

            // beforeAction() returning false means Yii's own runAction() never
            // calls the action method — actionEntry() (and so buildReport()/any
            // save) never runs; nothing was written to stdout here either.
            self::assertSame('', $controller->capturedStdout);
        } finally {
            if ($hadPrevious) {
                $_SERVER['CRAFT_ENVIRONMENT'] = $previous;
            } else {
                unset($_SERVER['CRAFT_ENVIRONMENT']);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayloadArray(string $sourceUid): array
    {
        return [
            'sourceUid' => $sourceUid,
            'section' => 'pages',
            'entryType' => 'contentPage',
            'sites' => [
                'en' => [
                    'enabled' => true,
                    'title' => 'Valid entry',
                    'slug' => 'valid-entry',
                    'fieldValues' => [],
                ],
            ],
        ];
    }
}
