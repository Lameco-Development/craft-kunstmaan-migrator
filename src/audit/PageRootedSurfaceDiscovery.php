<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\audit;

use yii\base\Component;

/**
 * Pure discovery normalizer for Page-rooted coverage.
 *
 * Inputs are the structural artifacts already produced by analyze/compile/load
 * stages. Output rows intentionally exclude sample values and content bodies:
 * only FQCNs, tables, properties, handles, adapter names, ids, and token types
 * are emitted.
 */
final class PageRootedSurfaceDiscovery extends Component
{
    private const CATEGORY_MIGRATED = 'migrated';
    private const CATEGORY_DROPPED = 'dropped';
    private const CATEGORY_OUT_OF_SCOPE = 'out_of_scope';
    private const CATEGORY_UNSUPPORTED = 'unsupported';
    private const CATEGORY_WARNING = 'warning';

    /**
     * @param array<string, mixed> $mapping
     * @param array<string, mixed> $pageStructure
     * @param array<string, list<array<string, mixed>>> $relationMetadata FQCN => relation rows
     * @param array<string, list<array<string, mixed>>> $serviceMetadata assets/seo/redirects/ckeditorRefs
     * @return list<array<string, mixed>>
     */
    public function discover(
        array $mapping,
        array $pageStructure,
        array $relationMetadata = [],
        array $serviceMetadata = [],
    ): array {
        $pageRoots = $this->acceptedPageRoots($mapping, $pageStructure);
        $rows = [];

        foreach ($pageRoots as $pageFqcn => $root) {
            $sourceTable = (string) ($root['sourceTable'] ?? '');
            $targetSection = (string) ($root['targetSection'] ?? $root['section'] ?? '');
            $targetEntryType = (string) ($root['targetEntryType'] ?? '');

            foreach ($this->directFieldRows($pageFqcn, $sourceTable, $targetSection, $targetEntryType, $mapping) as $row) {
                $rows[] = $row;
            }
            foreach ($this->pagePartRows($pageFqcn, $sourceTable, $targetSection, $targetEntryType, $mapping, $pageStructure) as $row) {
                $rows[] = $row;
            }
            foreach ($this->relationRows($pageFqcn, $targetSection, $targetEntryType, $relationMetadata[$pageFqcn] ?? []) as $row) {
                $rows[] = $row;
            }
            foreach ($this->taxonomyAndDataProviderRows($pageFqcn, $targetSection, $targetEntryType, $mapping) as $row) {
                $rows[] = $row;
            }
            foreach ($this->serviceRows($pageFqcn, $targetSection, $targetEntryType, $serviceMetadata) as $row) {
                $rows[] = $row;
            }
        }

        usort($rows, static function (array $a, array $b): int {
            return [
                (string) ($a['pageFqcn'] ?? ''),
                (string) ($a['surfaceType'] ?? ''),
                (string) ($a['sourceIdentifier'] ?? ''),
            ] <=> [
                (string) ($b['pageFqcn'] ?? ''),
                (string) ($b['surfaceType'] ?? ''),
                (string) ($b['sourceIdentifier'] ?? ''),
            ];
        });

        return $rows;
    }

    /**
     * @param array<string, mixed> $mapping
     * @param array<string, mixed> $pageStructure
     * @return array<string, array<string, mixed>>
     */
    private function acceptedPageRoots(array $mapping, array $pageStructure): array
    {
        $roots = [];
        foreach ((array) ($mapping['proposals'] ?? []) as $row) {
            if (!is_array($row) || ((string) ($row['kind'] ?? '')) !== 'nodeClass') {
                continue;
            }
            if (((string) ($row['status'] ?? '')) !== 'accepted') {
                continue;
            }
            $fqcn = (string) ($row['fqcn'] ?? '');
            if ($fqcn === '') {
                continue;
            }
            $compiled = (array) (($mapping['nodeClasses'] ?? [])[$fqcn] ?? []);
            $structure = (array) ($pageStructure[$fqcn] ?? []);
            $roots[$fqcn] = [
                'sourceTable' => (string) ($row['sourceTable'] ?? $compiled['sourceTable'] ?? $structure['tableName'] ?? ''),
                'targetSection' => (string) ($row['targetSection'] ?? $compiled['section'] ?? ''),
                'targetEntryType' => (string) ($row['targetEntryType'] ?? ''),
                'section' => (string) ($compiled['section'] ?? ''),
            ];
        }

        foreach ((array) ($mapping['nodeClasses'] ?? []) as $fqcn => $spec) {
            if (!is_string($fqcn) || isset($roots[$fqcn]) || !is_array($spec)) {
                continue;
            }
            if (((string) ($spec['action'] ?? '')) === 'SKIP') {
                continue;
            }
            $structure = (array) ($pageStructure[$fqcn] ?? []);
            $roots[$fqcn] = [
                'sourceTable' => (string) ($spec['sourceTable'] ?? $structure['tableName'] ?? ''),
                'targetSection' => (string) ($spec['section'] ?? ''),
                'targetEntryType' => (string) ($spec['entryType'] ?? $spec['section'] ?? ''),
                'section' => (string) ($spec['section'] ?? ''),
            ];
        }

        ksort($roots);
        return $roots;
    }

    /** @return list<array<string, mixed>> */
    private function directFieldRows(string $pageFqcn, string $sourceTable, string $targetSection, string $targetEntryType, array $mapping): array
    {
        $rows = [];
        $compiledFields = (array) (($mapping['nodeClasses'][$pageFqcn]['fields'] ?? []));
        foreach ($compiledFields as $fieldHandle => $spec) {
            if (!is_array($spec)) {
                continue;
            }
            $source = (string) ($spec['source'] ?? '');
            if ($source === '') {
                continue;
            }
            $rows[] = $this->row($pageFqcn, 'direct_field', self::CATEGORY_MIGRATED, 'compiled nodeClasses.fields', $sourceTable . '.' . $source, [
                'sourceTable' => $sourceTable,
                'property' => $source,
                'fieldHandle' => (string) $fieldHandle,
                'handler' => (string) ($spec['handler'] ?? ''),
                'targetSection' => $targetSection,
                'targetEntryType' => $targetEntryType,
            ]);
        }

        foreach ((array) ($mapping['proposals'] ?? []) as $proposal) {
            if (!is_array($proposal) || ((string) ($proposal['kind'] ?? 'column')) !== 'column') {
                continue;
            }
            if ((string) ($proposal['table'] ?? '') !== $sourceTable) {
                continue;
            }
            $status = (string) ($proposal['status'] ?? '');
            if (!in_array($status, ['accepted', 'dropped'], true)) {
                continue;
            }
            $identifier = $sourceTable . '.' . (string) ($proposal['column'] ?? '');
            $category = $status === 'accepted' ? self::CATEGORY_MIGRATED : self::CATEGORY_DROPPED;
            if ($status === 'accepted' && $this->containsSourceIdentifier($rows, $identifier)) {
                continue;
            }
            $rows[] = $this->row($pageFqcn, 'direct_field', $category, 'mapping proposals', $identifier, [
                'sourceTable' => $sourceTable,
                'property' => (string) ($proposal['column'] ?? ''),
                'fieldHandle' => (string) ($proposal['targetHandle'] ?? ''),
                'handler' => (string) ($proposal['handler'] ?? ''),
                'reason' => (string) ($proposal['rationale'] ?? ''),
                'targetSection' => $targetSection,
                'targetEntryType' => $targetEntryType,
            ]);
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function pagePartRows(string $pageFqcn, string $sourceTable, string $targetSection, string $targetEntryType, array $mapping, array $pageStructure): array
    {
        $rows = [];
        $short = $this->shortName($pageFqcn);
        $covered = [];
        foreach ((array) ($mapping['proposals'] ?? []) as $proposal) {
            if (!is_array($proposal) || ((string) ($proposal['kind'] ?? '')) !== 'pagePart') {
                continue;
            }
            $parent = (string) ($proposal['parentPageClass'] ?? '');
            if ($parent !== '' && $parent !== $short && $parent !== $pageFqcn) {
                continue;
            }
            $class = (string) ($proposal['pagePartClass'] ?? '');
            if ($class === '') {
                continue;
            }
            $status = (string) ($proposal['status'] ?? '');
            $category = $status === 'dropped' ? self::CATEGORY_DROPPED : ($status === 'accepted' ? self::CATEGORY_MIGRATED : self::CATEGORY_WARNING);
            $identifier = $class . '|' . (string) ($proposal['context'] ?? '');
            $covered[$identifier] = true;
            $rows[] = $this->row($pageFqcn, 'pagepart', $category, 'pageStructure contexts + mapping proposals', $identifier, [
                'pagePartClass' => $class,
                'context' => (string) ($proposal['context'] ?? ''),
                'targetSection' => $targetSection,
                'targetEntryType' => $targetEntryType,
                'reason' => (string) ($proposal['rationale'] ?? ''),
            ]);
        }

        foreach ((array) ($pageStructure[$pageFqcn]['contexts'] ?? []) as $context) {
            if (!is_array($context)) {
                continue;
            }
            $contextName = (string) ($context['name'] ?? '');
            foreach ((array) ($context['allowedPagePartClasses'] ?? []) as $allowed) {
                if (!is_array($allowed)) {
                    continue;
                }
                $class = (string) ($allowed['class'] ?? '');
                if ($class === '') {
                    continue;
                }
                $identifier = $class . '|' . $contextName;
                if (isset($covered[$identifier])) {
                    continue;
                }
                $rows[] = $this->row($pageFqcn, 'pagepart', self::CATEGORY_UNSUPPORTED, 'pageStructure contexts', $identifier, [
                    'pagePartClass' => $class,
                    'sourceTable' => (string) ($allowed['table'] ?? ''),
                    'context' => $contextName,
                    'targetSection' => $targetSection,
                    'targetEntryType' => $targetEntryType,
                    'reason' => 'No accepted or dropped pagePart mapping row.',
                ]);
            }
        }

        foreach ((array) ($mapping['pageParts'] ?? []) as $key => $spec) {
            if (!is_string($key) || !str_starts_with($key, '__implicit_content__|' . $short . '|')) {
                continue;
            }
            $rows[] = $this->row($pageFqcn, 'implicit_content', self::CATEGORY_MIGRATED, 'ExtractService::buildImplicitContentPageParts', $key, [
                'sourceTable' => $sourceTable,
                'context' => substr($key, strlen('__implicit_content__|' . $short . '|')),
                'targetBlockType' => is_array($spec) ? (string) ($spec['target'] ?? '') : '',
                'targetSection' => $targetSection,
                'targetEntryType' => $targetEntryType,
            ]);
        }
        if (!$this->hasSurfaceType($rows, 'implicit_content')) {
            $rows[] = $this->row($pageFqcn, 'implicit_content', self::CATEGORY_WARNING, 'ExtractService::buildImplicitContentPageParts', $sourceTable . '.__implicit_content__', [
                'sourceTable' => $sourceTable,
                'targetSection' => $targetSection,
                'targetEntryType' => $targetEntryType,
                'reason' => 'No implicit content page-part mapping is configured.',
            ]);
        }

        return $rows;
    }

    /** @param list<array<string, mixed>> $relations @return list<array<string, mixed>> */
    private function relationRows(string $pageFqcn, string $targetSection, string $targetEntryType, array $relations): array
    {
        $rows = [];
        $seen = [];
        foreach ($relations as $relation) {
            if (!is_array($relation)) {
                continue;
            }
            $type = (string) ($relation['type'] ?? '');
            $surfaceType = match ($type) {
                'ManyToOne' => 'many_to_one',
                'ManyToMany' => 'many_to_many',
                'OneToMany' => 'one_to_many',
                default => 'relation',
            };
            $category = $type === 'ManyToOne' ? self::CATEGORY_MIGRATED : self::CATEGORY_UNSUPPORTED;
            $property = (string) ($relation['property'] ?? '');
            $seen[$surfaceType] = true;
            $rows[] = $this->row($pageFqcn, $surfaceType, $category, $type === 'ManyToOne' ? 'ExtractService::_rel metadata' : 'Doctrine relation metadata + MappingCompiler relation warnings/options', $property !== '' ? $property : $type, [
                'property' => $property,
                'relationType' => $type,
                'targetEntity' => (string) ($relation['targetEntity'] ?? ''),
                'sourceTable' => (string) ($relation['sourceTable'] ?? ''),
                'joinColumn' => (string) ($relation['joinColumn'] ?? ''),
                'joinTable' => (string) ($relation['joinTable'] ?? ''),
                'mappedBy' => (string) ($relation['mappedBy'] ?? ''),
                'targetSection' => $targetSection,
                'targetEntryType' => $targetEntryType,
            ]);
        }

        foreach (['many_to_one', 'many_to_many', 'one_to_many'] as $missingType) {
            if (isset($seen[$missingType])) {
                continue;
            }
            $rows[] = $this->row($pageFqcn, $missingType, self::CATEGORY_UNSUPPORTED, 'Doctrine relation metadata', $missingType . ':not-discovered', [
                'targetSection' => $targetSection,
                'targetEntryType' => $targetEntryType,
                'reason' => 'No relation metadata provided for this relation shape.',
            ]);
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function taxonomyAndDataProviderRows(string $pageFqcn, string $targetSection, string $targetEntryType, array $mapping): array
    {
        $rows = [];
        foreach (['taxonomy' => 'taxonomies', 'dataProvider' => 'dataProviders'] as $kind => $block) {
            foreach ((array) ($mapping['proposals'] ?? []) as $proposal) {
                if (!is_array($proposal) || ((string) ($proposal['kind'] ?? '')) !== $kind) {
                    continue;
                }
                $fqcn = (string) ($proposal['fqcn'] ?? '');
                if ($fqcn === '') {
                    continue;
                }
                $status = (string) ($proposal['status'] ?? '');
                $category = $status === 'accepted' ? self::CATEGORY_MIGRATED : ($status === 'dropped' ? self::CATEGORY_OUT_OF_SCOPE : self::CATEGORY_WARNING);
                $rows[] = $this->row($pageFqcn, 'taxonomy_dataprovider', $category, 'compiled ' . $block . ' + mapping proposals', $fqcn, [
                    'kind' => $kind,
                    'fqcn' => $fqcn,
                    'sourceTable' => (string) ($proposal['sourceTable'] ?? ''),
                    'targetSection' => $targetSection,
                    'targetEntryType' => $targetEntryType,
                    'reason' => (string) ($proposal['rationale'] ?? ''),
                ]);
            }
        }
        if ($rows === []) {
            $rows[] = $this->row($pageFqcn, 'taxonomy_dataprovider', self::CATEGORY_OUT_OF_SCOPE, 'compiled taxonomies/dataProviders', 'taxonomy_dataprovider:not-configured', [
                'targetSection' => $targetSection,
                'targetEntryType' => $targetEntryType,
                'reason' => 'No page-owned taxonomy or dataProvider mapping configured.',
            ]);
        }
        return $rows;
    }

    /** @param array<string, list<array<string, mixed>>> $serviceMetadata @return list<array<string, mixed>> */
    private function serviceRows(string $pageFqcn, string $targetSection, string $targetEntryType, array $serviceMetadata): array
    {
        $rows = [];
        $rows = array_merge($rows, $this->metadataRows($pageFqcn, 'asset', 'AssetMigrationService referenced-id collector', $serviceMetadata['assets'] ?? [], self::CATEGORY_MIGRATED, self::CATEGORY_WARNING, $targetSection, $targetEntryType));
        $rows = array_merge($rows, $this->metadataRows($pageFqcn, 'seo', 'SeoMigrationService source lookup/adapters', $serviceMetadata['seo'] ?? [], self::CATEGORY_MIGRATED, self::CATEGORY_OUT_OF_SCOPE, $targetSection, $targetEntryType));
        $rows = array_merge($rows, $this->metadataRows($pageFqcn, 'redirect', 'RedirectMigrationService source lookup/adapters', $serviceMetadata['redirects'] ?? [], self::CATEGORY_MIGRATED, self::CATEGORY_OUT_OF_SCOPE, $targetSection, $targetEntryType));
        $rows = array_merge($rows, $this->metadataRows($pageFqcn, 'ckeditor_ref', 'CkeditorRewriterService token/media reference scanner', $serviceMetadata['ckeditorRefs'] ?? [], self::CATEGORY_MIGRATED, self::CATEGORY_WARNING, $targetSection, $targetEntryType));
        return $rows;
    }

    /** @param list<array<string, mixed>> $metadata @return list<array<string, mixed>> */
    private function metadataRows(string $pageFqcn, string $surfaceType, string $sourceService, array $metadata, string $presentCategory, string $missingCategory, string $targetSection, string $targetEntryType): array
    {
        $rows = [];
        foreach ($metadata as $index => $item) {
            if (!is_array($item)) {
                continue;
            }
            $rowPage = (string) ($item['pageFqcn'] ?? $pageFqcn);
            if ($rowPage !== $pageFqcn) {
                continue;
            }
            $identifier = $this->metadataIdentifier($surfaceType, $item, $index);
            $rows[] = $this->row($pageFqcn, $surfaceType, $presentCategory, $sourceService, $identifier, [
                'fieldHandle' => (string) ($item['fieldHandle'] ?? ''),
                'adapter' => (string) ($item['adapter'] ?? ''),
                'table' => (string) ($item['table'] ?? ''),
                'tokenType' => (string) ($item['tokenType'] ?? ''),
                'assetId' => isset($item['assetId']) ? (int) $item['assetId'] : null,
                'fileId' => isset($item['fileId']) ? (int) $item['fileId'] : null,
                'nodeTranslationId' => isset($item['nodeTranslationId']) ? (int) $item['nodeTranslationId'] : null,
                'pathKind' => (string) ($item['pathKind'] ?? ''),
                'targetSection' => $targetSection,
                'targetEntryType' => $targetEntryType,
            ]);
        }

        if ($rows === []) {
            $rows[] = $this->row($pageFqcn, $surfaceType, $missingCategory, $sourceService, $surfaceType . ':not-discovered', [
                'targetSection' => $targetSection,
                'targetEntryType' => $targetEntryType,
                'reason' => 'No structural discovery input provided for this surface.',
            ]);
        }

        return $rows;
    }

    /** @param array<string, mixed> $extra @return array<string, mixed> */
    private function row(string $pageFqcn, string $surfaceType, string $categoryHint, string $sourceService, string $sourceIdentifier, array $extra = []): array
    {
        $row = [
            'pageFqcn' => $pageFqcn,
            'surfaceType' => $surfaceType,
            'categoryHint' => $categoryHint,
            'sourceService' => $sourceService,
            'sourceIdentifier' => $sourceIdentifier,
        ];
        foreach ($extra as $key => $value) {
            if ($value === '' || $value === null || in_array($key, ['samples', 'sample', 'value', 'body', 'content'], true)) {
                continue;
            }
            $row[$key] = $value;
        }
        ksort($row);
        return $row;
    }

    /** @param list<array<string, mixed>> $rows */
    private function containsSourceIdentifier(array $rows, string $identifier): bool
    {
        foreach ($rows as $row) {
            if ((string) ($row['sourceIdentifier'] ?? '') === $identifier) {
                return true;
            }
        }
        return false;
    }

    /** @param list<array<string, mixed>> $rows */
    private function hasSurfaceType(array $rows, string $surfaceType): bool
    {
        foreach ($rows as $row) {
            if ((string) ($row['surfaceType'] ?? '') === $surfaceType) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string, mixed> $item */
    private function metadataIdentifier(string $surfaceType, array $item, int $index): string
    {
        foreach (['fieldHandle', 'assetId', 'fileId', 'nodeTranslationId', 'table', 'tokenType', 'pathKind', 'adapter'] as $key) {
            if (isset($item[$key]) && (string) $item[$key] !== '') {
                return $surfaceType . ':' . $key . ':' . (string) $item[$key];
            }
        }
        return $surfaceType . ':' . $index;
    }

    private function shortName(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');
        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }
}
