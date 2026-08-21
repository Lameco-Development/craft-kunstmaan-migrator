<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\support;

use lameco\kunstmaanmigrator\craft\PluginRegistry;

/**
 * The second adapter: whatever the test says is installed.
 */
final class InMemoryPluginRegistry implements PluginRegistry
{
    /** @param array<string, string> $versionsByHandle */
    public function __construct(private readonly array $versionsByHandle = [])
    {
    }

    public function isInstalled(string $handle): bool
    {
        return array_key_exists($handle, $this->versionsByHandle);
    }

    public function versionOf(string $handle): ?string
    {
        return $this->versionsByHandle[$handle] ?? null;
    }
}
