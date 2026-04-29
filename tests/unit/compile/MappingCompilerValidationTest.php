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

    public function testLegacyArticleDateColumnBackfillsNativeCraftPostDate(): void
    {
        $compiled = (new MappingCompiler())->compile(
            $this->mapping([
                $this->column('date', '', '', [
                    'sqlType' => 'datetime',
                    'relationIntent' => 'drop',
                ]),
            ]),
            $this->pageStructure(),
            ['nl' => 'default'],
            entryTypeFlatHandles: ['articlePage' => ['title', 'body']],
        );

        self::assertSame(
            ['handler' => 'date', 'source' => 'date'],
            $compiled['nodeClasses']['App\\Entity\\ArticlePage']['fields']['postDate'],
        );
    }

    public function testPostDateBackfillAlsoRecognizesExplicitPostDateColumnNames(): void
    {
        $compiled = (new MappingCompiler())->compile(
            $this->mapping([
                $this->column('post_date', '', '', ['sqlType' => 'datetime']),
            ]),
            $this->pageStructure(),
            ['nl' => 'default'],
            entryTypeFlatHandles: ['articlePage' => ['title', 'body']],
        );

        self::assertSame('post_date', $compiled['nodeClasses']['App\\Entity\\ArticlePage']['fields']['postDate']['source']);
        self::assertSame('date', $compiled['nodeClasses']['App\\Entity\\ArticlePage']['fields']['postDate']['handler']);
    }

    public function testEmployeeRelationAlsoFeedsContactCtaTeamMemberWhenCraftEntryTypeSupportsIt(): void
    {
        $compiled = (new MappingCompiler())->compile(
            $this->mapping([
                $this->column('employee_id', 'caseTeamMembers', 'relation', [
                    'handlerOptions' => ['stateSource' => 'App_Entity_Pages_EmployeePage'],
                ]),
            ]),
            $this->pageStructure(),
            ['nl' => 'default'],
            entryTypeFlatHandles: ['articlePage' => ['title', 'caseTeamMembers', 'contactCta']],
        );

        self::assertSame(
            $compiled['nodeClasses']['App\\Entity\\ArticlePage']['fields']['caseTeamMembers'],
            $compiled['nodeClasses']['App\\Entity\\ArticlePage']['fields']['contactCta.teamMember'],
        );
    }

    public function testEmployeeRelationDoesNotFeedContactCtaWhenCraftEntryTypeDoesNotOwnIt(): void
    {
        $compiled = (new MappingCompiler())->compile(
            $this->mapping([
                $this->column('employee_id', 'caseTeamMembers', 'relation', [
                    'handlerOptions' => ['stateSource' => 'App_Entity_Pages_EmployeePage'],
                ]),
            ]),
            $this->pageStructure(),
            ['nl' => 'default'],
            entryTypeFlatHandles: ['articlePage' => ['title', 'caseTeamMembers']],
        );

        self::assertArrayNotHasKey(
            'contactCta.teamMember',
            $compiled['nodeClasses']['App\\Entity\\ArticlePage']['fields'],
        );
    }

    public function testTargetRefBackfillsExecutableAssetFieldWhenTargetHandleAndHandlerAreBlank(): void
    {
        $compiled = (new MappingCompiler())->compile(
            $this->mapping([
                [
                    'kind' => 'column',
                    'table' => 'article_pages',
                    'column' => 'image_id',
                    'targetEntryType' => 'articlePage',
                    'targetHandle' => '',
                    'handler' => '',
                    'targetRef' => 'craft.field:articlePage.image',
                    'sourceRef' => 'kunstmaan.page:App\\Entity\\ArticlePage.image',
                    'relationIntent' => 'reference',
                    'status' => 'accepted',
                ],
            ]),
            $this->pageStructure(),
            ['nl' => 'default'],
            entryTypeFlatHandles: ['articlePage' => ['title', 'image']],
        );

        self::assertSame(
            ['handler' => 'asset', 'source' => 'image_id'],
            $compiled['nodeClasses']['App\\Entity\\ArticlePage']['fields']['image'],
        );
    }

    public function testEmbedTargetRefWithoutExplicitTargetHandleDoesNotCompileFlatMatrixField(): void
    {
        $compiled = (new MappingCompiler())->compile(
            $this->mapping([
                [
                    'kind' => 'column',
                    'table' => 'article_pages',
                    'column' => 'image_id',
                    'targetEntryType' => 'articlePage',
                    'targetHandle' => '',
                    'handler' => '',
                    'targetRef' => 'craft.field:articlePage.header',
                    'sourceRef' => 'kunstmaan.page:App\\Entity\\ArticlePage.image',
                    'relationIntent' => 'embed',
                    'status' => 'accepted',
                ],
            ]),
            $this->pageStructure(),
            ['nl' => 'default'],
            entryTypeFlatHandles: ['articlePage' => ['title', 'header']],
        );

        self::assertArrayNotHasKey(
            'header',
            $compiled['nodeClasses']['App\\Entity\\ArticlePage']['fields'],
        );
    }

    public function testAcceptedDropRowWithTargetRefDoesNotCompileField(): void
    {
        $compiled = (new MappingCompiler())->compile(
            $this->mapping([
                [
                    'kind' => 'column',
                    'table' => 'article_pages',
                    'column' => 'page_title',
                    'targetEntryType' => 'articlePage',
                    'targetHandle' => '',
                    'handler' => '',
                    'targetRef' => 'craft.field:articlePage.seo',
                    'relationIntent' => 'drop',
                    'status' => 'accepted',
                ],
            ]),
            $this->pageStructure(),
            ['nl' => 'default'],
            entryTypeFlatHandles: ['articlePage' => ['title', 'seo']],
        );

        self::assertArrayNotHasKey('seo', $compiled['nodeClasses']['App\\Entity\\ArticlePage']['fields']);
    }

    public function testCompileOverwritePreservesOperatorNodeClassRuntimeOverrides(): void
    {
        $mapping = $this->mapping([
            $this->column('summary', 'summary', 'plain'),
        ]);
        $mapping['nodeClasses'] = [
            'App\\Entity\\ArticlePage' => [
                'fields' => [
                    'manualRelation' => [
                        'source' => 'employee_id',
                        'handler' => 'relation',
                        'handlerOptions' => ['stateSource' => 'teamMember'],
                    ],
                ],
                'headerBlock' => [
                    'fieldHandle' => 'header',
                    'blockType' => 'headerDefault',
                    'title' => '{title}',
                ],
                'mergeRelations' => [
                    'employee' => [
                        'mode' => 'flatten',
                        'table' => 'employees',
                        'fk' => 'employee_id',
                    ],
                ],
            ],
        ];

        $compiled = (new MappingCompiler())->compile(
            $mapping,
            $this->pageStructure(),
            ['nl' => 'default'],
            entryTypeFlatHandles: ['articlePage' => ['title', 'summary', 'manualRelation', 'header']],
        );

        $nodeClass = $compiled['nodeClasses']['App\\Entity\\ArticlePage'];
        self::assertArrayHasKey('summary', $nodeClass['fields']);
        self::assertArrayHasKey('manualRelation', $nodeClass['fields']);
        self::assertSame('headerDefault', $nodeClass['headerBlock']['blockType']);
        self::assertSame('employees', $nodeClass['mergeRelations']['employee']['table']);
    }

    public function testCompileOverwriteDoesNotPreserveStaleMatrixContainerFieldOverrides(): void
    {
        $mapping = $this->mapping([]);
        $mapping['nodeClasses'] = [
            'App\\Entity\\ArticlePage' => [
                'fields' => [
                    'header' => ['source' => 'image_id', 'handler' => 'asset'],
                    'pageBuilder' => ['source' => 'logo_id', 'handler' => 'plain'],
                    'manualRelation' => [
                        'source' => 'employee_id',
                        'handler' => 'relation',
                        'handlerOptions' => ['stateSource' => 'teamMember'],
                    ],
                ],
                'pageBuilderHandle' => 'pageBuilder',
                'headerBlock' => [
                    'fieldHandle' => 'header',
                    'blockType' => 'headerDefault',
                ],
            ],
        ];

        $compiled = (new MappingCompiler())->compile(
            $mapping,
            $this->pageStructure(),
            ['nl' => 'default'],
            entryTypeFlatHandles: ['articlePage' => ['title', 'header', 'pageBuilder', 'manualRelation']],
        );

        $fields = $compiled['nodeClasses']['App\\Entity\\ArticlePage']['fields'];
        self::assertArrayNotHasKey('header', $fields);
        self::assertArrayNotHasKey('pageBuilder', $fields);
        self::assertArrayHasKey('manualRelation', $fields);
    }

    public function testCompileOverwriteDoesNotPreservePlainSeoFieldOverride(): void
    {
        $mapping = $this->mapping([]);
        $mapping['nodeClasses'] = [
            'App\\Entity\\ArticlePage' => [
                'fields' => [
                    'seo' => [
                        'source' => 'page_title',
                        'handler' => 'plain',
                    ],
                ],
            ],
        ];

        $compiled = (new MappingCompiler())->compile(
            $mapping,
            $this->pageStructure(),
            ['nl' => 'default'],
            entryTypeFlatHandles: ['articlePage' => ['title', 'seo']],
        );

        self::assertArrayNotHasKey('seo', $compiled['nodeClasses']['App\\Entity\\ArticlePage']['fields']);
    }

    public function testAcceptedNodeClassProposalCanEmitMergeRelations(): void
    {
        $mapping = $this->mapping([]);
        $mapping['proposals'][0]['mergeRelations'] = [
            'employee' => [
                'mode' => 'flatten',
                'table' => 'employees',
                'fk' => 'employee_id',
                'pk' => 'id',
            ],
        ];

        $compiled = (new MappingCompiler())->compile(
            $mapping,
            $this->pageStructure(),
            ['nl' => 'default'],
            entryTypeFlatHandles: ['articlePage' => ['title']],
        );

        self::assertSame(
            'employees',
            $compiled['nodeClasses']['App\\Entity\\ArticlePage']['mergeRelations']['employee']['table'],
        );
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

    public function testHeuristicBackfillDoesNotInventUnavailableCraftEntryTypes(): void
    {
        $compiled = (new MappingCompiler())->compile(
            [
                'proposals' => [
                    [
                        'kind' => 'nodeClass',
                        'fqcn' => 'App\\Entity\\ArticlePage',
                        'sourceTable' => 'article_pages',
                        'targetSection' => 'articles',
                        'targetEntryType' => 'articlePage',
                        'status' => 'accepted',
                    ],
                    [
                        'kind' => 'column',
                        'table' => 'article_pages',
                        'column' => 'title',
                        'targetEntryType' => 'articlePage',
                        'targetHandle' => 'title',
                        'handler' => 'plain',
                        'status' => 'accepted',
                    ],
                    [
                        'kind' => 'column',
                        'table' => 'unavailable_pages',
                        'column' => 'title',
                        'targetEntryType' => '',
                        'targetHandle' => 'title',
                        'handler' => 'plain',
                        'status' => 'accepted',
                    ],
                ],
            ],
            [
                'App\\Entity\\ArticlePage' => [
                    'tableName' => 'article_pages',
                    'contexts' => [['name' => 'main']],
                ],
                'App\\Entity\\UnavailablePage' => [
                    'tableName' => 'unavailable_pages',
                    'contexts' => [['name' => 'main']],
                ],
            ],
            ['nl' => 'default'],
            craftEntryTypeHandles: ['articlePage'],
            entryTypeFlatHandles: ['articlePage' => ['title']],
        );

        self::assertArrayHasKey('articlePage', $compiled['sections']);
        self::assertArrayNotHasKey('unavailablePage', $compiled['sections']);
        self::assertSame(0, $compiled['_compileReport']['autoAssignedTargets']);
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
