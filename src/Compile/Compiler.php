<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\Compile;

use Lameco\Kunstmaanmigrator\Mapping\EntityRow;
use Lameco\Kunstmaanmigrator\Mapping\Mapping;
use Lameco\Kunstmaanmigrator\Mapping\PageRow;
use Lameco\Kunstmaanmigrator\Payload\SourceUid;
use Lameco\Kunstmaanmigrator\Source\LegacyDatabase;
use Lameco\Kunstmaanmigrator\Source\MediaIndex;
use Lameco\Kunstmaanmigrator\Source\PageReader;
use Lameco\Kunstmaanmigrator\Source\PartReader;
use Lameco\Kunstmaanmigrator\Source\TaxonomyReader;
use Lameco\Kunstmaanmigrator\Source\TranslationIndex;
use Lameco\Kunstmaanmigrator\Target\TargetSchema;

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
        $run = $this->begin($db, $environment);

        // Taxonomies first. A page's category relation points at the entry a taxonomy row
        // becomes, and the loader resolves a `_ref` against entries it has already seen —
        // so emitting them after the pages that reference them costs a whole fixup pass.
        $this->compileEntities($run->pdo, $run->builder, $environment, $run->locales, $emit, $limit);

        foreach ($run->nodesById as $nodeId => $node) {
            if ($limit !== null && $this->entries >= $limit) {
                return;
            }

            $this->compileNodeUnit($run, $nodeId, $emit);
        }

        // Anything left is deeper in `lft` than the last page emitted.
        $this->finishStructural($run, $emit);
    }

    /**
     * Everything the environment walk holds in scope, opened once and carried
     * between units — a console run keeps it for the whole loop, a batched
     * queue job rebuilds it per batch and catches up (see `catchUpStructural`).
     */
    public function begin(LegacyDatabase $db, string $environment): CompilerRun
    {
        $pdo = $db->pdo();
        $pages = new PageReader($pdo, $this->mapping->offlineCutoff());
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
            TranslationIndex::load($pdo),
        );
        $sequencer = new SequenceEngine($this->mapping->sequence(), $this->mapping->parts(), $parts, $builder, $this->schema);

        $locales = ($this->mapping->environments()[$environment] ?? [])['locales'] ?? [];

        // The Craft section each live node's entry lands in — the only thing a structure
        // parent may be checked against, since Craft cannot parent across sections.
        $parentable = [];

        foreach ($pages->publishedNodeIds() as $nodeId => $entity) {
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
        $pendingStructural = [];

        foreach (array_keys($this->structuralNodes($ancestry, $parentable)) as $nodeId) {
            $pendingStructural[$nodeId] = $ancestry[$nodeId]['lft'] ?? 0;
        }

        asort($pendingStructural);

        $nodesById = [];

        foreach ($pages->nodes() as $node) {
            $nodesById[(int) $node['nodeId']] = $node;
        }

        return new CompilerRun(
            $environment,
            $pdo,
            $pages,
            $parts,
            $builder,
            $sequencer,
            $locales,
            $this->mapping->pageRows(),
            $parentable,
            $ancestry,
            $nodesById,
            $pendingStructural,
        );
    }

    /**
     * One node, compiled: the structural placeholders due before it, then the
     * page itself. Emits zero payloads for a node the mapping does not carry.
     */
    public function compileNodeUnit(CompilerRun $run, int $nodeId, callable $emit): void
    {
        $node = $run->nodesById[$nodeId] ?? null;

        if ($node === null) {
            return;
        }

        $this->flushStructural($run, $run->lftOf($nodeId), $emit);

        if (!$this->wanted($node['entity'])) {
            return;
        }

        $page = $run->pageRows[$node['entity']] ?? null;

        if ($page === null || !$page->compiles()) {
            $this->skip($node['entity']);

            return;
        }

        $sites = [];

        foreach ($node['translations'] as $translation) {
            $site = $run->locales[$translation['lang']] ?? null;

            if ($site === null) {
                $this->skip('locale:' . $run->environment . ':' . $translation['lang']);

                continue;
            }

            // Two legacy locales can map to one Craft site (`pt` and `br` both reach comBrPt).
            // A published translation always outranks a rescued offline one for that site,
            // whichever order they arrive in.
            if (isset($sites[$site]) && $sites[$site]['enabled'] && !$translation['online']) {
                continue;
            }

            $sites[$site] = $this->site(
                $translation, $node, $run->parts, $run->builder, $run->sequencer,
                $run->parentable, $run->environment, $page,
            );
        }

        // A page published in no mapped locale is not a page. `offlineCutoff` adds locales to
        // a page that is live somewhere; it does not revive one that is live nowhere — that
        // would emit an entry enabled on no site, which the loader refuses wholesale as
        // NO_ENABLED_SITE. If the node still owns a path segment, the structural lane above
        // emits it as a placeholder, which is the payload allowed to be enabled nowhere.
        if (array_filter(array_column($sites, 'enabled')) === []) {
            return;
        }

        $emit([
            'sourceUid' => $this->uid($run->environment, $node['nodeId']),
            'section' => $page->section(),
            'entryType' => (string) $page->entryType(),
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

    /**
     * Register the structural placeholders an earlier batch already emitted.
     *
     * A resumed batch rebuilds this run from scratch, so every placeholder is
     * pending again — but the ones before the resume point were emitted by a
     * previous batch, and re-emitting them would double-count. They are flushed
     * against a no-op emit: `parentable` learns them exactly as the original
     * flush taught it, and nothing is emitted twice.
     */
    public function catchUpStructural(CompilerRun $run, int $lastCompiledNodeId): void
    {
        $this->flushStructural($run, $run->lftOf($lastCompiledNodeId), static function(): void {
        });
    }

    /** The placeholders deeper in `lft` than the last page emitted. */
    public function finishStructural(CompilerRun $run, callable $emit): void
    {
        $this->flushStructural($run, PHP_INT_MAX, $emit);
    }

    private function flushStructural(CompilerRun $run, int $beforeLft, callable $emit): void
    {
        foreach ($run->pendingStructural as $nodeId => $lft) {
            if ($lft >= $beforeLft) {
                break;
            }

            unset($run->pendingStructural[$nodeId]);

            // Registered only once actually emitted. A placeholder that cannot be built —
            // no configured entry type, no mapped locale — must not become a `parentRef`
            // target, or its children point at an entry no payload ever writes.
            if ($this->emitStructural($nodeId, $run->ancestry, $run->parentable, $run->locales, $run->environment, $emit)) {
                $run->parentable[$nodeId] = 'pages';
            }
        }
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
        $entities = $this->mapping->entityRows();

        if ($entities === []) {
            return;
        }

        $reader = new TaxonomyReader($pdo);
        $sites = $this->siteHandles($locales);

        if ($sites === []) {
            return;
        }

        foreach ($entities as $name => $entity) {
            if (!$this->wanted($name) || !$entity->compiles()) {
                continue;
            }

            foreach ($reader->rows((string) $entity->table(), $entity->softDelete()) as $row) {
                if ($limit !== null && $this->entries >= $limit) {
                    return;
                }

                $this->compileEntityRow($entity, $row, $builder, $environment, $sites, $emit);
            }
        }
    }

    /**
     * The entity lanes and their row counts, for a batched job to slice.
     *
     * Same lane filter `compileEntities` applies, same reader, same order —
     * a unit list built from this stays aligned with what a slice compiles.
     *
     * @return array<string, int> lane name => row count
     */
    public function entityLaneCounts(CompilerRun $run): array
    {
        $reader = new \Lameco\Kunstmaanmigrator\Source\TaxonomyReader($run->pdo);
        $counts = [];

        foreach ($this->mapping->entityRows() as $name => $entity) {
            if (!$this->wanted($name) || !$entity->compiles()) {
                continue;
            }

            $counts[$name] = count($reader->rows((string) $entity->table(), $entity->softDelete()));
        }

        return $counts;
    }

    /**
     * One window of one entity lane — the batched counterpart of the lane loop
     * inside `compileEntities`. Cross-environment dedupe warnings only fire
     * within a single process; the loader's shared-uid state row is what
     * actually prevents a duplicate entry either way.
     */
    public function compileEntitySlice(CompilerRun $run, string $lane, int $offset, int $limit, callable $emit): void
    {
        $entity = $this->mapping->entityRow($lane);

        if ($entity === null || !$entity->compiles()) {
            return;
        }

        $sites = $this->siteHandles($run->locales);

        if ($sites === []) {
            return;
        }

        $reader = new \Lameco\Kunstmaanmigrator\Source\TaxonomyReader($run->pdo);
        $rows = $reader->rows((string) $entity->table(), $entity->softDelete());

        foreach (array_slice($rows, $offset, $limit) as $row) {
            $this->compileEntityRow($entity, $row, $run->builder, $run->environment, $sites, $emit);
        }
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $sites
     */
    private function compileEntityRow(
        EntityRow $entity,
        array $row,
        BlockBuilder $builder,
        string $environment,
        array $sites,
        callable $emit,
    ): void {
        $name = $entity->name;
        $entryType = (string) $entity->entryType();
        $dedupe = $entity->dedupe();
        $single = $entity->single();
        $titleColumn = $entity->titleColumn();
        $title = trim((string) ($row[$titleColumn] ?? ''));

        // Craft's title is required on every one of these entry types, and an entry
        // with no title is a row nobody can find again. A `single:` row is the
        // exception: it merges into the section's existing entry, whose title an
        // earlier contributor already set, so it carries no title of its own.
        if ($title === '' && !$single) {
            $this->skip(sprintf('%s: row %s has no `%s`', $name, (string) ($row['id'] ?? '?'), $titleColumn));

            return;
        }

        $uid = EntityIndex::uid(
            $dedupe ? EntityIndex::SHARED : $environment,
            (string) $entity->table(),
            (int) $row['id'],
        );

        if ($dedupe) {
            $seen = $this->sharedTitles[$uid] ?? null;

            if ($seen !== null && $seen !== $title) {
                $this->skip(sprintf('%s: `%s` and `%s` share id %d across environments', $name, $seen, $title, (int) $row['id']));
            }

            $this->sharedTitles[$uid] = $title;
        }

        $fields = $builder->fieldsFrom($entity->map(), $row, $name, $entryType);
        $fields += $builder->childrenOf($entity->children(), $entryType, (int) $row['id'], $name, true);

        // No `title` key at all when the row has none — an absent key reaches the
        // loader as null and leaves the existing entry title in place, where an
        // empty string would clear it.
        $site = ['enabled' => true];
        if ($title !== '') {
            $site['title'] = $title;
        }

        if ($fields !== []) {
            $site['fieldValues'] = $fields;
        }

        $payload = [
            'sourceUid' => $uid,
            'section' => (string) $entity->section(),
            'entryType' => $entryType,
            'sites' => array_fill_keys($sites, $site),
        ];
        if ($single) {
            $payload['single'] = true;
        }

        $emit($payload);

        $this->entries++;
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
        array $parentable,
        string $environment,
        PageRow $page,
    ): array {
        // Everything this call evaluates belongs to one legacy locale. `hide_title |
        // translatorFallback(...)` has no column to read that locale from, so it reads it
        // off the builder instead: set here, once, before any field or block is built.
        $builder->setLocale((string) $translation['lang']);

        $entryType = (string) $page->entryType();
        $site = [
            // A rescued offline translation is real content on a locale the old site kept
            // dark. It arrives disabled: an editor publishes it, the cutover does not.
            'enabled' => $translation['online'],
            'title' => $translation['title'],
            'slug' => $translation['slug'],
        ];

        // Kunstmaan hangs every page off the home node, but in Craft the home page is its
        // own single section and a structure cannot parent across sections. Those pages are
        // roots of the `pages` structure instead.
        $parentId = $node['parentId'];

        if ($parentId !== null) {
            $parentSection = $parentable[$parentId] ?? null;

            if ($parentSection !== null && $parentSection === $page->section()) {
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
        $pageRow = $page->table() !== null
            ? $parts->row($page->table(), $translation['entityId'])
            : null;

        $pageFields = $pageRow !== null
            ? $builder->fieldsFrom($page->map(), $pageRow, $translation['entity'], $entryType)
            : [];

        // A page entity can own collections too. Partner branches, contact persons and awards
        // are three tables hanging off `partner_pages` that the target models as three
        // Matrixes — the same relationship a pagepart's `children:` describes, one level up.
        if ($pageRow !== null) {
            $pageFields += $builder->childrenOf(
                $page->children(),
                $entryType,
                $translation['entityId'],
                $translation['entity'],
                true,
            );
        }

        // Sidecar entities — the header tab, structured data — decorate a page through the
        // polymorphic ref, outside both the page's own table and the pagepart tree. Which
        // pages carry one is a per-page fact the data answers, not a mapping declaration; a
        // page without a row simply gets nothing. The page's own map wins a target collision.
        $pageFields += $this->sidecarFields($parts, $builder, $translation, $entryType);

        // The node's `created` is when the page was made, not when it was published. Editorial
        // types carry their own date, and on the first real corpus the two disagreed on 279 of
        // 434 blog posts — several by months.
        $postDate = $translation['created'];

        if ($pageRow !== null && $page->postDate() !== null) {
            $postDate = $pageRow[$page->postDate()] ?? $postDate;
        }

        if ($postDate !== null) {
            $site['postDate'] = date(DATE_ATOM, (int) strtotime((string) $postDate));
        }

        $builderBlocks = [];
        $prependedBlocks = [];

        foreach ($page->contexts() as $context => $target) {
            $sequence = $parts->sequence($translation['entity'], $translation['entityId'], $context);

            $field = (string) $target['field'];

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

        $formBlock = $this->formBlockFor($parts, $translation, $page, $environment);

        if ($formBlock !== null) {
            $builderBlocks[] = $formBlock;
        }

        if ($builderBlocks !== []) {
            $pageFields[$page->builderField()] = $builderBlocks;
        }

        if ($pageFields !== []) {
            $site['fieldValues'] = $pageFields;
        }

        return $site;
    }

    /**
     * The form the page owns, referenced from the page itself.
     *
     * The forms lane compiles a page's `form` context into a Formie form and stops there:
     * on the first full Enreach run, 70 forms existed and no page pointed at any of them.
     * A page whose form context holds at least one mappable field gets a form block at the
     * foot of its builder, carrying `{"_form": <form sourceUid>}` for the loader to resolve
     * against the form lane's state row — the same two-pass contract a `_ref` follows when
     * the target does not exist yet.
     *
     * @param array<string, mixed> $translation
     * @return array{type:string, fields:array<string,mixed>}|null
     */
    private function formBlockFor(
        PartReader $parts,
        array $translation,
        PageRow $page,
        string $environment,
    ): ?array {
        $forms = $this->mapping->forms();
        $fieldSpecs = $this->mapping->formFields();

        if (!$forms->declared || $fieldSpecs === []) {
            return null;
        }

        $sequence = $parts->sequence((string) $translation['entity'], (int) $translation['entityId'], $forms->context);
        $mappable = array_filter($sequence, static fn(array $ref): bool => isset($fieldSpecs[$ref['part']]));

        if ($mappable === []) {
            return null;
        }

        $entryType = (string) $page->entryType();
        $field = $page->builderField();
        $slot = $this->schema?->slot($entryType, $field);

        if ($slot === null || !$slot->isMatrix()) {
            $this->skip(sprintf('form on %s: no %s to land in', $entryType, $field));

            return null;
        }

        foreach ($slot->nested as $blockType) {
            $formsHandle = $this->soleFormsSlot($blockType);

            if ($formsHandle === null) {
                continue;
            }

            $this->blocks++;

            return ['type' => $blockType, 'fields' => [$formsHandle => [[
                '_form' => SourceUid::forForm(
                    $environment,
                    (string) $translation['entity'],
                    (int) $translation['entityId'],
                ),
            ]]]];
        }

        $this->skip(sprintf('form on %s: no allowed block carries a Forms field', $entryType));

        return null;
    }

    /** The one Forms-type field on a block type, or null when there is none or several. */
    private function soleFormsSlot(string $blockType): ?string
    {
        $found = [];

        foreach ($this->schema?->slots($blockType) ?? [] as $slot) {
            if ($slot->type === 'Forms') {
                $found[] = $slot->handle;
            }
        }

        return count($found) === 1 ? $found[0] : null;
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
            $headTable = $this->mapping->partRow($emission['part'])?->table();

            if ($headTable !== null) {
                $nested['_sourcePartRef'] = sprintf(
                    '%s:%s:%d',
                    $environment,
                    $headTable,
                    $emission['id'],
                );
            }

            return ['type' => (string) $emitted['block'], 'fields' => $nested];
        }

        $part = $this->mapping->partRow($emission['part']);

        // Anything with no block to build is counted, so the run report names it; whether
        // that was a decision or a hole is the coverage report's distinction to draw.
        if ($part === null || $part->block() === null) {
            $this->skip($emission['part']);

            return null;
        }

        if (!$part->compilesToBlocks()) {
            return null;
        }

        $block = $builder->build($emission['part'], $emission['id'], $part->spec);

        if ($block === null) {
            return null;
        }

        // `promote:` is validated against the target and then not emitted: the lane has no
        // compiler. Counting it here keeps a declared-but-unbuilt collection out of the set of
        // things a clean coverage report implies were migrated.
        foreach (array_keys($part->promote()) as $table) {
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
        return SourceUid::forNode($environment, $nodeId);
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
        $entryType = $this->mapping->structuralEntryType();

        if ($entryType === null) {
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

    /**
     * Every sidecar's contribution to one page entry.
     *
     * A sidecar names targets for every page it decorates, but the hero fields are placed on
     * some entry types and not others — `heroImage` exists on eight. A field the type does not
     * carry is dropped here and counted, per type, so widening a field layout is what turns
     * the counter into content rather than a mapping edit.
     *
     * @param array<string, mixed> $translation
     * @return array<string, mixed>
     */
    private function sidecarFields(
        PartReader $parts,
        BlockBuilder $builder,
        array $translation,
        string $entryType,
    ): array {
        $fields = [];

        foreach ($this->mapping->sidecarRows() as $name => $sidecar) {
            // No `wanted()` gate: a sidecar rides the page it decorates, so a run narrowed
            // to one page entity still carries that page's hero. Excluding one is `drop:`.
            if (!$sidecar->isMigrated()) {
                continue;
            }

            $row = $parts->sidecarRow(
                (string) $sidecar->table(),
                (string) $translation['entity'],
                (int) $translation['entityId'],
            );

            if ($row === null) {
                continue;
            }

            $context = 'sidecar:' . $name;
            $mapped = $builder->fieldsFrom($sidecar->map(), $row, $context, $entryType);

            if (isset($row['id'])) {
                $mapped += $builder->childrenOf($sidecar->children(), $entryType, (int) $row['id'], $context, true);
            }

            foreach ($mapped as $target => $value) {
                if ($this->schema !== null && $this->schema->slot($entryType, (string) $target) === null) {
                    $this->skip(sprintf('sidecar %s: %s not on %s', $name, $target, $entryType));

                    continue;
                }

                $fields[(string) $target] ??= $value;
            }
        }

        return $fields;
    }

    /** The Craft section an entry of this page entity lands in, per the mapping. */
    private function sectionOfEntity(string $entity): ?string
    {
        return $this->mapping->pageRow($entity)?->section();
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
