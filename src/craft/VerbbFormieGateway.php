<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\craft;

use Craft;
use craft\elements\Asset;
use craft\helpers\StringHelper;
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
                $field = new $class();
                $field->handle = $this->fieldHandle($spec, $index, $built);
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
        $page = new FieldLayoutPage();
        $page->label = (string) ($settings['pageLabel'] ?? 'Page 1');
        $page->setRows(array_map(static function($field): FieldLayoutRow {
            $row = new FieldLayoutRow();
            $row->setFields([$field]);

            return $row;
        }, array_values($built)));

        $layout = new FormLayout();
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
        // into a form nobody reads.
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

        $existing = $existingId === null ? null : Submission::find()->id($existingId)->status(null)->one();
        $record = $existing ?? new Submission();
        $record->setForm($form);

        if (isset($submission['siteId'])) {
            $record->siteId = (int) $submission['siteId'];
        }

        $record->ipAddress = $submission['ipAddress'] ?? null;
        $record->dateCreated = new DateTime($submission['dateCreated']);
        $record->isIncomplete = false;
        $record->isSpam = false;

        // An explicit status on create, and the existing one kept on update:
        // a status change on save is what fires Formie's status-condition
        // notifications, and a re-run must not email anyone.
        if ($existing === null) {
            $record->statusId = $form->getDefaultStatus()?->id;
        }

        $values = $submission['values'];

        foreach ($submission['files'] ?? [] as $fieldHandle => $paths) {
            $ids = [];

            foreach ($paths as $path) {
                $assetId = $this->ingestUpload((string) $path, $submission['uploadVolume'] ?? null, $warnings);

                if ($assetId !== null) {
                    $ids[] = $assetId;
                }
            }

            $values[$fieldHandle] = $ids;
        }

        foreach ($values as $fieldHandle => $value) {
            if ($form->getFieldByHandle((string) $fieldHandle) === null) {
                $warnings[] = sprintf('form %s has no field "%s"; value not written.', $form->handle, $fieldHandle);

                continue;
            }

            $record->setFieldValue((string) $fieldHandle, $value);
        }

        // Archival data is what it is: a field that was optional in 2016 and
        // required now must not refuse the 2016 lead.
        if (!Craft::$app->getElements()->saveElement($record, false)) {
            $warnings[] = sprintf('form %s: Formie refused a submission — %s', $form->handle, implode('; ', $record->getErrorSummary(true)) ?: 'no reason given');

            return null;
        }

        return (int) $record->id;
    }

    /** A legacy upload copied into the volume, or null (with a warning) when it cannot be. */
    private function ingestUpload(string $path, ?string $volumeHandle, array &$warnings): ?int
    {
        $volume = $volumeHandle === null ? null : Craft::$app->getVolumes()->getVolumeByHandle($volumeHandle);

        if ($volume === null) {
            $warnings[] = sprintf('no upload volume "%s"; %s not migrated.', (string) $volumeHandle, basename($path));

            return null;
        }

        $temp = Craft::$app->getPath()->getTempPath() . DIRECTORY_SEPARATOR . uniqid('kuma-upload-', true) . '-' . basename($path);

        if (!@copy($path, $temp)) {
            $warnings[] = sprintf('could not copy %s.', $path);

            return null;
        }

        $asset = new Asset();
        $asset->tempFilePath = $temp;
        $asset->filename = basename($path);
        $asset->newFolderId = Craft::$app->getAssets()->getRootFolderByVolumeId($volume->id)?->id;
        $asset->volumeId = $volume->id;
        $asset->avoidFilenameConflicts = true;
        $asset->setScenario(Asset::SCENARIO_CREATE);

        if (!Craft::$app->getElements()->saveElement($asset)) {
            $warnings[] = sprintf('%s: %s', basename($path), implode('; ', $asset->getErrorSummary(true)));

            return null;
        }

        return (int) $asset->id;
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
