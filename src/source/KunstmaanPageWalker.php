<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\source;

use lameco\kunstmaanmigrator\analyze\KunstmaanSchemaDumper;
use lameco\kunstmaanmigrator\filter\MigrationFilters;
use yii\base\Component;

final class KunstmaanPageWalker extends Component
{
    public ?KunstmaanSourcePathResolver $pathResolver = null;
    public ?DoctrineEntityParser $entityParser = null;
    public ?KunstmaanSourceScanner $sourceScanner = null;
    public ?KunstmaanPageStructureScanner $pageStructureScanner = null;
    public ?KunstmaanSchemaDumper $kunstmaanSchemaDumper = null;

    public int $maxDepth = 4;

    /** @var array<string, mixed>|null */
    public ?array $sourceScanSnapshot = null;

    /** @var array<string, mixed>|null */
    public ?array $pageStructureSnapshot = null;

    /** @var array<string, mixed>|null */
    public ?array $sourceSchemaSnapshot = null;

    /**
     * @param list<string> $rootShortNames
     * @return array<string, mixed>
     */
    public function walk(array $rootShortNames = [], ?int $maxDepth = null): array
    {
        $depthLimit = max(0, $maxDepth ?? $this->maxDepth);
        $graph = $this->emptyGraph();
        $sourceScan = $this->sourceScan();
        $entities = $this->entityIndex($sourceScan);
        if ($entities === []) {
            return $graph;
        }

        $sourceSchema = $this->sourceSchema($rootShortNames, $sourceScan, $entities);
        $pageStructure = $this->pageStructure();
        $rootSet = $this->rootSet($rootShortNames, $entities);
        $visited = [];

        foreach ($entities as $fqcn => $entity) {
            if (!$entity instanceof DoctrineEntityInfo || !$this->isRoot($fqcn, $rootSet)) {
                continue;
            }

            $rootRef = KunstmaanGraphContract::pageRootRef($fqcn);
            $graph[KunstmaanGraphContract::KEY_ROOTS][$rootRef] = [
                'fqcn' => $fqcn,
                'entityRef' => $rootRef,
                'table' => $entity->tableName,
            ];

            $this->addEntity($graph, $entity, $rootRef, $sourceSchema);
            $this->walkEntityRelations($graph, $entity, $entities, $sourceSchema, $visited, 0, $depthLimit);
            $this->addPageparts($graph, $fqcn, $rootRef, $pageStructure, $entities, $sourceSchema, $visited, $depthLimit);
        }

        $this->addAssets($graph, $sourceScan);
        $this->sortGraph($graph);

        return $graph;
    }

    /** @return array<string, mixed> */
    private function emptyGraph(): array
    {
        return [
            KunstmaanGraphContract::KEY_GRAPH_VERSION => KunstmaanGraphContract::GRAPH_VERSION,
            KunstmaanGraphContract::KEY_ROOTS => [],
            KunstmaanGraphContract::KEY_ENTITIES => [],
            KunstmaanGraphContract::KEY_RELATIONS => [], // relation intents include out_of_scope
            KunstmaanGraphContract::KEY_PAGEPARTS => [], // pageparts registry
            KunstmaanGraphContract::KEY_PAGEPART_USAGES => [], // pagepartUsages registry
            KunstmaanGraphContract::KEY_ASSETS => [], // assets registry
            KunstmaanGraphContract::KEY_TABLES => [],
            KunstmaanGraphContract::KEY_SAMPLES => [],
            KunstmaanGraphContract::KEY_CONSTRAINTS => [],
        ];
    }

    /** @return array<string, mixed> */
    private function sourceScan(): array
    {
        if ($this->sourceScanSnapshot !== null) {
            return $this->sourceScanSnapshot;
        }

        if ($this->sourceScanner !== null) {
            return $this->sourceScanner->scan();
        }

        $path = $this->pathResolver?->resolve();
        if ($path !== null && $this->entityParser !== null) {
            $this->entityParser->sourceCheckoutPath = $path;
        }

        return [
            'tables' => [],
            'entities' => $this->entityParser?->getAll() ?? [],
            'm2mJoins' => [],
            'bodyCols' => [],
            'mediaFks' => [],
            'drift' => ['dbHasButScanMissing' => [], 'scanHasButDbMissing' => []],
        ];
    }

    /**
     * @param array<string, mixed> $sourceScan
     * @return array<string, DoctrineEntityInfo>
     */
    private function entityIndex(array $sourceScan): array
    {
        $entities = $sourceScan['entities'] ?? [];
        if (!is_array($entities)) {
            return [];
        }

        $out = [];
        foreach ($entities as $fqcn => $entity) {
            if (is_string($fqcn) && $entity instanceof DoctrineEntityInfo) {
                $out[$fqcn] = $entity;
            }
        }
        ksort($out);

        return $out;
    }

    /**
     * @param list<string> $rootShortNames
     * @param array<string, mixed> $sourceScan
     * @param array<string, DoctrineEntityInfo> $entities
     * @return array<string, mixed>
     */
    private function sourceSchema(array $rootShortNames, array $sourceScan, array $entities): array
    {
        if ($this->sourceSchemaSnapshot !== null) {
            return $this->sourceSchemaSnapshot;
        }

        if ($this->kunstmaanSchemaDumper === null) {
            return [];
        }

        $filters = new MigrationFilters(entities: $rootShortNames);

        return $this->kunstmaanSchemaDumper->dump(
            $filters,
            isset($sourceScan['tables']) && is_array($sourceScan['tables']) ? $sourceScan['tables'] : null,
            $entities,
        );
    }

    /** @return array<string, mixed> */
    private function pageStructure(): array
    {
        if ($this->pageStructureSnapshot !== null) {
            return $this->pageStructureSnapshot;
        }

        return $this->pageStructureScanner?->scan() ?? [];
    }

    /**
     * @param list<string> $rootShortNames
     * @param array<string, DoctrineEntityInfo> $entities
     * @return array<string, true>
     */
    private function rootSet(array $rootShortNames, array $entities): array
    {
        $roots = [];
        foreach ($rootShortNames as $rootShortName) {
            if ($rootShortName === '') {
                continue;
            }
            $roots[$rootShortName] = true;
            $roots['App\\Entity\\Pages\\' . $rootShortName] = true;
        }

        if ($roots !== []) {
            return $roots;
        }

        foreach (array_keys($entities) as $fqcn) {
            if (str_starts_with($fqcn, 'App\\Entity\\Pages\\')) {
                $roots[$fqcn] = true;
            }
        }

        return $roots;
    }

    /** @param array<string, true> $rootSet */
    private function isRoot(string $fqcn, array $rootSet): bool
    {
        $short = substr(strrchr($fqcn, '\\') ?: '\\' . $fqcn, 1);

        return isset($rootSet[$fqcn]) || isset($rootSet[$short]);
    }

    /**
     * @param array<string, mixed> $graph
     * @param array<string, mixed> $sourceSchema
     */
    private function addEntity(array &$graph, DoctrineEntityInfo $entity, ?string $ref = null, array $sourceSchema = []): string
    {
        $ref ??= KunstmaanGraphContract::entityRef($entity->fqcn);
        $columns = [];
        foreach ($entity->columns as $column) {
            if (!$column instanceof DoctrineColumnInfo) {
                continue;
            }
            $columns[$column->columnName] = [
                'column' => $column->columnName,
                'property' => $column->propertyName,
                'type' => $column->type,
                'nullable' => $column->nullable,
                'translatable' => $column->isGedmoTranslatable,
            ];
            $this->addColumnSamples($graph, $ref, $entity->tableName, $column->columnName, $sourceSchema);
        }

        $existing = $graph[KunstmaanGraphContract::KEY_ENTITIES][$ref] ?? [];
        $graph[KunstmaanGraphContract::KEY_ENTITIES][$ref] = array_replace_recursive([
            'fqcn' => $entity->fqcn,
            'table' => $entity->tableName,
            'columns' => $columns,
            'relationRefs' => [],
            'inboundOwners' => [],
        ], is_array($existing) ? $existing : []);

        if ($entity->tableName !== '') {
            $graph[KunstmaanGraphContract::KEY_TABLES][$entity->tableName] = ['entityRef' => $ref];
        }

        return $ref;
    }

    /**
     * @param array<string, mixed> $graph
     * @param array<string, DoctrineEntityInfo> $entities
     * @param array<string, mixed> $sourceSchema
     * @param array<string, int> $visited
     */
    private function walkEntityRelations(
        array &$graph,
        DoctrineEntityInfo $entity,
        array $entities,
        array $sourceSchema,
        array &$visited,
        int $depth,
        int $depthLimit,
        ?string $sourceRef = null,
    ): void {
        $sourceRef ??= $this->entityRefFor($graph, $entity->fqcn);
        if (($visited[$entity->fqcn] ?? PHP_INT_MAX) <= $depth) {
            return;
        }
        $visited[$entity->fqcn] = $depth;

        if ($depth >= $depthLimit) {
            return;
        }

        foreach ($entity->relations as $relation) {
            if (!$relation instanceof DoctrineRelationInfo) {
                continue;
            }

            $target = $entities[$relation->targetEntity] ?? null;
            $targetRef = $target instanceof DoctrineEntityInfo
                ? $this->addEntity($graph, $target, null, $sourceSchema)
                : KunstmaanGraphContract::entityRef($relation->targetEntity);
            $relationRef = $sourceRef . '.' . $relation->propertyName;

            $graph[KunstmaanGraphContract::KEY_RELATIONS][$relationRef] = [
                'sourceRef' => $sourceRef,
                'sourceFqcn' => $entity->fqcn,
                'targetRef' => $targetRef,
                'targetFqcn' => $relation->targetEntity,
                'relationType' => $relation->relationType,
                'property' => $relation->propertyName,
                'fkColumn' => $relation->fkColumn,
                'intentCandidates' => [
                    KunstmaanGraphContract::INTENT_REFERENCE,
                    KunstmaanGraphContract::INTENT_PROMOTE,
                    KunstmaanGraphContract::INTENT_EMBED,
                    KunstmaanGraphContract::INTENT_DROP,
                    KunstmaanGraphContract::INTENT_OUT_OF_SCOPE,
                ],
            ];

            $graph[KunstmaanGraphContract::KEY_ENTITIES][$sourceRef]['relationRefs'][] = $relationRef;

            if (isset($graph[KunstmaanGraphContract::KEY_ENTITIES][$targetRef])) {
                $graph[KunstmaanGraphContract::KEY_ENTITIES][$targetRef]['inboundOwners'][] = [
                    'ownerRef' => $sourceRef,
                    'ownerFqcn' => $entity->fqcn,
                    'property' => $relation->propertyName,
                    'fkColumn' => $relation->fkColumn,
                    'targetFqcn' => $relation->targetEntity,
                    'relationType' => $relation->relationType,
                    'relationRef' => $relationRef,
                ];
            }

            if ($target instanceof DoctrineEntityInfo) {
                $this->walkEntityRelations($graph, $target, $entities, $sourceSchema, $visited, $depth + 1, $depthLimit, $targetRef);
            }
        }
    }

    /** @param array<string, mixed> $graph */
    private function entityRefFor(array $graph, string $fqcn): string
    {
        $pageRef = KunstmaanGraphContract::pageRootRef($fqcn);
        if (isset($graph[KunstmaanGraphContract::KEY_ENTITIES][$pageRef])) {
            return $pageRef;
        }

        return KunstmaanGraphContract::entityRef($fqcn);
    }

    /**
     * @param array<string, mixed> $graph
     * @param array<string, mixed> $pageStructure
     * @param array<string, DoctrineEntityInfo> $entities
     * @param array<string, mixed> $sourceSchema
     * @param array<string, int> $visited
     */
    private function addPageparts(
        array &$graph,
        string $pageFqcn,
        string $pageRef,
        array $pageStructure,
        array $entities,
        array $sourceSchema,
        array &$visited,
        int $depthLimit,
    ): void {
        $record = $pageStructure[$pageFqcn] ?? null;
        if (!is_array($record)) {
            return;
        }

        $contexts = $record['contexts'] ?? [];
        if (!is_array($contexts)) {
            return;
        }

        foreach ($contexts as $context) {
            if (!is_array($context)) {
                continue;
            }
            $contextName = isset($context['name']) && is_string($context['name']) ? $context['name'] : '';
            $allowed = $context['allowedPagePartClasses'] ?? [];
            if (!is_array($allowed)) {
                continue;
            }

            foreach ($allowed as $allowedPagePart) {
                if (!is_array($allowedPagePart)) {
                    continue;
                }
                $class = isset($allowedPagePart['class']) && is_string($allowedPagePart['class'])
                    ? ltrim($allowedPagePart['class'], '\\')
                    : '';
                if ($class === '') {
                    continue;
                }

                $table = isset($allowedPagePart['table']) && is_string($allowedPagePart['table'])
                    ? $allowedPagePart['table']
                    : '';
                $pagepartRef = KunstmaanGraphContract::pagepartRef($class);
                $usageRef = $pageRef . '.pagepart.' . $contextName . '.' . $pagepartRef;
                $pagepartEntity = $entities[$class] ?? null;
                $columns = [];
                if ($pagepartEntity instanceof DoctrineEntityInfo) {
                    foreach ($pagepartEntity->columns as $column) {
                        if ($column instanceof DoctrineColumnInfo) {
                            $columns[$column->columnName] = [
                                'column' => $column->columnName,
                                'property' => $column->propertyName,
                                'type' => $column->type,
                                'nullable' => $column->nullable,
                            ];
                            $this->addColumnSamples($graph, $pagepartRef, $pagepartEntity->tableName, $column->columnName, $sourceSchema);
                        }
                    }
                    $table = $pagepartEntity->tableName !== '' ? $pagepartEntity->tableName : $table;
                }

                $graph[KunstmaanGraphContract::KEY_PAGEPARTS][$pagepartRef] = [ // pageparts definition
                    'fqcn' => $class,
                    'table' => $table,
                    'columns' => $columns,
                    'relationRefs' => [],
                ];
                $graph[KunstmaanGraphContract::KEY_PAGEPART_USAGES][$usageRef] = [ // pagepartUsages reference
                    'pageRootRef' => $pageRef,
                    'context' => $contextName,
                    'pagepartRef' => $pagepartRef,
                    'sourceTable' => $table,
                    'orderingEvidence' => $this->orderingEvidence($columns),
                ];
                if ($table !== '') {
                    $graph[KunstmaanGraphContract::KEY_TABLES][$table] = ['pagepartRef' => $pagepartRef];
                }

                if ($pagepartEntity instanceof DoctrineEntityInfo) {
                    $this->walkEntityRelations($graph, $pagepartEntity, $entities, $sourceSchema, $visited, 1, $depthLimit, $pagepartRef);
                }
            }
        }
    }

    /**
     * @param array<string, array<string, mixed>> $columns
     * @return list<string>
     */
    private function orderingEvidence(array $columns): array
    {
        $evidence = [];
        foreach (['weight', 'position', 'display_order', 'sequence'] as $candidate) {
            if (isset($columns[$candidate])) {
                $evidence[] = $candidate;
            }
        }

        return $evidence;
    }

    /**
     * @param array<string, mixed> $graph
     * @param array<string, mixed> $sourceScan
     */
    private function addAssets(array &$graph, array $sourceScan): void
    {
        $mediaFks = $sourceScan['mediaFks'] ?? [];
        if (!is_array($mediaFks)) {
            return;
        }

        $tableRefs = $graph[KunstmaanGraphContract::KEY_TABLES];
        foreach ($mediaFks as $mediaFk) {
            if (!is_array($mediaFk)) {
                continue;
            }
            $table = isset($mediaFk['table']) && is_string($mediaFk['table']) ? $mediaFk['table'] : '';
            $column = isset($mediaFk['column']) && is_string($mediaFk['column']) ? $mediaFk['column'] : '';
            if ($table === '' || $column === '' || !isset($tableRefs[$table]) || !is_array($tableRefs[$table])) {
                continue;
            }

            $ownerRef = $tableRefs[$table]['entityRef'] ?? $tableRefs[$table]['pagepartRef'] ?? null;
            if (!is_string($ownerRef) || $ownerRef === '') {
                continue;
            }

            $graph[KunstmaanGraphContract::KEY_ASSETS][$ownerRef . '.' . $column] = [ // assets evidence
                'ownerRef' => $ownerRef,
                'table' => $table,
                'column' => $column,
            ];
        }
    }

    /**
     * @param array<string, mixed> $graph
     * @param array<string, mixed> $sourceSchema
     */
    private function addColumnSamples(array &$graph, string $ref, string $table, string $column, array $sourceSchema): void
    {
        $columns = $sourceSchema['columns'][$table] ?? null;
        if (!is_array($columns)) {
            return;
        }

        foreach ($columns as $columnInfo) {
            if (!is_array($columnInfo) || ($columnInfo['column'] ?? null) !== $column) {
                continue;
            }
            $samples = $columnInfo['samples'] ?? [];
            if (is_array($samples) && $samples !== []) {
                $graph[KunstmaanGraphContract::KEY_SAMPLES][$ref . '.' . $column] = array_values($samples);
            }
            return;
        }
    }

    /** @param array<string, mixed> $graph */
    private function sortGraph(array &$graph): void
    {
        foreach ([
            KunstmaanGraphContract::KEY_ROOTS,
            KunstmaanGraphContract::KEY_ENTITIES,
            KunstmaanGraphContract::KEY_RELATIONS,
            KunstmaanGraphContract::KEY_PAGEPARTS, // pageparts sorted by ref
            KunstmaanGraphContract::KEY_PAGEPART_USAGES,
            KunstmaanGraphContract::KEY_ASSETS,
            KunstmaanGraphContract::KEY_TABLES,
            KunstmaanGraphContract::KEY_SAMPLES,
            KunstmaanGraphContract::KEY_CONSTRAINTS,
        ] as $key) {
            if (isset($graph[$key]) && is_array($graph[$key])) {
                ksort($graph[$key]);
            }
        }
    }
}
