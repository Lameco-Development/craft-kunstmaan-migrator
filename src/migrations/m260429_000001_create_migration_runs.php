<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\migrations;

use craft\db\Migration;

/**
 * Adds durable run records for queued/CP migration execution.
 *
 * Phase 12 / D-12: CP and queue surfaces need first-class status, progress,
 * logs, artifacts, summaries, and failure details instead of relying on
 * Craft's generic queue UI alone.
 */
class m260429_000001_create_migration_runs extends Migration
{
    public const RUNS_TABLE = '{{%kunstmaanmigrator_runs}}';

    public function safeUp(): bool
    {
        if ($this->db->tableExists(self::RUNS_TABLE)) {
            return true;
        }

        $this->createTable(self::RUNS_TABLE, [
            'id' => $this->primaryKey(),
            'stage' => $this->string(64)->notNull(),
            'mode' => $this->string(32)->notNull(),
            'status' => $this->string(32)->notNull()->defaultValue('draft'),
            'filters' => $this->json()->notNull(),
            'options' => $this->json()->notNull(),
            'gateSnapshot' => $this->json()->null(),
            'initiatedByUserId' => $this->integer()->null(),
            'queueJobId' => $this->string(128)->null(),
            'queueJobIds' => $this->json()->null(),
            'progress' => $this->decimal(5, 2)->notNull()->defaultValue(0),
            'logPath' => $this->string(1024)->null(),
            'artifactPaths' => $this->json()->null(),
            'summary' => $this->json()->null(),
            'failure' => $this->json()->null(),
            'dateStarted' => $this->dateTime()->null(),
            'dateFinished' => $this->dateTime()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
        ]);

        $this->createIndex(null, self::RUNS_TABLE, ['status'], false);
        $this->createIndex(null, self::RUNS_TABLE, ['stage', 'mode'], false);
        $this->createIndex(null, self::RUNS_TABLE, ['queueJobId'], false);
        $this->createIndex(null, self::RUNS_TABLE, ['dateCreated'], false);
        $this->createIndex(null, self::RUNS_TABLE, ['dateUpdated'], false);

        return true;
    }

    /**
     * Preserve run history on rollback/uninstall; operators reset explicitly.
     */
    public function safeDown(): bool
    {
        return true;
    }
}
