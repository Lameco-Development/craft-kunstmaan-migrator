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
 * Runs the entry/asset/SEOmatic/Retour/taxonomy count-match gate.
 *
 * Shared by VerifyController (console, prints to stdout) and VerifyJob
 * (queue, logs to file). No console dependencies — callers handle output.
 *
 * Expected counts are passed as an array argument so the plugin stays
 * project-agnostic. Tolerance is passed separately (D-60) — read from
 * `Settings::$verifyCountTolerance` (default 0.01) with a CLI override of
 * `--count-tolerance=`. mapping.yaml stays clean of verify config.
 *
 * Expected counts shape:
 *   [
 *     'sections'   => ['<sectionHandle>' => int, ...],
 *     'assets'     => ['<label>'         => int, ...],
 *     'plugins'    => ['seomatic' => int, 'retour' => int],
 *     'taxonomies' => ['<categoryGroupHandle>' => int, ...],
 *   ]
 *
 * Returns:
 *   ['pass' => bool, 'gates' => ['key' => ['expected', 'actual', 'delta', 'pass']]]
 *
 * Gates whose expected count is 0 or absent are skipped (pass=true, note set).
 * Gates for absent optional plugins (SEOmatic, Retour) are reported with
 * `skip => true` and excluded from the overall pass calculation.
 */
class CountGateService extends Component
{
    /**
     * @param array<string, mixed> $expectedCounts
     * @return array{pass: bool, gates: array<string, array<string, mixed>>}
     */
    public function run(array $expectedCounts, float $tolerance): array
    {
        $expectedSections   = (array) ($expectedCounts['sections']   ?? []);
        $expectedAssets     = (array) ($expectedCounts['assets']     ?? []);
        $expectedPlugins    = (array) ($expectedCounts['plugins']    ?? []);
        $expectedTaxonomies = (array) ($expectedCounts['taxonomies'] ?? []);

        $gates = [];
        $overallPass = true;

        // ── Entry counts (canonical entries, primary site) ────────────
        foreach ($expectedSections as $sectionHandle => $expected) {
            $expected = (int) $expected;
            if ($expected === 0) {
                $gates[$sectionHandle] = ['pass' => true, 'note' => 'expectedCount=0, skipped'];
                continue;
            }
            try {
                $actual = (int) Entry::find()
                    ->section($sectionHandle)
                    ->status(null)
                    ->count();
            } catch (Throwable) {
                $actual = -1;
            }
            $delta = $expected > 0 ? abs($actual - $expected) / $expected : 0.0;
            $pass  = $actual >= 0 && $delta <= $tolerance;
            if (!$pass) {
                $overallPass = false;
            }
            $gates[$sectionHandle] = ['expected' => $expected, 'actual' => $actual, 'delta' => $delta, 'pass' => $pass];
        }

        // ── Asset counts (from migration state table, not by volume) ─────
        // Assets land in a subfolder of the shared 'uploads' volume, so
        // Asset::find()->volume() would include unrelated pre-existing assets.
        // The state table is the canonical record of what the migrator created.
        // mapping.yaml key is a logical label only (e.g. 'migrated: 2691').
        $expectedAssetTotal = array_sum($expectedAssets);
        if ((int) $expectedAssetTotal > 0) {
            try {
                $actual = (int) (new Query())
                    ->from('{{%kunstmaanmigrator_state}}')
                    ->where(['source' => 'media', 'targetType' => 'asset'])
                    ->count();
            } catch (Throwable) {
                $actual = -1;
            }
            $expected = (int) $expectedAssetTotal;
            $delta    = $expected > 0 ? abs($actual - $expected) / $expected : 0.0;
            $pass     = $actual >= 0 && $delta <= $tolerance;
            if (!$pass) {
                $overallPass = false;
            }
            $gates['assets:migrated'] = ['expected' => $expected, 'actual' => $actual, 'delta' => $delta, 'pass' => $pass];
        }

        // ── Plugin counts (seomatic) — optional-plugin gate ──
        if (isset($expectedPlugins['seomatic'])) {
            $expected = (int) $expectedPlugins['seomatic'];
            if (Craft::$app->getPlugins()->getPlugin('seomatic') === null) {
                $gates['plugins:seomatic'] = ['skip' => true, 'note' => 'seomatic plugin not installed'];
            } elseif ($expected > 0) {
                try {
                    $actual = (int) (new Query())
                        ->from('{{%seomatic_metabundles}}')
                        ->where(['sourceBundleType' => 'section'])
                        ->count();
                } catch (Throwable) {
                    $actual = -1;
                }
                $delta = $expected > 0 ? abs($actual - $expected) / $expected : 0.0;
                $pass  = $actual >= 0 && $delta <= $tolerance;
                if (!$pass) {
                    $overallPass = false;
                }
                $gates['plugins:seomatic'] = ['expected' => $expected, 'actual' => $actual, 'delta' => $delta, 'pass' => $pass];
            }
        }

        // ── Plugin counts (retour) — optional-plugin gate (D-58 extension) ──
        // Mirror the SEOmatic shape; count from kunstmaanmigrator_state where
        // source='redirect' (RedirectMigrationService writes these in Plan 04-07).
        if (isset($expectedPlugins['retour'])) {
            $expected = (int) $expectedPlugins['retour'];
            if (Craft::$app->getPlugins()->getPlugin('retour') === null) {
                $gates['plugins:retour'] = ['skip' => true, 'note' => 'retour plugin not installed'];
            } elseif ($expected > 0) {
                try {
                    $actual = (int) (new Query())
                        ->from('{{%kunstmaanmigrator_state}}')
                        ->where(['source' => 'redirect'])
                        ->count();
                } catch (Throwable) {
                    $actual = -1;
                }
                $delta = $expected > 0 ? abs($actual - $expected) / $expected : 0.0;
                $pass  = $actual >= 0 && $delta <= $tolerance;
                if (!$pass) {
                    $overallPass = false;
                }
                $gates['plugins:retour'] = ['expected' => $expected, 'actual' => $actual, 'delta' => $delta, 'pass' => $pass];
            }
        }

        // ── Taxonomy counts (D-58/D-59 extension) ──────────────────────
        // Per-category-group count via Category::find()->group($handle)->count().
        foreach ($expectedTaxonomies as $groupHandle => $expected) {
            $expected = (int) $expected;
            if ($expected === 0) {
                $gates['taxonomies:' . $groupHandle] = ['pass' => true, 'note' => 'expectedCount=0, skipped'];
                continue;
            }
            try {
                $actual = (int) Category::find()
                    ->group($groupHandle)
                    ->status(null)
                    ->count();
            } catch (Throwable) {
                $actual = -1;
            }
            $delta = $expected > 0 ? abs($actual - $expected) / $expected : 0.0;
            $pass  = $actual >= 0 && $delta <= $tolerance;
            if (!$pass) {
                $overallPass = false;
            }
            $gates['taxonomies:' . $groupHandle] = ['expected' => $expected, 'actual' => $actual, 'delta' => $delta, 'pass' => $pass];
        }

        return ['pass' => $overallPass, 'gates' => $gates];
    }
}
