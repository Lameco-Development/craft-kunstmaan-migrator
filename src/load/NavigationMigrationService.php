<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\load;

use Craft;
use Throwable;
use yii\base\Component;
use lameco\kunstmaanmigrator\Plugin;
use lameco\kunstmaanmigrator\craft\CraftElementWriter;
use lameco\kunstmaanmigrator\craft\ElementWriter;
use lameco\kunstmaanmigrator\db\LegacyDbService;
use craft\elements\Entry;
use verbb\navigation\Navigation;
use verbb\navigation\elements\Node as NavNode;

/**
 * NavigationMigrationService — imports Kunstmaan MenuBundle data
 * (`kuma_menu` + `kuma_menu_item`) into verbb/navigation nodes.
 *
 * Craft element writes go through the ElementWriter seam, but this module is
 * not testable on that alone: seven call sites reach `Navigation::$plugin`
 * statically — `getNavs()->getNavByHandle()` and `getNodes()->setTempNodes()`
 * — and a static admits no fake. Every write path here therefore runs into
 * verbb before it reaches a save, which is why NavigationParentLinkageTest can
 * assert on which rows are skipped and what is reported, but not on a saved
 * node. Closing that needs a second port over verbb/navigation, roughly
 * `navByHandle()` and `registerTempNodes()`; the seam below is the half of the
 * job that was in scope.
 *
 * Source shape (per discovery against deklerk + simac source DBs):
 *   - `kuma_menu` `(id, name, locale)` — one row per (handle, locale).
 *     simac has 4 rows for `secondary_top` (nl/en/de/fr); deklerk has 1 for `top`.
 *   - `kuma_menu_item` is a nested-set tree per menu, with FK to
 *     `kuma_node_translation` for entry-typed links and a free-form `url`
 *     column for url-typed links. Per-locale trees may differ in shape
 *     (simac's secondary_top/nl has 25 items, secondary_top/en has 21) —
 *     locales are NOT translations of each other.
 *
 * Mapping strategy (faithful per-locale, NOT cross-locale dedup):
 *   - One verbb Node element per `kuma_menu_item` row, enabled only for
 *     the source row's locale's site. Items shared across locales become
 *     duplicate verbb nodes (one per locale). Operator can dedup
 *     post-migration via the CP if desired; cross-locale dedup is a
 *     candidate v0.2 enhancement.
 *
 *   - `node_translation_id → kuma_node.id → Craft entry id` resolved via
 *     the migrator's MigrationStateService (queryable by kuma_node id
 *     across all `App_Entity_Pages_*` source slugs).
 *
 *   - `type='page_link'` → verbb type `craft\elements\Entry`, elementId set,
 *     url null, title falls back to target entry's title when the source
 *     row had no override.
 *
 *   - `type='url_link'` (and any non-page_link) → verbb type null, url copied
 *     verbatim, title copied from override or url.
 *
 * Optional-plugin gate: if verbb/navigation is not installed the entire
 * pass is skipped with a WARN — never a hard error. Same shape as the
 * Retour adapter (D-56).
 *
 * Settings-disabled gate: `Settings::navigationEnabled` (default true).
 * When false, the pass is skipped with a distinct warn line so REPORT.md
 * can distinguish operator-opted-out from plugin-not-installed.
 *
 * State key: `('navigation', "kuma_menu_item:{$id}")`.
 *
 * Two-pass save: first pass creates every node with parentId=null and
 * builds a `kumaItemId → nodeId` map; second pass walks rows with
 * non-null parent_id and re-saves the child with `setParentId()` resolved
 * via the map — necessary because `kuma_menu_item.parent_id` is a self-FK
 * that must wait for the parent's verbb node to exist.
 *
 * Sites with no MenuBundle data (dewert uses NodeMenu/page-tree instead):
 * `kuma_menu` returns 0 rows → service emits a clean WARN and returns
 * without writing anything. NodeMenu migration is a separate stage
 * scheduled for v0.2 (walks Pages tree filtered by hiddenFromNav and
 * emits headerMain nodes pointing at top-level pages).
 */
class NavigationMigrationService extends Component
{
    public LegacyDbService $legacyDb;
    public MigrationStateService $stateService;

    /**
     * Kuma-locale → Craft-site-handle map. Wired in Plugin::init() from
     * Plugin::resolveSitesMap(). Empty map means no sites configured —
     * the service degrades gracefully (warn + return).
     *
     * @var array<string, string>
     */
    public array $sites = [];

    /**
     * The legacy environment being migrated, e.g. `COM`.
     *
     * Entries are recorded in state as `<ENV>:kuma_nodes` keyed by node id. Without knowing
     * the environment this service cannot find a single one of them.
     */
    public string $environment = '';

    /**
     * The seam at Craft's element writes. Wired in Plugin::init(); read
     * through elements() so no call site has to cope with "not wired yet".
     */
    public ?ElementWriter $elementWriter = null;

    /**
     * Source table-name overrides (passed verbatim into raw SQL).
     * Defaults match the canonical Kunstmaan MenuBundle schema.
     */
    public string $menuTableName = 'kuma_menu';
    public string $menuItemTableName = 'kuma_menu_item';
    public string $nodeTranslationTableName = 'kuma_node_translations';
    public string $nodeVersionTableName = 'kuma_node_versions';
    public string $nodesTableName = 'kuma_nodes';

    /**
     * Slice 2 (NodeMenu) target nav handle. The scaffolder's slice 7 v0.7
     * porter rewrites NodeMenu loops (`activeSubnode.children | filter(...)`)
     * to `craft.navigation.nodes('headerMain')`, so the target handle defaults
     * to `headerMain`. Operator can override per-project via Settings.
     */
    public string $nodeMenuNavHandle = 'headerMain';

    /**
     * Slice 2 — `kuma_nodes.internal_name` values to exclude from NodeMenu
     * migration. Mirrors source-template filters seen in real Lameco sites:
     *   - `'settings'` — every Lameco site filters this from header nav
     *     (it's the singleton-config Node, surfaced in templates via
     *     `nodemenu.getNodeByInternalName('settings')` not via the loop).
     * Other values (e.g. dewert's `'dienst'`) are project-specific and
     * operator-extended via Settings::nodeMenuExcludedInternalNames.
     *
     * @var list<string>
     */
    public array $nodeMenuExcludedInternalNames = ['settings'];

    private const STATE_SOURCE = 'navigation';

    /**
     * Slice 2 — FQCN-substring patterns that mark a Page entity as a
     * Single section in Craft (rendered via `preloadSingles` Twig var,
     * not as a regular nav entry). Mirrors scaffolder's
     * `SchemaGenerator::SINGLETON_PAGE_PATTERNS` so we skip the same
     * entities the scaffolder collapses into `globalSettings`. Matches
     * any FQCN ending with one of these short-name suffixes.
     */
    private const SINGLETON_FQCN_SUFFIXES = [
        'SettingPage', 'SettingsPage', 'FooterPage', 'HeaderPage',
        'GeneralPage', 'SitePage', 'ConfigPage',
    ];

    /**
     * Walk every `kuma_menu` + `kuma_menu_item` row and write a verbb Node
     * per source row. Idempotent: re-running updates existing nodes via
     * the state map.
     */
    private function elements(): ElementWriter
    {
        return $this->elementWriter ??= new CraftElementWriter();
    }

    public function migrateAll(MigrationOptions $opts): MigrationReport
    {
        $report = new MigrationReport();

        if (!Plugin::getInstance()->getSettings()->navigationEnabled) {
            Craft::info(
                'verbb/navigation adapter explicitly disabled via Settings::navigationEnabled; skipping nav migration pass.',
                'kunstmaanmigrator',
            );
            $report->warn(self::disabledWarnLine());
            return $report;
        }

        if (Craft::$app->plugins->getPlugin('navigation') === null) {
            Craft::warning(
                'verbb/navigation plugin not installed; skipping nav migration pass.',
                'kunstmaanmigrator',
            );
            $report->warn('verbb/navigation plugin not installed; nav migration skipped.');
            return $report;
        }

        if (!class_exists(Navigation::class) || Navigation::$plugin === null) {
            $report->incr('failed');
            $report->warn('verbb/navigation not loaded (class/plugin null); nav migration aborted.');
            return $report;
        }

        $localeToSiteId = $this->buildLocaleToSiteIdMap();
        if ($localeToSiteId === []) {
            $report->warn('No Craft sites mapped; nav migration aborted.');
            return $report;
        }

        try {
            $menus = $this->legacyDb->queryAll(
                'SELECT id, name, locale FROM ' . $this->menuTableName . ' ORDER BY id',
            );
        } catch (Throwable $e) {
            $report->warn(sprintf(
                'Could not read %s (%s); MenuBundle pass skipped.',
                $this->menuTableName,
                $e->getMessage(),
            ));
            $menus = [];
        }

        if ($menus === []) {
            $report->warn(sprintf(
                'No rows in %s; MenuBundle pass skipped (NodeMenu pass below covers page-tree sites).',
                $this->menuTableName,
            ));
            // NodeMenu pass below still runs — that's the right path for
            // dewert and any site that drives its menu off the page tree.
            $this->migrateNodeMenu($localeToSiteId, $opts, $report);
            return $report;
        }

        // Resolve verbb nav id per source handle once. Missing navs are
        // reported and the menu's items are skipped — operator should
        // re-run scaffolder generate-schema or apply project-config to
        // create the matching nav.
        $navIdByHandle = [];
        foreach ($menus as $menu) {
            $handle = (string) ($menu['name'] ?? '');
            if ($handle === '' || isset($navIdByHandle[$handle])) {
                continue;
            }
            $nav = Navigation::$plugin->getNavs()->getNavByHandle($handle);
            $navIdByHandle[$handle] = $nav?->id;
        }

        // First pass: create every node with parentId=null. Build the
        // kumaItemId → nodeId map for the second pass.
        $itemToNodeId = [];
        foreach ($menus as $menu) {
            $menuId = (int) ($menu['id'] ?? 0);
            $handle = (string) ($menu['name'] ?? '');
            $locale = (string) ($menu['locale'] ?? '');
            $navId = $navIdByHandle[$handle] ?? null;
            $siteId = $localeToSiteId[$locale] ?? null;

            if ($navId === null) {
                $report->warn(sprintf(
                    'kuma_menu id=%d handle="%s" has no matching verbb nav; skipping menu.',
                    $menuId,
                    $handle,
                ));
                continue;
            }
            if ($siteId === null) {
                $report->warn(sprintf(
                    'kuma_menu id=%d locale="%s" has no matching Craft site; skipping menu.',
                    $menuId,
                    $locale,
                ));
                continue;
            }

            try {
                $items = $this->legacyDb->queryAll(
                    'SELECT id, parent_id, node_translation_id, type, title, url, new_window, lft, lvl
                     FROM ' . $this->menuItemTableName . '
                     WHERE menu_id = :menuId
                     ORDER BY lft',
                    [':menuId' => $menuId],
                );
            } catch (Throwable $e) {
                $report->warn(sprintf(
                    'Could not read %s for menu id=%d (%s); skipping menu.',
                    $this->menuItemTableName,
                    $menuId,
                    $e->getMessage(),
                ));
                continue;
            }

            foreach ($items as $item) {
                $kumaItemId = (int) ($item['id'] ?? 0);
                try {
                    $nodeId = $this->upsertNavNode(
                        item: $item,
                        navId: (int) $navId,
                        siteId: $siteId,
                        opts: $opts,
                        report: $report,
                    );
                    if ($nodeId !== null) {
                        $itemToNodeId[$kumaItemId] = $nodeId;
                    }
                } catch (Throwable $e) {
                    $report->incr('failed');
                    $report->warn(sprintf(
                        'nav node import failed for kuma_menu_item id=%d: %s',
                        $kumaItemId,
                        $e->getMessage(),
                    ));
                }
            }
        }

        // Second pass: parent linkage. Walk every item with non-null
        // parent_id and re-save the child with setParentId() resolved via
        // the map. Items whose parent failed in the first pass are
        // reported and skipped — they remain as roots.
        if (!$opts->dryRun && $itemToNodeId !== []) {
            $this->applyParentLinkage($itemToNodeId, $report);
        }

        // Slice 2: NodeMenu pass. Runs after MenuBundle so a single
        // verbb nav can host both kinds (rare in practice — Lameco sites
        // typically use one or the other — but allowed). Internally
        // skipped when kuma_nodes is empty.
        $this->migrateNodeMenu($localeToSiteId, $opts, $report);

        return $report;
    }

    // --------------------------------------------------------------------------
    // Private — node upsert + helpers
    // --------------------------------------------------------------------------

    /**
     * Upsert a single verbb Node element from a kuma_menu_item row.
     * Returns the saved node's id, or null when skipped (dry-run, missing
     * entry target, etc.).
     *
     * @param array<string, mixed> $item
     */
    private function upsertNavNode(
        array $item,
        int $navId,
        int $siteId,
        MigrationOptions $opts,
        MigrationReport $report,
    ): ?int {
        $kumaItemId = (int) ($item['id'] ?? 0);
        if ($kumaItemId <= 0) {
            $report->incr('skipped');
            return null;
        }

        $stateKey = 'kuma_menu_item:' . $kumaItemId;
        $existingNodeId = $this->stateService->getTargetId(self::STATE_SOURCE, $stateKey);

        $type = (string) ($item['type'] ?? '');
        $titleOverride = $item['title'] !== null && $item['title'] !== ''
            ? (string) $item['title']
            : null;
        $url = $item['url'] !== null && $item['url'] !== ''
            ? (string) $item['url']
            : null;
        $newWindow = ((int) ($item['new_window'] ?? 0)) === 1;
        $nodeTranslationId = !empty($item['node_translation_id'])
            ? (int) $item['node_translation_id']
            : null;

        $node = null;
        if ($existingNodeId !== null) {
            $node = $this->elements()->findById($existingNodeId, NavNode::class, $siteId);
        }
        if ($node === null) {
            $node = new NavNode();
        }

        $node->navId = $navId;
        $node->siteId = $siteId;
        $node->newWindow = $newWindow;
        $node->enabled = true;
        $node->enabledForSite = true;

        $resolvedTitle = $titleOverride;
        if ($type === 'page_link' && $nodeTranslationId !== null) {
            $entryId = $this->resolveEntryIdFromNodeTranslation($nodeTranslationId);
            if ($entryId === null) {
                $report->incr('skipped');
                $report->warn(sprintf(
                    'kuma_menu_item id=%d: node_translation_id=%d has no migrated entry yet; skipping (re-run after entry migration completes).',
                    $kumaItemId,
                    $nodeTranslationId,
                ));
                return null;
            }
            $node->elementId = $entryId;
            $node->type = Entry::class;
            $node->url = null;
            if ($resolvedTitle === null) {
                $entry = Craft::$app->entries->getEntryById($entryId, $siteId);
                $resolvedTitle = $entry !== null ? (string) $entry->title : null;
            }
        } else {
            $node->elementId = null;
            $node->type = null;
            $node->url = $url ?? '#';
            if ($resolvedTitle === null) {
                $resolvedTitle = $url ?? '(URL)';
            }
        }
        $node->title = $resolvedTitle ?? '(untitled)';

        if ($opts->dryRun) {
            $report->incr('skipped');
            return null;
        }

        // Register with verbb's tempNodes service so validation can find
        // this node by (navId, parentId) without needing a DB round-trip
        // — mirrors NodesController::actionAdd which calls setTempNodes
        // before saveElement to get unsaved siblings into validation.
        Navigation::$plugin->getNodes()->setTempNodes([$node]);

        if (!$this->elements()->save($node)) {
            $report->incr('failed');
            $report->warn(sprintf(
                'saveElement refused nav node for kuma_menu_item id=%d: %s',
                $kumaItemId,
                json_encode($node->getErrors(), JSON_UNESCAPED_SLASHES),
            ));
            return null;
        }

        // Per-locale isolation: kuma_menu rows are locale-scoped (the source
        // model has separate menu trees per locale, possibly with genuinely
        // different items — simac's secondary_top/nl has 25 items vs en's
        // 21). Craft auto-propagates a saved element to all enabled sites
        // with elements_sites.enabled=1; without intervention every nl-only
        // node would also surface on en, and vice versa. Disable the
        // node on every site OTHER than the source siteId so verbb's
        // per-site query filters them out cleanly. Done via direct
        // elements_sites UPDATE — calling saveElement() per site would
        // cascade through verbb's tempNodes / validation again, costing
        // a round trip per non-source site for no practical benefit.
        try {
            Craft::$app->db->createCommand()
                ->update(
                    '{{%elements_sites}}',
                    ['enabled' => false],
                    ['and', ['elementId' => (int) $node->id], ['!=', 'siteId', $siteId]],
                )
                ->execute();
        } catch (Throwable $e) {
            $report->warn(sprintf(
                'kuma_menu_item id=%d: per-site enabled flag update failed: %s',
                $kumaItemId,
                $e->getMessage(),
            ));
        }

        $newNodeId = (int) $node->id;
        $this->stateService->record(
            source: self::STATE_SOURCE,
            key: $stateKey,
            targetType: 'navigation_node',
            targetId: $newNodeId,
            meta: [
                'kumaItemId' => $kumaItemId,
                'navId' => $navId,
                'siteId' => $siteId,
                'type' => $type,
                'kumaNodeTranslationId' => $nodeTranslationId,
                'kumaParentId' => $item['parent_id'] !== null ? (int) $item['parent_id'] : null,
            ],
        );
        $report->incr($existingNodeId !== null ? 'updated' : 'created');
        return $newNodeId;
    }

    /**
     * Second pass: walk every kuma_menu_item row with non-null parent_id
     * and reposition the corresponding verbb node under its parent in
     * the structure tree. Items whose parent failed to migrate are left
     * as roots and reported.
     *
     * @param array<int, int> $itemToNodeId  kumaItemId → verbb node id
     */
    private function applyParentLinkage(array $itemToNodeId, MigrationReport $report): void
    {
        $childIds = array_keys($itemToNodeId);
        if ($childIds === []) {
            return;
        }

        // Yii2's createCommand expects named parameters, not positional `?`
        // placeholders. Bind one named param per id so the IN-list matches
        // the bind shape Yii's PDO wrapper produces.
        $placeholders = [];
        $params = [];
        foreach ($childIds as $i => $childId) {
            $name = ':id' . $i;
            $placeholders[] = $name;
            $params[$name] = (int) $childId;
        }

        try {
            $rows = $this->legacyDb->queryAll(
                'SELECT id, parent_id FROM ' . $this->menuItemTableName . '
                 WHERE id IN (' . implode(',', $placeholders) . ') AND parent_id IS NOT NULL',
                $params,
            );
        } catch (Throwable $e) {
            $report->warn(sprintf(
                'Could not read parent linkage from %s (%s); nav tree may be flat.',
                $this->menuItemTableName,
                $e->getMessage(),
            ));
            return;
        }

        foreach ($rows as $row) {
            $kumaChildId = (int) ($row['id'] ?? 0);
            $kumaParentId = (int) ($row['parent_id'] ?? 0);
            $childNodeId = $itemToNodeId[$kumaChildId] ?? null;
            $parentNodeId = $itemToNodeId[$kumaParentId] ?? null;
            if ($childNodeId === null || $parentNodeId === null) {
                if ($childNodeId !== null && $parentNodeId === null) {
                    $report->warn(sprintf(
                        'kuma_menu_item id=%d parent_id=%d did not migrate; child remains as root.',
                        $kumaChildId,
                        $kumaParentId,
                    ));
                }
                continue;
            }

            try {
                /** @var NavNode|null $child */
                $child = $this->elements()->findById($childNodeId, NavNode::class);
                if ($child === null) {
                    continue;
                }
                $child->setParentId($parentNodeId);
                Navigation::$plugin->getNodes()->setTempNodes([$child]);
                if (!$this->elements()->save($child)) {
                    $report->warn(sprintf(
                        'failed to set parent on nav node id=%d (kuma_menu_item id=%d)',
                        $childNodeId,
                        $kumaChildId,
                    ));
                }
            } catch (Throwable $e) {
                $report->warn(sprintf(
                    'parent linkage failed for kuma_menu_item id=%d: %s',
                    $kumaChildId,
                    $e->getMessage(),
                ));
            }
        }
    }

    /**
     * Slice 2 — NodeMenu pass. For sites that drive their menu off the
     * page tree (`hidden_from_nav` flag on `kuma_nodes`) instead of via
     * MenuBundle. Emits one verbb Node per nav-visible kuma_node, linked
     * to the migrated entry. Cross-locale: one verbb element per kuma_node
     * (the page tree IS shared across locales — translations vary, but
     * structure doesn't), so verbb's render-time per-site title resolution
     * via the linked entry takes care of the locale-specific labels
     * automatically.
     *
     * Filtering applied (matches dewert's `_layout/layout.html.twig` shape):
     *   - `hidden_from_nav = 0`
     *   - `deleted = 0`
     *   - `internal_name NOT IN $nodeMenuExcludedInternalNames`
     *   - `ref_entity_name` short-name not in SINGLETON_FQCN_SUFFIXES
     *     (those are Single sections in Craft, surfaced via
     *     `globalSettings` — never as nav rows)
     *
     * Idempotent: re-running updates existing nodes via state map keyed
     * by `kuma_node:<id>`.
     *
     * Locale strategy — KEY DIFFERENCE FROM SLICE 1:
     * MenuBundle pass emits one verbb node per (item, locale) and disables
     * non-source sites; the page tree by contrast is shared across locales
     * (one tree, multi-site translations on the linked entry). NodeMenu
     * pass emits ONE verbb element per kuma_node enabled across every
     * site — the entry's per-site enabledForSite + per-site title govern
     * visibility / label naturally.
     *
     * @param array<string, int> $localeToSiteId  kuma_locale → siteId
     */
    private function migrateNodeMenu(array $localeToSiteId, MigrationOptions $opts, MigrationReport $report): void
    {
        if ($localeToSiteId === []) {
            return;
        }

        $nav = Navigation::$plugin->getNavs()->getNavByHandle($this->nodeMenuNavHandle);
        if ($nav === null) {
            $report->warn(sprintf(
                'NodeMenu target nav handle "%s" not found in verbb; NodeMenu pass skipped (re-run scaffolder + project-config/apply, or override Settings::nodeMenuNavHandle).',
                $this->nodeMenuNavHandle,
            ));
            return;
        }
        $navId = (int) $nav->id;

        $primarySite = Craft::$app->sites->getPrimarySite();
        $primarySiteId = (int) $primarySite->id;

        // Resolve the primary-locale Kuma code (e.g. 'nl') for sort
        // ordering. Falls back to whatever the first sites map entry
        // points at, then to empty (which makes COALESCE fire below).
        $primaryLang = array_search((string) $primarySite->handle, $this->sites, true);
        if (!is_string($primaryLang)) {
            $primaryLang = (string) (array_key_first($this->sites) ?? '');
        }

        // Single JOINed query — one row per kuma_node with the page entity's
        // (ref_id, ref_entity_name) tuple from the primary-locale
        // translation's public_node_version. Translations of the same node
        // typically point at the same entity row, so primary is fine for
        // ref_id resolution. The translation also carries `weight`, which
        // Kunstmaan's NodeMenu uses as the canonical nav sort key — bare
        // `kuma_nodes.lft` ordering doesn't match what live sites render
        // (dewert example: lft puts Contact first, but live's weight order
        // is Starten/Groeien/Afscheid/Specialisaties/Diensten/Actueel/Contact).
        // Order by weight ascending with lft as tiebreaker; nodes missing
        // a primary-locale translation fall to the end (COALESCE 9999) so
        // they don't accidentally jump to the front of the menu.
        // Cross-locale dedup model means one structureelements.lft per
        // kuma_node — only ONE locale's weight wins. Primary locale is
        // the consistent choice.
        try {
            $rows = $this->legacyDb->queryAll(
                'SELECT n.id, n.parent_id, n.lvl, n.lft,
                        n.internal_name, n.ref_entity_name,
                        v.ref_id, t.weight AS sort_weight
                 FROM ' . $this->nodesTableName . ' n
                 LEFT JOIN ' . $this->nodeTranslationTableName . ' t
                   ON t.node_id = n.id AND t.lang = :primaryLang
                 LEFT JOIN ' . $this->nodeVersionTableName . ' v
                   ON v.id = t.public_node_version_id
                 WHERE n.hidden_from_nav = 0 AND n.deleted = 0 AND n.lvl >= 1
                 ORDER BY COALESCE(t.weight, 9999), LOWER(t.title), n.lft',
                [':primaryLang' => $primaryLang],
            );
        } catch (Throwable $e) {
            $report->warn(sprintf(
                'Could not read %s for NodeMenu pass (%s); skipped.',
                $this->nodesTableName,
                $e->getMessage(),
            ));
            return;
        }

        if ($rows === []) {
            return;
        }

        // Pre-pass: build the parent-id map + directly-excluded Set.
        // Needs to happen BEFORE the create pass because rows are now
        // sorted by translation weight (not lft), so a child can appear
        // ahead of its parent in iteration order. Without a complete
        // exclusion set up front, the transitive-exclusion check on a
        // child would miss a parent that hasn't been processed yet.
        $parentMap = [];
        $directlyExcluded = [];
        foreach ($rows as $row) {
            $kumaNodeId = (int) ($row['id'] ?? 0);
            if ($kumaNodeId <= 0) {
                continue;
            }
            $parentMap[$kumaNodeId] = (int) ($row['parent_id'] ?? 0);
            $internalName = (string) ($row['internal_name'] ?? '');
            $fqcn = (string) ($row['ref_entity_name'] ?? '');
            if ($internalName !== '' && in_array($internalName, $this->nodeMenuExcludedInternalNames, true)) {
                $directlyExcluded[$kumaNodeId] = true;
            } elseif ($this->isSingletonFqcn($fqcn)) {
                $directlyExcluded[$kumaNodeId] = true;
            }
        }

        // First pass: create / update one verbb Node per kuma_node, with
        // parentId=null. Skipped for filter exclusions, including
        // transitive exclusion — when a parent is filtered (e.g. dewert's
        // 'dienst' top-level), every descendant is also dropped so we
        // mirror the source template's `{% if internalName != 'dienst' %}`
        // wrapper which gates the entire subtree's render.
        $kumaNodeIdToVerbbId = [];
        foreach ($rows as $row) {
            $kumaNodeId = (int) ($row['id'] ?? 0);
            if ($kumaNodeId <= 0) {
                continue;
            }

            // Walk up the ancestry chain — if any ancestor is directly
            // excluded, this descendant inherits the exclusion. Cycle
            // guard via $seen Set in case of corrupt source tree.
            $excludedByAncestor = false;
            $cursor = $kumaNodeId;
            $seen = [];
            while ($cursor > 0 && !isset($seen[$cursor])) {
                $seen[$cursor] = true;
                if (isset($directlyExcluded[$cursor])) {
                    $excludedByAncestor = true;
                    break;
                }
                $cursor = $parentMap[$cursor] ?? 0;
            }
            if ($excludedByAncestor) {
                $report->incr('skipped');
                continue;
            }

            $fqcn = (string) ($row['ref_entity_name'] ?? '');

            $refId = !empty($row['ref_id']) ? (int) $row['ref_id'] : null;
            if ($refId === null || $fqcn === '') {
                $report->incr('skipped');
                $report->warn(sprintf(
                    'kuma_node id=%d has no resolvable ref_id/ref_entity_name; skipping.',
                    $kumaNodeId,
                ));
                continue;
            }

            try {
                $verbbId = $this->upsertNodeMenuNode(
                    kumaNodeId: $kumaNodeId,
                    refId: $refId,
                    fqcn: $fqcn,
                    navId: $navId,
                    primarySiteId: $primarySiteId,
                    opts: $opts,
                    report: $report,
                );
                if ($verbbId !== null) {
                    $kumaNodeIdToVerbbId[$kumaNodeId] = $verbbId;
                }
            } catch (Throwable $e) {
                $report->incr('failed');
                $report->warn(sprintf(
                    'NodeMenu node import failed for kuma_node id=%d: %s',
                    $kumaNodeId,
                    $e->getMessage(),
                ));
            }
        }

        // Second pass: parent linkage via kuma_nodes.parent_id chain.
        // Top-level nodes have parent_id = root id (often 1), which is
        // NOT in our verbb map — they correctly stay at root level.
        if (!$opts->dryRun && $kumaNodeIdToVerbbId !== []) {
            foreach ($rows as $row) {
                $kumaNodeId = (int) ($row['id'] ?? 0);
                $kumaParentId = (int) ($row['parent_id'] ?? 0);
                $childVerbbId = $kumaNodeIdToVerbbId[$kumaNodeId] ?? null;
                $parentVerbbId = $kumaNodeIdToVerbbId[$kumaParentId] ?? null;

                if ($childVerbbId === null || $parentVerbbId === null) {
                    continue;
                }

                try {
                    /** @var NavNode|null $child */
                    $child = $this->elements()->findById($childVerbbId, NavNode::class);
                    if ($child === null) {
                        continue;
                    }
                    $child->setParentId($parentVerbbId);
                    Navigation::$plugin->getNodes()->setTempNodes([$child]);
                    if (!$this->elements()->save($child)) {
                        $report->warn(sprintf(
                            'failed to set parent on nav node id=%d (kuma_node id=%d)',
                            $childVerbbId,
                            $kumaNodeId,
                        ));
                    }
                } catch (Throwable $e) {
                    $report->warn(sprintf(
                        'parent linkage failed for kuma_node id=%d: %s',
                        $kumaNodeId,
                        $e->getMessage(),
                    ));
                }
            }
        }
    }

    /**
     * Upsert a single verbb Node from a kuma_node row. Resolves the page
     * entity via state-map (`App_Entity_Pages_<ShortName>` source slug,
     * `<ref_id>` key) and emits an Entry-typed nav node enabled across
     * every site (per the cross-locale dedup model — see migrateNodeMenu
     * docstring).
     */
    private function upsertNodeMenuNode(
        int $kumaNodeId,
        int $refId,
        string $fqcn,
        int $navId,
        int $primarySiteId,
        MigrationOptions $opts,
        MigrationReport $report,
    ): ?int {
        $stateKey = 'kuma_node:' . $kumaNodeId;
        $existingNodeId = $this->stateService->getTargetId(self::STATE_SOURCE, $stateKey);

        $entryId = $this->resolveEntryIdForNode($kumaNodeId, $refId, $fqcn);
        if ($entryId === null) {
            $report->incr('skipped');
            $report->warn(sprintf(
                'kuma_node id=%d → %s ref_id=%d has no migrated entry yet; skipping (re-run after entry migration completes).',
                $kumaNodeId,
                $stateSource,
                $refId,
            ));
            return null;
        }

        $node = null;
        if ($existingNodeId !== null) {
            $node = $this->elements()->findById($existingNodeId, NavNode::class, $primarySiteId);
        }
        if ($node === null) {
            $node = new NavNode();
        }

        $node->navId = $navId;
        $node->siteId = $primarySiteId;
        $node->elementId = $entryId;
        $node->type = Entry::class;
        $node->url = null;
        $node->newWindow = false;
        $node->enabled = true;
        $node->enabledForSite = true;

        // Title: read primary-site entry's title for the saved-row default.
        // verbb's render-time logic resolves the per-site title from the
        // linked element automatically, so the saved title is just a
        // fallback / CP display value.
        $entry = Craft::$app->entries->getEntryById($entryId, $primarySiteId);
        $node->title = $entry !== null ? (string) $entry->title : '(untitled)';

        if ($opts->dryRun) {
            $report->incr('skipped');
            return null;
        }

        Navigation::$plugin->getNodes()->setTempNodes([$node]);

        if (!$this->elements()->save($node)) {
            $report->incr('failed');
            $report->warn(sprintf(
                'saveElement refused NodeMenu node for kuma_node id=%d: %s',
                $kumaNodeId,
                json_encode($node->getErrors(), JSON_UNESCAPED_SLASHES),
            ));
            return null;
        }

        $newNodeId = (int) $node->id;
        $this->stateService->record(
            source: self::STATE_SOURCE,
            key: $stateKey,
            targetType: 'navigation_node',
            targetId: $newNodeId,
            meta: [
                'kumaNodeId' => $kumaNodeId,
                'navId' => $navId,
                'refId' => $refId,
                'refEntityName' => $fqcn,
                'kind' => 'nodeMenu',
            ],
        );
        $report->incr($existingNodeId !== null ? 'updated' : 'created');
        return $newNodeId;
    }

    /**
     * Match an FQCN against SINGLETON_FQCN_SUFFIXES — these resolve to
     * Single sections in Craft (`globalSettings`) and never appear as
     * regular nav entries.
     */
    private function isSingletonFqcn(string $fqcn): bool
    {
        $fqcn = trim($fqcn, '\\');
        if ($fqcn === '') {
            return false;
        }
        $shortName = (string) (strrchr($fqcn, '\\') ?: $fqcn);
        $shortName = ltrim($shortName, '\\');
        foreach (self::SINGLETON_FQCN_SUFFIXES as $suffix) {
            if ($shortName === $suffix) {
                return true;
            }
        }
        return false;
    }

    /**
     * Resolve `kuma_node_translations.id` → Craft entry id via the migrator
     * state map.
     *
     * Kunstmaan's data model: a Node owns multiple NodeTranslations (one
     * per locale); each NodeTranslation points at a `public_node_version_id`
     * — the live NodeVersion row that holds (`ref_id`, `ref_entity_name`):
     * the Page entity's row id (within its own per-FQCN table) + FQCN.
     *
     * EntryMigrationService writes state rows keyed by
     * `(source: 'App_Entity_Pages_<ShortName>', sourceKey: <ref_id>)`,
     * so the right path is JOIN-then-exact-lookup, NOT
     * `getTargetIdByKey($kumaNodeId, [prefix])` — sourceKey is per-FQCN
     * entity-row id, NOT the kuma_node id, so a prefix-only match would
     * accidentally cross FQCNs (multiple FQCNs have entity row id=1).
     *
     * Returns null when:
     *   - the translation row is missing or has no live version
     *   - the live version's `ref_entity_name` isn't in `App\Entity\Pages\*`
     *     (Kunstmaan also models singletons / customs as Nodes; those
     *     aren't kept under that FQCN namespace and currently have no
     *     migrator state mapping for nav purposes — operator extension
     *     point if needed)
     *   - the target entry hasn't been migrated yet (re-run after
     *     entry migration completes)
     */
    private function resolveEntryIdFromNodeTranslation(int $nodeTranslationId): ?int
    {
        try {
            $row = $this->legacyDb->queryOne(
                'SELECT t.node_id, v.ref_id, v.ref_entity_name
                 FROM ' . $this->nodeTranslationTableName . ' t
                 JOIN ' . $this->nodeVersionTableName . ' v ON v.id = t.public_node_version_id
                 WHERE t.id = :id',
                [':id' => $nodeTranslationId],
            );
        } catch (Throwable) {
            return null;
        }
        if ($row === null || empty($row['ref_id']) || empty($row['ref_entity_name'])) {
            return null;
        }

        return $this->resolveEntryIdForNode(
            (int) $row['node_id'],
            (int) $row['ref_id'],
            (string) $row['ref_entity_name'],
        );
    }

    /**
     * The Craft entry a Kunstmaan node became.
     *
     * One node is one entry, recorded as `<ENV>:kuma_nodes` keyed by node id — that is the
     * whole identity model, and it is what makes a re-run an update. This service used to ask
     * for `App_Entity_Pages_BlogPage` keyed by the page entity's row id, which is the v1
     * convention: nothing has written it since, so every menu item resolved to nothing and
     * navigation migrated zero nodes across all three environments while reporting no failure.
     *
     * The old key is still tried, so a host still carrying v1 state rows keeps working.
     */
    private function resolveEntryIdForNode(int $kumaNodeId, int $refId, string $fqcn): ?int
    {
        if ($this->environment !== '' && $kumaNodeId > 0) {
            $entryId = $this->stateService->getTargetId(
                sprintf('%s:kuma_nodes', $this->environment),
                (string) $kumaNodeId,
            );

            if ($entryId !== null) {
                return $entryId;
            }
        }

        if ($fqcn === '' || $refId <= 0) {
            return null;
        }

        return $this->stateService->getTargetId(str_replace('\\', '_', trim($fqcn, '\\')), (string) $refId);
    }

    /**
     * Build a `kuma_locale → siteId` lookup from `$this->sites`
     * (kuma_locale → Craft site handle, populated by Plugin::resolveSitesMap()).
     * Sites without a mapping entry are silently dropped — they're handled
     * upstream by SeoMigrationService's per-site warning loop, no need to
     * duplicate the noise here.
     *
     * @return array<string, int>
     */
    private function buildLocaleToSiteIdMap(): array
    {
        $out = [];
        foreach (Craft::$app->sites->getAllSites() as $site) {
            $handle = (string) $site->handle;
            $locale = array_search($handle, $this->sites, true);
            if ($locale === false) {
                continue;
            }
            $out[(string) $locale] = (int) $site->id;
        }
        return $out;
    }

    /**
     * Phase 4.1 / D-25 + D-27 — testable warn-line for the Settings-disabled
     * gate. Distinct copy from the plugin-not-installed line so REPORT.md
     * skipped-stages aggregation can pattern-match operator-opted-out vs
     * adapter-unavailable.
     *
     * @internal
     */
    private static function disabledWarnLine(): string
    {
        return 'verbb/navigation adapter disabled (explicitly via Settings::navigationEnabled); nav migration skipped.';
    }
}
