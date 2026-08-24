<?php

declare(strict_types=1);

namespace Lameco\KumaCompile\Payload;

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
     * The primary site's id.
     *
     * A Craft Address supports the primary site and no other — `Element::getSupportedSites()`
     * is not overridden for it — so a field holding one is effectively untranslatable, and
     * saving it against any other site throws. The loader needs to know which site that is.
     */
    /** @return array{id: int, handle: string} */
    public function primarySite(): array;

    /**
     * @return list<string> custom-field handles in the entry type's field layout; [] when the entry type is unknown
     */
    public function fieldHandlesFor(string $entryTypeHandle): array;

    /**
     * @return list<string> allowed block-type (nested entry type) handles for a Matrix field; [] when the field isn't a Matrix field or is unknown
     */
    public function blockTypesFor(string $entryTypeHandle, string $fieldHandle): array;

    /**
     * Every field placement on an entry type, with the detail the compile side needs to
     * turn a legacy row into a block: whether a field is a Matrix (and what it nests),
     * and whether the layout marks it required.
     *
     * Compiling against the live schema rather than a parse of `config/project/**` means
     * the answer cannot lag an unapplied project config, and there is one implementation
     * of "does this handle exist" instead of two.
     *
     * Keyed by the *placement's* handle, which a layout may override — `commonPageBuilder`
     * placed as `pageBuilder`. The mapping names the placement, so this must too.
     *
     * @return array<string, array{type: string, required: bool, nested: list<string>, propagationMethod?: ?string}> field handle => placement
     */
    public function fieldSlotsFor(string $entryTypeHandle): array;
}
