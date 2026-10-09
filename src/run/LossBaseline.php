<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\run;

/**
 * The losses a mapping accepts (`acceptedLosses:`), subtracted from what a run lost.
 *
 * Every real corpus loses something on purpose — a soft-deleted media file, a document
 * nobody has a copy of. Without a way to say so, `--fail-on-loss` could never pass on
 * such a corpus, so nobody passed it and a new loss went as unnoticed as before. Each
 * list is keyed exactly as the run report keys the loss, so a reviewed loss is copied
 * from the report into the mapping:
 *
 * - `lossyConversions`: transform => list of `<from> -> <to>` keys, as `losses` lists them;
 * - `unresolvedAssets`: asset paths, as `unresolvedAssetSample` lists them;
 * - `unresolvedReferences`: `<sourceUid>: <field> -> <ref>`, one per fixup orphan.
 *
 * An accepted key accepts every occurrence of it. A reference to a target that was never
 * migrated (`fixup.unresolvable`) and per-site block content the target cannot hold are
 * not acceptable here: the first is a mapping gap, the second a field configuration one.
 */
final class LossBaseline
{
    /**
     * @param array<string, list<string>> $conversions
     * @param list<string>                $assets
     * @param list<string>                $references
     * @param list<string>                $environments
     */
    private function __construct(
        private readonly array $conversions,
        private readonly array $assets,
        private readonly array $references,
        private readonly array $environments,
    ) {
    }

    /**
     * @param mixed        $spec         the mapping's `acceptedLosses:`, already through `Schema`
     * @param list<string> $environments the legacy environments the run walked. The fixup pass
     *   reads the whole state table, which other mappings' runs share; only references from
     *   these environments are this run's. Empty: every reference counts.
     */
    public static function fromSpec(mixed $spec, array $environments = []): self
    {
        $spec = is_array($spec) ? $spec : [];
        $conversions = [];

        foreach ((array) ($spec['lossyConversions'] ?? []) as $transform => $keys) {
            $conversions[(string) $transform] = self::strings($keys);
        }

        return new self(
            $conversions,
            self::strings($spec['unresolvedAssets'] ?? []),
            self::strings($spec['unresolvedReferences'] ?? []),
            array_values(array_map('strval', $environments)),
        );
    }

    /**
     * @param array<string, array<string, int>> $losses           as the run report's `losses`
     * @param list<string>                      $unresolvedAssets one asset reference per unresolved occurrence
     * @param list<array<string, mixed>>        $orphans          as the fixup pass reports them
     * @param array<string, int>                $unresolvable     sourceUid => refs to a target never migrated
     */
    public function assess(array $losses, array $unresolvedAssets, array $orphans, array $unresolvable = []): LossAssessment
    {
        $orphans = array_values(array_filter($orphans, fn(array $orphan): bool => $this->ours((string) ($orphan['sourceUid'] ?? ''))));
        $unresolvable = array_filter($unresolvable, fn(string $sourceUid): bool => $this->ours($sourceUid), ARRAY_FILTER_USE_KEY);

        $acceptedConversions = 0;
        $unacceptedLosses = [];
        $stale = [];

        foreach ($losses as $transform => $pairs) {
            foreach ($pairs as $pair => $count) {
                if (in_array((string) $pair, $this->conversions[$transform] ?? [], true)) {
                    $acceptedConversions += $count;
                } else {
                    $unacceptedLosses[$transform][$pair] = $count;
                }
            }
        }

        foreach ($this->conversions as $transform => $keys) {
            $gone = array_values(array_filter($keys, static fn(string $key): bool => !isset($losses[$transform][$key])));

            if ($gone !== []) {
                $stale['lossyConversions'][$transform] = $gone;
            }
        }

        [$acceptedAssets, $unacceptedAssets, $staleAssets] = self::split($unresolvedAssets, $this->assets);
        $references = array_map(
            static fn(array $orphan): string => sprintf(
                '%s: %s -> %s',
                (string) ($orphan['sourceUid'] ?? '?'),
                (string) ($orphan['field'] ?? '?'),
                (string) ($orphan['ref'] ?? '?'),
            ),
            $orphans,
        );
        [$acceptedRefs, $unacceptedRefs, $staleRefs] = self::split($references, $this->references);

        if ($staleAssets !== []) {
            $stale['unresolvedAssets'] = $staleAssets;
        }

        if ($staleRefs !== []) {
            $stale['unresolvedReferences'] = $staleRefs;
        }

        return new LossAssessment(
            lossyConversions: array_sum(array_map('array_sum', $unacceptedLosses)),
            unresolvedAssets: count($unacceptedAssets),
            unresolvedReferences: count($unacceptedRefs),
            unresolvable: array_sum($unresolvable),
            accepted: [
                'lossyConversions' => $acceptedConversions,
                'unresolvedAssets' => $acceptedAssets,
                'unresolvedReferences' => $acceptedRefs,
            ],
            unacceptedLosses: $unacceptedLosses,
            unacceptedAssets: array_values(array_unique($unacceptedAssets)),
            unacceptedReferences: $unacceptedRefs,
            stale: $stale,
        );
    }

    /** Whether a reference's source belongs to an environment this run walked. */
    private function ours(string $sourceUid): bool
    {
        if ($this->environments === []) {
            return true;
        }

        foreach ($this->environments as $env) {
            if (str_starts_with($sourceUid, 'kuma:' . $env . ':')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $occurrences
     * @param list<string> $accepted
     * @return array{int, list<string>, list<string>} accepted count, unaccepted occurrences, stale entries
     */
    private static function split(array $occurrences, array $accepted): array
    {
        $unaccepted = array_values(array_filter($occurrences, static fn(string $o): bool => !in_array($o, $accepted, true)));
        $stale = array_values(array_diff($accepted, $occurrences));

        return [count($occurrences) - count($unaccepted), $unaccepted, $stale];
    }

    /** @return list<string> */
    private static function strings(mixed $list): array
    {
        return is_array($list) ? array_values(array_map('strval', array_filter($list, 'is_string'))) : [];
    }
}
