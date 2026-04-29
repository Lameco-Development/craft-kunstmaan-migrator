<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\console;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Phase 8.7 / F1 — characterization test that locks the pipeline wiring of
 * `emitPageWrapSyntheticColumns` and the wrapped-entity force-drop loop into
 * AnalyzeController::actionIndex.
 *
 * The companion `AnalyzeControllerPageWrapFoldTest` exercises the static
 * helper in isolation — the helper could be perfect and the controller could
 * still ship a regression that simply forgets to call it. This test asserts
 * the source-level wiring so a silent refactor can't remove the inject point
 * or the force-drop loop without a failing test.
 *
 * Mirrors the existing `AnalyzeControllerNonPageEntityFilterTest` pattern:
 * the analyze pipeline runs inside actionIndex and cannot be invoked in a
 * pure unit test (Craft + legacy DB + AI). Source-shape characterization is
 * the project's accepted contract-locking technique for that orchestration
 * layer.
 */
final class AnalyzeControllerPageWrapFoldWiringTest extends TestCase
{
    public function testActionIndexCallsTheSyntheticEmitter(): void
    {
        $source = $this->workflowSource();

        self::assertStringContainsString(
            'self::emitPageWrapSyntheticColumns(',
            $source,
            'AnalyzeController::actionIndex must call emitPageWrapSyntheticColumns to inject the synthetic _rel: residual rows.',
        );
    }

    public function testActionIndexAppendsSyntheticRowsToResidual(): void
    {
        $source = $this->workflowSource();

        self::assertMatchesRegularExpression(
            '#\$residual\s*=\s*array_merge\(\s*\$residual\s*,\s*\$pageWrapSyntheticRows\s*\)#',
            $source,
            'Synthetic _rel: rows must be merged into the column-residual list before the column-LLM step consumes it.',
        );
    }

    public function testActionIndexForceDropsFoldedTaxonomies(): void
    {
        $source = $this->workflowSource();

        // The force-drop loop sets reason=superseded-by-page on taxonomy
        // proposals whose FQCN is in the folded set. Both the marker string
        // and the dropped status assignment must be present.
        self::assertStringContainsString(
            "'superseded-by-page'",
            $source,
            'F1 force-drop must mark folded taxonomies with reason: superseded-by-page so the rule traces back to feedback_pages_lead.md.',
        );
        self::assertMatchesRegularExpression(
            '#\$tp\[.status.\]\s*=\s*.dropped.#',
            $source,
            'F1 force-drop must set status: dropped on folded taxonomies (saved memory: feedback_pages_lead.md).',
        );
    }

    public function testF1MarkerCommentIsPresentForGreppability(): void
    {
        $source = $this->workflowSource();

        // Require the explicit decision marker so the wiring is greppable
        // alongside the helper definition.
        self::assertGreaterThanOrEqual(
            2,
            substr_count($source, 'Phase 8.7 / F1'),
            'Two or more `Phase 8.7 / F1` markers expected (helper + at least one wiring callsite).',
        );
    }

    private function workflowSource(): string
    {
        $workflow = new ReflectionClass(\lameco\kunstmaanmigrator\workflow\AnalyzeWorkflow::class);
        $file = (string) $workflow->getFileName();
        self::assertNotSame('', $file, 'AnalyzeWorkflow source path must resolve.');
        $source = (string) file_get_contents($file);
        self::assertNotSame('', $source, 'AnalyzeWorkflow source must be readable.');
        return $source;
    }
}
