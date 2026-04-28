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

    public function testPreflightCompiledMappingBlocksLoadFatalTargetValidation(): void
    {
        $controller = (new ReflectionClass(MigrateController::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(MigrateController::class, 'preflightCompiledMapping');

        $mapping = [
            'nodeClasses' => [
                'App\\Entity\\StructuralFormPage' => [
                    'sourceTable' => 'vacancy_form_pages',
                    'section' => 'formContentBlock',
                    'fields' => [],
                ],
            ],
            'sections' => [
                'formContentBlock' => [
                    'section' => 'contentPages',
                    'entryType' => 'formContentBlock',
                ],
            ],
            'sites' => ['nl' => 'default'],
        ];
        $schema = [
            'sections' => [
                'contentPages' => ['entryTypes' => ['contentPage']],
            ],
            'entryTypes' => [
                'contentPage' => ['fields' => []],
                'formContentBlock' => ['fields' => []],
            ],
        ];

        $result = $method->invoke($controller, $mapping, $schema);

        self::assertSame([], $result['missing']);
        self::assertNotSame([], $result['fatal']);
        self::assertStringContainsString('contentPages', implode("\n", $result['messages']));
        self::assertStringContainsString('formContentBlock', implode("\n", $result['messages']));
        self::assertStringContainsString('Load-fatal target validation', implode("\n", $result['messages']));
    }

    public function testFallbackReportSectionRendersOperatorVisibleRowsWithoutFailures(): void
    {
        $report = new MigrationReport();
        $report->incr('fallback.matrix_native_title');
        $report->incr('fallback.sparse_locale_primary');
        $report->warn(
            'Matrix native-title fallback: source=App\\Entity\\GenericPage:42 site=default field=contentBuilder blockType=genericTextBlock position=1 title="Migrated genericTextBlock block 1"',
        );
        $report->warn(
            'Sparse-locale primary-save fallback: source=App\\Entity\\GenericPage:43 primarySite=default fallbackSite=en borrowed=payload',
        );

        $rendered = implode("\n", MigrateController::renderFallbacksSection($report));

        self::assertStringContainsString('## Fallbacks', $rendered);
        self::assertStringContainsString('| matrix_native_title | 1 |', $rendered);
        self::assertStringContainsString('| sparse_locale_primary | 1 |', $rendered);
        self::assertStringContainsString('source=App\\Entity\\GenericPage:42', $rendered);
        self::assertStringContainsString('site=default', $rendered);
        self::assertStringContainsString('fallbackSite=en', $rendered);
        self::assertFalse($report->hasFailures());
        self::assertSame(0, $report->failureCount());
    }

    public function testTransformSentinelWarningsMergeIntoMigrationReportWithPrefixAndCount(): void
    {
        $controller = (new ReflectionClass(MigrateController::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(MigrateController::class, 'mergeTransformReportSentinel');
        $report = new MigrationReport();

        $blocking = $method->invoke($controller, [
            '__report' => [
                'warnings' => ["Handler 'relation' failed on topicRelation: resolver failed"],
            ],
        ], $report);

        self::assertTrue($blocking);
        self::assertSame(1, (int) ($report->counts['transform.warning'] ?? 0));
        self::assertSame(
            ["Transform: Handler 'relation' failed on topicRelation: resolver failed"],
            $report->warnings,
        );
    }

    public function testDryRunTransformRelationHandlerWarningStaysVisibleWithoutFailure(): void
    {
        $controller = (new ReflectionClass(MigrateController::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(MigrateController::class, 'mergeTransformReportSentinel');
        $report = new MigrationReport();

        $blocking = $method->invoke($controller, [
            '__report' => [
                'warnings' => ["Handler 'relation' failed on topicRelation: TaxonomyMigrationService exploded"],
            ],
        ], $report);

        self::assertTrue($blocking);
        self::assertStringContainsString('Transform:', implode("\n", $report->warnings));
        self::assertFalse($report->hasFailures(), 'Dry-run/actionIndex decides not to record live-only failure.');
    }

    public function testLiveTransformRelationHandlerFailureRecordsSyntheticTransformServiceFailure(): void
    {
        $controller = (new ReflectionClass(MigrateController::class))->newInstanceWithoutConstructor();
        $recordMethod = new ReflectionMethod(MigrateController::class, 'recordBlockingTransformFailure');
        $exitMethod = new ReflectionMethod(MigrateController::class, 'reportExitCode');
        $report = new MigrationReport();

        $recordMethod->invoke($controller, $report);

        self::assertTrue($report->hasFailures());
        self::assertSame(ExitCode::UNSPECIFIED_ERROR, $exitMethod->invoke($controller, $report));
        self::assertSame('transform', $report->failures[0]['legacyId']);
        self::assertSame('TransformService', $report->failures[0]['handler']);
        self::assertStringContainsString('Transform:', $report->failures[0]['message']);
    }
}
