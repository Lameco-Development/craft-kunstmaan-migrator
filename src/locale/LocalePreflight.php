<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\locale;

use Craft;
use lameco\kunstmaanmigrator\Plugin;
use lameco\kunstmaanmigrator\filter\MigrationFilters;
use yii\base\Component;

/**
 * Locale detection (LOC-01) + preflight gate (LOC-02).
 *
 * Detection: SELECT DISTINCT lang FROM kuma_node_translations.
 * Preflight: every detected locale must be either a Craft site handle OR present in
 * Settings::defaultLocales. If --locales is explicitly set, the check is scoped to
 * that subset (operator-scoped run).
 *
 * Per CONTEXT.md D-17: NO silent default-locale fallthrough. If any detected locale
 * is unmapped, ensure() returns the list — caller MUST hard-FAIL.
 */
final class LocalePreflight extends Component
{
    /**
     * Distinct locale codes present in the legacy DB (kuma_node_translations.lang).
     *
     * @return list<string>
     */
    public function detect(): array
    {
        $rows = Plugin::getInstance()->legacyDbService->queryAll(
            'SELECT DISTINCT lang FROM kuma_node_translations ORDER BY lang',
        );
        $out = [];
        foreach ($rows as $r) {
            $lang = (string) ($r['lang'] ?? '');
            if ($lang !== '') {
                $out[] = $lang;
            }
        }
        return $out;
    }

    /**
     * Returns null on pass, or list of unmapped-locale strings on fail.
     *
     * Caller (AnalyzeController / MapController / future MigrateController / VerifyController)
     * is responsible for hard-failing on a non-null return per LOC-02 D-17.
     *
     * @return list<string>|null
     */
    public function ensure(MigrationFilters $filters): ?array
    {
        $detected = $this->detect();

        $craftHandles = [];
        foreach (Craft::$app->getSites()->getAllSites() as $s) {
            $craftHandles[] = (string) $s->handle;
        }
        $settingsLocales = array_values((array) Plugin::getInstance()->getSettings()->defaultLocales);

        // If --locales explicitly set, scope check to that subset (operator-scoped run).
        $checkSet = $filters->locales !== [] ? $filters->locales : $detected;

        $unmapped = [];
        foreach ($checkSet as $locale) {
            if (!in_array($locale, $craftHandles, true) && !in_array($locale, $settingsLocales, true)) {
                $unmapped[] = $locale;
            }
        }
        return $unmapped === [] ? null : array_values($unmapped);
    }
}
