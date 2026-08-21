<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator;

use Craft;
use craft\helpers\App;
use Lameco\KumaCompile\Mapping\Mapping;
use Throwable;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterComponentTypesEvent;
use craft\services\Utilities;
use lameco\kunstmaanmigrator\adapters\AdapterGate;
use lameco\kunstmaanmigrator\adapters\AdapterRegistry;
use lameco\kunstmaanmigrator\craft\CraftElementWriter;
use lameco\kunstmaanmigrator\craft\CraftPluginRegistry;
use lameco\kunstmaanmigrator\craft\VerbbNavigationGateway;
use lameco\kunstmaanmigrator\db\KunstmaanEnvReader;
use lameco\kunstmaanmigrator\db\LegacyDbService;
use lameco\kunstmaanmigrator\finalize\CkeditorFinalizeService;
use lameco\kunstmaanmigrator\finalize\CkeditorRewriterService;
use lameco\kunstmaanmigrator\load\AssetMigrationService;
use lameco\kunstmaanmigrator\load\EntryMigrationService;
use lameco\kunstmaanmigrator\load\MigrationStateService;
use lameco\kunstmaanmigrator\load\NavigationMigrationService;
use lameco\kunstmaanmigrator\load\RedirectMigrationService;
use lameco\kunstmaanmigrator\load\TranslationMigrationService;
use lameco\kunstmaanmigrator\load\SeoMigrationService;
use lameco\kunstmaanmigrator\load\SeomaticPayloadBuilder;
use lameco\kunstmaanmigrator\models\Settings;
use lameco\kunstmaanmigrator\utilities\MigrationUtility;
use PDO;
use yii\base\Event;
use yii\db\Connection;

/**
 * Kunstmaan Migrator plugin entrypoint (payload-driven Kunstmaan → Craft loader).
 *
 * v2 loader prune: the analyze / compile / mapping / extract / transform /
 * verify / locale / workflow / queue / audit stages and their CP console +
 * settings-page surface are removed. Task 9 additionally removed the
 * orchestration-era per-entry pipeline that the payload-driven loader
 * (Task 8) replaced — the field-handler registry (never read; the live
 * path resolves assets/relations via AssetMigrationService/
 * EntryMigrationService directly), the old atomic per-entry orchestrator,
 * and the unused CP/job-only production guard (NeverProductionTrait
 * remains the live console guard). What remains is the load-side core —
 * MigrationStateService, EntryMigrationService, CkeditorRewriterService,
 * the load-side adapters, and the `doctor` + `load` + `state` console
 * commands.
 *
 * @property-read LegacyDbService $legacyDbService
 * @property-read KunstmaanEnvReader $kunstmaanEnvReader
 * @property-read MigrationStateService $migrationStateService
 * @property-read CkeditorRewriterService $ckeditorRewriterService
 * @property-read CkeditorFinalizeService $ckeditorFinalizeService
 * @property-read AssetMigrationService $assetMigrationService
 * @property-read EntryMigrationService $entryMigrationService
 * @property-read SeoMigrationService $seoMigrationService
 * @property-read SeomaticPayloadBuilder $seomaticPayloadBuilder
 * @property-read RedirectMigrationService $redirectMigrationService
 * @property-read NavigationMigrationService $navigationMigrationService
 * @property-read TranslationMigrationService $translationMigrationService
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    // D-08: v2 starts below v1.x's 2.0.0.
    public string $schemaVersion = '1.1.0';

    // No CP settings page in the v2 loader core — settings come from env vars
    // and config/kunstmaan-migrator.php only (see CLAUDE.md ground rules).
    public bool $hasCpSettings = true;

    public static function config(): array
    {
        return [
            'components' => [
                'legacyDbService' => LegacyDbService::class,      // Phase 1 (literal preserved for PluginBootstrapTest)
                'kunstmaanEnvReader' => KunstmaanEnvReader::class, // Settings::beforeValidate() DSN auto-fill seam
                'migrationStateService'   => MigrationStateService::class,
                'ckeditorRewriterService' => CkeditorRewriterService::class,
                'ckeditorFinalizeService' => CkeditorFinalizeService::class,
                'assetMigrationService'   => AssetMigrationService::class,
                'entryMigrationService'   => EntryMigrationService::class,
                // Phase 4 additions — load-side adapter services.
                'seoMigrationService'        => SeoMigrationService::class,
                'seomaticPayloadBuilder'     => SeomaticPayloadBuilder::class,
                'redirectMigrationService'   => RedirectMigrationService::class,
                'navigationMigrationService' => NavigationMigrationService::class,
                'translationMigrationService' => TranslationMigrationService::class,
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
        // No CP controllers/utility/settings surface remains in the v2 loader core.
        // D-03: console controllerNamespace points at the flat src/console/ directory.
        $this->controllerNamespace = Craft::$app->request->getIsConsoleRequest()
            ? 'lameco\\kunstmaanmigrator\\console'
            : 'lameco\\kunstmaanmigrator\\controllers';

        // A Utility, not a CP section: a tool used a handful of times per project
        // has no business in the nav beside Entries.
        Event::on(
            Utilities::class,
            Utilities::EVENT_REGISTER_UTILITIES,
            static function (RegisterComponentTypesEvent $event): void {
                $event->types[] = MigrationUtility::class;
            },
        );

        // CkeditorRewriterService deps (FIN-01 + FIN-02). assetResolver is typed
        // ?object — AssetMigrationService satisfies the duck-typed surface.
        // The finalize pass shares the one rewriter, so its lazily-warmed caches are warmed once.
        $this->ckeditorFinalizeService->rewriter      = $this->ckeditorRewriterService;
        $this->ckeditorFinalizeService->elementWriter = new CraftElementWriter();

        $this->ckeditorRewriterService->migrationState = $this->migrationStateService;
        $this->ckeditorRewriterService->legacyDb       = $this->legacyDbService;
        $this->ckeditorRewriterService->assetResolver  = $this->assetMigrationService;

        // AssetMigrationService deps.
        $this->assetMigrationService->legacyDb       = $this->legacyDbService;
        $this->assetMigrationService->migrationState = $this->migrationStateService;
        $this->assetMigrationService->elementWriter  = new CraftElementWriter();
        $settingsTargetVolume = (string) ($this->getSettings()->targetVolume ?? '');
        if ($settingsTargetVolume !== '') {
            $this->assetMigrationService->targetVolume = $settingsTargetVolume;
        }
        $this->assetMigrationService->skipAssetSizeValidation =
            (bool) ($this->getSettings()->skipAssetSizeValidation ?? false);

        // $sites is the kuma_locale → Craft site handle map. It is deliberately empty here:
        // locale → site is a per-environment fact (COM's `en` is comEnUs, LV's is comLvEn) and
        // the mapping states it per environment, so MigrateController::applySites() fills it
        // from there for the environment being run. Empty is tolerated at init() —
        // saveEntryForSites() throws on first access if nothing has set it.
        $this->entryMigrationService->stateService   = $this->migrationStateService;
        $this->entryMigrationService->elementWriter = new CraftElementWriter();
        $this->entryMigrationService->sites         = [];

        // Phase 4 Adapter wiring — D-54 / D-56 / D-57 / PATTERNS flag #7.

        // SeomaticPayloadBuilder needs migrationState for kuma_media → Craft asset id resolution.
        $this->seomaticPayloadBuilder->migrationState = $this->migrationStateService;

        // SeoMigrationService — 5 sibling deps + sites map set per environment.
        $adapterGate = new AdapterGate(new CraftPluginRegistry(), $this->getSettings());
        $this->seoMigrationService->adapterGate         = $adapterGate;
        $this->redirectMigrationService->adapterGate    = $adapterGate;
        $this->navigationMigrationService->adapterGate  = $adapterGate;
        $this->translationMigrationService->adapterGate = $adapterGate;

        $this->seoMigrationService->legacyDb      = $this->legacyDbService;
        $this->seoMigrationService->stateService  = $this->migrationStateService;
        $this->seoMigrationService->seoPayload    = $this->seomaticPayloadBuilder;
        $this->seoMigrationService->elementWriter = new CraftElementWriter();

        // RedirectMigrationService — 3 sibling deps + sites map (per environment).
        $this->redirectMigrationService->legacyDb     = $this->legacyDbService;
        $this->redirectMigrationService->stateService = $this->migrationStateService;

        // NavigationMigrationService — same shape as Redirect adapter.
        // verbb/navigation node migration. Reads kuma_menu + kuma_menu_item,
        // resolves entry-typed nodes via state map. Source-table overrides
        // flow from Settings below.
        $this->navigationMigrationService->legacyDb      = $this->legacyDbService;
        $this->navigationMigrationService->stateService  = $this->migrationStateService;
        $this->navigationMigrationService->elementWriter = new CraftElementWriter();
        $this->navigationMigrationService->navigationGateway = new VerbbNavigationGateway();

        // TranslationMigrationService — kuma_translation → Craft site
        // translations PHP catalogs (+ enupal-translate DB rows). Same
        // wiring shape as the other adapters.
        $this->translationMigrationService->legacyDb     = $this->legacyDbService;
        $this->translationMigrationService->stateService = $this->migrationStateService;

        // D-57: Settings table-name overrides wired here so adapter services pick them up.
        $settings = $this->getSettings();

        if (is_string($settings->nodeMenuNavHandle) && $settings->nodeMenuNavHandle !== '') {
            $this->navigationMigrationService->nodeMenuNavHandle = $settings->nodeMenuNavHandle;
        }
        if (is_array($settings->nodeMenuExcludedInternalNames)) {
            $this->navigationMigrationService->nodeMenuExcludedInternalNames = $settings->nodeMenuExcludedInternalNames;
        }
        if (is_array($settings->translationDomains) && $settings->translationDomains !== []) {
            $this->translationMigrationService->allowedDomains = $settings->translationDomains;
        }
    }


    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    /**
     * The settings screen.
     *
     * The adapter table is rendered from AdapterRegistry rather than from four
     * hard-coded rows, so a project that registers its own adapter through
     * EVENT_REGISTER_ADAPTERS gets a row without this template changing.
     */
    protected function settingsHtml(): ?string
    {
        $registry = new AdapterRegistry();
        $plugins = new CraftPluginRegistry();
        $detected = [];

        foreach ($registry->all() as $adapter) {
            $detected[$adapter->handle] = $adapter->pluginHandle === null
                ? null
                : $plugins->versionOf($adapter->pluginHandle);
        }

        return Craft::$app->getView()->renderTemplate('kunstmaan-migrator/settings', [
            'settings' => $this->getSettings(),
            'adapters' => $registry->all(),
            'detected' => $detected,
            'environments' => $this->declaredEnvironments(),
        ]);
    }

    /**
     * The environments the mapping declares, for the settings screen to show.
     *
     * Read-only on purpose. A control panel form is worse than a YAML file at
     * exactly the parts a real corpus needs: Enreach's DE environment looks for
     * uploads in its own directory and then falls back to COM's, and its COM
     * environment marks two locales as deliberately not migrated with a reason
     * attached — an ordered fallback list and a "no, and here is why" that a
     * checkbox cannot express. It also belongs in the same reviewable diff as
     * the field mappings it travels with.
     *
     * @return array<string, array<string, mixed>>
     */
    private function declaredEnvironments(): array
    {
        $path = App::parseEnv($this->getSettings()->mappingPath);

        if (!is_string($path) || $path === '' || !is_file($path)) {
            return [];
        }

        try {
            return Mapping::fromFile($path)->environments();
        } catch (Throwable) {
            // A broken mapping is the migrate command's problem to report properly;
            // the settings screen just shows nothing rather than fataling.
            return [];
        }
    }
}
