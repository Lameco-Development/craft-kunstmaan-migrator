<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\support;

use Lameco\Kunstmaanmigrator\craft\FormGateway;
use RuntimeException;

/**
 * The second adapter: whatever the test says Formie did.
 *
 * Like Formie it files a submission's content under each field's uid, not its
 * handle, so a form re-save that handed its fields new uids would orphan every
 * value already stored — the contract the production adapter keeps by reusing
 * a field (and so its uid) when the handle and type still match.
 */
final class InMemoryFormGateway implements FormGateway
{
    /** @var array<string, array{id: int, title: string, fields: list<array<string, mixed>>, settings: array<string, mixed>, uids: array<string, array{uid: string, type: string}>}> */
    public array $saved = [];

    /** @var list<string> */
    public array $refuse = [];

    /** @var array<int, array<string, mixed>> submission id => what was last handed over, plus `content` by field uid */
    public array $submissions = [];

    /** @var array<int, string> asset id => the path it was copied from */
    public array $uploads = [];

    /** @var list<string> basenames whose copy fails, the way an unreadable file does */
    public array $failUploads = [];

    /** When set, saveForm() throws with this message, as Formie does on a broken layout. */
    public ?string $throwOnForm = null;

    /** When set, saveSubmission() throws with this message — as a driver error carrying bound values would. */
    public ?string $throwOnSubmission = null;

    private int $nextId = 500;

    private int $nextSubmissionId = 9000;

    private int $nextAssetId = 7000;

    private int $nextUid = 1;

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

    public function saveForm(string $handle, string $title, array $fields, array $settings, array &$warnings, array &$handles = []): ?int
    {
        if ($this->throwOnForm !== null) {
            throw new RuntimeException($this->throwOnForm);
        }

        if (in_array($handle, $this->refuse, true)) {
            $warnings[] = sprintf('%s: refused by the test', $handle);

            return null;
        }

        $before = $this->saved[$handle]['uids'] ?? [];
        $uids = [];

        foreach ($fields as $index => $spec) {
            $type = (string) ($spec['type'] ?? '');

            if (in_array($type, ['submitButton', 'recaptcha'], true)) {
                continue;
            }

            // Not Formie's toHandle(): the twin's handles only have to be
            // stable and distinct, which is all the lane may rely on.
            $base = (string) ($spec['handle'] ?? '') ?: lcfirst(str_replace(' ', '', ucwords(strtolower(
                (string) preg_replace('/[^A-Za-z0-9]+/', ' ', (string) ($spec['label'] ?? '')),
            )))) ?: 'field';
            $fieldHandle = $base;

            for ($n = 2; isset($uids[$fieldHandle]); $n++) {
                $fieldHandle = $base . $n;
            }

            $kept = $before[$fieldHandle] ?? null;
            $uids[$fieldHandle] = $kept !== null && $kept['type'] === $type
                ? $kept
                : ['uid' => 'uid-' . $this->nextUid++, 'type' => $type];
            $handles[(string) ($spec['partRef'] ?? $index)] = ['handle' => $fieldHandle, 'type' => $type];
        }

        $id = $this->saved[$handle]['id'] ?? $this->nextId++;
        $this->saved[$handle] = ['id' => $id, 'title' => $title, 'fields' => $fields, 'settings' => $settings, 'uids' => $uids];

        return $id;
    }

    public function ingestUpload(string $path, string $volumeHandle, array &$warnings): ?int
    {
        if (in_array(basename($path), $this->failUploads, true)) {
            $warnings[] = 'could not copy the upload.';

            return null;
        }

        $id = $this->nextAssetId++;
        $this->uploads[$id] = $path;

        return $id;
    }

    public function saveSubmission(?int $existingId, int $formId, array $submission, array &$warnings): ?int
    {
        if ($this->throwOnSubmission !== null) {
            throw new RuntimeException($this->throwOnSubmission);
        }

        $form = $this->formById($formId);

        if ($form === null) {
            $warnings[] = sprintf('form %d does not exist', $formId);

            return null;
        }

        $id = $existingId ?? $this->nextSubmissionId++;
        $content = $this->submissions[$id]['content'] ?? [];

        foreach ($submission['values'] as $fieldHandle => $value) {
            $uid = $form['uids'][$fieldHandle]['uid'] ?? null;

            if ($uid === null) {
                $warnings[] = sprintf('form has no field "%s"; value not written.', $fieldHandle);

                continue;
            }

            $content[$uid] = $value;
        }

        $this->submissions[$id] = ['formId' => $formId] + $submission + ['content' => $content];

        return $id;
    }

    /**
     * What a submission holds now, by the handles its form has now — which is
     * what an editor opening it in the control panel would see.
     *
     * @return array<string, mixed>
     */
    public function valuesOf(int $submissionId): array
    {
        $submission = $this->submissions[$submissionId];
        $form = $this->formById((int) $submission['formId']) ?? ['uids' => []];
        $values = [];

        foreach ($form['uids'] as $fieldHandle => ['uid' => $uid]) {
            if (array_key_exists($uid, $submission['content'])) {
                $values[$fieldHandle] = $submission['content'][$uid];
            }
        }

        return $values;
    }

    /** @return array{id: int, uids: array<string, array{uid: string, type: string}>}|null */
    private function formById(int $formId): ?array
    {
        foreach ($this->saved as $form) {
            if ($form['id'] === $formId) {
                return $form;
            }
        }

        return null;
    }
}
