<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\queue;

use lameco\kunstmaanmigrator\queue\MigrateEnvironmentJob;
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
        $this->withEnvironment('production', function (): void {
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
        $this->withEnvironment('dev', function (): void {
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
