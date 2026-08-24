<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\payload;

use Craft;

use Lameco\KumaCompile\Payload\SourceUid;
use Lameco\Kunstmaanmigrator\load\EntryMigrationService;
use Lameco\Kunstmaanmigrator\load\MigrationStateService;
use RuntimeException;
use Throwable;

/**
 * Second-pass fixup (`load/fixup`, docs/loader-contract.md "Two-pass `_ref`
 * resolution semantics"). Drains state meta `pendingRefs` left behind by
 * `PayloadEntrySaver` once every payload in a batch has been through pass 1
 * — the target of a forward/cyclic reference may now be resolvable even
 * though it wasn't at save time.
 *
 * For each still-pending ref: re-resolve via `RefResolver`; if resolved,
 * locate the container the ref belongs to via its recorded `path` (see
 * `docs/loader-contract.md` "pendingRefs entry shape") and append the
 * resolved id, then re-save through `EntryMigrationService`'s per-site save
 * path so Matrix block ids persist (see `EntryMigrationService::
 * resaveEntryFieldForSite()`/`resaveEntryParentForSite()`). Still-unresolved
 * or unpatchable refs are kept in `pendingRefs` and reported as orphans —
 * nothing is ever silently dropped.
 *
 * Per-ref fail-forward: a `Throwable` raised while resolving or re-saving
 * one pending ref (stale site handle, DB deadlock, save failure) is caught
 * and that ref alone is folded into `orphans` (with its error message) and
 * kept in `pendingRefs` for the next run — it never aborts the rest of the
 * pass, matching `LoadController::buildLiveReport()`'s per-payload
 * fail-forward convention in pass 1.
 */
final class FixupService
{
    private readonly RefResolver $refResolver;

    public function __construct(
        private readonly MigrationStateService $stateService,
        private readonly EntryMigrationService $entryService,
    ) {
        $this->refResolver = new RefResolver($stateService);
    }

    /**
     * @return array{patched: int, orphans: list<array{sourceUid: string, field: string, ref: string, path: list<int|string>, error?: string}>}
     */
    public function run(): array
    {
        $patched = 0;
        $orphans = [];
        /**
         * `updateMeta()` is a write; deferring every call until after this
         * method's own foreach over `entryRows()` has fully exhausted (and
         * so closed) that generator's streaming cursor removes any doubt
         * about writing mid-iteration over an open read cursor on the same
         * connection.
         *
         * @var list<array{source: string, key: string, remaining: list<mixed>}>
         */
        $metaUpdates = [];

        foreach ($this->stateService->entryRows() as $row) {
            $pendingRefs = $this->decodePendingRefs($row['meta'] ?? null);
            if ($pendingRefs === []) {
                continue;
            }

            $source = (string) $row['source'];
            $key = (string) $row['sourceKey'];
            $sourceUid = SourceUid::fromStateRow($source, $key);
            $targetId = $row['targetId'] !== null ? (int) $row['targetId'] : null;

            $remaining = [];
            /** @var array<string, bool> $seenContainers de-dupe key for the multi-ref-per-container warning */
            $seenContainers = [];

            foreach ($pendingRefs as $pending) {
                $field = (string) ($pending['field'] ?? '');
                $site = (string) ($pending['site'] ?? '');
                $ref = (string) ($pending['ref'] ?? '');
                $path = (is_array($pending['path'] ?? null)) ? array_values($pending['path']) : [];

                $error = null;
                try {
                    $resolvedId = $this->refResolver->resolve($ref);

                    if (($pending['kind'] ?? null) === 'link') {
                        $ok = $resolvedId !== null
                            && $targetId !== null
                            && $this->patchLinkField(
                                $targetId,
                                $site,
                                $path,
                                $resolvedId,
                                is_array($pending['link'] ?? null) ? $pending['link'] : [],
                            );
                    } else {
                        $ok = $resolvedId !== null
                            && $targetId !== null
                            && $this->applyPatch($targetId, $site, $path, $resolvedId, $sourceUid, $seenContainers);
                    }
                } catch (Throwable $e) {
                    // A stale site handle, a DB deadlock, or a save failure
                    // for THIS ref must not abort the whole pass — every
                    // other still-fixable ref gets its own chance, and the
                    // pass always finishes and prints its JSON report.
                    $ok = false;
                    $error = $e->getMessage();
                }

                if (!$ok) {
                    $remaining[] = $pending;
                    $orphan = ['sourceUid' => $sourceUid, 'field' => $field, 'ref' => $ref, 'path' => $path];
                    if ($error !== null) {
                        $orphan['error'] = $error;
                    }
                    $orphans[] = $orphan;
                    continue;
                }

                $patched++;
            }

            if (count($remaining) !== count($pendingRefs)) {
                $metaUpdates[] = ['source' => $source, 'key' => $key, 'remaining' => $remaining];
            }
        }

        foreach ($metaUpdates as $update) {
            $this->stateService->updateMeta($update['source'], $update['key'], null, ['pendingRefs' => $update['remaining']]);
        }

        return ['patched' => $patched, 'orphans' => $orphans];
    }

    /**
     * @param list<int|string> $path
     * @param array<string, bool> $seenContainers
     */
    private function applyPatch(int $targetId, string $site, array $path, int $resolvedId, string $sourceUid, array &$seenContainers): bool
    {
        $containerKey = $site . "\0" . implode('.', $path);
        if (isset($seenContainers[$containerKey])) {
            // Task 4's `path` records the container, not the intra-list index
            // (docs/loader-contract.md). When more than one ref in the same
            // batch shares a container, each is still patched in full (never
            // dropped) as it resolves — but the relative order among them
            // within that container is not guaranteed to match the original
            // payload's ordering. Surfacing this so operators can spot it.
            Craft::warning(sprintf(
                'FixupService: multiple simultaneously-unresolved refs share one container (entry %s, site=%s, path=%s)'
                . ' — each is applied as it resolves; relative order within the container is not preserved.',
                $sourceUid,
                $site,
                $path === [] ? '(parent)' : implode('.', $path),
            ), 'kunstmaan-migrator');
        }
        $seenContainers[$containerKey] = true;

        if ($path === []) {
            return $this->entryService->resaveEntryParentForSite($targetId, $site, $resolvedId);
        }

        return $this->patchNestedField($targetId, $site, $path, $resolvedId);
    }

    /**
     * Write a resolved entry link at the slot the payload named.
     *
     * A relation appends an id to a list; a Link field holds one value at its own key, so the
     * path addresses the slot itself and the value replaces whatever is there.
     *
     * @param list<int|string> $path non-empty; $path[0] is the top-level field handle
     * @param array<string, mixed> $extra `label` / `target` the payload carried
     */
    private function patchLinkField(int $targetId, string $site, array $path, int $resolvedId, array $extra): bool
    {
        if ($path === []) {
            return false;
        }

        $siteId = Craft::$app->getSites()->getSiteByHandle($site)?->id;

        if ($siteId === null) {
            return false;
        }

        $link = ['type' => 'entry', 'value' => sprintf('{entry:%d@%d:url}', $resolvedId, $siteId)];

        foreach (['label', 'target'] as $key) {
            if (isset($extra[$key]) && is_string($extra[$key]) && $extra[$key] !== '') {
                $link[$key] = $extra[$key];
            }
        }

        $topField = (string) $path[0];

        // A Link field at the top level of the entry is the whole value, not a path into one.
        if (count($path) === 1) {
            return $this->entryService->resaveEntryFieldForSite($targetId, $site, $topField, $link);
        }

        $current = $this->entryService->readEntryFieldValueForSite($targetId, $site, $topField) ?? [];

        try {
            $mutated = self::setAtPath([$topField => $current], $path, $link);
        } catch (RuntimeException $e) {
            Craft::warning('FixupService: ' . $e->getMessage(), 'kunstmaan-migrator');

            return false;
        }

        return $this->entryService->resaveEntryFieldForSite($targetId, $site, $topField, (array) ($mutated[$topField] ?? []));
    }

    /**
     * As `appendAtPath`, but the last segment names the slot to overwrite rather than a list to
     * push onto.
     *
     * @param array<array-key, mixed> $node
     * @param list<int|string> $path non-empty
     * @param array<string, mixed> $value
     * @return array<array-key, mixed>
     */
    private static function setAtPath(array $node, array $path, array $value): array
    {
        $segment = $path[0];
        $key = self::realKeyForSegment($node, $segment);

        if (count($path) === 1) {
            $node[$key] = $value;

            return $node;
        }

        if (!isset($node[$key]) || !is_array($node[$key])) {
            throw new RuntimeException(sprintf('path segment "%s" not found or not an array.', (string) $segment));
        }

        $node[$key] = self::setAtPath($node[$key], array_slice($path, 1), $value);

        return $node;
    }

    /**
     * @param list<int|string> $path non-empty; $path[0] is the top-level field handle.
     */
    private function patchNestedField(int $targetId, string $site, array $path, int $resolvedId): bool
    {
        $topField = (string) $path[0];
        $current = $this->entryService->readEntryFieldValueForSite($targetId, $site, $topField) ?? [];

        try {
            $mutated = self::appendAtPath([$topField => $current], $path, $resolvedId);
        } catch (RuntimeException $e) {
            Craft::warning('FixupService: ' . $e->getMessage(), 'kunstmaan-migrator');

            return false;
        }

        return $this->entryService->resaveEntryFieldForSite($targetId, $site, $topField, (array) ($mutated[$topField] ?? []));
    }

    /**
     * Pure path-navigation: descend `$path` from `$node`'s root, resolving
     * each segment to the ACTUAL storage key at that level. A string segment
     * is a literal array key (a field handle, or the literal `'fields'`); an
     * integer segment is a 0-based POSITION among the current level's
     * entries, not a literal key — Matrix blocks serialize keyed by their
     * real element id (`craft\fields\Matrix::serializeValue()`), so `path`
     * records position, matching the same ordering contract
     * `EntryMigrationService::collectBlockUidsByPosition()` already relies on
     * elsewhere in this class. The LAST segment addresses the container
     * itself: append `$resolvedId` to it.
     *
     * @param array<array-key, mixed> $node
     * @param list<int|string> $path non-empty
     * @return array<array-key, mixed> $node with the id appended at $path
     */
    private static function appendAtPath(array $node, array $path, int $resolvedId): array
    {
        $segment = $path[0];
        $key = self::realKeyForSegment($node, $segment);

        if (count($path) === 1) {
            $container = $node[$key] ?? [];
            if (!is_array($container)) {
                throw new RuntimeException(sprintf('path segment "%s" is not a list container.', (string) $segment));
            }
            $container[] = $resolvedId;
            $node[$key] = $container;

            return $node;
        }

        if (!isset($node[$key]) || !is_array($node[$key])) {
            throw new RuntimeException(sprintf('path segment "%s" not found or not an array.', (string) $segment));
        }
        $node[$key] = self::appendAtPath($node[$key], array_slice($path, 1), $resolvedId);

        return $node;
    }

    /**
     * @param array<array-key, mixed> $node
     */
    private static function realKeyForSegment(array $node, int|string $segment): int|string
    {
        if (is_string($segment)) {
            return $segment;
        }

        $keys = array_keys($node);
        if (!array_key_exists($segment, $keys)) {
            throw new RuntimeException(sprintf('path position %d out of range (%d items).', $segment, count($keys)));
        }

        return $keys[$segment];
    }

    /**
     * @return list<array{field: string, site: string, ref: string, path: list<int|string>}>
     */
    private function decodePendingRefs(mixed $rawMeta): array
    {
        $meta = $rawMeta;
        if (is_string($meta)) {
            $decoded = json_decode($meta, true);
            $meta = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($meta)) {
            return [];
        }

        $pending = $meta['pendingRefs'] ?? [];

        return is_array($pending) ? array_values($pending) : [];
    }
}
