<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\workflow;

use lameco\kunstmaanmigrator\workflow\AnalyzeWorkflow;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AnalyzeWorkflowTest extends TestCase
{
    public function testWorkflowExposesSharedAnalyzeRunContract(): void
    {
        self::assertTrue(class_exists(AnalyzeWorkflow::class));

        $class = new ReflectionClass(AnalyzeWorkflow::class);
        self::assertTrue($class->hasMethod('run'));
        $source = (string) file_get_contents((string) $class->getFileName());

        foreach (['entities', 'locales', 'since', 'noAi', 'autoAcceptHigh', 'auditStrict', 'sourceStrict', 'noLayout', 'noProviders'] as $key) {
            self::assertStringContainsString($key, $source);
        }

        foreach (['status', 'filters', 'artifactPaths', 'summary', 'failure'] as $key) {
            self::assertStringContainsString($key, $source);
        }

        self::assertStringNotContainsString('shell_exec', $source);
        self::assertStringNotContainsString('php craft', $source);
    }

    public function testAnalyzeControllerDelegatesToWorkflow(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../../src/console/AnalyzeController.php');

        self::assertStringContainsString('AnalyzeWorkflow', $source);
        self::assertStringContainsString('->run(', $source);
        self::assertLessThan(
            strpos($source, 'AnalyzeWorkflow'),
            strpos($source, '$plugin = Plugin::getInstance()') ?: PHP_INT_MAX,
            'Controller should delegate before orchestration service lookup.',
        );
    }

    public function testAnthropicRemainsAnalyzeOnlyAndNoAiGatesIt(): void
    {
        $workflow = (string) file_get_contents(__DIR__ . '/../../../src/workflow/AnalyzeWorkflow.php');
        $compileWorkflow = is_file(__DIR__ . '/../../../src/workflow/CompileWorkflow.php')
            ? (string) file_get_contents(__DIR__ . '/../../../src/workflow/CompileWorkflow.php')
            : '';

        self::assertStringContainsString('$this->noAi', $workflow);
        self::assertStringContainsString('anthropicApiKey', $workflow);
        self::assertStringNotContainsString('anthropicApiKey', $compileWorkflow);
    }
}
