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
        $short = self::basename($class);

        return str_ends_with($short, 'PagePart') ? substr($short, 0, -8) : $short;
    }

    /**
     * Any Doctrine entity name without its namespace — `App\Entity\Pages\HomePage` => `HomePage`.
     * A page entity is keyed by this; a pagepart by `shortName()`, which also drops the suffix.
     */
    public static function basename(string $class): string
    {
        $class = self::normalize($class);

        return substr((string) strrchr($class, '\\'), 1) ?: $class;
    }

    /**
     * Live counts as a report lists them: by short name, except where more than one live class
     * shares it, then each by its qualified name with its own count. Presentation only — which
     * row a class compiles from is `Mapping::partKey()`'s call.
     *
     * @param array<string, int> $classes fully qualified class => live placements
     * @return array<string, int> report name => live placements, largest first
     */
    public static function reported(array $classes): array
    {
        $names = self::reportNames(array_keys($classes));
        $out = [];

        foreach ($classes as $class => $n) {
            $name = $names[(string) $class];
            $out[$name] = ($out[$name] ?? 0) + $n;
        }

        arsort($out);

        return $out;
    }

    /**
     * The name a report gives each class (`reported()`).
     *
     * @param list<string|int> $classes every live class, fully qualified
     * @return array<string, string> class, as given => its report name
     */
    public static function reportNames(array $classes): array
    {
        $byShort = [];

        foreach ($classes as $class) {
            $byShort[self::shortName((string) $class)][self::normalize((string) $class)] = true;
        }

        $names = [];

        foreach ($classes as $class) {
            $short = self::shortName((string) $class);
            $names[(string) $class] = count($byShort[$short]) > 1 ? self::normalize((string) $class) : $short;
        }

        return $names;
    }
    /**
     * Live placements by fully qualified class, summed over databases. A collision is a corpus
     * fact, not a database's: the app's class live in one environment and Kunstmaan's in another
     * still meet in the one short-name row that reads them both.
     *
     * @param array<string, int> ...$counts fully qualified class => live placements, per database
     * @return array<string, int>
     */
    public static function tally(array ...$counts): array
    {
        $out = [];

        foreach ($counts as $classes) {
            foreach ($classes as $class => $n) {
                $class = self::normalize((string) $class);
                $out[$class] = ($out[$class] ?? 0) + $n;
            }
        }

        arsort($out);

        return $out;
    }
}
