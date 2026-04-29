<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\records;

use craft\db\ActiveRecord;

/**
 * ActiveRecord for durable migration run rows.
 *
 * @property int $id
 * @property string $stage
 * @property string $mode
 * @property string $status
 * @property array<string, mixed>|string $filters
 * @property array<string, mixed>|string $options
 * @property array<string, mixed>|string|null $gateSnapshot
 * @property int|null $initiatedByUserId
 * @property string|null $queueJobId
 * @property array<int, string>|string|null $queueJobIds
 * @property float|int|string $progress
 * @property string|null $logPath
 * @property array<int, string>|string|null $artifactPaths
 * @property array<string, mixed>|string|null $summary
 * @property array<string, mixed>|string|null $failure
 * @property string|null $dateStarted
 * @property string|null $dateFinished
 * @property string $dateCreated
 * @property string $dateUpdated
 */
class MigrationRunRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%kunstmaanmigrator_runs}}';
    }
}
