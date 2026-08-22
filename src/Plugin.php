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
use lameco\kunstmaanmigrator\load\FormMigrationService;
use lameco\kunstmaanmigrator\load\GlobalsMigrationService;
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
 * Kunstmaan Migrator plugin entrypoint.
 *
 * Reads a legacy Kunstmaan database, compiles it against a mapping, and writes
 * it into Craft. The compile half lives in `lib/kuma-compile/` and knows nothing
 * about Craft; this half owns the writing, the state table that makes it
 * idempotent, the adapters that follow the entries, and the two operator
 * surfaces — a console command and a control-panel utility, both running the
 * same EnvironmentPipeline.
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
 * @property-read FormMigrationService $formMigrationService
 * @property-read GlobalsMigrationService $globalsMigrationService
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    // D-08: v2 starts below v1.x's 2.0.0.
    public string $schemaVersion = '1.1.0';

    /**
     * The connection and the adapter switches are settings; the topology is not.
     * Which databases exist, where their uploads are and which legacy locale
     * writes to which Craft site all come from the mapping, which the settings
     * screen shows read-only. See settingsHtml().
     */
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
                'formMigrationService'        => FormMigrationService::class,
                'globalsMigrationService'     => GlobalsMigrationService::class,
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
            $connection = $this->getSettings()->legacyConnection();
            Craft::$app->set('legacyDb', [
                'class'       => Connection::class,
                'dsn'         => sprintf(
                    'mysql:host=%s;port=%d;dbname=%s',
                    $connection['host'],
                    $connection['port'],
                    // The database name is per environment and comes from the mapping;
                    // this registration is only the fallback for commands that run
                    // outside a migration.
                    (string) App::parseEnv($this->getSettings()->legacyDbDatabase),
                ),
                'username'    => $connection['user'],
                'password'    => $connection['password'],
                'charset'     => $connection['charset'],
                'tablePrefix' => $connection['tablePrefix'],
                'attributes'  => [PDO::ATTR_EMULATE_PREPARES => false],
            ]);
        }

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

        // Deferred: none of this is needed to answer a request that never reaches
        // the migrator, and until now all twelve components were built and ~25
        // properties patched onto them on every front-end render and every
        // unrelated console command. onInit() runs once the application is up,
        // which is early enough for a console command and late enough to cost a
        // cached page nothing.
        Craft::$app->onInit(function (): void {
            $this->wireServices();
        });
    }

    /**
     * The object graph, assembled by hand.
     *
     * Property injection rather than constructors, which is a shallow seam: every
     * dependency is a nullable public property, so every use site has to cope with
     * "not wired yet" and no interface records what a module actually needs.
     * Constructor injection against the named interfaces is the standing follow-up.
     */
    private function wireServices(): void
    {
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
        $this->formMigrationService->adapterGate        = $adapterGate;
        $this->formMigrationService->stateService       = $this->migrationStateService;

        $this->globalsMigrationService->adapterGate       = $adapterGate;
        $this->globalsMigrationService->stateService      = $this->migrationStateService;
        $this->globalsMigrationService->elementWriter     = new CraftElementWriter();
        $this->globalsMigrationService->navigationGateway = new VerbbNavigationGateway();

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
            'coreSettings' => Settings::coreSettings(),
            'overridden' => $this->configFileKeys(),
        ]);
    }

    /**
     * The settings this project pins in `config/kunstmaan-migrator.php`.
     *
     * A config file beats project config in Craft, so a field backed by one is
     * a field an operator can edit and save with no effect whatsoever. Enreach
     * pins two. Craft's own settings screens disable such fields and say why;
     * a screen that accepts a value it will ignore is worse than one that does
     * not offer it.
     *
     * @return list<string>
     */
    private function configFileKeys(): array
    {
        $config = Craft::$app->getConfig()->getConfigFromFile($this->handle);

        return array_values(array_map(strval(...), array_keys($config)));
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
