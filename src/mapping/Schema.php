<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\mapping;

/**
 * Shape check for a mapping file, with no database involved.
 *
 * This exists because the failure it catches is silent: a mistyped key in a hand-edited
 * mapping does not throw, it just means a rule never fires and content quietly does not
 * migrate. Unknown keys are errors, not warnings.
 */
final class Schema
{
    private const TOP_LEVEL = [
        'version', 'environments', 'merge', 'pages', 'defaults',
        'sequence', 'parts', 'forms', 'globals', 'redirects', 'transforms', 'unmapped',
    ];

    private const PART_KEYS = [
        'live', 'table', 'block', 'switch', 'map', 'children', 'promote', 'ignore', 'absorbInto',
        'source', 'conflict', 'consumedBy', 'drop', 'manual', 'todo', 'note',
    ];

    private const CHILD_KEYS = ['table', 'fk', 'order', 'map', 'ignore', 'todo'];

    private const SEQUENCE_KEYS = ['id', 'match', 'guard', 'action', 'block', 'map', 'runs', 'else', 'note'];

    private const CONFLICT_KEYS = ['status', 'artifact', 'spec', 'note'];

    private const PROMOTE_KEYS = ['section', 'entryType', 'relation', 'dedupe', 'map', 'ignore', 'todo'];

    /** @return list<string> violations; empty means the shape is sound */
    public function validate(Mapping $mapping): array
    {
        $errors = [];

        $this->checkTopLevel($mapping, $errors);
        $this->checkEnvironments($mapping, $errors);
        $this->checkParts($mapping, $errors);
        $this->checkSequence($mapping, $errors);
        $this->checkLaneCollisions($mapping, $errors);

        return $errors;
    }

    /** @param list<string> $errors */
    private function checkTopLevel(Mapping $mapping, array &$errors): void
    {
        foreach (array_diff(array_keys($mapping->all()), self::TOP_LEVEL) as $key) {
            $errors[] = sprintf('unknown top-level key `%s`', $key);
        }

        if ($mapping->version() !== 1) {
            $errors[] = sprintf('unsupported mapping version `%s` (this tool speaks version 1)', $mapping->version());
        }

        if ($mapping->environments() === []) {
            $errors[] = 'no `environments:` — there is nothing to read from';
        }
    }

    /** @param list<string> $errors */
    private function checkEnvironments(Mapping $mapping, array &$errors): void
    {
        foreach ($mapping->environments() as $env => $spec) {
            if (!is_array($spec)) {
                $errors[] = sprintf('environment `%s` is not a mapping', $env);

                continue;
            }

            if (($spec['database'] ?? '') === '') {
                $errors[] = sprintf('environment `%s` has no `database:`', $env);
            }

            if (($spec['locales'] ?? []) === []) {
                $errors[] = sprintf('environment `%s` has no `locales:` — nothing would be written to any site', $env);
            }
        }
    }

    /** @param list<string> $errors */
    private function checkParts(Mapping $mapping, array &$errors): void
    {
        foreach ($mapping->parts() as $class => $spec) {
            if (!is_array($spec)) {
                $errors[] = sprintf('part `%s` is not a mapping', $class);

                continue;
            }

            foreach (array_diff(array_keys($spec), self::PART_KEYS) as $key) {
                $errors[] = sprintf('part `%s`: unknown key `%s`', $class, $key);
            }

            // Every part must resolve to exactly one disposition.
            $dispositions = array_filter([
                isset($spec['block']) ? 'block' : null,
                isset($spec['switch']) ? 'switch' : null,
                isset($spec['drop']) ? 'drop' : null,
                isset($spec['manual']) ? 'manual' : null,
                isset($spec['consumedBy']) ? 'consumedBy' : null,
            ]);

            if ($dispositions === []) {
                $errors[] = sprintf(
                    'part `%s`: no disposition — needs one of block, switch, consumedBy, drop or manual',
                    $class,
                );
            } elseif (count($dispositions) > 1) {
                $errors[] = sprintf('part `%s`: conflicting dispositions (%s)', $class, implode(', ', $dispositions));
            }

            foreach ($spec['children'] ?? [] as $field => $child) {
                if (!is_array($child)) {
                    $errors[] = sprintf('part `%s`: child `%s` is not a mapping', $class, $field);

                    continue;
                }

                foreach (array_diff(array_keys($child), self::CHILD_KEYS) as $key) {
                    $errors[] = sprintf('part `%s`, child `%s`: unknown key `%s`', $class, $field, $key);
                }

                foreach (['table', 'fk'] as $required) {
                    if (($child[$required] ?? '') === '') {
                        $errors[] = sprintf('part `%s`, child `%s`: missing `%s:`', $class, $field, $required);
                    }
                }
            }

            // A promoted collection becomes entries elsewhere plus a relation back, so it
            // needs a destination and the field that points at it.
            foreach ($spec['promote'] ?? [] as $childTable => $promo) {
                if (!is_array($promo)) {
                    $errors[] = sprintf('part `%s`: promote `%s` is not a mapping', $class, $childTable);

                    continue;
                }

                foreach (array_diff(array_keys($promo), self::PROMOTE_KEYS) as $key) {
                    $errors[] = sprintf('part `%s`, promote `%s`: unknown key `%s`', $class, $childTable, $key);
                }

                foreach (['section', 'entryType', 'relation'] as $required) {
                    if (($promo[$required] ?? '') === '') {
                        $errors[] = sprintf(
                            'part `%s`, promote `%s`: missing `%s:`',
                            $class,
                            $childTable,
                            $required,
                        );
                    }
                }

                if (isset($spec['children'][$childTable])) {
                    $errors[] = sprintf(
                        'part `%s`: `%s` is both promoted and a Matrix child',
                        $class,
                        $childTable,
                    );
                }
            }

            foreach (array_diff(array_keys($spec['conflict'] ?? []), self::CONFLICT_KEYS) as $key) {
                $errors[] = sprintf('part `%s`, conflict: unknown key `%s`', $class, $key);
            }

            $status = $spec['conflict']['status'] ?? null;

            if ($status !== null && !in_array($status, ['open', 'decided'], true)) {
                $errors[] = sprintf('part `%s`: conflict.status must be `open` or `decided`, got `%s`', $class, $status);
            }
        }
    }

    /** @param list<string> $errors */
    private function checkSequence(Mapping $mapping, array &$errors): void
    {
        $ids = [];

        foreach ($mapping->sequence() as $i => $rule) {
            if (!is_array($rule)) {
                $errors[] = sprintf('sequence rule #%d is not a mapping', $i);

                continue;
            }

            foreach (array_diff(array_keys($rule), self::SEQUENCE_KEYS) as $key) {
                $errors[] = sprintf('sequence rule #%d: unknown key `%s`', $i, $key);
            }

            foreach (['id', 'match', 'action'] as $required) {
                if (($rule[$required] ?? '') === '') {
                    $errors[] = sprintf('sequence rule #%d: missing `%s:`', $i, $required);
                }
            }

            if (isset($rule['id'])) {
                $ids[] = $rule['id'];
            }

            if (isset($rule['action']) && !in_array($rule['action'], ['absorb', 'emit'], true)) {
                $errors[] = sprintf('sequence rule `%s`: action must be `absorb` or `emit`', $rule['id'] ?? $i);
            }
        }

        // A dangling `else:` is the kind of typo that silently drops content.
        foreach ($mapping->sequence() as $rule) {
            if (isset($rule['else']) && !in_array($rule['else'], $ids, true)) {
                $errors[] = sprintf(
                    'sequence rule `%s`: `else: %s` names no rule',
                    $rule['id'] ?? '?',
                    $rule['else'],
                );
            }
        }

        if (count($ids) !== count(array_unique($ids))) {
            $errors[] = 'sequence rule ids are not unique';
        }
    }

    /** A class claimed by two lanes has an ambiguous target. @param list<string> $errors */
    private function checkLaneCollisions(Mapping $mapping, array &$errors): void
    {
        $lanes = [
            'parts'    => array_keys($mapping->parts()),
            'forms'    => array_keys($mapping->formFields()),
            'globals'  => array_keys($mapping->globalParts()),
            'unmapped' => array_keys($mapping->unmappedParts()),
        ];

        $seen = [];

        foreach ($lanes as $lane => $classes) {
            foreach ($classes as $class) {
                if (isset($seen[$class])) {
                    $errors[] = sprintf('`%s` is claimed by both `%s` and `%s`', $class, $seen[$class], $lane);

                    continue;
                }

                $seen[$class] = $lane;
            }
        }
    }
}
