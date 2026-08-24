<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\queue;

use craft\queue\BaseBatchedJob;
use Lameco\Kunstmaanmigrator\queue\MigrateEnvironmentJob;
use Lameco\Kunstmaanmigrator\queue\RunAdaptersJob;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * #48 — the environment job is batched and the pipeline is a chain.
 *
 * Lock the load-bearing surfaces: the base class (batch budgeting, spawned
 * continuations), the mapping-hash guard (a mid-run edit must refuse, not
 * silently compile the tail against different rules), the structural catch-up
 * on resume, and the chain hand-offs that made fixup/finalize ordering
 * structural instead of FIFO-hopeful (#47).
 */
final class BatchedMigrationChainTest extends TestCase
{
    public function testTheEnvironmentJobIsBatched(): void
    {
        self::assertTrue(is_subclass_of(MigrateEnvironmentJob::class, BaseBatchedJob::class));

        $defaults = (new ReflectionClass(MigrateEnvironmentJob::class))->getDefaultProperties();
        self::assertSame(50, $defaults['batchSize']);
        self::assertSame('', $defaults['mappingHash']);
        self::assertTrue($defaults['chainCorpusPasses']);
    }

    public function testAResumedBatchCatchesUpAndAMappingEditRefuses(): void
    {
        $source = (string) file_get_contents(
            (string) (new ReflectionClass(MigrateEnvironmentJob::class))->getFileName(),
        );

        self::assertStringContainsString('catchUpStructural', $source);
        self::assertStringContainsString('The mapping changed while this run was underway', $source);
        self::assertStringContainsString("QueueHelper::push(job: new RunAdaptersJob(", $source);
    }

    public function testTheChainEndsInTheCorpusPassesAndNowhereElseBeginsThem(): void
    {
        $source = (string) file_get_contents(
            (string) (new ReflectionClass(RunAdaptersJob::class))->getFileName(),
        );

        self::assertStringContainsString('new MigrateEnvironmentJob(', $source);
        self::assertStringContainsString('new ResolveDeferredRefsJob()', $source);
        self::assertStringContainsString('new FinalizeJob(', $source);
        // The next environment starts only after this one's adapters — and the
        // corpus passes only when no environment remains.
        self::assertMatchesRegularExpression('/remainingEnvironments !== \[\]/', $source);
    }
}
