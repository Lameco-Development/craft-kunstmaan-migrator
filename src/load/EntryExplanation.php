<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\load;

/**
 * One migrated entry, reconciled against the legacy node it came from.
 *
 * "Something is empty and I don't know why" was answerable — the run report, the state table and
 * the `--dump` payloads between them hold it — by correlating three sources by hand, per entry,
 * after the fact. This does that correlation.
 *
 * The two sides are asymmetric on purpose. What was *written* is recorded: the state row's
 * `meta.blockIds` maps each part's source ref to the block element it became, per site. What was
 * *there to write* is not recorded anywhere, so it is re-read from the legacy database rather
 * than trusted to a log. The difference between the two is the answer, and the mapping supplies
 * the reason: a class in the `dropped`, `manual`, `unmapped`, `forms` or `globals` lane is
 * missing by decision, and a class in the `blocks` lane that produced no block is a defect.
 *
 * Pure, so the reconciliation is testable without a database, a state table or a Craft entry.
 */
final readonly class EntryExplanation
{
    /**
     * @param array<string, array<string, string>> $blockIds  site handle => source ref => block element id
     * @param list<array{lang: string, context: string, part: string, entity: string, id: int, sequence: int}> $legacyParts
     * @param array<string, string>                $lanes     pagepart class => the lane the mapping puts it in
     * @param array<string, string>                $tables    pagepart class => the legacy table the mapping names
     * @param list<string>                         $contexts  Kunstmaan contexts the mapping streams into blocks
     * @param list<string>                         $locales   legacy langs that have a Craft site to land in
     * @return array{written: int, accountedFor: list<array<string, mixed>>, unexplained: list<array<string, mixed>>}
     */
    public static function reconcile(
        string $environment,
        array $blockIds,
        array $legacyParts,
        array $lanes,
        array $tables,
        array $contexts = [],
        array $locales = [],
    ): array {
        $written = [];

        foreach ($blockIds as $refs) {
            foreach (array_keys($refs) as $ref) {
                // `COM:table:17543#buttons[0]` is a nested row of the block written for
                // `COM:table:17543`, not a second part. Counting it as one would report more
                // parts migrated than the page ever had.
                $written[explode('#', (string) $ref)[0]] = true;
            }
        }

        $accountedFor = [];
        $unexplained = [];

        // Grouped before it is judged. One placement can be live in several locales — it is one
        // part and one block — and *which* locales decides whether its absence is a defect at
        // all, so the langs have to be collected before the first verdict rather than after.
        $grouped = [];

        foreach ($legacyParts as $part) {
            $key = $part['part'] . ':' . $part['id'];
            $grouped[$key] ??= $part + ['langs' => []];
            $grouped[$key]['langs'][(string) $part['lang']] = true;
        }

        foreach ($grouped as $part) {
            $class = (string) $part['part'];
            $table = $tables[$class] ?? null;
            $langs = array_keys($part['langs']);

            // The legacy row names the Doctrine entity; the state ref names the table the
            // mapping declared for it. The mapping is the only thing that knows both.
            $ref = $table !== null ? sprintf('%s:%s:%d', $environment, $table, $part['id']) : null;

            if ($ref !== null && isset($written[$ref])) {
                continue;
            }

            $lane = $lanes[$class] ?? null;
            $row = [
                'part' => $class,
                'id' => $part['id'],
                'context' => $part['context'],
                'langs' => $langs,
                'lane' => $lane,
                'ref' => $ref,
            ];

            // A placement that is live only in a locale with no Craft site has nowhere to land,
            // and the mapping said so with a reason. On this corpus that is COM:sp — 335 live
            // pages, 208 of them Spanish-only — so counting it as loss would put a declared
            // client decision at the top of the defect list.
            if ($locales !== [] && array_intersect($langs, $locales) === []) {
                $row['why'] = sprintf(
                    'live only in %s, which the mapping declares as having no Craft site',
                    implode(', ', $langs),
                );
                $accountedFor[] = $row;

                continue;
            }

            // A part the mapping deliberately does not turn into a block is not a hole, and
            // listing it as one buries the ones that are.
            if ($lane !== null && $lane !== 'blocks') {
                $accountedFor[] = $row;

                continue;
            }

            // A part in a context the mapping does not stream is missing for a stated reason,
            // not for an unknown one — and on a corpus where `form` and eight `footer-*`
            // contexts are deliberately left out, that is most of what would otherwise land in
            // the defect list.
            if ($contexts !== [] && !in_array((string) $part['context'], $contexts, true)) {
                $row['why'] = sprintf('`%s` is not one of the contexts the mapping streams into blocks', $part['context']);
                $accountedFor[] = $row;

                continue;
            }

            $row['why'] = match (true) {
                $lane === null => 'no lane in the mapping names this class — `coverage` would fail on it',
                $table === null => 'the blocks lane claims this class but declares no `table:`, so nothing could be read',
                default => 'the blocks lane claims this class, and no block was written for this placement',
            };
            $unexplained[] = $row;
        }

        return [
            'written' => count($written),
            'accountedFor' => $accountedFor,
            'unexplained' => $unexplained,
        ];
    }
}
