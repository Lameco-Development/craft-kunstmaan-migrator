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
 * The performance pass added what a save costs beyond the row: search
 * indexing, held off for the run and rebuilt once at the end, and the one
 * site lookup that replaces a `findById()` per site.
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
     * The sites an element has a row on — one read, however many sites the
     * install has.
     *
     * The question the unlisted-site wipe asks before it loads anything: an
     * entry written to two of nine sites has nothing to prune on the other
     * seven, and asking each of them with a site-scoped `findById()` was six
     * null-returning element loads per entry, ~11k per run.
     *
     * @return list<int>
     */
    public function siteIdsOf(int $elementId): array;

    /**
     * Saves an element, returning false rather than throwing when Craft
     * refuses it — callers read `$element->getErrors()` for the reason.
     *
     * `$propagate` defaults to false because this migration writes each site
     * explicitly: letting Craft propagate a save to sites the payload never
     * named is what leaked nested entries onto them.
     *
     * While search indexing is deferred (`deferSearchIndexing()`), the save
     * tells Craft not to index; Craft memoises that for the nested-entry and
     * propagation saves it makes inside the same call, so blocks inherit it.
     */
    public function save(ElementInterface $element, bool $runValidation = true, bool $propagate = false): bool;

    /**
     * Stop every save from here until `resumeSearchIndexing()` updating the
     * search index.
     *
     * Craft indexes on every save — inline in a console request, one queue
     * job per save under the web runner — and a nested entry whose field is
     * searchable re-indexes its owner too. During a migration that is
     * keyword extraction for every owner save and every block save, for
     * content nobody searches until the run is done. A run that defers it
     * indexes once, at the end, through `queueSearchIndex()`.
     *
     * A fact about the run, not about one writer: the services each hold
     * their own adapter, so the production adapter keeps this process-wide,
     * the way `UriJobGuard`'s handler is.
     */
    public function deferSearchIndexing(): void;

    /** Index on save again; how many saves went unindexed while deferred. */
    public function resumeSearchIndexing(): int;

    /**
     * The nested entries whose primary owner is one of these, one level down.
     * Trashed, draft and revision rows excluded.
     *
     * The final index stage needs them because Craft indexes a nested entry
     * as an element of its own, not only as keywords on its owner's row.
     *
     * @param list<int> $ownerIds
     * @return list<int>
     */
    public function nestedEntryIds(array $ownerIds): array;

    /**
     * Rebuild the search index of these elements on every site they have —
     * deferred to the queue, no element save. One call is one job.
     *
     * @param class-string<ElementInterface> $elementType
     * @param list<int> $elementIds
     */
    public function queueSearchIndex(string $elementType, array $elementIds): void;

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

    /**
     * The entries of a Structure section, parents before children, one per
     * element — the order a URI recomputation has to walk in, because a
     * Structure entry's URI is its parent's URI plus its own slug.
     *
     * Empty when the handle names no section or not a Structure: a Channel's
     * URI inherits no prefix, so there is nothing to settle.
     *
     * @return iterable<Entry>
     */
    public function structureEntries(string $sectionHandle): iterable;

    /**
     * Recomputes an element's slug and URI on the site it was loaded in and on
     * every other site it exists on, and writes them straight to
     * `elements_sites`: no element save, no nested-entry maintenance, no search
     * indexing — and no queue job, which is the point. Craft's own descendant
     * maintenance after a save goes to the queue at default priority, behind
     * the whole migration chain.
     *
     * Descendants are left alone; a caller walking parents-first reaches them
     * next.
     */
    public function updateSlugAndUri(ElementInterface $element): void;
}
