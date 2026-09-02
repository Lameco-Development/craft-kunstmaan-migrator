<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\Mapping;

use Lameco\Kunstmaanmigrator\Compile\EntityIndex;

/**
 * Shape check for a mapping file, with no database involved.
 *
 * This exists because the failure it catches is silent: a mistyped key in a hand-edited
 * mapping does not throw, it just means a rule never fires and content quietly does not
 * migrate. Unknown keys are errors, not warnings.
 */
final class Schema
{
    /**
     * The DSL's top-level keys, in the order a mapping declares them.
     *
     * Public because MappingDocument writes the file back in this order: the
     * file is reviewed in a pull request, and a diff that reorders the whole
     * document because a hash iterated differently is a diff nobody reads.
     *
     * @return list<string>
     */
    public static function topLevelKeys(): array
    {
        return self::TOP_LEVEL;
    }

    private const TOP_LEVEL = [
        'version', 'environments', 'merge', 'pages', 'defaults', 'entities',
        'sequence', 'columnGroups', 'parts', 'sidecars', 'forms', 'globals', 'redirects',
        'transforms', 'unmapped',
    ];

    private const SIDECAR_KEYS = [
        'live', 'table', 'map', 'children', 'ignore', 'unreviewed', 'drop', 'manual', 'todo', 'note',
    ];

    private const PART_KEYS = [
        'live', 'table', 'block', 'switch', 'map', 'children', 'firstChild', 'promote', 'ignore',
        'unreviewed', 'absorbInto', 'source', 'conflict', 'consumedBy', 'drop', 'manual', 'todo', 'note',
    ];

    private const CHILD_KEYS = ['table', 'fk', 'order', 'block', 'map', 'children', 'ignore', 'unreviewed', 'todo'];

    private const PAGE_KEYS = ['live', 'table', 'section', 'entryType', 'map', 'children', 'ignore',
        'unreviewed', 'contexts', 'postDate', 'manual', 'drop', 'todo', 'note', ];

    private const ENTITY_KEYS = ['live', 'table', 'section', 'entryType', 'title', 'softDelete', 'dedupe',
        'single', 'children', 'map', 'ignore', 'unreviewed', 'todo', 'note', ];

    private const REDIRECT_KEYS = ['live', 'table', 'map', 'defaultType', 'ignore', 'unreviewed', 'todo', 'note'];

    private const SEQUENCE_KEYS = ['id', 'match', 'guard', 'action', 'block', 'map', 'runs', 'else', 'note'];

    private const COLUMN_GROUP_KEYS = ['contexts', 'block', 'column', 'prepend', 'note'];

    private const CONFLICT_KEYS = ['status', 'artifact', 'spec', 'note'];

    private const PROMOTE_KEYS = [
        'section', 'entryType', 'relation', 'dedupe', 'map', 'ignore', 'unreviewed', 'todo',
    ];

    /** @return list<string> violations; empty means the shape is sound */
    public function validate(Mapping $mapping): array
    {
        $errors = [];

        $this->checkTopLevel($mapping, $errors);
        $this->checkEnvironments($mapping, $errors);
        $this->checkPages($mapping, $errors);
        $this->checkEntities($mapping, $errors);
        $this->checkRedirects($mapping, $errors);
        $this->checkParts($mapping, $errors);
        $this->checkSidecars($mapping, $errors);
        $this->checkUnreviewed($mapping, $errors);
        $this->checkRefs($mapping, $errors);
        $this->checkSequence($mapping, $errors);
        $this->checkColumnGroups($mapping, $errors);
        $this->checkLaneCollisions($mapping, $errors);

        return $errors;
    }

    /**
     * One row, checked for damage only.
     *
     * The row screen saves work in progress: a target not yet chosen, columns
     * still unreviewed, a part with no disposition. Those are what the
     * progress bar counts — refusing to save them is how a mapping editor
     * fights its own workflow. This blocks only what is malformed: unknown
     * keys, non-mapping values, conflicting dispositions, broken children or
     * promotions, and a class claimed by two lanes. `validate()` remains the
     * gate a run must pass in full.
     *
     * @return list<string> violations; empty means the row may be written
     */
    public function validateRow(Mapping $mapping, string $lane, string $key): array
    {
        $errors = [];
        $spec = match ($lane) {
            'pages' => $mapping->pages()[$key] ?? null,
            'parts' => $mapping->parts()[$key] ?? null,
            'entities' => $mapping->entities()[$key] ?? null,
            'sidecars' => $mapping->sidecars()[$key] ?? null,
            default => null,
        };

        if ($spec === null) {
            return $errors;
        }

        match ($lane) {
            'pages' => $this->checkPage($key, $spec, $errors, completeness: false),
            'parts' => $this->checkPart($key, $spec, $errors, completeness: false),
            'entities' => $this->checkEntity($key, $spec, $errors, completeness: false),
            'sidecars' => $this->checkSidecar($key, $spec, $errors, completeness: false),
        };

        $collisions = [];
        $this->checkLaneCollisions($mapping, $collisions);

        foreach ($collisions as $collision) {
            if (str_starts_with($collision, sprintf('`%s` is claimed', $key))) {
                $errors[] = $collision;
            }
        }

        return $errors;
    }

    /**
     * A generated skeleton lists every column it could not place under `unreviewed:`. Until a
     * human moves each one into `map:` or `ignore:` with a reason, the mapping is a draft.
     *
     * This exists because `ignore:` used to be generated as "everything `map:` does not consume",
     * while three documents described it as a deliberate declaration. Nothing could tell a decision
     * apart from generator output, so a column nobody had looked at read exactly like one somebody
     * had ruled out — which is how a legacy country relation sat in `ignore:` and migrated as
     * nothing at all.
     *
     * @param list<string> $errors
     */
    private function checkUnreviewed(Mapping $mapping, array &$errors): void
    {
        foreach ($mapping->unreviewed() as $subject => $columns) {
            $errors[] = sprintf(
                '%s: %d column%s still unreviewed (%s) — move each into `map:` or give it a reason under `ignore:`',
                $subject,
                count($columns),
                count($columns) === 1 ? '' : 's',
                implode(', ', array_slice($columns, 0, 6)) . (count($columns) > 6 ? ', …' : ''),
            );
        }
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

        $cutoff = $mapping->offlineCutoff();

        // The cutoff is compared against a stored timestamp in SQL. A value the database
        // cannot read as a date compares as a string instead of failing, which silently
        // changes which pages migrate — the one kind of wrong a run never reports.
        if ($cutoff !== null && \DateTimeImmutable::createFromFormat('!Y-m-d', $cutoff) === false) {
            $errors[] = sprintf(
                'defaults: `offlineCutoff: %s` is not a date the database can compare (expected YYYY-MM-DD)',
                $cutoff,
            );
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

    /**
     * A redirect needs a table to read and a column holding the destination.
     *
     * The `source` is never configurable: it is the node translation's own URL, which is the
     * only path a visitor can still be arriving on.
     *
     * @param list<string> $errors
     */
    private function checkRedirects(Mapping $mapping, array &$errors): void
    {
        foreach ($mapping->redirects() as $name => $spec) {
            if (!is_array($spec)) {
                $errors[] = sprintf('redirect `%s` is not a mapping', $name);

                continue;
            }

            foreach (array_diff(array_keys($spec), self::REDIRECT_KEYS) as $key) {
                $errors[] = sprintf('redirect `%s`: unknown key `%s`', $name, $key);
            }

            if (($spec['table'] ?? '') === '') {
                $errors[] = sprintf('redirect `%s`: missing `table:`', $name);
            }

            if (($spec['map']['destination'] ?? '') === '') {
                $errors[] = sprintf(
                    'redirect `%s`: no `map.destination:` — without it every redirect points nowhere',
                    $name,
                );
            }
        }
    }

    /**
     * Child collections, wherever they hang: off a pagepart or off a page entity.
     *
     * `firstChild:` reads the same row shape (`table`, `fk`, `order`, `map`) as `children:` —
     * only what it does with the first row differs, in the compiler, not in the shape a mapping
     * author writes — so it is checked here too, on a part row that carries it.
     *
     * @param array<string, mixed> $spec
     * @param list<string> $errors
     */
    private function checkChildren(string $subject, array $spec, array &$errors): void
    {
        foreach (['children' => 'child', 'firstChild' => 'firstChild'] as $key => $noun) {
            foreach ($spec[$key] ?? [] as $field => $child) {
                if (!is_array($child)) {
                    $errors[] = sprintf('%s: %s `%s` is not a mapping', $subject, $noun, $field);

                    continue;
                }

                foreach (array_diff(array_keys($child), self::CHILD_KEYS) as $unknown) {
                    $errors[] = sprintf('%s, %s `%s`: unknown key `%s`', $subject, $noun, $field, $unknown);
                }

                foreach (['table', 'fk'] as $required) {
                    if (($child[$required] ?? '') === '') {
                        $errors[] = sprintf('%s, %s `%s`: missing `%s:`', $subject, $noun, $field, $required);
                    }
                }

                // A child row can own a collection of its own — `children:` one level down, the
                // same shape this method already walks. Recursing is what stops a nested block
                // wrapper (one block, several nested rows) from validating clean on a mistake
                // this method would have caught at the top level.
                if (($child['children'] ?? []) !== []) {
                    $this->checkChildren(sprintf('%s, child `%s`', $subject, $field), $child, $errors);
                }
            }
        }
    }

    /**
     * Every `ref(<Entity>)` has to name something.
     *
     * A misspelled entity name compiles to no relation at all rather than to an error: the FK
     * is read, the lookup misses, and the field is simply absent from the payload. That is the
     * quietest possible failure, so it is caught on the mapping instead.
     *
     * @param list<string> $errors
     */
    private function checkRefs(Mapping $mapping, array &$errors): void
    {
        $index = new EntityIndex($mapping->entities());

        foreach (self::refsIn($mapping->all(), '') as $path => $names) {
            foreach ($names as $name) {
                if (!$index->has($name)) {
                    $errors[] = sprintf(
                        '%s: `ref(%s)` names no entity — declare it under `entities:`, or use one of: %s',
                        $path,
                        $name,
                        implode(', ', $index->names()),
                    );
                }
            }
        }
    }

    /**
     * @param array<array-key, mixed> $node
     * @return array<string, list<string>> path => entity names referenced there
     */
    private static function refsIn(array $node, string $path): array
    {
        $found = [];

        foreach ($node as $key => $value) {
            $here = $path === '' ? (string) $key : $path . '.' . (string) $key;

            if (is_array($value)) {
                $found = [...$found, ...self::refsIn($value, $here)];

                continue;
            }

            if (is_string($value) && preg_match_all('/\bref\(([^)]*)\)/', $value, $m) > 0) {
                $found[$here] = array_map(trim(...), $m[1]);
            }
        }

        return $found;
    }

    /**
     * A page entity's own columns are content too.
     *
     * The map-or-ignore rule was enforced on `parts:` only, so a page could name a table and
     * never say what to do with its columns. That is how 147 columns across 33 page tables —
     * every partner address, every editorial summary and publication date — stayed unmapped
     * while the mapping validated clean and coverage reported no holes.
     *
     * @param list<string> $errors
     */
    private function checkPages(Mapping $mapping, array &$errors): void
    {
        foreach ($mapping->pages() as $name => $spec) {
            $this->checkPage((string) $name, $spec, $errors);
        }
    }

    /**
     * One page row. `$completeness = false` checks only that the row is
     * well-formed — an editor saving work in progress may leave the target
     * unchosen and columns unreviewed; those are progress, not damage.
     *
     * @param list<string> $errors
     */
    private function checkPage(string $name, mixed $spec, array &$errors, bool $completeness = true): void
    {
        if (!is_array($spec)) {
            $errors[] = sprintf('page `%s` is not a mapping', $name);

            return;
        }

        foreach (array_diff(array_keys($spec), self::PAGE_KEYS) as $key) {
            $errors[] = sprintf('page `%s`: unknown key `%s`', $name, $key);
        }

        if (isset($spec['manual']) || isset($spec['drop'])) {
            return;
        }

        $this->checkChildren(sprintf('page `%s`', $name), $spec, $errors);

        if (!$completeness) {
            return;
        }

        if (($spec['entryType'] ?? '') === '') {
            $errors[] = sprintf('page `%s`: no `entryType:`', $name);
        }

        // `ignore: []` present-but-empty is a declaration in its own right: this table
        // carries nothing beyond the columns the node already supplies. Absent is not the
        // same as empty, so test for the key rather than its contents.
        if (isset($spec['table'])
            && ($spec['map'] ?? []) === []
            && !array_key_exists('ignore', $spec)
            && !array_key_exists('unreviewed', $spec)
        ) {
            $errors[] = sprintf(
                'page `%s`: names table `%s` but neither maps nor ignores any of its columns',
                $name,
                $spec['table'],
            );
        }
    }

    /**
     * A non-node table becoming entries has to say which table, which entry type, and which
     * column holds the title — Craft has no other way to name the entry, and these tables use
     * `name` as often as `title`.
     *
     * `dedupe:` is required rather than defaulted, because neither answer is safe to assume.
     * The corpus has taxonomy tables that are exact clones across environments (deduping them
     * is the only way to get 14 categories instead of 28) sitting next to tables that reuse the
     * same ids for unrelated names (deduping those merges two unrelated categories into one).
     *
     * @param list<string> $errors
     */
    private function checkEntities(Mapping $mapping, array &$errors): void
    {
        foreach ($mapping->entities() as $name => $spec) {
            $this->checkEntity((string) $name, $spec, $errors);
        }
    }

    /** @param list<string> $errors */
    private function checkEntity(string $name, mixed $spec, array &$errors, bool $completeness = true): void
    {
        if (!is_array($spec)) {
            $errors[] = sprintf('entity `%s` is not a mapping', $name);

            return;
        }

        foreach (array_diff(array_keys($spec), self::ENTITY_KEYS) as $key) {
            $errors[] = sprintf('entity `%s`: unknown key `%s`', $name, $key);
        }

        if (array_key_exists('dedupe', $spec) && !is_bool($spec['dedupe'])) {
            $errors[] = sprintf('entity `%s`: `dedupe:` is %s, not true or false', $name, get_debug_type($spec['dedupe']));
        }

        if (array_key_exists('single', $spec) && !is_bool($spec['single'])) {
            $errors[] = sprintf('entity `%s`: `single:` is %s, not true or false', $name, get_debug_type($spec['single']));
        }

        $this->checkChildren(sprintf('entity `%s`', $name), $spec, $errors);

        if (!$completeness) {
            return;
        }

        // A `single: true` entity merges into the section's existing entry
        // (Craft's auto-created Single); the title stays whatever an earlier
        // contributor set, so the row needs no title column of its own.
        $required = ($spec['single'] ?? false) === true
            ? ['table', 'section', 'entryType']
            : ['table', 'section', 'entryType', 'title'];
        foreach ($required as $key) {
            if (($spec[$key] ?? '') === '') {
                $errors[] = sprintf('entity `%s`: missing `%s:`', $name, $key);
            }
        }

        if (!array_key_exists('dedupe', $spec)) {
            $errors[] = sprintf(
                'entity `%s`: no `dedupe:` — say whether rows with the same id in different '
                . 'environments are the same thing',
                $name,
            );
        }

        // Same map-or-ignore rule the other lanes get: `id`, the title column and the
        // soft-delete column are accounted for by the keys above, and everything else on
        // the table is a decision somebody has to have made. `ignore: []` present-but-empty
        // says "nothing else here", which is different from saying nothing.
        if (($spec['map'] ?? []) === []
            && !array_key_exists('ignore', $spec)
            && !array_key_exists('unreviewed', $spec)
        ) {
            $errors[] = sprintf(
                'entity `%s`: neither maps nor ignores any column beyond its title',
                $name,
            );
        }
    }

    /** @param list<string> $errors */
    private function checkParts(Mapping $mapping, array &$errors): void
    {
        foreach ($mapping->parts() as $class => $spec) {
            $this->checkPart((string) $class, $spec, $errors);
        }
    }

    /** @param list<string> $errors */
    private function checkPart(string $class, mixed $spec, array &$errors, bool $completeness = true): void
    {
        if (!is_array($spec)) {
            $errors[] = sprintf('part `%s` is not a mapping', $class);

            return;
        }

        foreach (array_diff(array_keys($spec), self::PART_KEYS) as $key) {
            $errors[] = sprintf('part `%s`: unknown key `%s`', $class, $key);
        }

        // Every part must resolve to exactly one disposition. Having none yet
        // is unfinished work, not damage — only completeness flags it.
        $dispositions = array_filter([
            isset($spec['block']) ? 'block' : null,
            isset($spec['switch']) ? 'switch' : null,
            isset($spec['drop']) ? 'drop' : null,
            isset($spec['manual']) ? 'manual' : null,
            isset($spec['consumedBy']) ? 'consumedBy' : null,
        ]);

        if ($dispositions === [] && $completeness) {
            $errors[] = sprintf(
                'part `%s`: no disposition — needs one of block, switch, consumedBy, drop or manual',
                $class,
            );
        } elseif (count($dispositions) > 1) {
            $errors[] = sprintf('part `%s`: conflicting dispositions (%s)', $class, implode(', ', $dispositions));
        }

        $this->checkChildren(sprintf('part `%s`', $class), $spec, $errors);

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

    /**
     * A sidecar is a per-page entity keyed by the polymorphic `(ref_entity_name, ref_id)`
     * pair — a header tab, a footer tab, structured data. The table is the only thing a
     * mapping has to know; which pages it decorates is answered by the data, per page.
     *
     * @param list<string> $errors
     */
    private function checkSidecars(Mapping $mapping, array &$errors): void
    {
        foreach ($mapping->sidecars() as $name => $spec) {
            $this->checkSidecar((string) $name, $spec, $errors);
        }
    }

    /** @param list<string> $errors */
    private function checkSidecar(string $name, mixed $spec, array &$errors, bool $completeness = true): void
    {
        if (!is_array($spec)) {
            $errors[] = sprintf('sidecar `%s` is not a mapping', $name);

            return;
        }

        foreach (array_diff(array_keys($spec), self::SIDECAR_KEYS) as $key) {
            $errors[] = sprintf('sidecar `%s`: unknown key `%s`', $name, $key);
        }

        if (isset($spec['drop']) || isset($spec['manual'])) {
            return;
        }

        $this->checkChildren(sprintf('sidecar `%s`', $name), $spec, $errors);

        if (!$completeness) {
            return;
        }

        if (($spec['table'] ?? '') === '') {
            $errors[] = sprintf('sidecar `%s`: missing `table:`', $name);
        }

        if (($spec['map'] ?? []) === [] && ($spec['children'] ?? []) === []) {
            $errors[] = sprintf(
                'sidecar `%s`: no `map:` and no `children:` — a sidecar that writes nothing is a decision, say it with drop: or manual:',
                $name,
            );
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

    /**
     * A `columnGroups:` entry merges two or more simultaneous contexts — a Kunstmaan
     * multi-column row, `middle-left` next to `middle-right` — into one Matrix block with one
     * nested entry per context. `contexts:` needs at least two names to merge, and no context
     * may be claimed by two groups: the compiler routes an emitted block into the first group
     * whose `block:` matches, so a second claim would just silently never fire.
     *
     * @param list<string> $errors
     */
    private function checkColumnGroups(Mapping $mapping, array &$errors): void
    {
        $claimed = [];

        foreach ($mapping->columnGroups() as $i => $group) {
            if (!is_array($group)) {
                $errors[] = sprintf('columnGroups rule #%d is not a mapping', $i);

                continue;
            }

            foreach (array_diff(array_keys($group), self::COLUMN_GROUP_KEYS) as $key) {
                $errors[] = sprintf('columnGroups rule #%d: unknown key `%s`', $i, $key);
            }

            $contexts = $group['contexts'] ?? null;

            if (!is_array($contexts) || count($contexts) < 2
                || array_filter($contexts, static fn(mixed $c): bool => !is_string($c) || $c === '') !== []
            ) {
                $errors[] = sprintf('columnGroups rule #%d: `contexts:` needs at least two context names', $i);

                $contexts = [];
            }

            foreach (['block', 'column'] as $required) {
                if (($group[$required] ?? '') === '') {
                    $errors[] = sprintf('columnGroups rule #%d: missing `%s:`', $i, $required);
                }
            }

            if (array_key_exists('prepend', $group) && !is_bool($group['prepend'])) {
                $errors[] = sprintf('columnGroups rule #%d: `prepend:` is %s, not true or false', $i, get_debug_type($group['prepend']));
            }

            foreach ($contexts as $context) {
                if (isset($claimed[$context])) {
                    $errors[] = sprintf(
                        'columnGroups: context `%s` is claimed by both rule #%d and rule #%d',
                        $context,
                        $claimed[$context],
                        $i,
                    );

                    continue;
                }

                $claimed[$context] = $i;
            }
        }
    }

    /** A class claimed by two lanes has an ambiguous target. @param list<string> $errors */
    private function checkLaneCollisions(Mapping $mapping, array &$errors): void
    {
        $lanes = [
            'parts' => array_keys($mapping->parts()),
            'forms' => array_keys($mapping->formFields()),
            'globals' => array_keys($mapping->globalParts()),
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
