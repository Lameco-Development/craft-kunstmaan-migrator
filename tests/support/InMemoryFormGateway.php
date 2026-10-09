<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\support;

use Lameco\Kunstmaanmigrator\craft\FormGateway;

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

    /** @var array<int, array<string, mixed>> submission id => what was written */
    public array $submissions = [];

    private int $nextSubmissionId = 9000;

    public function saveForm(string $handle, string $title, array $fields, array $settings, array &$warnings, array &$handles = []): ?int
    {
        if (in_array($handle, $this->refuse, true)) {
            $warnings[] = sprintf('%s: refused by the test', $handle);

            return null;
        }

        $taken = [];

        foreach ($fields as $index => $spec) {
            if (in_array($spec['type'] ?? '', ['submitButton', 'recaptcha'], true)) {
                continue;
            }

            // Not Formie's toHandle(): the twin's handles only have to be
            // stable and distinct, which is all the lane may rely on.
            $base = (string) ($spec['handle'] ?? '') ?: lcfirst(str_replace(' ', '', ucwords(strtolower(
                (string) preg_replace('/[^A-Za-z0-9]+/', ' ', (string) ($spec['label'] ?? '')),
            )))) ?: 'field';
            $fieldHandle = $base;

            for ($n = 2; isset($taken[$fieldHandle]); $n++) {
                $fieldHandle = $base . $n;
            }

            $taken[$fieldHandle] = true;
            $handles[(string) ($spec['partRef'] ?? $index)] = ['handle' => $fieldHandle, 'type' => (string) ($spec['type'] ?? '')];
        }

        $id = $this->saved[$handle]['id'] ?? $this->nextId++;
        $this->saved[$handle] = ['id' => $id, 'title' => $title, 'fields' => $fields, 'settings' => $settings];

        return $id;
    }

    public function saveSubmission(?int $existingId, int $formId, array $submission, array &$warnings): ?int
    {
        if (!in_array($formId, array_column($this->saved, 'id'), true)) {
            $warnings[] = sprintf('form %d does not exist', $formId);

            return null;
        }

        $id = $existingId ?? $this->nextSubmissionId++;
        $this->submissions[$id] = ['formId' => $formId] + $submission;

        return $id;
    }
}
