<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\load;

use Craft;
use lameco\kunstmaanmigrator\db\LegacyDbService;
use lameco\kunstmaanmigrator\source\KunstmaanCoreTables;
use lameco\kunstmaanmigrator\source\PagePartRefsSchema;
use Throwable;
use verbb\formie\elements\Form;
use verbb\formie\fields\Agree;
use verbb\formie\fields\Checkboxes;
use verbb\formie\fields\Dropdown;
use verbb\formie\fields\Email;
use verbb\formie\fields\FileUpload;
use verbb\formie\fields\MultiLineText;
use verbb\formie\fields\Radio;
use verbb\formie\fields\SingleLineText;
use verbb\formie\Formie;
use verbb\formie\models\FieldLayout;
use verbb\formie\models\FieldLayoutPage;
use verbb\formie\models\FieldLayoutRow;
use yii\base\Component;

/**
 * Migrate Kunstmaan FormBundle FormPages → Formie forms.
 *
 * Source side: each live `App\Entity\Pages\FormPage` kuma_node has child
 * `kuma_page_part_refs` rows (context='main') pointing at form-field
 * PageParts (`SingleLineTextPagePart`, `EmailPagePart`, `ChoicePagePart`,
 * etc.). Each part type has its own `kuma_<type>_page_parts` table with
 * label/required/etc.
 *
 * Target side: Formie's `Form` element with a FieldLayout → FieldLayoutPage(s)
 * → FieldLayoutRow(s) → field instances. The Form is also a Craft element
 * (verbb\formie\elements\Form). saveElement() persists everything.
 *
 * Skipped non-field parts (HeaderPagePart / TextPagePart / RawHtmlPagePart
 * inside a form context, plus ReferrerFormPagePart / RecaptchaPagePart /
 * SubmitButtonPagePart) are tracked but produce no Formie field — Formie
 * has implicit submit + plugin-level captcha + heading via separate
 * Heading/Html field types we don't emit yet.
 *
 * Idempotent via state rows under source='form', sourceKey=<canonical
 * FormPage refId>, targetType='formie_form'. Re-runs upsert.
 *
 * Per-locale: Formie forms are language-neutral. We use the canonical
 * (NL-primary) FormPage refId for handle/title; per-locale field-label
 * overlays via Gedmo Translatable are NOT yet wired (deferred — the v1
 * shipped today carries NL-only field labels).
 */
class FormMigrationService extends Component
{
    public ?LegacyDbService $legacyDb = null;
    public ?MigrationStateService $stateService = null;

    private const STATE_SOURCE = 'form';
    private const FORM_PAGE_FQCN = 'App\\Entity\\Pages\\FormPage';
    private const FORM_CONTEXT = 'main';

    /**
     * `Kunstmaan\FormBundle\Entity\PageParts\<Type>PagePart` → driver.
     *
     * Drivers receive (legacyRow, baseDefaults) and return either a
     * fully-configured `verbb\formie\base\FieldInterface` instance or `null`
     * to skip (non-field parts).
     *
     * @var array<string, callable>
     */
    private array $drivers;

    public function __construct($config = [])
    {
        parent::__construct($config);
        $this->drivers = $this->buildDrivers();
    }

    public function migrateAll(MigrationOptions $opts): MigrationReport
    {
        $report = new MigrationReport();

        if (Craft::$app->plugins->getPlugin('formie') === null) {
            $report->warn('Formie plugin not installed; form migration skipped.');
            return $report;
        }
        if (!class_exists(Form::class) || Formie::$plugin === null) {
            $report->warn('Formie plugin not loaded; form migration aborted.');
            return $report;
        }
        if ($this->legacyDb === null || $this->stateService === null) {
            throw new \RuntimeException('FormMigrationService: legacyDb or stateService not injected');
        }

        $refsSchema = new PagePartRefsSchema($this->legacyDb);

        $nodes = $this->liveFormPageNodes();
        if ($nodes === []) {
            $report->warn('No live FormPage kuma_nodes found; nothing to migrate.');
            return $report;
        }

        foreach ($nodes as $node) {
            try {
                $this->migrateOneFormPage($node, $refsSchema, $opts, $report);
            } catch (Throwable $e) {
                $report->incr('failed');
                $report->warn(sprintf(
                    'form migration failed for kuma_node=%d: %s',
                    (int) ($node['id'] ?? 0),
                    $e->getMessage(),
                ));
            }
        }

        return $report;
    }

    /**
     * Live (non-deleted, online-translation-bearing) FormPage kuma_nodes.
     *
     * @return list<array{id: int, parent_id: ?int, ref_entity_name: string}>
     */
    private function liveFormPageNodes(): array
    {
        $rows = $this->legacyDb->queryAll(
            'SELECT DISTINCT n.id, n.parent_id, n.ref_entity_name'
            . ' FROM ' . KunstmaanCoreTables::NODES . ' n'
            . ' JOIN ' . KunstmaanCoreTables::NODE_TRANSLATIONS . ' t ON t.node_id = n.id'
            . ' WHERE n.deleted = 0 AND n.ref_entity_name = :class AND t.online = 1'
            . ' ORDER BY n.id',
            [':class' => self::FORM_PAGE_FQCN],
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => (int) $row['id'],
                'parent_id' => isset($row['parent_id']) ? (int) $row['parent_id'] : null,
                'ref_entity_name' => (string) $row['ref_entity_name'],
            ];
        }
        return $out;
    }

    /**
     * @param array{id: int} $node
     */
    private function migrateOneFormPage(
        array $node,
        PagePartRefsSchema $refsSchema,
        MigrationOptions $opts,
        MigrationReport $report,
    ): void {
        $nodeId = $node['id'];
        $translation = $this->canonicalTranslation($nodeId);
        if ($translation === null) {
            $report->incr('skipped');
            $report->warn(sprintf('FormPage kuma_node=%d has no public NL/primary translation', $nodeId));
            return;
        }

        $refId = (int) $translation['ref_id'];
        $sourceKey = (string) $refId;

        $existingId = $this->stateService->getTargetId(self::STATE_SOURCE, $sourceKey, null);
        if ($existingId !== null && !$opts->force) {
            $report->incr('skipped');
            return;
        }

        // Read part refs in the form context, then load each part's detail row.
        $parts = $this->loadFormParts($refId, $refsSchema);
        if ($parts === []) {
            $report->incr('skipped');
            $report->warn(sprintf(
                'FormPage refId=%d (kuma_node=%d) has no form parts in context "%s"',
                $refId,
                $nodeId,
                self::FORM_CONTEXT,
            ));
            return;
        }

        $title = $this->formTitle($translation);
        $handle = $this->formHandle($translation, $refId);

        if ($opts->dryRun) {
            $report->incr($existingId === null ? 'wouldCreate' : 'wouldUpdate');
            $report->warn(sprintf('[dry-run] would migrate FormPage refId=%d → %s (%s)', $refId, $title, $handle));
            return;
        }

        $form = $existingId !== null
            ? Form::find()->id($existingId)->one()
            : null;
        if ($form === null) {
            $form = new Form();
        }
        $form->title = $title;
        $form->handle = $handle;

        // Apply default-template if Formie has one configured. Idempotent.
        $templateId = Formie::$plugin->getSettings()->getDefaultFormTemplateId();
        if ($templateId) {
            $form->templateId = $templateId;
        }

        $submitLabel = $this->extractSubmitLabel($parts);
        if ($submitLabel !== null && $submitLabel !== '') {
            $form->settings->submitActionMessage = '';
            // Formie's submit-button label lives on the page, not the form
            // settings. Captured here for posterity; the Page configuration
            // step below reads the same value.
        }

        $fields = [];
        $usedHandles = [];
        foreach ($parts as $part) {
            $field = $this->buildField($part, $usedHandles, $report);
            if ($field !== null) {
                $fields[] = $field;
            }
        }

        if ($fields === []) {
            $report->incr('skipped');
            $report->warn(sprintf('FormPage refId=%d produced no Formie fields after mapping', $refId));
            return;
        }

        $fieldLayout = $this->assembleFieldLayout($fields, $submitLabel);
        $form->setFormLayout($fieldLayout);

        if (!Craft::$app->elements->saveElement($form)) {
            $report->incr('failed');
            $report->warn(sprintf(
                'Formie saveElement refused FormPage refId=%d — %s',
                $refId,
                json_encode($form->getErrors()),
            ));
            return;
        }

        $this->stateService->record(
            source: self::STATE_SOURCE,
            key: $sourceKey,
            targetType: 'formie_form',
            targetId: (int) $form->id,
            targetUid: (string) $form->uid,
            meta: [
                'kumaNodeId' => $nodeId,
                'fieldCount' => count($fields),
                'submitLabel' => $submitLabel,
            ],
        );
        $report->incr($existingId === null ? 'created' : 'updated');
    }

    /**
     * Pick the NL translation as canonical (or first available locale).
     *
     * @return ?array{lang: string, title: string, slug: string, ref_id: int}
     */
    private function canonicalTranslation(int $nodeId): ?array
    {
        $rows = $this->legacyDb->queryAll(
            'SELECT t.lang, t.title, t.slug, v.ref_id'
            . ' FROM ' . KunstmaanCoreTables::NODE_TRANSLATIONS . ' t'
            . ' LEFT JOIN ' . KunstmaanCoreTables::NODE_VERSIONS . ' v'
            . '   ON v.id = t.public_node_version_id AND v.type = \'public\''
            . ' WHERE t.node_id = :id AND t.online = 1'
            . ' ORDER BY (t.lang = \'nl\') DESC, t.lang',
            [':id' => $nodeId],
        );
        foreach ($rows as $row) {
            $refId = isset($row['ref_id']) ? (int) $row['ref_id'] : 0;
            if ($refId > 0) {
                return [
                    'lang' => (string) ($row['lang'] ?? ''),
                    'title' => (string) ($row['title'] ?? ''),
                    'slug' => (string) ($row['slug'] ?? ''),
                    'ref_id' => $refId,
                ];
            }
        }
        return null;
    }

    /**
     * Fetch every page-part ref attached to this FormPage in the form context,
     * sorted, with the part's detail row inlined under `detail`.
     *
     * @return list<array{type: string, sequence: int, partId: int, detail: array<string, mixed>|null}>
     */
    private function loadFormParts(int $refId, PagePartRefsSchema $refsSchema): array
    {
        $rows = $this->legacyDb->queryAll(
            'SELECT context, sequencenumber AS seq, ' . $refsSchema->selectAliases()
            . ' FROM ' . KunstmaanCoreTables::PAGE_PART_REFS
            . ' WHERE pageEntityname = :pe AND pageId = :pid AND context = :ctx'
            . ' ORDER BY sequencenumber ASC',
            [
                ':pe' => self::FORM_PAGE_FQCN,
                ':pid' => $refId,
                ':ctx' => self::FORM_CONTEXT,
            ],
        );
        $out = [];
        foreach ($rows as $row) {
            $type = (string) ($row['page_part_entityname'] ?? '');
            $partId = (int) ($row['page_part_id'] ?? 0);
            if ($type === '' || $partId === 0) {
                continue;
            }
            $out[] = [
                'type' => $type,
                'sequence' => (int) ($row['seq'] ?? 0),
                'partId' => $partId,
                'detail' => $this->loadPartDetail($type, $partId),
            ];
        }
        return $out;
    }

    /**
     * Lookup the detail row for a known form-field PagePart type. Unknown
     * types return null — caller treats them as skipped.
     *
     * @return array<string, mixed>|null
     */
    private function loadPartDetail(string $type, int $partId): ?array
    {
        $table = match ($type) {
            'Kunstmaan\\FormBundle\\Entity\\PageParts\\SingleLineTextPagePart' => 'kuma_single_line_text_page_parts',
            'Kunstmaan\\FormBundle\\Entity\\PageParts\\MultiLineTextPagePart' => 'kuma_multi_line_text_page_parts',
            'Kunstmaan\\FormBundle\\Entity\\PageParts\\EmailPagePart' => 'kuma_email_page_parts',
            'Kunstmaan\\FormBundle\\Entity\\PageParts\\CheckboxPagePart' => 'kuma_checkbox_page_parts',
            'Kunstmaan\\FormBundle\\Entity\\PageParts\\ChoicePagePart' => 'kuma_choice_page_parts',
            'Kunstmaan\\FormBundle\\Entity\\PageParts\\FileUploadPagePart' => 'kuma_file_upload_page_parts',
            'Kunstmaan\\FormBundle\\Entity\\PageParts\\SubmitButtonPagePart' => 'kuma_submit_button_page_parts',
            default => null,
        };
        if ($table === null) {
            return null;
        }
        $row = $this->legacyDb->queryAll(
            sprintf('SELECT * FROM %s WHERE id = :id LIMIT 1', $table),
            [':id' => $partId],
        );
        return $row[0] ?? null;
    }

    /**
     * Find the SubmitButtonPagePart's label across the form's parts (if any).
     *
     * @param list<array{type: string, detail: array<string, mixed>|null}> $parts
     */
    private function extractSubmitLabel(array $parts): ?string
    {
        foreach ($parts as $part) {
            if ($part['type'] === 'Kunstmaan\\FormBundle\\Entity\\PageParts\\SubmitButtonPagePart') {
                $label = trim((string) ($part['detail']['label'] ?? ''));
                return $label !== '' ? $label : null;
            }
        }
        return null;
    }

    /**
     * Map a single source PagePart row into a Formie field instance, or
     * `null` to skip non-field parts.
     *
     * Mutates `$usedHandles` to track handle uniqueness within this form.
     *
     * @param array{type: string, detail: array<string, mixed>|null} $part
     * @param array<string, true> $usedHandles
     */
    private function buildField(array $part, array &$usedHandles, MigrationReport $report): mixed
    {
        $detail = $part['detail'];
        if ($detail === null) {
            return null;
        }
        $driver = $this->drivers[$part['type']] ?? null;
        if ($driver === null) {
            return null;
        }
        $field = $driver($detail);
        if ($field === null) {
            return null;
        }

        $label = (string) $field->label;
        $field->handle = $this->uniqueHandle($label, $usedHandles);
        $usedHandles[$field->handle] = true;

        return $field;
    }

    /**
     * Build the handler map covering every supported source PagePart type.
     * Each handler accepts the legacy detail-row array and returns a fully
     * populated Formie field (label + per-type properties) — handle is
     * assigned by the caller for global form-uniqueness.
     *
     * @return array<string, callable>
     */
    private function buildDrivers(): array
    {
        return [
            'Kunstmaan\\FormBundle\\Entity\\PageParts\\SingleLineTextPagePart' => function (array $row): SingleLineText {
                $f = new SingleLineText();
                $this->applyFieldDefaults($f);
                $f->label = (string) ($row['label'] ?? '');
                $f->required = (bool) ($row['required'] ?? false);
                return $f;
            },
            'Kunstmaan\\FormBundle\\Entity\\PageParts\\MultiLineTextPagePart' => function (array $row): MultiLineText {
                $f = new MultiLineText();
                $this->applyFieldDefaults($f);
                $f->label = (string) ($row['label'] ?? '');
                $f->required = (bool) ($row['required'] ?? false);
                return $f;
            },
            'Kunstmaan\\FormBundle\\Entity\\PageParts\\EmailPagePart' => function (array $row): Email {
                $f = new Email();
                $this->applyFieldDefaults($f);
                $f->label = (string) ($row['label'] ?? '');
                $f->required = (bool) ($row['required'] ?? false);
                return $f;
            },
            'Kunstmaan\\FormBundle\\Entity\\PageParts\\CheckboxPagePart' => function (array $row): Agree {
                // Single-checkbox terms-of-service pattern → Formie's Agree
                // field. Formie's Agree wraps a description and yields
                // boolean true/false on submit. The description column
                // expects a ProseMirror node array; convert the source
                // label string via Formie's HTML→ProseMirror Renderer.
                $f = new Agree();
                $this->applyFieldDefaults($f);
                $label = (string) ($row['label'] ?? '');
                $f->label = $label;
                $f->description = $this->htmlToProseMirror($label);
                $f->required = (bool) ($row['required'] ?? false);
                return $f;
            },
            'Kunstmaan\\FormBundle\\Entity\\PageParts\\ChoicePagePart' => function (array $row) {
                $multiple = (bool) ($row['multiple'] ?? false);
                $expanded = (bool) ($row['expanded'] ?? false);
                $options = $this->parseChoices((string) ($row['choices'] ?? ''));
                if ($multiple) {
                    $f = new Checkboxes();
                } elseif ($expanded) {
                    $f = new Radio();
                } else {
                    $f = new Dropdown();
                }
                $this->applyFieldDefaults($f);
                $f->label = (string) ($row['label'] ?? '');
                $f->required = (bool) ($row['required'] ?? false);
                $f->options = $options;
                return $f;
            },
            'Kunstmaan\\FormBundle\\Entity\\PageParts\\FileUploadPagePart' => function (array $row): FileUpload {
                $f = new FileUpload();
                $this->applyFieldDefaults($f);
                $f->label = (string) ($row['label'] ?? '');
                $f->required = (bool) ($row['required'] ?? false);
                return $f;
            },
            // SubmitButtonPagePart is handled separately via extractSubmitLabel
            // — Formie has an implicit submit, no field for it.
            'Kunstmaan\\FormBundle\\Entity\\PageParts\\SubmitButtonPagePart' => static fn (array $row) => null,
        ];
    }

    /**
     * Apply Formie's per-field defaults (column widths, instructions, etc).
     * Method name was `getAllFieldDefaults` in Formie 2.x; renamed to
     * `getFieldTypeDefaults` in 3.x.
     */
    private function applyFieldDefaults(\verbb\formie\base\FieldInterface $field): void
    {
        $defaults = method_exists($field, 'getFieldTypeDefaults')
            ? $field->getFieldTypeDefaults()
            : (method_exists($field, 'getAllFieldDefaults') ? $field->getAllFieldDefaults() : []);
        if (!empty($defaults)) {
            Craft::configure($field, $defaults);
        }
    }

    /**
     * Convert a plain string into Formie's ProseMirror node array shape.
     * Used for the Agree field's `description` property which expects
     * a structured array, not a flat string.
     *
     * @return list<array<string, mixed>>
     */
    private function htmlToProseMirror(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }
        $renderer = new \verbb\formie\prosemirror\toprosemirror\Renderer();
        $rendered = $renderer->render('<p>' . htmlspecialchars($text, ENT_QUOTES | ENT_HTML5) . '</p>');
        $content = $rendered['content'] ?? null;
        return is_array($content) ? array_values($content) : [];
    }

    /**
     * Parse the legacy `choices` column. Kunstmaan stores comma- or
     * newline-separated option labels in a single string. Each entry
     * becomes a Formie option `{label, value, isDefault}`.
     *
     * @return list<array{label: string, value: string, isDefault: bool}>
     */
    private function parseChoices(string $choices): array
    {
        $choices = trim($choices);
        if ($choices === '') {
            return [];
        }
        $parts = preg_split('/[\r\n,]+/', $choices) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $label = trim((string) $part);
            if ($label === '') {
                continue;
            }
            $out[] = [
                'label' => $label,
                'value' => $label,
                'isDefault' => false,
            ];
        }
        return $out;
    }

    /**
     * Slugify a label into a Formie-compatible field handle. Handles must
     * be unique within a form — append `_2`, `_3`, ... on collisions.
     *
     * @param array<string, true> $usedHandles
     */
    private function uniqueHandle(string $label, array $usedHandles): string
    {
        $base = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', $label), '_'));
        if ($base === '' || ctype_digit($base)) {
            $base = 'field';
        }
        // Camel-case the snake_case base for Formie's typical handle convention.
        $parts = explode('_', $base);
        $handle = array_shift($parts) ?: 'field';
        foreach ($parts as $segment) {
            $handle .= ucfirst($segment);
        }
        $candidate = $handle;
        $i = 2;
        while (isset($usedHandles[$candidate])) {
            $candidate = $handle . $i;
            $i++;
        }
        return $candidate;
    }

    /**
     * Slugify a translation slug + refId into a globally-unique form handle.
     *
     * @param array{slug: string, title: string} $translation
     */
    private function formHandle(array $translation, int $refId): string
    {
        $base = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $translation['slug'] ?: $translation['title']), '-'));
        if ($base === '') {
            $base = 'form';
        }
        $base = str_replace('-', '_', $base);
        // Camel-case + suffix the legacy refId to guarantee global uniqueness
        // across re-runs (the state-table upsert keys off this anyway).
        $parts = explode('_', $base);
        $handle = array_shift($parts) ?: 'form';
        foreach ($parts as $segment) {
            $handle .= ucfirst($segment);
        }
        return $handle . 'Form' . $refId;
    }

    /**
     * @param array{title: string, slug: string} $translation
     */
    private function formTitle(array $translation): string
    {
        $title = trim((string) ($translation['title'] ?? ''));
        return $title !== '' ? $title : 'Migrated form';
    }

    /**
     * Wrap a flat field list into Formie 3.x's FieldLayout shape:
     * one Page → one Row per field → field instances. Operators can
     * rebuild the multi-column grid in the Formie CP afterwards.
     *
     * @param list<\verbb\formie\base\FieldInterface> $fields
     */
    private function assembleFieldLayout(array $fields, ?string $submitLabel): FieldLayout
    {
        $fieldLayout = new FieldLayout(['type' => Form::class]);

        $rows = [];
        foreach ($fields as $sortOrder => $field) {
            $row = new FieldLayoutRow(['sortOrder' => $sortOrder]);
            $row->setFields([$field]);
            $rows[] = $row;
        }

        $page = new FieldLayoutPage([
            'label' => 'Page 1',
            'sortOrder' => 0,
        ]);
        $page->setRows($rows);
        if ($submitLabel !== null && $submitLabel !== '') {
            $page->setPageSettings(['submitButtonLabel' => $submitLabel]);
        }

        $fieldLayout->setPages([$page]);

        return $fieldLayout;
    }

    /**
     * Delete every Formie form this migrator owns (per state.source='form').
     */
    public function truncate(): int
    {
        if (Craft::$app->plugins->getPlugin('formie') === null
            || !class_exists(Form::class)
            || Formie::$plugin === null
        ) {
            return 0;
        }

        $deleted = 0;
        foreach ($this->stateService->all(self::STATE_SOURCE) as $row) {
            $formId = (int) ($row['targetId'] ?? 0);
            if ($formId <= 0) {
                continue;
            }
            try {
                $form = Form::find()->id($formId)->one();
                if ($form !== null) {
                    Craft::$app->elements->deleteElement($form, true);
                    $deleted++;
                }
            } catch (Throwable $e) {
                Craft::warning(
                    sprintf('FormMigrationService::truncate: could not delete form id=%d — %s', $formId, $e->getMessage()),
                    __METHOD__,
                );
            }
            $sourceKey = (string) ($row['sourceKey'] ?? '');
            if ($sourceKey !== '') {
                $this->stateService->forget(self::STATE_SOURCE, $sourceKey);
            }
        }
        return $deleted;
    }
}
