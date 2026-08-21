<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\craft;

use Craft;
use craft\helpers\StringHelper;
use Throwable;
use verbb\formie\elements\Form;
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

    public function saveForm(string $handle, string $title, array $fields, array $settings, array &$warnings): ?int
    {
        if (!$this->isAvailable()) {
            $warnings[] = 'formie is not installed; no form was written.';

            return null;
        }

        $form = Form::find()->handle($handle)->status(null)->one() ?? new Form();
        $form->handle = $handle;
        $form->title = $title !== '' ? $title : $handle;

        $built = [];

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
                $field->label = (string) ($spec['label'] ?? '') ?: 'Field ' . ($index + 1);
                $field->handle = $this->fieldHandle($spec, $index, $built);
                $field->required = (bool) ($spec['required'] ?? false);

                foreach ((array) ($spec['settings'] ?? []) as $key => $value) {
                    if ($field->canSetProperty($key)) {
                        $field->$key = $value;
                    }
                }

                $built[$field->handle] = $field;
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
        $page->setRows(array_map(static function ($field): FieldLayoutRow {
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

        if (!Craft::$app->getElements()->saveElement($form)) {
            $warnings[] = sprintf('%s: %s', $handle, implode('; ', $form->getErrorSummary(true)));

            return null;
        }

        return (int) $form->id;
    }

    /**
     * A handle Formie will accept, unique within the form.
     *
     * Legacy `internal_name` is what an editor typed and is frequently blank,
     * duplicated, or not a valid handle. A collision silently overwriting an
     * earlier field is the failure worth preventing here.
     *
     * @param array<string, mixed> $spec
     * @param array<string, mixed> $taken
     */
    private function fieldHandle(array $spec, int $index, array $taken): string
    {
        $base = StringHelper::toCamelCase((string) ($spec['handle'] ?? ''));

        if ($base === '') {
            $base = StringHelper::toCamelCase((string) ($spec['label'] ?? '')) ?: 'field';
        }

        $handle = $base;
        $suffix = 1;

        while (isset($taken[$handle])) {
            $handle = $base . ++$suffix;
        }

        return $handle;
    }
}
