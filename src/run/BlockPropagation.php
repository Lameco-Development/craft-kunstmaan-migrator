<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\run;

/**
 * Whether the target's page-builder Matrix fields can hold what a multi-locale corpus is
 * about to put in them.
 *
 * `propagationMethod: all` means Craft keeps **one** block set for the owner, shared across
 * every site. While each locale's payload names the same legacy parts that collapses
 * harmlessly — 753 entries on the reference corpus do. When the locales name different parts
 * it cannot: each site's save replaces the other's, every run, and the Latvian page ends up
 * serving the English blocks. 160 entries corpus-wide, found by measuring a staging run.
 *
 * The loader cannot repair it. The block set is global by the field's own configuration, so
 * representing divergent locales needs `propagationMethod: none` or a per-site
 * `propagationKeyFormat` on the Craft side. What it can do is say so before the run rather
 * than per-entry during it — this is a content-model precondition, and the run is two hours
 * long.
 *
 * Pure, so the rule is testable without a Craft application or a Matrix field.
 */
final class BlockPropagation
{
    /**
     * @param array<string, ?string> $methods    Matrix field handle => propagation method, or
     *                                           null when the target has no such field
     * @param array<string, int>     $localesPer legacy environment => locales the mapping maps
     * @return list<string>
     */
    public static function problems(array $methods, array $localesPer): array
    {
        $multi = array_keys(array_filter($localesPer, static fn (int $n): bool => $n > 1));

        // One locale per environment means one site writing each block set, so a shared set is
        // exactly right and warning about it would be noise on every single-language corpus.
        if ($multi === []) {
            return [];
        }

        $shared = array_keys(array_filter($methods, static fn (?string $m): bool => $m === 'all'));

        if ($shared === []) {
            return [];
        }

        // One finding, not one per placement. The explanation is the same sentence every time
        // and repeating it five times is how a check's output stops being read.
        return [sprintf(
            '%s share one block set across every site (`propagationMethod: all`), and %s map more'
            . ' than one locale. Where two locales hold different parts, each site\'s save replaces'
            . ' the other\'s blocks — every run — and one locale ends up serving the other\'s'
            . ' content. The loader cannot repair this: the set is global by the field\'s own'
            . ' configuration. Set `propagationMethod: none`, or a per-site'
            . ' `propagationKeyFormat`, on each.',
            implode(', ', $shared),
            implode(', ', $multi),
        )];
    }
}
