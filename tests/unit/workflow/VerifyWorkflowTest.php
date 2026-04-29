<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\workflow;

use lameco\kunstmaanmigrator\workflow\VerifyWorkflow;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class VerifyWorkflowTest extends TestCase
{
    public function testWorkflowExposesSharedVerifyRunContract(): void
    {
        self::assertTrue(class_exists(VerifyWorkflow::class));

        $class = new ReflectionClass(VerifyWorkflow::class);
        self::assertTrue($class->hasMethod('run'));
        $source = (string) file_get_contents((string) $class->getFileName());

        foreach ([
            'baseline',
            'urlSpotCheck',
            'baselineDir',
            'countTolerance',
            'urlDiffThreshold',
            'entities',
            'locales',
            'since',
            'captureBaseline',
            'captureBaselineHtml',
            'output',
            'outputDir',
        ] as $key) {
            self::assertStringContainsString($key, $source);
        }

        foreach (['status', 'artifactPaths', 'summary', 'failure'] as $key) {
            self::assertStringContainsString($key, $source);
        }

        self::assertStringContainsString('VERIFY-', $source);
        self::assertStringContainsString('CountGateService', $source);
        self::assertStringContainsString('BaselineCounterService', $source);
        self::assertStringNotContainsString('shell_exec', $source);
        self::assertStringNotContainsString('php craft', $source);
    }

    public function testVerifyControllerDelegatesIndexActionToWorkflow(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../../src/console/VerifyController.php');

        self::assertStringContainsString('VerifyWorkflow', $source);
        self::assertStringContainsString('->run(', $source);
        $action = substr($source, (int) strpos($source, 'public function actionIndex(): int'), 1400);
        self::assertStringContainsString('(new VerifyWorkflow())->run(', $action);
        self::assertStringNotContainsString('$plugin = Plugin::getInstance()', $action);
    }

    public function testVerifyWorkflowPreservesMarkdownArtifactAndCaptureModes(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../../src/workflow/VerifyWorkflow.php');

        self::assertStringContainsString('renderReportMarkdown', $source);
        self::assertStringContainsString('writeAtomic', $source);
        self::assertStringContainsString('writeAtomicJson', $source);
        self::assertStringContainsString('captureBaseline', $source);
        self::assertStringContainsString('captureBaselineHtml', $source);
    }
}
