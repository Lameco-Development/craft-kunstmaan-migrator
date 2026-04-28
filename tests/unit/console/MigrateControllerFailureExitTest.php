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

    public function testFinalizeUnresolvedGateRecordsBlockingFailureAndDiagnostics(): void
    {
        $controller = (new ReflectionClass(MigrateController::class))->newInstanceWithoutConstructor();
        $recordMethod = new ReflectionMethod(MigrateController::class, 'recordFinalizeUnresolvedGate');
        $exitMethod = new ReflectionMethod(MigrateController::class, 'reportExitCode');
        $report = new MigrationReport();

        $recordMethod->invoke($controller, $report, [
            'unresolvable' => 2,
            'unresolvedDiagnostics' => [
                [
                    'tokenFamily' => 'nt',
                    'legacyId' => 80,
                    'token' => '[NT80]',
                    'siteId' => 1,
                    'entryId' => 123,
                    'fieldHandle' => 'body',
                    'reason' => 'no matching Craft entry id',
                ],
            ],
        ]);

        self::assertTrue($report->hasFailures());
        self::assertSame(ExitCode::UNSPECIFIED_ERROR, $exitMethod->invoke($controller, $report));
        self::assertStringContainsString('finalize.unresolvable=2', $report->warnings[0]);
        self::assertSame('FinalizeWalker', $report->failures[0]['handler']);
        self::assertSame('nt', $report->finalizeUnresolvedDiagnostics[0]['tokenFamily']);
    }

    public function testFinalizeUnresolvedDiagnosticsReportSectionIsBoundedAndStructural(): void
    {
        $rows = [];
        for ($i = 1; $i <= 101; $i++) {
            $rows[] = [
                'tokenFamily' => 'media',
                'legacyId' => $i,
                'token' => '[M' . $i . ']',
                'siteId' => 1,
                'entryId' => 100 + $i,
                'fieldHandle' => 'body',
                'reason' => 'no matching Craft asset id',
                'body' => '<p>PRIVATE</p>',
                'sample' => 'PRIVATE',
            ];
        }

        $rendered = implode("\n", MigrateController::renderFinalizeUnresolvedDiagnosticsSection($rows));

        self::assertStringContainsString('## Finalize unresolved diagnostics', $rendered);
        self::assertStringContainsString('| media | 1 | `[M1]` | 1 | 101 | `body` | no matching Craft asset id |', $rendered);
        self::assertStringContainsString('Showing 100 of 101 diagnostics', $rendered);
        self::assertStringNotContainsString('PRIVATE', $rendered);

        $mediaUrlRendered = implode("\n", MigrateController::renderFinalizeUnresolvedDiagnosticsSection([
            [
                'tokenFamily' => 'media_url',
                'legacyId' => 0,
                'token' => '/uploads/media/missing.jpg',
                'siteId' => 2,
                'entryId' => 321,
                'fieldHandle' => 'body',
                'reason' => 'no matching Craft asset id for legacy media URL',
            ],
        ]));
        self::assertStringContainsString(
            '| media_url | 0 | `/uploads/media/missing.jpg` | 2 | 321 | `body` | no matching Craft asset id for legacy media URL |',
            $mediaUrlRendered,
        );
    }

    public function testZeroFinalizeUnresolvedDoesNotRecordBlockingFailure(): void
    {
        $controller = (new ReflectionClass(MigrateController::class))->newInstanceWithoutConstructor();
        $recordMethod = new ReflectionMethod(MigrateController::class, 'recordFinalizeUnresolvedGate');
        $report = new MigrationReport();

        $recordMethod->invoke($controller, $report, ['unresolvable' => 0, 'unresolvedDiagnostics' => []]);

        self::assertFalse($report->hasFailures());
        self::assertSame([], $report->warnings);
    }

    public function testTransformBlockMarkerPathAndLoadFailureMessageAreStable(): void
    {
        $controller = (new ReflectionClass(MigrateController::class))->newInstanceWithoutConstructor();
        $pathMethod = new ReflectionMethod(MigrateController::class, 'transformBlockMarkerPath');
        $messageMethod = new ReflectionMethod(MigrateController::class, 'transformBlockMarkerLoadFailureMessage');

        $path = $pathMethod->invoke($controller, '/var/craft/storage/migration');
        $message = $messageMethod->invoke($controller, ['path' => $path]);

        self::assertSame('/var/craft/storage/migration/transform-block.json', $path);
        self::assertStringContainsString('prior transform relation/taxonomy failure marker blocks live load', $message);
        self::assertStringContainsString($path, $message);
        self::assertStringContainsString('re-run migrate/transform', $message);
    }

    public function testTransformMarkerIsClearedPersistedAndCheckedBeforeLiveLoad(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(MigrateController::class))->getFileName(),
        );

        self::assertGreaterThanOrEqual(
            2,
            substr_count($source, 'clearTransformBlockMarker($storageDir)'),
            'Both full actionIndex() and standalone actionTransform() must clear stale markers before fresh transform output.',
        );
        self::assertGreaterThanOrEqual(
            2,
            substr_count($source, 'writeTransformBlockMarker($storageDir, $report)'),
            'Both transform flows must persist a marker when relation/taxonomy transform warnings are blocking.',
        );
        self::assertStringContainsString('readTransformBlockMarker($storageDir)', $source);
        self::assertStringContainsString('prior transform relation/taxonomy failure marker blocks live load', $source);
        self::assertStringContainsString('recordFailure(', $source);
        self::assertStringContainsString('DRY RUN — would load entries', $source);
    }
}
