<?php

declare(strict_types=1);

namespace Lameco\KumaCompile\Compile;

use Lameco\KumaCompile\Legacy\LegacyDatabase;
use Lameco\KumaCompile\Legacy\PageReader;
use Lameco\KumaCompile\Legacy\PartReader;
use Lameco\KumaCompile\Mapping\Mapping;

/**
 * The `redirects:` lane: a legacy RedirectPage becomes a Retour redirect.
 *
 * The loader has read a redirects payload since before there was a compiler, and nothing
 * ever wrote one — `load/redirects` was a mouth with no upstream. This is the upstream.
 *
 * A Kunstmaan redirect page is a node like any other: it has a URL per published
 * translation, which is the path visitors still hit, and one destination column shared by
 * every translation. So one legacy row produces one redirect per site, not one in total.
 */
final class RedirectCompiler
{
    /** Kunstmaan writes an internal link as `[NT<node translation id>]`. */
    private const INTERNAL_LINK = '/^\[NT(\d+)\]$/';

    private int $count = 0;

    /** @var array<string, int> */
    private array $skipped = [];

    /** @param ?list<string> $only compile only these page entities; null is everything */
    public function __construct(
        private readonly Mapping $mapping,
        private readonly ?array $only = null,
    ) {
    }

    /**
     * @param callable(array{from:string, to:string, siteHandle:string, type:int}): void $emit
     */
    public function compile(LegacyDatabase $db, string $environment, callable $emit): void
    {
        $specs = $this->mapping->redirects();

        if ($specs === []) {
            return;
        }

        $pdo = $db->pdo();
        $pages = new PageReader($pdo);
        $parts = new PartReader($pdo);
        $nodeOfTranslation = $pages->nodeIdByTranslation();
        $locales = ($this->mapping->environments()[$environment] ?? [])['locales'] ?? [];

        foreach ($pages->nodes() as $node) {
            if ($this->only !== null && !in_array($node['entity'], $this->only, true)) {
                continue;
            }

            $spec = $specs[$node['entity']] ?? null;

            if (!is_array($spec) || ($spec['table'] ?? '') === '') {
                continue;
            }

            $map = $spec['map'] ?? [];
            $destinationColumn = (string) ($map['destination'] ?? 'url');
            $typeColumn = (string) ($map['type'] ?? 'type');
            $defaultType = (int) ($spec['defaultType'] ?? 301);

            foreach ($node['translations'] as $translation) {
                $site = $locales[$translation['lang']] ?? null;

                if (!is_string($site) || $site === '') {
                    $this->skip(sprintf('locale:%s:%s', $environment, $translation['lang']));

                    continue;
                }

                $from = trim((string) ($translation['url'] ?? ''));

                if ($from === '') {
                    $this->skip(sprintf('%s: translation has no url', $node['entity']));

                    continue;
                }

                $row = $parts->row((string) $spec['table'], $translation['entityId']);
                $to = $this->destination($row[$destinationColumn] ?? null, $nodeOfTranslation, $environment);

                if ($to === null) {
                    $this->skip(sprintf('%s: no destination on /%s', $node['entity'], $from));

                    continue;
                }

                $emit([
                    'from' => '/' . ltrim($from, '/'),
                    'to' => $to,
                    'siteHandle' => $site,
                    'type' => (int) ($row[$typeColumn] ?? null) ?: $defaultType,
                ]);

                $this->count++;
            }
        }
    }

    /**
     * A destination is either a literal URL or an internal link to a node.
     *
     * The internal form resolves to the node's `sourceUid` rather than to a legacy path,
     * because the loader turns that into the entry's *current* Craft URI — which is the
     * whole point of migrating a redirect rather than copying it.
     *
     * @param array<int, int> $nodeOfTranslation
     */
    private function destination(mixed $raw, array $nodeOfTranslation, string $environment): ?string
    {
        $value = trim((string) ($raw ?? ''));

        if ($value === '') {
            return null;
        }

        if (preg_match(self::INTERNAL_LINK, $value, $m) !== 1) {
            return $value;
        }

        $nodeId = $nodeOfTranslation[(int) $m[1]] ?? null;

        if ($nodeId === null) {
            $this->skip(sprintf('destination [NT%d] points at no node', (int) $m[1]));

            return null;
        }

        return EntityIndex::uid($environment, EntityIndex::NODES, $nodeId);
    }

    private function skip(string $reason): void
    {
        $this->skipped[$reason] = ($this->skipped[$reason] ?? 0) + 1;
    }

    public function count(): int
    {
        return $this->count;
    }

    /** @return array<string, int> */
    public function skipped(): array
    {
        arsort($this->skipped);

        return $this->skipped;
    }
}
