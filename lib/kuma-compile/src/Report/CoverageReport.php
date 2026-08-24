<?php

declare(strict_types=1);

namespace Lameco\KumaCompile\Report;

/**
 * The three readings of one coverage measurement: the machine-readable view,
 * the hole list an operator acts on, and the document a client reads.
 *
 * `kuma-compile coverage`, `./craft kunstmaan-migrator/mapping/coverage` and
 * the migrate preflight all render from here, so the wording of a hole and
 * the shape of the JSON cannot drift between them.
 */
final class CoverageReport
{
    /**
     * Lane names in the client's terms rather than the mapping's.
     *
     * `globals` and `sequence` mean something precise to whoever wrote the mapping and nothing
     * at all to the person deciding whether the migration is acceptable.
     *
     * @var array<string, string>
     */
    public const LANE_NAMES = [
        'blocks' => 'page content',
        'sequence' => 'page content (merged into the block above it)',
        'forms' => 'forms',
        'globals' => 'site-wide (footer, navigation)',
        'manual' => 'rebuilt by hand after migration',
        'dropped' => 'not migrated',
        'unmapped' => 'not migrated (declared)',
        'UNCLAIMED' => 'not yet decided',
    ];

    public function __construct(private readonly Coverage $coverage)
    {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'placements' => $this->coverage->totalPlacements(),
            'pages' => $this->coverage->totalPages(),
            'liveShare' => round($this->coverage->liveShare(), 4),
            'byLane' => $this->coverage->placementsByLane(),
            'unclaimedParts' => $this->coverage->unclaimedParts(),
            'unclaimedPageTypes' => $this->coverage->unclaimedPageTypes(),
            'staleParts' => $this->coverage->staleParts(),
            'strandedLocales' => $this->coverage->strandedLocales(),
            'omissions' => $this->coverage->declaredOmissions(),
            'holes' => $this->coverage->hasHoles(),
        ];
    }

    /**
     * One line per unclaimed class — what an operator has to decide on.
     *
     * @return list<string>
     */
    public function holes(): array
    {
        $holes = [];

        foreach ($this->coverage->unclaimedParts() as $class => $n) {
            $holes[] = sprintf('pagepart  %-32s %s live placements', (string) $class, number_format($n));
        }

        foreach ($this->coverage->unclaimedPageTypes() as $entity => $n) {
            $holes[] = sprintf('page      %-32s %s live pages', (string) $entity, number_format($n));
        }

        return $holes;
    }

    /**
     * The version to send a client.
     *
     * A migration's deliverable is not only what arrived; it is also an accounting of what did
     * not, and why. Both halves are already in hand — the placement counts measured against the
     * live databases, and the written reason every `unmapped:`, `drop:` and `manual:` carries —
     * and putting them in one document is what turns "the data is in Craft" into something
     * reviewable by somebody who does not read YAML.
     *
     * @param string $measuredBy the command that took the measurement, named in the preamble
     * @param string $date       the measurement date as it should appear (`Y-m-d`)
     */
    public function markdown(string $measuredBy, string $date): string
    {
        $coverage = $this->coverage;
        $lines = ['# What moves, and what does not', ''];
        $lines[] = sprintf(
            'Measured against the live legacy databases on %s by `%s`. Every number'
            . ' below counts *published* content: Kunstmaan keeps a copy of a page\'s whole content graph'
            . ' per saved version, and only %.1f%% of those rows belong to a page that is live.',
            $date,
            $measuredBy,
            $coverage->liveShare() * 100,
        );
        $lines[] = '';
        $lines[] = sprintf(
            '**%s content blocks across %s pages.**',
            number_format($coverage->totalPlacements()),
            number_format($coverage->totalPages()),
        );
        $lines[] = '';
        $lines[] = '## Where it lands';
        $lines[] = '';
        $lines[] = '| destination | blocks | share |';
        $lines[] = '|---|---:|---:|';

        $total = max(1, $coverage->totalPlacements());

        foreach ($coverage->placementsByLane() as $lane => $n) {
            $lines[] = sprintf('| %s | %s | %.1f%% |', self::LANE_NAMES[$lane] ?? $lane, number_format($n), $n / $total * 100);
        }

        $omissions = $coverage->declaredOmissions();

        if ($omissions !== []) {
            $lines[] = '';
            $lines[] = '## What is deliberately not migrated';
            $lines[] = '';
            $lines[] = 'Each of these is a decision, recorded in the mapping with its reason and reviewable in';
            $lines[] = 'the same place the migration itself is defined. Nothing here is an oversight.';
            $lines[] = '';
            $lines[] = '| | | blocks | why |';
            $lines[] = '|---|---|---:|---|';

            foreach ($omissions as $omission) {
                $lines[] = sprintf(
                    '| `%s` | %s | %s | %s |',
                    $omission['subject'],
                    $omission['kind'],
                    number_format($omission['placements']),
                    str_replace('|', '\\|', $omission['reason']),
                );
            }
        }

        if ($stranded = $coverage->strandedLocales()) {
            $lines[] = '';
            $lines[] = '## Languages with nowhere to go';
            $lines[] = '';
            $lines[] = 'These languages exist on the legacy site and have no site on the new one, so their pages';
            $lines[] = 'disappear at cutover. Creating a site for one of them is what changes that.';
            $lines[] = '';
            $lines[] = '| language | live pages |';
            $lines[] = '|---|---:|';

            foreach ($stranded as $locale => $pages) {
                $lines[] = sprintf('| %s | %s |', $locale, number_format($pages));
            }
        }

        if (!$coverage->hasHoles()) {
            $lines[] = '';
            $lines[] = '## Nothing is unaccounted for';
            $lines[] = '';
            $lines[] = 'Every kind of content on the legacy site is either migrated or listed above as a decision.';

            return implode("\n", $lines) . "\n";
        }

        $lines[] = '';
        $lines[] = '## Unaccounted for — not yet decided';
        $lines[] = '';
        $lines[] = 'Live content that is neither migrated nor declared. These need a decision before cutover.';
        $lines[] = '';
        $lines[] = '| | live |';
        $lines[] = '|---|---:|';

        foreach ($coverage->unclaimedParts() as $class => $n) {
            $lines[] = sprintf('| `%s` (content block) | %s |', $class, number_format($n));
        }

        foreach ($coverage->unclaimedPageTypes() as $entity => $n) {
            $lines[] = sprintf('| `%s` (page type) | %s |', $entity, number_format($n));
        }

        return implode("\n", $lines) . "\n";
    }
}
