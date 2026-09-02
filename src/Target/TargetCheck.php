<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\Target;

use Lameco\Kunstmaanmigrator\Mapping\Mapping;

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

            // `firstChild:` writes its map onto the block itself, exactly like the part's own
            // `map:` — not into a nested Matrix, so it is checked the same way, not through
            // `checkChildren()` below.
            foreach ($part->firstChild() as $child) {
                foreach (array_keys($child['map'] ?? []) as $target) {
                    $error = $this->checkPath($block, (string) $target);

                    if ($error !== null) {
                        $errors[] = sprintf('part `%s`: %s', $name, $error);
                    }
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
     * Mapping entries that send markup to a field with nowhere to render it.
     *
     * `ckeditor` keeps the legacy HTML, which is right for a rich target and wrong for a
     * PlainText one: Craft stores the string as given and the template prints it, so the
     * tags arrive on the page as text. On the reference corpus 60 live `ContentMediaTabbed`
     * placements sent `content | ckeditor` into `tabbedContentMediaTab.text` and the site
     * showed a literal `<p>` in front of the copy.
     *
     * The mapping already states the rule where it got it right — `uspBlockUsp.text` is
     * flattened with `inlineHtml` "because a rich target would not need the flattening" —
     * and the same target is fed raw HTML by a different part two entries away. That is the
     * inconsistency this catches, and it is knowable from the mapping and the content model
     * without reading a row of legacy data.
     *
     * A warning rather than an error: a PlainText field holding one `<em>` is untidy, not
     * broken, and refusing the run over it would be worse than saying so.
     *
     * @return list<string>
     */
    public function htmlIntoPlainText(Mapping $mapping): array
    {
        $warnings = [];

        foreach ($mapping->parts() as $name => $spec) {
            $block = $spec['block'] ?? null;

            if (!is_string($block) || !$this->schema->hasEntryType($block)) {
                continue;
            }

            foreach ($spec['map'] ?? [] as $target => $expression) {
                $this->warnOnRichIntoPlain($name, $block, (string) $target, $expression, $warnings);
            }

            foreach ($spec['children'] ?? [] as $field => $child) {
                $nested = $this->schema->nestedTypeOf($block, (string) $field);

                if ($nested === null) {
                    continue;
                }

                foreach ($child['map'] ?? [] as $target => $expression) {
                    $this->warnOnRichIntoPlain($name, $nested, (string) $target, $expression, $warnings);
                }
            }
        }

        return $warnings;
    }

    /** @param list<string> $warnings */
    private function warnOnRichIntoPlain(
        string $subject,
        string $owner,
        string $target,
        mixed $expression,
        array &$warnings,
    ): void {
        if (!is_string($expression) || preg_match('/\|\s*ckeditor\s*$/', $expression) !== 1) {
            return;
        }

        $slot = $this->schema->slot($owner, $target);

        if ($slot === null || $slot->type !== 'PlainText') {
            return;
        }

        $warnings[] = sprintf(
            '%s -> %s.%s: `%s` keeps the legacy HTML but the field is PlainText — the tags '
            . 'render as text; use `inlineHtml` to flatten them',
            $subject,
            $owner,
            $target,
            trim($expression),
        );
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
     * type actually has — whether the owner is a Page Builder block or a page entry type.
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
