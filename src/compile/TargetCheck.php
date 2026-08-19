<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\compile;

use lameco\kunstmaanmigrator\mapping\Mapping;

/**
 * Checks a mapping against the target Craft content model.
 *
 * The shape check in Schema asks "is this mapping well-formed"; this asks "does anything it
 * names actually exist". They are separate because a mapping is authored before the target
 * checkout is necessarily to hand, and the shape check must work without it.
 */
final class TargetCheck
{
    public function __construct(private readonly TargetModel $schema)
    {
    }

    /** @return list<string> */
    public function check(Mapping $mapping): array
    {
        $errors = [];

        foreach ($mapping->pages() as $name => $spec) {
            if (!is_array($spec) || isset($spec['manual'])) {
                continue;
            }

            $section = (string) ($spec['section'] ?? '');
            $entryType = (string) ($spec['entryType'] ?? '');

            if ($section !== '' && !$this->schema->hasSection($section)) {
                $errors[] = sprintf('page `%s`: no section `%s` in Craft', $name, $section);
            }

            if ($entryType !== '' && !$this->schema->hasEntryType($entryType)) {
                $errors[] = sprintf('page `%s`: no entry type `%s` in Craft', $name, $entryType);

                continue;
            }

            // A page's own columns land in real fields too, so they need the same check the
            // parts get. Without it a page map is free to name fields that do not exist.
            foreach (array_keys($spec['map'] ?? []) as $target) {
                if ($this->schema->slot($entryType, (string) $target) === null) {
                    $errors[] = sprintf(
                        'page `%s`: entry type `%s` has no field `%s`',
                        $name,
                        $entryType,
                        $target,
                    );
                }
            }
        }

        foreach ($mapping->parts() as $name => $spec) {
            if (!is_array($spec) || isset($spec['drop'], $spec['manual'])) {
                continue;
            }

            $block = $spec['block'] ?? null;

            if (!is_string($block) || $block === '') {
                continue;
            }

            if (!$this->schema->hasEntryType($block)) {
                $errors[] = sprintf('part `%s`: no block entry type `%s` in Craft', $name, $block);

                continue;
            }

            foreach (array_keys($spec['map'] ?? []) as $target) {
                $error = $this->checkPath($block, (string) $target);

                if ($error !== null) {
                    $errors[] = sprintf('part `%s`: %s', $name, $error);
                }
            }

            foreach ($spec['children'] ?? [] as $field => $child) {
                $slot = $this->schema->slot($block, (string) $field);

                if ($slot === null) {
                    $errors[] = sprintf('part `%s`: block `%s` has no field `%s`', $name, $block, $field);

                    continue;
                }

                if (!($slot['type'] === 'Matrix')) {
                    $errors[] = sprintf('part `%s`: `%s.%s` is %s, not a Matrix', $name, $block, $field, $slot['type']);

                    continue;
                }

                $nested = $this->schema->nestedTypeOf($block, (string) $field);

                foreach (array_keys($child['map'] ?? []) as $target) {
                    if ($nested !== null && $this->schema->slot($nested, (string) $target) === null) {
                        $errors[] = sprintf(
                            'part `%s`: nested `%s` has no field `%s`',
                            $name,
                            $nested,
                            $target,
                        );
                    }
                }
            }

            foreach ($spec['promote'] ?? [] as $table => $promo) {
                foreach ([['section', 'hasSection'], ['entryType', 'hasEntryType']] as [$key, $method]) {
                    $value = (string) ($promo[$key] ?? '');

                    if ($value !== '' && !$this->schema->{$method}($value)) {
                        $errors[] = sprintf('part `%s`, promote `%s`: no %s `%s` in Craft', $name, $table, $key, $value);
                    }
                }

                $relation = (string) ($promo['relation'] ?? '');

                if ($relation !== '' && $this->schema->slot($block, $relation) === null) {
                    $errors[] = sprintf('part `%s`: block `%s` has no relation field `%s`', $name, $block, $relation);
                }
            }
        }

        return $errors;
    }

    /**
     * Required fields the mapping never supplies a value for — not an error, because a field
     * may have a default, but the thing you want to know before a load fails.
     *
     * @return list<string>
     */
    public function unfilledRequired(Mapping $mapping): array
    {
        $warnings = [];

        foreach ($mapping->parts() as $name => $spec) {
            $block = $spec['block'] ?? null;

            if (!is_string($block) || !$this->schema->hasEntryType($block)) {
                continue;
            }

            $supplied = array_map(
                static fn (string $p): string => explode('.', str_replace(['[0]'], '', $p))[0],
                array_keys($spec['map'] ?? []),
            );
            $supplied = array_merge($supplied, array_keys($spec['children'] ?? []));

            foreach ($this->schema->requiredFields($block) as $required) {
                if (!in_array($required, $supplied, true)) {
                    $warnings[] = sprintf('%s -> %s.%s is required but never mapped', $name, $block, $required);
                }
            }
        }

        return $warnings;
    }

    /** `heading`, `contentColumns[0].heading` — check each hop exists. */
    private function checkPath(string $block, string $path): ?string
    {
        if (preg_match('/^(\w+)\[(\d+)\]\.(\w+)$/', $path, $m) === 1) {
            $slot = $this->schema->slot($block, $m[1]);

            if ($slot === null) {
                return sprintf('block `%s` has no field `%s`', $block, $m[1]);
            }

            $nested = $this->schema->nestedTypeOf($block, $m[1]);

            if ($nested !== null && $this->schema->slot($nested, $m[3]) === null) {
                return sprintf('nested `%s` has no field `%s`', $nested, $m[3]);
            }

            return null;
        }

        return $this->schema->slot($block, $path) === null
            ? sprintf('block `%s` has no field `%s`', $block, $path)
            : null;
    }
}
