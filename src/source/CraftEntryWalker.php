<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\source;

use yii\base\Component;

final class CraftEntryWalker extends Component
{
    public ?CraftKnowledgeBase $craftKnowledgeBase = null;

    public int $maxDepth = 4;

    /** @var array<string, mixed>|null */
    public ?array $targetSchemaSnapshot = null;

    /**
     * @param list<string> $candidateEntryTypeHandles
     * @return array<string, mixed>
     */
    public function walk(array $candidateEntryTypeHandles = [], ?int $maxDepth = null): array
    {
        $depthLimit = max(0, $maxDepth ?? $this->maxDepth);
        $schema = $this->targetSchema();
        $entryTypes = isset($schema['entryTypes']) && is_array($schema['entryTypes']) ? $schema['entryTypes'] : [];
        $sections = isset($schema['sections']) && is_array($schema['sections']) ? $schema['sections'] : [];
        $volumes = isset($schema['volumes']) && is_array($schema['volumes']) ? $schema['volumes'] : [];
        $matrixCatalog = isset($schema['matrixFields']) && is_array($schema['matrixFields']) ? $schema['matrixFields'] : [];

        $graph = $this->emptyGraph();
        $rootHandles = $candidateEntryTypeHandles !== []
            ? array_values(array_unique(array_filter($candidateEntryTypeHandles, static fn($handle): bool => is_string($handle) && $handle !== '')))
            : array_values(array_filter(array_keys($entryTypes), static fn($handle): bool => is_string($handle) && $handle !== ''));
        sort($rootHandles);

        $seenRefs = [];
        foreach ($rootHandles as $handle) {
            if (!isset($entryTypes[$handle])) {
                continue;
            }

            $entryTypeRef = CraftGraphContract::craftEntryTypeRef($handle);
            $graph[CraftGraphContract::KEY_ROOTS][$entryTypeRef] = [
                'handle' => $handle,
                'sections' => $this->sectionsForEntryType($handle, $sections),
            ];
            $this->walkEntryType($graph, $handle, $entryTypes[$handle], $matrixCatalog, $volumes, $seenRefs, 0, $depthLimit);
        }

        $this->sortGraph($graph);

        return $graph;
    }

    /** @return array<string, mixed> */
    private function emptyGraph(): array
    {
        return [
            CraftGraphContract::KEY_GRAPH_VERSION => CraftGraphContract::GRAPH_VERSION,
            CraftGraphContract::KEY_ROOTS => [],
            CraftGraphContract::KEY_ENTRY_TYPES => [],
            CraftGraphContract::KEY_FIELDS => [], // fields registry
            CraftGraphContract::KEY_MATRIX_BLOCK_TYPES => [], // matrixBlockTypes registry
            CraftGraphContract::KEY_MATRIX_USAGES => [], // matrixUsages registry
            CraftGraphContract::KEY_RELATION_TARGETS => [], // relationTargets registry
            CraftGraphContract::KEY_ASSET_VOLUMES => [], // assetVolumes registry
            CraftGraphContract::KEY_CONSTRAINTS => [], // constraints registry
        ];
    }

    /** @return array<string, mixed> */
    private function targetSchema(): array
    {
        if ($this->targetSchemaSnapshot !== null) {
            return $this->targetSchemaSnapshot;
        }

        return $this->craftKnowledgeBase?->dumpTargetSchema() ?? [];
    }

    /**
     * @param array<string, mixed> $sections
     * @return list<string>
     */
    private function sectionsForEntryType(string $entryTypeHandle, array $sections): array
    {
        $out = [];
        foreach ($sections as $sectionHandle => $entryTypeHandles) {
            if (!is_string($sectionHandle) || !is_array($entryTypeHandles)) {
                continue;
            }
            if (in_array($entryTypeHandle, $entryTypeHandles, true)) {
                $out[] = $sectionHandle;
            }
        }
        sort($out);

        return $out;
    }

    /**
     * @param array<string, mixed> $graph
     * @param mixed $fieldRows
     * @param array<string, mixed> $matrixCatalog
     * @param list<mixed> $volumes
     * @param array<string, true> $seenRefs
     */
    private function walkEntryType(
        array &$graph,
        string $handle,
        mixed $fieldRows,
        array $matrixCatalog,
        array $volumes,
        array &$seenRefs,
        int $depth,
        int $depthLimit,
    ): void {
        $entryTypeRef = CraftGraphContract::craftEntryTypeRef($handle);
        if (isset($seenRefs[$entryTypeRef]) || $depth > $depthLimit) {
            return;
        }
        $seenRefs[$entryTypeRef] = true;

        $fields = is_array($fieldRows) ? array_values($fieldRows) : [];
        $fieldRefs = [
            CraftGraphContract::craftFieldRef($handle, 'title'),
            CraftGraphContract::craftFieldRef($handle, 'slug'),
        ];

        $graph[CraftGraphContract::KEY_FIELDS][$fieldRefs[0]] = [
            'entryTypeRef' => $entryTypeRef,
            'handle' => 'title',
            'type' => 'Native',
        ];
        $graph[CraftGraphContract::KEY_FIELDS][$fieldRefs[1]] = [
            'entryTypeRef' => $entryTypeRef,
            'handle' => 'slug',
            'type' => 'Native',
        ];
        $graph[CraftGraphContract::KEY_CONSTRAINTS][$entryTypeRef . '.native.required'] = [
            'entryTypeRef' => $entryTypeRef,
            'requiredFields' => ['title', 'slug'],
        ];

        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }
            $fieldHandle = isset($field['handle']) && is_string($field['handle']) ? $field['handle'] : '';
            if ($fieldHandle === '') {
                continue;
            }

            if (str_contains($fieldHandle, '.')) {
                $this->addMatrixSubField($graph, $handle, $field, $matrixCatalog);
                continue;
            }

            $fieldRef = CraftGraphContract::craftFieldRef($handle, $fieldHandle);
            if (!isset($seenRefs[$fieldRef])) {
                $seenRefs[$fieldRef] = true;
                $fieldRefs[] = $fieldRef;
                $graph[CraftGraphContract::KEY_FIELDS][$fieldRef] = [
                    'entryTypeRef' => $entryTypeRef,
                    'handle' => $fieldHandle,
                    'type' => isset($field['type']) && is_string($field['type']) ? $field['type'] : 'Unknown',
                    'classification' => isset($field['classification']) && is_string($field['classification'])
                        ? $field['classification']
                        : null,
                ];
            }

            $type = isset($field['type']) && is_string($field['type']) ? $field['type'] : '';
            if ($type === 'Matrix') {
                $this->addMatrixField($graph, $handle, $fieldHandle, $field, $matrixCatalog, $seenRefs, $depth, $depthLimit);
            } elseif ($type === 'Entries') {
                $this->addEntriesField($graph, $fieldRef, $field);
            } elseif ($type === 'Assets') {
                $this->addAssetsField($graph, $fieldRef, $field, $volumes);
            }
        }

        $fieldRefs = array_values(array_unique($fieldRefs));
        sort($fieldRefs);
        $graph[CraftGraphContract::KEY_ENTRY_TYPES][$entryTypeRef] = [
            'handle' => $handle,
            'nativeFields' => ['title', 'slug'],
            'fieldRefs' => $fieldRefs,
        ];
    }

    /**
     * @param array<string, mixed> $graph
     * @param array<string, mixed> $field
     * @param array<string, mixed> $matrixCatalog
     * @param array<string, true> $seenRefs
     */
    private function addMatrixField(
        array &$graph,
        string $entryTypeHandle,
        string $fieldHandle,
        array $field,
        array $matrixCatalog,
        array &$seenRefs,
        int $depth,
        int $depthLimit,
    ): void {
        if ($depth >= $depthLimit) {
            return;
        }

        $allowedBlockTypes = $this->strings($field['allowedBlockTypes'] ?? $matrixCatalog[$fieldHandle] ?? []);
        foreach ($allowedBlockTypes as $blockEntryTypeHandle) {
            $blockRef = CraftGraphContract::matrixBlockRef($fieldHandle, $blockEntryTypeHandle);
            if (!isset($seenRefs[$blockRef])) {
                $seenRefs[$blockRef] = true;
                $graph[CraftGraphContract::KEY_MATRIX_BLOCK_TYPES][$blockRef] = [ // matrixBlockTypes definition
                    'matrixField' => $fieldHandle,
                    'entryType' => $blockEntryTypeHandle,
                    'fieldRefs' => [],
                ];
            }

            $matrixFieldRef = CraftGraphContract::craftFieldRef($entryTypeHandle, $fieldHandle);
            $usageRef = CraftGraphContract::craftEntryTypeRef($entryTypeHandle) . '.' . $fieldHandle . '.' . $blockEntryTypeHandle;
            $graph[CraftGraphContract::KEY_MATRIX_USAGES][$usageRef] = [ // matrixUsages ownership edge
                'entryTypeRef' => CraftGraphContract::craftEntryTypeRef($entryTypeHandle),
                'fieldRef' => $matrixFieldRef,
                'blockRef' => $blockRef,
            ];
            $graph[CraftGraphContract::KEY_CONSTRAINTS][$usageRef . '.ownership'] = [ // constraints: Matrix ownership
                'type' => 'matrixBlockOwnership',
                'entryTypeRef' => CraftGraphContract::craftEntryTypeRef($entryTypeHandle),
                'fieldRef' => $matrixFieldRef,
                'allowedBlockRef' => $blockRef,
            ];
        }
    }

    /**
     * @param array<string, mixed> $graph
     * @param array<string, mixed> $field
     * @param array<string, mixed> $matrixCatalog
     */
    private function addMatrixSubField(array &$graph, string $entryTypeHandle, array $field, array $matrixCatalog): void
    {
        $handle = isset($field['handle']) && is_string($field['handle']) ? $field['handle'] : '';
        [$matrixFieldHandle, $subFieldHandle] = explode('.', $handle, 2);
        $blockTypes = $this->strings($field['blockTypes'] ?? $field['allowedBlockTypes'] ?? $matrixCatalog[$matrixFieldHandle] ?? []);
        if ($blockTypes === []) {
            $blockTypes = ['*'];
        }

        foreach ($blockTypes as $blockEntryTypeHandle) {
            $blockRef = CraftGraphContract::matrixBlockRef($matrixFieldHandle, $blockEntryTypeHandle);
            $fieldRef = CraftGraphContract::craftFieldRef($blockEntryTypeHandle, $subFieldHandle);
            $graph[CraftGraphContract::KEY_FIELDS][$fieldRef] = [
                'entryTypeRef' => CraftGraphContract::craftEntryTypeRef($blockEntryTypeHandle),
                'handle' => $subFieldHandle,
                'type' => isset($field['type']) && is_string($field['type']) ? $field['type'] : 'Unknown',
                'matrixParentField' => $matrixFieldHandle,
            ];
            $existing = $graph[CraftGraphContract::KEY_MATRIX_BLOCK_TYPES][$blockRef] ?? [
                'matrixField' => $matrixFieldHandle,
                'entryType' => $blockEntryTypeHandle,
                'fieldRefs' => [],
            ];
            if (is_array($existing)) {
                $existing['fieldRefs'] = array_values(array_unique(array_merge(
                    isset($existing['fieldRefs']) && is_array($existing['fieldRefs']) ? $existing['fieldRefs'] : [],
                    [$fieldRef],
                )));
                $graph[CraftGraphContract::KEY_MATRIX_BLOCK_TYPES][$blockRef] = $existing;
            }
        }
    }

    /** @param array<string, mixed> $field */
    private function addEntriesField(array &$graph, string $fieldRef, array $field): void
    {
        $sources = $this->strings($field['sources'] ?? []);
        foreach ($sources as $source) {
            $targetRef = str_starts_with($source, 'entryType:')
                ? CraftGraphContract::craftEntryTypeRef(substr($source, strlen('entryType:')))
                : 'craft.relationTarget:' . $source;
            $graph[CraftGraphContract::KEY_RELATION_TARGETS][$targetRef] = [
                'fieldRef' => $fieldRef,
                'source' => $source,
            ];
            $graph[CraftGraphContract::KEY_CONSTRAINTS][$fieldRef . '.relationTarget.' . $source] = [
                'type' => 'entriesTarget',
                'fieldRef' => $fieldRef,
                'targetRef' => $targetRef,
            ];
        }
    }

    /**
     * @param array<string, mixed> $field
     * @param list<mixed> $volumes
     */
    private function addAssetsField(array &$graph, string $fieldRef, array $field, array $volumes): void
    {
        $allowedKinds = $this->strings($field['allowedKinds'] ?? []);
        $volumeHandles = $this->strings($field['volumes'] ?? $field['sources'] ?? $volumes);
        foreach ($volumeHandles as $volumeHandle) {
            $volumeRef = 'craft.assetVolume:' . $volumeHandle;
            $graph[CraftGraphContract::KEY_ASSET_VOLUMES][$volumeRef] = [
                'handle' => $volumeHandle,
            ];
            $graph[CraftGraphContract::KEY_CONSTRAINTS][$fieldRef . '.assetVolume.' . $volumeHandle] = [
                'type' => 'assetVolume',
                'fieldRef' => $fieldRef,
                'volumeRef' => $volumeRef,
                'allowedKinds' => $allowedKinds,
            ];
        }
    }

    /** @return list<string> */
    private function strings(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $strings = array_values(array_filter($value, static fn($item): bool => is_string($item) && $item !== ''));
        sort($strings);

        return array_values(array_unique($strings));
    }

    /** @param array<string, mixed> $graph */
    private function sortGraph(array &$graph): void
    {
        foreach ([
            CraftGraphContract::KEY_ROOTS,
            CraftGraphContract::KEY_ENTRY_TYPES,
            CraftGraphContract::KEY_FIELDS,
            CraftGraphContract::KEY_MATRIX_BLOCK_TYPES,
            CraftGraphContract::KEY_MATRIX_USAGES,
            CraftGraphContract::KEY_RELATION_TARGETS,
            CraftGraphContract::KEY_ASSET_VOLUMES,
            CraftGraphContract::KEY_CONSTRAINTS, // constraints sorted by ref
        ] as $key) {
            if (isset($graph[$key]) && is_array($graph[$key])) {
                ksort($graph[$key]);
            }
        }
    }
}
