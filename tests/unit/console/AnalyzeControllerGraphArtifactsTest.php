<?php

declare(strict_types=1);

namespace tests\unit\console;

use PHPUnit\Framework\TestCase;

final class AnalyzeControllerGraphArtifactsTest extends TestCase
{
    public function testAnalyzeWritesCanonicalWalkerGraphsForBothSides(): void
    {
        $source = $this->workflowSource();

        self::assertStringContainsString('kunstmaanPageWalker->walk', $source);
        self::assertStringContainsString('craftEntryWalker->walk', $source);
        self::assertStringContainsString("'/kunstmaan-schema.json'", $source);
        self::assertStringContainsString("'/craft-schema.json'", $source);
        self::assertStringContainsString('KunstmaanGraphContract::KEY_GRAPH_VERSION', $source);
        self::assertStringContainsString('CraftGraphContract::KEY_GRAPH_VERSION', $source);
    }

    public function testAnalyzePassesGraphPairToLlmBatchProposer(): void
    {
        $source = $this->workflowSource();

        self::assertStringContainsString('$kunstmaanGraph,', $source);
        self::assertStringContainsString('$craftGraph,', $source);
        self::assertStringContainsString('candidateEntryTypeHandles', $source);
    }

    private function workflowSource(): string
    {
        $file = dirname(__DIR__, 3) . '/src/workflow/AnalyzeWorkflow.php';
        $source = (string) file_get_contents($file);
        self::assertNotSame('', $source, 'AnalyzeWorkflow source must be readable.');

        return $source;
    }
}
