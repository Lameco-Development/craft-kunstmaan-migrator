<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\mapping;

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

    /** @return array<string, array<string, mixed>> */
    public function formFields(): array
    {
        return $this->data['forms']['fields'] ?? [];
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
