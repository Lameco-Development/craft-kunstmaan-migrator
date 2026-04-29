<?php

declare(strict_types=1);

namespace tests\unit\console;

use PHPUnit\Framework\TestCase;

final class AnalyzeControllerDualSchemaDumpTest extends TestCase
{
    public function testAnalyzePersistsCraftTargetSchemaBesideSourceSchema(): void
    {
        $source = $this->workflowSource();

        self::assertStringContainsString("'/kunstmaan-schema.json'", $source);
        self::assertStringContainsString("'/craft-schema.json'", $source);
        self::assertStringContainsString('->dumpTargetSchema(', $source);
        self::assertStringContainsString(
            "(array) (\$sourceScan['entities'] ?? [])",
            $source,
            'Source schema dumping must receive the FQCN-keyed entity index so scoped runs use real Doctrine table names.',
        );
    }

    private function workflowSource(): string
    {
        $file = dirname(__DIR__, 3) . '/src/workflow/AnalyzeWorkflow.php';
        $source = (string) file_get_contents($file);
        self::assertNotSame('', $source, 'AnalyzeWorkflow source must be readable.');

        return $source;
    }
}
