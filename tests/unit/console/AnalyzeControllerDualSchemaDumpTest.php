<?php

declare(strict_types=1);

namespace tests\unit\console;

use PHPUnit\Framework\TestCase;

final class AnalyzeControllerDualSchemaDumpTest extends TestCase
{
    public function testAnalyzePersistsCraftTargetSchemaBesideSourceSchema(): void
    {
        $source = $this->controllerSource();

        self::assertStringContainsString("'/kunstmaan-schema.json'", $source);
        self::assertStringContainsString("'/craft-schema.json'", $source);
        self::assertStringContainsString('->dumpTargetSchema(', $source);
        self::assertStringContainsString(
            "(array) (\$sourceScan['entities'] ?? [])",
            $source,
            'Source schema dumping must receive the FQCN-keyed entity index so scoped runs use real Doctrine table names.',
        );
    }

    private function controllerSource(): string
    {
        $file = dirname(__DIR__, 3) . '/src/console/AnalyzeController.php';
        $source = (string) file_get_contents($file);
        self::assertNotSame('', $source, 'AnalyzeController source must be readable.');

        return $source;
    }
}
