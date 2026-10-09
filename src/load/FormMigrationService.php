<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\load;

use Lameco\Kunstmaanmigrator\adapters\GatedAdapter;
use Lameco\Kunstmaanmigrator\adapters\MigrationAdapter;
use Lameco\Kunstmaanmigrator\Compile\FormCompiler;
use Lameco\Kunstmaanmigrator\Compile\SubmissionCompiler;
use Lameco\Kunstmaanmigrator\Compile\Transforms;
use Lameco\Kunstmaanmigrator\craft\FormGateway;
use Lameco\Kunstmaanmigrator\craft\VerbbFormieGateway;
use Lameco\Kunstmaanmigrator\Mapping\SubmissionsLane;
use Lameco\Kunstmaanmigrator\run\EnvironmentContext;
use Throwable;
use yii\base\Component;

/**
 * The `forms:` lane: a legacy form-context page becomes a Formie form.
 *
 * The mapping has declared this lane since the DSL was written and nothing ever
 * compiled it, so 495 live placements had no destination and the 289 migrated
 * `formBlock`s carry an empty relation and render as an empty shell.
 *
 * Written as an ordinary MigrationAdapter, which it could not have been before
 * EnvironmentContext: a lane that compiles from the mapping needs the mapping
 * and an open legacy connection, and `migrateAll(MigrationOptions, SiteMap)`
 * carried neither. Its configuration is declared rather than hard-coded for the
 * same reason — what a form is called, and what a submission does afterwards,
 * are a project's decisions, and the previous attempt at this lane baked both
 * into a 667-line class alongside the table names.
 */
class FormMigrationService extends Component implements MigrationAdapter
{
    use GatedAdapter;

    private const STATE_SOURCE = 'form';

    /** Keyed `<ENV>:kuma_form_submission:<id>` — a legacy id is unique within one database only. */
    private const SUBMISSION_STATE_SOURCE = 'form_submission';

    public ?MigrationStateService $stateService = null;

    public ?FormGateway $forms = null;

    public function handle(): string
    {
        return 'forms';
    }

    private function gateway(): FormGateway
    {
        return $this->forms ??= new VerbbFormieGateway();
    }

    public function migrateAll(MigrationOptions $opts, EnvironmentContext $context): MigrationReport
    {
        $report = new MigrationReport();

        if (!$this->isGateOpen($report)) {
            return $report;
        }

        if ($context->mapping === null || $context->legacy === null) {
            $report->warn('The forms lane compiles from the mapping and reads the legacy database; one of them was not supplied.');

            return $report;
        }

        if (!$this->gateway()->isAvailable()) {
            $report->warn('formie is not installed; no forms were written.');

            return $report;
        }

        $config = $this->config();
        $prefix = (string) ($config['handlePrefix'] ?? '');
        $compiler = new FormCompiler(
            $context->mapping,
            new Transforms($context->mapping->all()['transforms'] ?? []),
            $context->only,
        );

        $compiler->compile(
            $context->legacy,
            $context->name,
            function(array $record) use ($opts, $config, $prefix, $report): void {
                $this->load($record, $opts, $config, $prefix, $report);
            },
        );

        foreach ($compiler->skipped() as $reason => $count) {
            $report->warn(sprintf('%d skipped: %s', $count, $reason));
        }

        // After the forms, in the same pass: a submission lands on the form
        // the lane just wrote, so the order is structural, not hopeful.
        $this->migrateSubmissions($opts, $context, $report, $prefix);

        return $report;
    }

    /**
     * `forms.submissions:` — the legacy submissions of every opted-in node,
     * onto the form the lane wrote for its page, or onto an archive form when
     * the page carries none any more.
     *
     * Public, and free of the gate and the plugin's settings, so it is the seam
     * the pass is tested at; `migrateAll()` is the caller in a run.
     */
    public function migrateSubmissions(MigrationOptions $opts, EnvironmentContext $context, MigrationReport $report, string $prefix): void
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
            $existing = $this->stateService?->getTargetId(self::SUBMISSION_STATE_SOURCE, $key, null);

            if ($existing !== null && !$opts->force) {
                $report->incr('submissionsSkipped');

                continue;
            }

            $values = [];
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
                $id = $this->gateway()->saveSubmission($existing, $formId, $payload, $warnings);
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

            $this->stateService?->record(
                self::SUBMISSION_STATE_SOURCE,
                $key,
                'formie_submission',
                $id,
                null,
                null,
                ['form' => $group['formUid'], 'node' => $group['node']],
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
        $row = $this->stateService?->get(self::STATE_SOURCE, $uid, null);
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

        $handle = $this->handleFor($uid, $prefix);
        $warnings = [];
        $handles = [];
        $formId = $this->gateway()->saveForm(
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

        $this->stateService?->record(
            self::STATE_SOURCE,
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

    /**
     * @param array<string, mixed> $record
     * @param array<string, mixed> $config
     */
    private function load(array $record, MigrationOptions $opts, array $config, string $prefix, MigrationReport $report): void
    {
        $sourceUid = (string) $record['sourceUid'];
        $handle = $this->handleFor($sourceUid, $prefix);

        $report->incr('compiled');

        if ($opts->dryRun) {
            return;
        }

        $existing = $this->stateService?->getTargetId(self::STATE_SOURCE, $sourceUid, null);

        if ($existing !== null && !$opts->force) {
            $report->incr('skipped');

            return;
        }

        $warnings = [];
        $handles = [];

        try {
            $formId = $this->gateway()->saveForm(
                $handle,
                (string) ($record['title'] ?? $handle),
                (array) ($record['fields'] ?? []),
                [
                    'submitActionMessage' => $config['submitActionMessage'] ?? null,
                    'pageLabel' => $config['pageLabel'] ?? 'Page 1',
                ],
                $warnings,
                $handles,
            );
        } catch (Throwable $e) {
            $report->incr('failed');
            $report->warn(sprintf('%s: %s', $sourceUid, $e->getMessage()));

            return;
        }

        foreach ($warnings as $warning) {
            $report->warn($warning);
        }

        if ($formId === null) {
            $report->incr('failed');

            return;
        }

        // The state row is what lets a `formBlock` find the form it belongs to,
        // and what makes a second run an update rather than a duplicate.
        $this->stateService?->record(
            self::STATE_SOURCE,
            $sourceUid,
            'formie_form',
            $formId,
            null,
            null,
            // `fieldMap` is how a stored submission, which names the pagepart it
            // answered, finds the Formie field that part became.
            ['handle' => $handle, 'fields' => count((array) ($record['fields'] ?? [])), 'fieldMap' => $handles],
        );

        $report->incr($existing === null ? 'created' : 'updated');
    }

    /**
     * A stable, readable handle derived from the legacy identity.
     *
     * Derived rather than taken from the page title: two legacy pages routinely
     * share a title, and a form silently overwriting another is the failure this
     * lane would otherwise ship with.
     *
     * The environment is part of it because a page id is unique within one
     * legacy database and a migration walks three. COM's PotionsLandingPage 27
     * and DE's are different pages, and without the environment the second run
     * would overwrite the first — the same class of bug as the rewriter caching
     * bare legacy ids across databases.
     */
    private function handleFor(string $sourceUid, string $prefix): string
    {
        // kuma:<ENV>:form:<Entity>:<id>
        $parts = explode(':', $sourceUid);
        $tail = [$parts[1] ?? '', $parts[3] ?? '', $parts[4] ?? ''];

        $camel = str_replace(' ', '', ucwords(strtolower(str_replace(['_', '-'], ' ', implode(' ', $tail)))));

        return $prefix === '' ? lcfirst($camel) : $prefix . ucfirst($camel);
    }
}
