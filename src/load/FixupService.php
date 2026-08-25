<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\load;

use Craft;

use Lameco\Kunstmaanmigrator\Payload\SourceUid;
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
 * resaveEntryFieldForSite()`/`resaveEntryParentForSite()`). The refs of one
 * entry are patched per (site, top-level field): one read, every resolvable
 * patch applied to that value, one save — an element save is what a patch
 * costs, and the reference corpus had hundreds landing on one home page.
 *
 * A pending ref whose target has no state row at all after a full-corpus
 * pass is not pending, it is unresolvable under this mapping: its page type
 * is declared unmapped, or its node was never compiled. Such a ref moves
 * from `pendingRefs` to `unresolvableRefs` (same entry shape plus a
 * `reason`) so the next pass does not walk it again; it is reported once,
 * grouped by target. Only a caller knows whether the run was full — the
 * console has `narrowed()`, the queue chain knows which environments it
 * queued — so that fact arrives as a parameter, never inferred here.
 * Still-unresolved (narrowed run, target mid-write) or unpatchable refs
 * are kept in `pendingRefs` and reported as orphans — nothing is ever
 * silently dropped.
 *
 * Per-group fail-forward: a `Throwable` raised while re-saving one group
 * (stale site handle, DB deadlock, save failure) is caught and that group's
 * refs alone are folded into `orphans` (with the error message) and kept in
 * `pendingRefs` for the next run — it never aborts the rest of the pass,
 * matching `LoadController::buildLiveReport()`'s per-payload fail-forward
 * convention in pass 1.
 */
final class FixupService
{
    public const REASON_TARGET_NEVER_MIGRATED = 'target was never migrated under this mapping';

    private const TARGET_SAMPLE = 10;
    private const FROM_SAMPLE = 3;

    private readonly RefResolver $refResolver;

    /**
     * One state lookup per distinct target per pass: 206 refs on one entry
     * named three targets.
     *
     * @var array<string, array{id: ?int, recorded: bool}>
     */
    private array $lookups = [];

    public function __construct(
        private readonly MigrationStateService $stateService,
        private readonly EntryMigrationService $entryService,
        /**
         * Handle => Craft site id. Defaults to Craft's own sites; a test hands
         * in a map, which is the only reason this is a parameter.
         *
         * @var (\Closure(string): ?int)|null
         */
        private readonly ?\Closure $siteIdByHandle = null,
    ) {
        $this->refResolver = new RefResolver($stateService);
    }

    /**
     * @param bool $fullCorpus whether the load that precedes this pass walked every
     *   environment and every node. A narrowed run cannot tell a forward reference from a
     *   target that will never exist, so it leaves every unresolved ref pending.
     * @return array{
     *   patched: int,
     *   orphans: list<array{sourceUid: string, field: string, ref: string, path: list<int|string>, error?: string}>,
     *   unresolvable: int,
     *   unresolvableTargets: list<array{ref: string, count: int, reason: string, from: list<string>}>,
     * }
     */
    public function run(bool $fullCorpus = false): array
    {
        $this->lookups = [];
        $patched = 0;
        $orphans = [];
        /** @var array<string, array{count: int, from: array<string, true>}> keyed by target ref */
        $unresolvableTargets = [];
        $unresolvableCount = 0;
        /**
         * `updateMeta()` is a write; deferring every call until after this
         * method's own foreach over `entryRows()` has fully exhausted (and
         * so closed) that generator's streaming cursor removes any doubt
         * about writing mid-iteration over an open read cursor on the same
         * connection.
         *
         * @var list<array{source: string, key: string, meta: array<string, mixed>}>
         */
        $metaUpdates = [];

        foreach ($this->stateService->entryRows() as $row) {
            $meta = self::decodeMeta($row['meta'] ?? null);
            $pendingRefs = self::listAt($meta, 'pendingRefs');
            if ($pendingRefs === []) {
                continue;
            }

            $source = (string) $row['source'];
            $key = (string) $row['sourceKey'];
            $sourceUid = SourceUid::fromStateRow($source, $key);
            $targetId = $row['targetId'] !== null ? (int) $row['targetId'] : null;

            $remaining = [];
            $unresolvable = [];
            /** @var array<string, list<array{pending: array<string, mixed>, id: int}>> keyed by site + top-level field */
            $groups = [];

            foreach ($pendingRefs as $pending) {
                $ref = (string) ($pending['ref'] ?? '');
                $lookup = $this->lookup($ref);

                if ($lookup['id'] === null && $fullCorpus && !$lookup['recorded']) {
                    $unresolvable[] = $pending + ['reason' => self::REASON_TARGET_NEVER_MIGRATED];
                    $unresolvableCount++;
                    $unresolvableTargets[$ref] ??= ['count' => 0, 'from' => []];
                    $unresolvableTargets[$ref]['count']++;
                    $unresolvableTargets[$ref]['from'][$sourceUid] = true;
                    continue;
                }

                if ($lookup['id'] === null || $targetId === null) {
                    $remaining[] = $pending;
                    $orphans[] = self::orphan($sourceUid, $pending);
                    continue;
                }

                $groups[self::groupKey($pending)][] = ['pending' => $pending, 'id' => $lookup['id']];
            }

            /** @var array<string, bool> $seenContainers de-dupe key for the multi-ref-per-container warning */
            $seenContainers = [];

            foreach ($groups as $group) {
                try {
                    $failed = $this->applyGroup($targetId, $sourceUid, $group, $seenContainers);
                } catch (Throwable $e) {
                    // A stale site handle, a DB deadlock, or a save failure
                    // for THIS group must not abort the whole pass — every
                    // other still-fixable ref gets its own chance, and the
                    // pass always finishes and prints its JSON report.
                    $failed = array_map(
                        static fn(array $member): array => ['pending' => $member['pending'], 'error' => $e->getMessage()],
                        $group,
                    );
                }

                $patched += count($group) - count($failed);

                foreach ($failed as $failure) {
                    $remaining[] = $failure['pending'];
                    $orphans[] = self::orphan($sourceUid, $failure['pending'], $failure['error']);
                }
            }

            if (count($remaining) !== count($pendingRefs)) {
                $update = ['pendingRefs' => $remaining];

                if ($unresolvable !== []) {
                    $update['unresolvableRefs'] = array_merge(self::listAt($meta, 'unresolvableRefs'), $unresolvable);
                }

                $metaUpdates[] = ['source' => $source, 'key' => $key, 'meta' => $update];
            }
        }

        foreach ($metaUpdates as $update) {
            $this->stateService->updateMeta($update['source'], $update['key'], null, $update['meta']);
        }

        return [
            'patched' => $patched,
            'orphans' => $orphans,
            'unresolvable' => $unresolvableCount,
            'unresolvableTargets' => self::targetSample($unresolvableTargets),
        ];
    }

    /**
     * @return array{id: ?int, recorded: bool} the target's entry id, and whether the state
     *   table has a row for it at all — a row without a target id is a target mid-write, not
     *   a target that will never exist
     */
    private function lookup(string $ref): array
    {
        if (!isset($this->lookups[$ref])) {
            $id = $this->refResolver->resolve($ref);
            $this->lookups[$ref] = ['id' => $id, 'recorded' => $id !== null || $this->refResolver->isRecorded($ref)];
        }

        return $this->lookups[$ref];
    }

    /**
     * The refs that share one element save: same site, same top-level field. A parent ref
     * (`path === []`) groups by site alone.
     *
     * @param array<string, mixed> $pending
     */
    private static function groupKey(array $pending): string
    {
        $path = self::pathOf($pending);

        return (string) ($pending['site'] ?? '') . "\0" . ($path === [] ? '' : (string) $path[0]);
    }

    /**
     * Apply one group's patches: read the field once, apply every member's mutation to that
     * value, save once — and only when the value actually changed, so a re-run over an
     * already-patched container costs no element save.
     *
     * @param list<array{pending: array<string, mixed>, id: int}> $group
     * @param array<string, bool> $seenContainers
     * @return list<array{pending: array<string, mixed>, error: ?string}> the members that did not land
     */
    private function applyGroup(int $entryId, string $sourceUid, array $group, array &$seenContainers): array
    {
        $site = (string) ($group[0]['pending']['site'] ?? '');
        $siteId = $this->siteIdFor($site);

        if ($siteId === null) {
            return self::allFailed($group, null);
        }

        $path = self::pathOf($group[0]['pending']);

        if ($path === []) {
            return $this->applyParentGroup($entryId, $siteId, $sourceUid, $site, $group, $seenContainers);
        }

        $topField = (string) $path[0];
        $current = $this->entryService->readEntryFieldValueForSite($entryId, $siteId, $topField) ?? [];
        $tree = [$topField => $current];
        $mutated = $tree;
        $applied = [];
        $failed = [];

        foreach ($group as $member) {
            $pending = $member['pending'];
            $memberPath = self::pathOf($pending);
            $this->warnOnSharedContainer($sourceUid, $site, $memberPath, $seenContainers);

            try {
                $mutated = ($pending['kind'] ?? null) === 'link'
                    ? self::setAtPath($mutated, $memberPath, self::linkValue($member['id'], $siteId, $pending))
                    : self::appendAtPath($mutated, $memberPath, $member['id']);
                $applied[] = $member;
            } catch (RuntimeException $e) {
                Craft::warning('FixupService: ' . $e->getMessage(), 'kunstmaan-migrator');
                $failed[] = ['pending' => $pending, 'error' => null];
            }
        }

        if ($applied === [] || $mutated === $tree) {
            return $failed;
        }

        if (!$this->entryService->resaveEntryFieldForSite($entryId, $siteId, $topField, (array) ($mutated[$topField] ?? []))) {
            return array_merge($failed, self::allFailed($applied, null));
        }

        return $failed;
    }

    /**
     * A parent is one link per site, written through its own save path; a Link-field ref
     * with no path has nowhere to go.
     *
     * @param list<array{pending: array<string, mixed>, id: int}> $group
     * @param array<string, bool> $seenContainers
     * @return list<array{pending: array<string, mixed>, error: ?string}>
     */
    private function applyParentGroup(int $entryId, int $siteId, string $sourceUid, string $site, array $group, array &$seenContainers): array
    {
        $failed = [];

        foreach ($group as $member) {
            $this->warnOnSharedContainer($sourceUid, $site, [], $seenContainers);

            $ok = ($member['pending']['kind'] ?? null) !== 'link'
                && $this->entryService->resaveEntryParentForSite($entryId, $siteId, $member['id']);

            if (!$ok) {
                $failed[] = ['pending' => $member['pending'], 'error' => null];
            }
        }

        return $failed;
    }

    /**
     * @param list<array{pending: array<string, mixed>, id: int}> $members
     * @return list<array{pending: array<string, mixed>, error: ?string}>
     */
    private static function allFailed(array $members, ?string $error): array
    {
        return array_map(
            static fn(array $member): array => ['pending' => $member['pending'], 'error' => $error],
            $members,
        );
    }

    /**
     * @param list<int|string> $path
     * @param array<string, bool> $seenContainers
     */
    private function warnOnSharedContainer(string $sourceUid, string $site, array $path, array &$seenContainers): void
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
    }

    /**
     * The fixup pass runs once over the whole corpus, after every environment,
     * so it has no site map to hand — a deferred ref names its site by handle
     * and Craft answers for the id.
     */
    private function siteIdFor(string $handle): ?int
    {
        if ($this->siteIdByHandle !== null) {
            return ($this->siteIdByHandle)($handle);
        }

        $id = Craft::$app->getSites()->getSiteByHandle($handle)?->id;

        return $id === null ? null : (int) $id;
    }

    /**
     * A resolved entry link as a Link field holds it: one value at its own key, carrying the
     * `label` / `target` the payload had.
     *
     * @param array<string, mixed> $pending
     * @return array<string, string>
     */
    private static function linkValue(int $resolvedId, int $siteId, array $pending): array
    {
        $link = ['type' => 'entry', 'value' => sprintf('{entry:%d@%d:url}', $resolvedId, $siteId)];
        $extra = is_array($pending['link'] ?? null) ? $pending['link'] : [];

        foreach (['label', 'target'] as $key) {
            if (isset($extra[$key]) && is_string($extra[$key]) && $extra[$key] !== '') {
                $link[$key] = $extra[$key];
            }
        }

        return $link;
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
     * Pure path-navigation: descend `$path` from `$node`'s root, resolving
     * each segment to the ACTUAL storage key at that level. A string segment
     * is a literal array key (a field handle, or the literal `'fields'`); an
     * integer segment is a 0-based POSITION among the current level's
     * entries, not a literal key — Matrix blocks serialize keyed by their
     * real element id (`craft\fields\Matrix::serializeValue()`), so `path`
     * records position, matching the same ordering contract
     * `EntryMigrationService::collectBlockUidsByPosition()` already relies on
     * elsewhere in this class. The LAST segment addresses the container
     * itself: append `$resolvedId` to it — unless it is already there, in
     * which case the node comes back untouched and no save follows.
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
            if (!in_array($resolvedId, $container, true) && !in_array((string) $resolvedId, $container, true)) {
                $container[] = $resolvedId;
            }
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
     * @param array<string, mixed> $pending
     * @return list<int|string>
     */
    private static function pathOf(array $pending): array
    {
        return is_array($pending['path'] ?? null) ? array_values($pending['path']) : [];
    }

    /**
     * @param array<string, mixed> $pending
     * @return array{sourceUid: string, field: string, ref: string, path: list<int|string>, error?: string}
     */
    private static function orphan(string $sourceUid, array $pending, ?string $error = null): array
    {
        $orphan = [
            'sourceUid' => $sourceUid,
            'field' => (string) ($pending['field'] ?? ''),
            'ref' => (string) ($pending['ref'] ?? ''),
            'path' => self::pathOf($pending),
        ];

        if ($error !== null) {
            $orphan['error'] = $error;
        }

        return $orphan;
    }

    /**
     * The targets nothing will ever resolve, most-referenced first — three targets explain
     * two hundred refs, and a summary should say so in three lines.
     *
     * @param array<string, array{count: int, from: array<string, true>}> $targets
     * @return list<array{ref: string, count: int, reason: string, from: list<string>}>
     */
    private static function targetSample(array $targets): array
    {
        uasort($targets, static fn(array $a, array $b): int => $b['count'] <=> $a['count']);
        $sample = [];

        foreach (array_slice($targets, 0, self::TARGET_SAMPLE, true) as $ref => $target) {
            $sample[] = [
                'ref' => (string) $ref,
                'count' => $target['count'],
                'reason' => self::REASON_TARGET_NEVER_MIGRATED,
                'from' => array_slice(array_keys($target['from']), 0, self::FROM_SAMPLE),
            ];
        }

        return $sample;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeMeta(mixed $rawMeta): array
    {
        $meta = $rawMeta;
        if (is_string($meta)) {
            $decoded = json_decode($meta, true);
            $meta = is_array($decoded) ? $decoded : null;
        }

        return is_array($meta) ? $meta : [];
    }

    /**
     * @param array<string, mixed> $meta
     * @return list<array<string, mixed>>
     */
    private static function listAt(array $meta, string $key): array
    {
        $list = $meta[$key] ?? [];

        return is_array($list) ? array_values(array_filter($list, 'is_array')) : [];
    }
}
