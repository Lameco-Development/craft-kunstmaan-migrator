<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\mapping;

use lameco\kunstmaanmigrator\filter\MigrationFilters;

/**
 * Shared mapping-review helpers for CP and CLI operator surfaces.
 */
final class MappingReview
{
    /**
     * @return list<string>
     */
    public static function statusFilterOptions(): array
    {
        return ['all', 'proposed', 'needs-review', 'accepted', 'dropped', 'unsupported', 'warning'];
    }

    /**
     * @return list<string>
     */
    public static function kindFilterOptions(): array
    {
        return ['all', 'column', 'pagePart', 'nodeClass', 'taxonomy', 'dataProvider'];
    }

    /**
     * @return list<string>
     */
    public static function findingFilterOptions(): array
    {
        return ['all', 'fatal', 'warning', 'unsupported', 'none'];
    }

    public static function normalizeStatusFilter(string $statusFilter): string
    {
        return self::normalizeOption($statusFilter, self::statusFilterOptions());
    }

    public static function normalizeKindFilter(string $kindFilter): string
    {
        return self::normalizeOption($kindFilter, self::kindFilterOptions());
    }

    public static function normalizeFindingFilter(string $findingFilter): string
    {
        return self::normalizeOption($findingFilter, self::findingFilterOptions());
    }

    public static function normalizeSearchQuery(string $searchQuery): string
    {
        return trim($searchQuery);
    }

    /**
     * @param list<array{index:int,row:array<string,mixed>,summary?:string}> $indexedRows
     * @param array{statusFilter?:string,kindFilter?:string,findingFilter?:string,searchQuery?:string} $filters
     * @return list<array{index:int,row:array<string,mixed>,summary?:string}>
     */
    public static function filterRows(array $indexedRows, array $filters): array
    {
        $statusFilter = self::normalizeStatusFilter((string) ($filters['statusFilter'] ?? 'all'));
        $kindFilter = self::normalizeKindFilter((string) ($filters['kindFilter'] ?? 'all'));
        $findingFilter = self::normalizeFindingFilter((string) ($filters['findingFilter'] ?? 'all'));
        $searchQuery = self::normalizeSearchQuery((string) ($filters['searchQuery'] ?? ''));

        $out = [];
        foreach ($indexedRows as $item) {
            $row = $item['row'];
            if ($statusFilter !== 'all' && (string) ($row['status'] ?? 'unknown') !== $statusFilter) {
                continue;
            }
            if ($kindFilter !== 'all' && (string) ($row['kind'] ?? 'column') !== $kindFilter) {
                continue;
            }
            if (!self::matchesFindingFilter($row, $findingFilter)) {
                continue;
            }
            if ($searchQuery !== '' && !self::matchesSearchQuery($row, $searchQuery)) {
                continue;
            }
            $out[] = $item;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function findingSeverity(array $row): string
    {
        $explicit = (string) ($row['findingSeverity'] ?? $row['severity'] ?? '');
        if (in_array($explicit, ['fatal', 'warning', 'unsupported'], true)) {
            return $explicit;
        }

        $status = (string) ($row['status'] ?? '');
        if ($status === 'unsupported' || $status === 'warning') {
            return $status;
        }

        foreach ((array) ($row['findings'] ?? []) as $finding) {
            if (!is_array($finding)) {
                continue;
            }
            $severity = (string) ($finding['severity'] ?? '');
            if ($severity === 'fatal') {
                return 'fatal';
            }
            if ($severity === 'unsupported') {
                return 'unsupported';
            }
            if ($severity === 'warning') {
                return 'warning';
            }
        }

        return 'none';
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function findingRationale(array $row): string
    {
        $reasons = [];
        $rationale = trim((string) ($row['rationale'] ?? ''));
        if ($rationale !== '') {
            $reasons[] = $rationale;
        }
        foreach ((array) ($row['findings'] ?? []) as $finding) {
            if (!is_array($finding)) {
                continue;
            }
            $message = trim((string) ($finding['message'] ?? $finding['reason'] ?? ''));
            if ($message !== '') {
                $reasons[] = $message;
            }
        }

        return implode(' ', array_values(array_unique($reasons)));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{index:int,row:array<string,mixed>}>
     */
    public static function collectPageMappingRows(array $rows, MigrationFilters $filters): array
    {
        $out = [];
        $scopedSourceTables = [];
        $scopedEntryTypes = [];
        foreach ($rows as $row) {
            if (!is_array($row) || (string) ($row['kind'] ?? 'column') !== 'nodeClass') {
                continue;
            }
            $fqcn = (string) ($row['fqcn'] ?? '');
            if ($fqcn === '' || !$filters->allows($fqcn)) {
                continue;
            }
            $sourceTable = (string) ($row['sourceTable'] ?? '');
            if ($sourceTable !== '') {
                $scopedSourceTables[$sourceTable] = true;
            }
            $targetEntryType = (string) ($row['targetEntryType'] ?? '');
            if ($targetEntryType !== '') {
                $scopedEntryTypes[$targetEntryType] = true;
            }
        }

        foreach ($rows as $idx => $row) {
            if (!is_array($row)) {
                continue;
            }
            if (
                self::matchesEntitiesFilter($row, $filters)
                || self::matchesScopedNodeClassRow($row, $scopedSourceTables, $scopedEntryTypes)
            ) {
                $out[] = ['index' => (int) $idx, 'row' => $row];
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function matchesEntitiesFilter(array $row, MigrationFilters $filters): bool
    {
        if ($filters->entities === []) {
            return true;
        }

        $kind = (string) ($row['kind'] ?? 'column');
        if ($kind === 'pagePart') {
            $parent = (string) ($row['parentPageClass'] ?? '');
            return $parent !== '' && $filters->allows($parent);
        }

        if ($kind === 'nodeClass' || $kind === 'taxonomy') {
            $fqcn = (string) ($row['fqcn'] ?? '');
            return $fqcn !== '' && $filters->allows($fqcn);
        }

        $table = (string) ($row['table'] ?? '');
        foreach ($filters->entities as $entity) {
            $snake = self::snakeCaseEntity($entity);
            if (str_starts_with($table, 'kuma_' . $snake)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<string>
     */
    public static function pageEntities(array $rows): array
    {
        $entities = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $kind = (string) ($row['kind'] ?? 'column');
            if ($kind === 'nodeClass') {
                $short = self::shortClass((string) ($row['fqcn'] ?? ''));
                if ($short !== '') {
                    $entities[$short] = true;
                }
                continue;
            }
            if ($kind === 'pagePart') {
                $short = self::shortClass((string) ($row['parentPageClass'] ?? ''));
                if ($short !== '') {
                    $entities[$short] = true;
                }
            }
        }

        $out = array_keys($entities);
        sort($out, SORT_NATURAL | SORT_FLAG_CASE);
        return $out;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function summaryLine(array $row): string
    {
        $kind = (string) ($row['kind'] ?? 'column');
        $status = (string) ($row['status'] ?? 'unknown');
        if ($kind === 'nodeClass') {
            return sprintf(
                '[%s] node %s -> %s.%s',
                $status,
                self::shortClass((string) ($row['fqcn'] ?? '')),
                (string) ($row['targetSection'] ?? ''),
                (string) ($row['targetEntryType'] ?? ''),
            );
        }
        if ($kind === 'pagePart') {
            $fields = [];
            foreach ((array) ($row['fields'] ?? []) as $field) {
                if (!is_array($field)) {
                    continue;
                }
                $fields[] = (string) ($field['targetHandle'] ?? '') . '<-' . (string) ($field['sourceProperty'] ?? '');
            }
            return sprintf(
                '[%s] pagePart %s:%s -> %s.%s:%s%s',
                $status,
                self::shortClass((string) ($row['pagePartClass'] ?? '')),
                (string) ($row['context'] ?? ''),
                (string) ($row['targetEntryType'] ?? ''),
                (string) ($row['targetMatrixField'] ?? ''),
                (string) ($row['targetBlockType'] ?? ''),
                $fields !== [] ? ' {' . implode(', ', $fields) . '}' : '',
            );
        }

        return sprintf(
            '[%s] column %s.%s -> %s.%s (%s)',
            $status,
            (string) ($row['table'] ?? ''),
            (string) ($row['column'] ?? ''),
            (string) ($row['targetEntryType'] ?? ''),
            (string) ($row['targetHandle'] ?? ''),
            (string) ($row['handler'] ?? ''),
        );
    }

    public static function shortClass(string $class): string
    {
        $class = trim($class);
        if ($class === '') {
            return '';
        }
        $pos = strrpos($class, '\\');
        return $pos === false ? $class : substr($class, $pos + 1);
    }

    private static function snakeCaseEntity(string $entity): string
    {
        $short = self::shortClass($entity);
        $replaced = preg_replace('/(?<!^)[A-Z]/', '_$0', $short);
        assert(is_string($replaced), 'preg_replace returned null for literal snake-case pattern');
        return strtolower($replaced ?? $short);
    }

    /**
     * @param list<string> $allowed
     */
    private static function normalizeOption(string $value, array $allowed): string
    {
        $value = trim($value);
        return in_array($value, $allowed, true) ? $value : 'all';
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function matchesFindingFilter(array $row, string $findingFilter): bool
    {
        if ($findingFilter === 'all') {
            return true;
        }

        return self::findingSeverity($row) === $findingFilter;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function matchesSearchQuery(array $row, string $searchQuery): bool
    {
        $needle = mb_strtolower($searchQuery);
        foreach (self::searchHaystack($row) as $value) {
            if ($value !== '' && str_contains(mb_strtolower($value), $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $row
     * @return list<string>
     */
    private static function searchHaystack(array $row): array
    {
        $values = [
            (string) ($row['table'] ?? ''),
            (string) ($row['column'] ?? ''),
            (string) ($row['fqcn'] ?? ''),
            self::shortClass((string) ($row['fqcn'] ?? '')),
            (string) ($row['sourceTable'] ?? ''),
            (string) ($row['pagePartClass'] ?? ''),
            self::shortClass((string) ($row['pagePartClass'] ?? '')),
            (string) ($row['parentPageClass'] ?? ''),
            self::shortClass((string) ($row['parentPageClass'] ?? '')),
            (string) ($row['target'] ?? ''),
            (string) ($row['targetSection'] ?? ''),
            (string) ($row['targetEntryType'] ?? ''),
            (string) ($row['targetMatrixField'] ?? ''),
            (string) ($row['targetBlockType'] ?? ''),
            (string) ($row['targetHandle'] ?? ''),
            (string) ($row['handler'] ?? ''),
            (string) ($row['rationale'] ?? ''),
        ];

        foreach ((array) ($row['fields'] ?? []) as $field) {
            if (!is_array($field)) {
                continue;
            }
            $values[] = (string) ($field['sourceProperty'] ?? '');
            $values[] = (string) ($field['targetHandle'] ?? '');
            $values[] = (string) ($field['handler'] ?? '');
        }
        foreach ((array) ($row['findings'] ?? []) as $finding) {
            if (!is_array($finding)) {
                continue;
            }
            $values[] = (string) ($finding['message'] ?? '');
            $values[] = (string) ($finding['reason'] ?? '');
        }

        return array_values(array_filter($values, static fn (string $value): bool => $value !== ''));
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, true> $sourceTables
     * @param array<string, true> $entryTypes
     */
    private static function matchesScopedNodeClassRow(array $row, array $sourceTables, array $entryTypes): bool
    {
        if ((string) ($row['kind'] ?? 'column') !== 'column') {
            return false;
        }

        $table = (string) ($row['table'] ?? '');
        if ($table !== '' && isset($sourceTables[$table])) {
            return true;
        }

        $targetEntryType = (string) ($row['targetEntryType'] ?? '');
        return $targetEntryType !== '' && isset($entryTypes[$targetEntryType]);
    }
}
