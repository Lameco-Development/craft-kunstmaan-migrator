<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\support;

use lameco\kunstmaanmigrator\craft\NavigationGateway;
use verbb\navigation\elements\Node as NavNode;

/**
 * The second adapter. Records what the module asked verbb for, and answers
 * with whatever the test set up.
 */
final class InMemoryNavigationGateway implements NavigationGateway
{
    /** @var list<list<NavNode>> */
    public array $registeredTempNodes = [];

    /** @var list<string> */
    public array $handlesLookedUp = [];

    /** @param array<string, int> $navIdsByHandle */
    public function __construct(
        private readonly array $navIdsByHandle = [],
        private readonly bool $available = true,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function navIdByHandle(string $handle): ?int
    {
        $this->handlesLookedUp[] = $handle;

        return $this->navIdsByHandle[$handle] ?? null;
    }

    /** @param list<NavNode> $nodes */
    public function registerTempNodes(array $nodes): void
    {
        $this->registeredTempNodes[] = $nodes;
    }

    /** @return list<int> ids of every node registered, in call order */
    public function registeredNodeIds(): array
    {
        $ids = [];

        foreach ($this->registeredTempNodes as $batch) {
            foreach ($batch as $node) {
                $ids[] = (int) $node->id;
            }
        }

        return $ids;
    }
}
