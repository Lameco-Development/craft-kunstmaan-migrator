<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\source;

/**
 * Parsed metadata for a single Doctrine-mapped entity class.
 *
 * Source of truth for structure (table name, columns, relations) — extracted
 * from PHP source code rather than inferred from the database schema.
 *
 * @see DoctrineEntityParser
 */
final class DoctrineEntityInfo
{
    /**
     * @param \lameco\kunstmaanmigrator\source\DoctrineColumnInfo[] $columns
     * @param \lameco\kunstmaanmigrator\source\DoctrineRelationInfo[] $relations
     */
    public function __construct(
        public readonly string $fqcn,
        public readonly string $tableName,
        public readonly array $columns,
        public readonly array $relations,
    ) {}
}
