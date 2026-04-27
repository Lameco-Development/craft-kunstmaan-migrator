<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\compile;

use lameco\kunstmaanmigrator\compile\MappingCompiler;
use PHPUnit\Framework\TestCase;

/**
 * Phase 7 — implicit-content page-part compilation. Verifies that accepted
 * `__implicit_content__` proposals are folded into mapping.pageParts and that
 * the parent nodeClasses entry's pageBuilderHandle / pageBuilderContexts is
 * wired so ExtractService's synthetic page-part injection has a target.
 */
final class MappingCompilerImplicitBlocksTest extends TestCase
{
    private MappingCompiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new MappingCompiler();
    }

    public function testAcceptedImplicitRowEmitsPagePartsEntryAndWiresPageBuilder(): void
    {
        $mapping = [
            'proposals' => [
                // Column row so the FQCN is reachable in nodeClasses[].
                $this->columnRow('news_pages', 'title', 'newsPage', 'title'),
                $this->implicitRow(
                    parentPageClass: 'NewsPage',
                    context: 'main',
                    targetMatrixField: 'pageBuilder',
                    targetBlockType: 'textContentBlock',
                    fields: [
                        ['sourceProperty' => 'content', 'targetHandle' => 'html', 'handler' => 'ckeditor'],
                    ],
                ),
            ],
        ];
        $pageStructure = [
            'App\\Entity\\Pages\\NewsPage' => ['tableName' => 'news_pages', 'contexts' => []],
        ];

        $compiled = $this->compiler->compile($mapping, $pageStructure, ['nl' => 'default']);

        // mapping.pageParts entry emitted under the synthetic key.
        $key = '__implicit_content__|NewsPage|main';
        $this->assertArrayHasKey($key, $compiled['pageParts']);
        $this->assertSame('textContentBlock', $compiled['pageParts'][$key]['target']);
        $this->assertSame(
            ['html' => ['source' => 'content', 'handler' => 'ckeditor']],
            $compiled['pageParts'][$key]['fields'],
        );

        // nodeClasses[NewsPage].pageBuilderHandle wired from row.targetMatrixField.
        $nc = $compiled['nodeClasses']['App\\Entity\\Pages\\NewsPage'];
        $this->assertSame('pageBuilder', $nc['pageBuilderHandle']);
        $this->assertContains('main', $nc['pageBuilderContexts']);

        // Report counter.
        $this->assertSame(1, $compiled['_compileReport']['implicitBlocksEmitted']);
    }

    public function testEmptyHandlerDefaultsToPlain(): void
    {
        $mapping = [
            'proposals' => [
                $this->columnRow('news_pages', 'title', 'newsPage', 'title'),
                $this->implicitRow(
                    parentPageClass: 'NewsPage',
                    context: 'main',
                    targetMatrixField: 'pageBuilder',
                    targetBlockType: 'plainContentBlock',
                    fields: [
                        ['sourceProperty' => 'intro', 'targetHandle' => 'intro', 'handler' => ''],
                    ],
                ),
            ],
        ];
        $pageStructure = [
            'App\\Entity\\Pages\\NewsPage' => ['tableName' => 'news_pages'],
        ];

        $compiled = $this->compiler->compile($mapping, $pageStructure, ['nl' => 'default']);

        $key = '__implicit_content__|NewsPage|main';
        $this->assertSame('plain', $compiled['pageParts'][$key]['fields']['intro']['handler']);
    }

    public function testRowMissingMatrixOrBlockTypeIsSkippedWithWarning(): void
    {
        $mapping = [
            'proposals' => [
                $this->columnRow('news_pages', 'title', 'newsPage', 'title'),
                $this->implicitRow(
                    parentPageClass: 'NewsPage',
                    context: 'main',
                    targetMatrixField: '', // missing
                    targetBlockType: 'textContentBlock',
                    fields: [['sourceProperty' => 'content', 'targetHandle' => 'html', 'handler' => 'ckeditor']],
                ),
            ],
        ];
        $pageStructure = ['App\\Entity\\Pages\\NewsPage' => ['tableName' => 'news_pages']];

        $compiled = $this->compiler->compile($mapping, $pageStructure, ['nl' => 'default']);

        $this->assertSame(0, $compiled['_compileReport']['implicitBlocksEmitted']);
        $this->assertSame([], $compiled['pageParts']);
        $warnings = $compiled['_compileReport']['warnings'];
        $this->assertNotEmpty(array_filter(
            $warnings,
            static fn(string $w): bool => str_contains($w, 'targetMatrixField'),
        ));
    }

    public function testRowWithEmptyTargetHandleFieldsIsSkipped(): void
    {
        $mapping = [
            'proposals' => [
                $this->columnRow('news_pages', 'title', 'newsPage', 'title'),
                $this->implicitRow(
                    parentPageClass: 'NewsPage',
                    context: 'main',
                    targetMatrixField: 'pageBuilder',
                    targetBlockType: 'textContentBlock',
                    fields: [
                        ['sourceProperty' => 'content', 'targetHandle' => '', 'handler' => 'ckeditor'],
                    ],
                ),
            ],
        ];
        $pageStructure = ['App\\Entity\\Pages\\NewsPage' => ['tableName' => 'news_pages']];

        $compiled = $this->compiler->compile($mapping, $pageStructure, ['nl' => 'default']);

        $this->assertSame(0, $compiled['_compileReport']['implicitBlocksEmitted']);
        $warnings = $compiled['_compileReport']['warnings'];
        $this->assertNotEmpty(array_filter(
            $warnings,
            static fn(string $w): bool => str_contains($w, 'no fields with both sourceProperty and targetHandle'),
        ));
    }

    public function testOperatorCuratedPagePartsEntrySurvivesSkipExisting(): void
    {
        $mapping = [
            'pageParts' => [
                '__implicit_content__|NewsPage|main' => [
                    'target' => 'operatorBlock',
                    'fields' => ['html' => ['source' => 'operatorSource', 'handler' => 'plain']],
                ],
            ],
            'proposals' => [
                $this->columnRow('news_pages', 'title', 'newsPage', 'title'),
                $this->implicitRow(
                    parentPageClass: 'NewsPage',
                    context: 'main',
                    targetMatrixField: 'pageBuilder',
                    targetBlockType: 'incomingBlock', // would-be override
                    fields: [['sourceProperty' => 'content', 'targetHandle' => 'html', 'handler' => 'ckeditor']],
                ),
            ],
        ];
        $pageStructure = ['App\\Entity\\Pages\\NewsPage' => ['tableName' => 'news_pages']];

        $compiled = $this->compiler->compile($mapping, $pageStructure, ['nl' => 'default']);

        $key = '__implicit_content__|NewsPage|main';
        // Operator-curated entry survives; compiler did NOT overwrite.
        $this->assertSame('operatorBlock', $compiled['pageParts'][$key]['target']);
        $this->assertSame('operatorSource', $compiled['pageParts'][$key]['fields']['html']['source']);
        // Counter still 0 because skip-existing kicked in.
        $this->assertSame(0, $compiled['_compileReport']['implicitBlocksEmitted']);
    }

    public function testOperatorPageBuilderHandleSurvivesImplicitWiring(): void
    {
        // Compile-time mutation of nodeClasses respects operator hand-edits in
        // the input mapping — but compile() rebuilds nodeClasses from scratch
        // anyway, so this test verifies the OPPOSITE invariant: a row with a
        // pre-set pageBuilderHandle on a SECOND implicit row for the SAME FQCN
        // (different context) does not clobber the first wire.
        $mapping = [
            'proposals' => [
                $this->columnRow('news_pages', 'title', 'newsPage', 'title'),
                $this->implicitRow(
                    parentPageClass: 'NewsPage',
                    context: 'main',
                    targetMatrixField: 'pageBuilder',
                    targetBlockType: 'textContentBlock',
                    fields: [['sourceProperty' => 'content', 'targetHandle' => 'html', 'handler' => 'ckeditor']],
                ),
                $this->implicitRow(
                    parentPageClass: 'NewsPage',
                    context: 'sidebar',
                    targetMatrixField: 'wouldBeIgnored', // first wire wins
                    targetBlockType: 'sidebarBlock',
                    fields: [['sourceProperty' => 'sidebar', 'targetHandle' => 'html', 'handler' => 'ckeditor']],
                ),
            ],
        ];
        $pageStructure = ['App\\Entity\\Pages\\NewsPage' => ['tableName' => 'news_pages']];

        $compiled = $this->compiler->compile($mapping, $pageStructure, ['nl' => 'default']);

        $nc = $compiled['nodeClasses']['App\\Entity\\Pages\\NewsPage'];
        $this->assertSame('pageBuilder', $nc['pageBuilderHandle']); // first row's value held
        $this->assertContains('main', $nc['pageBuilderContexts']);
        $this->assertContains('sidebar', $nc['pageBuilderContexts']);
        $this->assertSame(2, $compiled['_compileReport']['implicitBlocksEmitted']);
    }

    public function testBasenameCollisionEmitsWarning(): void
    {
        $mapping = [
            'proposals' => [
                $this->columnRow('news_pages', 'title', 'newsPage', 'title'),
            ],
        ];
        $pageStructure = [
            'App\\Entity\\Pages\\NewsPage'   => ['tableName' => 'news_pages'],
            'App\\Entity\\Archive\\NewsPage' => ['tableName' => 'archive_news_pages'],
        ];

        $compiled = $this->compiler->compile($mapping, $pageStructure, ['nl' => 'default']);

        $warnings = $compiled['_compileReport']['warnings'];
        $this->assertNotEmpty(array_filter(
            $warnings,
            static fn(string $w): bool => str_contains($w, 'basename "NewsPage"'),
        ));
    }

    public function testParentPageClassWithNoMatchingFqcnEmitsWarningButStillEmitsPagePartEntry(): void
    {
        $mapping = [
            'proposals' => [
                $this->implicitRow(
                    parentPageClass: 'GhostPage', // not in pageStructure
                    context: 'main',
                    targetMatrixField: 'pageBuilder',
                    targetBlockType: 'textContentBlock',
                    fields: [['sourceProperty' => 'content', 'targetHandle' => 'html', 'handler' => 'ckeditor']],
                ),
            ],
        ];
        $pageStructure = ['App\\Entity\\Pages\\NewsPage' => ['tableName' => 'news_pages']];

        $compiled = $this->compiler->compile($mapping, $pageStructure, ['nl' => 'default']);

        $key = '__implicit_content__|GhostPage|main';
        $this->assertArrayHasKey($key, $compiled['pageParts']);
        $warnings = $compiled['_compileReport']['warnings'];
        $this->assertNotEmpty(array_filter(
            $warnings,
            static fn(string $w): bool =>
                str_contains($w, 'no matching nodeClasses') && str_contains($w, 'GhostPage'),
        ));
    }

    /** @return array<string, mixed> */
    private function columnRow(string $table, string $column, string $entryType, string $handle): array
    {
        return [
            'kind'            => 'column',
            'table'           => $table,
            'column'          => $column,
            'targetEntryType' => $entryType,
            'targetHandle'    => $handle,
            'handler'         => 'plain',
            'status'          => 'accepted',
        ];
    }

    /**
     * @param  list<array<string, string>> $fields
     * @return array<string, mixed>
     */
    private function implicitRow(
        string $parentPageClass,
        string $context,
        string $targetMatrixField,
        string $targetBlockType,
        array $fields,
    ): array {
        return [
            'kind'              => 'pagePart',
            'pagePartClass'     => '__implicit_content__',
            'parentPageClass'   => $parentPageClass,
            'context'           => $context,
            'targetMatrixField' => $targetMatrixField,
            'targetBlockType'   => $targetBlockType,
            'fields'            => $fields,
            'status'            => 'accepted',
        ];
    }
}
