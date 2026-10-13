<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\finalize;

/**
 * The legacy references the finalize pass knows how to resolve, as one list asked two ways:
 * of the database, to shortlist the rows worth loading, and of a field value, to skip one that
 * holds none. One list, so the two questions cannot disagree — when they did, 94 encoded media
 * tokens on Berkvens NL were never loaded.
 *
 * Craft percent-encodes the brackets of `src="[M3679]"` on save, so a body whose only legacy
 * reference is a media placeholder is stored as `%5BM3679%5D` (and `%255BM3679%255D` when an
 * already-encoded href was saved again). Both escape cases are listed: `elements_sites.content`
 * is JSON, and MySQL compares a JSON column case-sensitively.
 */
final class LegacyMarkers
{
    private const MARKERS = [
        '/uploads/media/',
        '[NT',
        '%5BNT',
        '%5bNT',
        '%255BNT',
        '%255bNT',
        '[M',
        '%5BM',
        '%5bM',
        '%255BM',
        '%255bM',
    ];

    /** Whether stored text still holds a legacy reference. */
    public static function inText(string $text): bool
    {
        foreach (self::MARKERS as $marker) {
            if (str_contains($text, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The same question as a Yii condition on a column: any marker, as a substring.
     *
     * @return array<int, mixed>
     */
    public static function likeConditions(string $column): array
    {
        $conditions = ['or'];

        foreach (self::MARKERS as $marker) {
            // No fourth operand: Yii's `false` there means "match the value literally", which
            // turns a contains-check into an equality-check and finds nothing. The default
            // escapes `%` and `_`, so `%5BM` is matched as written.
            $conditions[] = ['like', $column, $marker];
        }

        return $conditions;
    }
}
