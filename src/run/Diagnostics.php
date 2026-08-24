<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\run;

use Craft;
use craft\helpers\App;
use Lameco\KumaCompile\Mapping\Mapping;
use lameco\kunstmaanmigrator\adapters\AdapterRegistry;
use lameco\kunstmaanmigrator\compile\TargetModel;
use lameco\kunstmaanmigrator\load\AssetMigrationService;
use lameco\kunstmaanmigrator\payload\CraftSchemaGateway;
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
            ...$this->checkAdapterPlugins(),
            $this->checkEmbeddedAssets(),
            $this->checkLegacyMediaRoot(),
            $this->checkLegacyDb(),
            ...$this->checkMapping(),
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

            $resolved = Craft::$app->db->getSchema()->getRawTableName($tableName);

            return $this->result('state_table', true, "State table reachable ({$resolved}).");
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
     * Every adapter's plugin, not one of them.
     *
     * `doctor` reported Retour and stayed silent about SEOmatic, Formie and
     * verbb/navigation — three more passes that skip themselves when their
     * plugin is absent. The registry knows what each adapter needs, so the
     * report reads from it and cannot fall out of step when an adapter is
     * added. Informational (ok=true) on absence, matching the adapters' own
     * skip-with-a-warning behaviour; a disabled adapter is a decision, and the
     * report says so rather than flagging it.
     *
     * @return list<array{check: string, ok: bool, detail: string}>
     */
    private function checkAdapterPlugins(): array
    {
        $settings = Plugin::getInstance()?->getSettings();
        $checks = [];

        foreach ((new AdapterRegistry())->all() as $adapter) {
            if ($adapter->pluginHandle === null) {
                continue;
            }

            $plugin = Craft::$app->plugins->getPlugin($adapter->pluginHandle);
            $enabled = $settings === null || $settings->isAdapterEnabled($adapter);

            if ($plugin === null) {
                $detail = sprintf(
                    '%s not installed — the %s pass skips with a warning.',
                    $adapter->pluginHandle,
                    $adapter->label,
                );
            } elseif (!$enabled) {
                $detail = sprintf(
                    '%s v%s installed, but the adapter is switched off in settings.',
                    $adapter->pluginHandle,
                    (string) $plugin->getVersion(),
                );
            } else {
                $detail = sprintf(
                    '%s v%s installed — the %s pass runs.',
                    $adapter->pluginHandle,
                    (string) $plugin->getVersion(),
                    $adapter->label,
                );
            }

            $checks[] = $this->result('adapter:' . $adapter->handle, true, $detail);
        }

        return $checks;
    }

    /**
     * Not an adapter, but a lane enhancement worth stating: with
     * spicyweb/craft-embedded-assets installed, a legacy remote video becomes
     * an embedded-asset element; without it, the reference stays an id-only
     * state row and the video fields it fed stay empty.
     */
    private function checkEmbeddedAssets(): array
    {
        $plugin = Craft::$app->plugins->getPlugin('embeddedassets');

        return $this->result(
            'embedded_assets',
            true,
            $plugin !== null
                ? sprintf('embeddedassets v%s installed — remote videos become embedded assets.', (string) $plugin->getVersion())
                : 'embeddedassets not installed — remote videos are recorded but no element is created.',
        );
    }

    /**
     * Check #5: the LEGACY_MEDIA_PATH env var, which is the fallback root —
     * the per-environment `mediaRoot:` chains in the mapping are the real
     * source and are checked below, per environment. Informational when unset,
     * fails only when set but not a readable directory.
     */
    private function checkLegacyMediaRoot(): array
    {
        $root = App::env(AssetMigrationService::LEGACY_MEDIA_ROOT_ENV);
        if (!is_string($root) || $root === '') {
            return $this->result(
                'legacy_media_root',
                true,
                AssetMigrationService::LEGACY_MEDIA_ROOT_ENV . ' not set — normal; the mapping\'s per-environment mediaRoot chains are the source, checked below.',
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
     * Check #6: the single legacy connection Settings names, used by commands
     * that run outside a migration.
     *
     * A blank `legacyDbDatabase` is the normal case, not an unconfigured one —
     * the database name is per environment and comes from the mapping. This
     * check therefore says very little on its own, which is why it used to
     * report a green "not configured" on an install that could not connect at
     * all. The per-environment probes below are the ones that answer the
     * question.
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
                'no standalone legacy DB named in Settings — normal; each environment names its own database in the mapping, probed below.',
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
     * The mapping, and every environment it declares.
     *
     * `doctor` answered six questions and none of them was "can this migration
     * actually run" — it passed on an install whose mapping pointed nowhere,
     * whose legacy databases were unreachable and whose uploads directories had
     * moved, because the two checks that looked like they covered that read an
     * env var and a settings field the mapping-owns-topology design leaves
     * blank on purpose. MappingPreflight already asks the right questions; the
     * control panel ran it and the command could not.
     *
     * @return list<array{check: string, ok: bool, detail: string}>
     */
    private function checkMapping(): array
    {
        $path = App::parseEnv(Plugin::getInstance()->getSettings()->mappingPath);

        if (!is_string($path) || $path === '') {
            return [$this->result('mapping', false, 'No mapping file configured — set one in the plugin settings.')];
        }

        if (!is_file($path)) {
            return [$this->result('mapping', false, sprintf('Mapping file not found: %s', $path))];
        }

        try {
            $environments = Mapping::fromFile($path)->environments();
        } catch (Throwable $e) {
            return [$this->result('mapping', false, sprintf('Mapping is unreadable: %s', $e->getMessage()))];
        }

        $checks = [$this->result('mapping', true, sprintf('%s declares %d environment(s).', $path, count($environments)))];
        $checks[] = $this->checkBlockPropagation(Mapping::fromFile($path));

        try {
            $readiness = (new MappingPreflight(
                    new PdoPreflightProbe(EnvironmentPipeline::dsnFromSettings()),
                    static fn(string $path): string => (string) App::parseEnv($path),
                ))
                ->inspect($environments, Craft::$app->getSites()->getAllSites());
        } catch (Throwable $e) {
            $checks[] = $this->result('environments', false, sprintf('Preflight failed: %s', $e->getMessage()));

            return $checks;
        }

        foreach ($readiness as $environment) {
            $problems = $environment->problems();

            $checks[] = $this->result(
                'environment:' . $environment->name,
                $problems === [],
                $problems === []
                    ? sprintf(
                        '%s reachable, %s nodes · uploads readable (%d director%s)%s.',
                        $environment->database,
                        $environment->nodeCount === null ? '?' : number_format($environment->nodeCount),
                        count($environment->mediaRoots),
                        count($environment->mediaRoots) === 1 ? 'y' : 'ies',
                        $environment->localesNotMigrated === []
                            ? ''
                            : sprintf(' · %d locale(s) deliberately not migrated', count($environment->localesNotMigrated)),
                    )
                    : implode(' ', $problems),
            );
        }

        return $checks;
    }

    /**
     * Can the target's page-builder Matrix fields hold per-locale content at all?
     *
     * The one precondition the loader cannot work around and the only one that is invisible
     * until an editor opens the Latvian page and reads English. It belongs in the preflight
     * because the fix is a project-config change and the run is two hours long.
     *
     * @return array{check: string, ok: bool, detail: string}
     */
    private function checkBlockPropagation(Mapping $mapping): array
    {
        $localesPer = [];

        foreach ($mapping->environments() as $env => $spec) {
            $localesPer[(string) $env] = count((array) ($spec['locales'] ?? []));
        }

        $schema = new TargetModel(new CraftSchemaGateway());
        $contexts = $mapping->all()['defaults']['contexts'] ?? [];
        $methods = [];

        foreach ($mapping->pages() as $spec) {
            if (!is_array($spec) || isset($spec['manual'])) {
                continue;
            }

            $entryType = (string) ($spec['entryType'] ?? '');

            if ($entryType === '') {
                continue;
            }

            // `contexts:` names the layout's *instance* handle — `pageBuilder`, not the
            // `commonPageBuilder` field it instances. Asking the field service for the instance
            // handle returns nothing, which reads as "no opinion" and quietly passes the check.
            // The slot lookup is the same path the compiler resolves the field through.
            foreach (($spec['contexts'] ?? $contexts) as $target) {
                $field = is_array($target) ? (string) ($target['field'] ?? '') : '';
                $slot = $field !== '' ? $schema->slot($entryType, $field) : null;

                if ($slot !== null) {
                    $methods[$entryType . '.' . $field] = $slot->propagationMethod;
                }
            }
        }

        $problems = BlockPropagation::problems($methods, $localesPer);

        return $this->result(
            'blockPropagation',
            $problems === [],
            $problems === []
                ? sprintf('%d page-builder field(s) can hold per-locale blocks.', count($methods))
                : implode(' ', $problems),
        );
    }

    /**
     * @return array{check: string, ok: bool, detail: string}
     */
    private function result(string $check, bool $ok, string $detail): array
    {
        return ['check' => $check, 'ok' => $ok, 'detail' => $detail];
    }
}
