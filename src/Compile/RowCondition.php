<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\Compile;

/**
 * A `when:` read against a row's own columns.
 *
 * `BlockBuilder`'s switch asks about a part's *children* — "does any item carry an icon"
 * — because that is what decides a Page Builder block. A form field is decided by the
 * part's own configuration instead: Kunstmaan has one `Choice` part, and `expanded` and
 * `multiple` are what make it a select, a radio group or a checkbox group. Same question,
 * different subject, so a different condition shape rather than a bigger one.
 *
 * The grammar is deliberately small — comparisons joined by `and`:
 *
 *     expanded == 1
 *     expanded == 1 and multiple == 1
 *     expanded == 1 and multiple == 1 and lines(choices) == 1
 *     internal_name == null
 *     label == '_'
 *
 * Anything it cannot parse is false. A condition that passed when it was not understood
 * would hand every row to the same wrong case, which is harder to spot than none matching.
 */
final class RowCondition
{
    /** @param array<string, mixed> $row */
    public static function matches(string $expression, array $row): bool
    {
        $expression = trim($expression);

        if ($expression === '') {
            return false;
        }

        foreach (preg_split('/\s+and\s+/i', $expression) ?: [] as $term) {
            if (!self::term(trim($term), $row)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $row */
    private static function term(string $term, array $row): bool
    {
        if (preg_match('/^(lines\(\s*\w+\s*\)|\w+)\s*(==|!=)\s*(.+)$/', $term, $m) !== 1) {
            return false;
        }

        [, $subject, $operator, $literal] = $m;
        $left = self::read($subject, $row);
        $right = self::literal(trim($literal));

        if ($right === self::class) {
            // An unparseable right-hand side, not a value that happens to be null.
            return false;
        }

        $equal = $right === null
            ? $left === null || $left === ''
            : (string) $left === (string) $right;

        return $operator === '==' ? $equal : !$equal;
    }

    /** @param array<string, mixed> $row */
    private static function read(string $subject, array $row): mixed
    {
        if (preg_match('/^lines\(\s*(\w+)\s*\)$/', $subject, $m) === 1) {
            // The same split `| lines` performs, so a condition and the value it guards
            // can never disagree about how many options a textarea holds.
            $lines = preg_split('/\R/u', trim((string) ($row[$m[1]] ?? ''))) ?: [];

            return count(array_filter(array_map('trim', $lines), static fn(string $l): bool => $l !== ''));
        }

        return $row[$subject] ?? null;
    }

    /** @return mixed the literal, or this class's name when it is not one */
    private static function literal(string $literal): mixed
    {
        if ($literal === 'null') {
            return null;
        }

        if (preg_match('/^-?\d+$/', $literal) === 1) {
            return (int) $literal;
        }

        if (preg_match("/^'(.*)'$/s", $literal, $m) === 1) {
            return $m[1];
        }

        return self::class;
    }
}
