<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\console;

use lameco\kunstmaanmigrator\console\CompileController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class CompileControllerPageRootedCoverageTest extends TestCase
{
    public function testCompileControllerWritesDeterministicPageRootedCoverageArtifacts(): void
    {
        $source = $this->source();

        self::assertStringContainsString('PageRootedSurfaceDiscovery', $source);
        self::assertStringContainsString('PageRootedCoverageAuditor', $source);
        self::assertStringContainsString('page-rooted-coverage.json', $source);
        self::assertStringContainsString('PAGE-ROOTED-COVERAGE.md', $source);
        self::assertStringContainsString('writeAtomicJson($coverageJsonPath', $source);
        self::assertStringContainsString('writeAtomic($coverageMarkdownPath', $source);

        $jsonPos = strpos($source, 'page-rooted-coverage.json');
        $passPos = strpos($source, 'Compile: PASS');
        self::assertIsInt($jsonPos);
        self::assertIsInt($passPos);
        self::assertLessThan($passPos, $jsonPos, 'Coverage artifacts must be written before final PASS output.');
    }

    public function testCoverageMarkdownRendererIsStructuralAndIncludesPerPageTables(): void
    {
        $source = $this->source();

        self::assertStringContainsString('renderMarkdown($coverageRows)', $source);
        self::assertStringContainsString('Structural audit generated during compile', $source);
        self::assertStringContainsString('| Surface | Source identifier | Category | Craft target | Reason |', $source);
        self::assertStringNotContainsString('samples', $source);
    }

    private function source(): string
    {
        return (string) file_get_contents((new ReflectionClass(CompileController::class))->getFileName());
    }
}
