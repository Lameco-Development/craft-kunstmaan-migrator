<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\payload;

/**
 * Read-side Craft schema lookups `PayloadValidator` needs to check a payload
 * against the live install. `CraftSchemaGateway` is the production
 * implementation (thin wrapper over `Craft::$app`); tests fake this
 * interface so validator specs never boot a real Craft application.
 *
 * The `id` fields on the by-handle lookups exist because Task 4's
 * `PayloadEntrySaver` resolves the same handles to the ids
 * `EntryMigrationService::saveEntryForSites()` requires — one gateway lookup
 * serves both the validator and the saver.
 */
interface SchemaGateway
{
    /**
     * @return array{id: int, handle: string}|null null when the section handle doesn't exist
     */
    public function sectionByHandle(string $handle): ?array;

    /**
     * `hasTitleFormat` is true when the entry type auto-generates its title
     * (Craft's `hasTitleField: false` + a non-null `titleFormat`) — such
     * entry types don't require an explicit `title` per enabled site.
     *
     * @return array{id: int, handle: string, hasTitleFormat: bool}|null null when the entry type handle doesn't exist
     */
    public function entryTypeByHandle(string $handle): ?array;

    /**
     * @return array{id: int, handle: string}|null null when the site handle doesn't exist
     */
    public function siteByHandle(string $handle): ?array;

    /**
     * @return list<string> custom-field handles in the entry type's field layout; [] when the entry type is unknown
     */
    public function fieldHandlesFor(string $entryTypeHandle): array;

    /**
     * @return list<string> allowed block-type (nested entry type) handles for a Matrix field; [] when the field isn't a Matrix field or is unknown
     */
    public function blockTypesFor(string $entryTypeHandle, string $fieldHandle): array;
}
