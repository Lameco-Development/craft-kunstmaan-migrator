<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\queue;

use craft\helpers\App;
use Craft;
use craft\queue\BaseJob;
use Lameco\KumaCompile\Mapping\Mapping;
use lameco\kunstmaanmigrator\ProductionGuard;
use lameco\kunstmaanmigrator\run\EnvironmentPipeline;
use lameco\kunstmaanmigrator\run\RunLog;
use lameco\kunstmaanmigrator\run\RunSettings;
use lameco\kunstmaanmigrator\run\RunTally;
use RuntimeException;

/**
 * One environment, run off the queue.
 *
 * A job carries scalars, not objects: the mapping arrives as a path and is
 * re-read here rather than serialised, which also means a job picked up
 * minutes later reads the mapping as it is now rather than as it was when
 * someone pressed the button.
 *
 * Queueing is what makes a long migration survivable. Every stage is already
 * idempotent — the state table refuses to write a row twice — so a job that
 * dies halfway can simply be retried, which is the part of queue-driven
 * migration that is usually hard and here was already done.
 */
final class MigrateEnvironmentJob extends BaseJob
{
    public string $mappingPath = '';
    public string $environment = '';
    public bool $dryRun = false;
    public bool $force = false;
    public ?int $limit = null;
    public bool $entriesOnly = false;
    /** @var list<string>|null */
    public ?array $only = null;

    /**
     * The counts this job produced, for whatever reads the queue afterwards.
     *
     * @var array<string, int>
     */
    public array $counts = [];

    public function execute($queue): void
    {
        // Refusing here as well as in the console command is deliberate: a job
        // is the one path that can reach a production queue without anyone
        // typing a command on that machine.
        if (ProductionGuard::isProduction()) {
            throw new RuntimeException('Refusing to migrate against CRAFT_ENVIRONMENT=production');
        }

        if (!is_file($this->mappingPath)) {
            throw new RuntimeException(sprintf('Mapping file is gone: %s', $this->mappingPath));
        }

        $mapping = Mapping::fromFile($this->mappingPath);
        $spec = $mapping->environments()[$this->environment] ?? null;

        if (!is_array($spec)) {
            throw new RuntimeException(sprintf('Mapping names no environment "%s"', $this->environment));
        }

        $settings = new RunSettings(
            dryRun: $this->dryRun,
            force: $this->force,
            limit: $this->limit,
            entriesOnly: $this->entriesOnly,
            only: $this->only,
        );

        $tally = new RunTally();

        // The label moves, the bar does not: reporting a problem as progress 0.0
        // sent the bar back to the start on every warning.
        $progress = 0.0;
        $tally->onProblem = function (string $problem) use ($queue, &$progress): void {
            $this->setProgress($queue, $progress, $problem);
        };

        RunLog::default()->track('migrate', [
            'environment' => $this->environment,
            'dryRun' => $this->dryRun,
            'force' => $this->force,
            'limit' => $this->limit,
            'only' => $this->only,
        ], function (array &$extra) use ($mapping, $spec, $settings, $tally): void {
            try {
                EnvironmentPipeline::build($mapping, $settings)
                    ->run($mapping, $this->environment, $spec, $settings, $tally);
            } finally {
                $extra['counts'] = $tally->counts;
                $extra['problems'] = count($tally->problems);
            }

            $this->counts = $tally->counts;

            if ($tally->hasFailures()) {
                throw new RuntimeException(sprintf(
                    '%s: %d payloads failed — %s',
                    $this->environment,
                    $tally->counts['failed'],
                    $tally->problems[0] ?? 'no detail recorded',
                ));
            }
        });
    }

    protected function defaultDescription(): string
    {
        // What the queue shows an operator, so it says what is happening
        // rather than naming a class. Not translated: a job description is read
        // back out of the queue table by tests that cannot boot Craft, and a
        // guard for that would be worse than English here.
        return sprintf('Migrating %s — pages, content and files', $this->environment);
    }
}
