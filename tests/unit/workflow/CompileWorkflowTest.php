<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\workflow;

use lameco\kunstmaanmigrator\workflow\CompileWorkflow;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class CompileWorkflowTest extends TestCase
{
    public function testWorkflowExposesSharedCompileRunContract(): void
    {
        self::assertTrue(class_exists(CompileWorkflow::class));

        $class = new ReflectionClass(CompileWorkflow::class);
        self::assertTrue($class->hasMethod('run'));
        $source = (string) file_get_contents((string) $class->getFileName());

        foreach (['overwrite', 'dryRun'] as $key) {
            self::assertStringContainsString($key, $source);
        }

        foreach (['status', 'artifactPaths', 'summary', 'fatalWarningCount', 'warningCount', 'compiledAt', 'failure'] as $key) {
            self::assertStringContainsString($key, $source);
        }

        self::assertStringContainsString('GraphCompatibilityValidator', $source);
        self::assertStringNotContainsString('shell_exec', $source);
        self::assertStringNotContainsString('php craft', $source);
    }

    public function testCompileControllerDelegatesToWorkflow(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../../src/console/CompileController.php');

        self::assertStringContainsString('CompileWorkflow', $source);
        self::assertStringContainsString('->run(', $source);
        self::assertLessThan(
            strpos($source, 'CompileWorkflow'),
            strpos($source, '$plugin = Plugin::getInstance()') ?: PHP_INT_MAX,
            'Controller should delegate before orchestration service lookup.',
        );
    }

    public function testCompileWorkflowDoesNotIntroduceAiSurface(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../../src/workflow/CompileWorkflow.php');

        self::assertStringNotContainsString('anthropicApiKey', $source);
        self::assertStringNotContainsString('llmClassifier', $source);
    }
}
