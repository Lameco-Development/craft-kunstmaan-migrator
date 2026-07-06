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
 * Migrate command — v2 loader prune: the v1.x ETL pipeline (extract →
 * transform → load → finalize, plus the seo/redirect/navigation/translation/
 * taxonomy/truncate/sync-assets actions) is removed. actionIndex() is a stub;
 * Task 3 replaces it with the payload-driven load/entry command and deletes
 * this controller entirely.
 *
 * actionInstall() survives unmodified — it only drives Craft's
 * MigrationManager against src/migrations/ and has no dependency on the
 * removed pipeline.
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

    /**
     * Stub — the v1.x ETL pipeline is removed in this prune. Task 3 replaces
     * this action with the payload-driven load/entry command.
     */
    public function actionIndex(): int
    {
        if (($gate = $this->enforceNeverProduction()) !== null) {
            return $gate;
        }

        $this->stderr("replaced by load/entry in Task 3\n", Console::FG_RED);

        return 2;
    }
}
