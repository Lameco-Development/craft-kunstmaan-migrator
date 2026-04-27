<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\source;

/**
 * Immutable value object representing one ORM-mapped column extracted from a
 * Doctrine entity file (PHP 8 attribute or docblock annotation form).
 */
final class DoctrineColumnInfo
{
    public function __construct(
        /** Database column name (from name: '…' / name="…"). Falls back to property name. */
        public readonly string $columnName,
        /** Doctrine semantic type (string, integer, text, datetime, boolean, …). */
        public readonly string $type,
        public readonly bool $nullable,
        /** PHP property name on the entity class. */
        public readonly string $propertyName,
        /**
         * Plan 08-02 / D-10 signal #1 — Gedmo Translatable attribute presence.
         *
         * True when the property carries `#[Gedmo\Translatable]` (or any
         * use-map / FQCN equivalent of `Gedmo\Mapping\Annotation\Translatable`).
         * Consumed alongside the runtime `ext_translations` row signal by
         * `TaxonomyMigrationService` (Plan 08-11) to seed the per-locale
         * translation overlay.
         *
         * Defaults to false so pre-Phase-8 callers see no behavior change.
         */
        public readonly bool $isGedmoTranslatable = false,
    ) {}
}
