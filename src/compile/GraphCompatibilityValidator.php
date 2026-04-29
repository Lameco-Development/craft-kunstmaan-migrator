<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\compile;

use lameco\kunstmaanmigrator\source\CraftGraphContract;
use lameco\kunstmaanmigrator\source\KunstmaanGraphContract;
use yii\base\Component;

final class GraphCompatibilityValidator extends Component
{
    /**
     * @param array<string, mixed> $mapping
     * @param array<string, mixed> $kunstmaanGraph
     * @param array<string, mixed> $craftGraph
     * @return list<array{severity: string, code: string, sourceRef: string, targetRef: string, message: string}>
     */
    public function validate(array $mapping, array $kunstmaanGraph, array $craftGraph): array
    {
        $rows = [];
        if (($kunstmaanGraph[KunstmaanGraphContract::KEY_GRAPH_VERSION] ?? null) !== KunstmaanGraphContract::GRAPH_VERSION) {
            $rows[] = $this->row('fatal', 'missing_graph_version', '', '', 'Kunstmaan graph is missing or has an unknown graphVersion.');
        }
        if (($craftGraph[CraftGraphContract::KEY_GRAPH_VERSION] ?? null) !== CraftGraphContract::GRAPH_VERSION) {
            $rows[] = $this->row('fatal', 'missing_graph_version', '', '', 'Craft graph is missing or has an unknown graphVersion.');
        }

        $sourceRefs = $this->knownSourceRefs($kunstmaanGraph);
        $targetRefs = $this->knownTargetRefs($craftGraph);
        $proposals = $this->proposalRows($mapping);

        foreach ($proposals as $proposal) {
            $sourceRef = (string) ($proposal['sourceRef'] ?? '');
            $targetRef = (string) ($proposal['targetRef'] ?? '');
            if ($sourceRef !== '' && !isset($sourceRefs[$sourceRef])) {
                $severity = $this->sourceRefLooksOutOfScope($sourceRef, $kunstmaanGraph) ? 'warning' : 'fatal';
                $rows[] = $this->row($severity, 'unknown_source_ref', $sourceRef, $targetRef, "Unknown sourceRef '{$sourceRef}'.");
            }
            if ($targetRef !== '' && !isset($targetRefs[$targetRef])) {
                $rows[] = $this->row('fatal', 'unknown_target_ref', $sourceRef, $targetRef, "Unknown targetRef '{$targetRef}'.");
            }

            $this->validateFieldOwnership($rows, $proposal, $craftGraph);
            $this->validateMatrixBlock($rows, $proposal, $craftGraph);
            $this->validateRelationTarget($rows, $proposal, $craftGraph);
            $this->validateAssetVolume($rows, $proposal, $craftGraph);
        }

        $rows = array_merge($rows, $this->validateRelationIntentCoverage($proposals, $kunstmaanGraph));

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed> $proposal
     * @param array<string, mixed> $craftGraph
     */
    private function validateFieldOwnership(array &$rows, array $proposal, array $craftGraph): void
    {
        $targetRef = (string) ($proposal['targetRef'] ?? '');
        $expectedEntryTypeRef = (string) ($proposal['targetEntryTypeRef'] ?? '');
        if ($targetRef === '' || $expectedEntryTypeRef === '') {
            return;
        }

        $field = $craftGraph[CraftGraphContract::KEY_FIELDS][$targetRef] ?? null;
        if (!is_array($field)) {
            return;
        }

        $actualEntryTypeRef = (string) ($field['entryTypeRef'] ?? '');
        if ($actualEntryTypeRef !== '' && $actualEntryTypeRef !== $expectedEntryTypeRef) {
            $rows[] = $this->row(
                'fatal',
                'field_not_owned_by_entry_type',
                (string) ($proposal['sourceRef'] ?? ''),
                $targetRef,
                "Target field '{$targetRef}' is owned by '{$actualEntryTypeRef}', not '{$expectedEntryTypeRef}'.",
            );
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed> $proposal
     * @param array<string, mixed> $craftGraph
     */
    private function validateMatrixBlock(array &$rows, array $proposal, array $craftGraph): void
    {
        $fieldRef = (string) ($proposal['targetMatrixFieldRef'] ?? $proposal['targetRef'] ?? '');
        $blockRef = (string) ($proposal['targetBlockRef'] ?? '');
        if ($fieldRef === '' || $blockRef === '') {
            return;
        }

        foreach ((array) ($craftGraph[CraftGraphContract::KEY_MATRIX_USAGES] ?? []) as $usage) {
            if (!is_array($usage)) {
                continue;
            }
            if (($usage['fieldRef'] ?? null) === $fieldRef && ($usage['blockRef'] ?? null) === $blockRef) {
                return;
            }
        }

        $rows[] = $this->row(
            'fatal',
            'matrix_block_not_allowed',
            (string) ($proposal['sourceRef'] ?? ''),
            $blockRef,
            "Matrix block '{$blockRef}' is not allowed by Matrix field '{$fieldRef}'.",
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed> $proposal
     * @param array<string, mixed> $craftGraph
     */
    private function validateRelationTarget(array &$rows, array $proposal, array $craftGraph): void
    {
        $fieldRef = (string) ($proposal['targetRef'] ?? '');
        $relationTargetRef = (string) ($proposal['relationTargetRef'] ?? '');
        if ($fieldRef === '' || $relationTargetRef === '') {
            return;
        }

        foreach ((array) ($craftGraph[CraftGraphContract::KEY_CONSTRAINTS] ?? []) as $constraint) {
            if (!is_array($constraint) || ($constraint['type'] ?? '') !== 'entriesTarget') {
                continue;
            }
            if (($constraint['fieldRef'] ?? null) === $fieldRef && ($constraint['targetRef'] ?? null) === $relationTargetRef) {
                return;
            }
        }

        $rows[] = $this->row(
            'fatal',
            'relation_target_incompatible',
            (string) ($proposal['sourceRef'] ?? ''),
            $relationTargetRef,
            "Relation target '{$relationTargetRef}' is not allowed by Entries field '{$fieldRef}'.",
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed> $proposal
     * @param array<string, mixed> $craftGraph
     */
    private function validateAssetVolume(array &$rows, array $proposal, array $craftGraph): void
    {
        $fieldRef = (string) ($proposal['targetRef'] ?? '');
        $volumeRef = (string) ($proposal['assetVolumeRef'] ?? '');
        if ($fieldRef === '' || $volumeRef === '') {
            return;
        }

        foreach ((array) ($craftGraph[CraftGraphContract::KEY_CONSTRAINTS] ?? []) as $constraint) {
            if (!is_array($constraint) || ($constraint['type'] ?? '') !== 'assetVolume') {
                continue;
            }
            if (($constraint['fieldRef'] ?? null) === $fieldRef && ($constraint['volumeRef'] ?? null) === $volumeRef) {
                return;
            }
        }

        $rows[] = $this->row(
            'fatal',
            'asset_volume_incompatible',
            (string) ($proposal['sourceRef'] ?? ''),
            $volumeRef,
            "Asset volume '{$volumeRef}' is not allowed by Assets field '{$fieldRef}'.",
        );
    }

    /**
     * @param list<array<string, mixed>> $proposals
     * @param array<string, mixed> $kunstmaanGraph
     * @return list<array{severity: string, code: string, sourceRef: string, targetRef: string, message: string}>
     */
    private function validateRelationIntentCoverage(array $proposals, array $kunstmaanGraph): array
    {
        $decided = [];
        foreach ($proposals as $proposal) {
            $status = (string) ($proposal['status'] ?? 'accepted');
            if (!in_array($status, ['accepted', 'dropped'], true)) {
                continue;
            }
            $sourceRef = (string) ($proposal['sourceRef'] ?? '');
            $intent = (string) ($proposal['relationIntent'] ?? '');
            if ($sourceRef !== '' && in_array($intent, $this->validRelationIntents(), true)) {
                $decided[$sourceRef] = true;
            }
        }

        $rows = [];
        foreach ((array) ($kunstmaanGraph[KunstmaanGraphContract::KEY_RELATIONS] ?? []) as $relationRef => $relation) {
            if (!is_string($relationRef) || !is_array($relation)) {
                continue;
            }
            if (!$this->relationHasValueEvidence($relationRef, $relation, $kunstmaanGraph)) {
                continue;
            }
            if (!isset($decided[$relationRef])) {
                $rows[] = $this->row(
                    'warning',
                    'relation_intent_required',
                    $relationRef,
                    (string) ($relation['targetRef'] ?? ''),
                    "Relation '{$relationRef}' has FK evidence and needs reference, promote, embed, drop, or out_of_scope.",
                );
            }
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $relation
     * @param array<string, mixed> $kunstmaanGraph
     */
    private function relationHasValueEvidence(string $relationRef, array $relation, array $kunstmaanGraph): bool
    {
        if (($relation['hasValueEvidence'] ?? false) === true) {
            return true;
        }

        $sourceRef = (string) ($relation['sourceRef'] ?? '');
        $fkColumn = (string) ($relation['fkColumn'] ?? '');
        if ($sourceRef === '' || $fkColumn === '') {
            return false;
        }

        $samples = $kunstmaanGraph[KunstmaanGraphContract::KEY_SAMPLES][$sourceRef . '.' . $fkColumn] ?? [];
        if (is_array($samples) && $samples !== []) {
            return true;
        }

        return $relationRef !== '' && ($relation['fkColumn'] ?? null) !== null;
    }

    /**
     * Scoped analyze runs intentionally produce a Kunstmaan graph for only the
     * requested root pages. Existing mapping rows for other page roots should
     * not hard-block compile in that case; typos inside the scoped graph should.
     *
     * @param array<string, mixed> $kunstmaanGraph
     */
    private function sourceRefLooksOutOfScope(string $sourceRef, array $kunstmaanGraph): bool
    {
        $rootOrEntityRef = $this->rootOrEntityRef($sourceRef);
        if ($rootOrEntityRef === '') {
            return false;
        }

        $knownRoots = (array) ($kunstmaanGraph[KunstmaanGraphContract::KEY_ROOTS] ?? []);
        $knownEntities = (array) ($kunstmaanGraph[KunstmaanGraphContract::KEY_ENTITIES] ?? []);

        return !isset($knownRoots[$rootOrEntityRef]) && !isset($knownEntities[$rootOrEntityRef]);
    }

    private function rootOrEntityRef(string $sourceRef): string
    {
        foreach (['kunstmaan.page:', 'kunstmaan.entity:'] as $prefix) {
            if (!str_starts_with($sourceRef, $prefix)) {
                continue;
            }
            $propertyPos = strpos($sourceRef, '.', strlen($prefix));
            return $propertyPos === false ? $sourceRef : substr($sourceRef, 0, $propertyPos);
        }

        return '';
    }

    /**
     * @param array<string, mixed> $mapping
     * @return list<array<string, mixed>>
     */
    private function proposalRows(array $mapping): array
    {
        $rows = [];
        foreach ((array) ($mapping['proposals'] ?? []) as $proposal) {
            if (is_array($proposal)) {
                $rows[] = $proposal;
            }
        }

        return $rows;
    }

    /** @return array<string, true> */
    private function knownSourceRefs(array $kunstmaanGraph): array
    {
        return $this->knownRefs($kunstmaanGraph, [
            KunstmaanGraphContract::KEY_ROOTS,
            KunstmaanGraphContract::KEY_ENTITIES,
            KunstmaanGraphContract::KEY_RELATIONS,
            KunstmaanGraphContract::KEY_PAGEPARTS,
            KunstmaanGraphContract::KEY_PAGEPART_USAGES,
            KunstmaanGraphContract::KEY_ASSETS,
            KunstmaanGraphContract::KEY_SAMPLES,
        ]);
    }

    /** @return array<string, true> */
    private function knownTargetRefs(array $craftGraph): array
    {
        return $this->knownRefs($craftGraph, [
            CraftGraphContract::KEY_ROOTS,
            CraftGraphContract::KEY_ENTRY_TYPES,
            CraftGraphContract::KEY_FIELDS,
            CraftGraphContract::KEY_MATRIX_BLOCK_TYPES,
            CraftGraphContract::KEY_MATRIX_USAGES,
            CraftGraphContract::KEY_RELATION_TARGETS,
            CraftGraphContract::KEY_ASSET_VOLUMES,
        ]);
    }

    /**
     * @param array<string, mixed> $graph
     * @param list<string> $registryKeys
     * @return array<string, true>
     */
    private function knownRefs(array $graph, array $registryKeys): array
    {
        $refs = [];
        foreach ($registryKeys as $registryKey) {
            $registry = $graph[$registryKey] ?? [];
            if (!is_array($registry)) {
                continue;
            }
            foreach (array_keys($registry) as $ref) {
                if (is_string($ref) && $ref !== '') {
                    $refs[$ref] = true;
                }
            }
        }

        return $refs;
    }

    /** @return list<string> */
    private function validRelationIntents(): array
    {
        return [
            KunstmaanGraphContract::INTENT_REFERENCE,
            KunstmaanGraphContract::INTENT_PROMOTE,
            KunstmaanGraphContract::INTENT_EMBED,
            KunstmaanGraphContract::INTENT_DROP,
            KunstmaanGraphContract::INTENT_OUT_OF_SCOPE,
        ];
    }

    /**
     * @return array{severity: string, code: string, sourceRef: string, targetRef: string, message: string}
     */
    private function row(string $severity, string $code, string $sourceRef, string $targetRef, string $message): array
    {
        return [
            'severity' => $severity,
            'code' => $code,
            'sourceRef' => $sourceRef,
            'targetRef' => $targetRef,
            'message' => $message,
        ];
    }
}
