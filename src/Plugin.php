<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use lameco\kunstmaanmigrator\db\KunstmaanEnvReader;
use lameco\kunstmaanmigrator\db\LegacyDbService;
use lameco\kunstmaanmigrator\fields\FieldHandlerRegistry;
use lameco\kunstmaanmigrator\fields\handlers\AssetHandler;
use lameco\kunstmaanmigrator\fields\handlers\MatrixHandler;
use lameco\kunstmaanmigrator\fields\handlers\PlainTextHandler;
use lameco\kunstmaanmigrator\fields\handlers\RelationHandler;
use lameco\kunstmaanmigrator\fields\handlers\SplitNameHandler;
use lameco\kunstmaanmigrator\finalize\CkeditorRewriterService;
use lameco\kunstmaanmigrator\load\AssetMigrationService;
use lameco\kunstmaanmigrator\load\AtomicMigrationService;
use lameco\kunstmaanmigrator\load\AttachService;
use lameco\kunstmaanmigrator\load\EntryMigrationService;
use lameco\kunstmaanmigrator\load\MigrationStateService;
use lameco\kunstmaanmigrator\load\NavigationMigrationService;
use lameco\kunstmaanmigrator\load\RedirectMigrationService;
use lameco\kunstmaanmigrator\load\TranslationMigrationService;
use lameco\kunstmaanmigrator\load\SeoMigrationService;
use lameco\kunstmaanmigrator\load\SeomaticPayloadBuilder;
use lameco\kunstmaanmigrator\models\Settings;
use lameco\kunstmaanmigrator\safety\MigrationSafety;
use PDO;
use yii\db\Connection;

/**
 * Kunstmaan → Craft Migrator plugin entrypoint.
 *
 * v2 loader prune: the analyze / compile / mapping / extract / transform /
 * verify / locale / workflow / queue / audit stages and their CP console +
 * settings-page surface are removed. What remains is the load-side core —
 * MigrationStateService, EntryMigrationService, the field-handler registry,
 * CkeditorRewriterService, the load-side adapters, and the `doctor` +
 * `load` console commands — the base later tasks build the
 * payload-driven loader on top of.
 *
 * @property-read LegacyDbService $legacyDbService
 * @property-read KunstmaanEnvReader $kunstmaanEnvReader
 * @property-read FieldHandlerRegistry $fieldHandlerRegistry
 * @property-read PlainTextHandler $plainTextHandler
 * @property-read AssetHandler $assetHandler
 * @property-read RelationHandler $relationHandler
 * @property-read MatrixHandler $matrixHandler
 * @property-read SplitNameHandler $splitNameHandler
 * @property-read MigrationStateService $migrationStateService
 * @property-read CkeditorRewriterService $ckeditorRewriterService
 * @property-read AtomicMigrationService $atomicMigrationService
 * @property-read AssetMigrationService $assetMigrationService
 * @property-read EntryMigrationService $entryMigrationService
 * @property-read AttachService $attachService
 * @property-read SeoMigrationService $seoMigrationService
 * @property-read SeomaticPayloadBuilder $seomaticPayloadBuilder
 * @property-read RedirectMigrationService $redirectMigrationService
 * @property-read NavigationMigrationService $navigationMigrationService
 * @property-read TranslationMigrationService $translationMigrationService
 * @property-read MigrationSafety $migrationSafety
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    // D-08: v2 starts below v1.x's 2.0.0; Phase 12 bumps for run-record migrations.
    public string $schemaVersion = '1.1.0';

    // No CP settings page in the v2 loader core — settings come from env vars
    // and config/kunstmaan-migrator.php only (see CLAUDE.md ground rules).
    public bool $hasCpSettings = false;

    public static function config(): array
    {
        return [
            'components' => [
                'legacyDbService' => LegacyDbService::class,      // Phase 1 (literal preserved for PluginBootstrapTest)
                'kunstmaanEnvReader' => KunstmaanEnvReader::class, // Settings::beforeValidate() DSN auto-fill seam
                // Phase 3 additions — field handlers + finalize.
                'fieldHandlerRegistry'    => FieldHandlerRegistry::class,
                'plainTextHandler'        => PlainTextHandler::class,    // mode='plain' default; init() registers 4 modes
                'assetHandler'            => AssetHandler::class,
                'relationHandler'         => RelationHandler::class,
                'matrixHandler'           => MatrixHandler::class,
                'splitNameHandler'        => SplitNameHandler::class,
                'migrationStateService'   => MigrationStateService::class,
                'ckeditorRewriterService' => CkeditorRewriterService::class,
                'atomicMigrationService'  => AtomicMigrationService::class,
                'assetMigrationService'   => AssetMigrationService::class,
                'entryMigrationService'   => EntryMigrationService::class,
                'attachService'           => AttachService::class,
                // Phase 4 additions — load-side adapter services.
                'seoMigrationService'        => SeoMigrationService::class,
                'seomaticPayloadBuilder'     => SeomaticPayloadBuilder::class,
                'redirectMigrationService'   => RedirectMigrationService::class,
                'navigationMigrationService' => NavigationMigrationService::class,
                'translationMigrationService' => TranslationMigrationService::class,
                'migrationSafety'      => MigrationSafety::class,
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
        if (Craft::$app->request->getIsConsoleRequest()) {
            $this->controllerNamespace = 'lameco\\kunstmaanmigrator\\console';
        }

        // Field handler registry — register all PlainTextHandler modes and the 4 other
        // typed handlers. PlainTextHandler is parametric on its mode constructor arg
        // ('plain' / 'ckeditor' / 'link' / 'dropdown'); each mode registers under its
        // own id() so the registry can dispatch by handler-name from mapping data.
        $registry = $this->fieldHandlerRegistry;
        $registry->register(new PlainTextHandler('plain'));
        $registry->register(new PlainTextHandler('date'));
        $registry->register(new PlainTextHandler('ckeditor'));
        $registry->register(new PlainTextHandler('link'));
        $registry->register(new PlainTextHandler('email'));
        $registry->register(new PlainTextHandler('url'));
        $registry->register(new PlainTextHandler('dropdown'));
        $registry->register($this->assetHandler);
        $registry->register($this->relationHandler);
        $registry->register($this->matrixHandler);
        $registry->register($this->splitNameHandler);

        // AssetHandler resolves deferred asset tokens via AssetMigrationService.
        $this->assetHandler->assetResolver = $this->assetMigrationService;

        // CkeditorRewriterService deps (FIN-01 + FIN-02). assetResolver is typed
        // ?object — AssetMigrationService satisfies the duck-typed surface.
        $this->ckeditorRewriterService->migrationState = $this->migrationStateService;
        $this->ckeditorRewriterService->legacyDb       = $this->legacyDbService;
        $this->ckeditorRewriterService->assetResolver  = $this->assetMigrationService;

        // AssetMigrationService deps.
        $this->assetMigrationService->legacyDb       = $this->legacyDbService;
        $this->assetMigrationService->migrationState = $this->migrationStateService;
        $settingsTargetVolume = (string) ($this->getSettings()->targetVolume ?? '');
        if ($settingsTargetVolume !== '') {
            $this->assetMigrationService->targetVolume = $settingsTargetVolume;
        }
        $this->assetMigrationService->skipAssetSizeValidation =
            (bool) ($this->getSettings()->skipAssetSizeValidation ?? false);

        // AtomicMigrationService deps — orchestrates per-entry transactional load.
        $this->atomicMigrationService->migrationStateService = $this->migrationStateService;
        $this->atomicMigrationService->entryMigrationService = $this->entryMigrationService;
        $this->atomicMigrationService->assetMigrationService = $this->assetMigrationService;

        // EntryMigrationService deps. $sites is the kuma_locale → Craft site handle map
        // composed from Settings::$localeMap (D-28 ladder, simplified — see
        // resolveSitesMap()). Empty map is tolerated at init() — saveEntryForSites()
        // throws on first access if the operator hasn't configured locales yet,
        // surfacing a clear error.
        $this->entryMigrationService->stateService = $this->migrationStateService;
        $this->entryMigrationService->sites        = $this->resolveSitesMap();

        // Phase 4 Adapter wiring — D-54 / D-56 / D-57 / PATTERNS flag #7.

        // SeomaticPayloadBuilder needs migrationState for kuma_media → Craft asset id resolution.
        $this->seomaticPayloadBuilder->migrationState = $this->migrationStateService;

        // SeoMigrationService — 5 sibling deps + sites map from resolveSitesMap() (PATTERNS flag #3).
        $this->seoMigrationService->legacyDb     = $this->legacyDbService;
        $this->seoMigrationService->stateService = $this->migrationStateService;
        $this->seoMigrationService->seoPayload   = $this->seomaticPayloadBuilder;
        $this->seoMigrationService->sites        = $this->resolveSitesMap();
        // $filters is intentionally left null here — FilterFactory (which used to wire it
        // at command-invocation time) was removed in the v2 loader prune; no replacement
        // wiring exists yet, so every consumer's ?MigrationFilters $filters defaults to
        // "unbounded" until a later task reintroduces command-line filter wiring.

        // RedirectMigrationService — 3 sibling deps + sites map.
        $this->redirectMigrationService->legacyDb     = $this->legacyDbService;
        $this->redirectMigrationService->stateService = $this->migrationStateService;
        $this->redirectMigrationService->sites        = $this->resolveSitesMap();
        // $filters wired at invocation time.

        // NavigationMigrationService — same shape as Redirect adapter.
        // verbb/navigation node migration. Reads kuma_menu + kuma_menu_item,
        // resolves entry-typed nodes via state map. $filters wired at
        // invocation time. Source-table overrides flow from Settings below.
        $this->navigationMigrationService->legacyDb     = $this->legacyDbService;
        $this->navigationMigrationService->stateService = $this->migrationStateService;
        $this->navigationMigrationService->sites        = $this->resolveSitesMap();

        // TranslationMigrationService — kuma_translation → Craft site
        // translations PHP catalogs (+ enupal-translate DB rows). Same
        // wiring shape as the other adapters.
        $this->translationMigrationService->legacyDb     = $this->legacyDbService;
        $this->translationMigrationService->stateService = $this->migrationStateService;
        $this->translationMigrationService->sites        = $this->resolveSitesMap();

        // D-57: Settings table-name overrides wired here so adapter services pick them up.
        $settings = $this->getSettings();

        if (is_string($settings->seoTableName) && $settings->seoTableName !== '') {
            $this->seoMigrationService->seoTableName = $settings->seoTableName;
        }
        if (is_string($settings->redirectsTableName) && $settings->redirectsTableName !== '') {
            $this->redirectMigrationService->redirectsTableName = $settings->redirectsTableName;
        }
        if (is_string($settings->menuTableName) && $settings->menuTableName !== '') {
            $this->navigationMigrationService->menuTableName = $settings->menuTableName;
        }
        if (is_string($settings->menuItemTableName) && $settings->menuItemTableName !== '') {
            $this->navigationMigrationService->menuItemTableName = $settings->menuItemTableName;
        }
        if (is_string($settings->nodesTableName) && $settings->nodesTableName !== '') {
            $this->navigationMigrationService->nodesTableName = $settings->nodesTableName;
        }
        if (is_string($settings->nodeMenuNavHandle) && $settings->nodeMenuNavHandle !== '') {
            $this->navigationMigrationService->nodeMenuNavHandle = $settings->nodeMenuNavHandle;
        }
        if (is_array($settings->nodeMenuExcludedInternalNames)) {
            $this->navigationMigrationService->nodeMenuExcludedInternalNames = $settings->nodeMenuExcludedInternalNames;
        }
        if (is_string($settings->translationTableName) && $settings->translationTableName !== '') {
            $this->translationMigrationService->translationTableName = $settings->translationTableName;
        }
        if (is_array($settings->translationDomains) && $settings->translationDomains !== []) {
            $this->translationMigrationService->allowedDomains = $settings->translationDomains;
        }
    }

    /**
     * Build the kuma_locale → Craft site handle map used by EntryMigrationService::$sites.
     *
     * v2 loader prune: the mapping.yaml `sites:` block and LocalePreflight's
     * detection/language-prefix ladder were analyze-stage machinery, removed
     * along with src/mapping/ and src/locale/. The only remaining input is
     * the operator-curated Settings::$localeMap explicit override; an empty
     * map degrades to `[]` (EntryMigrationService throws a clear error on
     * first access if the operator hasn't configured locales yet).
     *
     * @return array<string, string> kuma_locale code → Craft site handle
     */
    private function resolveSitesMap(): array
    {
        $out = [];
        foreach ($this->getSettings()->localeMap as $legacy => $handle) {
            if (is_string($legacy) && is_string($handle) && $legacy !== '' && $handle !== '') {
                $out[$legacy] = $handle;
            }
        }
        return $out;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }
}
