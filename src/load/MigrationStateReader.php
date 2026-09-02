<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\load;

/**
 * Narrow read-only view over the migration state table.
 *
 * Exposes only the lookup paths handlers need (legacy → Craft id / uid / meta)
 * without leaking any write surface from MigrationStateService.
 *
 * Implemented in-place by MigrationStateService — the three methods below
 * already exist there with these exact signatures (per Plan 05-03 Task 1).
 *
 * Per D-11: asset + relation handlers resolve legacy ids through this
 * interface so MigrationStateService's write API (record, updateMeta,
 * forget, runOnce) is not reachable from the handler boundary.
 */
interface MigrationStateReader
{
    /**
     * Returns the Craft numeric id recorded for (source, sourceKey, siteId),
     * or null when no row exists, targetId is null, or targetId is the 0
     * placeholder a fallback path writes for a row that has no real Craft
     * element yet (e.g. a remote video whose embed lookup failed). 0 is
     * never a real element id, so it is treated as unresolved.
     *
     * siteId=null matches a site-agnostic row (flat keyCondition treats
     * `['siteId' => null]` as `siteId IS NULL`).
     */
    public function getTargetId(string $source, string $key, ?int $siteId = null): ?int;

    /**
     * Returns the Craft UID recorded for (source, sourceKey, siteId),
     * or null when no row exists or targetUid is null.
     */
    public function getTargetUid(string $source, string $key, ?int $siteId = null): ?string;

    /**
     * Returns the full row array for (source, sourceKey, siteId), or null
     * when no row exists. Used by handlers that need access to the stored
     * `meta` JSON alongside the target id.
     *
     * @return array<string, mixed>|null
     */
    public function get(string $source, string $key, ?int $siteId = null): ?array;
}
