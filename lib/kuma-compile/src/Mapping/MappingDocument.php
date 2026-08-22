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
    /** @var array<string, list<string>> "lane\0key" => the keys of that row this edit changed */
    private array $touched = [];

    /**
     * @param array<string, mixed> $data
     * @param string|null $source the file exactly as it was read, so a save can
     *        put back everything it did not change
     */
    private function __construct(
        private array $data,
        private readonly ?string $path = null,
        private readonly ?string $source = null,
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

        return new self($data, $path, (string) file_get_contents($path));
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

        $slot = $lane . "\0" . $key;
        $this->touched[$slot] = array_values(array_unique([
            ...($this->touched[$slot] ?? []),
            ...array_map(strval(...), array_keys($changes)),
        ]));

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

    /**
     * Write the file back, changing as little of it as possible.
     *
     * Dumping the whole document is correct and useless: on the Enreach mapping
     * one added `ignore:` reason produced a 1,652-line diff, because every
     * comment went and every inline `{a: b}` became a block. The reason to
     * write to the file at all is that a mapping is reviewed in a pull request,
     * and nobody reviews that.
     *
     * So an edit rewrites the rows it touched and nothing else. The file keeps
     * its header, its provenance notes, its `conflict:` explanations and its
     * formatting, and the diff is the decision somebody made. Comments *inside*
     * an edited row are lost, which is the honest cost and a local one.
     *
     * A document with no source to splice into — one built from an array, or
     * whose rows could not be located — falls back to a full dump.
     */
    public function save(?string $path = null): void
    {
        $target = $path ?? $this->path;

        if ($target === null) {
            throw new MappingException('This mapping has no path to save to.');
        }

        $yaml = $this->source !== null && $this->touched !== []
            ? $this->splicedSource()
            : $this->toYaml();

        if (file_put_contents($target, $yaml) === false) {
            throw new MappingException(sprintf('Could not write the mapping to %s', $target));
        }
    }

    /**
     * The original text with each touched row swapped for its current value.
     */
    private function splicedSource(): string
    {
        $text = (string) $this->source;

        foreach ($this->touched as $slot => $changedKeys) {
            [$lane, $key] = explode("\0", $slot, 2);
            $row = $this->data[$lane][$key] ?? null;

            if (!is_array($row)) {
                continue;
            }

            $spliced = self::replaceRow($text, $lane, $key, $row, $changedKeys);

            // A row the writer cannot find is one it must not guess at: falling
            // back to a full dump keeps the edit rather than losing it, and the
            // diff says loudly that something unusual happened.
            if ($spliced === null) {
                return $this->toYaml();
            }

            $text = $spliced;
        }

        return $text;
    }

    /**
     * Rewrite only the keys of one row that changed.
     *
     * Replacing the whole row is already a big improvement on dumping the whole
     * file, and it still moves lines nobody edited: Symfony's dumper re-quotes
     * any scalar containing a comma, so a `todo:` sentence would show up in a
     * diff about an `ignore:` reason. A reviewer then has to work out which of
     * the changed lines is the decision.
     *
     * So the row is split into its own top-level keys by indentation — which is
     * what YAML block structure means, and is why this can be done on text
     * without reflowing anything — and only the changed ones are re-emitted.
     *
     * @param array<string, mixed> $row
     * @param list<string> $changedKeys
     */
    private static function replaceRow(string $text, string $lane, string $key, array $row, array $changedKeys): ?string
    {
        $lines = explode("\n", $text);
        $bounds = self::rowBounds($lines, $lane, $key);

        if ($bounds === null) {
            return null;
        }

        [$start, $end] = $bounds;
        $out = [$lines[$start]];
        $written = [];

        foreach (self::keySegments($lines, $start + 1, $end) as [$name, $from, $to]) {
            if (!in_array($name, $changedKeys, true)) {
                for ($i = $from; $i < $to; $i++) {
                    $out[] = $lines[$i];
                }

                continue;
            }

            $written[] = $name;

            // A key the edit removed leaves with its lines.
            if (array_key_exists($name, $row)) {
                $out[] = self::emit($name, $row[$name]);
            }
        }

        // Keys the edit added, which have no lines to replace.
        foreach ($changedKeys as $name) {
            if (!in_array($name, $written, true) && array_key_exists($name, $row)) {
                $out[] = self::emit($name, $row[$name]);
            }
        }

        array_splice($lines, $start, $end - $start, array_merge(...array_map(
            static fn (string $chunk): array => explode("\n", rtrim($chunk, "\n")),
            $out,
        )));

        return implode("\n", $lines);
    }

    /**
     * One key of a row, as the lines it occupies.
     */
    private static function emit(string $name, mixed $value): string
    {
        return self::indent(
            self::inlineScalarLists(Yaml::dump([$name => $value], 6, 2, Yaml::DUMP_NULL_AS_TILDE)),
            4,
        );
    }

    /**
     * Where a row starts and ends: from its own line to the next line at its
     * indent or shallower. Trailing blank lines belong to the gap between rows.
     *
     * @param list<string> $lines
     * @return array{0: int, 1: int}|null
     */
    private static function rowBounds(array $lines, string $lane, string $key): ?array
    {
        $inLane = false;
        $start = null;

        foreach ($lines as $i => $line) {
            if (preg_match('~^' . preg_quote($lane, '~') . ':~', $line) === 1) {
                $inLane = true;

                continue;
            }

            if (!$inLane) {
                continue;
            }

            if ($start === null) {
                // Back at column zero: the lane is over without the row in it.
                if ($line !== '' && !str_starts_with($line, ' ') && !str_starts_with($line, '#')) {
                    return null;
                }

                if (preg_match('~^  ' . preg_quote($key, '~') . ':~', $line) === 1) {
                    $start = $i;
                }

                continue;
            }

            if (trim($line) !== '' && !str_starts_with($line, '   ')) {
                return [$start, self::withoutTrailingBlanks($lines, $start, $i)];
            }
        }

        return $start === null ? null : [$start, self::withoutTrailingBlanks($lines, $start, count($lines))];
    }

    /** @param list<string> $lines */
    private static function withoutTrailingBlanks(array $lines, int $start, int $end): int
    {
        while ($end - 1 > $start && trim($lines[$end - 1]) === '') {
            $end--;
        }

        return $end;
    }

    /**
     * A row's own keys, each with the line range it occupies. A comment line
     * belongs to the key it precedes, so it travels with it.
     *
     * @param list<string> $lines
     * @return list<array{0: string, 1: int, 2: int}>
     */
    private static function keySegments(array $lines, int $from, int $to): array
    {
        $segments = [];
        $pending = null;

        for ($i = $from; $i < $to; $i++) {
            if (preg_match('~^    ([\w-]+):~', $lines[$i], $m) !== 1) {
                continue;
            }

            if ($pending !== null) {
                $segments[] = [$pending[0], $pending[1], $i];
            }

            $pending = [$m[1], $i];
        }

        if ($pending !== null) {
            $segments[] = [$pending[0], $pending[1], $to];
        }

        return $segments;
    }

    private static function inlineScalarLists(string $yaml): string
    {
        $lines = explode("\n", $yaml);
        $out = [];

        for ($i = 0; $i < count($lines); $i++) {
            $line = $lines[$i];

            if (preg_match('~^(\s*)([\w-]+):\s*$~', $line, $m) !== 1) {
                $out[] = $line;

                continue;
            }

            [$all, $indent, $key] = $m;
            $items = [];
            $j = $i + 1;

            while ($j < count($lines) && preg_match('~^' . $indent . '  - (.*)$~', $lines[$j], $item) === 1) {
                // Only plain scalars: anything quoted, nested or long stays as
                // the dumper wrote it rather than being re-flowed by guesswork.
                if (preg_match('~^[\w.\/-]+$~', $item[1]) !== 1) {
                    $items = [];

                    break;
                }

                $items[] = $item[1];
                $j++;
            }

            if ($items === [] || $j === $i + 1) {
                $out[] = $line;

                continue;
            }

            $inline = sprintf('%s%s: [%s]', $indent, $key, implode(', ', $items));

            if (strlen($inline) > 96) {
                $out[] = $line;

                continue;
            }

            $out[] = $inline;
            $i = $j - 1;
        }

        return implode("\n", $out);
    }

    private static function indent(string $yaml, int $spaces): string
    {
        $pad = str_repeat(' ', $spaces);

        return implode("\n", array_map(
            static fn (string $line): string => $line === '' ? '' : $pad . $line,
            explode("\n", rtrim($yaml, "\n")),
        )) . "\n";
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
