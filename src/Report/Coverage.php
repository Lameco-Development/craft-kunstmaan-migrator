<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\Report;

use Lameco\Kunstmaanmigrator\Mapping\Mapping;
use Lameco\Kunstmaanmigrator\Mapping\PartRow;
use Lameco\Kunstmaanmigrator\Source\EntityTableIndex;
use Lameco\Kunstmaanmigrator\Source\LegacyDatabase;
use Lameco\Kunstmaanmigrator\Source\LiveSnapshot;
use Lameco\Kunstmaanmigrator\Source\PartClass;

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

    /** @var array<string, int> fully qualified pagepart class => live placements, across every snapshot */
    private array $partClasses = [];

    /** @var array<string, int> */
    private array $pageTypes = [];

    /** @var array<string, array<string, int>> */
    private array $localesByEnvironment = [];

    private int $allPartRefs = 0;

    /** @var array<string, true>|null classes a short-name row reads one table for, among others */
    private ?array $ambiguous = null;

    /** @var array<string, array<string, array<string, array{stacks: int, placements: int}>>> */
    private array $pageContextStacks = [];

    /**
     * @param ?EntityTableIndex $tables the legacy entities' tables, where known: classes that
     *        share a short name and read one table are no collision
     */
    public function __construct(
        private readonly Mapping $mapping,
        private readonly ?EntityTableIndex $tables = null,
    ) {
    }

    /**
     * The measurement every surface takes: one snapshot per connected
     * environment, folded into one picture of the corpus.
     *
     * @param iterable<LegacyDatabase> $connections
     */
    public static function measure(Mapping $mapping, iterable $connections, ?EntityTableIndex $tables = null): self
    {
        $coverage = new self($mapping, $tables);

        foreach ($connections as $db) {
            $coverage->ingest($db->snapshot());
        }

        return $coverage;
    }

    public function ingest(LiveSnapshot $snapshot): void
    {
        $this->ambiguous = null;

        foreach ($this->byClaimingKey($snapshot) as $class => $n) {
            $this->partPlacements[$class] = ($this->partPlacements[$class] ?? 0) + $n;
        }

        $this->partClasses = PartClass::tally($this->partClasses, $snapshot->partClasses);

        foreach ($snapshot->pageTypes as $entity => $n) {
            $this->pageTypes[$entity] = ($this->pageTypes[$entity] ?? 0) + $n;
        }

        $this->localesByEnvironment[$snapshot->environment] = $snapshot->pagesByLocale;
        $this->allPartRefs += $snapshot->allPartRefs;

        // Only the locales that land somewhere: a page on a locale with no Craft site is not
        // compiled at all, and `strandedLocales()` already counts it whole.
        $sites = (array) (($this->mapping->environments()[$snapshot->environment] ?? [])['locales'] ?? []);

        foreach ($snapshot->pageContextStacks as $page => $contexts) {
            foreach ($contexts as $context => $langs) {
                foreach ($langs as $lang => $mixes) {
                    $site = $sites[$lang] ?? null;

                    if (!is_string($site) || $site === '') {
                        continue;
                    }

                    foreach ($mixes as $mix => $totals) {
                        $known = $this->pageContextStacks[$page][$context][$mix] ?? ['stacks' => 0, 'placements' => 0];
                        $this->pageContextStacks[$page][$context][$mix] = [
                            'stacks' => $known['stacks'] + $totals['stacks'],
                            'placements' => $known['placements'] + $totals['placements'],
                        ];
                    }
                }
            }
        }
    }

    /**
     * The snapshot's placements, with a class the mapping keys by its qualified name counted
     * under that name even where nothing collides and the corpus reports it by its short one —
     * compile reads it through the qualified row, so coverage must too.
     *
     * @return array<string, int>
     */
    private function byClaimingKey(LiveSnapshot $snapshot): array
    {
        $placements = $snapshot->partPlacements;

        foreach ($snapshot->partClasses as $class => $n) {
            $short = PartClass::shortName((string) $class);
            $key = $this->mapping->partKey((string) $class);

            if ($key === $short || !isset($placements[$short])) {
                continue;
            }

            $placements[$short] -= $n;
            $placements[$key] = ($placements[$key] ?? 0) + $n;

            if ($placements[$short] <= 0) {
                unset($placements[$short]);
            }
        }

        return $placements;
    }

    /**
     * Parts lost to a single-valued page context: what compile drops from a `target: page`
     * context, which fills the page's own fields once. A stack holding a `consumedBy: page` part
     * keeps one placement and loses the rest; a stack holding none — a body text alone in the
     * header — loses all of it, being neither a hero nor a block. Losses, not holes: the mapping
     * decided the context holds one part. A number rather than a silent drop.
     *
     * A lower bound, as far as the legacy refs show it: a page part that fails its `requires:`,
     * maps nothing the entry type carries or has no row is assumed here to fill the page, so a
     * stack whose every page part fails loses one placement more than this counts. Judging that
     * needs the part's own row and the target schema — what `state/explain` asks compile for,
     * per node, and what the run report counts. Pages on a locale with no Craft site are left
     * out (`strandedLocales()` has them). `placementsByLane()` is per class, so a body text alone
     * in a page context is filed there under `blocks` and counted here as lost.
     *
     * @return list<array{page: string, context: string, placements: int}>
     */
    public function pageContextLosses(): array
    {
        $out = [];

        foreach ($this->pageContextStacks as $page => $contexts) {
            $row = $this->mapping->pageRow((string) $page);

            if ($row === null || !$row->compiles()) {
                continue;
            }

            foreach ($row->pageContexts() as $context) {
                $lost = 0;

                foreach ($contexts[$context] ?? [] as $mix => $totals) {
                    $lost += $totals['placements'] - ($this->fillsAPage((string) $mix) ? $totals['stacks'] : 0);
                }

                if ($lost > 0) {
                    $out[] = ['page' => (string) $page, 'context' => $context, 'placements' => $lost];
                }
            }
        }

        usort($out, static fn(array $a, array $b): int => $b['placements'] <=> $a['placements']);

        return $out;
    }

    /** Whether a mix of part classes holds one that can fill a page: a `consumedBy: page` part. */
    private function fillsAPage(string $mix): bool
    {
        foreach (explode(',', $mix) as $class) {
            $key = $this->claim($class);

            if ($key !== null && $this->mapping->partRow($key)?->disposition() === PartRow::PAGE) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, int> pagepart class => live placements, unclaimed by any lane */
    public function unclaimedParts(): array
    {
        return array_filter(
            $this->partPlacements,
            fn(int $n, string $class): bool => $this->claim($class) === null,
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * Short-name rows that read one table for more than one live class — each class's
     * placements would compile from the first one's rows. Their classes are holes until each
     * has a row of its own (`Mapping::unresolvedPartCollisions()`).
     *
     * @return list<array{key: string, table: ?string, classes: array<string, int>}>
     */
    public function unresolvedCollisions(): array
    {
        // Summed across databases: one database may hold only the app's class and another only
        // Kunstmaan's, and the one short-name row still reads one table for both.
        return $this->mapping->unresolvedPartCollisions($this->partClasses + $this->partPlacements, $this->tables);
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
        $reached = [];

        foreach (array_keys($this->partPlacements) as $class) {
            $key = $this->mapping->claimingKey((string) $class);

            if ($key !== null) {
                $reached[$key] = true;
            }
        }

        return array_values(array_filter(
            array_map('strval', array_keys($this->mapping->accountedParts())),
            static fn(string $key): bool => !isset($reached[$key]),
        ));
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
            $key = $this->claim((string) $class);
            $lane = $key !== null ? $accounted[$key] : 'UNCLAIMED';
            $lanes[$lane] = ($lanes[$lane] ?? 0) + $n;
        }

        arsort($lanes);

        return $lanes;
    }

    /**
     * The key that claims a live class, or null when none does — including when the claim is a
     * short-name row reading one table for several live classes, which compiles all but one of
     * them from the wrong rows.
     */
    private function claim(string $class): ?string
    {
        if ($this->ambiguous === null) {
            $this->ambiguous = [];

            foreach ($this->unresolvedCollisions() as $collision) {
                foreach (array_keys($collision['classes']) as $member) {
                    $this->ambiguous[(string) $member] = true;
                }

                // A database where one class of the name was live alone reports it by the short
                // name; those placements fall through to the same ambiguous row.
                $this->ambiguous[$collision['key']] = true;
            }
        }

        return isset($this->ambiguous[PartClass::normalize($class)]) ? null : $this->mapping->claimingKey($class);
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
                'placements' => $this->placementsClaimedBy((string) $class),
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

        $kinds = [PartRow::DROPPED => 'pagepart, dropped', PartRow::MANUAL => 'pagepart, rebuilt by hand'];

        foreach ($this->mapping->partRows() as $class => $row) {
            $kind = $kinds[$row->disposition()] ?? null;

            if ($kind !== null) {
                $out[] = [
                    'subject' => $class,
                    'kind' => $kind,
                    'reason' => $row->reason() ?? 'no reason given',
                    'placements' => $this->placementsClaimedBy($class),
                ];
            }
        }

        usort($out, static fn(array $a, array $b): int => $b['placements'] <=> $a['placements']);

        return $out;
    }

    /** Live placements whose class the given key claims — its own, and any class that falls back to it. */
    private function placementsClaimedBy(string $key): int
    {
        $n = 0;

        foreach ($this->partPlacements as $class => $placements) {
            if ($this->mapping->claimingKey((string) $class) === $key) {
                $n += $placements;
            }
        }

        return $n;
    }

    public function hasHoles(): bool
    {
        return $this->unclaimedParts() !== [] || $this->unclaimedPageTypes() !== [];
    }
}
