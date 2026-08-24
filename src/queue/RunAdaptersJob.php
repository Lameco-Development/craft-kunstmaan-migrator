<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\queue;

use craft\helpers\Queue as QueueHelper;
use craft\queue\BaseJob;
use Lameco\KumaCompile\Mapping\Mapping;
use Lameco\Kunstmaanmigrator\run\EnvironmentContext;
use Lameco\Kunstmaanmigrator\run\EnvironmentPipeline;
use Lameco\Kunstmaanmigrator\run\RunLog;
use Lameco\Kunstmaanmigrator\run\RunSettings;
use Lameco\Kunstmaanmigrator\safety\ProductionGuard;
use RuntimeException;
use yii\queue\RetryableJobInterface;

/**
 * One environment's adapter passes, then the next link of the chain (#48).
 *
 * Pushed by the environment job's last batch. When environments remain, the
 * next one is pushed from here; when none do, the corpus-wide fixup and
 * finalize passes are — which is what makes their ordering structural instead
 * of FIFO-hopeful (#47): they can no longer run before the entries they
 * resolve against exist.
 */
final class RunAdaptersJob extends BaseJob implements RetryableJobInterface
{
    public string $mappingPath = '';
    public string $environment = '';
    /** @var list<string> */
    public array $remainingEnvironments = [];
    public bool $dryRun = false;
    public bool $force = false;
    public bool $entriesOnly = false;
    /** @var list<string>|null */
    public ?array $only = null;
    public bool $chainCorpusPasses = true;
    public string $mappingHash = '';

    public function execute($queue): void
    {
        if (ProductionGuard::isProduction()) {
            throw new RuntimeException('Refusing to run adapters against CRAFT_ENVIRONMENT=production');
        }

        $mapping = Mapping::fromFile($this->mappingPath);
        $spec = $mapping->environments()[$this->environment] ?? null;

        if (!is_array($spec)) {
            throw new RuntimeException(sprintf('Mapping names no environment "%s"', $this->environment));
        }

        $settings = new RunSettings(
            dryRun: $this->dryRun,
            force: $this->force,
            entriesOnly: $this->entriesOnly,
            only: $this->only,
        );

        if (!$this->entriesOnly) {
            $pipeline = EnvironmentPipeline::build($mapping, $settings);
            [$db, $siteMap] = $pipeline->prepare($mapping, $this->environment, (array) $spec, $settings);

            RunLog::default()->track('adapters', ['environment' => $this->environment], function(array &$extra) use ($pipeline, $mapping, $db, $siteMap, $settings, $spec): void {
                $extra['adapters'] = $pipeline->runAdaptersFor(
                    new EnvironmentContext(
                        name: $this->environment,
                        database: (string) $spec['database'],
                        sites: $siteMap,
                        mediaRoots: EnvironmentPipeline::mediaRootsFrom((array) $spec),
                        mapping: $mapping,
                        legacy: $db,
                        only: $settings->only,
                    ),
                    $settings,
                );
            });
        }

        if ($this->remainingEnvironments !== []) {
            $next = $this->remainingEnvironments[0];

            QueueHelper::push(job: new MigrateEnvironmentJob([
                'mappingPath' => $this->mappingPath,
                'environment' => $next,
                'remainingEnvironments' => array_values(array_slice($this->remainingEnvironments, 1)),
                'dryRun' => $this->dryRun,
                'force' => $this->force,
                'entriesOnly' => $this->entriesOnly,
                'only' => $this->only,
                'chainCorpusPasses' => $this->chainCorpusPasses,
                'mappingHash' => $this->mappingHash,
            ]), priority: 512);

            return;
        }

        if ($this->chainCorpusPasses && !$this->entriesOnly && !$this->dryRun) {
            QueueHelper::push(job: new ResolveDeferredRefsJob(), priority: 512);
            QueueHelper::push(job: new FinalizeJob(['mappingPath' => $this->mappingPath, 'dryRun' => $this->dryRun]), priority: 512);
        }
    }

    protected function defaultDescription(): string
    {
        return sprintf('Migrating %s — SEO, redirects, navigation, forms', $this->environment);
    }

    /** The finalize pass rewrites every CKEditor field once per site. */
    public function getTtr(): int
    {
        return 7200;
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
