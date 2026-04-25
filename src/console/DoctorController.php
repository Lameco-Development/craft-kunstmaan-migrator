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
 * Doctor — preflight diagnostics for the migrator. Three checks per D-17:
 *   1. Legacy DB reachability (SELECT 1)
 *   2. Anthropic key presence (Settings override OR env; never echoes the value — T-1-03)
 *   3. storage/migration/ writable (auto-creates if missing — D-18 greenfield behavior)
 *
 * Drops from v1: checkQueueWorker (PROJECT.md Key Decisions — v2 is CLI-inline) and
 * checkMapping (D-17 — defers to Phase 2 alongside the mapping loader).
 */
class DoctorController extends Controller
{
    use NeverProductionTrait;

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
        $ok = $this->checkLegacyDb()    && $ok;
        $ok = $this->checkApiKey()      && $ok;
        $ok = $this->checkStorageDir()  && $ok;

        $this->stdout(
            "\n" . ($ok ? "Doctor: PASS\n" : "Doctor: FAIL — fix the above before running migrate\n"),
            $ok ? Console::FG_GREEN : Console::FG_RED,
        );

        return $ok ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Check #1: legacy DB reachable. Port verbatim from v1 lines 105-115.
     */
    private function checkLegacyDb(): bool
    {
        try {
            Plugin::getInstance()->legacyDbService->queryOne('SELECT 1 AS ok');
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
}
