<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\console;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Phase 8.1 / D-05a — characterization test for the PagePart exclusion in
 * AnalyzeController step 7.7's non-page-entity index build.
 *
 * Why source-shape characterisation: step 7.7 runs inside actionIndex(),
 * which orchestrates the full analyze pipeline (legacy DB scan, schema
 * dump, AI calls, mapping IO). It cannot be invoked in a pure unit test
 * without a Craft + DB harness. The 8.1 contract is therefore locked
 * structurally:
 *   - the index-builder body must contain the namespace-based PagePart
 *     skip (preg_match against `\PageParts\` segment) AND the suffix-based
 *     skip (str_ends_with on `PagePart`) — either match disqualifies the
 *     FQCN from the proposeNonPageEntities input.
 *
 * Reasoning: pageparts globally map to Matrix block types (handled by
 * step 7.6 / proposePagePartBlocks). Letting them flow into the
 * non-page-entity proposer produced status:accepted rows with empty
 * targetSection — the exact failure that crashed TaxonomyMigrationService
 * during the Phase 8 rehearsal run.
 */
final class AnalyzeControllerNonPageEntityFilterTest extends TestCase
{
    public function testStep77BuilderExcludesPagePartsByNamespaceSegment(): void
    {
        $body = $this->step77BodySource();

        // The source contains: preg_match('#\\\\PageParts?\\\\#', $fqcn)
        // (two literal backslashes either side of `PageParts?` after PHP
        // string escaping → matches the `\PageParts\` segment in any FQCN).
        $this->assertStringContainsString(
            "preg_match('#\\\\\\\\PageParts?\\\\\\\\#', \$fqcn)",
            $body,
            'Step 7.7 must skip FQCNs that contain a `\\PageParts\\` namespace segment.',
        );
    }

    public function testStep77BuilderExcludesPagePartsBySuffix(): void
    {
        $body = $this->step77BodySource();

        $this->assertMatchesRegularExpression(
            '#str_ends_with\(\s*\$fqcn\s*,\s*[\'"]PagePart[\'"]\s*\)#',
            $body,
            'Step 7.7 must skip FQCNs whose class-name suffix is `PagePart`.',
        );
    }

    public function testStep77BuilderHasD05aRationaleComment(): void
    {
        $body = $this->step77BodySource();

        $this->assertStringContainsString(
            'D-05a',
            $body,
            'Step 7.7 must reference the D-05a decision marker so the exclusion is greppable.',
        );
    }

    private function step77BodySource(): string
    {
        $workflow = new ReflectionClass(\lameco\kunstmaanmigrator\workflow\AnalyzeWorkflow::class);
        $file = (string) $workflow->getFileName();
        $this->assertNotSame('', $file, 'AnalyzeWorkflow source path must resolve.');
        $source = (string) file_get_contents($file);
        $this->assertNotSame('', $source, 'AnalyzeWorkflow source must be readable.');

        // Slice the file from `$nonPageEntityIndex = []` to the closing `}` of
        // the foreach. Bounding window keeps the assertions targeted to the
        // step 7.7 builder rather than letting accidental matches elsewhere
        // satisfy them.
        $needle = '$nonPageEntityIndex = [];';
        $start = strpos($source, $needle);
        $this->assertNotFalse($start, 'Step 7.7 builder anchor `$nonPageEntityIndex = []` not found.');

        // 800-char window is generous — the foreach is ~30 lines.
        return substr($source, $start, 1500);
    }
}
