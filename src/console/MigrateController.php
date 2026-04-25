<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\console;

use Craft;
use craft\console\Controller;
use craft\db\MigrationManager;
use craft\helpers\Console;
use lameco\kunstmaanmigrator\NeverProductionTrait;
use yii\console\ExitCode;

/**
 * Migrate command — Phase 1 ships only actionInstall (FND-02a programmatic install shim).
 * extract / transform / load / finalize actions land in Phase 3 (D-05).
 */
class MigrateController extends Controller
{
    use NeverProductionTrait;

    /**
     * FND-02a: idempotent re-runner for the plugin's own migrations.
     * `./craft plugin/install kunstmaan-migrator` already runs Install.php on first install;
     * this action is the post-install / future-schema-bump shim — needed because Craft 5
     * dropped --migrationPath from the standard migrate command.
     */
    public function actionInstall(): int
    {
        // D-20: every legacy-reading or destructive action gates on NeverProduction first.
        if (($gate = $this->enforceNeverProduction()) !== null) {
            return $gate;
        }

        // PATH NOTE: v2's flat src/console/ is 2 levels deep (vs v1's 3-deep
        // src/bridge/console/controllers/) — so __DIR__ . '/../migrations' reaches
        // src/migrations/. Do NOT use '/../../migrations' (that was v1's path).
        //
        // NAMESPACE NOTE: 'lameco\\kunstmaanmigrator\\migrations' matches src/migrations/
        // under the PSR-4 prefix declared in composer.json (D-04 flat layout).
        $manager = Craft::createObject([
            'class'              => MigrationManager::class,
            'track'              => 'kunstmaanmigrator',
            'migrationNamespace' => 'lameco\\kunstmaanmigrator\\migrations',
            'migrationPath'      => __DIR__ . '/../migrations',
        ]);

        $this->stdout("Installing migrator migrations...\n", Console::FG_CYAN);
        $manager->up();
        $this->stdout("  OK migrator migrations applied (track=kunstmaanmigrator)\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}
