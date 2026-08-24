<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\support;

use Lameco\Kunstmaanmigrator\craft\EmbedGateway;

/**
 * The second adapter: whatever the test says an embed provider returned.
 */
final class InMemoryEmbedGateway implements EmbedGateway
{
    /** @var array<string, int> url => asset id */
    public array $created = [];

    /** @var list<string> URLs to fail on */
    public array $refuse = [];

    private int $nextId = 700;

    public function __construct(private readonly bool $isAvailable = true)
    {
    }

    public function available(): bool
    {
        return $this->isAvailable;
    }

    public function createFromUrl(string $url, int $folderId): ?int
    {
        if (in_array($url, $this->refuse, true)) {
            return null;
        }

        return $this->created[$url] ??= $this->nextId++;
    }
}
