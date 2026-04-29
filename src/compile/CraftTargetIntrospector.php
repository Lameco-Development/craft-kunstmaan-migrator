<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\compile;

use yii\base\Component;

/**
 * Validates compiled mapping targets against a normalized Craft schema array.
 *
 * The service is intentionally schema-facade based so unit tests can validate
 * behavior without booting a project-specific Craft install.
 */
final class CraftTargetIntrospector extends Component
{
    /**
     * @param array<string, mixed> $compiled
     * @param array<string, mixed> $schema
     * @return list<string>
     */
    public function validate(array $compiled, array $schema): array
    {
        $result = $this->validateWithSeverity($compiled, $schema);
        return array_values(array_unique(array_merge($result['fatal'], $result['warnings'])));
    }

    /**
     * @param array<string, mixed> $compiled
     * @param array<string, mixed> $schema
     * @return array{fatal: list<string>, warnings: list<string>}
     */
    public function validateWithSeverity(array $compiled, array $schema): array
    {
        $fatal = [];
        $warnings = [];
        $sections = (array) ($schema['sections'] ?? []);
        $entryTypes = (array) ($schema['entryTypes'] ?? []);

        foreach ((array) ($compiled['sections'] ?? []) as $sectionKey => $sectionSpec) {
            if (!is_array($sectionSpec)) {
                continue;
            }
            $sectionHandle = (string) ($sectionSpec['section'] ?? $sectionKey);
            $entryType = (string) ($sectionSpec['entryType'] ?? $sectionKey);
            if ($sectionHandle === '' || !isset($sections[$sectionHandle])) {
                $fatal[] = sprintf('Craft target section `%s` for entryType `%s` does not exist.', $sectionHandle !== '' ? $sectionHandle : '∅', $entryType);
                continue;
            }
            $allowedEntryTypes = (array) ($sections[$sectionHandle]['entryTypes'] ?? []);
            if ($entryType !== '' && $allowedEntryTypes !== [] && !in_array($entryType, $allowedEntryTypes, true)) {
                $fatal[] = sprintf('Craft target section `%s` does not allow entryType `%s` (allowed: %s).', $sectionHandle, $entryType, implode(', ', $allowedEntryTypes));
            }
        }

        foreach ((array) ($compiled['nodeClasses'] ?? []) as $fqcn => $nodeSpec) {
            if (!is_array($nodeSpec)) {
                continue;
            }
            $sectionKey = (string) ($nodeSpec['section'] ?? '');
            $entryType = (string) ($compiled['sections'][$sectionKey]['entryType'] ?? $sectionKey);
            if ($entryType === '' || !isset($entryTypes[$entryType])) {
                $fatal[] = sprintf('%s targets missing Craft entryType `%s`.', (string) $fqcn, $entryType !== '' ? $entryType : '∅');
                continue;
            }
            $fields = (array) ($entryTypes[$entryType]['fields'] ?? []);
            foreach ((array) ($nodeSpec['fields'] ?? []) as $targetHandle => $fieldSpec) {
                if (!is_array($fieldSpec)) {
                    continue;
                }
                $target = (string) $targetHandle;
                if ($this->isNativeEntryProperty($target)) {
                    continue;
                }
                if (str_contains($target, '.')) {
                    $this->validateMatrixTarget($warnings, (string) $fqcn, $entryType, $target, $fieldSpec, $fields);
                    continue;
                }
                if (!isset($fields[$target])) {
                    $warnings[] = sprintf('%s target field `%s` missing on Craft entryType `%s`.', (string) $fqcn, $target, $entryType);
                    continue;
                }
                $this->validateFieldKind($warnings, (string) $fqcn, $target, $fieldSpec, (array) $fields[$target], $schema);
            }
        }

        $this->validateOptionalAdapter($warnings, 'SEOmatic', 'seomatic', $compiled, $schema);
        $this->validateOptionalAdapter($warnings, 'Retour', 'retour', $compiled, $schema);

        sort($fatal);
        sort($warnings);
        return [
            'fatal' => array_values(array_unique($fatal)),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * @param list<string> $warnings
     * @param array<string, mixed> $fieldSpec
     * @param array<string, mixed> $fields
     */
    private function validateMatrixTarget(array &$warnings, string $fqcn, string $entryType, string $target, array $fieldSpec, array $fields): void
    {
        [$matrixHandle, $subHandle] = array_pad(explode('.', $target, 2), 2, '');
        if ($matrixHandle === '' || $subHandle === '' || !isset($fields[$matrixHandle])) {
            $warnings[] = sprintf('%s Matrix target `%s` missing on Craft entryType `%s`.', $fqcn, $target, $entryType);
            return;
        }
        $matrix = (array) $fields[$matrixHandle];
        if ((string) ($matrix['type'] ?? '') !== 'matrix') {
            $warnings[] = sprintf('%s target `%s` is dotted but `%s` is not a Matrix field.', $fqcn, $target, $matrixHandle);
            return;
        }
        $blockType = (string) ($fieldSpec['blockType'] ?? $fieldSpec['handlerOptions']['blockType'] ?? '');
        $blocks = (array) ($matrix['blocks'] ?? []);
        if ($blockType === '') {
            foreach ($blocks as $candidateHandle => $candidate) {
                $candidateFields = (array) (($candidate['fields'] ?? null) ?: []);
                if (in_array($subHandle, $candidateFields, true)) {
                    $blockType = (string) $candidateHandle;
                    break;
                }
            }
            if ($blockType === '') {
                $blockType = (string) array_key_first($blocks);
            }
        }
        if ($blockType === '' || !isset($blocks[$blockType])) {
            $warnings[] = sprintf('%s Matrix target `%s` references missing block type `%s`.', $fqcn, $target, $blockType !== '' ? $blockType : '∅');
            return;
        }
        $blockFields = (array) ($blocks[$blockType]['fields'] ?? []);
        if ($this->isNativeEntryProperty($subHandle)) {
            return;
        }
        if (!in_array($subHandle, $blockFields, true)) {
            $warnings[] = sprintf('%s Matrix target `%s` missing block field `%s` on block `%s`.', $fqcn, $target, $subHandle, $blockType);
        }
    }

    /**
     * @param list<string> $warnings
     * @param array<string, mixed> $fieldSpec
     * @param array<string, mixed> $fieldSchema
     * @param array<string, mixed> $schema
     */
    private function validateFieldKind(array &$warnings, string $fqcn, string $target, array $fieldSpec, array $fieldSchema, array $schema): void
    {
        $handler = (string) ($fieldSpec['handler'] ?? '');
        $type = (string) ($fieldSchema['type'] ?? '');
        if ($handler === 'asset' || $type === 'asset') {
            $volume = (string) ($fieldSpec['handlerOptions']['volume'] ?? '');
            $allowed = (array) ($fieldSchema['volumes'] ?? $schema['volumes'] ?? []);
            if ($volume !== '' && $allowed !== [] && !in_array($volume, $allowed, true)) {
                $warnings[] = sprintf('%s asset target `%s` references missing/disallowed volume `%s`.', $fqcn, $target, $volume);
            }
        }
        if ($handler === 'relation' || $type === 'entries') {
            $sources = (array) ($fieldSpec['handlerOptions']['sources'] ?? []);
            $allowedSources = (array) ($fieldSchema['sources'] ?? []);
            if ($sources !== [] && $allowedSources !== []) {
                foreach ($sources as $source) {
                    if (!in_array($source, $allowedSources, true)) {
                        $warnings[] = sprintf('%s Entries target `%s` references disallowed source `%s`.', $fqcn, $target, (string) $source);
                    }
                }
            }
        }
    }

    /** @param list<string> $warnings */
    private function validateOptionalAdapter(array &$warnings, string $label, string $key, array $compiled, array $schema): void
    {
        if (!isset($compiled[$key])) {
            return;
        }
        $enabled = (bool) ($schema['plugins'][$key] ?? false);
        if (!$enabled) {
            $warnings[] = sprintf('%s adapter target present but %s plugin is not enabled; target validation skipped as out-of-scope.', $label, $label);
        }
    }

    private function isNativeEntryProperty(string $handle): bool
    {
        return in_array($handle, ['title', 'slug', 'postDate', 'expiryDate', 'enabled', 'authorId', 'parentId'], true);
    }
}
