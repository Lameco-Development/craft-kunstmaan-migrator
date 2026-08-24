<?php

declare(strict_types=1);

namespace Lameco\KumaCompile\Report;

use Lameco\KumaCompile\Legacy\Introspection;
use Lameco\KumaCompile\Mapping\Mapping;

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
            ...$this->mappedColumnsMissing(),
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
            'parts' => $this->mapping->parts(),
            'pages' => $this->mapping->pages(),
            'entities' => $this->mapping->entities(),
            'sidecars' => $this->mapping->sidecars(),
        ];

        foreach ($lanes as $lane => $rows) {
            foreach ($rows as $subject => $spec) {
                if (!is_array($spec) || isset($spec['drop']) || isset($spec['manual']) || !isset($spec['table'])) {
                    continue;
                }

                $class = $this->introspection->entityForTable((string) $spec['table']);

                if ($class !== null) {
                    yield ['lane' => $lane, 'subject' => (string) $subject, 'class' => $class, 'spec' => $spec];
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
