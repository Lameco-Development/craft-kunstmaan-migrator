<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\migrations;

use Craft;
use craft\db\Migration;
use craft\fields\PlainText;
use craft\helpers\StringHelper;

/**
 * Install — D-06 (state table schema verbatim from v1.x), D-07 (idempotent),
 * D-09 (field UID reuse for v1.x→v2 swap-in continuity), D-10 (safeDown is no-op).
 */
class Install extends Migration
{
    public const FIELD_HANDLE = 'kunstmaanSourceId';
    public const STATE_TABLE = '{{%kunstmaanmigrator_state}}';
    public const PROJECT_CONFIG_UID_PATH = 'plugins.kunstmaan-migrator.kunstmaanSourceIdFieldUid';

    public function safeUp(): bool
    {
        $this->ensureStateTable();
        $this->ensureFieldAndAttach();
        return true;
    }

    // D-10 / FND-03: uninstall PRESERVES state table and field — operator wipes manually for full reset.
    public function safeDown(): bool
    {
        return true;
    }

    private function ensureStateTable(): void
    {
        if ($this->db->tableExists(self::STATE_TABLE)) {
            // D-07 idempotency: prior install (or v1.x) already created the table.
            // Leave it alone; row-level data must survive re-install.
            return;
        }

        // D-06: schema byte-for-byte from v1.x src/craft/migrations/Install.php.
        // The 570-row CQM rehearsal site already has rows in this exact shape.
        $this->createTable(self::STATE_TABLE, [
            'id' => $this->primaryKey(),
            'source' => $this->string(64)->notNull(),
            'sourceKey' => $this->string(255)->notNull(),
            'targetType' => $this->string(64)->notNull(),
            'targetId' => $this->integer(),
            'targetUid' => $this->uid(),
            'siteId' => $this->integer()->null(),
            'meta' => $this->json()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
        ]);

        $this->createIndex(null, self::STATE_TABLE, ['source', 'sourceKey', 'siteId'], true);
        $this->createIndex(null, self::STATE_TABLE, ['dateUpdated'], false);
    }

    private function ensureFieldAndAttach(): void
    {
        $projectConfig = Craft::$app->projectConfig;

        // D-09 step 1: forced YAML re-read (true second arg) survives concurrent
        // project-config races — Craft idempotent-install guidance.
        $existingUid = $projectConfig->get(self::PROJECT_CONFIG_UID_PATH, true);

        if ($existingUid !== null) {
            // Plugin already installed against this site — UID persisted under our config path. No-op.
            return;
        }

        // D-09 step 2: before minting a new UID, check whether the site already has a field
        // with our handle (v1.x → v2 swap-in case). REUSE its UID — never replace.
        // Literal handle kept inline so grep-based UID-continuity assertions work.
        $existingField = Craft::$app->fields->getFieldByHandle('kunstmaanSourceId');

        if ($existingField !== null) {
            $projectConfig->set(self::PROJECT_CONFIG_UID_PATH, $existingField->uid);
            Craft::info(
                "kunstmaan-migrator Install: reusing existing field UID {$existingField->uid} for handle '" . self::FIELD_HANDLE . "'",
                'kunstmaan-migrator',
            );
            return;
        }

        // D-09 step 3: greenfield Craft host — mint a new field + UID.
        // Plain Text type per PROJECT.md Key Decisions ("kunstmaanSourceId field stays Plain Text").
        $field = new PlainText([
            'name' => 'Kunstmaan Source ID',
            'handle' => self::FIELD_HANDLE,
            'instructions' => "Legacy Kunstmaan source identifier (format '<source>:<id>'). Used by the Kunstmaan→Craft migrator for upsert lookup. Do not edit.",
            'searchable' => true,
            'uid' => StringHelper::UUID(),
        ]);
        $field->charLimit = 255;

        if (!Craft::$app->fields->saveField($field)) {
            throw new \RuntimeException(
                'Failed to save kunstmaanSourceId field: ' . json_encode($field->getErrors()),
            );
        }

        $projectConfig->set(self::PROJECT_CONFIG_UID_PATH, $field->uid);
        Craft::info(
            "kunstmaan-migrator Install: minted new field UID {$field->uid} for handle '" . self::FIELD_HANDLE . "'",
            'kunstmaan-migrator',
        );
    }
}
