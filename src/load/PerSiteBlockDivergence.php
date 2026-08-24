<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\load;

/**
 * Whether a set of per-locale block payloads can coexist in one shared block set.
 *
 * A Matrix field with `propagationMethod: all` keeps one block set for the owner across every
 * site. Locales naming the same legacy parts collapse into that set harmlessly — the common
 * case, 753 entries on the reference corpus. Locales naming *different* parts cannot: each
 * site's save replaces the other's, permanently, and the losing locale ends up showing the
 * winner's content.
 *
 * Pure, and separate from the service, because the cost of getting it wrong is a warning on
 * every multi-site entry in the corpus — which is the same as no warning at all.
 */
final class PerSiteBlockDivergence
{
    /**
     * @param array<string, array<string, bool>> $perSiteRefs site handle → set of sourceRefs
     */
    public static function isUnrepresentable(array $perSiteRefs): bool
    {
        if (count($perSiteRefs) < 2) {
            return false;
        }

        $sets = [];

        foreach ($perSiteRefs as $refs) {
            $keys = array_keys($refs);
            sort($keys);
            $sets[] = $keys;
        }

        $first = array_shift($sets);

        foreach ($sets as $set) {
            if ($set !== $first) {
                return true;
            }
        }

        return false;
    }
}
