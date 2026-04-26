<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\console;

use Craft;
use craft\console\Controller;
use craft\helpers\App;
use craft\helpers\Console;
use lameco\kunstmaanmigrator\NeverProductionTrait;
use lameco\kunstmaanmigrator\Plugin;
use Throwable;
use yii\console\ExitCode;

/**
 * Doctor — preflight diagnostics for the migrator. Five checks (D-17 + Phase 2 / Plan 05 + Phase 02.1 / D-31):
 *   1. Legacy DB reachability (SELECT 1)
 *   2. Anthropic key presence (Settings override OR env; never echoes the value — T-1-03)
 *   3. storage/migration/ writable (auto-creates if missing — D-18 greenfield behavior)
 *   4. mapping.yaml health (deferred from Phase 1 / D-17 — landed alongside MappingFile in Phase 2)
 *   5. Kunstmaan source path (D-31 — KUNSTMAAN_SOURCE_PATH env or kunstmaanSourcePath Settings)
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
}
