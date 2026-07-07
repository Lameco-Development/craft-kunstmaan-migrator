<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\payload;

use Craft;
use lameco\kunstmaanmigrator\load\EntryMigrationService;
use lameco\kunstmaanmigrator\load\MigrationStateService;
use RuntimeException;

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
     * @return array{patched: int, orphans: list<array{sourceUid: string, field: string, ref: string, path: list<int|string>}>}
     */
    public function run(): array
    {
        $patched = 0;
        $orphans = [];

        foreach ($this->stateService->entryRows() as $row) {
            $pendingRefs = $this->decodePendingRefs($row['meta'] ?? null);
            if ($pendingRefs === []) {
                continue;
            }

            $source = (string) $row['source'];
            $key = (string) $row['sourceKey'];
            $sourceUid = 'kuma:' . $source . ':' . $key;
            $targetId = $row['targetId'] !== null ? (int) $row['targetId'] : null;

            $remaining = [];
            /** @var array<string, bool> $seenContainers de-dupe key for the multi-ref-per-container warning */
            $seenContainers = [];

            foreach ($pendingRefs as $pending) {
                $field = (string) ($pending['field'] ?? '');
                $site = (string) ($pending['site'] ?? '');
                $ref = (string) ($pending['ref'] ?? '');
                $path = (is_array($pending['path'] ?? null)) ? array_values($pending['path']) : [];

                $resolvedId = $this->refResolver->resolve($ref);

                $ok = $resolvedId !== null
                    && $targetId !== null
                    && $this->applyPatch($targetId, $site, $path, $resolvedId, $sourceUid, $seenContainers);

                if (!$ok) {
                    $remaining[] = $pending;
                    $orphans[] = ['sourceUid' => $sourceUid, 'field' => $field, 'ref' => $ref, 'path' => $path];
                    continue;
                }

                $patched++;
            }

            if (count($remaining) !== count($pendingRefs)) {
                $this->stateService->updateMeta($source, $key, null, ['pendingRefs' => $remaining]);
            }
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
