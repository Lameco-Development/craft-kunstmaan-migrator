<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\run;

/**
 * What a run lost once the mapping's `acceptedLosses:` is taken off — the counts
 * `--fail-on-loss` gates on, and the accepted, unaccepted and stale split the run
 * report shows.
 */
final class LossAssessment
{
    /**
     * @param int $unresolvable references to a target never migrated — never acceptable
     * @param array{lossyConversions: int, unresolvedAssets: int, unresolvedReferences: int} $accepted
     * @param array<string, array<string, int>> $unacceptedLosses     transform => "from -> to" => count
     * @param list<string>                      $unacceptedAssets     distinct paths
     * @param list<string>                      $unacceptedReferences
     * @param array<string, mixed>              $stale                accepted entries the run no longer loses, keyed as `acceptedLosses:`
     */
    public function __construct(
        public readonly int $lossyConversions,
        public readonly int $unresolvedAssets,
        public readonly int $unresolvedReferences,
        public readonly int $unresolvable,
        public readonly array $accepted,
        public readonly array $unacceptedLosses,
        public readonly array $unacceptedAssets,
        public readonly array $unacceptedReferences,
        public readonly array $stale,
    ) {
    }

    /** Whether anything the mapping does not accept was lost. */
    public function lost(): bool
    {
        return RunOutcome::lost($this->lossyConversions, $this->unresolvedAssets, $this->unresolvedReferences + $this->unresolvable);
    }

    /** @return array<string, mixed> the run report's `acceptedLosses` block */
    public function report(): array
    {
        return [
            'accepted' => $this->accepted,
            'unaccepted' => [
                'lossyConversions' => $this->lossyConversions,
                'unresolvedAssets' => $this->unresolvedAssets,
                'unresolvedReferences' => $this->unresolvedReferences,
                'unresolvable' => $this->unresolvable,
                'losses' => $this->unacceptedLosses,
                'unresolvedAssetPaths' => $this->unacceptedAssets,
                'unresolvedReferenceKeys' => $this->unacceptedReferences,
            ],
            'stale' => $this->stale,
        ];
    }
}
