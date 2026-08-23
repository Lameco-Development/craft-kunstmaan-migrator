<?php

declare(strict_types=1);

namespace Lameco\KumaCompile\Mapping;

use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;

/**
 * A parsed mapping file. Read-only; every accessor returns plain arrays so the
 * rest of the tool never reaches back into the YAML structure by hand.
 */
final class Mapping
{
    private const LANES = ['parts', 'forms', 'globals', 'redirects'];

    /** @param array<string, mixed> $data */
    private function __construct(
        public readonly string $path,
        private readonly array $data,
    ) {
    }

    /**
     * A mapping from data already in memory — the seam MappingDocument uses to
     * validate an edit before it is written to disk.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data, string $path = ''): self
    {
        return new self($path, self::resolveTags($data));
    }

    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            throw new MappingException(sprintf('Mapping file not found: %s', $path));
        }

        $data = Yaml::parseFile($path, Yaml::PARSE_CUSTOM_TAGS);

        if (!is_array($data)) {
            throw new MappingException(sprintf('Mapping file is not a YAML mapping: %s', $path));
        }

        return new self($path, self::resolveTags($data));
    }

    /**
     * The DSL uses `!unmapped "<reason>"` to declare a deliberate non-goal. Resolving it to
     * null keeps every "is there a target?" check a plain null check, while the reason stays
     * readable in the file.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function resolveTags(array $data): array
    {
        foreach ($data as $key => $value) {
            $data[$key] = match (true) {
                $value instanceof TaggedValue && $value->getTag() === 'unmapped' => null,
                is_array($value)                                                 => self::resolveTags($value),
                default                                                          => $value,
            };
        }

        return $data;
    }

    /** @return array<string, mixed> the raw parsed tree; for schema checks only */
    public function all(): array
    {
        return $this->data;
    }

    /** @return list<array<string, mixed>> */
    public function sequence(): array
    {
        return $this->data['sequence'] ?? [];
    }

    public function version(): int
    {
        return (int) ($this->data['version'] ?? 0);
    }

    /** @return array<string, array<string, mixed>> */
    public function environments(): array
    {
        return $this->data['environments'] ?? [];
    }

    /** @return array<string, array<string, mixed>> */
    public function pages(): array
    {
        return $this->data['pages'] ?? [];
    }

    /** @return array<string, array<string, mixed>> */
    public function parts(): array
    {
        return $this->data['parts'] ?? [];
    }

    /**
     * Per-page sidecar entities, read by the polymorphic ref every Kunstmaan tab uses.
     *
     * Kunstmaan attaches page-level extras — a header tab, a footer tab, structured data —
     * as separate entities keyed by `(ref_entity_name, ref_id)`, outside the pagepart tree.
     * The name differs per site; the column signature does not, which is what makes this a
     * lane rather than a special case: any table carrying that pair can be named here and
     * joined to every page it decorates.
     *
     * @return array<string, array<string, mixed>> sidecar name => spec
     */
    public function sidecars(): array
    {
        return $this->data['sidecars'] ?? [];
    }

    /**
     * Non-node legacy tables that become entries of their own.
     *
     * A Kunstmaan corpus keeps its taxonomies outside the node tree — categories, countries,
     * vendors, certification levels — and every page FK into one of them is a relation with
     * nowhere to point until the table itself has been migrated. This lane is what gives
     * those rows a Craft identity, and `ref(<Entity>)` is how a page reaches it.
     *
     * @return array<string, array<string, mixed>> entity name => spec
     */
    public function entities(): array
    {
        return $this->data['entities'] ?? [];
    }

    /**
     * Legacy page entities that become redirects rather than entries.
     *
     * @return array<string, array<string, mixed>> page entity name => spec
     */
    public function redirects(): array
    {
        return $this->data['redirects'] ?? [];
    }

    /** @return array<string, array<string, mixed>> */
    public function formFields(): array
    {
        return $this->data['forms']['fields'] ?? [];
    }

    /**
     * The `globals:` lane as declared: page type => contexts + parts.
     *
     * `globalParts()` below flattens every page's parts into one list, which is
     * what the lane-collision check needs and the only thing that ever read this
     * block — so the lane's own structure had no accessor at all.
     *
     * @return array<string, array<string, mixed>>
     */
    public function globals(): array
    {
        return $this->data['globals'] ?? [];
    }

    /** @return array<string, array<string, mixed>> */
    public function globalParts(): array
    {
        $parts = [];

        foreach ($this->data['globals'] ?? [] as $page) {
            foreach ($page['parts'] ?? [] as $class => $spec) {
                $parts[$class] = $spec;
            }
        }

        return $parts;
    }

    /** @return array<string, string> pagepart class => reason */
    public function unmappedParts(): array
    {
        return $this->data['unmapped']['parts'] ?? [];
    }

    /** @return array<string, string> page entity => reason */
    public function unmappedPageTypes(): array
    {
        return $this->data['unmapped']['pageTypes'] ?? [];
    }

    /**
     * Every pagepart class the mapping accounts for, in any lane, mapped to the
     * lane that claims it. A class the compiler meets that is absent here is an error.
     *
     * @return array<string, string> pagepart class => lane
     */
    public function accountedParts(): array
    {
        $accounted = [];

        foreach ($this->parts() as $class => $spec) {
            $accounted[$class] = match (true) {
                ($spec['consumedBy'] ?? null) === 'sequence' => 'sequence',
                isset($spec['drop'])                         => 'dropped',
                isset($spec['manual'])                       => 'manual',
                default                                      => 'blocks',
            };
        }

        foreach (array_keys($this->formFields()) as $class) {
            $accounted[$class] ??= 'forms';
        }

        foreach (array_keys($this->globalParts()) as $class) {
            $accounted[$class] ??= 'globals';
        }

        foreach (array_keys($this->unmappedParts()) as $class) {
            $accounted[$class] ??= 'unmapped';
        }

        return $accounted;
    }

    /**
     * Every page entity the mapping accounts for.
     *
     * @return array<string, string> page entity => lane
     */
    public function accountedPageTypes(): array
    {
        $accounted = [];

        foreach ($this->pages() as $entity => $spec) {
            $accounted[$entity] = isset($spec['manual']) ? 'manual' : 'pages';
        }

        foreach (array_keys($this->data['redirects'] ?? []) as $entity) {
            $accounted[$entity] ??= 'redirects';
        }

        foreach (array_keys($this->data['globals'] ?? []) as $entity) {
            $accounted[$entity] ??= 'globals';
        }

        foreach (array_keys($this->unmappedPageTypes()) as $entity) {
            $accounted[$entity] ??= 'unmapped';
        }

        return $accounted;
    }

    /**
     * Unresolved disagreements between the source mappings. While any of these
     * is `open`, the mapping is not a program and the compiler must not run.
     *
     * @return array<int, Conflict>
     */
    public function openConflicts(): array
    {
        $open = [];

        foreach (self::LANES as $lane) {
            foreach ($this->data[$lane] ?? [] as $name => $spec) {
                if (!is_array($spec) || !is_array($spec['conflict'] ?? null)) {
                    continue;
                }

                if (($spec['conflict']['status'] ?? 'open') !== 'open') {
                    continue;
                }

                $open[] = new Conflict(
                    lane: $lane,
                    subject: (string) $name,
                    artifact: (string) ($spec['conflict']['artifact'] ?? '?'),
                    spec: (string) ($spec['conflict']['spec'] ?? '?'),
                    note: isset($spec['conflict']['note']) ? trim((string) $spec['conflict']['note']) : null,
                    live: isset($spec['live']) ? (int) $spec['live'] : null,
                );
            }
        }

        usort($open, static fn (Conflict $a, Conflict $b) => ($b->live ?? 0) <=> ($a->live ?? 0));

        return $open;
    }

    /**
     * Columns a generated skeleton could not place, per subject, still awaiting a human.
     *
     * @return array<string, list<string>> subject => column names
     */
    public function unreviewed(): array
    {
        $out = [];

        foreach ($this->reviewableSubjects() as $subject => $spec) {
            $columns = $spec['unreviewed'] ?? [];

            if (is_array($columns) && $columns !== []) {
                $out[$subject] = array_values(array_map(strval(...), $columns));
            }
        }

        return $out;
    }

    /**
     * Ignored columns carrying no reason — the legacy list form, `ignore: [a, b, c]`.
     *
     * Not an error: the list form is how every mapping was written before reasons existed, and
     * failing on it would brick a corpus rather than improve it. But it is the state where a
     * decision and a generator default look identical, so it is counted and reported rather than
     * left to look settled.
     *
     * @return array<string, list<string>> subject => column names
     */
    public function unreasonedIgnores(): array
    {
        $out = [];

        foreach ($this->reviewableSubjects() as $subject => $spec) {
            $ignore = $spec['ignore'] ?? null;

            // A map is `column: reason`; a list is the unreasoned legacy form.
            if (is_array($ignore) && $ignore !== [] && array_is_list($ignore)) {
                $out[$subject] = array_values(array_map(strval(...), $ignore));
            }
        }

        return $out;
    }

    /**
     * Everything that can carry `ignore:` / `unreviewed:`, keyed by a human-readable path.
     *
     * @return array<string, array<string, mixed>>
     */
    private function reviewableSubjects(): array
    {
        $subjects = [];

        foreach (['pages' => 'page', 'parts' => 'part', 'entities' => 'entity'] as $lane => $noun) {
            foreach ($this->data[$lane] ?? [] as $name => $spec) {
                if (!is_array($spec)) {
                    continue;
                }

                $subjects[sprintf('%s `%s`', $noun, $name)] = $spec;

                foreach ($spec['children'] ?? [] as $field => $child) {
                    if (is_array($child)) {
                        $subjects[sprintf('%s `%s`, child `%s`', $noun, $name, $field)] = $child;
                    }
                }

                foreach ($spec['promote'] ?? [] as $table => $promo) {
                    if (is_array($promo)) {
                        $subjects[sprintf('%s `%s`, promote `%s`', $noun, $name, $table)] = $promo;
                    }
                }
            }
        }

        return $subjects;
    }

    /** Placeholders the mapping still carries: `todo:` and null-valued settings. */
    public function todos(): array
    {
        $todos = [];
        $data = $this->data;

        array_walk_recursive(
            $data,
            static function (mixed $value, string|int $key) use (&$todos): void {
                if ($key === 'todo' && is_string($value)) {
                    $todos[] = trim($value);
                }
            }
        );

        return $todos;
    }
}
