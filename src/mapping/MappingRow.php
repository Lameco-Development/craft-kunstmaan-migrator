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
     * @param list<string>          $unreviewed legacy columns nobody has placed
     * @param array<string, mixed>  $map        legacy column => Craft field expression
     * @param array<string, mixed>  $ignore     legacy column => why it is not migrated
     * @param array<string, mixed>  $spec       the row as the file holds it
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
            map: (array) ($spec['map'] ?? []),
            ignore: (array) ($spec['ignore'] ?? []),
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
     * Every legacy column this row knows about, wherever it currently sits.
     *
     * @return list<string>
     */
    public function columns(): array
    {
        $columns = [
            ...array_keys($this->map),
            ...array_keys($this->ignore),
            ...$this->unreviewed,
        ];

        return array_values(array_unique(array_map(strval(...), $columns)));
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
