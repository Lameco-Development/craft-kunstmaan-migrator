<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\verify;

use Craft;
use craft\db\Query;
use craft\elements\Category;
use craft\elements\Entry;
use lameco\kunstmaanmigrator\Plugin;
use lameco\kunstmaanmigrator\filter\MigrationFilters;
use Throwable;
use yii\base\Component;

/**
 * BaselineCounterService — light-counts baseline of the current Craft state
 * (sections + per-site counts, assets, taxonomies, Retour, SEOmatic) for
 * Phase 5 equivalence verification.
 *
 * Pure read: this service issues NO writes to the database, filesystem, or
 * any cache. Output is `format: counts-v1` — counts + light metadata only.
 *
 * D-59 explicit drop list (vs v1's BaselineSnapshotService): we ship counts
 * + light metadata only — no per-entry content checksums, no Matrix-block
 * sort normalization, no asset byte-level hashing, no git revision lookup,
 * no per-section entry list. Only the count survives. The full SHA-heavy
 * snapshot path is deferred to a future `verify capture-baseline --deep`
 * flag; v1's BaselineSnapshotService body remains in
 * `~/Sites/craft-kunstmaan-migrator/` as the verbatim source.
 *
 * Output shape:
 *   [
 *     'format'      => 'counts-v1',
 *     'generatedAt' => '<UTC ISO-8601>',
 *     'sections' => [
 *       '<sectionHandle>' => [
 *         'totalCount' => int,
 *         'countsBySite' => ['<siteHandle>' => int, ...],
 *       ],
 *       ...
 *     ],
 *     'assets' => ['totalCount' => int],
 *     'taxonomies' => ['<categoryGroupHandle>' => ['totalCount' => int], ...],
 *     'retour' => ['totalCount' => int],   // 0 if Retour absent (gate-skipped)
 *     'seomatic' => ['totalCount' => int], // 0 if SEOmatic absent
 *   ]
 *
 * Optional-plugin detection: SEOmatic / Retour absence yields `totalCount => 0`
 * rather than throwing, so the baseline runs on minimal configs.
 */
class BaselineCounterService extends Component
{
    /**
     * Capture a counts-only baseline of the current Craft state.
     *
     * Phase 4.1 / D-29: when $filters is non-null, the snapshot embeds a
     * `filterScope` JSON header (entities / locales / since) so the doctor
     * 8th check can detect filter-scope drift between capture and verify.
     * `filterScope: null` is emitted when no filters were passed (the key is
     * always present so downstream consumers don't need to handle missing-key).
     *
     * @return array<string, mixed>
     */
    public function capture(?MigrationFilters $filters = null, ?array $translatedScope = null): array
    {
        return [
            'format' => 'counts-v1',
            'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'filterScope' => self::buildFilterScope($filters),
            'sections' => $this->captureSections($filters, $translatedScope),
            'assets' => $this->captureAssets(),
            'taxonomies' => $this->captureTaxonomies(),
            'retour' => $this->captureRetour(),
            'seomatic' => $this->captureSeomatic(),
        ];
    }

    /**
     * Phase 4.1 / D-29 — pure helper rendering the filterScope JSON header
     * shape. Public-static so the doctor 8th check (D-30) and the
     * BaselineCounterServiceFiltersTest can call it directly without a
     * Craft bootstrap.
     *
     * @return array{entities: list<string>, locales: list<string>, since: ?string}|null
     */
    public static function buildFilterScope(?MigrationFilters $filters): ?array
    {
        if ($filters === null) {
            return null;
        }
        return [
            'entities' => array_values($filters->entities),
            'locales'  => array_values($filters->locales),
            'since'    => $filters->since,
        ];
    }

    /**
     * Per-section totalCount + countsBySite. NO per-entry SHA (D-59 drop).
     *
     * Phase 4.1 / D-29 — when $filters carries an entities allow-list, sections
     * outside the list are skipped (not included with totalCount=0). When
     * $filters carries a locales subset, the per-site counts are restricted
     * to the matching Craft sites via Settings::$localeMap.
     *
     * @return array<string, array<string, mixed>>
     */
    private function captureSections(?MigrationFilters $filters = null, ?array $translatedScope = null): array
    {
        $out = [];
        $sections = Craft::$app->entries->getAllSections();
        $scopeSiteIds = $this->resolveScopeSiteIds($filters);

        foreach ($sections as $section) {
            $handle = (string) $section->handle;

            // D-29 / D-17: respect source-entity filters only after they have
            // been translated to Craft section handles at the controller
            // boundary. Never compare Kunstmaan source identities directly to
            // Craft section handles.
            if (CountGateService::isSectionFilteredOut($handle, $filters, $translatedScope)) {
                continue;
            }

            $query = Entry::find()
                ->section($section)
                ->site('*')
                ->status(null)
                ->drafts(null)
                ->revisions(false);
            if ($scopeSiteIds !== []) {
                $query->siteId($scopeSiteIds);
            }
            $entries = $query->all();

            $countsBySite = [];
            $total = 0;
            foreach ($entries as $entry) {
                $siteHandle = (string) $entry->getSite()->handle;
                $countsBySite[$siteHandle] = ($countsBySite[$siteHandle] ?? 0) + 1;
                $total++;
            }

            ksort($countsBySite);

            $out[$handle] = [
                'totalCount' => $total,
                'countsBySite' => $countsBySite,
            ];
        }

        ksort($out);
        return $out;
    }

    /**
     * Phase 4.1 / D-29 — resolve filter-scoped Craft site IDs from the locale
     * subset. Mirrors CountGateService::resolveScopeSiteIds.
     *
     * @return list<int>
     */
    private function resolveScopeSiteIds(?MigrationFilters $filters): array
    {
        if ($filters === null || $filters->locales === []) {
            return [];
        }
        $localeMap = (array) Plugin::getInstance()->getSettings()->localeMap;
        $siteIds = [];
        try {
            foreach ($filters->locales as $locale) {
                $handle = $localeMap[$locale] ?? null;
                if (!is_string($handle) || $handle === '') {
                    continue;
                }
                $site = Craft::$app->sites->getSiteByHandle($handle);
                if ($site !== null) {
                    $siteIds[] = (int) $site->id;
                }
            }
        } catch (Throwable) {
            return [];
        }
        return array_values(array_unique($siteIds));
    }

    /**
     * Asset totalCount sourced from the kunstmaanmigrator_state table — the
     * canonical record of what the migrator created (mirrors CountGateService).
     * Asset::find()->volume() would include unrelated pre-existing assets,
     * since the migrator lands in a subfolder of a shared volume.
     *
     * @return array<string, int>
     */
    private function captureAssets(): array
    {
        try {
            $count = (int) (new Query())
                ->from('{{%kunstmaanmigrator_state}}')
                ->where(['source' => 'media', 'targetType' => 'asset'])
                ->count();
        } catch (Throwable) {
            $count = 0;
        }

        return ['totalCount' => $count];
    }

    /**
     * Per-category-group totalCount.
     *
     * @return array<string, array<string, int>>
     */
    private function captureTaxonomies(): array
    {
        $out = [];
        $groups = Craft::$app->categories->getAllGroups();

        foreach ($groups as $group) {
            $handle = (string) $group->handle;
            try {
                $count = (int) Category::find()
                    ->group($group)
                    ->status(null)
                    ->count();
            } catch (Throwable) {
                $count = 0;
            }
            $out[$handle] = ['totalCount' => $count];
        }

        ksort($out);
        return $out;
    }

    /**
     * Retour redirect totalCount. 0 if plugin is absent (gate-skipped).
     *
     * @return array<string, int>
     */
    private function captureRetour(): array
    {
        if (Craft::$app->plugins->getPlugin('retour') === null) {
            return ['totalCount' => 0];
        }

        try {
            $count = (int) (new Query())
                ->from('{{%retour_static_redirects}}')
                ->count();
        } catch (Throwable) {
            $count = 0;
        }

        return ['totalCount' => $count];
    }

    /**
     * SEOmatic section meta-bundle totalCount. 0 if plugin is absent.
     *
     * @return array<string, int>
     */
    private function captureSeomatic(): array
    {
        if (Craft::$app->plugins->getPlugin('seomatic') === null) {
            return ['totalCount' => 0];
        }

        try {
            $count = (int) (new Query())
                ->from('{{%seomatic_metabundles}}')
                ->where(['sourceBundleType' => 'section'])
                ->count();
        } catch (Throwable) {
            $count = 0;
        }

        return ['totalCount' => $count];
    }
}
