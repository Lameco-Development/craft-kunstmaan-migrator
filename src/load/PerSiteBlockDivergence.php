<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\load;

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
     * What identifies each block of every Matrix field in one site's payload.
     *
     * A block carrying `_sourcePartRef` is identified by it: two locales holding the same
     * legacy part with translated copy are the common case, and calling that divergence
     * would fire on almost every multi-site entry in the corpus.
     *
     * A block carrying no ref is identified by its content instead. Only page-builder blocks
     * get a ref — a Matrix built by `link()`, `links()` or the sidecar lane has none, which on
     * the reference corpus is 0 of 102 `heroButtons` blocks against 3,298 of 3,316 page-builder
     * blocks. Keying purely on refs made those fields invisible to the check, so `heroButtons`
     * on a `propagationMethod: all` field lost every locale but the last one written, silently:
     * an English page serving a Danish button. With no ref to compare, the content is the only
     * evidence of whether two locales are carrying the same thing.
     *
     * Nested Matrixes ride along inside their parent block's fingerprint rather than being
     * reported in their own right; the owner block already differs when a child does.
     *
     * @param array<string, mixed> $fieldValues one site's `fieldValues`
     * @return array<string, array<string, bool>> Matrix field handle → set of block identities
     */
    public static function identities(array $fieldValues): array
    {
        $out = [];

        foreach ($fieldValues as $handle => $value) {
            if (!is_array($value) || $value === [] || !self::looksLikeMatrix($value)) {
                continue;
            }

            $identities = [];

            foreach (array_values($value) as $block) {
                $fields = is_array($block) ? (array) ($block['fields'] ?? []) : [];
                $ref = $fields['_sourcePartRef'] ?? null;

                $identities[$ref !== null
                    ? 'ref:' . (string) $ref
                    : 'content:' . self::fingerprint(is_array($block) ? $block : [])] = true;
            }

            $out[(string) $handle] = $identities;
        }

        return $out;
    }

    /**
     * A stable hash of one block's content.
     *
     * Keys are sorted the whole way down, because key order is an artefact of how the payload
     * was assembled: two identical buttons serialised in different orders are the same button,
     * and hashing them apart would put the noise straight back.
     *
     * @param array<mixed> $block
     */
    private static function fingerprint(array $block): string
    {
        $canonical = self::sortDeep($block);

        return md5((string) json_encode($canonical));
    }

    /**
     * @param array<mixed> $value
     * @return array<mixed>
     */
    private static function sortDeep(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sortDeep($item);
            }
        }

        ksort($value);

        return $value;
    }

    /** @param array<mixed> $payload */
    private static function looksLikeMatrix(array $payload): bool
    {
        $first = reset($payload);

        return is_array($first) && isset($first['type']);
    }

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
