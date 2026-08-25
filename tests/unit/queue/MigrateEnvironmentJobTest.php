<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\queue;

use Lameco\Kunstmaanmigrator\queue\MigrateEnvironmentJob;
use Lameco\Kunstmaanmigrator\run\RunTally;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * The refusals a queued run has to make before it touches anything.
 *
 * A job is the one path that can reach a queue on a machine nobody typed a
 * command on, so it re-checks the production guard rather than trusting that
 * whatever enqueued it already did.
 */
final class MigrateEnvironmentJobTest extends TestCase
{
    /**
     * Yii's BaseObject constructor runs `Yii::configure()`, and the `Yii`
     * class is not loaded in this tier — so the job is built without it and
     * its public properties are set directly, which is all the constructor
     * would have done.
     *
     * @param array<string, mixed> $config
     */
    private function job(array $config): MigrateEnvironmentJob
    {
        $job = (new ReflectionClass(MigrateEnvironmentJob::class))->newInstanceWithoutConstructor();

        foreach ($config as $property => $value) {
            $job->$property = $value;
        }

        return $job;
    }

    /** @param callable(): void $body */
    private function withEnvironment(string $environment, callable $body): void
    {
        $had = array_key_exists('CRAFT_ENVIRONMENT', $_SERVER);
        $previous = $_SERVER['CRAFT_ENVIRONMENT'] ?? null;
        $_SERVER['CRAFT_ENVIRONMENT'] = $environment;

        try {
            $body();
        } finally {
            if ($had) {
                $_SERVER['CRAFT_ENVIRONMENT'] = $previous;
            } else {
                unset($_SERVER['CRAFT_ENVIRONMENT']);
            }
        }
    }

    public function testItRefusesToRunAgainstProduction(): void
    {
        $this->withEnvironment('production', function(): void {
            $job = $this->job(['mappingPath' => '/does/not/matter', 'environment' => 'COM']);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Refusing to migrate against CRAFT_ENVIRONMENT=production');

            $job->execute(null);
        });
    }

    /**
     * The mapping travels as a path, not as a serialised object, so it can be
     * gone by the time the job runs. Saying which file is missing beats a
     * parse error from inside Mapping.
     */
    public function testItSaysWhichMappingFileWentMissing(): void
    {
        $this->withEnvironment('dev', function(): void {
            $job = $this->job([
                'mappingPath' => '/tmp/no-such-mapping-' . __LINE__ . '.yaml',
                'environment' => 'COM',
            ]);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Mapping file is gone');

            $job->execute(null);
        });
    }

    public function testTheDescriptionNamesTheEnvironmentBeingMigrated(): void
    {
        $job = $this->job(['mappingPath' => '/x.yaml', 'environment' => 'DE']);

        $describe = (new \ReflectionMethod($job, 'defaultDescription'))->invoke($job);

        self::assertStringContainsString('DE', (string) $describe);
    }

    /**
     * Each batch is its own process and a fresh tally; the job is what
     * outlives them, so the phase split folds onto it batch by batch — the
     * same table the console prints for one long tally.
     */
    public function testEachBatchFoldsItsPhaseTimingsOntoTheJob(): void
    {
        $job = $this->job(['mappingPath' => '/x.yaml', 'environment' => 'COM']);

        $first = new RunTally();
        $first->addTiming('entrySave', 2.0, 10);
        $first->addTiming('compile', 0.5, 10);
        $first->addTypeTiming('PartnerPage', 1.5, 5);
        $job->foldTimings($first, 3.0);

        $second = new RunTally();
        $second->addTiming('entrySave', 1.0, 5);
        $second->addTiming('assets', 4.0, 8);
        $second->addTypeTiming('PartnerPage', 0.5, 2);
        $second->addTypeTiming('NewsPage', 0.25, 1);
        $job->foldTimings($second, 6.0);

        self::assertSame(['seconds' => 3.0, 'count' => 15], $job->timings['entrySave']);
        self::assertSame(['seconds' => 0.5, 'count' => 10], $job->timings['compile']);
        self::assertSame(['seconds' => 4.0, 'count' => 8], $job->timings['assets']);
        self::assertSame(['seconds' => 2.0, 'count' => 7], $job->timingsByType['PartnerPage']);
        self::assertSame(['seconds' => 0.25, 'count' => 1], $job->timingsByType['NewsPage']);
        self::assertSame(9.0, $job->wallSeconds);
        self::assertSame(200.0, RunTally::timingReport($job->timings)['entrySave']['avgMs']);
    }

    public function testTheFlagsThatSurviveSerialisationAreScalars(): void
    {
        $job = $this->job([
            'mappingPath' => '/x.yaml',
            'environment' => 'COM',
            'dryRun' => true,
            'force' => true,
            'limit' => 25,
            'entriesOnly' => true,
            'only' => ['newsPage'],
        ]);

        foreach (['mappingPath', 'environment', 'dryRun', 'force', 'limit', 'entriesOnly', 'only'] as $property) {
            self::assertTrue(
                is_scalar($job->$property) || is_array($job->$property) || $job->$property === null,
                sprintf('%s must survive being serialised into the queue', $property),
            );
        }
    }
}
