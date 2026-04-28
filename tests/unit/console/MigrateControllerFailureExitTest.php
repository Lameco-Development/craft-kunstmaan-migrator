<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\console;

use lameco\kunstmaanmigrator\console\MigrateController;
use lameco\kunstmaanmigrator\load\MigrationReport;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use yii\console\ExitCode;

/**
 * Phase 9 / Plan 09-05 — truthful final migrate outcome after diagnostic
 * continuation. The action methods need Craft bootstrap, so this locks the
 * pure report-state and controller-helper seams.
 */
final class MigrateControllerFailureExitTest extends TestCase
{
    public function testMigrationReportHasFailuresDetectsRowsAndFailedCount(): void
    {
        $clean = new MigrationReport();
        self::assertFalse($clean->hasFailures());
        self::assertSame(0, $clean->failureCount());

        $countOnly = new MigrationReport();
        $countOnly->incr('failed', 2);
        self::assertTrue($countOnly->hasFailures());
        self::assertSame(2, $countOnly->failureCount());

        $rowOnly = new MigrationReport();
        $rowOnly->failures[] = [
            'legacyId' => 42,
            'slug' => 'App_Entity_Page/42',
            'handler' => 'AtomicMigrationService',
            'message' => 'boom',
            'trace' => null,
        ];
        self::assertTrue($rowOnly->hasFailures());
        self::assertSame(1, $rowOnly->failureCount());
    }

    public function testReportExitCodeReturnsUnspecifiedErrorWhenFailuresWereRecorded(): void
    {
        $controller = (new ReflectionClass(MigrateController::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(MigrateController::class, 'reportExitCode');

        $report = new MigrationReport();
        $report->recordFailure(99, 'App_Entity_Page/99', 'AtomicMigrationService', new RuntimeException('entry failed'));

        self::assertSame(ExitCode::UNSPECIFIED_ERROR, $method->invoke($controller, $report));
    }

    public function testReportExitCodeReturnsOkForCleanReport(): void
    {
        $controller = (new ReflectionClass(MigrateController::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(MigrateController::class, 'reportExitCode');

        self::assertSame(ExitCode::OK, $method->invoke($controller, new MigrationReport()));
    }

    public function testActionIndexAndActionLoadUseReportFailureGateAfterReportWrite(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(MigrateController::class))->getFileName(),
        );

        self::assertGreaterThanOrEqual(
            3,
            substr_count($source, 'reportExitCode('),
            'Helper plus actionIndex() and actionLoad() call sites must be present.',
        );
        self::assertStringContainsString('Migrate: FAIL', $source);
        self::assertStringContainsString('writeReport($storageDir, $report', $source);
    }
}
