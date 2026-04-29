<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\queue;

use lameco\kunstmaanmigrator\queue\jobs\MigrationStageJob;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Source-level contract tests for the Phase 12 queued safe-stage runner.
 *
 * The job executes inside a Craft queue worker, so these tests lock the
 * serialization, safety, workflow-dispatch, and run-record contracts without
 * requiring a bootstrapped Craft queue.
 */
final class MigrationStageJobTest extends TestCase
{
    private function source(): string
    {
        $reflection = new ReflectionClass(MigrationStageJob::class);
        $source = file_get_contents((string) $reflection->getFileName());
        self::assertIsString($source);

        return $source;
    }

    public function testStageJobExposesSerializationSafePayloadProperties(): void
    {
        self::assertTrue(class_exists(MigrationStageJob::class));

        $reflection = new ReflectionClass(MigrationStageJob::class);
        self::assertSame('craft\queue\BaseJob', $reflection->getParentClass()?->getName());

        $expected = [
            'runId' => 'int',
            'stage' => 'string',
            'mode' => 'string',
            'filters' => 'array',
            'options' => 'array',
        ];

        foreach ($expected as $property => $type) {
            self::assertTrue($reflection->hasProperty($property), "Missing public payload property {$property}");
            $refProperty = $reflection->getProperty($property);
            self::assertTrue($refProperty->isPublic(), "{$property} must remain public for queue serialization");
            self::assertInstanceOf(ReflectionNamedType::class, $refProperty->getType());
            self::assertSame($type, $refProperty->getType()->getName());
        }
    }

    public function testExecuteRechecksProductionBeforeWorkflowDispatch(): void
    {
        $source = $this->source();

        self::assertStringContainsString('assertNotProductionForJob', $source);
        self::assertStringContainsString('markRunning($this->runId)', $source);
        self::assertStringContainsString('analyzeWorkflow', $source);
        self::assertStringContainsString('compileWorkflow', $source);
        self::assertStringContainsString('verifyWorkflow', $source);

        $safetyOffset = strpos($source, 'assertNotProductionForJob');
        $markRunningOffset = strpos($source, 'markRunning($this->runId)');
        $dispatchOffset = strpos($source, 'match ($this->stage)');

        self::assertIsInt($safetyOffset);
        self::assertIsInt($markRunningOffset);
        self::assertIsInt($dispatchOffset);
        self::assertLessThan($markRunningOffset, $safetyOffset);
        self::assertLessThan($dispatchOffset, $safetyOffset);
    }

    public function testStageJobUpdatesProgressSuccessFailureAndArtifacts(): void
    {
        $source = $this->source();

        foreach ([
            'setProgress',
            'markSucceeded',
            'markFailed',
            'updateProgress',
            'artifactPaths',
            'failure',
            'Unsupported migration stage',
        ] as $needle) {
            self::assertStringContainsString($needle, $source);
        }
    }
}
