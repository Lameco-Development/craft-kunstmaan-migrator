<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\mapping;

use yii\base\Component;

/**
 * BlockAvailabilityValidator — verbatim port from v1's bridge/mapping/BlockAvailabilityValidator.php
 * (namespace flatten only: bridge\mapping → mapping). Phase 02.1 / D-36.
 *
 * Validates that every `pageParts[*].target` block handle in the mapping is actually
 * available inside the matrix-availability index for at least one nodeClass that uses
 * the pagePart. Pure validation logic — no DB / no I/O / no Craft service touches.
 *
 * Lives under `src/mapping/` because the responsibility is mapping validation, not
 * proposal generation. Returns `string[]` errors and never throws — same contract as
 * MappingAuditor's other finding emitters. The caller (MappingAuditor::audit())
 * decides whether to escalate to a non-zero exit (warn-by-default; --audit-strict
 * elevates per Phase 2 / D-16).
 *
 * v1↔v2 surface impedance (PATTERNS section 12): v1 walks `mapping['pageParts']` and
 * `mapping['nodeClasses']`. v2 mapping.yaml is a flat `proposals:` list with `kind: pagePart`
 * rows. The signature is preserved verbatim so reconciliation (Plan 09) can audit the
 * v1 → v2 ports rule-by-rule. The MappingAuditor adapter builds the v1-shaped `$mapping`
 * argument by walking v2's flat `proposals[]`.
 */
final class BlockAvailabilityValidator extends Component
{
    /**
     * Validate pagePart.target block handles against the matrix
     * availability index. Returns list of validation errors.
     *
     * Cross-references pagePart FQCN → nodeClass FQCNs via
     * $pagePartToNodeClasses (built from kuma_page_part_refs by the
     * caller). When $pagePartToNodeClasses is empty the validation runs
     * against ALL nodeClasses (broader, more false-positives).
     *
     * @param array<string, mixed>                       $mapping              Raw mapping.yaml
     * @param array<string, array<string, list<string>>> $matrixIndex          From KunstmaanKnowledgeBase::buildMatrixAvailabilityIndex()
     * @param array<string, list<string>>                $pagePartToNodeClasses pagePart FQCN → list of nodeClass FQCNs (optional)
     * @return list<string> Validation errors (empty = all OK)
     */
    public function validate(
        array $mapping,
        array $matrixIndex,
        array $pagePartToNodeClasses = [],
    ): array {
        $errors = [];

        $pageParts   = (array) ($mapping['pageParts'] ?? []);
        $nodeClasses = (array) ($mapping['nodeClasses'] ?? []);
        $sections    = (array) ($mapping['sections'] ?? []);

        // Build: nodeClass FQCN → (entryType, pageBuilderHandle) pairs.
        /** @var array<string, array{entryType: string, matrixHandle: string}> $ncToMatrix */
        $ncToMatrix = [];
        foreach ($nodeClasses as $fqcn => $spec) {
            if (!is_array($spec) || ($spec['action'] ?? null) === 'SKIP') {
                continue;
            }
            $sectionKey       = (string) ($spec['section'] ?? '');
            $sectionSpec      = $sections[$sectionKey] ?? null;
            $entryType        = is_array($sectionSpec) ? (string) ($sectionSpec['entryType'] ?? '') : '';
            $pageBuilderHandle = (string) ($spec['pageBuilderHandle'] ?? '');
            if ($entryType === '' || $pageBuilderHandle === '') {
                continue;
            }
            $ncToMatrix[(string) $fqcn] = ['entryType' => $entryType, 'matrixHandle' => $pageBuilderHandle];
        }

        foreach ($pageParts as $ppFqcn => $ppSpec) {
            if (!is_array($ppSpec) || ($ppSpec['action'] ?? null) === 'SKIP') {
                continue;
            }
            $targetBlock = (string) ($ppSpec['target'] ?? '');
            if ($targetBlock === '') {
                continue;
            }

            // Determine which nodeClasses to check against.
            $relevantNcs = $pagePartToNodeClasses[(string) $ppFqcn] ?? [];
            if ($relevantNcs === []) {
                if ($pagePartToNodeClasses !== []) {
                    // Cross-reference was populated from DB but this pagePart has no rows —
                    // it is not used in any page (e.g. handled via headerBlock config, not
                    // as a regular page-builder page part). Skip to avoid false positives.
                    continue;
                }
                // DB unavailable — cross-reference was never populated, fall back to all.
                $relevantNcs = array_keys($ncToMatrix);
            }

            foreach ($relevantNcs as $ncFqcn) {
                $pair = $ncToMatrix[$ncFqcn] ?? null;
                if ($pair === null) {
                    continue; // nodeClass has no pageBuilderHandle, skip
                }
                $entryType   = $pair['entryType'];
                $matrixHandle = $pair['matrixHandle'];

                $allowedBlocks = $matrixIndex[$entryType][$matrixHandle] ?? null;
                if ($allowedBlocks === null) {
                    continue; // matrix not in index (entry type not scoped)
                }

                if (!in_array($targetBlock, $allowedBlocks, true)) {
                    $errors[] = sprintf(
                        "WARNING: block '%s' (target for pagePart '%s') not found in %s.%s (nodeClass: %s) — verify availability",
                        $targetBlock,
                        $ppFqcn,
                        $entryType,
                        $matrixHandle,
                        $ncFqcn,
                    );
                }
            }
        }

        return $errors;
    }
}
