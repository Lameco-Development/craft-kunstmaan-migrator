<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\console;

use lameco\kunstmaanmigrator\console\AnalyzeController;
use lameco\kunstmaanmigrator\source\DoctrineColumnInfo;
use lameco\kunstmaanmigrator\source\DoctrineEntityInfo;
use lameco\kunstmaanmigrator\source\DoctrineRelationInfo;
use PHPUnit\Framework\TestCase;

/**
 * Phase 8.7 / F1 — page-wins auto-folding for ManyToOne 1:1 wrapping pairs.
 *
 * Exercises AnalyzeController::emitPageWrapSyntheticColumns directly via its
 * static signature — no Craft + DB harness needed because the helper takes
 * value-object inputs (DoctrineEntityInfo) and an already-extracted columns-
 * by-table array. The real pipeline plugs the same helper into actionIndex
 * between the entity-level LLM step and the targetEntryType backfill, so the
 * unit-tested behaviour matches what runs in production.
 *
 * Behaviour locked here:
 *   - Symmetric name-match gating: `<X>Page` strips to `<X>` matching the
 *     target basename. `EmployeePage`→`Employee` triggers; `CaseStudyPage`→
 *     `CaseStudyCategory` does NOT (CaseStudyCategory contributes its own
 *     taxonomy entry; the wrap is a 1:N FK, not a 1:1 wrap).
 *   - Synthetic columns shaped `_rel:<property>.<column>` so the existing
 *     residual-column LLM prompt (LlmClassifier::buildBatchPrompt:2219)
 *     handles them through its native `_rel:` branch.
 *   - `id` and FK columns on the target are skipped — structural plumbing,
 *     not content.
 *   - targetEntryType inherited from the page's nodeClass proposal map at
 *     emit time — synthetic rows reach the LLM with entry-type context even
 *     when the page's nodeClass landed medium confidence (the lines 441-469
 *     backfill is high-only).
 *   - Vendor entities (parser doesn't see them) are skipped silently.
 *   - Folded-target FQCN list returned for the downstream taxonomy-drop step.
 */
final class AnalyzeControllerPageWrapFoldTest extends TestCase
{
    public function testNameMatchedPageWrapEmitsRelSyntheticColumns(): void
    {
        // EmployeePage (table: employee_pages) wraps Employee 1:1 via $employee ManyToOne.
        $employeePage = new DoctrineEntityInfo(
            fqcn: 'App\\Entity\\Pages\\EmployeePage',
            tableName: 'employee_pages',
            columns: [
                new DoctrineColumnInfo('id', 'integer', false, 'id'),
                new DoctrineColumnInfo('title', 'string', false, 'title'),
                new DoctrineColumnInfo('employee_id', 'integer', true, 'employee'),
            ],
            relations: [
                new DoctrineRelationInfo(
                    relationType: 'ManyToOne',
                    targetEntity: 'App\\Entity\\Employee',
                    propertyName: 'employee',
                    fkColumn: 'employee_id',
                ),
            ],
        );
        $employee = new DoctrineEntityInfo(
            fqcn: 'App\\Entity\\Employee',
            tableName: 'employees',
            columns: [
                new DoctrineColumnInfo('id', 'integer', false, 'id'),
                new DoctrineColumnInfo('email', 'string', true, 'email'),
                new DoctrineColumnInfo('job_title', 'string', true, 'jobTitle'),
            ],
            relations: [],
        );

        $pageStructure = [
            'App\\Entity\\Pages\\EmployeePage' => ['tableName' => 'employee_pages'],
        ];
        $entityIndex = [
            'App\\Entity\\Pages\\EmployeePage' => $employeePage,
            'App\\Entity\\Employee'             => $employee,
        ];
        $columnsByTable = [
            'employees' => [
                ['column' => 'id',        'fillRate' => 100.0, 'sqlType' => 'int',     'samples' => []],
                ['column' => 'email',     'fillRate' => 95.0,  'sqlType' => 'varchar', 'samples' => ['a@b.nl']],
                ['column' => 'job_title', 'fillRate' => 80.0,  'sqlType' => 'varchar', 'samples' => ['Dev']],
            ],
        ];
        $pageTableToEntryType = ['employee_pages' => 'teamMember'];

        [$rows, $folded] = AnalyzeController::emitPageWrapSyntheticColumns(
            $pageStructure,
            $entityIndex,
            $columnsByTable,
            $pageTableToEntryType,
        );

        self::assertCount(2, $rows, '`id` should be skipped; email + job_title remain.');

        $cols = array_column($rows, 'column');
        self::assertContains('_rel:employee.email', $cols);
        self::assertContains('_rel:employee.job_title', $cols);

        $emailRow = $rows[array_search('_rel:employee.email', $cols, true)];
        self::assertSame('employee_pages', $emailRow['table']);
        self::assertSame('teamMember', $emailRow['targetEntryType']);
        self::assertSame(95.0, $emailRow['fillRate']);
        self::assertSame('varchar', $emailRow['sqlType']);
        self::assertSame(['a@b.nl'], $emailRow['samples']);

        self::assertSame(['App\\Entity\\Employee'], $folded);
    }

    public function testTargetTaxonomyManyToOneIsSkippedByNameMismatch(): void
    {
        // CaseStudyPage→CaseStudyCategory: the page name strips to "CaseStudy"
        // but the target is "CaseStudyCategory". Name-mismatch ⇒ no synthetic
        // emit, no fold. Category entries continue to migrate as their own
        // taxonomy.
        $caseStudyPage = new DoctrineEntityInfo(
            fqcn: 'App\\Entity\\Pages\\CaseStudyPage',
            tableName: 'case_study_pages',
            columns: [],
            relations: [
                new DoctrineRelationInfo('ManyToOne', 'App\\Entity\\CaseStudyCategory', 'caseCategory', 'case_study_category_id'),
            ],
        );
        $caseStudyCategory = new DoctrineEntityInfo(
            fqcn: 'App\\Entity\\CaseStudyCategory',
            tableName: 'case_study_categories',
            columns: [
                new DoctrineColumnInfo('id', 'integer', false, 'id'),
                new DoctrineColumnInfo('name', 'string', false, 'name'),
            ],
            relations: [],
        );

        [$rows, $folded] = AnalyzeController::emitPageWrapSyntheticColumns(
            ['App\\Entity\\Pages\\CaseStudyPage' => ['tableName' => 'case_study_pages']],
            [
                'App\\Entity\\Pages\\CaseStudyPage' => $caseStudyPage,
                'App\\Entity\\CaseStudyCategory'    => $caseStudyCategory,
            ],
            ['case_study_categories' => [['column' => 'name', 'fillRate' => 100.0, 'sqlType' => 'varchar', 'samples' => []]]],
            ['case_study_pages' => 'casePage'],
        );

        self::assertSame([], $rows, 'CaseStudy → CaseStudyCategory must NOT trigger a name-match fold.');
        self::assertSame([], $folded);
    }

    public function testVendorTargetIsSkippedSilently(): void
    {
        // Page wraps a vendor / Kunstmaan-core entity the parser does not see.
        $page = new DoctrineEntityInfo(
            fqcn: 'App\\Entity\\Pages\\NodePage',
            tableName: 'node_pages',
            columns: [],
            relations: [
                new DoctrineRelationInfo('ManyToOne', 'Kunstmaan\\NodeBundle\\Entity\\Node', 'node', 'node_id'),
            ],
        );

        [$rows, $folded] = AnalyzeController::emitPageWrapSyntheticColumns(
            ['App\\Entity\\Pages\\NodePage' => ['tableName' => 'node_pages']],
            ['App\\Entity\\Pages\\NodePage' => $page], // vendor target absent from index
            [],
            [],
        );

        self::assertSame([], $rows);
        self::assertSame([], $folded);
    }

    public function testFkColumnsOnTargetAreOmittedFromSyntheticEmission(): void
    {
        // Target entity has its own ManyToOne — its FK column should NOT appear
        // as a synthetic _rel: row (structural plumbing, not content).
        $page = new DoctrineEntityInfo(
            fqcn: 'App\\Entity\\Pages\\WidgetPage',
            tableName: 'widget_pages',
            columns: [],
            relations: [new DoctrineRelationInfo('ManyToOne', 'App\\Entity\\Widget', 'widget', 'widget_id')],
        );
        $widget = new DoctrineEntityInfo(
            fqcn: 'App\\Entity\\Widget',
            tableName: 'widgets',
            columns: [
                new DoctrineColumnInfo('id', 'integer', false, 'id'),
                new DoctrineColumnInfo('label', 'string', false, 'label'),
                new DoctrineColumnInfo('owner_id', 'integer', true, 'owner'),
            ],
            relations: [
                new DoctrineRelationInfo('ManyToOne', 'App\\Entity\\Owner', 'owner', 'owner_id'),
            ],
        );

        [$rows, $folded] = AnalyzeController::emitPageWrapSyntheticColumns(
            ['App\\Entity\\Pages\\WidgetPage' => ['tableName' => 'widget_pages']],
            [
                'App\\Entity\\Pages\\WidgetPage' => $page,
                'App\\Entity\\Widget'            => $widget,
            ],
            [
                'widgets' => [
                    ['column' => 'id',       'fillRate' => 100.0, 'sqlType' => 'int',     'samples' => []],
                    ['column' => 'label',    'fillRate' => 100.0, 'sqlType' => 'varchar', 'samples' => []],
                    ['column' => 'owner_id', 'fillRate' => 80.0,  'sqlType' => 'int',     'samples' => []],
                ],
            ],
            ['widget_pages' => 'widget'],
        );

        $cols = array_column($rows, 'column');
        self::assertContains('_rel:widget.label', $cols);
        self::assertNotContains('_rel:widget.id', $cols, '`id` is always skipped.');
        self::assertNotContains('_rel:widget.owner_id', $cols, 'FK columns on the target are skipped — they are plumbing, not content.');
        self::assertSame(['App\\Entity\\Widget'], $folded);
    }

    public function testPageWithoutPageSuffixIsSkipped(): void
    {
        // A non-conventional page class (no `Page` suffix) does NOT trigger
        // F1's structural fold — operator hand-curates if the wrap is real.
        $unusual = new DoctrineEntityInfo(
            fqcn: 'App\\Entity\\Pages\\Article',
            tableName: 'articles',
            columns: [],
            relations: [new DoctrineRelationInfo('ManyToOne', 'App\\Entity\\Article', 'article', 'article_id')],
        );

        [$rows, $folded] = AnalyzeController::emitPageWrapSyntheticColumns(
            ['App\\Entity\\Pages\\Article' => ['tableName' => 'articles']],
            ['App\\Entity\\Pages\\Article' => $unusual],
            [],
            [],
        );

        self::assertSame([], $rows);
        self::assertSame([], $folded);
    }

    public function testNonManyToOneRelationsAreIgnored(): void
    {
        // OneToMany / ManyToMany / OneToOne do not trigger F1 — the spec is
        // narrowly scoped to ManyToOne 1:1 wrappers.
        $page = new DoctrineEntityInfo(
            fqcn: 'App\\Entity\\Pages\\GalleryPage',
            tableName: 'gallery_pages',
            columns: [],
            relations: [
                new DoctrineRelationInfo('OneToMany', 'App\\Entity\\Gallery', 'gallery', null),
                new DoctrineRelationInfo('ManyToMany', 'App\\Entity\\Gallery', 'galleries', null),
            ],
        );
        $gallery = new DoctrineEntityInfo(
            fqcn: 'App\\Entity\\Gallery',
            tableName: 'galleries',
            columns: [new DoctrineColumnInfo('caption', 'string', true, 'caption')],
            relations: [],
        );

        [$rows, $folded] = AnalyzeController::emitPageWrapSyntheticColumns(
            ['App\\Entity\\Pages\\GalleryPage' => ['tableName' => 'gallery_pages']],
            [
                'App\\Entity\\Pages\\GalleryPage' => $page,
                'App\\Entity\\Gallery'            => $gallery,
            ],
            ['galleries' => [['column' => 'caption', 'fillRate' => 50.0, 'sqlType' => 'varchar', 'samples' => []]]],
            ['gallery_pages' => 'galleryPage'],
        );

        self::assertSame([], $rows);
        self::assertSame([], $folded);
    }

    public function testEmptyInputsReturnEmptyOutputs(): void
    {
        [$rows, $folded] = AnalyzeController::emitPageWrapSyntheticColumns([], [], [], []);
        self::assertSame([], $rows);
        self::assertSame([], $folded);
    }
}
