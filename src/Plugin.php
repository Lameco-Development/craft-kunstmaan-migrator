<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use lameco\kunstmaanmigrator\db\LegacyDbService;
use lameco\kunstmaanmigrator\filter\FilterFactory;
use lameco\kunstmaanmigrator\locale\LocalePreflight;
use lameco\kunstmaanmigrator\models\Settings;
use PDO;
use yii\db\Connection;

/**
 * Kunstmaan → Craft Migrator plugin entrypoint.
 *
 * @property-read LegacyDbService $legacyDbService
 * @property-read FilterFactory $filterFactory
 * @property-read LocalePreflight $localePreflight
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    // D-08: v2 declares 1.0.0 (NOT v1.x's 2.0.0).
    public string $schemaVersion = '1.0.0';

    // D-16: enables CP Settings page; placeholder template ships with this plan,
    // real form lives in Phase 4 / CFG-01.
    public bool $hasCpSettings = true;

    public static function config(): array
    {
        return [
            'components' => [
                'legacyDbService' => LegacyDbService::class,    // Phase 1
                'filterFactory'   => FilterFactory::class,      // Phase 2 (Plan 01) — D-10 Settings+CLI merge
                'localePreflight' => LocalePreflight::class,    // Phase 2 (Plan 01) — LOC-01 detect + LOC-02 ensure
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        // D-11: register the legacyDb Yii application component ONLY when the host has
        // not already declared one. On v1.x→v2 swap-in hosts the existing config/app.php
        // declaration wins (zero churn for operators); on greenfield hosts the plugin
        // fills the gap from Settings (which falls back to CRAFT_LEGACY_DB_* env vars).
        //
        // Use the `true` second arg to has() — it checks for a *registered* (vs
        // *instantiated*) component, which is the right check pre-first-access.
        if (!Craft::$app->has('legacyDb', true)) {
            /** @var Settings $settings */
            $settings = $this->getSettings();
            Craft::$app->set('legacyDb', [
                'class'       => Connection::class,
                'dsn'         => sprintf(
                    'mysql:host=%s;port=%d;dbname=%s',
                    (string) $settings->legacyDbServer,
                    $settings->legacyDbPort,
                    (string) $settings->legacyDbDatabase,
                ),
                'username'    => $settings->legacyDbUser,
                'password'    => $settings->legacyDbPassword,
                'charset'     => $settings->legacyDbCharset,
                'tablePrefix' => $settings->legacyDbTablePrefix,
                'attributes'  => [PDO::ATTR_EMULATE_PREPARES => false],
            ]);
        }

        // D-03: console controllerNamespace points at the flat src/console/ directory.
        // No web controller namespace yet — the CP Settings save handler lands in Phase 4
        // when CFG-01 introduces the real form.
        if (Craft::$app->request->getIsConsoleRequest()) {
            $this->controllerNamespace = 'lameco\\kunstmaanmigrator\\console';
        }
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->view->renderTemplate(
            'kunstmaan-migrator/_settings.twig',
            ['plugin' => $this, 'settings' => $this->getSettings()],
        );
    }
}
