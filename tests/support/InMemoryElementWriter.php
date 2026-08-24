<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\support;

use Closure;
use craft\base\ElementInterface;
use craft\elements\Entry;
use Lameco\Kunstmaanmigrator\craft\ElementWriter;
use ReflectionClass;

/**
 * The second adapter, and the reason the seam is real rather than
 * hypothetical: it records what the write half asked Craft to do, so a test
 * can assert on the decision instead of on the database afterwards.
 */
final class InMemoryElementWriter implements ElementWriter
{
    /** @var list<array{element: ElementInterface, runValidation: bool, propagate: bool}> */
    public array $saved = [];

    /** @var list<array{element: ElementInterface, hardDelete: bool}> */
    public array $deleted = [];

    /** @var list<array{sectionId: int, typeId: int, siteId: int}> */
    public array $created = [];

    public int $cacheInvalidations = 0;

    /**
     * Every slug/URI recomputation asked for, in call order, with the sites
     * the production adapter would have written — the site the element was
     * loaded in plus every other site it has a row on.
     *
     * @var list<array{id: int, siteIds: list<int>}>
     */
    public array $urisUpdated = [];

    /** @var array<string, list<Entry>> section handle => entries, parents first */
    private array $structures = [];

    /**
     * Craft stamps an id onto a new element as it saves it, and callers read
     * that id back to wire relationships. So does this — otherwise a pass that
     * saves and then links builds its map out of nulls.
     */
    public int $nextId = 900;

    /**
     * How a blank entry is built. Craft's constructor boots the application,
     * so the default builds one without it; a test that needs the entry to
     * answer for a field layout or a matrix value hands in its own stub.
     *
     * @var (Closure(int, int, int): Entry)|null sectionId, typeId, siteId
     */
    public ?Closure $entryFactory = null;

    /** @var array<string, ElementInterface> */
    private array $findable = [];

    /** @var array<int, Entry> keyed by section id */
    private array $singles = [];

    /** @var array<int, array<int, true>> element id => site ids it has a row on */
    private array $sitesOf = [];

    /** Elements this writer should refuse to save, by spl_object_id. */
    private array $refuse = [];

    public function createEntry(int $sectionId, int $typeId, int $siteId): Entry
    {
        $this->created[] = ['sectionId' => $sectionId, 'typeId' => $typeId, 'siteId' => $siteId];

        if ($this->entryFactory !== null) {
            return ($this->entryFactory)($sectionId, $typeId, $siteId);
        }

        /** @var Entry $entry */
        $entry = (new ReflectionClass(Entry::class))->newInstanceWithoutConstructor();
        $entry->sectionId = $sectionId;
        $entry->typeId = $typeId;
        $entry->siteId = $siteId;

        return $entry;
    }

    public function singleEntry(int $sectionId, int $siteId): ?Entry
    {
        return $this->singles[$sectionId] ?? null;
    }

    public function livesOnAnySite(int $elementId, array $siteIds): bool
    {
        foreach ($siteIds as $siteId) {
            if (isset($this->sitesOf[$elementId][$siteId])) {
                return true;
            }
        }

        return false;
    }

    public function save(ElementInterface $element, bool $runValidation = true, bool $propagate = false): bool
    {
        if (isset($this->refuse[spl_object_id($element)])) {
            return false;
        }

        if ($element->id === null) {
            $element->id = $this->nextId++;
        }

        $this->findable[$this->key((int) $element->id, null)] ??= $element;

        if ($element->siteId !== null) {
            $this->sitesOf[(int) $element->id][(int) $element->siteId] = true;
        }

        $this->saved[] = [
            'element' => $element,
            'runValidation' => $runValidation,
            'propagate' => $propagate,
        ];

        return true;
    }

    public function delete(ElementInterface $element, bool $hardDelete = false): void
    {
        $this->deleted[] = ['element' => $element, 'hardDelete' => $hardDelete];
    }

    /**
     * @template T of ElementInterface
     * @param class-string<T> $class
     * @return T|null
     */
    public function findById(int $id, string $class, ?int $siteId = null): ?ElementInterface
    {
        return $this->findable[$this->key($id, $siteId)] ?? $this->findable[$this->key($id, null)] ?? null;
    }

    public function invalidateCaches(): void
    {
        $this->cacheInvalidations++;
    }

    public function structureEntries(string $sectionHandle): iterable
    {
        return $this->structures[$sectionHandle] ?? [];
    }

    public function updateSlugAndUri(ElementInterface $element): void
    {
        $siteIds = array_keys($this->sitesOf[(int) $element->id] ?? []);

        if ($element->siteId !== null && !in_array((int) $element->siteId, $siteIds, true)) {
            array_unshift($siteIds, (int) $element->siteId);
        }

        $this->urisUpdated[] = ['id' => (int) $element->id, 'siteIds' => $siteIds];
    }

    /**
     * What a Structure section holds, in the order the production adapter
     * hands it out: parents before children.
     *
     * @param list<Entry> $entries
     */
    public function willWalk(string $sectionHandle, array $entries): void
    {
        $this->structures[$sectionHandle] = $entries;
    }

    public function willFind(int $id, ElementInterface $element, ?int $siteId = null): void
    {
        $this->findable[$this->key($id, $siteId)] = $element;

        if ($siteId !== null) {
            $this->sitesOf[$id][$siteId] = true;
        }
    }

    /**
     * A site-scoped lookup answers only for the sites it was told about. The
     * fallback to the unscoped element is what most tests want; a test about
     * propagation needs the opposite — a site the entry was never written to
     * must come back empty.
     */
    public function willFindOnlyOnKnownSites(int $id): void
    {
        unset($this->findable[$this->key($id, null)]);
    }

    public function willFindSingle(int $sectionId, Entry $entry): void
    {
        $this->singles[$sectionId] = $entry;
    }

    /** @param list<int> $siteIds */
    public function willLiveOn(int $elementId, array $siteIds): void
    {
        foreach ($siteIds as $siteId) {
            $this->sitesOf[$elementId][$siteId] = true;
        }
    }

    public function willRefuse(ElementInterface $element): void
    {
        $this->refuse[spl_object_id($element)] = true;
    }

    /** @return list<int> ids of the elements deleted, in call order */
    public function deletedIds(): array
    {
        return array_map(static fn(array $row): int => (int) $row['element']->id, $this->deleted);
    }

    /** @return list<int> ids of the elements saved, in call order */
    public function savedIds(): array
    {
        return array_map(static fn(array $row): int => (int) $row['element']->id, $this->saved);
    }

    private function key(int $id, ?int $siteId): string
    {
        return $id . ':' . ($siteId ?? '*');
    }
}
