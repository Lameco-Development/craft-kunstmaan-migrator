<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\Mapping;

/**
 * One entry of a `map:`, split into the parts a person can choose.
 *
 * `niv | titleLevel` is two decisions — which legacy column, and what to do to
 * it — presented as one string somebody has to know the grammar of. Split, they
 * are two dropdowns of real options.
 *
 * Not everything splits. `link(link_url, title, link_new_window)` composes
 * several columns into one field, and `ref(CaseCategory)` names an entity
 * rather than a column; those keep a text box, because a form that pretended
 * otherwise would quietly drop the argument list.
 */
final class FieldExpression
{
    private function __construct(
        public readonly string $column,
        public readonly string $transform,
        public readonly string $advanced,
    ) {
    }

    public static function parse(string $expression): self
    {
        $expression = trim($expression);

        if ($expression === '') {
            return new self('', '', '');
        }

        // A function call, or anything else with punctuation the two-dropdown
        // shape cannot hold.
        if (preg_match('~^([\w.]+)(?:\s*\|\s*([\w]+))?$~', $expression, $m) !== 1) {
            return new self('', '', $expression);
        }

        return new self($m[1], $m[2] ?? '', '');
    }

    /**
     * Back to what the mapping stores, or an empty string when this field is
     * not filled from anything.
     */
    public static function compose(string $column, string $transform, string $advanced): string
    {
        $advanced = trim($advanced);

        if ($advanced !== '') {
            return $advanced;
        }

        $column = trim($column);
        $transform = trim($transform);

        if ($column === '') {
            return '';
        }

        return $transform === '' ? $column : sprintf('%s | %s', $column, $transform);
    }

    public function isAdvanced(): bool
    {
        return $this->advanced !== '';
    }
}
