<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\queue;

use Lameco\Kunstmaanmigrator\queue\FinalizeJob;
use Lameco\Kunstmaanmigrator\queue\ResolveDeferredRefsJob;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * The two corpus-wide jobs: what they refuse, and when they hold Craft's
 * maintenance off.
 *
 * Each is queued from two places — the end of a chain, and the run screen's
 * stand-alone button — and only the chain has a URI pass and an index stage
 * after it. A job that held on its own would leave what it wrote unsettled
 * and unindexed for good, so the flag is off unless the chain says otherwise.
 */
final class CorpusPassJobsTest extends TestCase
{
    /**
     * Yii's BaseObject constructor runs `Yii::configure()`, and the `Yii`
     * class is not loaded in this tier — so the job is built without it and
     * its public properties are set directly.
     *
     * @template T of object
     * @param class-string<T> $class
     * @param array<string, mixed> $config
     * @return T
     */
    private function job(string $class, array $config): object
    {
        $job = (new ReflectionClass($class))->newInstanceWithoutConstructor();

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

    /** @return iterable<string, array{0: class-string, 1: string}> */
    public static function jobs(): iterable
    {
        yield 'fixup' => [ResolveDeferredRefsJob::class, 'Refusing to resolve references'];
        yield 'finalize' => [FinalizeJob::class, 'Refusing to finalize'];
    }

    /** @param class-string $class */
    #[DataProvider('jobs')]
    public function testItRefusesToRunAgainstProduction(string $class, string $refusal): void
    {
        $this->withEnvironment('production', function() use ($class, $refusal): void {
            $job = $this->job($class, ['chainCorpusPasses' => true]);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage($refusal);

            $job->execute(null);
        });
    }

    /**
     * A job the run screen queues on its own is built from defaults, and the
     * default is the one that leaves Craft's maintenance alone.
     */
    #[DataProvider('jobs')]
    public function testAStandAloneJobDoesNotHold(string $class): void
    {
        $defaults = (new ReflectionClass($class))->getDefaultProperties();

        self::assertFalse($defaults['chainCorpusPasses']);
    }

    /** @param class-string $class */
    #[DataProvider('jobs')]
    public function testTheChainFlagSurvivesSerialisation(string $class): void
    {
        $job = $this->job($class, ['chainCorpusPasses' => true]);

        self::assertTrue(is_scalar($job->chainCorpusPasses));
    }

    /**
     * The hold pairs its two halves around the pass, and its counts ride the
     * job's run-log line the way the adapter job's do — the same
     * `slugJobsVetoed` and `searchIndexDeferred` an operator reads off a
     * console summary.
     */
    #[DataProvider('jobs')]
    public function testTheJobArmsAroundItsPassAndReportsTheCounts(string $class): void
    {
        $source = (string) file_get_contents((string) (new ReflectionClass($class))->getFileName());

        $branch = (int) strpos($source, 'if ($this->chainCorpusPasses) {');
        $guard = (int) strpos($source, 'MaintenanceGuard::build()->guard(');
        $counts = (int) strpos($source, "\$extra['slugJobsVetoed']");

        self::assertGreaterThan(0, $branch);
        self::assertGreaterThan($branch, $guard);
        self::assertGreaterThan($guard, $counts, 'the counts are read after the hold has been released');
        self::assertStringContainsString("\$extra['searchIndexDeferred'] = \$tally->searchIndexDeferred", $source);
        self::assertStringContainsString('} else {', $source);
        self::assertStringContainsString('$pass();', $source, 'a stand-alone job runs the same pass, unheld');
    }
}
