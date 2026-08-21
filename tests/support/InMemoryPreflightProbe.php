<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\support;

use lameco\kunstmaanmigrator\run\PreflightProbe;

/**
 * The second adapter: whatever the test says is out there.
 */
final class InMemoryPreflightProbe implements PreflightProbe
{
    /**
     * @param array<string, int|null> $nodeCounts       database => count, or null for "table missing"
     * @param list<string>            $unreachable      databases that refuse a connection
     * @param list<string>            $readableDirectories
     */
    public function __construct(
        private readonly array $nodeCounts = [],
        private readonly array $unreachable = [],
        private readonly array $readableDirectories = [],
    ) {
    }

    public function reachable(string $database): bool
    {
        return !in_array($database, $this->unreachable, true);
    }

    public function nodeCount(string $database): ?int
    {
        return $this->nodeCounts[$database] ?? null;
    }

    public function directoryReadable(string $path): bool
    {
        return in_array($path, $this->readableDirectories, true);
    }
}
