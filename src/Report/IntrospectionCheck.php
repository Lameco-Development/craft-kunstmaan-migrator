<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\Report;

use Lameco\Kunstmaanmigrator\Mapping\EntityRow;
use Lameco\Kunstmaanmigrator\Mapping\Mapping;
use Lameco\Kunstmaanmigrator\Source\Introspection;

/**
 * The mapping, checked against the legacy application's own account of itself.
 *
 * The database-facing checks ask whether the mapping covers the *content*; these ask
 * whether it covers the *wiring* — the three ways a mapping can silently disagree with
 * the source it migrates:
 *
 *  1. an owning ManyToMany the mapping never reads — a relation living in a join table
 *     of two foreign keys, invisible to every column list;
 *  2. a column with a form widget in the legacy CP, ignored without a reason — an editor
 *     could type there, so dropping it is a decision someone should have written down;
 *  3. a mapped column the entity does not have — a typo the run would only surface hours in.
 */
final class IntrospectionCheck
{
    public function __construct(
        private readonly Mapping $mapping,
        private readonly Introspection $introspection,
    ) {
    }

    /** @return list<string> */
    public function warnings(): array
    {
        $text = $this->mapping->path !== '' && is_file($this->mapping->path)
            ? (string) file_get_contents($this->mapping->path)
            : '';

        return [
            ...$this->unclaimedManyToMany($text),
            ...$this->unclaimedOwnedCollections($text),
            ...$this->mappedColumnsMissing(),
            ...$this->unmappedEditableColumns($text),
        ];
    }

    /**
     * Every subject the mapping binds to a legacy table, with its entity.
     *
     * @return iterable<array{lane: string, subject: string, class: string, spec: array<string, mixed>}>
     */
    private function subjects(): iterable
    {
        $lanes = [
            'parts' => $this->mapping->partRows(),
            'pages' => $this->mapping->pageRows(),
            'entities' => $this->mapping->entityRows(),
            'sidecars' => $this->mapping->sidecarRows(),
        ];

        foreach ($lanes as $lane => $rows) {
            foreach ($rows as $subject => $row) {
                // An entity row has no disposition: the schema gives it no `drop:` or `manual:`.
                $migrated = $row instanceof EntityRow || $row->isMigrated();

                if (!$migrated || $row->table() === null) {
                    continue;
                }

                $class = $this->introspection->entityForTable($row->table());

                if ($class !== null) {
                    yield ['lane' => $lane, 'subject' => $subject, 'class' => $class, 'spec' => $row->spec];
                }
            }
        }
    }

    /**
     * @return list<string>
     */
    private function unclaimedManyToMany(string $mappingText): array
    {
        $out = [];

        foreach ($this->subjects() as $subject) {
            foreach ($this->introspection->owningManyToMany($subject['class']) as $assoc) {
                // Named anywhere in the file counts — an `m2m()` read, or a reasoned
                // note that quotes the join table while declining it.
                if (str_contains($mappingText, $assoc['joinTable'])) {
                    continue;
                }

                $out[] = sprintf(
                    '%s `%s`: Doctrine relates `%s` to %s through `%s`, and the mapping never reads that join table — the selection is lost',
                    $subject['lane'],
                    $subject['subject'],
                    $assoc['field'],
                    $this->shortName($assoc['target']),
                    $assoc['joinTable'],
                );
            }
        }

        return $out;
    }


    /**
     * The owning OneToMany associations `unclaimedManyToMany` does not look at — a page's
     * own child collection, in its own table with a foreign key back to the owner, not a
     * join table. `productBrandItems` on `ProductPage` is exactly this shape: no join table
     * for `unclaimedManyToMany` to notice, and no scalar column on `ProductPage` itself for
     * `mappedColumnsMissing` to catch either — the relation is invisible to both without this.
     *
     * @return list<string>
     */
    private function unclaimedOwnedCollections(string $mappingText): array
    {
        $out = [];

        foreach ($this->subjects() as $subject) {
            foreach ($this->introspection->ownedCollections($subject['class']) as $collection) {
                if ($collection['field'] === '') {
                    continue;
                }

                $needle = $collection['table'] !== '' ? $collection['table'] : $collection['field'];

                // Named anywhere in the file counts — a `children:` block that reads the
                // table, or a reasoned note that declines it by name.
                if (str_contains($mappingText, $needle)) {
                    continue;
                }

                $out[] = sprintf(
                    '%s `%s`: Doctrine relates `%s` to %s through a one-to-many, and the mapping never reads it — the collection is lost',
                    $subject['lane'],
                    $subject['subject'],
                    $collection['field'],
                    $this->shortName($collection['target']),
                );
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function mappedColumnsMissing(): array
    {
        $out = [];

        foreach ($this->subjects() as $subject) {
            $columns = $this->introspection->columnsOf($subject['class']);

            if ($columns === []) {
                continue;
            }

            foreach ((array) ($subject['spec']['map'] ?? []) as $target => $expression) {
                $column = $this->simpleColumn((string) $expression);

                if ($column !== null && !in_array($column, $columns, true) && !str_starts_with($column, 'node.')) {
                    $out[] = sprintf(
                        '%s `%s`: map `%s` reads `%s`, which is not a column of %s',
                        $subject['lane'],
                        $subject['subject'],
                        $target,
                        $column,
                        $this->shortName($subject['class']),
                    );
                }
            }
        }

        return $out;
    }

    /**
     * `editableColumns` was dead code until now — declared, documented, called nowhere. It
     * reads the opposite direction from `mappedColumnsMissing`: not a mapped column the
     * entity lacks, but a column the legacy CP form lets an editor fill in that the mapping
     * never mentions at all — a field that would silently disappear rather than fail loudly.
     *
     * @return list<string>
     */
    private function unmappedEditableColumns(string $mappingText): array
    {
        $out = [];

        foreach ($this->subjects() as $subject) {
            foreach ($this->introspection->editableColumns($subject['class']) as $column) {
                if (str_contains($mappingText, $column)) {
                    continue;
                }

                $out[] = sprintf(
                    '%s `%s`: `%s` has a form widget in the legacy CP, and the mapping never reads it — content an editor could type is dropped',
                    $subject['lane'],
                    $subject['subject'],
                    $column,
                );
            }
        }

        return $out;
    }

    /** The single column a plain expression reads, or null for gathers and functions. */
    private function simpleColumn(string $expression): ?string
    {
        $head = trim((string) explode('|', $expression)[0]);

        return $head === '' || str_contains($head, '(') || str_contains($head, "'") ? null : $head;
    }

    private function shortName(string $class): string
    {
        $parts = explode('\\', $class);

        return end($parts) ?: $class;
    }
}
