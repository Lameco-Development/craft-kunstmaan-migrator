<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\console;

use Craft;
use craft\console\Controller;
use lameco\kunstmaanmigrator\load\AssetMigrationService;
use lameco\kunstmaanmigrator\NeverProductionTrait;
use lameco\kunstmaanmigrator\Plugin;
use lameco\kunstmaanmigrator\run\Diagnostics;
use yii\console\ExitCode;

/**
 * Doctor — preflight diagnostics for the v2 loader-only core (Task 6
 * rewrite). Emits a single JSON array of `{check, ok, detail}` rows on
 * stdout and exits non-zero if any row's `ok` is false — every other
 * console command in this plugin already emits machine-readable
 * JSON/NDJSON; doctor now matches instead of writing ANSI-colored prose.
 *
 * v2 loader prune: the SEOmatic informational check and the
 * ext_translations presence check are removed — analyze/taxonomy-stage
 * machinery that has no place in a loader-only world (the Anthropic-key
 * check and the mapping-file check never existed in this class to begin
 * with; both were pruned before Task 6).
 *
 * Task 8 reverses part of the earlier prune: the whole-branch review found
 * the live payload path now genuinely resolves `_asset` (filesystem JIT via
 * AssetMigrationService::resolveFromLegacyUrl) and `{{kuma:media:<id>}}`
 * tokens (legacy-DB JIT via resolveFromLegacyId) — both media-heavy sites
 * (e.g. Enreach) depend on, so doctor needs to surface whether either
 * config knob is present and reachable. Both are purely informational when
 * absent (`ok=true`) since a no-asset site needs neither.
 *
 * Six checks remain, each independent — no early-exit, every check always
 * runs so the operator gets the full report in one pass:
 *
 *   1. checkStateTable       — the plugin is installed (Craft's plugin
 *                              service has it registered) AND the
 *                              kunstmaanmigrator_state table exists and is
 *                              reachable.
 *   2. checkStorageWritable  — storage/migration/ exists (auto-creating it
 *                              if missing) and is writable.
 *   3. checkNotProduction    — CRAFT_ENVIRONMENT must not be 'production'.
 *                              Reuses NeverProductionTrait::enforceNeverProduction()
 *                              as the single source of truth for that
 *                              predicate — still "the production guard",
 *                              just surfaced as a check row instead of an
 *                              early hard-exit (every write command still
 *                              hard-refuses via the trait itself).
 *   4. checkRetourPresence   — informational only (always ok=true): reports
 *                              whether the Retour plugin is installed —
 *                              `load/redirects` reports SKIPPED_NO_RETOUR
 *                              per row when it isn't.
 *   5. checkLegacyMediaRoot  — informational when LEGACY_MEDIA_PATH is
 *                              unset (`_asset` resolution is simply skipped
 *                              for this site); fails only when the env var
 *                              IS set but points at a missing/unreadable
 *                              directory (a misconfiguration, not an
 *                              absence).
 *   6. checkLegacyDb         — informational when the legacy DB isn't
 *                              configured (Settings::$legacyDbServer/
 *                              $legacyDbDatabase both blank — `{{kuma:media:}}`
 *                              tokens then only resolve via ids already
 *                              cached in state); fails only when configured
 *                              but unreachable.
 */
class DoctorController extends Controller
{
    use NeverProductionTrait;

    public function actionIndex(): int
    {
        $checks = (new Diagnostics())->run();

        $this->stdout(json_encode($checks, JSON_UNESCAPED_SLASHES) . PHP_EOL);

        return self::exitCodeFor($checks);
    }

    /**
     * @param list<array{check: string, ok: bool, detail: string}> $checks
     */
    public static function exitCodeFor(array $checks): int
    {
        foreach ($checks as $check) {
            if (($check['ok'] ?? true) === false) {
                return ExitCode::UNSPECIFIED_ERROR;
            }
        }

        return ExitCode::OK;
    }

    /**
     * Check #1: the plugin is registered with Craft AND the migrator state
     * table exists and is reachable.
     */
}
