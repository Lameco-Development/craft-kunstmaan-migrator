<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\console;

use lameco\kunstmaanmigrator\console\MigrateController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Phase 9 / Plan 09-01 — compiled mapping preflight characterization.
 *
 * Reflection keeps this unit-level: the helper is pure array inspection, while
 * actionIndex()/actionLoad() themselves require Craft and plugin bootstrap.
 */
final class MigrateControllerCompilePreflightTest extends TestCase
{
    /**
     * @return array{missing: list<string>, messages: list<string>}
     */
    private function preflight(array $mapping): array
    {
        $controller = (new ReflectionClass(MigrateController::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(MigrateController::class, 'preflightCompiledMapping');
        $method->setAccessible(true);

        /** @var array{missing: list<string>, messages: list<string>} $result */
        $result = $method->invoke($controller, $mapping);
        return $result;
    }

    public function testCoreCompiledBlocksAreRequired(): void
    {
        $result = $this->preflight(['proposals' => []]);

        self::assertSame(['nodeClasses', 'sections', 'sites'], $result['missing']);
        self::assertStringContainsString('nodeClasses', implode("\n", $result['messages']));
        self::assertStringContainsString('./craft kunstmaan-migrator/compile', implode("\n", $result['messages']));
    }

    public function testAcceptedRuntimeProposalKindsRequireCorrespondingCompiledBlocks(): void
    {
        $mapping = [
            'nodeClasses' => ['App\\Entity\\Pages\\NewsPage' => ['sourceTable' => 'kuma_news_page']],
            'sections' => ['newsArticle' => ['entryType' => 'newsArticle', 'section' => 'news']],
            'sites' => ['nl' => 'default'],
            'proposals' => [
                ['kind' => 'pagePart', 'pagePartClass' => 'HeaderPagePart', 'status' => 'accepted'],
                ['kind' => 'taxonomy', 'fqcn' => 'App\\Entity\\NewsCategory', 'status' => 'accepted'],
                ['kind' => 'dataProvider', 'fqcn' => 'App\\Provider\\HomeProvider', 'status' => 'accepted'],
                ['kind' => 'pagePart', 'pagePartClass' => 'DroppedPagePart', 'status' => 'dropped'],
            ],
        ];

        $result = $this->preflight($mapping);

        self::assertSame(['pageParts', 'taxonomies', 'dataProviders'], $result['missing']);
        self::assertStringContainsString('pageParts', implode("\n", $result['messages']));
        self::assertStringContainsString('taxonomies', implode("\n", $result['messages']));
        self::assertStringContainsString('dataProviders', implode("\n", $result['messages']));
        self::assertStringContainsString('./craft kunstmaan-migrator/compile', implode("\n", $result['messages']));
    }

    public function testCompleteRuntimeMappingPassesPreflight(): void
    {
        $mapping = [
            'nodeClasses' => ['App\\Entity\\Pages\\NewsPage' => ['sourceTable' => 'kuma_news_page']],
            'sections' => ['newsArticle' => ['entryType' => 'newsArticle', 'section' => 'news']],
            'sites' => ['nl' => 'default'],
            'pageParts' => ['HeaderPagePart' => ['targetBlockType' => 'header']],
            'taxonomies' => ['App\\Entity\\NewsCategory' => ['sourceTable' => 'news_categories']],
            'dataProviders' => ['App\\Provider\\HomeProvider' => ['target' => 'home']],
            'proposals' => [
                ['kind' => 'pagePart', 'pagePartClass' => 'HeaderPagePart', 'status' => 'accepted'],
                ['kind' => 'taxonomy', 'fqcn' => 'App\\Entity\\NewsCategory', 'status' => 'accepted'],
                ['kind' => 'dataProvider', 'fqcn' => 'App\\Provider\\HomeProvider', 'status' => 'accepted'],
            ],
        ];

        self::assertSame(['missing' => [], 'messages' => []], $this->preflight($mapping));
    }

    public function testMigrateIndexAndLoadCallCompiledMappingPreflight(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(MigrateController::class))->getFileName(),
        );

        self::assertGreaterThanOrEqual(
            3,
            substr_count($source, 'preflightCompiledMapping('),
            'Helper plus actionIndex() and actionLoad() call sites must be present.',
        );
        self::assertStringContainsString('FAIL compiled mapping incomplete', $source);
        self::assertStringContainsString('./craft kunstmaan-migrator/compile', $source);
    }
}
