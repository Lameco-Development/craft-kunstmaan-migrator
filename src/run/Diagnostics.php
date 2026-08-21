<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\run;

use Craft;
use craft\helpers\App;
use lameco\kunstmaanmigrator\load\AssetMigrationService;
use lameco\kunstmaanmigrator\Plugin;
use lameco\kunstmaanmigrator\ProductionGuard;
use Throwable;

/**
 * The preflight `doctor` reports, as a service rather than a console command.
 *
 * The checks were private methods on DoctorController, which meant the only
 * way to ask "is this install ready" was to open a terminal. They answer the
 * same six questions wherever they are asked from, so they live here and both
 * the console command and the control panel read them.
 *
 * @phpstan-type Check array{check: string, ok: bool, detail: string}
 */
final class Diagnostics
{
    /**
     * @return list<Check>
     */
    public function run(): array
    {
        return [
            $this->checkStateTable(),
            $this->checkStorageWritable(),
            $this->checkNotProduction(),
            $this->checkRetourPresence(),
            $this->checkLegacyMediaRoot(),
            $this->checkLegacyDb(),
        ];
    }

    /**
     * @param list<Check> $checks
     */
    public static function allPassed(array $checks): bool
    {
        foreach ($checks as $check) {
            if (($check['ok'] ?? true) === false) {
                return false;
            }
        }

        return true;
    }

    private function checkStateTable(): array
    {
        $tableName = '{{%kunstmaanmigrator_state}}';

        try {
            if (Craft::$app->plugins->getPlugin('kunstmaan-migrator') === null) {
                return $this->result('state_table', false, 'kunstmaan-migrator plugin is not installed.');
            }

            if (!Craft::$app->db->getTableSchema($tableName)) {
                return $this->result(
                    'state_table',
                    false,
                    "State table '{$tableName}' is missing — run `./craft plugin/install kunstmaan-migrator` "
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
     * Check #3: NOT production. Reads the same predicate the write commands
     * refuse on, rather than re-deriving it — ProductionGuard is the one place
     * that decides what production means.
     */
    private function checkNotProduction(): array
    {
        $blocked = ProductionGuard::isProduction();

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
