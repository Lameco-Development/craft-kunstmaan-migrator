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
 * removed along with their backing classes. The verify-baseline check is also
 * removed (the `src/verify/` subsystem and its `capture-baseline` command are
 * gone). Five checks remain (D-17 + Phase 2 / Plan 05 + Phase 3 / Plan 03-13 +
 * Phase 8 / Plan 08-14 / D-09):
 *   1. Legacy DB reachability (SELECT 1)
 *   2. storage/migration/ writable (auto-creates if missing — D-18 greenfield behavior)
 *   3. State table reachability (Phase 3 / CONTEXT Discretion — catches Phase 1 install drift before migrate runs)
 *   4. Adapter plugin health (D-69 — SEOmatic + Retour optional, INFO on absence per ADP-01..03)
 *   5. ext_translations presence (Phase 8 / Plan 08-14 / TAX-09 / D-09 — INFO/WARN/OK;
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

    // FILT-03: doctor accepts the three filter flags for command-surface
    // uniformity with the other console commands. They are no-ops here —
    // doctor doesn't read legacy data. (The Phase 4.1 / D-30 verify-baseline
    // check that once made them load-bearing was removed in the v2 prune
    // along with the `src/verify/` subsystem it gated.)
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
     * Check #5 (Phase 8 / Plan 08-14 / TAX-09 / D-09): ext_translations presence.
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
