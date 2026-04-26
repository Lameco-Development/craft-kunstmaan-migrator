<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\console;

use Craft;
use craft\console\Controller;
use craft\helpers\App;
use craft\helpers\Console;
use lameco\kunstmaanmigrator\NeverProductionTrait;
use lameco\kunstmaanmigrator\Plugin;
use lameco\kunstmaanmigrator\locale\LocalePreflight;
use Throwable;
use yii\console\ExitCode;

/**
 * Doctor — preflight diagnostics for the migrator. Eight checks (D-17 + Phase 2 / Plan 05 + Phase 02.1 / D-31 + Phase 3 / Plan 03-13 + Phase 4 / Plan 04-11 / D-69):
 *   1. Legacy DB reachability (SELECT 1)
 *   2. Anthropic key presence (Settings override OR env; never echoes the value — T-1-03)
 *   3. storage/migration/ writable (auto-creates if missing — D-18 greenfield behavior)
 *   4. mapping.yaml health (deferred from Phase 1 / D-17 — landed alongside MappingFile in Phase 2)
 *   5. Kunstmaan source path (D-31 — KUNSTMAAN_SOURCE_PATH env or kunstmaanSourcePath Settings)
 *   6. State table reachability (Phase 3 / CONTEXT Discretion — catches Phase 1 install drift before migrate runs)
 *   7. Adapter plugin health (D-69 — SEOmatic + Retour optional, INFO on absence per ADP-01..03)
 *   8. Verify baseline presence (D-69 — INFO when storage/migration/baseline.json missing)
 *   9. Kunstmaan .env source presence (Phase 4.1 / D-10 — INFO/OK; never FAILs.
 *      Reports .env / .env.example presence + which whitelisted keys (DATABASE_URL,
 *      DEFAULT_LOCALE) were found + DSN-host vs Settings::legacyDbServer mismatch.)
 *  10. LocalePreflight Rung 0 advisory consult (Phase 4.1 / D-11..D-13 — INFO/WARN/silent;
 *      never FAILs. Compares env DEFAULT_LOCALE against Settings::localeMap first key.
 *      Silent when env unset (D-13); WARN on mismatch (D-12 verbatim copy).)
 *
 * Drops from v1: checkQueueWorker (PROJECT.md Key Decisions — v2 is CLI-inline).
 *
 * FILT-03: declares --entities / --locales / --since for command-surface uniformity but
 * ignores them — doctor doesn't read legacy data.
 */
class DoctorController extends Controller
{
    use NeverProductionTrait;

    // FILT-03: doctor accepts the three filter flags for command-surface uniformity but
    // ignores them — doctor doesn't read legacy data, so filters are a no-op here.
    public ?string $entities = null;
    public ?string $locales  = null;
    public ?string $since    = null;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['entities', 'locales', 'since']);
    }

    public function actionIndex(): int
    {
        // FND-04 / D-20: NeverProduction guard FIRST — before any check runs.
        if (($gate = $this->enforceNeverProduction()) !== null) {
            return $gate;
        }

        $this->stdout("Doctor: preflight diagnostics\n", Console::FG_CYAN);

        // `&&` against $ok so every check still executes even after a failure;
        // operators want the full report, not a short-circuited tail.
        $ok = true;
        $ok = $this->checkLegacyDb()             && $ok;
        $ok = $this->checkApiKey()               && $ok;
        $ok = $this->checkStorageDir()           && $ok;
        $ok = $this->checkMappingFile()          && $ok;
        $ok = $this->checkKunstmaanSourcePath()  && $ok;
        $ok = $this->checkStateTable()           && $ok;
        // Phase 4 extensions — D-69. Both always return true (INFO not FAIL):
        $ok = $this->checkAdapterPlugins()       && $ok;
        $ok = $this->checkVerifyBaseline()       && $ok;
        // Phase 4.1 / D-10 (9th — info only, never blocks):
        $ok = $this->checkKunstmaanEnvSource()   && $ok;
        // Phase 4.1 / D-11..D-13 (10th — info/warn/silent; never blocks):
        $ok = $this->checkLocalePreflightRung0() && $ok;

        $this->stdout(
            "\n" . ($ok ? "Doctor: PASS\n" : "Doctor: FAIL — fix the above before running migrate\n"),
            $ok ? Console::FG_GREEN : Console::FG_RED,
        );

        return $ok ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Check #1: legacy DB reachable AND a default schema is selected.
     *
     * `SELECT 1` only proves the connection opens (host/port/user/pass valid).
     * Every downstream stage (LocalePreflight, SchemaDumper, ...) issues
     * unqualified queries that need a default schema, so we also verify
     * `SELECT DATABASE()` is non-null. Catches the case where
     * `CRAFT_LEGACY_DB_DATABASE` is unset and the DSN ends with `dbname=`.
     */
    private function checkLegacyDb(): bool
    {
        try {
            $svc = Plugin::getInstance()->legacyDbService;
            $svc->queryOne('SELECT 1 AS ok');
            $dbname = $svc->queryScalar('SELECT DATABASE()');
            if ($dbname === null || $dbname === '') {
                $this->stderr(
                    "  FAIL legacyDb connected but no default schema selected — "
                    . "set CRAFT_LEGACY_DB_DATABASE in .env (or legacyDbDatabase in plugin settings)\n",
                    Console::FG_RED,
                );
                return false;
            }
            $this->stdout("  OK   legacyDb reachable\n", Console::FG_GREEN);
            return true;
        } catch (Throwable $e) {
            // Connection error message may include host/port; we let it through because the
            // operator needs to see WHAT broke. We do NOT echo password / DSN — Yii's
            // Connection exception messages do not include credentials by default.
            $this->stderr("  FAIL legacyDb unreachable: {$e->getMessage()}\n", Console::FG_RED);
            return false;
        }
    }

    /**
     * Check #2: Anthropic key presence — Settings override OR env (D-14).
     * T-1-03 mitigation: report PRESENCE only. Never echo or log the value.
     */
    private function checkApiKey(): bool
    {
        $fromSettings = (string) (Plugin::getInstance()->getSettings()->anthropicApiKey ?? '');
        $fromEnv      = (string) (App::env('ANTHROPIC_API_KEY') ?? '');
        $hasKey = $fromSettings !== '' || $fromEnv !== '';

        if ($hasKey) {
            $this->stdout("  OK   ANTHROPIC_API_KEY set\n", Console::FG_GREEN);
            return true;
        }
        $this->stderr(
            "  FAIL ANTHROPIC_API_KEY missing — set in .env or plugin Settings (analyze will fail without it).\n",
            Console::FG_RED,
        );
        return false;
    }

    /**
     * Check #3: storage/migration/ exists and is writable.
     * D-18: auto-create the directory under Craft's storage tree (one less manual op step).
     * Side-effecting, but only creates a known-good directory under storage/.
     */
    private function checkStorageDir(): bool
    {
        // T-1-04 mitigation: path is constrained to Craft's storage tree — no user input
        // feeds the path; Craft owns getStoragePath() and we append a fixed literal.
        $dir = Craft::$app->path->getStoragePath() . '/migration';
        try {
            if (!is_dir($dir)) {
                if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
                    $this->stderr("  FAIL could not create {$dir}\n", Console::FG_RED);
                    return false;
                }
            }
            if (!is_writable($dir)) {
                $this->stderr("  FAIL {$dir} not writable\n", Console::FG_RED);
                return false;
            }
            $this->stdout("  OK   storage/migration writable ({$dir})\n", Console::FG_GREEN);
            return true;
        } catch (Throwable $e) {
            $this->stderr("  FAIL storage check error: {$e->getMessage()}\n", Console::FG_RED);
            return false;
        }
    }

    /**
     * Check #4 (deferred from Phase 1 / D-17): mapping.yaml health.
     *
     * Soft-warn on missing file (analyze creates it, not doctor); hard-fail on parse error
     * or missing top-level `proposals:` key.
     */
    private function checkMappingFile(): bool
    {
        $path = Plugin::getInstance()->mappingFile->resolvePath();
        try {
            if (!is_file($path)) {
                $this->stdout("  WARN mapping.yaml not found at {$path} (run analyze first)\n", Console::FG_YELLOW);
                return true; // WARN-only — file is created by analyze, not by doctor
            }
            $parsed = \Symfony\Component\Yaml\Yaml::parseFile($path);
            if (!is_array($parsed) || !array_key_exists('proposals', $parsed)) {
                $this->stderr("  FAIL mapping.yaml at {$path} missing top-level 'proposals:' key\n", Console::FG_RED);
                return false;
            }
            $rowCount = is_array($parsed['proposals']) ? count($parsed['proposals']) : 0;
            $this->stdout("  OK   mapping.yaml at {$path} ({$rowCount} rows)\n", Console::FG_GREEN);
            return true;
        } catch (Throwable $e) {
            $this->stderr("  FAIL mapping.yaml parse error: {$e->getMessage()}\n", Console::FG_RED);
            return false;
        }
    }

    /**
     * Check #5 (Phase 02.1 / D-31): Kunstmaan source path resolves and contains src/Entity/.
     *
     * FAIL when KUNSTMAAN_SOURCE_PATH is unset or invalid — analyze cannot proceed
     * (greenfield-fallback dropped per D-31). WARN when config/kunstmaancms/pageparts/
     * is absent (PHP-only fallback for KunstmaanPageStructureScanner is still viable —
     * D-31 Discretion).
     */
    private function checkKunstmaanSourcePath(): bool
    {
        $resolver = Plugin::getInstance()->kunstmaanSourcePathResolver;
        $path = $resolver->resolve();
        if ($path === null) {
            $this->stderr(
                "  FAIL KUNSTMAAN_SOURCE_PATH unset or invalid — analyze cannot proceed.\n"
                . "       Set KUNSTMAAN_SOURCE_PATH in .env (or kunstmaanSourcePath in plugin settings).\n"
                . "       Path must exist, be readable, and contain src/Entity/.\n",
                Console::FG_RED,
            );
            return false;
        }
        // YAML config absence is WARN-only (D-31 Discretion locked in Plan 01):
        // KunstmaanPageStructureScanner falls back to PHP-only scan via
        // getPagePartAdminConfigurations() when the YAML dir is missing.
        if (!is_dir($path . '/config/kunstmaancms/pageparts')) {
            $this->stdout(
                "  WARN config/kunstmaancms/pageparts/ not found at {$path} — falling back to PHP-only page-part scan\n",
                Console::FG_YELLOW,
            );
        }
        $this->stdout("  OK   Kunstmaan source path → {$path}\n", Console::FG_GREEN);
        return true;
    }

    /**
     * Check #6 (Phase 3 / Plan 03-13 — CONTEXT Discretion): state-table reachability.
     *
     * Catches the case where Phase 1 install drifted (table missing or schema-incompatible)
     * before migrate runs. Cheap, deterministic. FAIL when missing — operator must run
     * `./craft kunstmaan-migrator/migrate/install` to recreate.
     */
    private function checkStateTable(): bool
    {
        try {
            $tableName = '{{%kunstmaanmigrator_state}}';
            if (!Craft::$app->db->getTableSchema($tableName)) {
                $this->stderr(
                    "  FAIL state table '{$tableName}' missing — run "
                    . "`./craft kunstmaan-migrator/migrate/install` first.\n",
                    Console::FG_RED,
                );
                return false;
            }
            // Probe reachability with a no-op SELECT against the table.
            Craft::$app->db->createCommand("SELECT COUNT(*) FROM {$tableName}")->queryScalar();
            $this->stdout("  OK   kunstmaanmigrator_state table reachable\n", Console::FG_GREEN);
            return true;
        } catch (Throwable $e) {
            $this->stderr("  FAIL state table check: {$e->getMessage()}\n", Console::FG_RED);
            return false;
        }
    }

    /**
     * Check #7 (D-69): adapter plugin presence — informational only.
     * SEOmatic + Retour are optional per ADP-01..03; absence is not a FAIL.
     */
    private function checkAdapterPlugins(): bool
    {
        $seomatic = Craft::$app->plugins->getPlugin('seomatic');
        if ($seomatic !== null) {
            $version = (string) $seomatic->getVersion();
            $this->stdout("  OK   seomatic v{$version} installed\n", Console::FG_GREEN);
        } else {
            $this->stdout("  INFO seomatic not installed (adapter will skip)\n", Console::FG_YELLOW);
        }

        $retour = Craft::$app->plugins->getPlugin('retour');
        if ($retour !== null) {
            $version = (string) $retour->getVersion();
            $this->stdout("  OK   retour v{$version} installed\n", Console::FG_GREEN);
        } else {
            $this->stdout("  INFO retour not installed (adapter will skip)\n", Console::FG_YELLOW);
        }
        return true; // D-69: always OK — adapter absence is informational.
    }

    /**
     * Check #8 (D-69): verify baseline presence — informational only.
     * Operators may run doctor before capturing baseline.
     *
     * Phase 4.1 / D-30 — escalates from INFO/OK to WARN when baseline.json's
     * captured filterScope mismatches the current run's --entity / --locale /
     * --since invocation. Silent when baseline carries no filterScope (legacy
     * captures pre-Phase-4.1) and when the captured scope matches. NEVER FAILs
     * — D-69 invariant preserved.
     */
    private function checkVerifyBaseline(): bool
    {
        $path = Craft::$app->path->getStoragePath() . '/migration/baseline.json';
        if (!is_file($path)) {
            $this->stdout(
                "  INFO baseline.json missing — run `verify capture-baseline` first if you want to gate migrate runs.\n",
                Console::FG_YELLOW,
            );
            return true; // D-69: always OK
        }

        $this->stdout("  OK   baseline.json present at {$path}\n", Console::FG_GREEN);

        // Phase 4.1 / D-30 — filter-scope WARN escalation.
        // T-04.1-05-08 mitigation: malformed JSON falls through to 'no-scope'
        // (silent) rather than throwing.
        $raw = @file_get_contents($path);
        $decoded = $raw !== false ? json_decode($raw, true) : null;
        $captured = is_array($decoded) && isset($decoded['filterScope']) && is_array($decoded['filterScope'])
            ? $decoded['filterScope']
            : null;

        $current = [
            'entities' => $this->parseCsvList($this->entities),
            'locales'  => $this->parseCsvList($this->locales),
            'since'    => ($this->since !== null && $this->since !== '') ? $this->since : null,
        ];

        $cmp = self::compareBaselineFilterScope($captured, $current);

        switch ($cmp['status']) {
            case 'no-scope':
                // D-30: captured baseline has no filterScope (pre-Phase-4.1 capture or
                // baseline written without filters) — silent; no extra row.
                return true;
            case 'matches':
                // D-30: captured scope matches current run's scope — silent; no extra row.
                return true;
            case 'mismatch':
                // D-30: verbatim copy — DO NOT paraphrase.
                $this->stdout(sprintf(
                    "  WARN filter-scope mismatch — baseline was captured with `%s`, current run is `%s`. Re-run capture-baseline or re-run verify with matching filters.\n",
                    $cmp['capturedSummary'],
                    $cmp['currentSummary'],
                ), Console::FG_YELLOW);
                return true; // D-30 + D-69: WARN, never FAIL.
        }
        return true;
    }

    /**
     * Phase 4.1 / D-30 — pure helper. Returns one of:
     *   ['status' => 'no-scope', 'capturedSummary' => '', 'currentSummary' => '']
     *   ['status' => 'matches',  'capturedSummary' => string, 'currentSummary' => string]
     *   ['status' => 'mismatch', 'capturedSummary' => string, 'currentSummary' => string]
     *
     * Mismatch is detected on any of:
     *   - entities array set-difference (order-independent)
     *   - locales  array set-difference (order-independent)
     *   - since    string strict equality (===)
     *
     * Public-static so the test file (Phase 4.1 / Plan 04.1-05 / Task 4) can
     * call it directly without Reflection. Mirrors
     * LocalePreflight::compareEnvDefaultLocaleToLocaleMap from Plan 04.1-03.
     *
     * @param array<string, mixed>|null $captured baseline.json's filterScope header (null when absent)
     * @param array<string, mixed>|null $current  current run's filter scope
     * @return array{status: string, capturedSummary: string, currentSummary: string}
     */
    public static function compareBaselineFilterScope(?array $captured, ?array $current): array
    {
        if ($captured === null) {
            return ['status' => 'no-scope', 'capturedSummary' => '', 'currentSummary' => ''];
        }
        $current = $current ?? ['entities' => [], 'locales' => [], 'since' => null];

        $capturedEntities = self::normalizeList($captured['entities'] ?? []);
        $currentEntities  = self::normalizeList($current['entities']  ?? []);
        $capturedLocales  = self::normalizeList($captured['locales']  ?? []);
        $currentLocales   = self::normalizeList($current['locales']   ?? []);
        $capturedSince    = is_string($captured['since'] ?? null) && $captured['since'] !== '' ? (string) $captured['since'] : null;
        $currentSince     = is_string($current['since']  ?? null) && $current['since']  !== '' ? (string) $current['since']  : null;

        $matches = $capturedEntities === $currentEntities
            && $capturedLocales === $currentLocales
            && $capturedSince === $currentSince;

        $summary = static fn(array $entities, array $locales, ?string $since): string => sprintf(
            'entities=%s; locales=%s; since=%s',
            $entities === [] ? 'all' : implode(',', $entities),
            $locales  === [] ? 'all' : implode(',', $locales),
            $since ?? 'none',
        );

        return [
            'status'          => $matches ? 'matches' : 'mismatch',
            'capturedSummary' => $summary($capturedEntities, $capturedLocales, $capturedSince),
            'currentSummary'  => $summary($currentEntities, $currentLocales, $currentSince),
        ];
    }

    /**
     * Phase 4.1 / D-30 — set-equality normalisation: trim, drop empties, sort.
     * Order-independent comparison; ['blogPosts','events'] ≡ ['events','blogPosts'].
     *
     * @return list<string>
     */
    private static function normalizeList(mixed $v): array
    {
        if (!is_array($v)) {
            return [];
        }
        $out = [];
        foreach ($v as $x) {
            if (is_string($x)) {
                $trimmed = trim($x);
                if ($trimmed !== '') {
                    $out[] = $trimmed;
                }
            }
        }
        sort($out, SORT_STRING);
        return $out;
    }

    /**
     * Phase 4.1 / D-30 — CSV → list<string> for the doctor's --entities /
     * --locales flags (which mirror MigrateController's flag shape).
     *
     * @return list<string>
     */
    private function parseCsvList(?string $csv): array
    {
        if ($csv === null || $csv === '') {
            return [];
        }
        return array_values(array_filter(
            array_map('trim', explode(',', $csv)),
            static fn(string $s): bool => $s !== '',
        ));
    }

    /**
     * Check #9 (Phase 4.1 / D-10): Kunstmaan .env source presence + whitelisted keys.
     *
     * INFO-only, never FAILs. Reports:
     *   - whether .env / .env.example exist at the resolved source path
     *   - which whitelisted keys (DATABASE_URL, DEFAULT_LOCALE) were found
     *   - if DATABASE_URL parsed: whether the parsed host differs from
     *     Settings::legacyDbServer (operator-supplied value wins per D-07)
     *
     * T-04.1-01-04 mitigation: lists FILE NAMES and HOST only — never
     * passwords or full DSN. Operator can verify by reading the
     * implementation — only the host accessor is consulted.
     */
    private function checkKunstmaanEnvSource(): bool
    {
        $plugin = Plugin::getInstance();
        $reader = $plugin->kunstmaanEnvReader;
        $sourcePath = $plugin->kunstmaanSourcePathResolver->resolve();
        if ($sourcePath === null) {
            $this->stdout("  INFO Kunstmaan source path unset — env reader inert\n", Console::FG_YELLOW);
            return true;
        }

        $envExample = is_file($sourcePath . '/.env.example') ? '.env.example' : null;
        $env        = is_file($sourcePath . '/.env')         ? '.env'         : null;
        $found = array_values(array_filter([$envExample, $env]));
        if ($found === []) {
            $this->stdout("  INFO no .env / .env.example at {$sourcePath}\n", Console::FG_YELLOW);
            return true;
        }
        $this->stdout(sprintf("  OK   Kunstmaan env source: %s\n", implode(' + ', $found)), Console::FG_GREEN);

        $dsn = $reader->getDatabaseUrl();
        if ($dsn !== null && $dsn !== '') {
            $parsedHost = $reader->getDsnHost();
            $settingsHost = (string) ($plugin->getSettings()->legacyDbServer ?? '');
            if ($parsedHost !== null && $settingsHost !== '' && $parsedHost !== $settingsHost) {
                $this->stdout(
                    sprintf(
                        "  INFO DATABASE_URL host=%s differs from Settings::legacyDbServer=%s (operator value wins)\n",
                        $parsedHost,
                        $settingsHost,
                    ),
                    Console::FG_YELLOW,
                );
            } else {
                $this->stdout("  OK   DATABASE_URL parsed\n", Console::FG_GREEN);
            }
        } else {
            $this->stdout("  INFO DATABASE_URL not set in .env/.env.example\n", Console::FG_YELLOW);
        }

        $defaultLocale = $reader->getDefaultLocale();
        if ($defaultLocale !== null && $defaultLocale !== '') {
            $this->stdout(
                sprintf("  OK   DEFAULT_LOCALE found: %s\n", $defaultLocale),
                Console::FG_GREEN,
            );
        } else {
            $this->stdout("  INFO DEFAULT_LOCALE not set in .env/.env.example\n", Console::FG_YELLOW);
        }

        return true; // D-10: info only — never blocks.
    }

    /**
     * Check #10 (Phase 4.1 / D-11..D-13): LocalePreflight Rung 0 advisory consult.
     *
     * Compares the Kunstmaan project's env DEFAULT_LOCALE against the first key
     * of Settings::localeMap. Three outcomes:
     *   - env null/blank        → silent (D-13: no row)
     *   - env present, no map   → INFO (operator hasn't curated localeMap yet)
     *   - env matches firstKey  → OK
     *   - env differs           → WARN with verbatim D-12 copy. NEVER FAIL.
     *
     * Per D-11 the existing 3-rung matching ladder in LocalePreflight::resolve()
     * is unchanged — Rung 0 is purely advisory at this doctor seam. Per D-12
     * NL-default Kunstmaan migrating into an EN-primary Craft instance is a
     * real and intentional pattern; we do not second-guess the operator.
     *
     * T-04.1-03-01 mitigation: row content is locale codes only (e.g. "nl") —
     * no file paths, no DSNs, no secrets.
     */
    private function checkLocalePreflightRung0(): bool
    {
        $plugin = Plugin::getInstance();
        $envLocale = $plugin->kunstmaanEnvReader->getDefaultLocale();
        $localeMap = (array) $plugin->getSettings()->localeMap;
        $result = LocalePreflight::compareEnvDefaultLocaleToLocaleMap($envLocale, $localeMap);

        switch ($result['status']) {
            case 'silent':
                return true; // D-13: no row when env signal absent.
            case 'no-map':
                $this->stdout(
                    sprintf("  INFO DEFAULT_LOCALE=%s but no localeMap configured\n", $result['envLocale']),
                    Console::FG_YELLOW,
                );
                return true;
            case 'ok':
                $this->stdout(
                    sprintf("  OK   DEFAULT_LOCALE=%s aligns with localeMap[0]\n", $result['envLocale']),
                    Console::FG_GREEN,
                );
                return true;
            case 'warn':
                // D-12: verbatim WARN copy — DO NOT paraphrase.
                $this->stdout(
                    sprintf(
                        "  WARN Kunstmaan default locale `%s` but operator's `localeMap` lists `%s` first (Craft-primary). Reorder localeMap or confirm intent.\n",
                        $result['envLocale'],
                        $result['firstHandle'],
                    ),
                    Console::FG_YELLOW,
                );
                return true; // D-12: WARN, never FAIL.
        }
        return true;
    }
}
