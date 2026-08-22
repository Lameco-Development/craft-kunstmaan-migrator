<?php

declare(strict_types=1);

namespace Lameco\KumaCompile\Mapping;

use Symfony\Component\Yaml\Yaml;

/**
 * The mapping as something you can edit, not just read.
 *
 * `Mapping` exists to be compiled from: it resolves `!unmapped "reason"` to
 * null, because a lane that is not migrated has no target and the compiler
 * wants the absence, not the reason. That is right for compiling and fatal for
 * editing — saving a Mapping back would erase every deliberate non-goal in the
 * file along with the reason someone wrote down for it.
 *
 * So this is the editing view: the same file, parsed with custom tags intact,
 * mutable per row, and writable back to disk. The file stays the single source
 * of truth — a mapping is reviewed in a pull request, and an edit that did not
 * show up in the diff would not be.
 *
 * What is lost on the first save is the generator's prose: the `# TODO: Craft
 * block handle` hints and the header explaining what to fill in. Every fact
 * those comments sit beside — the live count, the discovered table, the columns
 * nobody has placed — is already a real key (`live`, `table`, `unreviewed`), so
 * nothing measured is lost. The hints belong on the fields of the editor
 * anyway, where they are read at the moment they are needed.
 */
final class MappingDocument
{
    /** @param array<string, mixed> $data */
    private function __construct(
        private array $data,
        private readonly ?string $path = null,
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

        return new self($data, $path);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->data;
    }

    /**
     * One lane's rows — `parts`, `pages`, `entities`, `redirects`, `globals`.
     *
     * @return array<string, mixed>
     */
    public function lane(string $lane): array
    {
        $rows = $this->data[$lane] ?? [];

        return is_array($rows) ? $rows : [];
    }

    /**
     * One row, or null when the lane does not name it.
     *
     * @return array<string, mixed>|null
     */
    public function row(string $lane, string $key): ?array
    {
        $row = $this->lane($lane)[$key] ?? null;

        return is_array($row) ? $row : null;
    }

    /**
     * Change some keys of one row, leaving the rest alone.
     *
     * A patch rather than a replace because an editor shows a handful of a
     * row's keys and a row carries more than that: overwriting the row with
     * what the form posted would drop `live`, `unreviewed` and `children`
     * every time somebody set a block handle.
     *
     * A key set to null is removed, which is how the editor clears one.
     *
     * @param array<string, mixed> $changes
     */
    public function patch(string $lane, string $key, array $changes): self
    {
        $row = $this->row($lane, $key) ?? [];

        foreach ($changes as $name => $value) {
            if ($value === null) {
                unset($row[$name]);

                continue;
            }

            $row[$name] = $value;
        }

        $this->data[$lane][$key] = $row;

        return $this;
    }

    /**
     * The compiling view of the same data, so a caller can validate an edit
     * before writing it.
     */
    public function mapping(): Mapping
    {
        return Mapping::fromArray($this->data, $this->path ?? '');
    }

    public function toYaml(): string
    {
        return Yaml::dump(
            $this->ordered(),
            // Deep enough that `parts.<class>.children.<field>.map` still nests
            // rather than collapsing to one inline blob.
            6,
            2,
            Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_NULL_AS_TILDE,
        );
    }

    public function save(?string $path = null): void
    {
        $target = $path ?? $this->path;

        if ($target === null) {
            throw new MappingException('This mapping has no path to save to.');
        }

        if (file_put_contents($target, $this->toYaml()) === false) {
            throw new MappingException(sprintf('Could not write the mapping to %s', $target));
        }
    }

    /**
     * Top-level keys in the order the DSL declares them.
     *
     * Not cosmetic: this file is read in a pull request, and a diff that
     * reorders the whole document because a hash happened to iterate
     * differently is a diff nobody reviews. Anything the schema does not name
     * keeps its position at the end rather than being dropped.
     *
     * @return array<string, mixed>
     */
    private function ordered(): array
    {
        $out = [];

        foreach (Schema::topLevelKeys() as $key) {
            if (array_key_exists($key, $this->data)) {
                $out[$key] = $this->data[$key];
            }
        }

        foreach ($this->data as $key => $value) {
            $out[$key] ??= $value;
        }

        return $out;
    }
}
