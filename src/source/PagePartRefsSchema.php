<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\source;

use lameco\kunstmaanmigrator\db\LegacyDbService;

/**
 * Resolve the actual column names on `kuma_page_part_refs` for the connected
 * legacy DB.
 *
 * Different Kunstmaan vintages name the foreign-key columns differently:
 *
 *   - Older / generator-bundle scaffolded sites: `page_part_id`,
 *     `page_part_entityname` (snake_case).
 *   - Newer / camelCase-Doctrine sites (e.g. dewert, sailwise): `pagePartId`,
 *     `pagePartEntityname`.
 *
 * Both shapes appear in the wild on currently-maintained Lameco projects, so
 * the migrator can't pick one and call it canonical. This class introspects
 * the table once on construction, picks the actual column names, and exposes
 * SELECT-fragment helpers that alias whichever names exist back to the
 * snake_case keys the calling PHP code already uses (`$row['page_part_id']`,
 * `$row['page_part_entityname']`). That keeps every call site below the
 * SQL boundary untouched.
 *
 * Cached for the lifetime of the request — a single CLI/web run never spans
 * two DBs, so detecting once is correct.
 */
final class PagePartRefsSchema
{
    /** Snake-case column names used in PHP `$row['…']` reads. */
    public const KEY_ID = 'page_part_id';
    public const KEY_ENTITY = 'page_part_entityname';

    private string $idColumn;
    private string $entityColumn;

    public function __construct(LegacyDbService $legacyDb)
    {
        $cols = $this->describeColumns($legacyDb);
        $this->idColumn = $this->pickColumn($cols, ['page_part_id', 'pagePartId'])
            ?? 'page_part_id';
        $this->entityColumn = $this->pickColumn($cols, ['page_part_entityname', 'pagePartEntityname'])
            ?? 'page_part_entityname';
    }

    /**
     * Real column name on the legacy DB for the page-part ID FK. Use only
     * inside SELECT clauses — wrap with `selectAliases()` for the typical
     * PHP-key-preserving shape.
     */
    public function idColumn(): string
    {
        return $this->idColumn;
    }

    public function entityColumn(): string
    {
        return $this->entityColumn;
    }

    /**
     * SELECT-list fragment that returns BOTH columns aliased to the
     * canonical snake_case keys the calling PHP code expects. Caller
     * supplies any additional columns / leading-comma context.
     *
     * Example:
     *   $sql = "SELECT context, sequencenumber, " . $schema->selectAliases()
     *        . " FROM " . KunstmaanCoreTables::PAGE_PART_REFS;
     */
    public function selectAliases(): string
    {
        return sprintf(
            '%s AS %s, %s AS %s',
            $this->idColumn,
            self::KEY_ID,
            $this->entityColumn,
            self::KEY_ENTITY,
        );
    }

    /**
     * Single-column variant — returns just the entity column aliased.
     * Used in queries that only project the entity name (no id).
     */
    public function entitySelectAlias(): string
    {
        return sprintf('%s AS %s', $this->entityColumn, self::KEY_ENTITY);
    }

    /**
     * @return list<string>  Lowercase column names actually present on the table.
     */
    private function describeColumns(LegacyDbService $legacyDb): array
    {
        $rows = $legacyDb->queryAll(
            'SHOW COLUMNS FROM ' . KunstmaanCoreTables::PAGE_PART_REFS,
        );
        $out = [];
        foreach ($rows as $row) {
            if (isset($row['Field'])) {
                $out[] = (string) $row['Field'];
            } elseif (isset($row['field'])) {
                $out[] = (string) $row['field'];
            }
        }
        return $out;
    }

    /**
     * @param list<string> $present
     * @param list<string> $candidates
     */
    private function pickColumn(array $present, array $candidates): ?string
    {
        $byLower = [];
        foreach ($present as $c) {
            $byLower[strtolower($c)] = $c;
        }
        foreach ($candidates as $candidate) {
            $key = strtolower($candidate);
            if (isset($byLower[$key])) {
                return $byLower[$key];
            }
        }
        return null;
    }
}
