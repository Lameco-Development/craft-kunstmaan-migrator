<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\queue;

use craft\helpers\Queue as QueueHelper;
use craft\queue\BaseBatchedJob;
use Lameco\Kunstmaanmigrator\Compile\CompilerRun;
use Lameco\Kunstmaanmigrator\Mapping\Mapping;
use Lameco\Kunstmaanmigrator\run\EnvironmentContext;
use Lameco\Kunstmaanmigrator\run\EnvironmentPipeline;
use Lameco\Kunstmaanmigrator\run\RunLog;
use Lameco\Kunstmaanmigrator\run\RunSettings;
use Lameco\Kunstmaanmigrator\run\RunTally;
use Lameco\Kunstmaanmigrator\safety\ProductionGuard;
use RuntimeException;
use yii\queue\RetryableJobInterface;

/**
 * One environment, run off the queue in batches (#48).
 *
 * The monolithic version was a single hour-scale unit inside a queue built
 * for second-scale ones, and the first CP-driven run paid for it four times
 * over: TTR kills, progress-label overflows, FIFO-hopeful ordering, and web
 * runner write races. A batch is now ~fifty units — an entity-lane window, a
 * page with its due structural placeholders — each execution compiles and
 * saves its window and ends, and Craft spawns the continuation. Progress is
 * batch arithmetic instead of a label doubling as an error log.
 *
 * A job carries scalars, not objects: the mapping arrives as a path and is
 * re-read per batch — guarded by `mappingHash`, because a mapping edited
 * mid-run would compile the tail of the corpus against different rules than
 * its head. Every stage stays idempotent via the state table, so a killed
 * batch retries for the cost of one batch.
 *
 * Chaining replaces FIFO hope: `after()` pushes the adapter job for this
 * environment, which pushes the next environment, and the corpus-wide fixup
 * and finalize passes run only after the last environment's adapters.
 */
final class MigrateEnvironmentJob extends BaseBatchedJob implements RetryableJobInterface
{
    public string $mappingPath = '';
    public string $environment = '';
    public bool $dryRun = false;
    public bool $force = false;
    public ?int $limit = null;
    public bool $entriesOnly = false;
    /** @var list<string>|null */
    public ?array $only = null;

    /** @var list<string> environments still to run after this one */
    public array $remainingEnvironments = [];

    /** Whether the chain ends in the corpus-wide fixup + finalize passes. */
    public bool $chainCorpusPasses = true;

    /**
     * Whether the chain walks every environment and every node. Set by the caller that queued
     * it, since only the caller knows what it left out; the fixup pass at the end of the
     * chain classifies a target that never appeared as unresolvable only when this is true.
     */
    public bool $fullCorpus = false;

    /** sha1 of the mapping file at push time; a mid-run edit refuses to continue. */
    public string $mappingHash = '';

    /**
     * The counts this job accumulated across batches, for whatever reads the
     * queue afterwards — carried on the job because each batch is its own
     * process and a fresh RunTally.
     *
     * @var array<string, int>
     */
    public array $counts = [];

    public int $problems = 0;

    /**
     * Per-locale block content the target cannot hold, and per-asset failures —
     * the two counts `--fail-on-loss` gates on. They lived on singletons the
     * console read after its loop, so a queued run lost them; now every batch
     * folds its tally into the job.
     */
    public int $perSiteBlockLosses = 0;

    /** @var list<string> the first ten, for the run log */
    public array $perSiteBlockLossSample = [];

    public int $assetFailures = 0;

    /**
     * What the compile half reported, folded per batch. The console read these
     * off its one pipeline at the end of the run; a batch is a fresh pipeline,
     * so without the fold a queued run had no skip reasons and no loss counts.
     *
     * @var array<string, int>
     */
    public array $skippedSources = [];

    public int $lossyConversions = 0;

    public int $unresolvedAssets = 0;

    public int $mediaTokenIssues = 0;

    public int $deferredRefs = 0;

    public int $batchSize = 50;

    private ?EnvironmentPipeline $pipeline = null;
    private ?CompilerRun $compilerRun = null;
    private ?RunSettings $settings = null;
    private ?RunTally $tally = null;
    private ?EnvironmentContext $context = null;

    protected function loadData(): \craft\base\Batchable
    {
        // Refusing here as well as in the console command is deliberate: a job
        // is the one path that can reach a production queue without anyone
        // typing a command on that machine.
        if (ProductionGuard::isProduction()) {
            throw new RuntimeException('Refusing to migrate against CRAFT_ENVIRONMENT=production');
        }

        // Ahead of the element-index backlog, or the chain stalls: every entry
        // save spawns slug/search jobs at default priority, and LV's second
        // batch queued behind 34,000 of them on the first live run. Priority
        // rides on the job, so spawned continuations inherit it.
        $this->priority ??= 512;

        if (!is_file($this->mappingPath)) {
            throw new RuntimeException(sprintf('Mapping file is gone: %s', $this->mappingPath));
        }

        $hash = sha1((string) file_get_contents($this->mappingPath));

        if ($this->mappingHash === '') {
            $this->mappingHash = $hash;
        } elseif ($hash !== $this->mappingHash) {
            throw new RuntimeException(sprintf(
                'The mapping changed while this run was underway (%s). The head of the corpus was compiled '
                . 'against different rules than its tail would be — start a fresh run instead.',
                $this->mappingPath,
            ));
        }

        $mapping = Mapping::fromFile($this->mappingPath);
        $spec = $mapping->environments()[$this->environment] ?? null;

        if (!is_array($spec)) {
            throw new RuntimeException(sprintf('Mapping names no environment "%s"', $this->environment));
        }

        $this->settings = new RunSettings(
            dryRun: $this->dryRun,
            force: $this->force,
            limit: $this->limit,
            entriesOnly: $this->entriesOnly,
            only: $this->only,
        );
        $this->tally = new RunTally();
        $this->pipeline = EnvironmentPipeline::build($mapping, $this->settings);

        $this->context = $this->pipeline->prepare($mapping, $this->environment, (array) $spec, $this->settings);

        $compiler = $this->pipeline->compiler();
        $run = $compiler->begin($this->context->legacy, $this->environment);
        $this->compilerRun = $run;

        $units = [];

        foreach ($compiler->entityLaneCounts($run) as $lane => $count) {
            for ($offset = 0; $offset < $count; $offset += 25) {
                $units[] = ['e', $lane, $offset, 25];
            }
        }

        foreach (array_keys($run->nodesById) as $nodeId) {
            $units[] = ['n', $nodeId];
        }

        $units[] = ['t'];

        // A resumed batch rebuilds the run from scratch; the structural
        // placeholders an earlier batch emitted must be registered, silently,
        // up to the last node unit already processed.
        if ($this->itemOffset > 0) {
            for ($i = min($this->itemOffset, count($units)) - 1; $i >= 0; $i--) {
                if (($units[$i][0] ?? '') === 'n') {
                    $compiler->catchUpStructural($run, (int) $units[$i][1]);
                    break;
                }
            }
        }

        return new EnvironmentWorkload($units);
    }

    protected function processItem(mixed $item): void
    {
        $compiler = $this->pipeline->compiler();
        $emit = function(array $raw): void {
            $this->pipeline->processOne($raw, $this->context, $this->settings, $this->tally);
        };

        try {
            match ($item[0]) {
                'e' => $compiler->compileEntitySlice($this->compilerRun, (string) $item[1], (int) $item[2], (int) $item[3], $emit),
                'n' => $compiler->compileNodeUnit($this->compilerRun, (int) $item[1], $emit),
                't' => $compiler->finishStructural($this->compilerRun, $emit),
                default => throw new RuntimeException('Unknown work unit: ' . json_encode($item)),
            };
        } catch (\Throwable $e) {
            // A unit that dies — a compile-time throw, a write conflict that
            // outlasted its retries — is one unit's problem, not the batch's:
            // no state row was written, so the next run re-covers it. Failing
            // the job here would abandon the forty-nine units behind it and
            // the whole chain after them.
            $this->tally->problem(sprintf('unit %s: %s', json_encode($item), $e->getMessage()));
        }
    }

    /**
     * Whatever the label and whoever produced it, it fits the column.
     * craft_queue.progressLabel is varchar(255), and an exception message
     * riding a label has now killed environment jobs twice — once through the
     * old onProblem wiring, once through a path no grep of this plugin finds.
     * The job is the choke point every label passes through, so the job is
     * where the guarantee lives.
     */
    protected function setProgress($queue, float $progress, ?string $label = null): void
    {
        parent::setProgress($queue, $progress, $label === null ? null : mb_substr($label, 0, 250));
    }

    protected function before(): void
    {
        RunLog::default()->append([
            'event' => 'started',
            'job' => 'migrate',
            'environment' => $this->environment,
            'dryRun' => $this->dryRun,
            'force' => $this->force,
            'limit' => $this->limit,
            'only' => $this->only,
        ]);
    }

    protected function afterBatch(): void
    {
        $this->pipeline->foldCompileReport($this->tally);

        foreach ($this->tally->counts as $name => $count) {
            $this->counts[$name] = ($this->counts[$name] ?? 0) + $count;
        }

        foreach ($this->tally->skippedSources as $source => $count) {
            $this->skippedSources[$source] = ($this->skippedSources[$source] ?? 0) + $count;
        }

        $this->lossyConversions += $this->tally->lossyConversions;
        $this->unresolvedAssets += count($this->tally->unresolvedAssets);
        $this->mediaTokenIssues += count($this->tally->mediaTokenIssues);
        $this->deferredRefs += count($this->tally->deferredRefs);
        $this->problems += count($this->tally->problems);
        $this->perSiteBlockLosses += count($this->tally->perSiteBlockLosses);
        $this->perSiteBlockLossSample = array_slice(
            [...$this->perSiteBlockLossSample, ...$this->tally->perSiteBlockLosses],
            0,
            10,
        );
        $this->assetFailures += count($this->tally->assetFailures);
    }

    protected function after(): void
    {
        // Content failures are the report's to carry, not the queue's to
        // abort on: a console run keeps going past a failed payload and the
        // chain must too — stopping here would skip the adapters and the
        // corpus-wide passes over content an operator triages afterwards.
        RunLog::default()->append([
            'event' => 'finished',
            'job' => 'migrate',
            'environment' => $this->environment,
            'dryRun' => $this->dryRun,
            'force' => $this->force,
            'counts' => $this->counts,
            'problems' => $this->problems,
            'lossyConversions' => $this->lossyConversions,
            'skippedSources' => $this->skippedSources,
            'unresolvedAssets' => $this->unresolvedAssets,
            'mediaTokenIssues' => $this->mediaTokenIssues,
            'deferredRefs' => $this->deferredRefs,
            'perSiteBlocksNotRepresentable' => $this->perSiteBlockLosses,
            'perSiteBlockLossSample' => $this->perSiteBlockLossSample,
            'assetFailures' => $this->assetFailures,
        ]);

        QueueHelper::push(job: new RunAdaptersJob([
            'mappingPath' => $this->mappingPath,
            'environment' => $this->environment,
            'remainingEnvironments' => $this->remainingEnvironments,
            'dryRun' => $this->dryRun,
            'force' => $this->force,
            'entriesOnly' => $this->entriesOnly,
            'only' => $this->only,
            'chainCorpusPasses' => $this->chainCorpusPasses,
            'fullCorpus' => $this->fullCorpus,
            'mappingHash' => $this->mappingHash,
        ]), priority: 512);
    }

    protected function defaultDescription(): string
    {
        // What the queue shows an operator, so it says what is happening
        // rather than naming a class. Not translated: a job description is read
        // back out of the queue table by tests that cannot boot Craft, and a
        // guard for that would be worse than English here.
        return sprintf('Migrating %s — pages, content and files', $this->environment);
    }

    /**
     * A batch is minutes, and the budget check inside BaseBatchedJob breaks a
     * batch early rather than letting it near this. Declared on the job because
     * TTR must survive every push path — the CP run screen, the console
     * --queue mode, and the queue manager's Retry, which re-activates the row
     * with the channel default of 300 seconds and killed the first CP-driven
     * DE run at five minutes.
     */
    public function getTtr(): int
    {
        return 3600;
    }

    /**
     * A failed migration pass is retried by an operator who has read the
     * error, not by the queue on a loop.
     */
    public function canRetry($attempt, $error): bool
    {
        return false;
    }
}
