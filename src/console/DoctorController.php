<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\console;

use Craft;
use craft\console\Controller;
use craft\helpers\App;
use lameco\kunstmaanmigrator\load\AssetMigrationService;
use lameco\kunstmaanmigrator\NeverProductionTrait;
use lameco\kunstmaanmigrator\Plugin;
use Throwable;
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
        $checks = [
            $this->checkStateTable(),
            $this->checkStorageWritable(),
            $this->checkNotProduction(),
            $this->checkRetourPresence(),
            $this->checkLegacyMediaRoot(),
            $this->checkLegacyDb(),
        ];

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
    private function checkStateTable(): array
    {
        $tableName = '{{%kunstmaanmigrator_state}}';

        try {
            if (Craft::$app->plugins->getPlugin('kuma-loader') === null) {
                return $this->result('state_table', false, 'kuma-loader plugin is not installed.');
            }

            if (!Craft::$app->db->getTableSchema($tableName)) {
                return $this->result(
                    'state_table',
                    false,
                    "State table '{$tableName}' is missing — run `./craft plugin/install kuma-loader` "
                    . '(Craft\'s native plugin install runs the migration that creates it).',
                );
            }

            Craft::$app->db->createCommand("SELECT COUNT(*) FROM {$tableName}")->queryScalar();

            return $this->result('state_table', true, "kunstmaanmigrator_state table reachable ({$tableName}).");
        } catch (Throwable $e) {
            return $this->result('state_table', false, "state table check failed: {$e->getMessage()}");
        }
    }

    /**
     * Check #2: storage/migration/ exists and is writable, auto-creating it
     * under Craft's storage tree if missing.
     */
    private function checkStorageWritable(): array
    {
        $dir = Craft::$app->path->getStoragePath() . '/migration';

        try {
            if (!is_dir($dir)) {
                if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
                    return $this->result('storage_writable', false, "could not create {$dir}");
                }
            }
            if (!is_writable($dir)) {
                return $this->result('storage_writable', false, "{$dir} is not writable");
            }

            return $this->result('storage_writable', true, "{$dir} is writable");
        } catch (Throwable $e) {
            return $this->result('storage_writable', false, "storage check failed: {$e->getMessage()}");
        }
    }

    /**
     * Check #3: NOT production. Delegates the predicate to
     * NeverProductionTrait::enforceNeverProduction() (which also writes the
     * refusal line to stderr on a hit) rather than re-reading
     * CRAFT_ENVIRONMENT independently — one canonical guard, surfaced here
     * as a check row instead of an early hard-exit.
     */
    private function checkNotProduction(): array
    {
        $blocked = $this->enforceNeverProduction() !== null;

        return $this->result(
            'not_production',
            !$blocked,
            $blocked
                ? 'CRAFT_ENVIRONMENT=production — migrator commands refuse to run here.'
                : 'CRAFT_ENVIRONMENT is not production.',
        );
    }

    /**
     * Check #4: Retour presence — informational only, always ok=true.
     */
    private function checkRetourPresence(): array
    {
        $retour = Craft::$app->plugins->getPlugin('retour');
        if ($retour !== null) {
            return $this->result('retour_presence', true, 'retour v' . (string) $retour->getVersion() . ' installed.');
        }

        return $this->result(
            'retour_presence',
            true,
            'retour not installed — load/redirects will report SKIPPED_NO_RETOUR for every row.',
        );
    }

    /**
     * Check #5: LEGACY_MEDIA_PATH — informational when unset (`_asset`
     * resolution is filesystem-JIT via AssetMigrationService::resolveFromLegacyUrl,
     * skipped entirely without a root), fails only when set but not a
     * readable directory (misconfiguration, not absence).
     */
    private function checkLegacyMediaRoot(): array
    {
        $root = App::env(AssetMigrationService::LEGACY_MEDIA_ROOT_ENV);
        if (!is_string($root) || $root === '') {
            return $this->result(
                'legacy_media_root',
                true,
                AssetMigrationService::LEGACY_MEDIA_ROOT_ENV . ' not configured — _asset resolution is skipped for this site.',
            );
        }

        if (!is_dir($root) || !is_readable($root)) {
            return $this->result(
                'legacy_media_root',
                false,
                sprintf('%s=%s is not a readable directory.', AssetMigrationService::LEGACY_MEDIA_ROOT_ENV, $root),
            );
        }

        return $this->result(
            'legacy_media_root',
            true,
            sprintf('%s=%s is a readable directory.', AssetMigrationService::LEGACY_MEDIA_ROOT_ENV, $root),
        );
    }

    /**
     * Check #6: legacy DB reachability — informational when
     * Settings::$legacyDbServer/$legacyDbDatabase are both blank
     * (`{{kuma:media:<id>}}` tokens then only resolve via ids already
     * cached in state; AssetMigrationService::resolveFromLegacyId's legacy-DB
     * JIT path is simply unavailable), fails only when configured but the
     * connection itself doesn't work.
     */
    private function checkLegacyDb(): array
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin?->getSettings();
        $configured = $settings !== null
            && is_string($settings->legacyDbServer) && $settings->legacyDbServer !== ''
            && is_string($settings->legacyDbDatabase) && $settings->legacyDbDatabase !== '';

        if (!$configured) {
            return $this->result(
                'legacy_db',
                true,
                'legacy DB not configured — {{kuma:media:<id>}} token resolution falls back to already-cached state only.',
            );
        }

        try {
            $dbName = $plugin->legacyDbService->getDatabaseName();

            return $this->result('legacy_db', true, "legacy DB reachable (database={$dbName}).");
        } catch (Throwable $e) {
            return $this->result('legacy_db', false, "legacy DB configured but unreachable: {$e->getMessage()}");
        }
    }

    /**
     * @return array{check: string, ok: bool, detail: string}
     */
    private function result(string $check, bool $ok, string $detail): array
    {
        return ['check' => $check, 'ok' => $ok, 'detail' => $detail];
    }
}
