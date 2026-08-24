<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\craft;

use craft\base\ElementInterface;
use craft\elements\Entry;

/**
 * The write half's one seam at Craft.
 *
 * Every module that writes elements reached for `Craft::$app->elements`
 * directly. A static call admits no second adapter, so the only test surface
 * for 7,457 lines was a real database and a two-hour migration — which is why
 * the defects found in this half all cost hours to find and would each have
 * been a three-line assertion behind a seam.
 *
 * The surface is deliberately the one the write half actually uses, derived by
 * reading all nineteen call sites rather than by guessing: eleven saves, four
 * lookups, two deletes and two cache invalidations. Nothing else from Craft's
 * elements service belongs here until something needs it.
 *
 * The second pass over the write half found what still kept `saveEntryForSites()`
 * off this seam was not reads — ten of thirteen were `findById()` already — but
 * construction: `new Entry()`, the Single-section lookup, and one raw query on
 * `elements_sites`. Those three are the rest of the surface.
 *
 * Two adapters make it a real seam rather than a hypothetical one:
 * CraftElementWriter in production, InMemoryElementWriter in tests.
 */
interface ElementWriter
{
    /**
     * A blank entry for a section, entry type and site — the element Craft
     * would build with `new Entry()`, which boots the application to do it.
     */
    public function createEntry(int $sectionId, int $typeId, int $siteId): Entry;

    /**
     * The one entry a Single section already owns on a site, or null when the
     * section is not a Single (or has none yet).
     *
     * Craft auto-creates a Single's entry when the section is applied from
     * project config; creating a second fails URI validation, so a migration
     * into a Single writes over the existing one.
     */
    public function singleEntry(int $sectionId, int $siteId): ?Entry;

    /**
     * Whether an element has a row on any of these sites.
     *
     * The question block reconciliation asks before deleting a nested entry
     * found on a site the payload never wrote: one nested entry is one row per
     * site it exists on, and deleting the copy on an unpayloaded site takes the
     * payloaded site's content with it.
     *
     * @param list<int> $siteIds
     */
    public function livesOnAnySite(int $elementId, array $siteIds): bool;
    /**
     * Saves an element, returning false rather than throwing when Craft
     * refuses it — callers read `$element->getErrors()` for the reason.
     *
     * `$propagate` defaults to false because this migration writes each site
     * explicitly: letting Craft propagate a save to sites the payload never
     * named is what leaked nested entries onto them.
     */
    public function save(ElementInterface $element, bool $runValidation = true, bool $propagate = false): bool;

    /**
     * Deletes an element. `$hardDelete` skips the soft-delete window, which is
     * what block reconciliation wants: a stale nested entry should not come
     * back from the recycle bin on the next run.
     */
    public function delete(ElementInterface $element, bool $hardDelete = false): void;

    /**
     * The element, as the type the caller asked for.
     *
     * Generic on purpose. Returning a bare ElementInterface made every caller
     * an implicit downcast — `$node->navId`, `$entry->type` — which is a cast
     * an analyser cannot check and a reader cannot see. Naming the class in the
     * signature means the type comes back with it.
     *
     * @template T of ElementInterface
     * @param class-string<T> $class
     * @return T|null
     */
    public function findById(int $id, string $class, ?int $siteId = null): ?ElementInterface;

    /**
     * Invalidates Craft's element caches after a bulk write.
     */
    public function invalidateCaches(): void;
}
