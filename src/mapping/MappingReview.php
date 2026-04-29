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
