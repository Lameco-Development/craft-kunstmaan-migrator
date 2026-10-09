<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\craft;

use Craft;
use craft\elements\Asset;
use craft\helpers\StringHelper;
use craft\models\VolumeFolder;
use DateTime;
use Throwable;
use verbb\formie\elements\Form;
use verbb\formie\elements\Submission;
use verbb\formie\Formie;
use verbb\formie\models\FieldLayout as FormLayout;
use verbb\formie\models\FieldLayoutPage;
use verbb\formie\models\FieldLayoutRow;

/**
 * The production adapter at verbb/formie.
 *
 * Formie is not a composer requirement — the forms lane is optional the same
 * way SEO and redirects are — so everything here is guarded and every class
 * reference is resolved at call time rather than at load.
 */
final class VerbbFormieGateway implements FormGateway
{
    /**
     * Legacy pagepart type => Formie field class.
     *
     * The mapping decides which legacy class becomes which `type:`; this decides
     * what that type means to Formie. Two separate questions, deliberately: the
     * first is a project's, the second is this plugin's, and merging them is how
     * the old FormMigrationService ended up with the field vocabulary hard-coded
     * next to the table names.
     */
    private const TYPES = [
        'singleLineText' => \verbb\formie\fields\SingleLineText::class,
        'multiLineText' => \verbb\formie\fields\MultiLineText::class,
        'email' => \verbb\formie\fields\Email::class,
        'hiddenField' => \verbb\formie\fields\Hidden::class,
        'checkboxes' => \verbb\formie\fields\Checkboxes::class,
        'agree' => \verbb\formie\fields\Agree::class,
        'dropdown' => \verbb\formie\fields\Dropdown::class,
        'radio' => \verbb\formie\fields\Radio::class,
        'fileUpload' => \verbb\formie\fields\FileUpload::class,
        'phone' => \verbb\formie\fields\Phone::class,
        'number' => \verbb\formie\fields\Number::class,
        'heading' => \verbb\formie\fields\Heading::class,
        'html' => \verbb\formie\fields\Html::class,
    ];

    /**
     * Legacy types Formie provides itself rather than as a field.
     *
     * A submit button is part of every Formie form and a captcha is a plugin
     * setting, so emitting either as a field would put a second button on the
     * page. Skipped deliberately, and reported as such — "we chose not to" and
     * "we did not know how" must not look the same in a run report.
     */
    private const PROVIDED_BY_FORMIE = ['submitButton', 'recaptcha'];

    public function isAvailable(): bool
    {
        return Craft::$app->plugins->getPlugin('formie') !== null
            && class_exists(Formie::class)
            && Formie::$plugin !== null;
    }

    public function formIdByHandle(string $handle): ?int
    {
        if (!$this->isAvailable()) {
            return null;
        }

        $id = Form::find()->handle($handle)->status(null)->ids()[0] ?? null;

        return $id === null ? null : (int) $id;
    }

    public function saveForm(string $handle, string $title, array $fields, array $settings, array &$warnings, array &$handles = []): ?int
    {
        if (!$this->isAvailable()) {
            $warnings[] = 'formie is not installed; no form was written.';

            return null;
        }

        $form = Form::find()->handle($handle)->status(null)->one() ?? new Form();
        $form->handle = $handle;
        $form->title = $title !== '' ? $title : $handle;

        // Formie files a submission's content under each field's uid. A re-save
        // that built every field anew gave each a new uid and orphaned every
        // value already stored, so a field whose handle and type still match is
        // updated in place — its id, and so its uid, kept — and the layout it
        // sits in is the form's own, not a second one.
        $layout = $form->id ? $form->getFormLayout() : new FormLayout();
        $previous = [];

        foreach ($layout->getFields() as $field) {
            $previous[$field->handle] = $field;
        }

        $built = [];
        $refs = [];

        foreach ($fields as $index => $spec) {
            $type = (string) ($spec['type'] ?? '');

            if (in_array($type, self::PROVIDED_BY_FORMIE, true)) {
                continue;
            }

            $class = self::TYPES[$type] ?? null;

            if ($class === null) {
                $warnings[] = sprintf('%s: no Formie field for type "%s"; skipped.', $handle, $type);

                continue;
            }

            try {
                $fieldHandle = $this->fieldHandle($spec, $index, $built);
                $field = isset($previous[$fieldHandle]) && $previous[$fieldHandle]::class === $class
                    ? $previous[$fieldHandle]
                    : new $class();
                $field->handle = $fieldHandle;
                // A legacy hidden field carries a name and no label. "Field 7"
                // tells an editor nothing; `enreachGtm` at least says what it is.
                $field->label = (string) ($spec['label'] ?? '')
                    ?: ($spec['handle'] ?? '')
                    ?: 'Field ' . ($index + 1);
                $field->required = (bool) ($spec['required'] ?? false);

                $fieldSettings = (array) ($spec['settings'] ?? []);

                // A volume named by handle — what a mapping can know — becomes
                // the `volume:<uid>` source Formie's file field stores.
                if (isset($fieldSettings['uploadVolume'])) {
                    $volumeHandle = (string) $fieldSettings['uploadVolume'];
                    $volume = Craft::$app->getVolumes()->getVolumeByHandle($volumeHandle);
                    unset($fieldSettings['uploadVolume']);

                    if ($volume === null) {
                        $warnings[] = sprintf('%s: no volume "%s" for %s; the field has no upload location.', $handle, $volumeHandle, $field->handle);
                    } else {
                        $fieldSettings['uploadLocationSource'] = 'volume:' . $volume->uid;
                    }
                }

                foreach ($fieldSettings as $key => $value) {
                    if ($field->canSetProperty($key)) {
                        $field->$key = $value;
                    }
                }

                $built[$field->handle] = $field;
                $refs[$field->handle] = [(string) ($spec['partRef'] ?? $index), $type];
            } catch (Throwable $e) {
                $warnings[] = sprintf('%s: %s could not be built — %s', $handle, $type, $e->getMessage());
            }
        }

        if ($built === []) {
            $warnings[] = sprintf('%s: no field survived; form not written.', $handle);

            return null;
        }

        // One page, one row per field. The legacy Row/Col brackets describe a
        // two-column layout that Formie can express, but reproducing it wrongly
        // is worse than a single column an editor can rearrange in a minute.
        $pages = $layout->getPages();
        $page = $pages[0] ?? new FieldLayoutPage();
        $page->label = (string) ($settings['pageLabel'] ?? 'Page 1');
        $oldRows = $page->getRows();
        $page->setRows(array_map(static function($field): FieldLayoutRow {
            $row = new FieldLayoutRow();
            $row->setFields([$field]);

            return $row;
        }, array_values($built)));

        // What the rebuilt layout no longer holds, for Formie to delete once
        // the rest has saved: the old rows, any other page, and a field that
        // went or changed type.
        $kept = array_map(static fn($field): ?int => $field->id, $built);
        $layout->setDeletedItems([
            'fields' => array_values(array_filter(
                array_map(static fn($field): ?int => $field->id, $previous),
                static fn(?int $id): bool => $id !== null && !in_array($id, $kept, true),
            )),
            'rows' => array_values(array_filter(array_map(static fn($row): ?int => $row->id, [
                ...$oldRows,
                ...array_merge(...array_map(static fn($other): array => $other->getRows(), array_slice($pages, 1))),
            ]))),
            'pages' => array_values(array_filter(array_map(static fn($other): ?int => $other->id, array_slice($pages, 1)))),
        ]);
        $layout->setPages([$page]);
        $form->setFormLayout($layout);

        foreach (['submitActionMessage', 'submitAction'] as $key) {
            if (isset($settings[$key]) && $form->canSetProperty($key)) {
                $form->$key = $settings[$key];
            }
        }

        // An archive form holds a deleted page's submissions and is placed
        // nowhere. Scheduled to have closed already, so that if someone does
        // place it, Formie shows its expired message rather than taking leads
        // into a form nobody reads. The schedule is also what refuses a posted
        // submission: SubmissionsController::actionSubmit() validates it, and
        // Submission::validate() fails an expired form. `enabled = false` would
        // do nothing — Form has no statuses, so _getForm() finds it regardless.
        // The CP is exempt from the schedule, so the form and its submissions
        // stay readable there.
        if (!empty($settings['archived'])) {
            $form->settings->scheduleForm = true;
            $form->settings->scheduleFormStart = null;
            $form->settings->scheduleFormEnd = new DateTime('2000-01-01');
        }

        if (!Craft::$app->getElements()->saveElement($form)) {
            // Formie validates the layout rather than the form for a bad field
            // handle, so getErrorSummary() comes back empty and the run reported
            // a failure with no message at all — which is the least useful thing
            // a report can say.
            $summary = $form->getErrorSummary(true);

            foreach ($form->getFormLayout()->getFields() as $field) {
                foreach ($field->getErrorSummary(true) as $error) {
                    $summary[] = sprintf('%s: %s', $field->handle, $error);
                }
            }

            $warnings[] = sprintf(
                '%s: %s',
                $handle,
                $summary === [] ? 'Formie refused the form without saying why.' : implode('; ', $summary),
            );

            return null;
        }

        foreach ($refs as $fieldHandle => [$ref, $type]) {
            $handles[$ref] = ['handle' => $fieldHandle, 'type' => $type];
        }

        return (int) $form->id;
    }

    public function saveSubmission(?int $existingId, int $formId, array $submission, array &$warnings): ?int
    {
        if (!$this->isAvailable()) {
            $warnings[] = 'formie is not installed; no submission was written.';

            return null;
        }

        $form = Form::find()->id($formId)->status(null)->one();

        if ($form === null) {
            $warnings[] = sprintf('form %d does not exist; submission not written.', $formId);

            return null;
        }

        // Every site, status, and spam or incomplete flag: a migrated lead filed
        // on a non-primary site, or one an editor has since marked as spam, is
        // still the one to update — not missing, and not to be written twice.
        $existing = $existingId === null ? null : Submission::find()
            ->id($existingId)
            ->siteId('*')
            ->status(null)
            ->isIncomplete(null)
            ->isSpam(null)
            ->one();
        $record = $existing ?? new Submission();
        $record->setForm($form);

        if (isset($submission['siteId'])) {
            $record->siteId = (int) $submission['siteId'];
        }

        $record->ipAddress = $submission['ipAddress'] ?? null;
        $record->dateCreated = new DateTime($submission['dateCreated']);
        $record->isIncomplete = false;
        $record->isSpam = false;
        // Formie's afterSave() sends every status-condition notification when
        // a save changes the status and this is false — which a create with an
        // explicit status always does. The front-end controller sets it for the
        // same reason; it gates nothing else.
        $record->isNewSubmission = true;

        // An explicit status on create, the existing one kept on update.
        if ($existing === null) {
            $record->statusId = $form->getDefaultStatus()?->id;
        }

        foreach ($submission['values'] as $fieldHandle => $value) {
            if ($form->getFieldByHandle((string) $fieldHandle) === null) {
                $warnings[] = sprintf('form %s has no field "%s"; value not written.', $form->handle, $fieldHandle);

                continue;
            }

            $record->setFieldValue((string) $fieldHandle, $value);
        }

        // Archival data is what it is: a field that was optional in 2016 and
        // required now must not refuse the 2016 lead.
        if (!Craft::$app->getElements()->saveElement($record, false)) {
            // The attributes, not the messages: a message can quote the value.
            $warnings[] = sprintf('form %s: Formie refused the submission (%s).', $form->handle, implode(', ', array_keys($record->getErrors())) ?: 'no reason given');

            return null;
        }

        return (int) $record->id;
    }

    /**
     * Not AssetMigrationService's ingest: that one places legacy *media* in the
     * public volumes, unsanitised because it is the client's own artwork, and
     * shares one asset per file across environments. An applicant's CV is an
     * untrusted upload bound for a private volume, one asset per submission.
     */
    public function ingestUpload(string $path, int $formId, string $fieldHandle, ?string $volumeHandle, array &$warnings): ?int
    {
        if (!$this->isAvailable()) {
            $warnings[] = 'formie is not installed; the upload was not copied.';

            return null;
        }

        $folder = $this->uploadFolder($formId, $fieldHandle, $volumeHandle, $warnings);

        if ($folder === null) {
            return null;
        }

        $temp = Craft::$app->getPath()->getTempPath() . DIRECTORY_SEPARATOR . uniqid('kuma-upload-', true) . '-' . basename($path);

        if (!@copy($path, $temp)) {
            $warnings[] = 'could not copy the upload.';

            return null;
        }

        $asset = new Asset();
        $asset->tempFilePath = $temp;
        $asset->filename = basename($path);
        $asset->newFolderId = $folder->id;
        $asset->volumeId = $folder->volumeId;
        $asset->avoidFilenameConflicts = true;
        $asset->setScenario(Asset::SCENARIO_CREATE);

        if (!Craft::$app->getElements()->saveElement($asset)) {
            // Craft's messages name the file; the attributes do not.
            $warnings[] = sprintf('Craft refused the upload as an asset (%s).', implode(', ', array_keys($asset->getErrors())) ?: 'no reason given');

            return null;
        }

        return (int) $asset->id;
    }

    /**
     * The folder Formie itself would put a new upload on this field in: the
     * field's upload location — volume and subpath, tokens rendered — by
     * Formie's own resolver; else the root of the fallback volume.
     *
     * A subpath token that needs a saved submission (`{id}`) cannot render
     * before the submission exists, and Formie then answers with a user's
     * temporary upload folder. That is no place for a CV, so it falls back to
     * the root of the field's volume, and says so.
     *
     * @param list<string> $warnings
     */
    private function uploadFolder(int $formId, string $fieldHandle, ?string $volumeHandle, array &$warnings): ?VolumeFolder
    {
        $assets = Craft::$app->getAssets();
        $form = Form::find()->id($formId)->status(null)->one();
        $field = $form?->getFieldByHandle($fieldHandle);
        $source = $field instanceof \verbb\formie\fields\FileUpload ? (string) $field->uploadLocationSource : '';

        if ($form !== null && $field instanceof \verbb\formie\fields\FileUpload && $source !== '') {
            // How Formie reads its source: `volume:<uid>` or `folder:<uid>`, the uid a volume's.
            $volume = str_contains($source, ':')
                ? Craft::$app->getVolumes()->getVolumeByUid(explode(':', $source)[1])
                : null;

            if ($volume === null) {
                $warnings[] = sprintf('the %s field uploads to a volume that does not exist; the upload was not copied.', $fieldHandle);

                return null;
            }

            $submission = new Submission();
            $submission->setForm($form);

            try {
                $folder = $assets->getFolderById($field->resolveDynamicPathToFolderId($submission));
            } catch (Throwable) {
                $folder = null;
            }

            if ($folder !== null && $folder->volumeId === $volume->id) {
                return $folder;
            }

            $warnings[] = sprintf('the %s field\'s upload subpath does not resolve before the submission is saved; the upload lands in its volume\'s root.', $fieldHandle);

            return $assets->getRootFolderByVolumeId($volume->id);
        }

        $volume = $volumeHandle === null ? null : Craft::$app->getVolumes()->getVolumeByHandle($volumeHandle);

        if ($volume === null) {
            $warnings[] = $volumeHandle === null
                ? sprintf('the %s field has no upload location and forms.submissions declares no volume; the upload was not copied.', $fieldHandle)
                : sprintf('no upload volume "%s"; the upload was not copied.', $volumeHandle);

            return null;
        }

        return $assets->getRootFolderByVolumeId($volume->id);
    }

    /**
     * A handle Formie will accept, unique within the form.
     *
     * Legacy `internal_name` is what an editor typed. On the real corpus that
     * includes `Prénom` and `Téléphone`, which camel-casing leaves accented and
     * Formie rejects — one form in twenty-six failed on exactly this. toHandle()
     * folds to ASCII, which is what a field handle has to be.
     *
     * It is also frequently blank or duplicated within one form, and a collision
     * silently overwriting an earlier field is the other failure worth
     * preventing here.
     *
     * @param array<string, mixed> $spec
     * @param array<string, mixed> $taken
     */
    private function fieldHandle(array $spec, int $index, array $taken): string
    {
        $base = StringHelper::toHandle((string) ($spec['handle'] ?? ''));

        if ($base === '') {
            $base = StringHelper::toHandle((string) ($spec['label'] ?? '')) ?: 'field';
        }

        $handle = $base;
        $suffix = 1;

        while (isset($taken[$handle])) {
            $handle = $base . ++$suffix;
        }

        return $handle;
    }
}
