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
 *
 * Matching ladder (first hit wins):
 *   1. Settings::localeMap[$legacy] → explicit operator override (strongest)
 *   2. Exact match against Craft site handles + Settings::defaultLocales
 *   3. Language-prefix loose match — split on `-`/`_`, compare prefixes
 *      (so legacy `nl` matches Craft handle `nl-NL`, and legacy `de_AT`
 *      matches Craft handle `de-AT`).
 *
 * Per CONTEXT.md D-17: NO silent default-locale fallthrough. If any detected
 * locale matches none of the three rungs, ensure() returns the list — caller
 * MUST hard-FAIL.
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
     * Resolve every detected legacy locale through the matching ladder.
     *
     * @param list<string> $detected
     * @return array<string, array{matched: bool, target: ?string, via: string}>
     *   Map of legacy locale → resolution detail. `via` is one of
     *   `localeMap`, `exact`, `prefix`, or `none`.
     */
    public function resolve(array $detected): array
    {
        $craftHandles    = $this->craftSiteHandles();
        $settingsLocales = array_values((array) Plugin::getInstance()->getSettings()->defaultLocales);
        /** @var array<string, string> $localeMap */
        $localeMap       = (array) Plugin::getInstance()->getSettings()->localeMap;
        $mapped          = array_values(array_unique(array_merge($craftHandles, $settingsLocales)));

        $out = [];
        foreach ($detected as $legacy) {
            // Rung 1: explicit override.
            if (isset($localeMap[$legacy]) && $localeMap[$legacy] !== '') {
                $out[$legacy] = ['matched' => true, 'target' => (string) $localeMap[$legacy], 'via' => 'localeMap'];
                continue;
            }
            // Rung 2: exact match against handles + defaultLocales.
            if (in_array($legacy, $mapped, true)) {
                $out[$legacy] = ['matched' => true, 'target' => $legacy, 'via' => 'exact'];
                continue;
            }
            // Rung 3: language-prefix match (split on `-` or `_`).
            $legacyPrefix = self::languagePrefix($legacy);
            $prefixHit = null;
            foreach ($mapped as $candidate) {
                if (self::languagePrefix($candidate) === $legacyPrefix) {
                    $prefixHit = $candidate;
                    break;
                }
            }
            if ($prefixHit !== null) {
                $out[$legacy] = ['matched' => true, 'target' => $prefixHit, 'via' => 'prefix'];
                continue;
            }
            $out[$legacy] = ['matched' => false, 'target' => null, 'via' => 'none'];
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
        $resolved = $this->resolve($detected);

        // If --locales explicitly set, scope check to that subset (operator-scoped run).
        $checkSet = $filters->locales !== [] ? $filters->locales : $detected;

        $unmapped = [];
        foreach ($checkSet as $locale) {
            $detail = $resolved[$locale] ?? ['matched' => false];
            if (!$detail['matched']) {
                $unmapped[] = $locale;
            }
        }
        return $unmapped === [] ? null : array_values($unmapped);
    }

    /**
     * Lower-cased BCP 47 / POSIX locale primary subtag — the substring before
     * the first `-` or `_`. `nl-NL` → `nl`; `de_AT` → `de`; `en` → `en`.
     */
    public static function languagePrefix(string $locale): string
    {
        $lower = strtolower($locale);
        $cut   = strcspn($lower, '-_');
        return substr($lower, 0, $cut);
    }

    /**
     * Phase 4.1 / D-11..D-13 — Rung 0 advisory comparison.
     *
     * Pure helper; no I/O. Used by DoctorController::checkLocalePreflightRung0().
     * Rung 0 is purely advisory at the doctor seam — the existing 3-rung
     * matching ladder in resolve() is unchanged. Compares the Kunstmaan
     * project's env DEFAULT_LOCALE against the first key of Settings::localeMap
     * (legacy locale code) and reports drift between the operator's primary
     * locale intent and the Kunstmaan source-of-truth.
     *
     * Returns one of:
     *   ['status' => 'silent', 'envLocale' => null, 'firstHandle' => null]
     *     — env signal absent (D-13: no doctor row)
     *   ['status' => 'no-map', 'envLocale' => string, 'firstHandle' => null]
     *     — env present but localeMap empty (caller emits INFO row)
     *   ['status' => 'ok',     'envLocale' => string, 'firstHandle' => string]
     *     — env matches first localeMap key
     *   ['status' => 'warn',   'envLocale' => string, 'firstHandle' => string]
     *     — env mismatches first localeMap key (D-12: WARN, NEVER FAIL)
     *
     * @param array<string, mixed> $localeMap
     * @return array{status: string, envLocale: ?string, firstHandle: ?string}
     */
    public static function compareEnvDefaultLocaleToLocaleMap(
        ?string $envLocale,
        array $localeMap,
    ): array {
        if ($envLocale === null || $envLocale === '') {
            return ['status' => 'silent', 'envLocale' => null, 'firstHandle' => null];
        }
        if ($localeMap === []) {
            return ['status' => 'no-map', 'envLocale' => $envLocale, 'firstHandle' => null];
        }
        $firstHandle = (string) array_key_first($localeMap);
        $status = ($firstHandle === $envLocale) ? 'ok' : 'warn';
        return ['status' => $status, 'envLocale' => $envLocale, 'firstHandle' => $firstHandle];
    }

    /**
     * Locale-relevant strings exposed by every Craft site: both the handle
     * (slug-style identifier) AND the language (BCP 47 code, e.g. `nl-NL`).
     * Either may match a legacy Kunstmaan locale; collecting both lets the
     * exact + prefix rungs find a hit when only one of them aligns.
     *
     * @return list<string>
     */
    private function craftSiteHandles(): array
    {
        $out = [];
        foreach (Craft::$app->getSites()->getAllSites() as $s) {
            $handle   = (string) $s->handle;
            $language = (string) $s->language;
            if ($handle !== '')   { $out[] = $handle; }
            if ($language !== '' && $language !== $handle) { $out[] = $language; }
        }
        return array_values(array_unique($out));
    }
}
