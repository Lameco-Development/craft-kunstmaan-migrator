<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\queue;

use lameco\kunstmaanmigrator\queue\jobs\MigrationPipelineJob;
use lameco\kunstmaanmigrator\queue\jobs\MigrationStageJob;
use lameco\kunstmaanmigrator\queue\jobs\WorkflowFailedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use yii\queue\RetryableJobInterface;

/**
 * Migration batches must not auto-replay (partially applied entries) and must
 * outlive yii\queue's 300s default TTR — and a workflow-reported failure must
 * surface as a failed queue job instead of a green one.
 */
final class MigrationJobRetryContractTest extends TestCase
{
    /** @return list<array{0: MigrationPipelineJob|MigrationStageJob}> */
    public static function jobs(): array
    {
        return [
            [new MigrationPipelineJob()],
            [new MigrationStageJob()],
        ];
    }

    #[DataProvider('jobs')]
    public function testJobsDeclareLongTtrAndNeverAutoRetry(MigrationPipelineJob|MigrationStageJob $job): void
    {
        self::assertInstanceOf(RetryableJobInterface::class, $job);
        self::assertSame(3600, $job->getTtr());
        self::assertFalse($job->canRetry(1, new RuntimeException('boom')));
        self::assertFalse($job->canRetry(99, new RuntimeException('boom')));
    }

    public function testWorkflowFailureBranchesThrowAfterMarkingTheRunFailed(): void
    {
        foreach ([MigrationPipelineJob::class, MigrationStageJob::class] as $class) {
            $source = file_get_contents((new \ReflectionClass($class))->getFileName());
            self::assertIsString($source);

            $markFailed = strpos($source, 'markFailed');
            $throwMarker = strpos($source, 'throw new WorkflowFailedException');
            self::assertIsInt($markFailed, "$class must mark the run failed");
            self::assertIsInt($throwMarker, "$class must rethrow workflow failure to the queue");
            self::assertGreaterThan(
                $markFailed,
                $throwMarker,
                "$class must record run failure context before throwing",
            );

            // The catch must not overwrite the rich failure context recorded
            // by the workflow-failure branch.
            self::assertStringContainsString('catch (WorkflowFailedException', $source);
        }

        self::assertTrue(is_subclass_of(WorkflowFailedException::class, RuntimeException::class));
    }
}
