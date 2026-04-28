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
    public function run(array $expectedCounts, float $tolerance, ?MigrationFilters $filters = null, ?array $translatedScope = null): array
    {
        $expectedSections   = (array) ($expectedCounts['sections']   ?? []);
        $expectedAssets     = (array) ($expectedCounts['assets']     ?? []);
        $expectedPlugins    = (array) ($expectedCounts['plugins']    ?? []);
        $expectedTaxonomies = (array) ($expectedCounts['taxonomies'] ?? []);

        $gates = [];
        $overallPass = true;

        $unmappedSourceEntities = (array) ($translatedScope['unmappedSourceEntities'] ?? []);
        if ($unmappedSourceEntities !== []) {
            $gates['filters:unmappedSourceEntities'] = [
                'pass' => false,
                'note' => 'unmapped source entity filters: ' . implode(', ', array_map('strval', $unmappedSourceEntities)),
                'unmappedSourceEntities' => array_values(array_map('strval', $unmappedSourceEntities)),
            ];
            $overallPass = false;
        }

        // Phase 4.1 / VER-04 — filter-scoped siteIds for locale-restricted runs.
        // Empty array = no scoping (Phase 4 behavior preserved).
        $scopeSiteIds = $this->resolveScopeSiteIds($filters);

        // ── Entry counts (canonical entries, primary site) ────────────
        foreach ($expectedSections as $sectionHandle => $expected) {
            // Phase 4.1 / D-28 — filter-aware gate evaluation. Sections excluded
            // by an entities allow-list get a SKIPPED row, not a 0/expected fail.
            if (self::isSectionFilteredOut($sectionHandle, $filters, $translatedScope)) {
                $gates[$sectionHandle] = ['skip' => true, 'note' => 'filtered out (entities allow-list)'];
                continue;
            }
            $expected = (int) $expected;
            if ($expected === 0) {
                $gates[$sectionHandle] = ['pass' => true, 'note' => 'expectedCount=0, skipped'];
                continue;
            }
            try {
                $query = Entry::find()
                    ->section($sectionHandle)
                    ->status(null);
                if ($scopeSiteIds !== []) {
                    $query->siteId($scopeSiteIds);
                }
                $actual = (int) $query->count();
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

    /**
     * Phase 4.1 / D-28 — pure decision: is this section excluded by the
     * filters' entities allow-list?
     *
     * Returns true ONLY when filters carry a non-empty entities list AND
     * the section handle is not in it. Null filters or empty allow-list
     * preserve Phase 4 behavior (everything in scope).
     *
     * @internal Public-static for direct unit tests without Reflection;
     *           mirrors LocalePreflight::compareEnvDefaultLocaleToLocaleMap.
     */
    public static function isSectionFilteredOut(string $sectionHandle, ?MigrationFilters $filters, ?array $translatedScope = null): bool
    {
        if ($filters === null) {
            return false;
        }
        if ($filters->entities === []) {
            return false;
        }

        // D-17 / 09-02B: never compare source-domain entity filters directly to
        // Craft section handles. When callers have not supplied a translated
        // Craft scope, preserve BC by leaving the section unfiltered.
        if ($translatedScope === null) {
            return false;
        }

        $sectionHandles = array_values(array_map('strval', (array) ($translatedScope['sectionHandles'] ?? [])));

        return !in_array($sectionHandle, $sectionHandles, true);
    }

    /**
     * Phase 4.1 / D-28 — resolve filter-scoped Craft site IDs from the locale
     * subset (when filters carry one). Returns [] when no locale scoping
     * applies (preserves Phase 4 unscoped behavior).
     *
     * Locale → siteId via Settings::$localeMap (the established Kunstmaan-locale
     * → Craft-site-handle map; mirrors EntryMigrationService and other services
     * that consume this map). Unmapped locales are silently skipped — the
     * locale preflight step ensures every filter locale is mapped before the
     * verify gate runs.
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
}
