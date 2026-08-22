<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\mapping;

/**
 * One row of a mapping lane, as something to decide about.
 *
 * The lane's raw array says what the file contains; this says what state the
 * row is in, which is the question an operator is actually holding: has this
 * been decided, deliberately dropped, or not looked at yet.
 */
final class MappingRow
{
    public const DECIDED = 'decided';
    public const DROPPED = 'dropped';
    public const OPEN = 'open';

    /**
     * @param list<string>           $unreviewed legacy columns nobody has placed
     * @param array<string, string>  $map        Craft field => legacy column expression.
     *        That direction is the DSL's: `dynamicsId: dynamics_id` reads "this
     *        Craft field is filled from that legacy column", and one field can
     *        consume several columns through an expression, which the other
     *        direction cannot express.
     * @param array<string, ?string> $ignore     legacy column => why it is not migrated
     * @param array<string, mixed>   $spec       the row as the file holds it
     */
    private function __construct(
        public readonly string $key,
        public readonly int $live,
        public readonly ?string $table,
        public readonly ?string $target,
        public readonly ?string $dropped,
        public readonly array $unreviewed,
        public readonly array $map,
        public readonly array $ignore,
        public readonly array $spec,
    ) {
    }

    /** @param array<string, mixed> $spec */
    public static function fromSpec(string $key, array $spec): self
    {
        return new self(
            key: $key,
            live: (int) ($spec['live'] ?? 0),
            table: isset($spec['table']) && is_string($spec['table']) ? $spec['table'] : null,
            // `block` for a part, `entryType` for a page, `section` for an entity:
            // one row's target under whichever key its lane names it.
            target: self::firstString($spec, ['block', 'entryType', 'target']),
            dropped: self::firstString($spec, ['drop', 'manual', 'consumedBy']),
            unreviewed: array_values(array_map(strval(...), (array) ($spec['unreviewed'] ?? []))),
            map: array_map(strval(...), (array) ($spec['map'] ?? [])),
            ignore: self::normaliseIgnore((array) ($spec['ignore'] ?? [])),
            spec: $spec,
        );
    }

    public function status(): string
    {
        if ($this->dropped !== null) {
            return self::DROPPED;
        }

        // Unreviewed columns keep a row open even once it has a target: the
        // block is chosen and some of its content still has nowhere to go, and
        // a list that called that finished would hide the remaining work.
        if ($this->target !== null && $this->unreviewed === []) {
            return self::DECIDED;
        }

        return self::OPEN;
    }

    /**
     * The legacy columns this row still owes a decision on, or already made one
     * about — with which of the two it is.
     *
     * The distinction is the point, and collapsing it is how a screen comes to
     * show a decision as an oversight. `ignore: [color, show_as_slider]` is the
     * list form of a real decision that predates reasons being required; a
     * column in `unreviewed:` is one nobody has looked at. Both have no reason
     * attached, and they are not the same thing.
     *
     * Columns consumed by `map:` are deliberately absent: a mapped column lives
     * inside an expression on the Craft field that eats it, and one expression
     * can consume several. Listing them again as separate decisions would
     * invite two answers to one question.
     *
     * @return array<string, array{ignored: bool, reason: ?string}>
     */
    public function columns(): array
    {
        $out = [];

        foreach ($this->ignore as $column => $reason) {
            $out[$column] = ['ignored' => true, 'reason' => $reason];
        }

        foreach ($this->unreviewed as $column) {
            $out[$column] ??= ['ignored' => false, 'reason' => null];
        }

        return $out;
    }

    /**
     * `ignore:` comes in two shapes and both are in the wild: a map of column
     * to reason, which is what the DSL wants, and a bare list of columns from
     * before reasons were required. Reading only the first shows a list-form
     * row's columns as `0`, `1`, `2`.
     *
     * @param array<mixed, mixed> $ignore
     * @return array<string, ?string>
     */
    private static function normaliseIgnore(array $ignore): array
    {
        $out = [];

        foreach ($ignore as $key => $value) {
            if (is_int($key)) {
                $out[(string) $value] = null;

                continue;
            }

            $out[(string) $key] = $value === null ? null : (string) $value;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $spec
     * @param list<string>         $keys
     */
    private static function firstString(array $spec, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($spec[$key]) && is_string($spec[$key]) && $spec[$key] !== '') {
                return $spec[$key];
            }
        }

        return null;
    }
}
