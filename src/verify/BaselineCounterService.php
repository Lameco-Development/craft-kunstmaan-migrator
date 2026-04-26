<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\verify;

use Craft;
use craft\db\Query;
use craft\elements\Category;
use craft\elements\Entry;
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
 *     'format' => 'counts-v1',
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
     * @return array<string, mixed>
     */
    public function capture(): array
    {
        return [
            'format' => 'counts-v1',
            'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'sections' => $this->captureSections(),
            'assets' => $this->captureAssets(),
            'taxonomies' => $this->captureTaxonomies(),
            'retour' => $this->captureRetour(),
            'seomatic' => $this->captureSeomatic(),
        ];
    }

    /**
     * Per-section totalCount + countsBySite. NO per-entry SHA (D-59 drop).
     *
     * @return array<string, array<string, mixed>>
     */
    private function captureSections(): array
    {
        $out = [];
        $sections = Craft::$app->entries->getAllSections();

        foreach ($sections as $section) {
            $handle = (string) $section->handle;

            $entries = Entry::find()
                ->section($section)
                ->site('*')
                ->status(null)
                ->drafts(null)
                ->revisions(false)
                ->all();

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
