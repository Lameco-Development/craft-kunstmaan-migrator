<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\queue;

use lameco\kunstmaanmigrator\queue\jobs\MigrationPipelineJob;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Source-level contract tests for staged dry-run/live migration queue work.
 */
final class MigrationPipelineJobTest extends TestCase
{
    private function source(): string
    {
        $reflection = new ReflectionClass(MigrationPipelineJob::class);
        $source = file_get_contents((string) $reflection->getFileName());
        self::assertIsString($source);

        return $source;
    }

    public function testPipelineJobExposesSerializationSafeBatchedPayloadProperties(): void
    {
        self::assertTrue(class_exists(MigrationPipelineJob::class));

        $reflection = new ReflectionClass(MigrationPipelineJob::class);
        self::assertSame('craft\queue\BaseJob', $reflection->getParentClass()?->getName());

        $expected = [
            'runId' => 'int',
            'mode' => 'string',
            'filters' => 'array',
            'options' => 'array',
            'gateSnapshot' => 'array',
            'batchOffset' => 'int',
            'batchLimit' => 'int',
        ];

        foreach ($expected as $property => $type) {
            self::assertTrue($reflection->hasProperty($property), "Missing public payload property {$property}");
            $refProperty = $reflection->getProperty($property);
            self::assertTrue($refProperty->isPublic(), "{$property} must remain public for queue serialization");
            self::assertInstanceOf(ReflectionNamedType::class, $refProperty->getType());
            self::assertSame($type, $refProperty->getType()->getName());
        }
    }

    public function testPipelineJobAllowsOnlyDryRunAndLiveAndBlocksUnsafeLiveGates(): void
    {
        $source = $this->source();

        foreach (['dryRun', 'live', 'gateSnapshot', 'blocked', 'unknown', 'assertNotProductionForJob'] as $needle) {
            self::assertStringContainsString($needle, $source);
        }
    }

    public function testPipelineJobProcessesOneBatchAndChainsNextBatch(): void
    {
        $source = $this->source();

        foreach ([
            'migrateWorkflow',
            'batchOffset',
            'batchLimit',
            'nextBatchOffset',
            'new self',
            'push(',
            'appendQueueJobId',
            'markSucceeded',
            'setProgress',
        ] as $needle) {
            self::assertStringContainsString($needle, $source);
        }
    }

    public function testProductionCheckAppearsBeforeMigrateWorkflowDispatch(): void
    {
        $source = $this->source();

        $safetyOffset = strpos($source, 'assertNotProductionForJob');
        $dispatchOffset = strpos($source, 'migrateWorkflow->run');

        self::assertIsInt($safetyOffset);
        self::assertIsInt($dispatchOffset);
        self::assertLessThan($dispatchOffset, $safetyOffset);
        self::assertStringNotContainsString('shell_exec', $source);
        self::assertStringNotContainsString('php craft', $source);
    }

    public function testPipelineJobUsesOnlyScalarOrArrayPublicPayloadProperties(): void
    {
        $reflection = new ReflectionClass(MigrationPipelineJob::class);

        foreach ($reflection->getProperties() as $property) {
            if (!$property->isPublic()) {
                continue;
            }

            $type = $property->getType();
            self::assertInstanceOf(ReflectionNamedType::class, $type, $property->getName() . ' must have a named scalar/array type');
            self::assertContains($type->getName(), ['int', 'string', 'array'], $property->getName() . ' must be serialization-safe');
        }
    }

    public function testPipelineJobSourceLocksStagedBatchQueueChainingContract(): void
    {
        $source = $this->source();

        foreach (['batchOffset', 'batchLimit', 'nextBatchOffset', 'appendQueueJobId'] as $needle) {
            self::assertStringContainsString($needle, $source);
        }

        $pushOffset = strpos($source, 'push(new self');
        $appendOffset = strpos($source, 'appendQueueJobId');
        self::assertIsInt($pushOffset);
        self::assertIsInt($appendOffset);
        self::assertGreaterThan($pushOffset, $appendOffset, 'Next queue job ID must be appended after push() returns it');
    }
}
