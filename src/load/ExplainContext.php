<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\load;

use Lameco\Kunstmaanmigrator\Mapping\Mapping;
use Lameco\Kunstmaanmigrator\Mapping\PageRow;

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
     * @param list<string>          $pageContexts Kunstmaan contexts declared `target: page`
     * @param array<string, array{blocks: list<string>, page: list<string>}> $contextsByPage
     *        short page entity => its own split, where a page's `contexts:` replaces the defaults;
     *        a page not named here is judged by `$contexts` / `$pageContexts`
     * @param ?\Closure(string): string $partKey a placement's entity => the row key claiming it
     *        (`Mapping::partKey()`); null keys every placement by its short name
     */
    public function __construct(
        public string $environment,
        public array $lanes,
        public array $tables,
        public array $contexts,
        public array $locales,
        public array $pageContexts = [],
        public array $contextsByPage = [],
        public ?\Closure $partKey = null,
    ) {
    }

    /** Everything an explanation reads from the mapping, for one environment it declares. */
    public static function fromMapping(string $environment, Mapping $mapping): self
    {
        $tables = [];

        foreach ($mapping->partRows() as $class => $part) {
            if ($part->table() !== null) {
                $tables[(string) $class] = (string) $part->table();
            }
        }

        $locales = [];

        foreach ((array) (($mapping->environments()[$environment] ?? [])['locales'] ?? []) as $lang => $handle) {
            if (is_string($handle) && $handle !== '') {
                $locales[] = (string) $lang;
            }
        }

        $defaults = ['blocks' => [], 'page' => []];

        foreach ($mapping->defaultContexts() as $context => $target) {
            $defaults[PageRow::isPageTarget($target) ? 'page' : 'blocks'][] = (string) $context;
        }

        // A page's own `contexts:` replaces the defaults wholesale — a `target: page` context it
        // declares is filled, and a default one it leaves out is not — so each page is resolved.
        $byPage = [];

        foreach ($mapping->pageRows() as $entity => $page) {
            $byPage[(string) $entity] = [
                'blocks' => array_map(strval(...), array_keys($page->contexts())),
                'page' => $page->pageContexts(),
            ];
        }

        return new self(
            $environment,
            $mapping->accountedParts(),
            $tables,
            $defaults['blocks'],
            $locales,
            $defaults['page'],
            $byPage,
            $mapping->partKey(...),
        );
    }

    /**
     * @param array<string, array<string, string>> $blockIds
     * @param list<array{lang: string, context: string, part: string, entity: string, id: int, sequence: int}> $legacyParts
     * @param ?string $page short page entity the node is, so its own contexts are used; null for the defaults
     * @param array<string, array<string, array{part: string, id: int}|null>>|null $pageFills
     *        what compile writes from each page context (`Compiler::pageContextFills()`)
     * @return array{written: int, accountedFor: list<array<string, mixed>>, unexplained: list<array<string, mixed>>}
     */
    public function reconcile(array $blockIds, array $legacyParts, ?string $page = null, ?array $pageFills = null): array
    {
        $own = $page !== null ? ($this->contextsByPage[$page] ?? null) : null;

        return EntryExplanation::reconcile(
            $this->environment,
            $blockIds,
            $this->partKey === null ? $legacyParts : array_map(
                // Keyed as compile keys them, so the lane, the table and a page fill all match.
                fn(array $part): array => ['part' => ($this->partKey)($part['entity'])] + $part,
                $legacyParts,
            ),
            $this->lanes,
            $this->tables,
            $own['blocks'] ?? $this->contexts,
            $this->locales,
            $own['page'] ?? $this->pageContexts,
            $pageFills,
        );
    }
}
