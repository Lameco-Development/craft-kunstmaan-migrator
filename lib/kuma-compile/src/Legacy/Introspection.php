<?php

declare(strict_types=1);

namespace Lameco\KumaCompile\Legacy;

/**
 * The legacy application's own account of itself, as a committed artifact.
 *
 * The database says what content exists; only the source says what the CMS *wired up* —
 * Doctrine associations (a ManyToMany lives in a join table no column map can reach),
 * the sidecar entities a NodeListener hangs on pages, and which columns actually have a
 * form widget in the editing UI. `introspect` writes this file once, in git, reviewable;
 * `validate` reads it. The compiler never does — the mapping stays the program.
 */
final class Introspection
{
    /**
     * @param array<string, array<string, mixed>> $entities  FQCN => {table, columns, associations, …}
     * @param list<array<string, mixed>>          $sidecars  NodeListener-attached entities
     * @param array<string, array<string, mixed>> $formTypes form class => {entity, fields}
     */
    private function __construct(
        public readonly string $mode,
        public readonly array $entities,
        public readonly array $sidecars,
        public readonly array $formTypes,
    ) {
    }

    public static function fromFile(string $path): self
    {
        $raw = @file_get_contents($path);

        if ($raw === false) {
            throw new \RuntimeException(sprintf('Cannot read introspection artifact at %s', $path));
        }

        $data = json_decode($raw, true);

        if (!is_array($data) || !isset($data['entities'])) {
            throw new \RuntimeException(sprintf('%s is not an introspection artifact', $path));
        }

        return new self(
            mode: (string) ($data['mode'] ?? 'unknown'),
            entities: (array) $data['entities'],
            sidecars: array_values((array) ($data['sidecars'] ?? [])),
            formTypes: (array) ($data['formTypes'] ?? []),
        );
    }

    /** @param array<string, mixed> $data as assembled by the introspect command */
    public static function fromArray(array $data): self
    {
        return new self(
            mode: (string) ($data['mode'] ?? 'unknown'),
            entities: (array) ($data['entities'] ?? []),
            sidecars: array_values((array) ($data['sidecars'] ?? [])),
            formTypes: (array) ($data['formTypes'] ?? []),
        );
    }

    /** The entity backing a table, or null — tables are unique per entity in Doctrine. */
    public function entityForTable(string $table): ?string
    {
        foreach ($this->entities as $class => $spec) {
            if (($spec['table'] ?? null) === $table && !($spec['mappedSuperclass'] ?? false)) {
                return (string) $class;
            }
        }

        return null;
    }

    /**
     * The owning ManyToMany associations of one entity — the relations a column map
     * cannot reach, each living in a join table of two foreign keys.
     *
     * @return list<array{field: string, target: string, joinTable: string}>
     */
    public function owningManyToMany(string $class): array
    {
        $out = [];

        foreach ((array) ($this->entities[$class]['associations'] ?? []) as $assoc) {
            if (($assoc['kind'] ?? '') !== 'ManyToMany' || isset($assoc['mappedBy']) || !isset($assoc['joinTable'])) {
                continue;
            }

            $out[] = [
                'field' => (string) ($assoc['field'] ?? ''),
                'target' => (string) ($assoc['target'] ?? ''),
                'joinTable' => (string) $assoc['joinTable'],
            ];
        }

        return $out;
    }

    /**
     * The database columns an entity's CP form actually edits.
     *
     * A column with a form widget is content an editor could type; a column without one is
     * plumbing. The distinction is what separates "silently dropped content" from "a dead
     * column nobody could ever fill".
     *
     * @return list<string>
     */
    public function editableColumns(string $class): array
    {
        $fields = [];

        foreach ($this->formTypes as $form) {
            if (($form['entity'] ?? null) === $class) {
                $fields = [...$fields, ...array_map(strval(...), (array) ($form['fields'] ?? []))];
            }
        }

        if ($fields === []) {
            return [];
        }

        $columns = [];

        foreach ($fields as $field) {
            $column = $this->entities[$class]['columns'][$field]['column'] ?? null;

            if ($column !== null) {
                $columns[] = (string) $column;
            }
        }

        return array_values(array_unique($columns));
    }

    /**
     * Every column of an entity, by database name — field columns and the foreign keys
     * Doctrine models as associations. `image_id` is a real column of the pageparts
     * table; it is just not a *field* to the ORM.
     *
     * @return list<string>
     */
    public function columnsOf(string $class): array
    {
        $out = [];

        foreach ((array) ($this->entities[$class]['columns'] ?? []) as $spec) {
            if (isset($spec['column'])) {
                $out[] = (string) $spec['column'];
            }
        }

        foreach ((array) ($this->entities[$class]['associations'] ?? []) as $assoc) {
            foreach ((array) ($assoc['joinColumns'] ?? []) as $joinColumn) {
                $out[] = (string) $joinColumn;
            }
        }

        return $out;
    }
}
