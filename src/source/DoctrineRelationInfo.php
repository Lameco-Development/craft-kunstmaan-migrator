<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\source;

/**
 * Immutable value object representing one ORM relation extracted from a
 * Doctrine entity file.
 */
final class DoctrineRelationInfo
{
    public function __construct(
        /** ManyToOne | OneToMany | ManyToMany | OneToOne */
        public readonly string $relationType,
        /**
         * Target entity — fully-qualified when resolvable from `use` statements,
         * otherwise the short class name as written in the annotation/attribute.
         */
        public readonly string $targetEntity,
        /** PHP property name that carries the relation. */
        public readonly string $propertyName,
        /**
         * FK column name on the owning side from #[ORM\JoinColumn(name: '...')],
         * or null when not present (e.g. inverse/mappedBy sides).
         */
        public readonly ?string $fkColumn = null,
    ) {}
}
