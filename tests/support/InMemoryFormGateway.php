<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\support;

use lameco\kunstmaanmigrator\craft\FormGateway;

/**
 * The second adapter: whatever the test says Formie did.
 */
final class InMemoryFormGateway implements FormGateway
{
    /** @var array<string, array{title: string, fields: list<array<string, mixed>>, settings: array<string, mixed>}> */
    public array $saved = [];

    /** @var list<string> */
    public array $refuse = [];

    private int $nextId = 500;

    public function __construct(private readonly bool $available = true)
    {
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function formIdByHandle(string $handle): ?int
    {
        return isset($this->saved[$handle]) ? $this->saved[$handle]['id'] : null;
    }

    public function saveForm(string $handle, string $title, array $fields, array $settings, array &$warnings): ?int
    {
        if (in_array($handle, $this->refuse, true)) {
            $warnings[] = sprintf('%s: refused by the test', $handle);

            return null;
        }

        $id = $this->saved[$handle]['id'] ?? $this->nextId++;
        $this->saved[$handle] = ['id' => $id, 'title' => $title, 'fields' => $fields, 'settings' => $settings];

        return $id;
    }
}
