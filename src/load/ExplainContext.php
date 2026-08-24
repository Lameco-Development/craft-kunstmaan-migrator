<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\load;

/**
 * The things an explanation needs that do not change from node to node.
 *
 * `EntryExplanation::reconcile()` takes seven arguments, five of which are the same for every
 * node in an environment — the lanes, the part tables, the streamed contexts, the migrated
 * locales. Threading those through a sweep of two thousand nodes by hand is how the per-node
 * path and the sweep path drift into answering slightly different questions.
 */
final readonly class ExplainContext
{
    /**
     * @param array<string, string> $lanes    pagepart class => the lane the mapping puts it in
     * @param array<string, string> $tables   pagepart class => the legacy table the mapping names
     * @param list<string>          $contexts Kunstmaan contexts the mapping streams into blocks
     * @param list<string>          $locales  legacy langs that have a Craft site to land in
     */
    public function __construct(
        public string $environment,
        public array $lanes,
        public array $tables,
        public array $contexts,
        public array $locales,
    ) {
    }

    /**
     * @param array<string, array<string, string>> $blockIds
     * @param list<array{lang: string, context: string, part: string, entity: string, id: int, sequence: int}> $legacyParts
     * @return array{written: int, accountedFor: list<array<string, mixed>>, unexplained: list<array<string, mixed>>}
     */
    public function reconcile(array $blockIds, array $legacyParts): array
    {
        return EntryExplanation::reconcile(
            $this->environment,
            $blockIds,
            $legacyParts,
            $this->lanes,
            $this->tables,
            $this->contexts,
            $this->locales,
        );
    }
}
