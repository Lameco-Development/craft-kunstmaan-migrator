<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\runs;

use lameco\kunstmaanmigrator\records\MigrationRunRecord;
use lameco\kunstmaanmigrator\runs\MigrationRunService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Source-level contract tests for Phase 12 run-record storage.
 *
 * The run service eventually touches Craft's DB connection, so this unit test
 * starts by locking the table/field contract through source assertions that do
 * not require a bootstrapped Craft application.
 */
final class MigrationRunServiceTest extends TestCase
{
    private function migrationSource(): string
    {
        $path = dirname(__DIR__, 3) . '/src/migrations/m260429_000001_create_migration_runs.php';
        self::assertFileExists($path);
        $source = file_get_contents($path);
        self::assertIsString($source);
        return $source;
    }

    private function recordSource(): string
    {
        $path = dirname(__DIR__, 3) . '/src/records/MigrationRunRecord.php';
        self::assertFileExists($path);
        $source = file_get_contents($path);
        self::assertIsString($source);
        return $source;
    }

    private function serviceSource(): string
    {
        $path = dirname(__DIR__, 3) . '/src/runs/MigrationRunService.php';
        self::assertFileExists($path);
        $source = file_get_contents($path);
        self::assertIsString($source);
        return $source;
    }

    public function testMigrationCreatesKunstmaanMigratorRunsTableAndPreservesRowsOnDown(): void
    {
        $source = $this->migrationSource();

        self::assertStringContainsString('{{%kunstmaanmigrator_runs}}', $source);
        self::assertStringContainsString('tableExists', $source);
        self::assertStringContainsString('createTable', $source);
        self::assertStringContainsString('function safeDown', $source);
        self::assertStringNotContainsString('dropTable', $source);
    }

    public function testMigrationDeclaresRunLifecycleJsonAndQueueColumns(): void
    {
        $source = $this->migrationSource();

        foreach ([
            'stage',
            'mode',
            'status',
            'filters',
            'options',
            'gateSnapshot',
            'initiatedByUserId',
            'queueJobId',
            'queueJobIds',
            'progress',
            'logPath',
            'artifactPaths',
            'summary',
            'failure',
            'dateStarted',
            'dateFinished',
            'dateCreated',
            'dateUpdated',
        ] as $column) {
            self::assertStringContainsString($column, $source);
        }
    }

    public function testRecordPointsAtRunTable(): void
    {
        self::assertSame('{{%kunstmaanmigrator_runs}}', MigrationRunRecord::tableName());
        self::assertStringContainsString('{{%kunstmaanmigrator_runs}}', $this->recordSource());
    }

    public function testRunServiceExposesLifecycleMethods(): void
    {
        $reflection = new ReflectionClass(MigrationRunService::class);

        foreach ([
            'createRun',
            'markQueued',
            'appendQueueJobId',
            'markRunning',
            'updateProgress',
            'markSucceeded',
            'markFailed',
            'latest',
            'find',
            'list',
            'appendArtifact',
        ] as $method) {
            self::assertTrue($reflection->hasMethod($method), "Missing {$method}()");
        }
    }

    public function testRunServiceLocksLifecycleStatuses(): void
    {
        $source = $this->serviceSource();

        foreach ([
            'draft',
            'queued',
            'running',
            'succeeded',
            'failed',
            'cancelled',
            'blocked',
        ] as $status) {
            self::assertStringContainsString($status, $source);
        }
    }

    public function testRunServiceStoresCurrentAndPluralQueueJobIds(): void
    {
        $source = $this->serviceSource();

        self::assertStringContainsString('queueJobId', $source);
        self::assertStringContainsString('queueJobIds', $source);
        self::assertStringContainsString('appendQueueJobId', $source);
        self::assertMatchesRegularExpression('/queueJobId.*=.*\\$queueJobId/s', $source);
    }

    public function testRunContractsExposeJsonLogAndArtifactFields(): void
    {
        $migrationSource = $this->migrationSource();
        $recordSource = $this->recordSource();
        $serviceSource = $this->serviceSource();

        foreach ([
            'gateSnapshot',
            'queueJobIds',
            'artifactPaths',
            'logPath',
        ] as $field) {
            self::assertStringContainsString($field, $migrationSource);
            self::assertStringContainsString($field, $recordSource . $serviceSource);
        }

        self::assertStringContainsString('storage/migration', $serviceSource);
        self::assertStringContainsString('ARTIFACT_ROOT', $serviceSource);
        self::assertStringContainsString('normalizeArtifactPath', $serviceSource);
    }

    public function testRunServiceHandlesMissingRunTableForReadSideConsoleRendering(): void
    {
        $source = $this->serviceSource();

        self::assertStringContainsString('private function runsTableExists()', $source);
        self::assertStringContainsString('private function assertRunsTableExists()', $source);
        self::assertMatchesRegularExpression('/function latest[^{]+{\\s*if \\(!\\$this->runsTableExists\\(\\)\\)/s', $source);
        self::assertMatchesRegularExpression('/function find[^{]+{\\s*if \\(!\\$this->runsTableExists\\(\\)\\)/s', $source);
        self::assertMatchesRegularExpression('/function list[^{]+{\\s*if \\(!\\$this->runsTableExists\\(\\)\\)/s', $source);
        self::assertStringContainsString('Run Craft pending migrations', $source);
    }
}
