<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\compile;

use lameco\kunstmaanmigrator\compile\MappingCompiler;
use PHPUnit\Framework\TestCase;

final class MappingCompilerValidationTest extends TestCase
{
    public function testMatrixHandlerOnScalarLongtextWarnsAndSkipsCompiledField(): void
    {
        $compiled = (new MappingCompiler())->compile(
            $this->mapping([
                $this->column('body', 'bodyBlocks', 'matrix', ['sqlType' => 'longtext']),
            ]),
            $this->pageStructure(),
            ['nl' => 'default'],
            entryTypeFlatHandles: ['articlePage' => ['title', 'bodyBlocks']],
        );

        self::assertArrayNotHasKey(
            'bodyBlocks',
            $compiled['nodeClasses']['App\\Entity\\ArticlePage']['fields'],
        );
        $warnings = implode("\n", $compiled['_compileReport']['warnings']);
        self::assertStringContainsString('App\\Entity\\ArticlePage', $warnings);
        self::assertStringContainsString('body', $warnings);
        self::assertStringContainsString('bodyBlocks', $warnings);
        self::assertStringContainsString('matrix', strtolower($warnings));
        self::assertStringContainsString('scalar', strtolower($warnings));
    }

    public function testDropdownHandlerOnArbitraryTextWarnsAndSkipsWithoutOptionMetadata(): void
    {
        $compiled = (new MappingCompiler())->compile(
            $this->mapping([
                $this->column('title', 'category', 'dropdown', ['sqlType' => 'varchar(255)']),
            ]),
            $this->pageStructure(),
            ['nl' => 'default'],
            entryTypeFlatHandles: ['articlePage' => ['title', 'category']],
        );

        self::assertArrayNotHasKey(
            'category',
            $compiled['nodeClasses']['App\\Entity\\ArticlePage']['fields'],
        );
        $warnings = implode("\n", $compiled['_compileReport']['warnings']);
        self::assertStringContainsString('dropdown', strtolower($warnings));
        self::assertStringContainsString('options', strtolower($warnings));
        self::assertStringContainsString('title', $warnings);
        self::assertStringContainsString('category', $warnings);
    }

    public function testRelationHandlerWithoutStateSourceJoinTableOrDerivableOptionsWarnsAndSkips(): void
    {
        $compiled = (new MappingCompiler())->compile(
            $this->mapping([
                $this->column('category_id', 'category', 'relation'),
            ]),
            $this->pageStructure(),
            ['nl' => 'default'],
            entryTypeFlatHandles: ['articlePage' => ['title', 'category']],
        );

        self::assertArrayNotHasKey(
            'category',
            $compiled['nodeClasses']['App\\Entity\\ArticlePage']['fields'],
        );
        $warnings = implode("\n", $compiled['_compileReport']['warnings']);
        self::assertStringContainsString('relation', strtolower($warnings));
        self::assertStringContainsString('stateSource', $warnings);
        self::assertStringContainsString('category_id', $warnings);
    }

    public function testPageBuilderHandlePropagatesWhenParentEntryTypeOwnsMatrixField(): void
    {
        $compiled = (new MappingCompiler())->compile(
            $this->mapping([], [
                $this->pagePart('App\\Entity\\TextPagePart', 'ArticlePage', 'bodyBlocks', 'textBlock'),
            ]),
            $this->pageStructure(),
            ['nl' => 'default'],
            entryTypeFlatHandles: ['articlePage' => ['title', 'bodyBlocks']],
        );

        self::assertSame(
            'bodyBlocks',
            $compiled['nodeClasses']['App\\Entity\\ArticlePage']['pageBuilderHandle'],
        );
        self::assertSame([], array_filter(
            $compiled['_compileReport']['warnings'],
            static fn(string $warning): bool => str_contains($warning, 'pageBuilderHandle `bodyBlocks` not propagated'),
        ));
    }

    public function testInvalidPageBuilderOwnershipKeepsFlatFallbackAndDoesNotPropagate(): void
    {
        $compiled = (new MappingCompiler())->compile(
            $this->mapping([], [
                $this->pagePart('App\\Entity\\TextPagePart', 'ArticlePage', 'otherBuilder', 'textBlock'),
            ]),
            $this->pageStructure(),
            ['nl' => 'default'],
            flatPagePartCandidates: ['articlePage' => 'body'],
            entryTypeFlatHandles: ['articlePage' => ['title', 'body']],
        );

        $nodeClass = $compiled['nodeClasses']['App\\Entity\\ArticlePage'];
        self::assertSame('', $nodeClass['pageBuilderHandle']);
        self::assertSame('body', $nodeClass['flatPagePartContent']);

        $warnings = implode("\n", $compiled['_compileReport']['warnings']);
        self::assertStringContainsString('pageBuilderHandle `otherBuilder` not propagated', $warnings);
        self::assertStringContainsString('flatPagePartContent `body`', $warnings);
    }

    public function testInvalidPageBuilderOwnershipWithoutFallbackIsVisibleForOperatorReview(): void
    {
        $compiled = (new MappingCompiler())->compile(
            $this->mapping([], [
                $this->pagePart('App\\Entity\\TextPagePart', 'ArticlePage', 'otherBuilder', 'textBlock'),
            ]),
            $this->pageStructure(),
            ['nl' => 'default'],
            entryTypeFlatHandles: ['articlePage' => ['title', 'body']],
        );

        self::assertSame(
            '',
            $compiled['nodeClasses']['App\\Entity\\ArticlePage']['pageBuilderHandle'],
        );
        $warnings = implode("\n", $compiled['_compileReport']['warnings']);
        self::assertStringContainsString('pageBuilderHandle `otherBuilder` not propagated', $warnings);
        self::assertStringContainsString('no flatPagePartContent fallback is available', $warnings);
        self::assertStringContainsString('operator review', $warnings);
    }

    /**
     * @param list<array<string, mixed>> $columns
     * @return array<string, mixed>
     */
    private function mapping(array $columns, array $pageParts = []): array
    {
        return [
            'proposals' => array_merge([
                [
                    'kind' => 'nodeClass',
                    'fqcn' => 'App\\Entity\\ArticlePage',
                    'sourceTable' => 'article_pages',
                    'targetSection' => 'articles',
                    'targetEntryType' => 'articlePage',
                    'status' => 'accepted',
                ],
                $this->column('title', 'title', 'plain'),
            ], $columns, $pageParts),
        ];
    }

    /** @return array<string, mixed> */
    private function column(string $column, string $targetHandle, string $handler, array $extra = []): array
    {
        return $extra + [
            'kind' => 'column',
            'table' => 'article_pages',
            'column' => $column,
            'targetEntryType' => 'articlePage',
            'targetHandle' => $targetHandle,
            'handler' => $handler,
            'status' => 'accepted',
        ];
    }

    /** @return array<string, mixed> */
    private function pagePart(string $pagePartClass, string $parentPageClass, string $matrixField, string $blockType): array
    {
        return [
            'kind' => 'pagePart',
            'pagePartClass' => $pagePartClass,
            'parentPageClass' => $parentPageClass,
            'context' => 'main',
            'targetMatrixField' => $matrixField,
            'targetBlockType' => $blockType,
            'status' => 'accepted',
        ];
    }

    /** @return array<string, mixed> */
    private function pageStructure(): array
    {
        return [
            'App\\Entity\\ArticlePage' => [
                'tableName' => 'article_pages',
                'contexts' => [['name' => 'main']],
            ],
        ];
    }
}
