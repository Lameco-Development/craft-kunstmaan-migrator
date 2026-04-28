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
    public const DOMAIN_CRAFT_BASELINE_CURRENT_DRIFT = 'Craft baseline/current drift';
    public const DOMAIN_MIGRATION_CREATED_STATE_COUNTS = 'Migration-created state counts';
    public const DOMAIN_SOURCE_TRANSFORMED_PARITY = 'Source/transformed parity';

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
     * Compare two already-like-for-like flat count maps.
     *
     * This is intentionally domain-agnostic: callers choose whether the domain
     * is blocking. Phase 10 uses it for Craft baseline/current drift where the
     * same Craft-count snapshot shape is compared over time, not converted into
     * migration source expectations.
     *
     * @param array<string, int> $expected
     * @param array<string, int> $actual
     * @return array{pass: bool, gates: array<string, array<string, mixed>>}
     */
    public function compareFlatCounts(array $expected, array $actual, float $tolerance, string $domain, bool $blocking = true): array
    {
        $keys = array_values(array_unique(array_merge(array_keys($expected), array_keys($actual))));
        sort($keys);

        $gates = [];
        $overallPass = true;
        foreach ($keys as $key) {
            $expectedCount = (int) ($expected[$key] ?? 0);
            $actualCount = (int) ($actual[$key] ?? 0);
            $delta = $expectedCount > 0 ? abs($actualCount - $expectedCount) / $expectedCount : ($actualCount === 0 ? 0.0 : 1.0);
            $pass = $delta <= $tolerance;
            if ($blocking && !$pass) {
                $overallPass = false;
            }

            $gates[$key] = [
                'domain' => $domain,
                'expected' => $expectedCount,
                'actual' => $actualCount,
                'delta' => $delta,
                'pass' => $pass,
                'blocking' => $blocking,
            ];
        }

        return ['pass' => $overallPass, 'gates' => $gates];
    }

    /**
     * Flatten BaselineCounterService's counts-v1 snapshot into an explicit
     * Craft-count domain. Section totals and per-site counts stay separate so
     * verify compares like-for-like rows instead of mixing `site('*')` totals
     * with canonical primary-site migration expectations.
     *
     * @param array<string, mixed> $snapshot
     * @return array<string, int>
     */
    public static function flattenCraftSnapshotCounts(array $snapshot): array
    {
        $out = [];

        foreach ((array) ($snapshot['sections'] ?? []) as $handle => $row) {
            if (!is_array($row)) {
                continue;
            }
            $section = (string) $handle;
            $out['craft.sections.' . $section . '.total'] = (int) ($row['totalCount'] ?? 0);
            foreach ((array) ($row['countsBySite'] ?? []) as $siteHandle => $count) {
                $out['craft.sections.' . $section . '.site.' . (string) $siteHandle] = (int) $count;
            }
        }

        $out['craft.assets.total'] = (int) (($snapshot['assets'] ?? [])['totalCount'] ?? 0);
        foreach ((array) ($snapshot['taxonomies'] ?? []) as $handle => $row) {
            if (is_array($row)) {
                $out['craft.taxonomies.' . (string) $handle . '.total'] = (int) ($row['totalCount'] ?? 0);
            }
        }
        $out['craft.plugins.retour.total'] = (int) (($snapshot['retour'] ?? [])['totalCount'] ?? 0);
        $out['craft.plugins.seomatic.total'] = (int) (($snapshot['seomatic'] ?? [])['totalCount'] ?? 0);

        ksort($out);
        return $out;
    }

    /**
     * Current migration-created counts from the plugin state table.
     *
     * These are reported as their own domain. They are not compared against a
     * pre-migration Craft baseline because that would mix domains.
     *
     * @return array<string, int>
     */
    public function migrationCreatedStateCounts(): array
    {
        $out = [
            'migration.state.rows.total' => 0,
            'migration.created.entries' => 0,
            'migration.created.assets' => 0,
            'migration.created.redirects' => 0,
            'migration.created.seo' => 0,
        ];

        try {
            $out['migration.state.rows.total'] = (int) (new Query())
                ->from('{{%kunstmaanmigrator_state}}')
                ->count();

            $out['migration.created.entries'] = (int) (new Query())
                ->from('{{%kunstmaanmigrator_state}}')
                ->where(['targetType' => 'entry'])
                ->andWhere(['not in', 'source', ['media', 'redirect', 'seo_meta']])
                ->count();

            $out['migration.created.assets'] = (int) (new Query())
                ->from('{{%kunstmaanmigrator_state}}')
                ->where(['source' => 'media', 'targetType' => 'asset'])
                ->count();

            $out['migration.created.redirects'] = (int) (new Query())
                ->from('{{%kunstmaanmigrator_state}}')
                ->where(['source' => 'redirect'])
                ->count();

            $out['migration.created.seo'] = (int) (new Query())
                ->from('{{%kunstmaanmigrator_state}}')
                ->where(['source' => 'seo_meta'])
                ->count();
        } catch (Throwable) {
            foreach ($out as $key => $_) {
                $out[$key] = -1;
            }
        }

        return $out;
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
