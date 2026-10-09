<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\load;

use Lameco\Kunstmaanmigrator\Compile\SubmissionCompiler;
use Lameco\Kunstmaanmigrator\craft\FormGateway;
use Lameco\Kunstmaanmigrator\Mapping\SubmissionsLane;
use Lameco\Kunstmaanmigrator\run\EnvironmentContext;
use Lameco\Kunstmaanmigrator\safety\ProductionGuard;
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
        // Public and legacy-reading, so it does not lean on a caller having asked.
        if (ProductionGuard::isProduction()) {
            $report->warn('Refusing to migrate form submissions against CRAFT_ENVIRONMENT=production.');

            return;
        }

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
            // Assets an earlier run already made, by field handle. Copying the
            // file again would add a second asset per upload; attaching these
            // keeps one, and restores it on a field that lost it.
            $ingested = $existing === null
                ? []
                : self::recordedAssets($this->state?->get(self::STATE_SOURCE, $key, null)['meta']['files'] ?? null);
            $files = [];

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
                    $assetIds = $ingested[$field['handle']] ?? $this->ingestFile($key, $formId, $field['handle'], (array) $answer['value'], $lane, $report);

                    if ($assetIds !== []) {
                        $values[$field['handle']] = $assetIds;
                        $files[$field['handle']] = $assetIds;
                    }

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
                'siteId' => self::siteIdFor((string) $submission['lang'], $context),
                'ipAddress' => (string) $submission['ip'] !== '' ? (string) $submission['ip'] : null,
            ];

            try {
                $id = $this->forms->saveSubmission($existing, $formId, $payload, $warnings);
            } catch (Throwable $e) {
                $id = null;
                // A driver error echoes the values it was bound — an applicant's
                // name, address, message — so the report says what kind, not what.
                $warnings[] = sprintf('%s while saving; its message is withheld because it can carry submission content.', $e::class);
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
                    // Only a file that became an asset: one that failed is tried again.
                    'files' => $files + $ingested,
                ],
            );
            $report->incr($existing === null ? 'submissionsCreated' : 'submissionsUpdated');
        }
    }

    /**
     * The site a submission is filed on: its locale's, else the environment's
     * primary, else its first bound site. Never none: Formie would file it on
     * Craft's primary site, which for Berkvens FR is another site group.
     */
    private static function siteIdFor(string $lang, EnvironmentContext $context): ?int
    {
        return $context->sites->siteIdForLocale($lang)
            ?? $context->sites->primary()?->siteId
            ?? ($context->sites->bindings()[0] ?? null)?->siteId;
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
            self::archiveFields((array) $group['fields'], $lane->volume, $lane->subpath),
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
    private static function archiveFields(array $fields, ?string $volume, ?string $subpath): array
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

            // The lane's volume and subpath, so the field says where its files
            // are — the folder ingestUpload() puts them in, and Formie would.
            if ($type === 'fileUpload' && $volume !== null) {
                $settings['uploadVolume'] = $volume;

                if ($subpath !== null) {
                    $settings['uploadLocationSubpath'] = $subpath;
                }
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
     * One answered upload copied into the folder its file field uploads to — the
     * lane's volume when the field names none — as the asset ids it became;
     * none when it cannot be, reported by state key and field handle.
     *
     * @param array{name?: ?string, url?: ?string} $file
     * @return list<int>
     */
    private function ingestFile(string $key, int $formId, string $handle, array $file, SubmissionsLane $lane, MigrationReport $report): array
    {
        $path = self::legacyFile($file, $lane->filesRoot);

        if ($path === null) {
            $report->incr('submissionFilesMissing');
            $report->warn(sprintf('%s: %s: the uploaded file is not under the files root; the submission lands without it.', $key, $handle));

            return [];
        }

        $warnings = [];
        $assetId = $this->forms->ingestUpload($path, $formId, $handle, $lane->volume, $warnings);

        foreach ($warnings as $warning) {
            $report->warn(sprintf('%s: %s: %s', $key, $handle, $warning));
        }

        if ($assetId === null) {
            $report->incr('submissionFilesFailed');

            return [];
        }

        return [$assetId];
    }

    /**
     * The assets a state row says an earlier run made, by field handle.
     *
     * @return array<string, list<int>>
     */
    private static function recordedAssets(mixed $files): array
    {
        $out = [];

        foreach (is_array($files) ? $files : [] as $handle => $ids) {
            $ids = is_array($ids) ? array_values(array_filter($ids, 'is_int')) : [];

            if (is_string($handle) && $ids !== []) {
                $out[$handle] = $ids;
            }
        }

        return $out;
    }

    /**
     * Where a legacy upload is on disk: its stored url under the files root, or
     * — an upload from before Kunstmaan stored one — its name under
     * `uploads/formsubmissions`.
     *
     * Only ever under the root. The url is what the legacy site stored, and a
     * `../` in it must not reach a file the migration was never pointed at.
     *
     * @param array{name?: ?string, url?: ?string} $file
     */
    private static function legacyFile(array $file, ?string $root): ?string
    {
        $root = $root === null ? false : realpath($root);

        if ($root === false) {
            return null;
        }

        $candidates = [];

        if (($file['url'] ?? null) !== null && $file['url'] !== '') {
            $candidates[] = $root . '/' . ltrim((string) $file['url'], '/');
        }

        if (($file['name'] ?? null) !== null && $file['name'] !== '') {
            $candidates[] = $root . '/uploads/formsubmissions/' . basename((string) $file['name']);
        }

        foreach ($candidates as $candidate) {
            $path = realpath($candidate);

            if ($path !== false && is_file($path) && str_starts_with($path, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
                return $path;
            }
        }

        return null;
    }
}
