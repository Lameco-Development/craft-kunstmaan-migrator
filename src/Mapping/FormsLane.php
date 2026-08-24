<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\Mapping;

/**
 * The `forms:` lane's facts, as the compiler and the reports read them.
 *
 * The lane's per-field rows stay on `Mapping::formFields()`; this is the rest of
 * it: whether it is declared at all, which Kunstmaan context holds a page's form,
 * and the block the lane emits (with the field on it the lane fills itself).
 */
final class FormsLane
{
    private const DEFAULT_CONTEXT = 'form';

    private function __construct(
        public readonly bool $declared,
        public readonly string $context,
        public readonly ?string $emitBlock,
        public readonly ?string $emitField,
    ) {
    }

    public static function fromSpec(mixed $spec): self
    {
        if (!is_array($spec) || $spec === []) {
            return new self(false, self::DEFAULT_CONTEXT, null, null);
        }

        $emit = is_array($spec['emit'] ?? null) ? $spec['emit'] : [];
        $context = $spec['context'] ?? null;

        return new self(
            declared: true,
            context: is_string($context) && $context !== '' ? $context : self::DEFAULT_CONTEXT,
            emitBlock: self::string($emit, 'block'),
            emitField: self::string($emit, 'field'),
        );
    }

    /** @param array<string, mixed> $spec */
    private static function string(array $spec, string $key): ?string
    {
        $value = $spec[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
