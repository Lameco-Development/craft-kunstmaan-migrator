<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\compile;

use lameco\kunstmaanmigrator\legacy\LegacyDatabase;
use lameco\kunstmaanmigrator\legacy\MediaIndex;
use lameco\kunstmaanmigrator\legacy\PageReader;
use lameco\kunstmaanmigrator\legacy\PartReader;
use lameco\kunstmaanmigrator\mapping\Mapping;

/**
 * Compiles one legacy environment into Kunstmaan Migrator payloads.
 *
 * One Kunstmaan node becomes one Craft entry; its published translations become that entry's
 * sites. `sourceUid` is the node's identity, which is what makes a re-run an update rather
 * than a second copy.
 */
final class Compiler
{
    private int $entries = 0;

    private int $blocks = 0;

    /** @var array<string, int> */
    private array $skipped = [];

    public function __construct(
        private readonly Mapping $mapping,
        private readonly Transforms $transforms,
        private readonly ?TargetModel $schema = null,
    ) {
    }

    /**
     * @param callable(array<string, mixed>): void $emit
     */
    public function compile(LegacyDatabase $db, string $environment, callable $emit, ?int $limit = null): void
    {
        $pdo = $db->pdo();
        $pages = new PageReader($pdo);
        $parts = new PartReader($pdo);
        $builder = new BlockBuilder(
            $parts,
            $this->transforms,
            $environment,
            $this->schema,
            null,
            MediaIndex::load($pdo),
        );
        $sequencer = new SequenceEngine($this->mapping->sequence(), $this->mapping->parts(), $parts, $builder, $this->schema);

        $locales = ($this->mapping->environments()[$environment] ?? [])['locales'] ?? [];
        $pageSpecs = $this->mapping->pages();
        $contexts = $this->mapping->all()['defaults']['contexts'] ?? ['main' => ['field' => 'commonPageBuilder']];
        $published = $pages->publishedNodeIds();

        foreach ($pages->nodes() as $node) {
            if ($limit !== null && $this->entries >= $limit) {
                return;
            }

            $spec = $pageSpecs[$node['entity']] ?? null;

            if ($spec === null || isset($spec['manual']) || !isset($spec['entryType'])) {
                $this->skip($node['entity']);

                continue;
            }

            $sites = [];

            foreach ($node['translations'] as $translation) {
                $site = $locales[$translation['lang']] ?? null;

                if ($site === null) {
                    $this->skip('locale:' . $environment . ':' . $translation['lang']);

                    continue;
                }

                $sites[$site] = $this->site(
                    $translation, $node, $parts, $builder, $sequencer,
                    $contexts, $published, $environment, $spec,
                    (string) $spec['entryType'],
                );
            }

            if ($sites === []) {
                continue;
            }

            $emit([
                'sourceUid' => $this->uid($environment, $node['nodeId']),
                'section' => (string) ($spec['section'] ?? 'pages'),
                'entryType' => (string) $spec['entryType'],
                'sites' => $sites,
            ]);

            $this->entries++;
        }
    }

    /** @return array<string, mixed> */
    private function site(
        array $translation,
        array $node,
        PartReader $parts,
        BlockBuilder $builder,
        SequenceEngine $sequencer,
        array $contexts,
        array $published,
        string $environment,
        array $pageSpec,
        string $entryType,
    ): array {
        $site = [
            'enabled' => true,
            'title' => $translation['title'],
            'slug' => $translation['slug'],
        ];

        // Kunstmaan hangs every page off the home node, but in Craft the home page is its
        // own single section and a structure cannot parent across sections. Those pages are
        // roots of the `pages` structure instead.
        $parentId = $node['parentId'];

        if ($parentId !== null && isset($published[$parentId])) {
            $parentSection = $this->sectionOfNode($parentId, $published);

            if ($parentSection !== null && $parentSection === ($pageSpec['section'] ?? 'pages')) {
                $site['parentRef'] = $this->uid($environment, $parentId);
            }
        }

        // A page entity's own columns are content: the summary, the category, the overview
        // image, and — for editorial types — the publication date. Reading only the node
        // gives an entry that looks migrated and is missing most of itself.
        $pageRow = isset($pageSpec['table'])
            ? $parts->row((string) $pageSpec['table'], $translation['entityId'])
            : null;

        $pageFields = $pageRow !== null
            ? $builder->fieldsFrom($pageSpec['map'] ?? [], $pageRow, $translation['entity'])
            : [];

        // The node's `created` is when the page was made, not when it was published. Editorial
        // types carry their own date, and on the first real corpus the two disagreed on 279 of
        // 434 blog posts — several by months.
        $postDate = $translation['created'];

        if ($pageRow !== null && isset($pageSpec['postDate'])) {
            $postDate = $pageRow[(string) $pageSpec['postDate']] ?? $postDate;
        }

        if ($postDate !== null) {
            $site['postDate'] = date(DATE_ATOM, (int) strtotime((string) $postDate));
        }

        $builderBlocks = [];

        foreach ($contexts as $context => $target) {
            $sequence = $parts->sequence($translation['entity'], $translation['entityId'], (string) $context);

            $field = (string) ($target['field'] ?? 'pageBuilder');

            // Some entry types have no Page Builder at all — casePage and partnerPage carry
            // their own structured fields instead. Emitting one anyway makes Craft reject the
            // whole entry, so the parts are dropped here and counted.
            if ($sequence !== [] && $this->schema !== null && $this->schema->slot($entryType, $field) === null) {
                $this->skip(sprintf('%s has no %s — %d parts dropped', $entryType, $field, count($sequence)));

                continue;
            }

            foreach ($sequencer->apply($sequence) as $emission) {
                $block = $this->blockFor($emission, $builder, $builder->environment());

                if ($block === null) {
                    continue;
                }

                // A Page Builder does not accept every block: editorial entry types carry a
                // deliberately narrower subset. Emitting a block the field disallows produces
                // a payload Craft rejects wholesale, taking the rest of the page with it.
                if (!$this->allows($entryType, $field, $block['type'])) {
                    $this->skip(sprintf('%s not allowed on %s.%s', $block['type'], $entryType, $field));

                    continue;
                }

                $builderBlocks[] = $block;
                $this->blocks++;
            }
        }

        if ($builderBlocks !== []) {
            $field = (string) (reset($contexts)['field'] ?? 'pageBuilder');
            $pageFields[$field] = $builderBlocks;
        }

        if ($pageFields !== []) {
            $site['fieldValues'] = $pageFields;
        }

        return $site;
    }

    /** @return array{type:string, fields:array<string,mixed>}|null */
    private function blockFor(array $emission, BlockBuilder $builder, string $environment): ?array
    {
        // A heading with nowhere to be absorbed becomes a block of its own.
        if (isset($emission['emit'])) {
            $emitted = $emission['emit'];

            if (($emitted['block'] ?? '') === '') {
                return null;
            }

            $fields = [];

            foreach ($emitted['fields'] as $path => $value) {
                $fields[$path] = $value;
            }

            $nested = $this->nest($fields, (string) $emitted['block']);

            // A block synthesised by a sequence rule still needs a stable identity, or a
            // re-run appends a second copy of it. The head part it came from is that identity.
            $headSpec = $this->mapping->parts()[$emission['part']] ?? [];

            if (isset($headSpec['table'])) {
                $nested['_sourcePartRef'] = sprintf(
                    '%s:%s:%d',
                    $environment,
                    $headSpec['table'],
                    $emission['id'],
                );
            }

            return ['type' => (string) $emitted['block'], 'fields' => $nested];
        }

        $spec = $this->mapping->parts()[$emission['part']] ?? null;

        if ($spec === null || isset($spec['drop'], $spec['manual']) || !isset($spec['block'])) {
            if ($spec === null || !isset($spec['block'])) {
                $this->skip($emission['part']);
            }

            return null;
        }

        $block = $builder->build($emission['part'], $emission['id'], $spec);

        if ($block === null) {
            return null;
        }

        // An absorbed heading lands on the block that followed it, at the path that block
        // declared — which may be inside a nested Matrix.
        if ($emission['absorb'] ?? []) {
            $block['fields'] = array_replace_recursive(
                $this->nest($emission['absorb'], $block['type']),
                $block['fields'],
            );
        }

        return $block;
    }

    /** Turn `a[0].b` paths into real nesting, naming the nested entry type from the schema. */
    private function nest(array $flat, string $block): array
    {
        $out = [];

        foreach ($flat as $path => $value) {
            if (preg_match('/^(\w+)\[(\d+)\]\.(\w+)$/', (string) $path, $m) === 1) {
                $out[$m[1]][(int) $m[2]]['type'] ??= $this->childType($block, $m[1]);
                $out[$m[1]][(int) $m[2]]['fields'][$m[3]] = $value;

                continue;
            }

            $out[$path] = $value;
        }

        return $out;
    }

    private function childType(string $block, string $field): string
    {
        return $this->schema?->nestedTypeOf($block, $field) ?? $field;
    }

    private function uid(string $environment, int $nodeId): string
    {
        return sprintf('kuma:%s:kuma_nodes:%d', $environment, $nodeId);
    }

    /** The Craft section a published node's entry lands in, per the mapping. */
    private function sectionOfNode(int $nodeId, array $published): ?string
    {
        $entity = $published[$nodeId] ?? null;

        if (!is_string($entity)) {
            return null;
        }

        $spec = $this->mapping->pages()[$entity] ?? null;

        return is_array($spec) ? (string) ($spec['section'] ?? 'pages') : null;
    }

    /** Whether a Matrix field on this entry type accepts blocks of the given type. */
    private function allows(string $entryType, string $field, string $blockType): bool
    {
        if ($this->schema === null) {
            return true;
        }

        $slot = $this->schema->slot($entryType, $field);

        if ($slot === null) {
            return false;
        }

        return $slot['nested'] === [] || in_array($blockType, $slot['nested'], true);
    }

    private function skip(string $reason): void
    {
        $this->skipped[$reason] = ($this->skipped[$reason] ?? 0) + 1;
    }

    public function entryCount(): int
    {
        return $this->entries;
    }

    public function blockCount(): int
    {
        return $this->blocks;
    }

    /** @return array<string, int> */
    public function skipped(): array
    {
        arsort($this->skipped);

        return $this->skipped;
    }
}
