<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\Source;

/**
 * The two names a pagepart class goes by: its fully qualified class name, as the refs table
 * stores it, and its short name, as a mapping row is usually keyed.
 *
 * The short name drops the namespace and the `PagePart` suffix — `Text` for
 * `App\Entity\PageParts\TextPagePart`. Two namespaces can define the same short name (the
 * app's `TextPagePart` next to Kunstmaan's), and each has its own table with overlapping ids,
 * so a row that has to tell them apart is keyed by the fully qualified name instead.
 */
final class PartClass
{
    /**
     * A fully qualified name in one spelling: single separators, no leading one. MySQL stores
     * single backslashes, the sqlite fixtures doubled ones, and a YAML key may lead with one.
     */
    public static function normalize(string $class): string
    {
        return ltrim((string) preg_replace('/\\\\+/', '\\', $class), '\\');
    }

    /** Whether a mapping key names a class by its fully qualified name rather than its short one. */
    public static function isQualified(string $key): bool
    {
        return str_contains($key, '\\');
    }

    /** `App\Entity\PageParts\TextPagePart` => `Text`. A short name passes through unchanged. */
    public static function shortName(string $class): string
    {
        $class = self::normalize($class);
        $short = substr((string) strrchr($class, '\\'), 1) ?: $class;

        return str_ends_with($short, 'PagePart') ? substr($short, 0, -8) : $short;
    }
}
