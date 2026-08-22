<?php

declare(strict_types=1);

namespace Lameco\KumaCompile\Compile;

use Lameco\KumaCompile\Legacy\LegacyDatabase;
use Lameco\KumaCompile\Legacy\MediaIndex;
use Lameco\KumaCompile\Legacy\PageReader;
use Lameco\KumaCompile\Legacy\PartReader;
use Lameco\KumaCompile\Legacy\TaxonomyReader;
use Lameco\KumaCompile\Mapping\Mapping;
use Lameco\KumaCompile\Target\TargetSchema;

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

    private int $structuralEntries = 0;

    /**
     * Shared-entity uid => the title already emitted for it.
     *
     * A deduplicated entity is compiled once per environment against whichever copy of the
     * table that environment holds, so the last run wins. That is correct when the copies
     * agree and silent data loss when they do not, which is why disagreement is counted.
     *
     * @var array<string, string>
     */
    private array $sharedTitles = [];

    /** @var array<string, int> */
    private array $skipped = [];

    /**
     * @param ?list<string> $only compile only these page entities / entity-lane names; null is everything
     */
    public function __construct(
        private readonly Mapping $mapping,
        private readonly Transforms $transforms,
        private readonly ?TargetSchema $schema = null,
        private readonly ?array $only = null,
    ) {
    }

    /**
     * Whether a subject is in this run.
     *
     * A narrowed run is not a lossy one: what it leaves out is not counted as skipped,
     * because nobody asked for it. The report says what the run covered instead.
     */
    private function wanted(string $name): bool
    {
        return $this->only === null || in_array($name, $this->only, true);
    }

    /**
     * @param callable(array<string, mixed>): void $emit
     */
    public function compile(LegacyDatabase $db, string $environment, callable $emit, ?int $limit = null): void
    {
        $pdo = $db->pdo();
        $pages = new PageReader($pdo);
        $parts = new PartReader($pdo);
        $entities = new EntityIndex($this->mapping->entities(), $pages->nodeIdByTranslation());
        $builder = new BlockBuilder(
            $parts,
            $this->transforms,
            $environment,
            $this->schema,
            null,
            MediaIndex::load($pdo),
            $entities,
        );
        $sequencer = new SequenceEngine($this->mapping->sequence(), $this->mapping->parts(), $parts, $builder, $this->schema);

        $locales = ($this->mapping->environments()[$environment] ?? [])['locales'] ?? [];
        $pageSpecs = $this->mapping->pages();

        // Taxonomies first. A page's category relation points at the entry a taxonomy row
        // becomes, and the loader resolves a `_ref` against entries it has already seen —
        // so emitting them after the pages that reference them costs a whole fixup pass.
        $this->compileEntities($pdo, $builder, $environment, $locales, $emit, $limit);
        $contexts = $this->mapping->all()['defaults']['contexts'] ?? ['main' => ['field' => 'commonPageBuilder']];
        $published = $pages->publishedNodeIds();

        // The Craft section each live node's entry lands in — the only thing a structure
        // parent may be checked against, since Craft cannot parent across sections.
        $parentable = [];

        foreach ($published as $nodeId => $entity) {
            $section = $this->sectionOfEntity((string) $entity);

            if ($section !== null) {
                $parentable[$nodeId] = $section;
            }
        }

        // Ancestors that own a path segment but never become an entry of their own.
        // Registered as parentable before anything is emitted, so a child's parentRef can
        // point at one; emitted shallowest-first, so the loader resolves each ref in pass one.
        $ancestry = $pages->ancestry();

        // Ordered by `lft`, and emitted *interleaved with the pages* rather than all up front.
        // A placeholder's own parent is often an ordinary page, and the loader resolves a
        // parentRef against entries it has already written — so emitting every placeholder
        // first strands the ones whose parent comes later, at the structure root. That is not
        // repairable after the fact: Craft assigns a `-2` slug the moment two entries collide
        // at the root and never gives the base slug back. `lft` is the nested-set tree order,
        // so following it guarantees a parent is written before its children.
        $structural = $this->structuralNodes($ancestry, $parentable);
        $pendingStructural = [];

        foreach (array_keys($structural) as $nodeId) {
            $pendingStructural[$nodeId] = $ancestry[$nodeId]['lft'] ?? 0;
        }

        asort($pendingStructural);

        $flushStructural = function (int $beforeLft) use (
            &$pendingStructural, &$parentable, $ancestry, $locales, $environment, $emit
        ): void {
            foreach ($pendingStructural as $nodeId => $lft) {
                if ($lft >= $beforeLft) {
                    break;
                }

                unset($pendingStructural[$nodeId]);

                // Registered only once actually emitted. A placeholder that cannot be built —
                // no configured entry type, no mapped locale — must not become a `parentRef`
                // target, or its children point at an entry no payload ever writes.
                if ($this->emitStructural($nodeId, $ancestry, $parentable, $locales, $environment, $emit)) {
                    $parentable[$nodeId] = 'pages';
                }
            }
        };

        foreach ($pages->nodes() as $node) {
            if ($limit !== null && $this->entries >= $limit) {
                return;
            }

            $flushStructural($ancestry[$node['nodeId']]['lft'] ?? PHP_INT_MAX);

            if (!$this->wanted($node['entity'])) {
                continue;
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
                    $contexts, $parentable, $environment, $spec,
                    (string) $spec['entryType'],
                );
            }

            if ($sites === []) {
                continue;
            }

            // A locale whose translation is offline still owns its slug in that locale's URL:
            // Kunstmaan builds `for-resellers/products-services/...` from an EN slug on a node
            // whose EN translation is switched off. Carrying only the online translations
            // leaves Craft to propagate the primary site's slug into that locale, and every
            // descendant inherits the wrong word. The row is written disabled, so it owns the
            // path segment without publishing anything.
            //
            // Only ever an addition to a page that is published *somewhere* mapped. A node
            // with no published mapped locale at all is not a page here — if it still owns a
            // path segment, the structural lane above emits it, and reviving it as an entry
            // enabled nowhere is what `NO_ENABLED_SITE` is there to catch.
            $sites += $this->offlineSites($node, $ancestry, $parentable, $locales, $environment, $spec, $sites);

            $emit([
                'sourceUid' => $this->uid($environment, $node['nodeId']),
                'section' => (string) ($spec['section'] ?? 'pages'),
                'entryType' => (string) $spec['entryType'],
                'sites' => $sites,
                // What the SEO pass needs to find its rows. `kuma_seo` is keyed on the fully
                // qualified page class and the *per-locale* entity row id — a node's
                // translations each point at their own row, and one shared id would leak the
                // primary locale's meta into every other site. The loader has no way to work
                // either out after the fact, so they travel with the payload.
                'legacy' => $this->legacyRefs($node),
            ]);

            $this->entries++;
        }

        // Anything left is deeper in `lft` than the last page emitted.
        $flushStructural(PHP_INT_MAX);
    }

    /**
     * The legacy identity of a node: its page class, and its entity row id per locale.
     *
     * @param array<string, mixed> $node
     * @return array{class:string, refIds:array<string,int>}
     */
    private function legacyRefs(array $node): array
    {
        $class = '';
        $refIds = [];

        foreach ($node['translations'] as $translation) {
            $class = $class !== '' ? $class : (string) ($translation['entityClass'] ?? '');
            $refIds[(string) $translation['lang']] = (int) $translation['entityId'];
        }

        return ['class' => $class, 'refIds' => $refIds];
    }

    /**
     * The `entities:` lane: one non-node legacy row becomes one Craft entry.
     *
     * These rows are not translated in Kunstmaan — one table, one name, no per-locale copy —
     * so the same title is written to every site the environment reaches, and only those. A
     * deduplicated entity still ends up on every site in the mapping, but by accumulation:
     * each environment's run names its own sites against the one shared uid. Naming them all
     * in one payload is what the loader refuses, and rightly — locale to site is a
     * per-environment fact, so a payload that names `comLvEn` during the COM run is naming a
     * site the run has no map for.
     *
     * @param array<string, ?string> $locales
     * @param callable(array<string, mixed>): void $emit
     */
    private function compileEntities(
        \PDO $pdo,
        BlockBuilder $builder,
        string $environment,
        array $locales,
        callable $emit,
        ?int $limit,
    ): void {
        $specs = $this->mapping->entities();

        if ($specs === []) {
            return;
        }

        $reader = new TaxonomyReader($pdo);
        $sites = $this->siteHandles($locales);

        if ($sites === []) {
            return;
        }

        foreach ($specs as $name => $spec) {
            if (!$this->wanted((string) $name)) {
                continue;
            }

            if (!is_array($spec) || ($spec['table'] ?? '') === '' || ($spec['entryType'] ?? '') === '') {
                continue;
            }

            $table = (string) $spec['table'];
            $dedupe = ($spec['dedupe'] ?? false) === true;
            $titleColumn = (string) ($spec['title'] ?? 'title');

            foreach ($reader->rows($table, isset($spec['softDelete']) ? (string) $spec['softDelete'] : null) as $row) {
                if ($limit !== null && $this->entries >= $limit) {
                    return;
                }

                $title = trim((string) ($row[$titleColumn] ?? ''));

                // Craft's title is required on every one of these entry types, and an entry
                // with no title is a row nobody can find again.
                if ($title === '') {
                    $this->skip(sprintf('%s: row %s has no `%s`', $name, (string) ($row['id'] ?? '?'), $titleColumn));

                    continue;
                }

                $uid = EntityIndex::uid(
                    $dedupe ? EntityIndex::SHARED : $environment,
                    $table,
                    (int) $row['id'],
                );

                if ($dedupe) {
                    $seen = $this->sharedTitles[$uid] ?? null;

                    if ($seen !== null && $seen !== $title) {
                        $this->skip(sprintf('%s: `%s` and `%s` share id %d across environments', $name, $seen, $title, (int) $row['id']));
                    }

                    $this->sharedTitles[$uid] = $title;
                }

                $fields = $builder->fieldsFrom($spec['map'] ?? [], $row, (string) $name, (string) $spec['entryType']);
                $site = ['enabled' => true, 'title' => $title];

                if ($fields !== []) {
                    $site['fieldValues'] = $fields;
                }

                $emit([
                    'sourceUid' => $uid,
                    'section' => (string) $spec['section'],
                    'entryType' => (string) $spec['entryType'],
                    'sites' => array_fill_keys($sites, $site),
                ]);

                $this->entries++;
            }
        }
    }

    /**
     * @param array<string, ?string> $locales
     * @return list<string>
     */
    private function siteHandles(array $locales): array
    {
        $handles = [];

        foreach ($locales as $site) {
            if (is_string($site) && $site !== '') {
                $handles[$site] = true;
            }
        }

        return array_keys($handles);
    }

    /** @return array<string, mixed> */
    private function site(
        array $translation,
        array $node,
        PartReader $parts,
        BlockBuilder $builder,
        SequenceEngine $sequencer,
        array $contexts,
        array $parentable,
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

        if ($parentId !== null) {
            $parentSection = $parentable[$parentId] ?? null;

            if ($parentSection !== null && $parentSection === ($pageSpec['section'] ?? 'pages')) {
                $site['parentRef'] = $this->uid($environment, $parentId);
            } elseif ($parentSection === null) {
                // A hierarchy edge that leads nowhere re-roots this page and shortens its
                // URL. Counted rather than dropped in silence, which is how 1,244 pages
                // came to sit at the root of the first real corpus without anyone noticing.
                $this->skip('parent:' . $environment . ':' . $node['entity']);
            }
        }

        // A page entity's own columns are content: the summary, the category, the overview
        // image, and — for editorial types — the publication date. Reading only the node
        // gives an entry that looks migrated and is missing most of itself.
        $pageRow = isset($pageSpec['table'])
            ? $parts->row((string) $pageSpec['table'], $translation['entityId'])
            : null;

        $pageFields = $pageRow !== null
            ? $builder->fieldsFrom($pageSpec['map'] ?? [], $pageRow, $translation['entity'], $entryType)
            : [];

        // A page entity can own collections too. Partner branches, contact persons and awards
        // are three tables hanging off `partner_pages` that the target models as three
        // Matrixes — the same relationship a pagepart's `children:` describes, one level up.
        if ($pageRow !== null) {
            $pageFields += $builder->childrenOf(
                $pageSpec['children'] ?? [],
                $entryType,
                $translation['entityId'],
                $translation['entity'],
                true,
            );
        }

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
        $prependedBlocks = [];

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

                // `prepend: true` is what puts a hero above the body. It was declared in the
                // mapping and read by nothing, so every `top` part landed *after* the whole
                // main context — 890 live placements arriving at the foot of the page.
                if (($target['prepend'] ?? false) === true) {
                    $prependedBlocks[] = $block;
                } else {
                    $builderBlocks[] = $block;
                }

                $this->blocks++;
            }
        }

        $builderBlocks = array_merge($prependedBlocks, $builderBlocks);

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

        // `promote:` is validated against the target and then not emitted: the lane has no
        // compiler. Counting it here keeps a declared-but-unbuilt collection out of the set of
        // things a clean coverage report implies were migrated.
        foreach (array_keys($spec['promote'] ?? []) as $table) {
            $this->skip(sprintf('promote: %s -> %s (lane not implemented)', $emission['part'], (string) $table));
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

    /**
     * Disabled site rows for the locales this node has a slug in but no published translation.
     *
     * Same idea as a structural placeholder, one level down: the entry exists on that site only
     * to own its slug, so the URLs beneath it come out in the right language. Sites the node is
     * genuinely published in are left untouched.
     *
     * @param array<string, mixed> $node
     * @param array<int, array<string, mixed>> $ancestry
     * @param array<int, string> $parentable
     * @param array<string, mixed> $locales
     * @param array<string, mixed> $pageSpec
     * @param array<string, mixed> $already the sites the online translations already produced
     * @return array<string, array<string, mixed>>
     */
    private function offlineSites(
        array $node,
        array $ancestry,
        array $parentable,
        array $locales,
        string $environment,
        array $pageSpec,
        array $already,
    ): array {
        $row = $ancestry[$node['nodeId']] ?? null;

        if ($row === null) {
            return [];
        }

        $out = [];

        foreach ($row['slugs'] as $lang => $slug) {
            if (($row['offline'][$lang] ?? false) !== true) {
                continue;
            }

            $site = $locales[$lang] ?? null;

            // Two legacy locales can map to one Craft site (`pt` and `br` both reach comBrPt).
            // A published translation always outranks an unpublished one for that site.
            if (!is_string($site) || isset($already[$site]) || isset($out[$site])) {
                continue;
            }

            $out[$site] = [
                'enabled' => false,
                'title' => $row['titles'][$lang] ?? $slug,
                'slug' => $slug,
            ];

            $parentId = $node['parentId'];

            if ($parentId !== null
                && ($parentable[$parentId] ?? null) === ($pageSpec['section'] ?? 'pages')
            ) {
                $out[$site]['parentRef'] = $this->uid($environment, $parentId);
            }
        }

        return $out;
    }

    /**
     * Ancestors of the `pages` tree that no entry covers, shallowest first.
     *
     * A Kunstmaan URL is the slug chain of a node's ancestors, and an ancestor earns its
     * segment whether or not it is published and whether or not it becomes an entry. Three
     * kinds routinely do not: a node with no online translation, a page type parked in
     * `unmapped:`, and — the common one — a `RedirectPage`, which is how Kunstmaan gives a
     * section its landing URL. Dropping any of them re-roots the whole subtree beneath it.
     *
     * The walk stops at a node that already parents into `pages`, and at one whose entry
     * lands in another section: the Kunstmaan home node is the whole tree's parent, and a
     * Craft structure cannot parent across sections.
     *
     * @param array<int, array{parentId:?int, entity:string, slugs:array<string,string>,
     *                         titles:array<string,string>}> $ancestry
     * @param array<int, string> $parentable node id => the Craft section its entry lands in
     * @return array<int, int> node id => depth, ordered shallowest first
     */
    private function structuralNodes(array $ancestry, array $parentable): array
    {
        $needed = [];

        foreach ($parentable as $nodeId => $section) {
            if ($section !== 'pages') {
                continue;
            }

            $parentId = $ancestry[$nodeId]['parentId'] ?? null;
            $guard = 0;

            while ($parentId !== null && $guard++ < 64) {
                // Already covered — either by an entry, or by an ancestor found earlier.
                if (isset($parentable[$parentId]) || isset($needed[$parentId])) {
                    break;
                }

                $row = $ancestry[$parentId] ?? null;

                if ($row === null) {
                    break;
                }

                // A live node whose entry lands elsewhere ends the chain rather than
                // becoming a placeholder for a section it does not belong to.
                $ancestorSection = $this->sectionOfEntity($row['entity']);

                if ($ancestorSection !== null && $ancestorSection !== 'pages') {
                    break;
                }

                $needed[$parentId] = true;
                $parentId = $row['parentId'];
            }
        }

        $depths = [];

        foreach (array_keys($needed) as $nodeId) {
            $depth = 0;
            $walk = $ancestry[$nodeId]['parentId'] ?? null;
            $guard = 0;

            while ($walk !== null && $guard++ < 64) {
                $depth++;
                $walk = $ancestry[$walk]['parentId'] ?? null;
            }

            $depths[$nodeId] = $depth;
        }

        asort($depths);

        return $depths;
    }

    /**
     * Emit one ancestor as a structural placeholder.
     *
     * The entry exists to own a path segment and nothing else: disabled on every site, so
     * Craft still computes its URI and still hands the segment to its descendants, while the
     * URL itself 404s and falls through to Retour — which is exactly what a `RedirectPage`
     * should do. It carries no field values, and it is not counted as a migrated page.
     *
     * A site is listed only where the node has a slug *in that locale*. Kunstmaan omits the
     * segment for a locale the ancestor was never translated into, and borrowing another
     * locale's slug to fill the gap invents a path the old site never served.
     *
     * @param array<int, array{parentId:?int, entity:string, slugs:array<string,string>,
     *                         titles:array<string,string>}> $ancestry
     * @param array<int, string> $parentable
     * @param array<string, mixed> $locales
     * @param callable(array<string, mixed>): void $emit
     * @return bool whether the placeholder was emitted, and may therefore be parented onto
     */
    private function emitStructural(
        int $nodeId,
        array $ancestry,
        array $parentable,
        array $locales,
        string $environment,
        callable $emit,
    ): bool {
        $entryType = $this->mapping->all()['defaults']['structuralEntryType'] ?? null;

        if (!is_string($entryType) || $entryType === '') {
            // Without a target entry type there is nowhere to put the segment. Say so per
            // ancestor rather than re-rooting its subtree without a word.
            $this->skip('structural:no-entry-type:' . $environment);

            return false;
        }

        $row = $ancestry[$nodeId];
        $sites = [];

        foreach ($row['slugs'] as $lang => $slug) {
            $site = $locales[$lang] ?? null;

            if (!is_string($site) || isset($sites[$site])) {
                continue;
            }

            $sites[$site] = [
                'enabled' => false,
                'title' => $row['titles'][$lang] ?? $slug,
                'slug' => $slug,
            ];

            $parentId = $row['parentId'];

            if ($parentId !== null && ($parentable[$parentId] ?? null) === 'pages') {
                $sites[$site]['parentRef'] = $this->uid($environment, $parentId);
            }
        }

        if ($sites === []) {
            $this->skip('structural:no-mapped-locale:' . $environment);

            return false;
        }

        $emit([
            'sourceUid' => $this->uid($environment, $nodeId),
            'section' => 'pages',
            'entryType' => $entryType,
            // Tells the loader this entry is a path segment, not content: it is legal for
            // it to be enabled nowhere, which no other payload is.
            'structural' => true,
            'sites' => $sites,
        ]);

        $this->structuralEntries++;

        return true;
    }

    /** The Craft section an entry of this page entity lands in, per the mapping. */
    private function sectionOfEntity(string $entity): ?string
    {
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

        return $slot->nested === [] || in_array($blockType, $slot->nested, true);
    }

    private function skip(string $reason): void
    {
        $this->skipped[$reason] = ($this->skipped[$reason] ?? 0) + 1;
    }

    /** Path-segment placeholders emitted; deliberately not part of `entryCount()`. */
    public function structuralCount(): int
    {
        return $this->structuralEntries;
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
