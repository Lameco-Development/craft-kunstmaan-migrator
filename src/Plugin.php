<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterTemplateRootsEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\UserPermissions;
use craft\services\Utilities;
use craft\web\View as CraftView;
use lameco\kunstmaanmigrator\analyze\HeuristicProposer;
use lameco\kunstmaanmigrator\analyze\LlmClassifier;
use lameco\kunstmaanmigrator\analyze\ReportBuilder;
use lameco\kunstmaanmigrator\analyze\KunstmaanSchemaDumper;
use lameco\kunstmaanmigrator\audit\PageRootedCoverageAuditor;
use lameco\kunstmaanmigrator\audit\PageRootedSurfaceDiscovery;
use lameco\kunstmaanmigrator\compile\MappingCompiler;
use lameco\kunstmaanmigrator\compile\CraftTargetIntrospector;
use lameco\kunstmaanmigrator\db\LegacyDbService;
use lameco\kunstmaanmigrator\extract\ExtractService;
use lameco\kunstmaanmigrator\fields\FieldHandlerRegistry;
use lameco\kunstmaanmigrator\fields\handlers\AssetHandler;
use lameco\kunstmaanmigrator\fields\handlers\MatrixHandler;
use lameco\kunstmaanmigrator\fields\handlers\PlainTextHandler;
use lameco\kunstmaanmigrator\fields\handlers\RelationHandler;
use lameco\kunstmaanmigrator\fields\handlers\SplitNameHandler;
use lameco\kunstmaanmigrator\filter\FilterFactory;
use lameco\kunstmaanmigrator\finalize\CkeditorRewriterService;
use lameco\kunstmaanmigrator\finalize\FinalizeWalker;
use lameco\kunstmaanmigrator\load\AssetMigrationService;
use lameco\kunstmaanmigrator\load\AssetPathResolver;
use lameco\kunstmaanmigrator\load\AtomicMigrationService;
use lameco\kunstmaanmigrator\load\AttachService;
use lameco\kunstmaanmigrator\load\EntryMigrationService;
use lameco\kunstmaanmigrator\load\MigrationStateService;
use lameco\kunstmaanmigrator\load\NavigationMigrationService;
use lameco\kunstmaanmigrator\load\RedirectMigrationService;
use lameco\kunstmaanmigrator\load\TranslationMigrationService;
use lameco\kunstmaanmigrator\load\SeoMigrationService;
use lameco\kunstmaanmigrator\load\SeomaticPayloadBuilder;
use lameco\kunstmaanmigrator\load\TaxonomyMigrationService;
use lameco\kunstmaanmigrator\locale\LocalePreflight;
use lameco\kunstmaanmigrator\mapping\BlockAvailabilityValidator;
use lameco\kunstmaanmigrator\mapping\CoverageAuditor;
use lameco\kunstmaanmigrator\mapping\MappingAuditor;
use lameco\kunstmaanmigrator\mapping\MappingFile;
use lameco\kunstmaanmigrator\models\Settings;
use lameco\kunstmaanmigrator\runs\MigrationRunService;
use lameco\kunstmaanmigrator\safety\MigrationGateService;
use lameco\kunstmaanmigrator\safety\MigrationSafety;
use lameco\kunstmaanmigrator\source\BodyScanColumnFinder;
use lameco\kunstmaanmigrator\source\DetailTableResolver;
use lameco\kunstmaanmigrator\source\DoctrineEntityParser;
use lameco\kunstmaanmigrator\source\KunstmaanKnowledgeBase;
use lameco\kunstmaanmigrator\source\KunstmaanEnvReader;
use lameco\kunstmaanmigrator\source\CraftEntryWalker;
use lameco\kunstmaanmigrator\source\KunstmaanPageWalker;
use lameco\kunstmaanmigrator\source\KunstmaanPageStructureScanner;
use lameco\kunstmaanmigrator\source\KunstmaanSourcePathResolver;
use lameco\kunstmaanmigrator\source\KunstmaanSourceScanner;
use lameco\kunstmaanmigrator\source\CraftKnowledgeBase;
use lameco\kunstmaanmigrator\source\MediaFkScanner;
use lameco\kunstmaanmigrator\source\TopologicalOrderer;
use lameco\kunstmaanmigrator\transform\TransformService;
use lameco\kunstmaanmigrator\utilities\KunstmaanMappingUtility;
use lameco\kunstmaanmigrator\verify\BaselineCounterService;
use lameco\kunstmaanmigrator\verify\CaptureBaselineHtmlService;
use lameco\kunstmaanmigrator\verify\CountGateService;
use lameco\kunstmaanmigrator\verify\SnapshotDiffer;
use lameco\kunstmaanmigrator\verify\SpotCheckUrlFetcher;
use lameco\kunstmaanmigrator\workflow\AnalyzeWorkflow;
use lameco\kunstmaanmigrator\workflow\CompileWorkflow;
use lameco\kunstmaanmigrator\workflow\MigrateWorkflow;
use lameco\kunstmaanmigrator\workflow\VerifyWorkflow;
use PDO;
use yii\base\Event;
use yii\db\Connection;

/**
 * Kunstmaan → Craft Migrator plugin entrypoint.
 *
 * @property-read LegacyDbService $legacyDbService
 * @property-read FilterFactory $filterFactory
 * @property-read LocalePreflight $localePreflight
 * @property-read MappingFile $mappingFile
 * @property-read KunstmaanSchemaDumper $kunstmaanSchemaDumper
 * @property-read HeuristicProposer $heuristicProposer
 * @property-read LlmClassifier $llmClassifier
 * @property-read ReportBuilder $reportBuilder
 * @property-read CoverageAuditor $coverageAuditor
 * @property-read MappingAuditor $mappingAuditor
 * @property-read MappingCompiler $mappingCompiler
 * @property-read CraftTargetIntrospector $craftTargetIntrospector
 * @property-read PageRootedSurfaceDiscovery $pageRootedSurfaceDiscovery
 * @property-read PageRootedCoverageAuditor $pageRootedCoverageAuditor
 * @property-read KunstmaanSourcePathResolver $kunstmaanSourcePathResolver
 * @property-read KunstmaanEnvReader $kunstmaanEnvReader
 * @property-read DoctrineEntityParser $doctrineEntityParser
 * @property-read DetailTableResolver $detailTableResolver
 * @property-read BodyScanColumnFinder $bodyScanColumnFinder
 * @property-read MediaFkScanner $mediaFkScanner
 * @property-read KunstmaanKnowledgeBase $kunstmaanKnowledgeBase
 * @property-read CraftKnowledgeBase $craftKnowledgeBase
 * @property-read KunstmaanPageWalker $kunstmaanPageWalker
 * @property-read CraftEntryWalker $craftEntryWalker
 * @property-read KunstmaanPageStructureScanner $kunstmaanPageStructureScanner
 * @property-read KunstmaanSourceScanner $kunstmaanSourceScanner
 * @property-read BlockAvailabilityValidator $blockAvailabilityValidator
 * @property-read FieldHandlerRegistry $fieldHandlerRegistry
 * @property-read PlainTextHandler $plainTextHandler
 * @property-read AssetHandler $assetHandler
 * @property-read RelationHandler $relationHandler
 * @property-read MatrixHandler $matrixHandler
 * @property-read SplitNameHandler $splitNameHandler
 * @property-read MigrationStateService $migrationStateService
 * @property-read CkeditorRewriterService $ckeditorRewriterService
 * @property-read FinalizeWalker $finalizeWalker
 * @property-read ExtractService $extractService
 * @property-read TransformService $transformService
 * @property-read AtomicMigrationService $atomicMigrationService
 * @property-read AssetMigrationService $assetMigrationService
 * @property-read EntryMigrationService $entryMigrationService
 * @property-read AttachService $attachService
 * @property-read SeoMigrationService $seoMigrationService
 * @property-read SeomaticPayloadBuilder $seomaticPayloadBuilder
 * @property-read RedirectMigrationService $redirectMigrationService
 * @property-read NavigationMigrationService $navigationMigrationService
 * @property-read TranslationMigrationService $translationMigrationService
 * @property-read TaxonomyMigrationService $taxonomyMigrationService
 * @property-read BaselineCounterService $baselineCounterService
 * @property-read CountGateService $countGateService
 * @property-read SnapshotDiffer $snapshotDiffer
 * @property-read SpotCheckUrlFetcher $spotCheckUrlFetcher
 * @property-read CaptureBaselineHtmlService $captureBaselineHtmlService
 * @property-read MigrationRunService $migrationRunService
 * @property-read MigrationSafety $migrationSafety
 * @property-read MigrationGateService $migrationGateService
 * @property-read AnalyzeWorkflow $analyzeWorkflow
 * @property-read CompileWorkflow $compileWorkflow
 * @property-read MigrateWorkflow $migrateWorkflow
 * @property-read VerifyWorkflow $verifyWorkflow
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const PERMISSION_REVIEW_MAPPING = 'kunstmaan-migrator:reviewMapping';
    public const PERMISSION_RUN_MIGRATIONS = 'kunstmaan-migrator:runMigrations';

    // D-08: v2 starts below v1.x's 2.0.0; Phase 12 bumps for run-record migrations.
    public string $schemaVersion = '1.1.0';

    // D-16: enables CP Settings page; placeholder template ships with this plan,
    // real form lives in Phase 4 / CFG-01.
    public bool $hasCpSettings = true;

    public static function config(): array
    {
        return [
            'components' => [
                'legacyDbService' => LegacyDbService::class,      // Phase 1 (literal preserved for PluginBootstrapTest)
                'filterFactory'     => FilterFactory::class,      // Phase 2 (Plan 01) — D-10 Settings+CLI merge
                'localePreflight'   => LocalePreflight::class,    // Phase 2 (Plan 01) — LOC-01 detect + LOC-02 ensure
                'mappingFile'       => MappingFile::class,        // Phase 2 (Plan 02) — D-01/D-04/D-07 status-on-row IO
                'kunstmaanSchemaDumper'      => KunstmaanSchemaDumper::class,       // Phase 2 (Plan 03) — legacy MySQL → kunstmaan-schema array
                'heuristicProposer' => HeuristicProposer::class,  // Phase 2 (Plan 03) — 9 deterministic heuristics
                'llmClassifier'     => LlmClassifier::class,      // Phase 2 (Plan 03) — Anthropic Haiku batch caller
                'reportBuilder'     => ReportBuilder::class,      // Phase 2 (Plan 03) — D-17 paste-ready locales block
                'coverageAuditor'   => CoverageAuditor::class,    // Phase 2 (Plan 05) — D-14 MAP-06
                'mappingAuditor'    => MappingAuditor::class,     // Phase 2 (Plan 05) — D-16 MAP-07
                'mappingCompiler'   => MappingCompiler::class,    // Phase 6 — proposals[] → nodeClasses/sections/sites bridge
                'craftTargetIntrospector' => CraftTargetIntrospector::class, // Phase 9 — compiled target schema validation
                'pageRootedSurfaceDiscovery' => PageRootedSurfaceDiscovery::class, // Phase 9 — structural Page-rooted surface discovery
                'pageRootedCoverageAuditor'  => PageRootedCoverageAuditor::class,  // Phase 9 — deterministic Page-rooted coverage report
                'kunstmaanSourcePathResolver' => KunstmaanSourcePathResolver::class, // Phase 02.1 (Plan 01) — D-33 source-path resolver
                'kunstmaanEnvReader'            => KunstmaanEnvReader::class,           // Phase 4.1 / D-05 — .env reader, 2-key whitelist
                'doctrineEntityParser'          => DoctrineEntityParser::class,         // Phase 02.1 (Plan 02) — D-41 verbatim port
                'detailTableResolver'           => DetailTableResolver::class,          // Phase 02.1 (Plan 02) — 4-tier FQCN→table
                'bodyScanColumnFinder'          => BodyScanColumnFinder::class,         // Phase 02.1 (Plan 03) — body-col discovery
                'mediaFkScanner'                => MediaFkScanner::class,               // Phase 02.1 (Plan 03) — kuma_media FK discovery
                'kunstmaanKnowledgeBase'                 => KunstmaanKnowledgeBase::class,                // Phase 02.1 (Plan 03) — D-42 step 8 KB markdown
                'craftKnowledgeBase'            => CraftKnowledgeBase::class,           // Phase 6 — target Craft schema renderer for LLM prompts
                'kunstmaanPageWalker'           => KunstmaanPageWalker::class,          // Phase 11 — source graph walker
                'craftEntryWalker'              => CraftEntryWalker::class,             // Phase 11 — target graph walker
                'kunstmaanPageStructureScanner' => KunstmaanPageStructureScanner::class, // Phase 02.1 (Plan 04) — D-40 right side
                'kunstmaanSourceScanner'        => KunstmaanSourceScanner::class,        // Phase 02.1 (Plan 05) — D-40 left side orchestrator
                'blockAvailabilityValidator'    => BlockAvailabilityValidator::class,    // Phase 02.1 (Plan 08) — D-36 fourth finding kind
                // Phase 3 additions — ETL pipeline + handlers + finalize.
                'fieldHandlerRegistry'    => FieldHandlerRegistry::class,
                'plainTextHandler'        => PlainTextHandler::class,    // mode='plain' default; init() registers 4 modes
                'assetHandler'            => AssetHandler::class,
                'relationHandler'         => RelationHandler::class,
                'matrixHandler'           => MatrixHandler::class,
                'splitNameHandler'        => SplitNameHandler::class,
                'migrationStateService'   => MigrationStateService::class,
                'ckeditorRewriterService' => CkeditorRewriterService::class,
                'finalizeWalker'          => FinalizeWalker::class,
                'extractService'          => ExtractService::class,
                'transformService'        => TransformService::class,
                'atomicMigrationService'  => AtomicMigrationService::class,
                'assetMigrationService'   => AssetMigrationService::class,
                'entryMigrationService'   => EntryMigrationService::class,
                'attachService'           => AttachService::class,
                // Phase 4 additions — adapter services + verify services.
                'seoMigrationService'        => SeoMigrationService::class,
                'seomaticPayloadBuilder'     => SeomaticPayloadBuilder::class,
                'redirectMigrationService'   => RedirectMigrationService::class,
                'navigationMigrationService' => NavigationMigrationService::class,
                'translationMigrationService' => TranslationMigrationService::class,
                // Phase 8 / D-08 — verbatim-ported taxonomy load service (TAX-08).
                'taxonomyMigrationService'   => TaxonomyMigrationService::class,
                'baselineCounterService'     => BaselineCounterService::class,
                'countGateService'           => CountGateService::class,
                'snapshotDiffer'             => SnapshotDiffer::class,
                'spotCheckUrlFetcher'        => SpotCheckUrlFetcher::class,
                'captureBaselineHtmlService' => CaptureBaselineHtmlService::class,
                // Phase 12 additions — CP migration console, run records, safety gates, and queue-ready workflows.
                'migrationRunService'  => MigrationRunService::class,
                'migrationSafety'      => MigrationSafety::class,
                'migrationGateService' => MigrationGateService::class,
                'analyzeWorkflow'      => AnalyzeWorkflow::class,
                'compileWorkflow'      => CompileWorkflow::class,
                'migrateWorkflow'      => MigrateWorkflow::class,
                'verifyWorkflow'       => VerifyWorkflow::class,
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
        } else {
            $this->controllerNamespace = 'lameco\\kunstmaanmigrator\\controllers';
            Event::on(
                Utilities::class,
                Utilities::EVENT_REGISTER_UTILITIES,
                static function (RegisterComponentTypesEvent $event): void {
                    $event->types[] = KunstmaanMappingUtility::class;
                },
            );
            Event::on(
                UserPermissions::class,
                UserPermissions::EVENT_REGISTER_PERMISSIONS,
                static function (RegisterUserPermissionsEvent $event): void {
                    $event->permissions[] = [
                        'heading' => 'Kunstmaan migrator',
                        'permissions' => [
                            self::PERMISSION_REVIEW_MAPPING => [
                                'label' => 'Review and edit the migration mapping',
                            ],
                            self::PERMISSION_RUN_MIGRATIONS => [
                                'label' => 'Queue migration stages from the control panel',
                            ],
                        ],
                    ];
                },
            );
        }

        Event::on(
            CraftView::class,
            CraftView::EVENT_REGISTER_CP_TEMPLATE_ROOTS,
            static function (RegisterTemplateRootsEvent $event): void {
                $event->roots['kunstmaan-migrator'] = dirname(__DIR__) . '/templates';
            },
        );

        // Phase 02.1 follow-up: wire the source-namespace components' sibling
        // dependencies. Plugin::config() registers them as bare class names so
        // each is instantiated with its public ?Foo $dep = null properties unset.
        // Yii component config supports nested DI but we keep config() declarative
        // and inject here once everything is registered. Without this wiring the
        // KunstmaanSourceScanner::scan() short-circuits to emptyResult() because
        // entityParser stays null, and AnalyzeController reports "0 entities, 0 tables".
        $this->kunstmaanSourceScanner->pathResolver  = $this->kunstmaanSourcePathResolver;
        $this->kunstmaanSourceScanner->entityParser  = $this->doctrineEntityParser;
        $this->kunstmaanSourceScanner->tableResolver = $this->detailTableResolver;
        $this->kunstmaanSourceScanner->bodyFinder    = $this->bodyScanColumnFinder;
        $this->kunstmaanSourceScanner->mediaScanner  = $this->mediaFkScanner;
        $this->kunstmaanSourceScanner->legacyDb      = $this->legacyDbService;

        // Phase 8.4 / D-17 — prime sourceCheckoutPath on the entity parser at
        // plugin init so commands other than analyze (migrate, doctor) get a
        // warmable parser too. Without this priming the parser stays empty
        // outside the analyze pipeline (KunstmaanSourceScanner::scan() is the
        // only other code path that sets the property), DetailTableResolver
        // throws on every PagePart FQCN → ExtractService::loadPageParts()
        // silently drops every kuma_page_part_refs row → migrate transforms
        // every page with empty pageBuilder content. Cross-wire entityParser
        // onto the resolver too (mirrors the scan-time wire on line ~131 of
        // KunstmaanSourceScanner).
        $sourceCheckoutPath = $this->kunstmaanSourcePathResolver->resolve();
        if (is_string($sourceCheckoutPath) && $sourceCheckoutPath !== '') {
            $this->doctrineEntityParser->sourceCheckoutPath = $sourceCheckoutPath;
            if ($this->detailTableResolver->entityParser === null) {
                $this->detailTableResolver->entityParser = $this->doctrineEntityParser;
            }
        }

        // KunstmaanKnowledgeBase needs legacyDb for renderPagesMarkdown / renderPagePartsMarkdown
        // (used by AnalyzeController's LLM classifier step). Without this wire the LLM
        // pass throws LogicException at first KB render.
        $this->kunstmaanKnowledgeBase->legacyDb     = $this->legacyDbService;
        $this->kunstmaanKnowledgeBase->entityParser = $this->doctrineEntityParser;

        $this->kunstmaanPageStructureScanner->pathResolver  = $this->kunstmaanSourcePathResolver;
        $this->kunstmaanPageStructureScanner->tableResolver = $this->detailTableResolver;

        $this->kunstmaanPageWalker->pathResolver = $this->kunstmaanSourcePathResolver;
        $this->kunstmaanPageWalker->entityParser = $this->doctrineEntityParser;
        $this->kunstmaanPageWalker->sourceScanner = $this->kunstmaanSourceScanner;
        $this->kunstmaanPageWalker->pageStructureScanner = $this->kunstmaanPageStructureScanner;
        $this->kunstmaanPageWalker->kunstmaanSchemaDumper = $this->kunstmaanSchemaDumper;
        $this->craftEntryWalker->craftKnowledgeBase = $this->craftKnowledgeBase;

        // MappingAuditor's block-availability check (D-36) is inert when the
        // validator stays null. Wire it so the audit step actually fires.
        $this->mappingAuditor->blockAvailabilityValidator = $this->blockAvailabilityValidator;

        // Phase 3 / 75a95bc sibling-DI — handlers + ETL services + finalize. See Plan 03-13.
        // Every Phase 3 service that depends on another sibling component is wired here;
        // bare class registrations in config() leave the public ?Foo $dep = null slots
        // null and produce silent NPEs at first call.

        // Field handler registry — register all PlainTextHandler modes and the 4 other
        // typed handlers. PlainTextHandler is parametric on its mode constructor arg
        // ('plain' / 'ckeditor' / 'link' / 'dropdown'); each mode registers under its
        // own id() so the registry can dispatch by handler-name from mapping.yaml.
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

        // FinalizeWalker drives CkeditorRewriterService over saved entries (FIN-01).
        $this->finalizeWalker->rewriter = $this->ckeditorRewriterService;

        // ExtractService deps. TopologicalOrderer is a plain class (no Component base)
        // — instantiate inline rather than promote it to a Yii component, mirroring
        // the AssetPathResolver pattern below.
        $this->extractService->legacyDb            = $this->legacyDbService;
        $this->extractService->detailTableResolver = $this->detailTableResolver;
        $this->extractService->topologicalOrderer  = new TopologicalOrderer();
        // Phase 8.5 / D-21 — Doctrine relation introspection for ManyToOne
        // FK joins under the `_rel:<prop>.<col>` namespace.
        $this->extractService->entityParser        = $this->doctrineEntityParser;
        $this->extractService->joinFkRelations     = (bool) $this->getSettings()->joinFkRelations;

        // TransformService deps (5 slots). migrationState is typed ?MigrationStateReader
        // — MigrationStateService implements that interface (verified). assetPathResolver
        // is a static-helper carrier; a fresh instance is sufficient.
        $this->transformService->handlerRegistry   = $this->fieldHandlerRegistry;
        $this->transformService->ckeditorRewriter  = $this->ckeditorRewriterService;
        $this->transformService->legacyDb          = $this->legacyDbService;
        $this->transformService->migrationState    = $this->migrationStateService;
        $this->transformService->assetPathResolver = new AssetPathResolver();
        // Phase 10: page-rooted taxonomy relations delegate lazy state misses
        // to TaxonomyMigrationService through ResolverContext. The handler
        // remains read/delegate-only and never owns taxonomy writes.
        $this->transformService->taxonomyResolver  = $this->taxonomyMigrationService;

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
        // composed from Phase 2's LocalePreflight + Settings::$localeMap (D-28 ladder).
        // Empty map is tolerated at init() — saveEntryForSites() throws on first access
        // if the operator hasn't configured locales yet, surfacing a clear error.
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
        // $filters wired via FilterFactory at command-invocation time, not init() (Phase 02.1 pattern).

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

        // Phase 8 / D-08 / TAX-08 — TaxonomyMigrationService sibling DI fanout.
        // Service public slots: legacyDb / migrationState / mappingFile (Plan 11).
        // No sites-map: TaxonomyMigrationService reads mapping.sites itself via
        // mappingFile->load() (D-09 fallback branch). NeverProductionTrait is NOT
        // applied at the service level — applied at the controller seam
        // (MigrateController::actionTaxonomies / actionIndex bolt-on, Plan 12).
        $this->taxonomyMigrationService->legacyDb       = $this->legacyDbService;
        $this->taxonomyMigrationService->migrationState = $this->migrationStateService;
        $this->taxonomyMigrationService->mappingFile    = $this->mappingFile;

        // CaptureBaselineHtmlService → SpotCheckUrlFetcher.
        $this->captureBaselineHtmlService->fetcher = $this->spotCheckUrlFetcher;

        // D-57: Settings table-name overrides wired here so adapter services pick them up.
        $settings = $this->getSettings();

        // Phase 12 CP console / queue workflow wiring.
        // MigrationGateService is consumed by future CP controllers and queue
        // dispatchers, so it must share the same run repository, mapping file,
        // settings model, and production safety helper as the rest of the
        // plugin graph instead of constructing detached fallback instances.
        $this->migrationGateService->migrationRunService = $this->migrationRunService;
        $this->migrationGateService->mappingFile = $this->mappingFile;
        $this->migrationGateService->settings = $settings;
        $this->migrationGateService->migrationSafety = $this->migrationSafety;

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

        // BaselineCounterService — pure-read; no sibling deps in v2 light shape (D-59).
        // CountGateService — pure-read; no sibling deps (expectedCounts arg passed at call time).
        // SnapshotDiffer — pure-function; zero deps. Ported but unused at v1.0 (deferred --deep flag).
        // SpotCheckUrlFetcher — uses Craft::createGuzzleClient; zero plugin-internal deps.
    }

    /**
     * Build the kuma_locale → Craft site handle map used by EntryMigrationService::$sites.
     *
     * Composes Phase 2's LocalePreflight matching ladder (D-28):
     *   1. Settings::$localeMap[$legacy] → explicit operator override (strongest)
     *   2. Exact match against Craft site handles + Settings::$defaultLocales
     *   3. Language-prefix loose match
     *
     * Detection requires a legacy DB connection (LocalePreflight::detect issues
     * `SELECT DISTINCT lang FROM kuma_node_translations`). We swallow connection
     * failures here and return an empty map — Plugin::init() must remain
     * crash-resistant on hosts where the legacy DB is unreachable at boot
     * (operator runs `doctor` first to surface the issue). EntryMigrationService
     * throws a clear error on first access if the map is empty.
     *
     * @return array<string, string> kuma_locale code → Craft site handle
     */
    private function resolveSitesMap(): array
    {
        // Phase 6 precedence change: prefer the operator-curated mapping.yaml
        // `sites:` block (when present) over LocalePreflight's language-prefix
        // guesswork. LocalePreflight::resolve() will happily return language
        // codes (e.g. nl → 'nl-NL') because it dual-indexes site handles +
        // languages — but downstream EntryMigrationService::saveEntryForSites
        // calls `getSiteByHandle()` which can't find a Site whose handle is
        // the language code (the NL site's handle is 'default', language is
        // 'nl-NL'). The mapping.yaml sites: block lets the operator (or
        // compile's auto-derive) lock in the correct handle explicitly.
        try {
            $mapping = $this->mappingFile->load();
            $mappingSites = (array) ($mapping['sites'] ?? []);
            if ($mappingSites !== []) {
                $out = [];
                foreach ($mappingSites as $legacy => $handle) {
                    if (is_string($legacy) && is_string($handle) && $legacy !== '' && $handle !== '') {
                        $out[$legacy] = $handle;
                    }
                }
                if ($out !== []) {
                    return $out;
                }
            }
        } catch (\Throwable) {
            // Mapping unreadable — fall through to localePreflight detection.
        }

        try {
            $detected = $this->localePreflight->detect();
        } catch (\Throwable) {
            return [];
        }

        if ($detected === []) {
            return [];
        }

        $resolved = $this->localePreflight->resolve($detected);
        $out = [];
        foreach ($resolved as $legacy => $detail) {
            if (($detail['matched'] ?? false) === true) {
                $target = (string) ($detail['target'] ?? '');
                if ($target !== '') {
                    $out[(string) $legacy] = $target;
                }
            }
        }
        return $out;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->view->renderTemplate(
            'kunstmaan-migrator/_settings.twig',
            [
                'plugin' => $this,
                'settings' => $this->getSettings(),
                'localeOptions' => $this->resolveLocaleOptions(), // [] on DB failure (D-21)
                'siteHandleOptions' => $this->resolveSiteHandleOptions(), // always available (D-22)
                // Phase 6 — fallback dropdown options. Three-state: a top "(no
                // fallback — skip unmapped)" sentinel mapping to '', followed by
                // every real Craft handle. Operator picks one or leaves empty
                // for the explicit-skip behavior.
                'entryTypeFallbackOptions' => $this->resolveEntryTypeFallbackOptions(),
                'blockTypeFallbackOptions' => $this->resolveBlockTypeFallbackOptions(),
            ],
        );
    }

    /**
     * Phase 6 — dropdown options for Settings::defaultEntryType. Top option is
     * the "no fallback" sentinel (empty value). Following options are every
     * Craft entry-type handle, sorted alphabetically.
     *
     * @return array<int,array{label:string,value:string}>
     */
    private function resolveEntryTypeFallbackOptions(): array
    {
        $opts = [
            ['label' => '(no fallback — skip unmapped)', 'value' => ''],
        ];
        try {
            foreach ($this->craftKnowledgeBase->entryTypeHandles() as $h) {
                $opts[] = ['label' => $h, 'value' => $h];
            }
        } catch (\Throwable $e) {
            Craft::warning('entry-type fallback options unavailable: ' . $e->getMessage(), __METHOD__);
        }
        return $opts;
    }

    /**
     * Phase 6 — dropdown options for Settings::defaultBlockType. Same shape as
     * resolveEntryTypeFallbackOptions; values are the union of block-type
     * handles across every Matrix field.
     *
     * @return array<int,array{label:string,value:string}>
     */
    private function resolveBlockTypeFallbackOptions(): array
    {
        $opts = [
            ['label' => '(no fallback — skip unmapped)', 'value' => ''],
        ];
        try {
            foreach ($this->craftKnowledgeBase->allBlockTypeHandles() as $h) {
                $opts[] = ['label' => $h, 'value' => $h];
            }
        } catch (\Throwable $e) {
            Craft::warning('block-type fallback options unavailable: ' . $e->getMessage(), __METHOD__);
        }
        return $opts;
    }

    /**
     * Phase 4.1 / D-21 — pre-compute legacy locale dropdown options.
     * Returns [] on DB failure so the template falls back to free-text
     * (`type: localeOptions ? 'select' : 'singleline'`).
     *
     * @return array<int,array{label:string,value:string}>
     */
    private function resolveLocaleOptions(): array
    {
        try {
            $detected = $this->localePreflight->detect();
        } catch (\Throwable $e) {
            Craft::warning(
                'locale dropdown options unavailable: ' . $e->getMessage(),
                __METHOD__,
            );
            return [];
        }
        return array_map(
            static fn(string $code): array => ['label' => $code, 'value' => $code],
            $detected,
        );
    }

    /**
     * Phase 4.1 / D-22 — Craft site handle dropdown options. No DB dependency.
     *
     * @return array<int,array{label:string,value:string}>
     */
    private function resolveSiteHandleOptions(): array
    {
        return array_map(
            static fn($s): array => [
                'label' => (string) $s->handle,
                'value' => (string) $s->handle,
            ],
            Craft::$app->getSites()->getAllSites(),
        );
    }
}
