<?php

declare(strict_types=1);

namespace Lameco\KumaCompile\Report;

use Lameco\KumaCompile\Legacy\LiveSnapshot;
use Lameco\KumaCompile\Mapping\Mapping;

/**
 * Measures a mapping against the live legacy content it claims to describe.
 *
 * "Complete" is not a judgment call: every live pagepart class and page entity must be
 * claimed by some lane — including the `unmapped` lane, which is how you declare a
 * deliberate non-goal. Anything unclaimed is a hole, and a hole fails the build.
 */
final class Coverage
{
    /** @var array<string, int> */
    private array $partPlacements = [];

    /** @var array<string, int> */
    private array $pageTypes = [];

    /** @var array<string, array<string, int>> */
    private array $localesByEnvironment = [];

    private int $allPartRefs = 0;

    public function __construct(private readonly Mapping $mapping)
    {
    }

    public function ingest(LiveSnapshot $snapshot): void
    {
        foreach ($snapshot->partPlacements as $class => $n) {
            $this->partPlacements[$class] = ($this->partPlacements[$class] ?? 0) + $n;
        }

        foreach ($snapshot->pageTypes as $entity => $n) {
            $this->pageTypes[$entity] = ($this->pageTypes[$entity] ?? 0) + $n;
        }

        $this->localesByEnvironment[$snapshot->environment] = $snapshot->pagesByLocale;
        $this->allPartRefs += $snapshot->allPartRefs;
    }

    /** @return array<string, int> pagepart class => live placements, unclaimed by any lane */
    public function unclaimedParts(): array
    {
        $accounted = $this->mapping->accountedParts();

        return array_diff_key($this->partPlacements, $accounted);
    }

    /** @return array<string, int> page entity => live pages, unclaimed by any lane */
    public function unclaimedPageTypes(): array
    {
        $accounted = $this->mapping->accountedPageTypes();

        return array_diff_key($this->pageTypes, $accounted);
    }

    /** Classes the mapping describes that no longer occur in live content. */
    public function staleParts(): array
    {
        return array_diff(array_keys($this->mapping->accountedParts()), array_keys($this->partPlacements));
    }

    /**
     * Live placements grouped by the lane that claims them.
     *
     * @return array<string, int> lane => placements
     */
    public function placementsByLane(): array
    {
        $accounted = $this->mapping->accountedParts();
        $lanes = [];

        foreach ($this->partPlacements as $class => $n) {
            $lane = $accounted[$class] ?? 'UNCLAIMED';
            $lanes[$lane] = ($lanes[$lane] ?? 0) + $n;
        }

        arsort($lanes);

        return $lanes;
    }

    /**
     * Legacy locales with no Craft site, and the live pages each strands.
     *
     * @return array<string, int> "ENV:lang" => live pages
     */
    public function strandedLocales(): array
    {
        $stranded = [];

        foreach ($this->mapping->environments() as $env => $spec) {
            foreach ($this->localesByEnvironment[$env] ?? [] as $lang => $pages) {
                $site = ($spec['locales'] ?? [])[$lang] ?? null;

                if ($site === null || $site === '') {
                    $stranded[sprintf('%s:%s', $env, $lang)] = $pages;
                }
            }
        }

        arsort($stranded);

        return $stranded;
    }

    public function totalPlacements(): int
    {
        return array_sum($this->partPlacements);
    }

    public function totalPages(): int
    {
        return array_sum($this->pageTypes);
    }

    /** Share of raw pagepart rows that belong to a published page. */
    public function liveShare(): float
    {
        return $this->allPartRefs > 0 ? $this->totalPlacements() / $this->allPartRefs : 0.0;
    }

    /**
     * Deliberate omissions, with the reason each was declared under and what it costs.
     *
     * `unmapped:`, `drop:` and `manual:` already carry a written reason each — that is what
     * makes them declarations rather than silence — and the placement counts are already
     * measured. Putting the two together is the client-facing half of coverage: not "the
     * mapping has no holes" but "this is what will not be on the new site, and why".
     *
     * @return list<array{subject: string, kind: string, reason: string, placements: int}>
     */
    public function declaredOmissions(): array
    {
        $out = [];

        foreach ($this->mapping->unmappedParts() as $class => $reason) {
            $out[] = [
                'subject' => (string) $class,
                'kind' => 'pagepart, not migrated',
                'reason' => (string) $reason,
                'placements' => $this->partPlacements[$class] ?? 0,
            ];
        }

        foreach ($this->mapping->unmappedPageTypes() as $entity => $reason) {
            $out[] = [
                'subject' => (string) $entity,
                'kind' => 'page type, not migrated',
                'reason' => (string) $reason,
                'placements' => $this->pageTypes[$entity] ?? 0,
            ];
        }

        foreach ($this->mapping->parts() as $class => $spec) {
            if (!is_array($spec)) {
                continue;
            }

            foreach ([['drop', 'pagepart, dropped'], ['manual', 'pagepart, rebuilt by hand']] as [$key, $kind]) {
                if (isset($spec[$key])) {
                    $out[] = [
                        'subject' => (string) $class,
                        'kind' => $kind,
                        'reason' => is_string($spec[$key]) ? $spec[$key] : 'no reason given',
                        'placements' => $this->partPlacements[$class] ?? 0,
                    ];
                }
            }
        }

        usort($out, static fn(array $a, array $b): int => $b['placements'] <=> $a['placements']);

        return $out;
    }

    public function hasHoles(): bool
    {
        return $this->unclaimedParts() !== [] || $this->unclaimedPageTypes() !== [];
    }
}
