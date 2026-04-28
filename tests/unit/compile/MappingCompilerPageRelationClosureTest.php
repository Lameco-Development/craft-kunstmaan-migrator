<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\compile;

use lameco\kunstmaanmigrator\compile\MappingCompiler;
use PHPUnit\Framework\TestCase;

final class MappingCompilerPageRelationClosureTest extends TestCase
{
    public function testPageOwnedManyToManyWithJoinMetadataCompilesRelationHandlerOptions(): void
    {
        $compiled = (new MappingCompiler())->compile(
            $this->mapping([
                $this->relationColumn('categories', [
                    'relationType' => 'ManyToMany',
                    'relationProperty' => 'categories',
                    'targetFqcn' => 'App\\Entity\\Category',
                    'targetTable' => 'categories',
                    'joinTable' => 'article_category',
                    'joinLocalColumn' => 'article_id',
                    'joinForeignColumn' => 'category_id',
                ]),
            ]),
            $this->pageStructure(),
            ['nl' => 'default'],
            entryTypeFlatHandles: ['articlePage' => ['title', 'categories']],
        );

        $field = $compiled['nodeClasses']['App\\Entity\\ArticlePage']['fields']['categories'];
        self::assertSame('relation', $field['handler']);
        self::assertSame('id', $field['source']);
        self::assertSame([
            'stateSource' => 'App_Entity_Category',
            'joinTable' => 'article_category',
            'joinLocalColumn' => 'article_id',
            'joinForeignColumn' => 'category_id',
        ], $field['handlerOptions']);
    }

    public function testPageOwnedOneToManyWithChildBackReferenceCompilesRelationHandlerOptions(): void
    {
        $compiled = (new MappingCompiler())->compile(
            $this->mapping([
                $this->relationColumn('quotes', [
                    'relationType' => 'OneToMany',
                    'relationProperty' => 'quotes',
                    'targetFqcn' => 'App\\Entity\\Quote',
                    'targetTable' => 'article_quotes',
                    'backRefColumn' => 'article_id',
                ]),
            ]),
            $this->pageStructure(),
            ['nl' => 'default'],
            entryTypeFlatHandles: ['articlePage' => ['title', 'quotes']],
        );

        $field = $compiled['nodeClasses']['App\\Entity\\ArticlePage']['fields']['quotes'];
        self::assertSame('relation', $field['handler']);
        self::assertSame('id', $field['source']);
        self::assertSame([
            'stateSource' => 'App_Entity_Quote',
            'joinTable' => 'article_quotes',
            'joinLocalColumn' => 'article_id',
            'joinForeignColumn' => 'id',
        ], $field['handlerOptions']);
    }

    public function testUnsupportedPageOwnedRelationShapeWarnsAndSkips(): void
    {
        $compiled = (new MappingCompiler())->compile(
            $this->mapping([
                $this->relationColumn('categories', [
                    'relationType' => 'ManyToMany',
                    'relationProperty' => 'categories',
                    'targetFqcn' => 'App\\Entity\\Category',
                    'targetTable' => 'categories',
                ]),
            ]),
            $this->pageStructure(),
            ['nl' => 'default'],
            entryTypeFlatHandles: ['articlePage' => ['title', 'categories']],
        );

        self::assertArrayNotHasKey(
            'categories',
            $compiled['nodeClasses']['App\\Entity\\ArticlePage']['fields'],
        );
        $warnings = implode("\n", $compiled['_compileReport']['warnings']);
        self::assertStringContainsString('unsupported', strtolower($warnings));
        self::assertStringContainsString('ManyToMany', $warnings);
        self::assertStringContainsString('App\\Entity\\ArticlePage', $warnings);
        self::assertStringContainsString('categories', $warnings);
        self::assertStringContainsString('joinTable', $warnings);
    }

    /** @param list<array<string, mixed>> $columns */
    private function mapping(array $columns): array
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
                $this->plainColumn('title', 'title'),
            ], $columns),
        ];
    }

    /** @param array<string, mixed> $metadata */
    private function relationColumn(string $targetHandle, array $metadata): array
    {
        return [
            'kind' => 'column',
            'table' => 'article_pages',
            'column' => 'id',
            'targetEntryType' => 'articlePage',
            'targetHandle' => $targetHandle,
            'handler' => 'relation',
            'status' => 'accepted',
            'relation' => $metadata,
        ];
    }

    private function plainColumn(string $column, string $targetHandle): array
    {
        return [
            'kind' => 'column',
            'table' => 'article_pages',
            'column' => $column,
            'targetEntryType' => 'articlePage',
            'targetHandle' => $targetHandle,
            'handler' => 'plain',
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
