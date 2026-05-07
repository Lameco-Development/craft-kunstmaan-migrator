<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\console;

use lameco\kunstmaanmigrator\audit\PageRootedCoverageAuditor;
use lameco\kunstmaanmigrator\console\CompileController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

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
        $source = (string) file_get_contents((new ReflectionClass(PageRootedCoverageAuditor::class))->getFileName());

        self::assertStringContainsString('renderMarkdown(array $coverageRows)', $source);
        self::assertStringContainsString('Structural audit generated during compile', $source);
        self::assertStringContainsString('| Surface | Source identifier | Category | Craft target | Reason |', $source);
        self::assertStringNotContainsString('PRIVATE', $source);
    }

    public function testCompileWarningSummarizerDeduplicatesAndAggregatesPageBuilderNoise(): void
    {
        $controller = (new ReflectionClass(CompileController::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(CompileController::class, 'summarizeCompileWarnings');
        $warnings = $method->invoke($controller, [
            'App\Entity\Pages\ContactSuccessPage: column proposal table.image_id → contentPage::image dropped',
            'App\Entity\Pages\ContactSuccessPage: column proposal table.image_id → contentPage::image dropped',
            'App\Entity\Pages\EmployeePage: pageBuilderHandle `pageBuilderCondensed` not propagated for TextPagePart because entry-type `teamMember` does not own that Matrix field; preserving page-part content via flatPagePartContent `bio`.',
            'App\Entity\Pages\EmployeePage: pageBuilderHandle `pageBuilderCondensed` not propagated for ImagePagePart because entry-type `teamMember` does not own that Matrix field; preserving page-part content via flatPagePartContent `bio`.',
        ]);

        self::assertCount(2, $warnings);
        self::assertStringContainsString('(repeated 2x)', $warnings[0]);
        self::assertStringContainsString('2 page-part mapping(s) not propagated', $warnings[1]);
        self::assertStringContainsString('TextPagePart, ImagePagePart', $warnings[1]);
    }

    public function testGraphWarningSummarizerAggregatesRelationIntentNoise(): void
    {
        $controller = (new ReflectionClass(CompileController::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(CompileController::class, 'summarizeGraphCompatibilityRows');
        $rows = $method->invoke($controller, [
            ['severity' => 'warning', 'code' => 'relation_intent_required', 'sourceRef' => 'a', 'targetRef' => 'b'],
            ['severity' => 'warning', 'code' => 'relation_intent_required', 'sourceRef' => 'c', 'targetRef' => 'd'],
            ['severity' => 'fatal', 'code' => 'schema_missing', 'message' => 'bad'],
        ]);

        self::assertCount(2, $rows);
        self::assertSame('schema_missing', $rows[0]['code']);
        self::assertSame('relation_intent_required', $rows[1]['code']);
        self::assertStringContainsString('2 graph relation(s)', $rows[1]['message']);
        self::assertStringContainsString('a -> b, c -> d', $rows[1]['message']);
    }

    private function source(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../../src/workflow/CompileWorkflow.php');
    }
}
