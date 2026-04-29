<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\queue\jobs;

use craft\queue\BaseJob;
use InvalidArgumentException;
use lameco\kunstmaanmigrator\Plugin;
use RuntimeException;
use Throwable;

/**
 * Staged dry-run/live migration pipeline queue job.
 *
 * The job processes one workflow batch and chains a follow-up job when the
 * workflow reports a nextBatchOffset. Payload properties stay scalar/array
 * only so Craft can safely serialize jobs between web and worker processes.
 */
class MigrationPipelineJob extends BaseJob
{
    public int $runId;
    public string $mode;
    /** @var array<string, mixed> */
    public array $filters = [];
    /** @var array<string, mixed> */
    public array $options = [];
    /** @var array<string, mixed> */
    public array $gateSnapshot = [];
    public int $batchOffset = 0;
    public int $batchLimit = 50;

    public function execute($queue): void
    {
        $plugin = Plugin::getInstance();
        $plugin->migrationSafety->assertNotProductionForJob();

        $this->assertSupportedMode();
        $this->assertLiveGatesAllowProcessing();

        $runService = $plugin->migrationRunService;
        $this->setProgress($queue, 0.05, $this->label('starting batch'));
        $runService->markRunning($this->runId);
        $runService->updateProgress($this->runId, $this->progressPercent(0, 0), [
            'mode' => $this->mode,
            'batchOffset' => $this->batchOffset,
            'batchLimit' => $this->batchLimit,
            'message' => 'Pipeline batch started.',
        ]);

        try {
            $result = $plugin->migrateWorkflow->run($this->workflowOptions(), function (array $event) use ($queue, $runService): void {
                $this->setProgress($queue, 0.5, $this->label((string) ($event['message'] ?? 'running batch')));
                $runService->updateProgress($this->runId, $this->progressPercent(0, 0), [
                    'mode' => $this->mode,
                    'batchOffset' => $this->batchOffset,
                    'batchLimit' => $this->batchLimit,
                    'event' => $event,
                ]);
            });

            $processed = (int) ($result['processed'] ?? 0);
            $total = (int) ($result['total'] ?? 0);
            $nextBatchOffset = $result['nextBatchOffset'] ?? null;
            $batchProgress = $this->progressPercent($processed, $total);

            $this->setProgress($queue, $batchProgress / 100, $this->label('batch complete'));
            $runService->updateProgress($this->runId, $batchProgress, [
                'mode' => $this->mode,
                'batchOffset' => $this->batchOffset,
                'batchLimit' => $this->batchLimit,
                'processed' => $processed,
                'total' => $total,
                'nextBatchOffset' => $nextBatchOffset,
                'summary' => (array) ($result['summary'] ?? []),
            ]);

            if (($result['status'] ?? 'failed') !== 'succeeded') {
                $failure = (array) ($result['failure'] ?? []);
                $message = (string) ($failure['message'] ?? "Migration pipeline {$this->mode} batch failed.");
                $runService->markFailed($this->runId, $message, [
                    'mode' => $this->mode,
                    'batchOffset' => $this->batchOffset,
                    'batchLimit' => $this->batchLimit,
                    'failure' => $failure,
                    'summary' => (array) ($result['summary'] ?? []),
                ]);
                $this->setProgress($queue, 1.0, $this->label('failed'));
                return;
            }

            if ($nextBatchOffset !== null) {
                $nextJobId = $queue->push(new self([
                    'runId' => $this->runId,
                    'mode' => $this->mode,
                    'filters' => $this->filters,
                    'options' => $this->options,
                    'gateSnapshot' => $this->gateSnapshot,
                    'batchOffset' => (int) $nextBatchOffset,
                    'batchLimit' => $this->batchLimit,
                ]));
                $runService->appendQueueJobId($this->runId, $nextJobId);
                $this->setProgress($queue, min(0.99, $batchProgress / 100), $this->label('queued next batch'));
                return;
            }

            $runService->markSucceeded(
                $this->runId,
                $this->successSummary($result),
                $this->artifactPaths($result),
            );
            $this->setProgress($queue, 1.0, $this->label('completed'));
        } catch (Throwable $e) {
            $runService->markFailed($this->runId, $e->getMessage(), [
                'mode' => $this->mode,
                'batchOffset' => $this->batchOffset,
                'batchLimit' => $this->batchLimit,
                'type' => $e::class,
            ]);
            $this->setProgress($queue, 1.0, $this->label('failed'));
            throw $e;
        }
    }

    protected function defaultDescription(): ?string
    {
        return sprintf(
            'Kunstmaan migration %s batch for run #%d (offset %d, limit %d)',
            $this->mode,
            $this->runId,
            $this->batchOffset,
            $this->batchLimit,
        );
    }

    private function assertSupportedMode(): void
    {
        if (!in_array($this->mode, ['dryRun', 'live'], true)) {
            throw new InvalidArgumentException("Unsupported migration pipeline mode: {$this->mode}");
        }
    }

    private function assertLiveGatesAllowProcessing(): void
    {
        if ($this->mode !== 'live') {
            return;
        }

        $blockingStatuses = $this->gateStatuses($this->gateSnapshot);
        if (array_intersect($blockingStatuses, ['blocked', 'unknown']) !== []) {
            throw new RuntimeException('Live migration gateSnapshot contains blocked or unknown gates.');
        }
    }

    /**
     * @param array<mixed> $gates
     * @return list<string>
     */
    private function gateStatuses(array $gates): array
    {
        $statuses = [];
        foreach ($gates as $gate) {
            if (!is_array($gate)) {
                continue;
            }
            if (isset($gate['status'])) {
                $statuses[] = (string) $gate['status'];
            }
            $statuses = array_merge($statuses, $this->gateStatuses($gate));
        }

        return $statuses;
    }

    /**
     * @return array<string, mixed>
     */
    private function workflowOptions(): array
    {
        return array_merge($this->filters, $this->options, [
            'live' => $this->mode === 'live',
            'batchOffset' => $this->batchOffset,
            'batchLimit' => $this->batchLimit,
        ]);
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function successSummary(array $result): array
    {
        return [
            'mode' => $this->mode,
            'batchOffset' => $this->batchOffset,
            'batchLimit' => $this->batchLimit,
            'summary' => (array) ($result['summary'] ?? []),
            'filters' => (array) ($result['filters'] ?? $this->filters),
            'processed' => (int) ($result['processed'] ?? 0),
            'total' => (int) ($result['total'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $result
     * @return list<string>
     */
    private function artifactPaths(array $result): array
    {
        return array_values(array_map('strval', (array) ($result['artifactPaths'] ?? [])));
    }

    private function progressPercent(int $processed, int $total): float
    {
        if ($total <= 0) {
            return 5.0;
        }

        return max(5.0, min(99.0, (($this->batchOffset + $processed) / $total) * 100));
    }

    private function label(string $suffix): string
    {
        $suffix = trim(preg_replace('/\s+/', ' ', $suffix) ?? $suffix);
        return sprintf('%s batch %d-%d: %s', $this->mode, $this->batchOffset, $this->batchLimit, $suffix);
    }
}
