<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\runs;

use lameco\kunstmaanmigrator\records\MigrationRunRecord;
use PHPUnit\Framework\TestCase;

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
}
