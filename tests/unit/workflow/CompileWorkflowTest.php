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
        $action = substr($source, (int) strpos($source, 'public function actionIndex(): int'), 1100);
        self::assertStringContainsString('(new CompileWorkflow())->run(', $action);
        self::assertStringNotContainsString('$plugin = Plugin::getInstance()', $action);
    }

    public function testCompileWorkflowDoesNotIntroduceAiSurface(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../../src/workflow/CompileWorkflow.php');

        self::assertStringNotContainsString('anthropicApiKey', $source);
        self::assertStringNotContainsString('llmClassifier', $source);
    }
}
