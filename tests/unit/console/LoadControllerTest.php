<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\console;

use lameco\kunstmaanmigrator\console\LoadController;
use lameco\kunstmaanmigrator\payload\PayloadValidator;
use lameco\kunstmaanmigrator\payload\SchemaGateway;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
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

    public function siteByHandle(string $handle): ?array
    {
        return ['id' => 1, 'handle' => $handle];
    }

    public function fieldHandlesFor(string $entryTypeHandle): array
    {
        return ['pageBuilder', 'relatedPages', 'body'];
    }

    public function blockTypesFor(string $entryTypeHandle, string $fieldHandle): array
    {
        return [];
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

    private function validatorAllowingAll(): PayloadValidator
    {
        return new PayloadValidator(new AllowAllSchemaGateway());
    }

    private function writeTemp(string $suffix, string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'kuma-loader-') . $suffix;
        file_put_contents($path, $contents);

        return $path;
    }

    protected function tearDown(): void
    {
        foreach (glob(sys_get_temp_dir() . '/kuma-loader-*') ?: [] as $file) {
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

    // --- actionEntry() wiring, exercised directly (bypasses Yii dispatch) ---

    public function testActionEntryReturnsUsageExitCodeWhenPayloadOptionMissing(): void
    {
        $controller = $this->uninitializedController();
        $controller->dryRun = true;

        self::assertSame(ExitCode::USAGE, $controller->actionEntry());
    }

    public function testActionEntryThrowsNotSupportedExceptionWhenNotDryRun(): void
    {
        $controller = $this->uninitializedController();
        $controller->payload = $this->writeTemp('.json', json_encode($this->validPayloadArray('kuma:COM:nt_page:1')));
        $controller->dryRun = false;

        $this->expectException(\yii\base\NotSupportedException::class);
        $controller->actionEntry();
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
