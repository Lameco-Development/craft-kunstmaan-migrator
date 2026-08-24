<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\craft;

use verbb\navigation\elements\Node as NavNode;

/**
 * The seam at verbb/navigation.
 *
 * The ElementWriter seam took Craft's element writes out of the migration
 * modules, but NavigationMigrationService stayed untestable: seven call sites
 * reached `Navigation::$plugin` statically, so every write path ran into verbb
 * before it reached a save. The element seam removed one static and left
 * another in front of it.
 *
 * Narrower than verbb's own API on purpose. Callers only ever read `->id` off a
 * Nav, so this hands back the id rather than the model — which keeps the port
 * small and means a fake does not have to construct a verbb element.
 */
interface NavigationGateway
{
    /**
     * Whether verbb/navigation is installed and booted.
     *
     * Two conditions the module previously checked separately — Craft's plugin
     * registry, then `Navigation::$plugin` being non-null. They report the same
     * thing to a caller, which is "can this pass run at all".
     */
    public function isAvailable(): bool;

    /**
     * The id of the nav with this handle, or null when no such nav exists —
     * which is a normal outcome the caller reports, not an error.
     */
    public function navIdByHandle(string $handle): ?int;

    /**
     * Hands verbb the nodes it must treat as already-known for the current
     * request. Verbb's node save reads its parent from this registry rather
     * than from the database, so a node saved without it lands at the root.
     *
     * @param list<NavNode> $nodes
     */
    public function registerTempNodes(array $nodes): void;
}
