<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\console;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use lameco\kunstmaanmigrator\NeverProductionTrait;
use lameco\kunstmaanmigrator\Plugin;
use lameco\kunstmaanmigrator\db\KunstmaanCoreTables;
use Throwable;
use yii\console\ExitCode;

/**
 * Doctor — preflight diagnostics for the migrator. v2 loader prune: the
 * analyze/mapping/locale-detection checks (Anthropic key, mapping.yaml health,
 * Kunstmaan source path, .env source presence, LocalePreflight Rung 0) are
 * removed along with their backing classes. Six checks remain (D-17 + Phase 2 /
 * Plan 05 + Phase 3 / Plan 03-13 + Phase 4 / Plan 04-11 / D-69 + Phase 8 / Plan
 * 08-14 / D-09):
 *   1. Legacy DB reachability (SELECT 1)
 *   2. storage/migration/ writable (auto-creates if missing — D-18 greenfield behavior)
 *   3. State table reachability (Phase 3 / CONTEXT Discretion — catches Phase 1 install drift before migrate runs)
 *   4. Adapter plugin health (D-69 — SEOmatic + Retour optional, INFO on absence per ADP-01..03)
 *   5. Verify baseline presence (D-69 — INFO when storage/migration/baseline.json missing)
 *   6. ext_translations presence (Phase 8 / Plan 08-14 / TAX-09 / D-09 — INFO/WARN/OK;
 *      never FAILs. Empty table → WARN (Gedmo overlay falls back to source-locale-only
 *      per D-09's pragmatic monolingual-Kunstmaan default); missing table → INFO (Gedmo
 *      Translatable absent); populated → OK with row count.)
 *
 * Drops from v1: checkQueueWorker (PROJECT.md Key Decisions — v2 is CLI-inline).
 *
 * FILT-03: declares --entities / --locales / --since for command-surface uniformity but
 * ignores them — doctor doesn't read legacy data.
 */
class DoctorController extends Controller
{
    use NeverProductionTrait;

    // FILT-03 + Phase 4.1 / D-30: doctor accepts the three filter flags for
    // command-surface uniformity. Phase 4 made these no-ops (doctor doesn't
    // read legacy data); Phase 4.1 / D-30 makes them load-bearing for the 8th
    // check (`checkVerifyBaseline`) — when the operator passes
    // `--entities=...` / `--locales=...` / `--since=...`, those values are
    // compared against baseline.json's captured `filterScope` JSON header and
    // any mismatch escalates the row from OK to WARN with verbatim D-30 copy.
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
        $ok = $this->checkStorageDir()           && $ok;
        $ok = $this->checkStateTable()           && $ok;
        // Phase 4 extensions — D-69. Both always return true (INFO not FAIL):
        $ok = $this->checkAdapterPlugins()       && $ok;
        $ok = $this->checkVerifyBaseline()       && $ok;
        // Phase 8 / Plan 08-14 / TAX-09 / D-09 (info/warn/ok; never blocks):
        $ok = $this->checkExtTranslations()      && $ok;

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
     * Every downstream load-side query issues unqualified SQL that needs a
     * default schema, so we also verify
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
     * Check #2: storage/migration/ exists and is writable.
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
     * Check #3 (Phase 3 / Plan 03-13 — CONTEXT Discretion): state-table reachability.
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
     * Check #4 (D-69): adapter plugin presence — informational only.
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
     * Check #5 (D-69): verify baseline presence — informational only.
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
     * Check #6 (Phase 8 / Plan 08-14 / TAX-09 / D-09): ext_translations presence.
     *
     * D-09 mandate: WARN-only when empty (NEVER FAIL). The Gedmo Translatable
     * table is optional in Kunstmaan deployments — many sites are monolingual,
     * which Phase 8 treats as a first-class default rather than an error. The
     * three branches (each returns true):
     *
     *   - Populated → OK with row count.
     *   - Empty     → WARN: Gedmo Translatable taxonomy migration will fall back
     *                 to source-locale-only (D-09 monolingual-Kunstmaan default).
     *   - Missing   → INFO: table not present in legacy DB (Gedmo Translatable
     *                 not installed at all). Detected via Throwable on the count
     *                 query (table-not-found → SQLSTATE[42S02]).
     *
     * Surfaces the operator-visible signal that a monolingual-fallback path
     * would be taken by any taxonomy-relation load logic (Plan 08-11).
     */
    private function checkExtTranslations(): bool
    {
        try {
            $svc = Plugin::getInstance()->legacyDbService;
            $count = (int) $svc->queryScalar(
                'SELECT COUNT(*) FROM ' . KunstmaanCoreTables::EXT_TRANSLATIONS,
            );
            if ($count === 0) {
                $this->stdout(
                    "  WARN ext_translations is empty — Gedmo Translatable taxonomy migration will fall back "
                    . "to source-locale-only (D-09 monolingual-Kunstmaan default).\n",
                    Console::FG_YELLOW,
                );
                return true;
            }
            $this->stdout(
                sprintf("  OK   ext_translations populated (%d rows)\n", $count),
                Console::FG_GREEN,
            );
            return true;
        } catch (Throwable) {
            $this->stdout(
                "  INFO ext_translations table not present in legacy DB — Gedmo Translatable absent\n",
                Console::FG_YELLOW,
            );
            return true;
        }
    }
}
