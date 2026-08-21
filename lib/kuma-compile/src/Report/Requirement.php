<?php

declare(strict_types=1);

namespace Lameco\KumaCompile\Report;

/**
 * One required Craft field, and what the mapping does about it.
 *
 * A required field the mapping never fills is not a validation error — the field may carry a
 * default — but it is the thing that decides whether a load survives. The compiler drops empty
 * values from a payload rather than writing them, so "mapped to a column that is empty on 80% of
 * live rows" fails exactly like "not mapped at all", just later and only on some rows. Both cases
 * are the same question to a human: supply a default here, or relax the field in Craft.
 */
final class Requirement
{
    public const OK = 'ok';
    public const DEFAULTED = 'default';
    public const PARTIAL = 'partial';
    public const MISSING = 'missing';

    public function __construct(
        public readonly string $lane,
        public readonly string $subject,
        public readonly string $target,
        public readonly string $field,
        public readonly ?string $source = null,
        public readonly ?string $supplier = null,
        public readonly ?int $live = null,
        public ?int $rows = null,
        public ?int $empty = null,
        public readonly bool $totalTransform = false,
        public readonly ?string $craftDefault = null,
    ) {
    }

    public function isSupplied(): bool
    {
        return $this->supplier !== null;
    }

    /**
     * The column the fill rate has to be measured on, or null when the expression reads
     * something other than one plain column — a `link(...)` gather, a literal, `node.*`, or a
     * transform that manufactures a value out of an empty one.
     */
    public function measurableColumn(): ?string
    {
        if ($this->supplier !== 'map' || $this->source === null || $this->totalTransform) {
            return null;
        }

        $expression = trim($this->source);

        if ($expression === '' || str_contains($expression, '(') || str_starts_with($expression, "'")) {
            return null;
        }

        $column = trim(explode('|', $expression)[0]);

        return $column === '' || str_contains($column, '.') ? null : $column;
    }

    /**
     * A field Craft fills itself is not a hole.
     *
     * Craft applies an option marked `default: true` when the element is fresh and the payload
     * omits the field — and the compiler omits a field whose value came out empty. So a required
     * dropdown with a default lands on that default, whether the mapping never filled it or filled
     * it from a column that is empty on some rows. Worth reporting, because it is a value the
     * migration is choosing, but it is not a decision anyone has to take before loading.
     */
    public function verdict(): string
    {
        $short = !$this->isSupplied()
            || ($this->empty !== null && $this->rows !== null && $this->rows > 0 && $this->empty > 0);

        if (!$short) {
            return self::OK;
        }

        if ($this->craftDefault !== null) {
            return self::DEFAULTED;
        }

        return $this->isSupplied() ? self::PARTIAL : self::MISSING;
    }

    /** What a human has to decide, in the two terms the target actually offers. */
    public function action(): string
    {
        return match ($this->verdict()) {
            self::MISSING   => 'set a default in the mapping, or relax the field in Craft',
            self::PARTIAL   => 'add a fallback for the empty rows, or relax the field in Craft',
            self::DEFAULTED => sprintf('none — Craft writes `%s`; override in the mapping to choose otherwise', (string) $this->craftDefault),
            default         => '',
        };
    }

    public function affected(): int
    {
        return match ($this->verdict()) {
            self::MISSING             => $this->live ?? 0,
            self::PARTIAL             => $this->empty ?? 0,
            self::DEFAULTED           => $this->empty ?? $this->live ?? 0,
            default                   => 0,
        };
    }
}
