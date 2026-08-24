<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\payload;

use Craft;

use craft\fieldlayoutelements\CustomField;
use craft\fields\Matrix;
use Lameco\KumaCompile\Payload\SchemaGateway;

/**
 * Production `SchemaGateway` — thin read-only wrapper over
 * `Craft::$app->getEntries()` / `getSites()` and field layouts. No caching:
 * Craft's own services already memoize section/entry-type/site lookups.
 */
final class CraftSchemaGateway implements SchemaGateway
{
    public function sectionByHandle(string $handle): ?array
    {
        $section = Craft::$app->getEntries()->getSectionByHandle($handle);
        if ($section === null) {
            return null;
        }

        return ['id' => (int) $section->id, 'handle' => (string) $section->handle];
    }

    public function entryTypeByHandle(string $handle): ?array
    {
        $entryType = Craft::$app->getEntries()->getEntryTypeByHandle($handle);
        if ($entryType === null) {
            return null;
        }

        return [
            'id' => (int) $entryType->id,
            'handle' => (string) $entryType->handle,
            'hasTitleFormat' => !$entryType->hasTitleField && $entryType->titleFormat !== null,
        ];
    }

    public function siteByHandle(string $handle): ?array
    {
        $site = Craft::$app->getSites()->getSiteByHandle($handle);
        if ($site === null) {
            return null;
        }

        return ['id' => (int) $site->id, 'handle' => (string) $site->handle];
    }

    public function primarySite(): array
    {
        $site = Craft::$app->getSites()->getPrimarySite();

        return ['id' => (int) $site->id, 'handle' => (string) $site->handle];
    }

    public function fieldSlotsFor(string $entryTypeHandle): array
    {
        $layout = $this->fieldLayoutFor($entryTypeHandle);
        if ($layout === null) {
            return [];
        }

        $slots = [];
        foreach ($layout->getTabs() as $tab) {
            foreach ($tab->getElements() as $element) {
                if (!($element instanceof CustomField)) {
                    continue;
                }

                $field = $element->getField();

                // The layout element's handle, not the field's. A placement can override it —
                // `commonPageBuilder` is placed as `pageBuilder` — and the mapping names the
                // placement, because that is what the payload has to be keyed by. Reading the
                // base handle here made the live schema disagree with the project-config reader
                // on exactly the fields a page builder is made of.
                $handle = (string) $element->attribute();
                $nested = [];

                if ($field instanceof Matrix) {
                    foreach ($field->getEntryTypes() as $entryType) {
                        $nested[] = (string) $entryType->handle;
                    }
                }

                $slots[$handle] = [
                    'type' => (new \ReflectionClass($field))->getShortName(),
                    'required' => (bool) $element->required,
                    'nested' => $nested,
                    'propagationMethod' => self::propagationOf($field),
                ];
            }
        }

        return $slots;
    }

    public function fieldHandlesFor(string $entryTypeHandle): array
    {
        $layout = $this->fieldLayoutFor($entryTypeHandle);
        if ($layout === null) {
            return [];
        }

        return array_map(static fn($field): string => (string) $field->handle, $layout->getCustomFields());
    }

    public function blockTypesFor(string $entryTypeHandle, string $fieldHandle): array
    {
        $layout = $this->fieldLayoutFor($entryTypeHandle);
        if ($layout === null) {
            return [];
        }

        foreach ($layout->getCustomFields() as $field) {
            if ($field->handle !== $fieldHandle) {
                continue;
            }
            if (!($field instanceof Matrix)) {
                return [];
            }

            return array_map(static fn($blockType): string => (string) $blockType->handle, $field->getEntryTypes());
        }

        return [];
    }

    /**
     * How a Matrix shares its blocks across sites.
     *
     * Craft 5 types this as a `PropagationMethod` enum; a non-Matrix field has no such
     * property and reads as null, which every caller has to treat as "no opinion" anyway.
     */
    private static function propagationOf(\craft\base\FieldInterface $field): ?string
    {
        $method = $field instanceof Matrix ? $field->propagationMethod : null;

        if ($method instanceof \BackedEnum) {
            return (string) $method->value;
        }

        return is_string($method) ? $method : null;
    }

    private function fieldLayoutFor(string $entryTypeHandle): ?\craft\models\FieldLayout
    {
        $entryType = Craft::$app->getEntries()->getEntryTypeByHandle($entryTypeHandle);

        return $entryType?->getFieldLayout();
    }
}
