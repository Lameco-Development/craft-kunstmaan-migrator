<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\Target;

use Lameco\Kunstmaanmigrator\Mapping\Mapping;
use Lameco\Kunstmaanmigrator\Mapping\PageRow;
use Lameco\Kunstmaanmigrator\Mapping\PartRow;

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
        $errors = [...$this->checkStructural($mapping), ...$this->checkFormsField($mapping)];

        foreach ($mapping->pageRows() as $name => $page) {
            if (!$page->isMigrated()) {
                continue;
            }

            // The section is checked as the compiler will use it — `pages` when the row
            // does not say — so a missing default section fails here, not at the loader.
            $section = $page->section();
            $entryType = $page->entryType();

            if (!$this->schema->hasSection($section)) {
                $errors[] = sprintf('page `%s`: no section `%s` in Craft', $name, $section);
            }

            if ($entryType === null) {
                continue;
            }

            if (!$this->schema->hasEntryType($entryType)) {
                $errors[] = sprintf('page `%s`: no entry type `%s` in Craft', $name, $entryType);

                continue;
            }

            // A page's own columns land in real fields too, so they need the same check the
            // parts get. Without it a page map is free to name fields that do not exist.
            foreach (array_keys($page->map()) as $target) {
                if ($this->schema->slot($entryType, (string) $target) === null) {
                    $errors[] = sprintf(
                        'page `%s`: entry type `%s` has no field `%s`',
                        $name,
                        $entryType,
                        $target,
                    );
                }
            }

            $this->checkChildren(sprintf('page `%s`', $name), $entryType, $page->children(), $errors);

            // A field the page writes itself and the block stream also fills: the blocks win at
            // compile, so the mapped value is dropped on every page that has any blocks there.
            $written = array_map(strval(...), [...array_keys($page->map()), ...array_keys($page->children())]);

            foreach (array_values(array_intersect($written, $this->blockFields($mapping, $page))) as $field) {
                $errors[] = sprintf(
                    'page `%s`: `%s` is both mapped and a block field — the blocks replace the mapped value',
                    $name,
                    $field,
                );
            }
        }

        foreach ($mapping->entityRows() as $name => $entity) {
            $section = $entity->section();
            $entryType = $entity->entryType();

            if ($section !== null && !$this->schema->hasSection($section)) {
                $errors[] = sprintf('entity `%s`: no section `%s` in Craft', $name, $section);
            }

            if ($entryType === null) {
                continue;
            }

            if (!$this->schema->hasEntryType($entryType)) {
                $errors[] = sprintf('entity `%s`: no entry type `%s` in Craft', $name, $entryType);

                continue;
            }

            foreach (array_keys($entity->map()) as $target) {
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

        $errors = [...$errors, ...$this->checkPageParts($mapping)];

        foreach ($mapping->partRows() as $name => $part) {
            $block = $part->block();

            if ($block === null || !$part->compilesToBlocks()) {
                continue;
            }

            if (!$this->schema->hasEntryType($block)) {
                $errors[] = sprintf('part `%s`: no block entry type `%s` in Craft', $name, $block);

                continue;
            }

            foreach (array_keys($part->map()) as $target) {
                $error = $this->checkPath($block, (string) $target);

                if ($error !== null) {
                    $errors[] = sprintf('part `%s`: %s', $name, $error);
                }
            }

            $this->checkChildren(sprintf('part `%s`', $name), $block, $part->children(), $errors);

            foreach ($part->promote() as $table => $promo) {
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
     * A `consumedBy: page` part's `map:` and `children:` address the fields of the page it sits
     * on, so they are checked against the page entry types that have a `target: page` context —
     * the types it can reach. A field no such type has is a typo, and every value is dropped: an
     * error. A type that merely lacks one is `pagesWithNoBlockField()`'s warning instead — the
     * `forms.field` precedent: compile drops the value there and counts it.
     *
     * @return list<string>
     */
    private function checkPageParts(Mapping $mapping): array
    {
        $hosts = $this->pagePartHosts($mapping);
        $errors = [];

        foreach ($mapping->partRows() as $name => $part) {
            if ($part->disposition() !== PartRow::PAGE) {
                continue;
            }

            foreach (array_keys($part->map()) as $target) {
                if ($this->hostsWith($hosts, (string) $target) !== []) {
                    continue;
                }

                foreach ($hosts as $entryType) {
                    $errors[] = sprintf('part `%s`: page entry type `%s` has no field `%s`', $name, $entryType, $target);
                }
            }

            // A host with the field still has to hold it as a Matrix of the right shape; with no
            // host holding it at all, each one says so.
            foreach ($part->children() as $field => $child) {
                $holding = $this->hostsWith($hosts, (string) $field);

                foreach ($holding !== [] ? $holding : $hosts as $entryType) {
                    $this->checkChildren(sprintf('part `%s`', $name), $entryType, [$field => $child], $errors);
                }
            }

            // The block stream wins a field it fills, so a page part writing one is lost on every
            // page that has blocks there.
            $written = array_map(strval(...), [...array_keys($part->map()), ...array_keys($part->children())]);

            foreach ($mapping->pageRows() as $pageName => $page) {
                if (!$page->compiles() || $page->pageContexts() === []) {
                    continue;
                }

                foreach (array_values(array_intersect($written, $this->blockFields($mapping, $page))) as $field) {
                    $errors[] = sprintf(
                        "part `%s`: `%s` is a block field on page `%s` — the blocks replace the page part's value",
                        $name,
                        $field,
                        $pageName,
                    );
                }
            }
        }

        return $errors;
    }

    /**
     * The page entry types a `consumedBy: page` part can land on: those with a `target: page` context.
     *
     * @return list<string>
     */
    private function pagePartHosts(Mapping $mapping): array
    {
        $hosts = [];

        foreach ($mapping->pageRows() as $page) {
            $entryType = (string) $page->entryType();

            if ($page->compiles() && $page->pageContexts() !== [] && $this->schema->hasEntryType($entryType)) {
                $hosts[$entryType] = true;
            }
        }

        return array_map(strval(...), array_keys($hosts));
    }

    /**
     * @param list<string> $hosts
     * @return list<string> the hosts that carry the field
     */
    private function hostsWith(array $hosts, string $field): array
    {
        return array_values(array_filter($hosts, fn(string $host): bool => $this->schema->slot($host, $field) !== null));
    }

    /**
     * A page entry type lacking a field a page part writes, where another type it can land on
     * has it: compile drops the value on those pages and counts it.
     *
     * @return list<string>
     */
    private function pagePartGaps(Mapping $mapping): array
    {
        $hosts = $this->pagePartHosts($mapping);
        $warnings = [];

        foreach ($mapping->partRows() as $name => $part) {
            if ($part->disposition() !== PartRow::PAGE) {
                continue;
            }

            foreach ([...array_keys($part->map()), ...array_keys($part->children())] as $field) {
                $holding = $this->hostsWith($hosts, (string) $field);

                if ($holding === []) {
                    continue;
                }

                foreach (array_diff($hosts, $holding) as $entryType) {
                    $warnings[] = sprintf(
                        'part `%s` writes `%s`, which page entry type `%s` does not have — dropped on those pages',
                        $name,
                        $field,
                        $entryType,
                    );
                }
            }
        }

        return $warnings;
    }

    /**
     * The fields a page's block stream fills: each context's, and `forms.field` when declared.
     *
     * @return list<string>
     */
    private function blockFields(Mapping $mapping, PageRow $page): array
    {
        $fields = $page->contextFields();
        $formsField = $this->formsField($mapping);

        if ($formsField !== null) {
            $fields[] = $formsField;
        }

        return array_values(array_unique($fields));
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

        foreach ($mapping->partRows() as $name => $part) {
            $block = $part->block();

            if ($block === null || !$this->schema->hasEntryType($block)) {
                continue;
            }

            $supplied = array_map(
                static fn(string $p): string => explode('.', str_replace(['[0]'], '', $p))[0],
                array_map(strval(...), array_keys($part->map())),
            );
            $supplied = array_merge($supplied, array_map(strval(...), array_keys($part->children())));

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
        $warnings = [];

        foreach ($mapping->pageRows() as $name => $page) {
            $entryType = $page->entryType();

            if (!$page->compiles() || !$this->schema->hasEntryType((string) $entryType)) {
                continue;
            }

            $missing = array_values(array_filter(
                $page->contextFields(),
                fn(string $field): bool => $this->schema->slot((string) $entryType, $field) === null,
            ));

            if ($missing !== []) {
                $warnings[] = sprintf(
                    'page `%s` streams blocks into `%s`, which `%s` does not have — every part on'
                    . ' these pages is dropped',
                    $name,
                    implode('`, `', $missing),
                    $entryType,
                );
            }

            $formsField = $this->formsField($mapping);

            if ($formsField !== null && !$this->isMatrix((string) $entryType, $formsField)) {
                $warnings[] = sprintf(
                    'page `%s` lands its form block in `%s`, which `%s` does not have — a form on these'
                    . ' pages is dropped',
                    $name,
                    $formsField,
                    $entryType,
                );
            }
        }

        return [...$warnings, ...$this->pagePartGaps($mapping)];
    }

    /**
     * `forms.field` names a Matrix on no page type at all: a typo, and every form block in the
     * corpus is dropped at compile. A page type that merely lacks it is `pagesWithNoBlockField()`'s
     * warning instead — the field is lane-wide, and a type that never holds a form need not carry it.
     *
     * @return list<string>
     */
    private function checkFormsField(Mapping $mapping): array
    {
        $field = $this->formsField($mapping);

        if ($field === null) {
            return [];
        }

        $pageTypes = [];

        foreach ($mapping->pageRows() as $page) {
            $entryType = (string) $page->entryType();

            if ($page->compiles() && $this->schema->hasEntryType($entryType)) {
                $pageTypes[] = $entryType;
            }
        }

        foreach ($pageTypes as $entryType) {
            if ($this->isMatrix($entryType, $field)) {
                return [];
            }
        }

        return $pageTypes === [] ? [] : [sprintf(
            'forms.field: no page entry type has a Matrix `%s` — every form block would be dropped',
            $field,
        )];
    }

    /** The declared `forms.field`, or null when the lane is off or falls back to the builder. */
    private function formsField(Mapping $mapping): ?string
    {
        $forms = $mapping->forms();

        return $forms->declared ? $forms->field : null;
    }

    private function isMatrix(string $entryType, string $field): bool
    {
        return $this->schema->slot($entryType, $field)?->isMatrix() === true;
    }

    /**
     * Where structural placeholders land: a structure that exists and allows their entry type.
     *
     * Checked only when an entry type is configured — without one the compiler emits no
     * placeholder, so the section is never written to. A missing section, a channel or a type
     * the section refuses all surface at load time otherwise, after every subtree under a
     * placeholder has already been re-rooted.
     *
     * @return list<string>
     */
    private function checkStructural(Mapping $mapping): array
    {
        $entryType = $mapping->structuralEntryType();

        if ($entryType === null) {
            return [];
        }

        $errors = [];
        $section = $mapping->structuralSection();

        if (!$this->schema->hasEntryType($entryType)) {
            $errors[] = sprintf('defaults.structuralEntryType: no entry type `%s` in Craft', $entryType);
        }

        if (!$this->schema->hasSection($section)) {
            $errors[] = sprintf('defaults.structuralSection: no section `%s` in Craft', $section);

            return $errors;
        }

        $type = $this->schema->sectionType($section);

        // An unknown type is not a wrong one: a source that does not record it is no evidence.
        if ($type !== null && $type !== 'structure') {
            $errors[] = sprintf(
                'defaults.structuralSection: section `%s` is a %s, not a structure — a placeholder there cannot parent anything',
                $section,
                $type,
            );
        }

        $allowed = $this->schema->sectionEntryTypes($section);

        if ($allowed !== null && $errors === [] && !in_array($entryType, $allowed, true)) {
            $errors[] = sprintf('defaults.structuralEntryType: section `%s` does not allow entry type `%s`', $section, $entryType);
        }

        return $errors;
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

        foreach ($mapping->partRows() as $name => $part) {
            if (!$part->compilesToBlocks()) {
                continue;
            }

            foreach ($part->blocks() as $block) {
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
     * `PageRow::contextFields()` names them the way the compiler will read them.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function hostingFields(Mapping $mapping): array
    {
        $hosts = [];

        foreach ($mapping->pageRows() as $page) {
            $entryType = $page->entryType();

            if (!$page->compiles() || !$this->schema->hasEntryType((string) $entryType)) {
                continue;
            }

            foreach ($page->contextFields() as $field) {
                $hosts[$entryType . '.' . $field] = [(string) $entryType, $field];
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

    /**
     * A child collection has to land in a Matrix, and its columns in fields the nested entry
     * type actually has — whether the owner is a Page Builder block or a page entry type. An
     * Assets field takes one asset per row, so its map holds the one expression that yields it;
     * a Table field takes the map's targets as column handles, checked when the schema lists them.
     *
     * @param array<string, array<string, mixed>> $children the row's `children:`
     * @param list<string> $errors
     */
    private function checkChildren(string $subject, string $owner, array $children, array &$errors): void
    {
        foreach ($children as $field => $child) {
            $slot = $this->schema->slot($owner, (string) $field);

            if ($slot === null) {
                $errors[] = sprintf('%s: `%s` has no field `%s`', $subject, $owner, $field);

                continue;
            }

            $map = is_array($child['map'] ?? null) ? $child['map'] : [];

            if ($slot->type === 'Assets') {
                if (count($map) !== 1) {
                    $errors[] = sprintf(
                        '%s: `%s.%s` is an Assets field, so its `map:` holds exactly one value — it holds %d',
                        $subject,
                        $owner,
                        $field,
                        count($map),
                    );
                }

                continue;
            }

            if ($slot->type === 'Table') {
                foreach (array_keys($map) as $column) {
                    if ($slot->columns !== null && !in_array((string) $column, $slot->columns, true)) {
                        $errors[] = sprintf('%s: Table `%s.%s` has no column `%s`', $subject, $owner, $field, $column);
                    }
                }

                continue;
            }

            if (!$slot->isMatrix()) {
                $errors[] = sprintf(
                    '%s: `%s.%s` is %s — a `children:` collection fills a Matrix, Assets or Table field',
                    $subject,
                    $owner,
                    $field,
                    $slot->type,
                );

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
