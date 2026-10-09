<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\Mapping;

/**
 * One `lookup(<source>.<column>[, <order>])` transform.
 *
 * `<source>` is a declared entity, or else a plain legacy table — one that never becomes an
 * entry, such as the `model_photos` a join table points into, or the `document` a file row's
 * `document_id` names. Only a lower-case snake-case name can be a table, which is how every
 * Doctrine table is named: an undeclared `CamelCase` name is a misspelled entity, and the shape
 * check says so instead of letting it compile to nothing.
 *
 * It lives in the mapping vocabulary rather than beside `Compile\BlockBuilder`, which evaluates
 * it, because the shape check and `validate --live` parse it too.
 */
final readonly class LookupExpression
{
    private const CALL = '/\blookup\((\w+)\.(\w+)(?:\s*,\s*(\w+))?\)/';

    private const TABLE_NAME = '/^[a-z][a-z0-9_]*$/';

    private function __construct(
        public string $source,
        public string $column,
        public ?string $order,
    ) {
    }

    /** The transform, when it is a lookup; null for any other. */
    public static function parse(string $transform): ?self
    {
        $transform = trim($transform);

        if (preg_match(self::CALL, $transform, $m) !== 1 || $m[0] !== $transform) {
            return null;
        }

        return new self($m[1], $m[2], ($m[3] ?? '') !== '' ? $m[3] : null);
    }

    /**
     * Every lookup a `map:` value makes, at any depth — inside `address()` or `coalesce()` too.
     *
     * @return list<self>
     */
    public static function allIn(string $expression): array
    {
        preg_match_all(self::CALL, $expression, $matches, PREG_SET_ORDER);

        return array_map(
            static fn(array $m): self => new self($m[1], $m[2], ($m[3] ?? '') !== '' ? $m[3] : null),
            $matches,
        );
    }

    /** Whether the source can name a plain table — the reading of any name no entity declares. */
    public function namesTable(): bool
    {
        return preg_match(self::TABLE_NAME, $this->source) === 1;
    }

    /** As the mapping writes it, for messages. */
    public function __toString(): string
    {
        return sprintf('lookup(%s.%s%s)', $this->source, $this->column, $this->order !== null ? ', ' . $this->order : '');
    }
}
