<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\load;

use Lameco\Kunstmaanmigrator\Compile\SubmissionCompiler;
use Lameco\Kunstmaanmigrator\craft\FormGateway;
use Lameco\Kunstmaanmigrator\Mapping\SubmissionsLane;
use Lameco\Kunstmaanmigrator\run\EnvironmentContext;
use Throwable;

/**
 * `forms.submissions:` — the legacy form submissions, behind the Formie seam.
 *
 * Runs inside the forms lane, after its forms: a submission lands on the form
 * the lane wrote for its node's page, or on an archive form built from what
 * the submissions answered when that page is gone.
 */
final class SubmissionMigrationService
{
    /** Keyed `<ENV>:kuma_form_submission:<id>` — a legacy id is unique within one database only. */
    private const STATE_SOURCE = 'form_submission';

    public function __construct(
        private readonly FormGateway $forms,
        private readonly ?MigrationStateService $state,
    ) {
    }

    /**
     * The legacy submissions of every opted-in node, onto the form the lane
     * wrote for its page, or onto an archive form when the page carries none
     * any more.
     *
     * Free of the gate and the plugin's settings, so it is the seam the pass is
     * tested at; `FormMigrationService::migrateAll()` is the caller in a run.
     */
    public function migrate(MigrationOptions $opts, EnvironmentContext $context, MigrationReport $report, string $prefix): void
    {
        if ($context->mapping === null || $context->legacy === null) {
            return;
        }

        $lane = $context->mapping->forms()->submissions;

        if (!$lane->declared) {
            return;
        }

        $compiler = new SubmissionCompiler($context->mapping);
        $compiler->compile(
            $context->legacy,
            $context->name,
            function(array $group) use ($opts, $context, $report, $prefix, $lane): void {
                $this->loadSubmissions($group, $opts, $context, $report, $prefix, $lane);
            },
        );

        foreach ($compiler->skipped() as $reason => $count) {
            $report->warn(sprintf('submissions: %d skipped: %s', $count, $reason));
        }
    }

    /** @param array<string, mixed> $group one node's submissions, as SubmissionCompiler emits it */
    private function loadSubmissions(
        array $group,
        MigrationOptions $opts,
        EnvironmentContext $context,
        MigrationReport $report,
        string $prefix,
        SubmissionsLane $lane,
    ): void {
        $submissions = (array) $group['submissions'];
        $report->incr('submissionsCompiled', count($submissions));

        if ($opts->dryRun) {
            return;
        }

        $target = $this->targetForm($group, $opts, $report, $prefix, $lane);

        if ($target === null) {
            $report->incr('submissionsFailed', count($submissions));

            return;
        }

        [$formId, $fieldMap] = $target;

        foreach ($submissions as $submission) {
            $key = (string) $submission['key'];
            $existing = $this->state?->getTargetId(self::STATE_SOURCE, $key, null);

            if ($existing !== null && !$opts->force) {
                $report->incr('submissionsSkipped');

                continue;
            }

            $values = [];
            $files = [];
            // Files an earlier run already copied in. Handing them over again
            // would add a second asset per upload; leaving the handle out keeps
            // the relation the submission already has.
            $ingested = $existing === null
                ? []
                : (array) ($this->state?->get(self::STATE_SOURCE, $key, null)['meta']['files'] ?? []);

            foreach ((array) $submission['values'] as $part => $answer) {
                $field = $fieldMap[$part] ?? null;

                if ($field === null) {
                    $report->incr('submissionValuesUnmatched');
                    $report->warn(sprintf(
                        '%s: no field on form %d for %s ("%s"); value not written.',
                        $key,
                        $formId,
                        $part,
                        $answer['label'] ?? '',
                    ));

                    continue;
                }

                if ($answer['kind'] === 'file') {
                    if (in_array($field['handle'], $ingested, true)) {
                        continue;
                    }

                    $path = $this->legacyFile((array) $answer['value'], $lane->filesRoot);

                    if ($path === null) {
                        $report->incr('submissionFilesMissing');
                        $report->warn(sprintf(
                            '%s: uploaded file %s is not under the files root; the submission lands without it.',
                            $key,
                            $answer['value']['url'] ?? $answer['value']['name'] ?? '?',
                        ));

                        continue;
                    }

                    $files[$field['handle']][] = $path;

                    continue;
                }

                $value = self::valueFor($answer['kind'], $answer['value'], $field['type']);

                if ($value !== null) {
                    $values[$field['handle']] = $value;
                }
            }

            $warnings = [];
            $payload = [
                'values' => $values,
                'dateCreated' => (string) $submission['created'],
                'siteId' => $context->sites->siteIdForLocale((string) $submission['lang']) ?? $context->sites->primary()?->siteId,
                'ipAddress' => (string) $submission['ip'] !== '' ? (string) $submission['ip'] : null,
            ];

            if ($files !== []) {
                $payload['files'] = $files;
                $payload['uploadVolume'] = $lane->volume;
            }

            try {
                $id = $this->forms->saveSubmission($existing, $formId, $payload, $warnings);
            } catch (Throwable $e) {
                $id = null;
                $warnings[] = $e->getMessage();
            }

            foreach ($warnings as $warning) {
                $report->warn(sprintf('%s: %s', $key, $warning));
            }

            if ($id === null) {
                $report->incr('submissionsFailed');

                continue;
            }

            $this->state?->record(
                self::STATE_SOURCE,
                $key,
                'formie_submission',
                $id,
                null,
                null,
                [
                    'form' => $group['formUid'],
                    'node' => $group['node'],
                    'files' => array_values(array_unique([...$ingested, ...array_keys($files)])),
                ],
            );
            $report->incr($existing === null ? 'submissionsCreated' : 'submissionsUpdated');
        }
    }

    /**
     * The form a node's submissions land on, with which handle each legacy
     * pagepart has there: the lane's form when it wrote one, else an archive
     * form built from what the submissions answered.
     *
     * @param array<string, mixed> $group
     * @return array{0: int, 1: array<string, array{handle: string, type: string}>}|null
     */
    private function targetForm(array $group, MigrationOptions $opts, MigrationReport $report, string $prefix, SubmissionsLane $lane): ?array
    {
        $uid = (string) $group['formUid'];
        $row = $this->state?->get(FormMigrationService::STATE_SOURCE, $uid, null);
        $meta = is_array($row['meta'] ?? null) ? $row['meta'] : [];
        $archived = (bool) ($meta['archived'] ?? false);

        if ($row !== null && !($archived && $opts->force)) {
            if (!is_array($meta['fieldMap'] ?? null)) {
                $report->warn(sprintf(
                    'node %d: form %s was written before field handles were recorded; re-run the forms lane with --force, then the submissions.',
                    $group['node'],
                    $uid,
                ));

                return null;
            }

            return [(int) $row['targetId'], $meta['fieldMap']];
        }

        $handle = FormMigrationService::handleFor($uid, $prefix);
        $warnings = [];
        $handles = [];
        $formId = $this->forms->saveForm(
            $handle,
            sprintf('%s (legacy node %d, archived)', $group['title'], $group['node']),
            self::archiveFields((array) $group['fields'], $lane->volume),
            ['archived' => true],
            $warnings,
            $handles,
        );

        foreach ($warnings as $warning) {
            $report->warn($warning);
        }

        if ($formId === null) {
            return null;
        }

        $this->state?->record(
            FormMigrationService::STATE_SOURCE,
            $uid,
            'formie_form',
            $formId,
            null,
            null,
            ['handle' => $handle, 'archived' => true, 'fields' => count($handles), 'fieldMap' => $handles],
        );
        $report->incr('archiveForms');

        return [$formId, $handles];
    }

    /**
     * An archive form's fields: one per pagepart any submission answered,
     * typed from what the legacy field stored.
     *
     * @param array<string, array<string, mixed>> $fields
     * @return list<array{type: string, label: string, handle: string, required: bool, settings: array<string, mixed>, partRef: string}>
     */
    private static function archiveFields(array $fields, ?string $volume): array
    {
        $out = [];

        foreach ($fields as $part => $field) {
            $settings = [];
            $type = match ($field['kind']) {
                'text' => 'multiLineText',
                'email' => 'email',
                'boolean' => 'agree',
                'file' => 'fileUpload',
                'choice' => empty($field['multiple']) ? 'dropdown' : 'checkboxes',
                default => 'singleLineText',
            };

            if ($field['kind'] === 'choice') {
                $settings['options'] = array_map(
                    static fn(string $choice): array => ['label' => $choice, 'value' => $choice],
                    (array) ($field['choices'] ?? []),
                );
            }

            if ($type === 'fileUpload' && $volume !== null) {
                $settings['uploadVolume'] = $volume;
            }

            $out[] = [
                'type' => $type,
                'label' => (string) $field['label'],
                'handle' => '',
                'required' => false,
                'settings' => $settings,
                'partRef' => (string) $part,
            ];
        }

        return $out;
    }

    /** A legacy answer in the shape the Formie field it lands on stores. */
    private static function valueFor(string $kind, mixed $value, string $type): mixed
    {
        if ($kind === 'choice') {
            $labels = array_values((array) $value);

            return $type === 'checkboxes' ? $labels : ($labels === [] ? null : implode(', ', $labels));
        }

        if ($kind === 'boolean') {
            return $value;
        }

        return $value === null ? null : (string) $value;
    }

    /**
     * Where a legacy upload is on disk: its stored url under the files root, or
     * — an upload from before Kunstmaan stored one — its name under
     * `uploads/formsubmissions`.
     *
     * @param array{name?: ?string, url?: ?string} $file
     */
    private function legacyFile(array $file, ?string $root): ?string
    {
        if ($root === null) {
            return null;
        }

        $root = rtrim($root, '/');
        $candidates = [];

        if (($file['url'] ?? null) !== null && $file['url'] !== '') {
            $candidates[] = $root . '/' . ltrim((string) $file['url'], '/');
        }

        if (($file['name'] ?? null) !== null && $file['name'] !== '') {
            $candidates[] = $root . '/uploads/formsubmissions/' . basename((string) $file['name']);
        }

        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }
}
