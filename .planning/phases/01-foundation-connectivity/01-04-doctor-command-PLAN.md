---
phase: 01-foundation-connectivity
plan: 04
type: execute
wave: 3
depends_on: [01, 02, 03]
files_modified:
  - src/console/DoctorController.php
autonomous: true
requirements: [FND-04, CONN-03]
must_haves:
  truths:
    - "kunstmaan-migrator/doctor reports OK/FAIL on legacy DB reachability, Anthropic key presence, and storage/migration writability (3 checks per D-17)."
    - "Every check still runs even after a failure — operators get the full report, not a short-circuited tail."
    - "Doctor exits 0 on full pass, 1 on any FAIL."
    - "storage/migration/ is auto-created if missing (D-18) — one less manual op step."
    - "Doctor refuses to run when CRAFT_ENVIRONMENT=production (FND-04 enforcement via NeverProductionTrait)."
    - "Anthropic key check reports presence ONLY — never echoes or logs the value (T-1-03 mitigation)."
  artifacts:
    - path: src/console/DoctorController.php
      provides: "Doctor console controller — 3 preflight checks + plain-text OK/FAIL output"
      contains: "class DoctorController extends Controller"
  key_links:
    - from: src/console/DoctorController.php
      to: src/db/LegacyDbService.php
      via: "Plugin::getInstance()->legacyDbService->queryOne('SELECT 1 AS ok')"
      pattern: "legacyDbService->queryOne"
    - from: src/console/DoctorController.php
      to: src/models/Settings.php
      via: "Plugin::getInstance()->getSettings()->anthropicApiKey"
      pattern: "getSettings\\(\\)->anthropicApiKey"
    - from: src/console/DoctorController.php
      to: "Craft storage path"
      via: "Craft::\\$app->path->getStoragePath() . '/migration'"
      pattern: "getStoragePath\\(\\)"
    - from: src/console/DoctorController.php
      to: src/NeverProductionTrait.php
      via: "use NeverProductionTrait + enforceNeverProduction() guard"
      pattern: "enforceNeverProduction"
---

<objective>
Ship the `kunstmaan-migrator/doctor` console command with three preflight checks (D-17 — NOT four as
CONN-03 currently lists; the mapping-file check defers to Phase 2 alongside the loader). Every check is
gated on `enforceNeverProduction()` first; output is plain-text OK/FAIL with ANSI colors; exit code is 0 on
full pass, 1 on any FAIL.

The three checks per D-17:
1. Legacy DB reachability — `Plugin::getInstance()->legacyDbService->queryOne('SELECT 1 AS ok')` (port from v1).
2. Anthropic key presence — Settings.anthropicApiKey (CP override) OR ANTHROPIC_API_KEY env (D-14). Reports presence only; NEVER echoes or logs the value (T-1-03).
3. storage/migration/ writable — auto-creates (mkdir 0755) if missing (D-18 greenfield behavior; v1 had no equivalent), then verifies write perm.

Drops from v1: `checkQueueWorker()` (PROJECT.md Key Decisions: v2 is CLI-inline by default), `checkMapping()` (D-17 — Phase 2 loader/validator owns it).

Purpose: Operators have one command to confirm Phase 1 is configured correctly before running Phase 2's
analyze. The 5th success criterion in ROADMAP.md ("CRAFT_ENVIRONMENT=production causes any legacy-reading
command to refuse with a clear error message") is also gated through this controller's NeverProduction
hook.
Output: A working `./craft kunstmaan-migrator/doctor` that exits 0 / 1 with structured stdout/stderr.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/PROJECT.md
@.planning/ROADMAP.md
@.planning/REQUIREMENTS.md
@.planning/phases/01-foundation-connectivity/01-CONTEXT.md
@.planning/phases/01-foundation-connectivity/01-PATTERNS.md
@CLAUDE.md
@src/Plugin.php
@src/NeverProductionTrait.php
@src/db/LegacyDbService.php
@src/models/Settings.php
</context>

<interfaces>
<!-- Contract this plan creates — Plan 05 (PHPUnit tests) and downstream phases consume the controller class shape. -->

```php
namespace lameco\kunstmaanmigrator\console;

class DoctorController extends \craft\console\Controller
{
    use \lameco\kunstmaanmigrator\NeverProductionTrait;

    public function actionIndex(): int;          // ExitCode::OK on full pass, ExitCode::UNSPECIFIED_ERROR on any FAIL
    private function checkLegacyDb(): bool;      // SELECT 1 against legacyDbService
    private function checkApiKey(): bool;        // presence-only — never logs the value
    private function checkStorageDir(): bool;    // auto-creates storage/migration/, verifies writable
}
```

CLI invocation: `./craft kunstmaan-migrator/doctor` (Plan 02's controllerNamespace switch routes the
namespace; the `Index` action is the controller default).
</interfaces>

<tasks>

<task type="auto" tdd="false">
  <name>Task 1: Write DoctorController with three preflight checks + NeverProduction guard</name>
  <files>src/console/DoctorController.php</files>
  <read_first>
    - ~/Sites/craft-kunstmaan-migrator/src/bridge/console/controllers/DoctorController.php (full file — port checkLegacyDb + checkApiKey patterns; DROP checkQueueWorker and checkMapping)
    - .planning/phases/01-foundation-connectivity/01-PATTERNS.md (section "src/console/DoctorController.php", lines 171-296)
    - .planning/phases/01-foundation-connectivity/01-CONTEXT.md (D-14, D-17, D-18, D-19, D-20)
    - src/db/LegacyDbService.php (Plan 02 — confirms queryOne signature)
    - src/models/Settings.php (Plan 02 — confirms anthropicApiKey property)
    - src/NeverProductionTrait.php (Plan 01 — confirms enforceNeverProduction() return shape)
  </read_first>
  <action>
    Create `src/console/DoctorController.php`. This is a SUBSET port of v1's `src/bridge/console/controllers/DoctorController.php`: keep `checkLegacyDb()` and `checkApiKey()` patterns, add the new `checkStorageDir()` method per D-18, and DROP `checkQueueWorker()` (PROJECT.md Key Decisions) and `checkMapping()` (D-17 → Phase 2). Also drop the v1 imports for `Queue` and `PingJob`.

    The `actionIndex()` orchestrator runs all three checks via `&&`-against-`$ok` so every check executes even after a failure (operators want the full report — see PATTERNS.md inline comment at line 211).

    Concrete file content (paste-ready, follows PATTERNS.md lines 178-291):

    ```php
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
    ```

    Notes for the executor:
    - DO NOT add a `checkQueueWorker()` or `checkMapping()` method — these were dropped per PROJECT.md Key Decisions and D-17 respectively.
    - The `&&`-against-`$ok` pattern (NOT short-circuit `&&`) ensures all three checks run even when an earlier one fails. Order matters: `$this->checkX() && $ok` evaluates `checkX()` first, then ANDs with `$ok`. If you wrote `$ok && $this->checkX()`, PHP would short-circuit on a prior failure.
    - `checkApiKey()` builds `$hasKey` as a boolean comparison — no string slicing, no `substr()`, no `preg_match()` against the key value. Defense-in-depth against accidental disclosure.
    - The `try/catch (Throwable)` in `checkLegacyDb()` echoes `$e->getMessage()`. Yii's Connection exception messages do not include the password (only host/port/database). If a future Yii change adds the password to error messages, this becomes T-1-02 — out of scope for this plan but worth a code-review checkpoint.
  </action>
  <acceptance_criteria>
    - `src/console/DoctorController.php` exists.
    - `grep -q "namespace lameco\\\\kunstmaanmigrator\\\\console;" src/console/DoctorController.php` exits 0.
    - `grep -q "class DoctorController extends Controller" src/console/DoctorController.php` exits 0.
    - NeverProductionTrait wired: `grep -q "use lameco\\\\kunstmaanmigrator\\\\NeverProductionTrait;" src/console/DoctorController.php && grep -q "use NeverProductionTrait;" src/console/DoctorController.php`.
    - All three checks present: `for m in 'function checkLegacyDb' 'function checkApiKey' 'function checkStorageDir'; do grep -q "$m" src/console/DoctorController.php || exit 1; done`.
    - Dropped checks ABSENT: `! grep -q 'function checkQueueWorker' src/console/DoctorController.php` AND `! grep -q 'function checkMapping' src/console/DoctorController.php`.
    - Dropped imports ABSENT: `! grep -q 'use craft\\\\queue\\\\Queue' src/console/DoctorController.php` AND `! grep -q 'PingJob' src/console/DoctorController.php`.
    - actionIndex orchestrator present and gated: `grep -q "function actionIndex(): int" src/console/DoctorController.php && awk '/function actionIndex/,/^    }/' src/console/DoctorController.php | head -8 | grep -q 'enforceNeverProduction'`.
    - Three-check chain verified (each appears in actionIndex body): `awk '/function actionIndex/,/^    }/' src/console/DoctorController.php | grep -q 'checkLegacyDb()' && awk '/function actionIndex/,/^    }/' src/console/DoctorController.php | grep -q 'checkApiKey()' && awk '/function actionIndex/,/^    }/' src/console/DoctorController.php | grep -q 'checkStorageDir()'`.
    - SELECT 1 reachability check: `grep -q "queryOne('SELECT 1 AS ok')" src/console/DoctorController.php`.
    - storage/migration path constructed via getStoragePath: `grep -q "getStoragePath() . '/migration'" src/console/DoctorController.php`.
    - mkdir with 0755 perms: `grep -q '@mkdir(.dir, 0755, true)' src/console/DoctorController.php`.
    - T-1-03 secret-leak prevention: `! grep -E 'echo .*anthropicApiKey|var_dump.*anthropicApiKey|print_r.*anthropicApiKey|stdout.*\\\$fromSettings|stdout.*\\\$fromEnv' src/console/DoctorController.php`.
    - Exit codes: `grep -q 'ExitCode::OK' src/console/DoctorController.php && grep -q 'ExitCode::UNSPECIFIED_ERROR' src/console/DoctorController.php`.
    - `php -l src/console/DoctorController.php` exits 0.
    - `php -r 'require __DIR__ . "/vendor/autoload.php"; $rc = new ReflectionClass("lameco\\kunstmaanmigrator\\console\\DoctorController"); foreach (["actionIndex","checkLegacyDb","checkApiKey","checkStorageDir"] as $m) { $rc->hasMethod($m) or exit(1); } if ($rc->hasMethod("checkQueueWorker") || $rc->hasMethod("checkMapping")) { exit(2); }'` exits 0.
  </acceptance_criteria>
  <verify>
    <automated>php -l src/console/DoctorController.php &amp;&amp; php -r 'require __DIR__ . "/vendor/autoload.php"; $rc = new ReflectionClass("lameco\\kunstmaanmigrator\\console\\DoctorController"); foreach (["actionIndex","checkLegacyDb","checkApiKey","checkStorageDir"] as $m) { $rc->hasMethod($m) or exit(1); } if ($rc->hasMethod("checkQueueWorker") || $rc->hasMethod("checkMapping")) { exit(2); }'</automated>
  </verify>
  <done>DoctorController autoloads with three checks, gates on NeverProduction first, drops the v1 queue+mapping checks, prints plain-text OK/FAIL with ANSI colors, exits 0/1, and contains zero secret-echo paths.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| Operator CLI invocation → DoctorController::actionIndex | Trusted operator surface (CLI is canonical). NeverProduction guard rejects production env per FND-04. |
| DoctorController → legacy DB | Read-only `SELECT 1` smoke. No user input flows to the query (it's a literal). |
| DoctorController → Settings.anthropicApiKey | Read-only — value is converted to `$hasKey` boolean and DROPPED. Value never appears in stdout/stderr. |
| DoctorController → filesystem (storage/migration/) | Side-effecting (mkdir 0755). Path is constrained to `Craft::$app->path->getStoragePath() . '/migration'`; no user input feeds the path. |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-1-01 | Elevation of Privilege | `DoctorController::actionIndex` | mitigate | First line of `actionIndex()` calls `$this->enforceNeverProduction()` and returns its non-null result. Verified by acceptance-criteria grep + ordering check (`awk '/function actionIndex/,/^    }/' \\| head -8 \\| grep -q 'enforceNeverProduction'`). Combined with Plan 03's `MigrateController::actionInstall` guard, this satisfies FND-04 across all Phase 1 controllers. |
| T-1-03 | Information Disclosure | `DoctorController::checkApiKey` | mitigate | `$fromSettings` and `$fromEnv` are converted to a `$hasKey` boolean via `!== ''` comparison and used ONLY in branch selection. The OK/FAIL output strings are static literals (`"  OK   ANTHROPIC_API_KEY set\n"` / the "missing" message) — no string interpolation of either variable. Verified by acceptance-criteria grep `! grep -E 'echo .*anthropicApiKey\\|stdout.*\\$fromSettings\\|stdout.*\\$fromEnv'`. |
| T-1-04 | Tampering | `DoctorController::checkStorageDir` mkdir | mitigate | Path constrained to `Craft::$app->path->getStoragePath() . '/migration'` — no user input. Mode `0755` is conservative (owner write, group/other read+execute). The `@mkdir($dir, 0755, true)` recursive call cannot escape the storage tree because `getStoragePath()` returns an absolute Craft-owned path. Verified by acceptance-criteria greps for the literal `/migration` suffix and `0755` mode. |
| T-1-08 | Information Disclosure | `checkLegacyDb` exception message | accept | `$e->getMessage()` is echoed on connection failure. Yii's `Connection` exceptions carry host/port/database but NOT the password. Operators need to see WHAT broke; full opacity defeats the doctor's purpose. If a future Yii version starts including credentials in exception messages, this becomes T-1-02 and the catch must mask. Documented as accepted risk; revisit in Phase 5 hardening. |

All four phase-level threats (T-1-01..T-1-04) are now distributed: T-1-01 in Plans 03+04, T-1-02 in Plan 02,
T-1-03 in Plans 02+04, T-1-04 in Plan 04.
</threat_model>

<verification>
After the task:

1. `composer dump-autoload --no-interaction` picks up the new controller.
2. `php -l src/console/DoctorController.php` exits 0.
3. The reflection probe from the task's verify confirms the four expected methods exist and the two dropped methods do NOT.
4. Pattern-mirror checks against PATTERNS.md:
   - `grep -c 'Console::FG_GREEN' src/console/DoctorController.php` >= 3 (one per OK row + one in summary).
   - `grep -c 'Console::FG_RED' src/console/DoctorController.php` >= 3 (one per potential FAIL row + one in summary).
5. Read `src/console/DoctorController.php` and confirm visually that the order in `actionIndex()` is: NeverProduction → header → checkLegacyDb → checkApiKey → checkStorageDir → summary.

End-to-end functional verification (via doctor against a live Craft host) is deferred to Plan 05 / phase
verifier — Phase 1 cannot run a Craft-bootstrapped command from a unit-test context without a fixture site.
The acceptance criteria here verify the contract structurally; the rehearsal verifies it behaviorally.
</verification>

<success_criteria>
- FND-04 satisfied across Phase 1: every legacy-reading controller action (DoctorController + MigrateController::actionInstall) gates on `enforceNeverProduction()` first.
- CONN-03 partially satisfied: 3 checks ship in Phase 1 per D-17 (mapping check defers to Phase 2 — Plan 05 patches CONN-03 wording in REQUIREMENTS.md).
- D-17 honored: 3 checks (legacyDb, anthropicApiKey, storageDir).
- D-18 honored: storage/migration/ auto-create with 0755 perms.
- D-19 honored: plain-text OK/FAIL output with ANSI colors; exit 0 on full pass / 1 on any FAIL.
- D-20 honored: every action gates on enforceNeverProduction first.
- T-1-01, T-1-03, T-1-04 mitigations all verified by acceptance-criteria greps.
- File lints clean and autoloads through PSR-4.
- No mapping or queue check artifacts (PATTERNS.md "Drop entirely from v1" enforced by negative greps).
</success_criteria>

<output>
After completion, create `.planning/phases/01-foundation-connectivity/01-04-SUMMARY.md`.
</output>
