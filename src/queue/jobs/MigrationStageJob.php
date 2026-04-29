<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\queue\jobs;

use craft\queue\BaseJob;
use InvalidArgumentException;
use lameco\kunstmaanmigrator\Plugin;
use Throwable;

/**
 * Queue-safe runner for the non-live migration console stages.
 *
 * Public properties are intentionally limited to scalar/array values so Craft
 * can serialize the job payload without carrying services, records, or other
 * runtime-only objects across queue boundaries.
 */
class MigrationStageJob extends BaseJob
{
    public int $runId;
    public string $stage;
    public string $mode;
    /** @var array<string, mixed> */
    public array $filters = [];
    /** @var array<string, mixed> */
    public array $options = [];

    public function execute($queue): void
    {
        $plugin = Plugin::getInstance();
        $plugin->migrationSafety->assertNotProductionForJob();

        $runService = $plugin->migrationRunService;
        $this->setProgress($queue, 0.05, $this->label('starting'));
        $runService->markRunning($this->runId);
        $runService->updateProgress($this->runId, 5, [
            'stage' => $this->stage,
            'mode' => $this->mode,
            'message' => 'Queue job started.',
        ]);

        try {
            $workflow = match ($this->stage) {
                'analyze' => $plugin->analyzeWorkflow,
                'compile' => $plugin->compileWorkflow,
                'verify' => $plugin->verifyWorkflow,
                default => throw new InvalidArgumentException("Unsupported migration stage: {$this->stage}"),
            };

            $result = $workflow->run($this->workflowOptions(), function (array $event) use ($queue, $runService): void {
                $this->setProgress($queue, 0.5, $this->label((string) ($event['message'] ?? 'running')));
                $runService->updateProgress($this->runId, 50, [
                    'stage' => $this->stage,
                    'mode' => $this->mode,
                    'event' => $event,
                ]);
            });

            $this->setProgress($queue, 0.95, $this->label('recording result'));
            $runService->updateProgress($this->runId, 95, [
                'stage' => $this->stage,
                'mode' => $this->mode,
                'summary' => (array) ($result['summary'] ?? []),
            ]);

            if (($result['status'] ?? 'failed') === 'succeeded') {
                $runService->markSucceeded(
                    $this->runId,
                    $this->successSummary($result),
                    $this->artifactPaths($result),
                );
                $this->setProgress($queue, 1.0, $this->label('completed'));
                return;
            }

            $failure = (array) ($result['failure'] ?? []);
            $message = (string) ($failure['message'] ?? "Migration stage {$this->stage} failed.");
            $runService->markFailed($this->runId, $message, [
                'stage' => $this->stage,
                'mode' => $this->mode,
                'failure' => $failure,
                'summary' => (array) ($result['summary'] ?? []),
            ]);
            $this->setProgress($queue, 1.0, $this->label('failed'));
        } catch (Throwable $e) {
            $runService->markFailed($this->runId, $e->getMessage(), [
                'stage' => $this->stage,
                'mode' => $this->mode,
                'type' => $e::class,
            ]);
            $this->setProgress($queue, 1.0, $this->label('failed'));
            throw $e;
        }
    }

    protected function defaultDescription(): ?string
    {
        return sprintf('Kunstmaan migration %s stage for run #%d', $this->stage, $this->runId);
    }

    /**
     * @return array<string, mixed>
     */
    private function workflowOptions(): array
    {
        $options = array_merge($this->filters, $this->options);
        $allowed = match ($this->stage) {
            'analyze' => ['entities', 'locales', 'since', 'noAi', 'autoAcceptHigh', 'auditStrict', 'sourceStrict', 'noLayout', 'noProviders'],
            'compile' => ['overwrite', 'dryRun'],
            'verify' => ['baseline', 'urlSpotCheck', 'baselineDir', 'countTolerance', 'urlDiffThreshold', 'entities', 'locales', 'since', 'captureBaseline', 'captureBaselineHtml', 'output', 'outputDir'],
            default => [],
        };

        return $this->filterWorkflowOptions($options, $allowed);
    }

    /**
     * @param array<string, mixed> $options
     * @param list<string> $allowed
     * @return array<string, mixed>
     */
    private function filterWorkflowOptions(array $options, array $allowed): array
    {
        $out = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $options) && $options[$key] !== null) {
                $out[$key] = $options[$key];
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function successSummary(array $result): array
    {
        return [
            'stage' => $this->stage,
            'mode' => $this->mode,
            'summary' => (array) ($result['summary'] ?? []),
            'filters' => (array) ($result['filters'] ?? $this->filters),
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

    private function label(string $suffix): string
    {
        $suffix = trim(preg_replace('/\s+/', ' ', $suffix) ?? $suffix);
        return sprintf('%s/%s: %s', $this->stage, $this->mode, $suffix);
    }
}
