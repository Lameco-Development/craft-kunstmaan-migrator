<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\runs;

use Craft;
use craft\db\Connection;
use craft\db\Query;
use craft\helpers\Db;
use DateTime;
use lameco\kunstmaanmigrator\records\MigrationRunRecord;
use RuntimeException;
use yii\base\Component;

/**
 * Repository/service for durable migration run records.
 *
 * Phase 12 keeps run mutation logic here so CP controllers and queue jobs can
 * share one lifecycle implementation instead of duplicating ActiveRecord writes.
 */
class MigrationRunService extends Component
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_BLOCKED = 'blocked';
    public const ARTIFACT_ROOT = 'storage/migration';

    private function db(): Connection
    {
        return Craft::$app->db;
    }

    /**
     * @param array<string, mixed> $filters
     * @param array<string, mixed> $options
     * @param array<string, mixed>|null $gateSnapshot
     */
    public function createRun(
        string $stage,
        string $mode,
        array $filters,
        array $options,
        ?int $initiatedByUserId,
        ?array $gateSnapshot = null,
    ): MigrationRunRecord {
        $now = $this->now();
        $record = new MigrationRunRecord();
        $record->stage = $stage;
        $record->mode = $mode;
        $record->status = self::STATUS_DRAFT;
        $record->filters = $filters;
        $record->options = $options;
        $record->gateSnapshot = $gateSnapshot;
        $record->initiatedByUserId = $initiatedByUserId;
        $record->queueJobId = null;
        $record->queueJobIds = [];
        $record->progress = 0;
        $record->logPath = self::ARTIFACT_ROOT . '/runs/' . $this->safePathSegment($stage) . '-' . $this->safePathSegment($mode) . '-' . gmdate('YmdHis') . '.log';
        $record->artifactPaths = [];
        $record->summary = null;
        $record->failure = null;
        $record->dateStarted = null;
        $record->dateFinished = null;
        $record->dateCreated = $now;
        $record->dateUpdated = $now;

        if (!$record->save(false)) {
            throw new RuntimeException('Failed to create migration run record.');
        }

        return $record;
    }

    public function markQueued(int $id, string|int $queueJobId): void
    {
        $queueJobId = $this->normalizeQueueJobId($queueJobId);
        $run = $this->requireRun($id);
        $queueJobIds = $this->jsonList($run['queueJobIds'] ?? null);
        if (!in_array($queueJobId, $queueJobIds, true)) {
            $queueJobIds[] = $queueJobId;
        }

        $this->update($id, [
            'status' => self::STATUS_QUEUED,
            'queueJobId' => $queueJobId,
            'queueJobIds' => $queueJobIds,
            'dateUpdated' => $this->now(),
        ]);
    }

    public function appendQueueJobId(int $id, string|int $queueJobId): void
    {
        $queueJobId = $this->normalizeQueueJobId($queueJobId);
        $run = $this->requireRun($id);
        $queueJobIds = $this->jsonList($run['queueJobIds'] ?? null);
        if (!in_array($queueJobId, $queueJobIds, true)) {
            $queueJobIds[] = $queueJobId;
        }

        $this->update($id, [
            'queueJobIds' => $queueJobIds,
            'dateUpdated' => $this->now(),
        ]);
    }

    public function markRunning(int $id): void
    {
        $run = $this->requireRun($id);
        $this->update($id, [
            'status' => self::STATUS_RUNNING,
            'dateStarted' => $run['dateStarted'] ?: $this->now(),
            'dateUpdated' => $this->now(),
        ]);
    }

    /**
     * @param array<string, mixed>|null $summary
     */
    public function updateProgress(int $id, float $progress, ?array $summary = null): void
    {
        $values = [
            'progress' => $this->clampProgress($progress),
            'dateUpdated' => $this->now(),
        ];
        if ($summary !== null) {
            $values['summary'] = $summary;
        }

        $this->requireRun($id);
        $this->update($id, $values);
    }

    /**
     * @param array<string, mixed> $summary
     * @param array<int, string> $artifactPaths
     */
    public function markSucceeded(int $id, array $summary = [], array $artifactPaths = []): void
    {
        $this->requireRun($id);
        $this->update($id, [
            'status' => self::STATUS_SUCCEEDED,
            'progress' => 100,
            'summary' => $summary,
            'artifactPaths' => array_values($artifactPaths),
            'failure' => null,
            'dateFinished' => $this->now(),
            'dateUpdated' => $this->now(),
        ]);
    }

    /**
     * @param array<string, mixed> $failure
     */
    public function markFailed(int $id, string $message, array $failure = []): void
    {
        $this->requireRun($id);
        $failure = ['message' => $message] + $failure;
        $this->update($id, [
            'status' => self::STATUS_FAILED,
            'failure' => $failure,
            'dateFinished' => $this->now(),
            'dateUpdated' => $this->now(),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function latest(?string $stage = null, ?string $mode = null): ?array
    {
        $query = $this->baseQuery();
        if ($stage !== null) {
            $query->andWhere(['stage' => $stage]);
        }
        if ($mode !== null) {
            $query->andWhere(['mode' => $mode]);
        }

        $row = $query
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])
            ->limit(1)
            ->one($this->db());

        return $this->rowToArray($row ?: null);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $row = $this->baseQuery()
            ->where(['id' => $id])
            ->one($this->db());

        return $this->rowToArray($row ?: null);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(int $limit = 50): array
    {
        $limit = max(1, min(500, $limit));
        $rows = $this->baseQuery()
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])
            ->limit($limit)
            ->all($this->db());

        return array_values(array_filter(array_map(
            fn (array $row): ?array => $this->rowToArray($row),
            $rows,
        )));
    }

    public function appendArtifact(int $id, string $path): void
    {
        $path = $this->normalizeArtifactPath($path);
        $run = $this->requireRun($id);
        $artifactPaths = $this->jsonList($run['artifactPaths'] ?? null);
        if (!in_array($path, $artifactPaths, true)) {
            $artifactPaths[] = $path;
        }

        $this->update($id, [
            'artifactPaths' => $artifactPaths,
            'dateUpdated' => $this->now(),
        ]);
    }

    private function baseQuery(): Query
    {
        return (new Query())->from(MigrationRunRecord::tableName());
    }

    /**
     * @return array<string, mixed>
     */
    private function requireRun(int $id): array
    {
        $row = $this->find($id);
        if ($row === null) {
            throw new RuntimeException("Migration run {$id} was not found.");
        }
        return $row;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function update(int $id, array $values): void
    {
        $this->db()->createCommand()
            ->update(MigrationRunRecord::tableName(), $values, ['id' => $id])
            ->execute();
    }

    private function normalizeQueueJobId(string|int $queueJobId): string
    {
        return (string) $queueJobId;
    }

    private function normalizeArtifactPath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            throw new RuntimeException('Artifact path cannot be empty.');
        }
        if ($path !== self::ARTIFACT_ROOT && !str_starts_with($path, self::ARTIFACT_ROOT . '/')) {
            throw new RuntimeException('Artifact path must be under storage/migration.');
        }
        return $path;
    }

    private function safePathSegment(string $value): string
    {
        $segment = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $value) ?: 'run';
        return trim($segment, '-') ?: 'run';
    }

    private function clampProgress(float $progress): float
    {
        return max(0.0, min(100.0, $progress));
    }

    private function now(): string
    {
        return Db::prepareDateForDb(new DateTime());
    }

    /**
     * @return array<int, string>
     */
    private function jsonList(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (is_array($value)) {
            return array_values(array_map('strval', $value));
        }
        if (!is_string($value)) {
            return [];
        }
        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            return [];
        }
        return array_values(array_map('strval', $decoded));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function rowToArray(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        foreach (['filters', 'options', 'gateSnapshot', 'queueJobIds', 'artifactPaths', 'summary', 'failure'] as $jsonField) {
            $row[$jsonField] = $this->decodeJsonField($row[$jsonField] ?? null);
        }
        if (isset($row['progress'])) {
            $row['progress'] = (float) $row['progress'];
        }

        return $row;
    }

    private function decodeJsonField(mixed $value): mixed
    {
        if ($value === null || is_array($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return null;
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : null;
    }
}
