<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\load;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Entry;
use craft\fields\Matrix;
use Lameco\Kunstmaanmigrator\craft\ElementWriter;
use Lameco\Kunstmaanmigrator\sites\SiteMap;

/**
 * Which nested entries survive a save, on which sites, and which ids carry to the next run.
 *
 * One instance per `saveEntryForSites()` call. It starts from the ids the previous run
 * recorded in the state row's `meta.blockIds` and ends with the map the next run reads —
 * `tracked()` — so the service persists what this module decided rather than assembling it
 * at seven points of its own. In between, the lifecycle per site is: `thread()` the payload
 * before it is written, `reconcile()` the site if it may hold blocks no run tracked, save,
 * `record()` the ids Craft assigned. `prune()` runs once, for the sites the payload never
 * named.
 *
 * The map is nested per site: a Matrix field with `propagationMethod: none` keeps separate
 * nested entries per site, and a flat sourceRef→id map collapses across them so only the
 * last site written survives — which broke the deferred-relation fix-up on every other site.
 *
 * Craft's own pruning (`NestedElementManager::deleteOtherNestedElements()`) is scoped to the
 * owner's site and runs only inside a save, so it reaches exactly the sites this module saves
 * with a payload. Everything else on this module's delete list is what that leaves behind.
 * Both block-bloat defects AUDIT measured — 28,108 nested entries written for 6,285 compiled,
 * and the ghost blocks a propagated primary save left on every secondary site — lived here.
 */
final class BlockIdentity
{
    /**
     * @var array<string, array<string, string>> site handle → sourceRef → nested entry id
     */
    private array $tracked;

    /**
     * Where each ref sat in the payload `thread()` last saw for a site, so `record()` can pair
     * the ids Craft assigned by position: Craft persists blocks in the order handed to
     * `setFieldValues()`, and `thread()` rekeys without reordering.
     *
     * @var array<string, array<string, list<array{ref: ?string, children: array}>>> site handle → field handle → positions
     */
    private array $positions = [];

    /**
     * @param array<string, array<string, string|int>> $tracked site handle → sourceRef → id, from the previous run
     */
    public function __construct(
        private readonly ElementWriter $elements,
        array $tracked = [],
    ) {
        $this->tracked = [];

        foreach ($tracked as $siteHandle => $ids) {
            foreach ((array) $ids as $sourceRef => $id) {
                $this->tracked[(string) $siteHandle][(string) $sourceRef] = (string) $id;
            }
        }
    }

    /**
     * From a state row's meta, however it was stored.
     *
     * `blockIds` (or the older `blockUids`) is the per-site map. A row written before the map
     * was nested holds the flat sourceRef→id shape; it is promoted to the primary site's bucket
     * so a re-run against it still threads the primary's blocks at minimum.
     */
    public static function fromMeta(ElementWriter $elements, mixed $meta, string $primaryHandle): self
    {
        if (is_string($meta)) {
            $meta = json_decode($meta, true);
        }

        $raw = is_array($meta) ? (array) ($meta['blockIds'] ?? $meta['blockUids'] ?? []) : [];

        if ($raw === []) {
            return new self($elements);
        }

        $first = reset($raw);

        return new self($elements, is_array($first) ? $raw : [$primaryHandle => $raw]);
    }

    /**
     * The map to persist for the next run; empty when no site recorded a block.
     *
     * @return array<string, array<string, string>>
     */
    public function tracked(): array
    {
        return array_filter($this->tracked);
    }

    /**
     * Key every block this site already owns by its id, so Craft updates it in place instead
     * of creating a second one, and remember where each ref sits for `record()`.
     *
     * Reads the refs off the payload as handed in — before anything strips them.
     *
     * @param array<string, mixed> $fieldValues
     * @return array<string, mixed>
     */
    public function thread(string $siteHandle, array $fieldValues): array
    {
        $this->positions[$siteHandle] = self::positions($fieldValues);
        $ids = $this->tracked[$siteHandle] ?? [];

        foreach ($fieldValues as $handle => $payload) {
            if (is_array($payload) && $payload !== [] && self::looksLikeMatrixPayload($payload)) {
                $fieldValues[$handle] = self::rekey($payload, $ids);
            }
        }

        return $fieldValues;
    }

    /**
     * After the site's save: the ids Craft assigned, paired with the refs `thread()` saw.
     *
     * Craft 5's Matrix indexes existing nested entries by element id in the flat-array
     * payload shape, so element ids are what the next run keys by — not uids.
     */
    public function record(string $siteHandle, ElementInterface $saved): void
    {
        $this->tracked[$siteHandle] = array_merge(
            $this->tracked[$siteHandle] ?? [],
            self::collect($saved, $this->positions[$siteHandle] ?? []),
        );
    }

    /**
     * Delete the blocks on this site that no run tracked, for the Matrix fields the payload
     * is about to write. Fields the payload does not touch are left alone.
     *
     * A guard, not the main path: a new entry's secondary rows are created bare, so on a
     * first run there is nothing here to find. It still earns its place on a site newly
     * added to an existing entry — Craft duplicates the primary's blocks onto every site it
     * propagates to for the first time, and emptying the primary to prevent that would delete
     * the primary's own blocks — and on a re-run whose state row predates the per-site map.
     *
     * @param array<string, mixed> $fieldValues this site's payload
     */
    public function reconcile(Entry $localised, string $siteHandle, array $fieldValues): void
    {
        $keep = [];

        foreach ($this->tracked[$siteHandle] ?? [] as $id) {
            if ((int) $id > 0) {
                $keep[(int) $id] = true;
            }
        }

        foreach ($fieldValues as $handle => $payload) {
            if (!is_array($payload) || $payload === [] || !self::looksLikeMatrixPayload($payload)) {
                continue;
            }

            foreach ($this->blocksOn($localised, (string) $handle) as $block) {
                if (empty($block->id) || isset($keep[(int) $block->id])) {
                    continue;
                }

                $this->delete($block, 'reconcile');
            }
        }
    }

    /**
     * Delete the nested entries on sites this payload never named.
     *
     * Every Craft site, not only this environment's: propagation does not stop at the
     * mapping, so neither can the pruning. A site with no payload has no content by
     * definition, so anything found there is a propagation artefact — unless a payloaded
     * site shares it. A nested entry is one element across the sites it exists on, so the
     * copy reachable from an unpayloaded site is the same row the payloaded site renders,
     * and deleting it there takes the content with it: measured at 294 of 825 pages losing
     * their whole Page Builder on a clean run.
     *
     * One site lookup per entry, then a localised load only for the sites that have a row:
     * an entry written to two of nine sites has nothing on the other seven, and asking each
     * of them with a site-scoped `findById()` was six null-returning element loads per
     * entry, ~11k per run on the reference corpus.
     *
     * @param array<string, mixed> $perSite the payload's per-site data, keyed by site handle
     */
    public function prune(Entry $entry, array $perSite, SiteMap $sites): void
    {
        $keep = [];

        foreach (array_keys($perSite) as $handle) {
            $siteId = $sites->siteIdForHandle((string) $handle);

            if ($siteId !== null) {
                $keep[$siteId] = true;
            }
        }

        // No payload means no "keep" set; bailing out beats treating every site as prunable.
        if ($keep === [] || $entry->id === null) {
            return;
        }

        foreach ($this->elements->siteIdsOf((int) $entry->id) as $siteId) {
            if (isset($keep[$siteId])) {
                continue;
            }

            $localised = $this->elements->findById((int) $entry->id, Entry::class, $siteId);

            if ($localised === null) {
                continue;
            }

            foreach ($this->ownedBlocksOn($localised) as $block) {
                if ($this->elements->livesOnAnySite((int) $block->id, array_keys($keep))) {
                    continue;
                }

                $this->delete($block, 'prune');
            }
        }
    }

    /**
     * The same field values with every Matrix payload emptied.
     *
     * A new element always propagates on its first save, and a site row created by
     * propagation receives every field value the primary holds — Matrix included, which
     * `NestedElementManager::duplicateNestedElements()` then copies as fresh nested entries.
     * Saving the primary bare first gives propagation nothing to copy; each site then writes
     * its own blocks with `propagate = false`, on an owner that is no longer new.
     *
     * @param array<string, mixed> $fieldValues
     * @return array<string, mixed>
     */
    public static function withoutBlocks(array $fieldValues): array
    {
        foreach ($fieldValues as $handle => $payload) {
            if (is_array($payload) && $payload !== [] && self::looksLikeMatrixPayload($payload)) {
                $fieldValues[$handle] = [];
            }
        }

        return $fieldValues;
    }


    /**
     * A Craft Matrix payload: an array whose values are arrays that each carry a `type`.
     *
     * @param array<mixed> $payload
     */
    public static function looksLikeMatrixPayload(array $payload): bool
    {
        $first = reset($payload);

        return is_array($first) && isset($first['type']);
    }

    /**
     * Rewrite a Matrix payload's `new{n}` keys to the tracked id where the block's
     * `fields._sourcePartRef` is known, order preserved. A block's own nested Matrixes are
     * threaded first, so reusing a parent in place reuses its children too rather than
     * rebuilding them underneath it.
     *
     * @param array<string|int, mixed> $payload
     * @param array<string, string> $ids sourceRef → id
     * @return array<string|int, mixed>
     */
    private static function rekey(array $payload, array $ids): array
    {
        if ($ids === []) {
            return $payload;
        }

        $rewritten = [];

        foreach ($payload as $key => $block) {
            if (is_array($block) && is_array($block['fields'] ?? null)) {
                foreach ($block['fields'] as $nestedHandle => $nested) {
                    if (is_array($nested) && $nested !== [] && self::looksLikeMatrixPayload($nested)) {
                        $block['fields'][$nestedHandle] = self::rekey($nested, $ids);
                    }
                }
            }

            $sourceRef = $block['fields']['_sourcePartRef'] ?? null;
            $rewritten[$sourceRef !== null && isset($ids[$sourceRef]) ? $ids[$sourceRef] : $key] = $block;
        }

        return $rewritten;
    }

    /**
     * `_sourcePartRef` per Matrix field by block position, nested Matrixes as children:
     * a block whose children cannot be threaded has them rebuilt under it on every re-run.
     *
     * @param array<string, mixed> $fieldValues
     * @return array<string, list<array{ref: ?string, children: array}>>
     */
    private static function positions(array $fieldValues): array
    {
        $positions = [];

        foreach ($fieldValues as $handle => $value) {
            if (!is_array($value) || $value === [] || !self::looksLikeMatrixPayload($value)) {
                continue;
            }

            $refs = [];

            foreach (array_values($value) as $block) {
                $fields = is_array($block) ? (array) ($block['fields'] ?? []) : [];
                $ref = $fields['_sourcePartRef'] ?? null;
                $refs[] = [
                    'ref' => $ref !== null ? (string) $ref : null,
                    'children' => self::positions($fields),
                ];
            }

            $positions[(string) $handle] = $refs;
        }

        return $positions;
    }

    /**
     * Pair just-saved blocks with the refs recorded at their position, recursing into the
     * children of each block.
     *
     * @param array<string, list<array{ref: ?string, children: array}>> $positions
     * @return array<string, string> sourceRef → id
     */
    private static function collect(ElementInterface $owner, array $positions): array
    {
        $map = [];

        foreach ($positions as $fieldHandle => $refs) {
            try {
                $blocks = $owner->getFieldValue($fieldHandle);

                if (!is_object($blocks) || !method_exists($blocks, 'all')) {
                    continue;
                }

                foreach (array_values($blocks->all()) as $idx => $block) {
                    $position = $refs[$idx] ?? null;

                    if (!is_array($position)) {
                        continue;
                    }

                    if ($position['ref'] !== null && $block->id) {
                        $map[$position['ref']] = (string) $block->id;
                    }

                    if ($position['children'] !== []) {
                        $map += self::collect($block, $position['children']);
                    }
                }
            } catch (\Throwable) {
                // Not a Matrix field after all; nothing of it to record.
            }
        }

        return $map;
    }

    /**
     * The blocks one field holds on the site the entry was loaded in, enabled or not.
     *
     * @return list<object>
     */
    private function blocksOn(Entry $localised, string $fieldHandle): array
    {
        try {
            $value = $localised->getFieldValue($fieldHandle);
        } catch (\Throwable) {
            return [];
        }

        if (!is_object($value) || !method_exists($value, 'all')) {
            return [];
        }

        try {
            // Unscoped, the query answers with blocks of other sites and the delete follows.
            if (method_exists($value, 'siteId')) {
                $value->siteId($localised->siteId);
            }
            if (method_exists($value, 'status')) {
                $value->status(null);
            }

            return array_values(array_filter($value->all(), 'is_object'));
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Every nested entry this entry owns on its own site, across its Matrix fields.
     *
     * Matrix only: an Entries relation field also answers `all()`, and its entries are other
     * people's content. Ownership is re-checked per block as a second line of defence.
     *
     * @return list<Entry>
     */
    private function ownedBlocksOn(Entry $localised): array
    {
        $out = [];

        foreach ($localised->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            if (!$field instanceof Matrix) {
                continue;
            }

            foreach ($this->blocksOn($localised, $field->handle) as $block) {
                if ($block instanceof Entry && $block->id && $block->getPrimaryOwnerId() === $localised->id) {
                    $out[] = $block;
                }
            }
        }

        return $out;
    }

    /**
     * Hard: a stale nested entry must not come back from the recycle bin on the next run.
     * A refused delete is a warning, so the rest of the entry still lands.
     */
    private function delete(ElementInterface $block, string $step): void
    {
        try {
            $this->elements->delete($block, true);
        } catch (\Throwable $e) {
            if (class_exists(Craft::class, false)) {
                Craft::warning(
                    sprintf('BlockIdentity: %s could not delete nested entry %d: %s', $step, (int) $block->id, $e->getMessage()),
                    __METHOD__,
                );
            }
        }
    }
}
