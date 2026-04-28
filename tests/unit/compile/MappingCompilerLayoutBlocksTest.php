<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\compile;

use lameco\kunstmaanmigrator\compile\MappingCompiler;
use PHPUnit\Framework\TestCase;

/**
 * Phase 8 / D-12 — page-builder layout-block compile pass coverage.
 *
 * Verifies that accepted `kind=nodeClass` partial-update rows (carrying
 * `headerBlock` / `bodyWrapBlock` / `bodyColumn`) are folded into the
 * existing `nodeClasses[fqcn]` entry; that per-slot skip-existing
 * preserves a previously-set value (operator-override semantics extended
 * to slot granularity); and that `status=needs-review` rows are not
 * folded.
 */
final class MappingCompilerLayoutBlocksTest extends TestCase
{
    private MappingCompiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new MappingCompiler();
    }

    public function testLayoutBlockProposalFoldedIntoNodeClass(): void
    {
        $fqcn = 'App\\Entity\\Pages\\NewsPage';
        $mapping = [
            'proposals' => [
                // Column row makes the FQCN reachable in nodeClasses[].
                $this->columnRow('news_pages', 'title', 'newsPage', 'title'),
                // Layout proposal — folds headerBlock into nodeClasses[fqcn].
                $this->layoutRow($fqcn, headerBlock: 'heroBanner'),
            ],
        ];
        $pageStructure = [$fqcn => ['tableName' => 'news_pages']];

        $compiled = $this->compiler->compile($mapping, $pageStructure, ['nl' => 'default']);

        $this->assertSame('heroBanner', $compiled['nodeClasses'][$fqcn]['headerBlock']);
        $this->assertSame(1, (int) $compiled['_compileReport']['layoutBlocksEmitted']);
    }

    public function testSlotSkipExistingPreservesOperatorOverride(): void
    {
        // Two layout proposals on the same FQCN. The first fills headerBlock
        // (simulating an earlier-accepted row or operator hand-edit on the
        // proposal). The second proposes a different headerBlock, which the
        // per-slot skip-existing semantics MUST reject — operator decisions
        // sacred at slot granularity, not just row granularity.
        $fqcn = 'App\\Entity\\Pages\\NewsPage';
        $mapping = [
            'proposals' => [
                $this->columnRow('news_pages', 'title', 'newsPage', 'title'),
                $this->layoutRow($fqcn, headerBlock: 'OPERATOR_OVERRIDE'),
                $this->layoutRow($fqcn, headerBlock: 'heroBanner'),
            ],
        ];
        $pageStructure = [$fqcn => ['tableName' => 'news_pages']];

        $compiled = $this->compiler->compile($mapping, $pageStructure, ['nl' => 'default']);

        // Per-slot skip-existing: the first-row value held; the second row
        // proposed a different headerBlock but was rejected slot-by-slot.
        $this->assertSame('OPERATOR_OVERRIDE', $compiled['nodeClasses'][$fqcn]['headerBlock']);
        // Counter is 1 (first row folded); the second row touched no slot,
        // so it never incremented the counter.
        $this->assertSame(1, (int) $compiled['_compileReport']['layoutBlocksEmitted']);
    }

    public function testNeedsReviewLayoutRowIsNotFolded(): void
    {
        $fqcn = 'App\\Entity\\Pages\\NewsPage';
        $mapping = [
            'proposals' => [
                $this->columnRow('news_pages', 'title', 'newsPage', 'title'),
                $this->layoutRow($fqcn, headerBlock: 'heroBanner', status: 'needs-review'),
            ],
        ];
        $pageStructure = [$fqcn => ['tableName' => 'news_pages']];

        $compiled = $this->compiler->compile($mapping, $pageStructure, ['nl' => 'default']);

        // nodeClasses[fqcn].headerBlock retains the empty stub the compile()
        // pre-fill set on initial construction (null per the empty-stub block).
        $this->assertNull($compiled['nodeClasses'][$fqcn]['headerBlock']);
        $this->assertSame(0, (int) $compiled['_compileReport']['layoutBlocksEmitted']);
    }

    /** @return array<string, mixed> */
    private function columnRow(
        string $table,
        string $column,
        string $entryType,
        string $targetHandle,
    ): array {
        return [
            'kind'            => 'column',
            'table'           => $table,
            'column'          => $column,
            'targetEntryType' => $entryType,
            'targetHandle'    => $targetHandle,
            'handler'         => 'plain',
            'status'          => 'accepted',
        ];
    }

    /** @return array<string, mixed> */
    private function layoutRow(
        string $fqcn,
        ?string $headerBlock = null,
        ?string $bodyWrapBlock = null,
        ?string $bodyColumn = null,
        string $status = 'accepted',
    ): array {
        $row = [
            'kind'       => 'nodeClass',
            'fqcn'       => $fqcn,
            'confidence' => 'high',
            'rationale'  => 'test',
            'status'     => $status,
        ];
        if ($headerBlock !== null) {
            $row['headerBlock'] = $headerBlock;
        }
        if ($bodyWrapBlock !== null) {
            $row['bodyWrapBlock'] = $bodyWrapBlock;
        }
        if ($bodyColumn !== null) {
            $row['bodyColumn'] = $bodyColumn;
        }
        return $row;
    }
}
