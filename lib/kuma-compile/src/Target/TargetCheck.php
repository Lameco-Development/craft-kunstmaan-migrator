<?php

declare(strict_types=1);

namespace Lameco\KumaCompile\Target;

use Lameco\KumaCompile\Mapping\Mapping;

/**
 * Checks a mapping against the target Craft content model.
 *
 * The shape check in Schema asks "is this mapping well-formed"; this asks "does anything it
 * names actually exist". They are separate because a mapping is authored before the target
 * checkout is necessarily to hand, and the shape check must work without it.
 */
final class TargetCheck
{
    public function __construct(private readonly TargetSchema $schema)
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

            if ($entryType === '') {
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

            $this->checkChildren(sprintf('page `%s`', $name), $entryType, $spec, $errors);
        }

        foreach ($mapping->entities() as $name => $spec) {
            if (!is_array($spec)) {
                continue;
            }

            $section = (string) ($spec['section'] ?? '');
            $entryType = (string) ($spec['entryType'] ?? '');

            if ($section !== '' && !$this->schema->hasSection($section)) {
                $errors[] = sprintf('entity `%s`: no section `%s` in Craft', $name, $section);
            }

            if ($entryType === '') {
                continue;
            }

            if (!$this->schema->hasEntryType($entryType)) {
                $errors[] = sprintf('entity `%s`: no entry type `%s` in Craft', $name, $entryType);

                continue;
            }

            foreach (array_keys($spec['map'] ?? []) as $target) {
                if ($this->schema->slot($entryType, (string) $target) === null) {
                    $errors[] = sprintf(
                        'entity `%s`: entry type `%s` has no field `%s`',
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

            $this->checkChildren(sprintf('part `%s`', $name), $block, $spec, $errors);

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

    /**
     * Page entry types with nowhere to put a block at all.
     *
     * `contexts:` names the Matrix a page's block stream lands in. When the target's entry type
     * has no such field, every part on every node of that type is dropped — the compiler says
     * so (`casePage has no pageBuilder — 18 parts dropped`) and says it into a run report, once
     * per node, two hours in. On the reference corpus that is 496 pageparts across 51 pages,
     * the largest single documented loss, and it is knowable from two YAML files.
     *
     * A warning rather than an error, deliberately. A page type may legitimately hold no blocks,
     * and the DSL has no way to say so yet — there is no disposition for "render these into a
     * rich-text field on the page", which is what this corpus actually wants. Failing the build
     * would leave nothing to do about it.
     *
     * @return list<string>
     */
    public function pagesWithNoBlockField(Mapping $mapping): array
    {
        $defaults = $mapping->all()['defaults']['contexts'] ?? [];
        $warnings = [];

        foreach ($mapping->pages() as $name => $spec) {
            if (!is_array($spec) || isset($spec['manual']) || isset($spec['unmapped'])) {
                continue;
            }

            $entryType = (string) ($spec['entryType'] ?? '');

            if ($entryType === '' || !$this->schema->hasEntryType($entryType)) {
                continue;
            }

            $missing = [];

            foreach (($spec['contexts'] ?? $defaults) as $target) {
                $field = is_array($target) ? (string) ($target['field'] ?? '') : '';

                if ($field !== '' && $this->schema->slot($entryType, $field) === null) {
                    $missing[$field] = true;
                }
            }

            if ($missing !== []) {
                $warnings[] = sprintf(
                    'page `%s` streams blocks into `%s`, which `%s` does not have — every part on'
                    . ' these pages is dropped',
                    $name,
                    implode('`, `', array_keys($missing)),
                    $entryType,
                );
            }
        }

        return $warnings;
    }

    /**
     * Blocks no page in the mapping can hold.
     *
     * A Matrix field names the entry types it accepts, and a part whose block is not on that
     * list is dropped at write time — 44 blocks on the reference corpus, discovered from a run
     * report. `check()` cannot see it: it validates that the block and its fields exist, which
     * they do. What is wrong is the pairing.
     *
     * The check is deliberately the total one. Whether a given part ever lands on a given page
     * type is a fact about the data, not about the mapping, so "allowed on some pages and not
     * others" is a run-time finding and the run already reports it by name. A block *no*
     * hosting field accepts is not data-dependent: every placement of that part is lost, every
     * time, and that is knowable from two YAML files before anything is compiled.
     *
     * @return list<string>
     */
    public function blocksNoPageAccepts(Mapping $mapping): array
    {
        $hosts = $this->hostingFields($mapping);

        if ($hosts === []) {
            return [];
        }

        $errors = [];

        foreach ($mapping->parts() as $name => $spec) {
            if (!is_array($spec) || isset($spec['drop']) || isset($spec['manual']) || ($spec['consumedBy'] ?? null) === 'sequence') {
                continue;
            }

            foreach ($this->blocksOf($spec) as $block) {
                if (!$this->schema->hasEntryType($block) || $this->acceptedSomewhere($block, $hosts)) {
                    continue;
                }

                $errors[] = sprintf(
                    'part `%s`: no page in the mapping accepts block `%s` — every placement is dropped at write time',
                    $name,
                    $block,
                );
            }
        }

        return $errors;
    }

    /**
     * `<page entry type>.<context field>` pairs a compiled block can be written into.
     *
     * `contexts:` names the field, per page or from `defaults:`; the compiler falls back to
     * `pageBuilder` when neither does, so this does too.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function hostingFields(Mapping $mapping): array
    {
        $defaults = $mapping->all()['defaults']['contexts'] ?? ['main' => ['field' => 'commonPageBuilder']];
        $hosts = [];

        foreach ($mapping->pages() as $spec) {
            if (!is_array($spec) || isset($spec['manual']) || isset($spec['unmapped'])) {
                continue;
            }

            $entryType = (string) ($spec['entryType'] ?? '');

            if ($entryType === '' || !$this->schema->hasEntryType($entryType)) {
                continue;
            }

            foreach (($spec['contexts'] ?? $defaults) as $target) {
                $field = is_array($target) ? (string) ($target['field'] ?? 'pageBuilder') : 'pageBuilder';
                $hosts[$entryType . '.' . $field] = [$entryType, $field];
            }
        }

        return array_values($hosts);
    }

    /** @param list<array{0: string, 1: string}> $hosts */
    private function acceptedSomewhere(string $block, array $hosts): bool
    {
        foreach ($hosts as [$entryType, $field]) {
            $slot = $this->schema->slot($entryType, $field);

            // An unrestricted Matrix accepts everything, and a field that is not there cannot
            // reject anything — `pagesWithNoBlockField()` above owns that case.
            if ($slot === null || $slot->nested === [] || in_array($block, $slot->nested, true)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $spec @return list<string> */
    private function blocksOf(array $spec): array
    {
        $blocks = [];

        if (isset($spec['block']) && is_string($spec['block'])) {
            $blocks[] = $spec['block'];
        }

        foreach ($spec['switch'] ?? [] as $case) {
            if (isset($case['block']) && is_string($case['block'])) {
                $blocks[] = $case['block'];
            }
        }

        return array_values(array_unique($blocks));
    }

    /** `heading`, `contentColumns[0].heading` — check each hop exists. */
    /**
     * A child collection has to land in a Matrix, and its columns in fields the nested entry
     * type actually has — whether the owner is a Page Builder block or a page entry type.
     *
     * @param array<string, mixed> $spec
     * @param list<string> $errors
     */
    private function checkChildren(string $subject, string $owner, array $spec, array &$errors): void
    {
        foreach ($spec['children'] ?? [] as $field => $child) {
            $slot = $this->schema->slot($owner, (string) $field);

            if ($slot === null) {
                $errors[] = sprintf('%s: `%s` has no field `%s`', $subject, $owner, $field);

                continue;
            }

            if (!$slot->isMatrix()) {
                $errors[] = sprintf('%s: `%s.%s` is %s, not a Matrix', $subject, $owner, $field, $slot->type);

                continue;
            }

            $nested = $this->schema->nestedTypeOf($owner, (string) $field);

            foreach (array_keys($child['map'] ?? []) as $target) {
                if ($nested !== null && $this->schema->slot($nested, (string) $target) === null) {
                    $errors[] = sprintf('%s: nested `%s` has no field `%s`', $subject, $nested, $target);
                }
            }
        }
    }

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
