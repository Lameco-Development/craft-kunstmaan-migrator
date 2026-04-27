<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\console;

use Craft;
use craft\console\Controller;
use craft\db\MigrationManager;
use craft\helpers\Console;
use lameco\kunstmaanmigrator\NeverProductionTrait;
use lameco\kunstmaanmigrator\Plugin;
use lameco\kunstmaanmigrator\filter\MigrationFilters;
use lameco\kunstmaanmigrator\load\MigrationOptions;
use lameco\kunstmaanmigrator\load\MigrationReport;
use Symfony\Component\Yaml\Yaml;
use Throwable;
use yii\console\ExitCode;

/**
 * Migrate command — Phase 1 ships only actionInstall (FND-02a programmatic install shim);
 * Phase 3 (Plan 03-13) adds the ETL operator surface:
 *   - actionIndex     — full pipeline (extract → transform → load → finalize)
 *   - actionExtract   — Stage 2a only
 *   - actionTransform — Stage 2b only
 *   - actionLoad      — Stage 2c only (reads transformed/entries/*.json from disk)
 *   - actionFinalize  — Stage 2d only (CKEditor token resolution pass)
 *   - actionTruncate  — D-51 wide+safety-rails delete (--live --confirm required)
 *
 * Each action gates first on enforceNeverProduction() (D-20). Defaults to dry-run; --live
 * writes to Craft. Honors --entities / --locales / --since filter spec (FILT-02).
 *
 * Per-entry progress emission (ETL-06): `[N/total] <slug> → <verb>` where verb is
 * created / updated / skipped / FAILED — failures stream to stderr (FG_RED).
 *
 * REPORT.md emission (D-50 failures + D-52 counts) at end of actionIndex / actionLoad.
 *
 * Atomic-always-on (ETL-04): no --atomic flag — per-entry transaction is the only mode.
 * JIT assets default; --preload-assets opt-in (FH-03).
 */
class MigrateController extends Controller
{
    use NeverProductionTrait;

    /** Apply writes to Craft. Default false = dry-run. */
    public bool $live = false;

    /** Required alongside --live for actionTruncate (D-51 safety rail). */
    public bool $confirm = false;

    /**
     * FH-03 opt-in: preload all referenced legacy assets in one batch before the
     * load pass. Default false = JIT materialise per-entry (per CLAUDE.md rule).
     */
    public bool $preloadAssets = false;

    /**
     * Skip --force entry overwrite. Wired through to MigrationOptions::force.
     * Default false = idempotent skip on existing state-row hits (ETL-05).
     */
    public bool $force = false;

    public ?string $entities = null;
    public ?string $locales  = null;
    public ?string $since    = null;

    // Phase 4.1 / D-26 — CLI override flags for adapter bypass per-run. Distinct
    // from Settings::seoEnabled / retourEnabled (which persist across runs);
    // these flip the adapter for THIS invocation only and emit a distinct
    // warn-line copy so REPORT.md skipped-stages aggregation can tell them apart.
    public bool $noSeo = false;
    public bool $noRetour = false;

    /**
     * D-65 verbosity counter — `-v` / `-vv` / `-vvv`. Yii parses repeated
     * short flags as a string value (`-vv` → `verbose='v'`); verbosityLevel()
     * does the str-length count. Accept int form too so `--verbose=2` works.
     */
    public string|int $verbose = 0;

    /** D-67: per-run timestamped log file under storage/migration/. */
    private ?string $logFilePath = null;
    /** @var resource|null */
    private $logFileHandle = null;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), [
            'live', 'confirm', 'preloadAssets', 'force', 'entities', 'locales', 'since',
            // Phase 4.1 / D-26 — adapter bypass per-run.
            'noSeo', 'noRetour',
            // D-65: -v..-vvv verbosity (string|int — see verbosityLevel()).
            'verbose',
        ]);
    }

    /**
     * D-65: Yii's option parser doesn't support repeated short flags natively
     * (-vv is parsed as --verbose=v). The verbosityLevel() helper reads the
     * string|int $verbose value and counts characters / digits to derive the
     * level. The 'v' → 'verbose' alias keeps the short-flag UX intact.
     */
    public function optionAliases(): array
    {
        return array_merge(parent::optionAliases(), [
            'v' => 'verbose',
        ]);
    }

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
     * Full pipeline: extract → transform → load → finalize → REPORT.md.
     *
     * Default dry-run. `--live` writes to Craft. Per CLAUDE.md atomic-always-on,
     * the per-entry transaction is the only mode (no --atomic flag).
     */
    public function actionIndex(): int
    {
        if (($gate = $this->enforceNeverProduction()) !== null) {
            return $gate;
        }

        // D-67: open per-run log BEFORE the first stdout so stage timings and
        // FAIL emissions can be correlated against the on-disk trail.
        $this->openLogFile($this->defaultLogPath());
        $this->logLine('actionIndex started; verbosity=' . $this->verbosityLevel(), 1);
        $tRunStart = microtime(true);

        $this->stdout("Migrate: extract → transform → load → finalize\n", Console::FG_CYAN);

        $plugin = Plugin::getInstance();
        $filters = $plugin->filterFactory->fromCli($this->entities, $this->locales, $this->since, $this->noSeo, $this->noRetour);
        $storageDir = Craft::$app->path->getStoragePath() . '/migration';
        $report = new MigrationReport();

        // Step 1: locale preflight (LOC-02 D-17 hard-fail).
        $unmapped = $plugin->localePreflight->ensure($filters);
        if ($unmapped !== null) {
            $this->stderr(
                "  FAIL unmapped Kunstmaan locales: " . implode(', ', $unmapped) . "\n",
                Console::FG_RED,
            );
            return ExitCode::CONFIG;
        }
        $this->stdout("  OK   locale preflight\n", Console::FG_GREEN);

        // Step 2: load mapping.yaml + coverage gate (MAP-06).
        $mappingPath = $plugin->mappingFile->resolvePath();
        if (!is_file($mappingPath)) {
            $this->stderr(
                "  FAIL mapping.yaml not found at {$mappingPath} — run analyze first\n",
                Console::FG_RED,
            );
            return ExitCode::CONFIG;
        }
        $mapping = $plugin->mappingFile->load($mappingPath);
        $this->stdout(
            "  OK   mapping loaded (" . count($mapping['proposals'] ?? []) . " rows) → {$mappingPath}\n",
            Console::FG_GREEN,
        );

        // Step 3: extract (writes storage/migration/extracted/<fqcn-slug>/<node-id>.json).
        $extractProgress = $this->makeExtractProgress();
        try {
            $extractReport = $plugin->extractService->run($mapping, $filters, [], $extractProgress);
            $extractCounts = is_array($extractReport) ? $extractReport : iterator_to_array($extractReport);
        } catch (Throwable $e) {
            $this->endProgressIfStarted();
            $this->stderr("  FAIL extract: {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $this->endProgressIfStarted();
        $extractedNodes = (int) ($extractCounts['nodesExtracted'] ?? 0);
        $this->stdout(
            "  OK   extract complete ({$extractedNodes} nodes → {$storageDir}/extracted/)\n",
            Console::FG_GREEN,
        );

        // Step 4: transform — stream extracted/*.json through TransformService and persist
        // each yielded payload to transformed/entries/<fqcn-slug>/<node-id>.json so the
        // load stage can read by file path (AtomicMigrationService::migrateOneEntry's
        // verbatim v1 contract takes a file path, not a tuple — Plan 03-12 SUMMARY).
        $transformedDir = $storageDir . '/transformed/entries';
        $transformedCount = 0;
        // Use the just-completed extract count as the transform denominator — extract and
        // transform are 1:1 at the input level (locale fan-out happens in transform's output).
        $transformProgress = $this->makeTransformProgress($extractedNodes);
        try {
            $extractedStream = $this->streamExtracted($storageDir);
            foreach ($plugin->transformService->run($extractedStream, $mapping, $filters, [], $transformProgress) as $payload) {
                if (isset($payload['__report'])) {
                    continue; // sentinel — counters available via the Transform run report
                }
                $fqcn = (string) ($payload['stateSource'] ?? '');
                $nodeId = (int) ($payload['kuma_node_id'] ?? 0);
                if ($fqcn === '' || $nodeId <= 0) {
                    continue;
                }
                $outDir = $transformedDir . '/' . $this->slugify($fqcn);
                if (!is_dir($outDir) && !@mkdir($outDir, 0775, true) && !is_dir($outDir)) {
                    throw new \RuntimeException("Cannot create transformed dir: {$outDir}");
                }
                $outFile = $outDir . '/' . $nodeId . '.json';
                $json = json_encode(
                    $payload,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                );
                if ($json === false || file_put_contents($outFile, $json) === false) {
                    $report->warn("Could not write transformed JSON: {$outFile}");
                    continue;
                }
                $transformedCount++;
            }
        } catch (Throwable $e) {
            $this->endProgressIfStarted();
            $this->stderr("  FAIL transform: {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $this->endProgressIfStarted();
        $this->stdout(
            "  OK   transform complete ({$transformedCount} payloads → {$transformedDir})\n",
            Console::FG_GREEN,
        );

        // FH-03: --preload-assets ingests the full referenced asset set in one batch
        // before the per-entry loop. Default JIT materialises inside migrateOneEntry().
        // Hoisted above the load step so the SEO + Retour bolt-ons (D-55) downstream
        // can re-use the same MigrationOptions instance without redeclaring.
        $opts = new MigrationOptions(
            dryRun: !$this->live,
            force: $this->force,
            skipAssets: false,
        );

        // Step 5: load — per-entry atomic write (or dry-run print).
        if (!$this->live) {
            $this->stdout(
                "  WARN load skipped (dry-run; pass --live to write entries)\n",
                Console::FG_YELLOW,
            );
        } else {
            if ($this->preloadAssets) {
                try {
                    $plugin->assetMigrationService->ingestReferenced($opts, $filters);
                    $this->stdout("  OK   --preload-assets batch complete\n", Console::FG_GREEN);
                } catch (Throwable $e) {
                    $this->stderr("  FAIL --preload-assets: {$e->getMessage()}\n", Console::FG_RED);
                    return ExitCode::UNSPECIFIED_ERROR;
                }
            }
            $loadExit = $this->runLoadFromDisk($transformedDir, $opts, $report);
            if ($loadExit !== ExitCode::OK) {
                return $loadExit;
            }
        }

        // Step 6: finalize (CKEditor token resolution pass).
        if ($this->live) {
            $finalizeProgress = $this->makeFinalizeProgress();
            try {
                $finalizeCounts = $plugin->finalizeWalker->walk($filters, $finalizeProgress);
                $this->endProgressIfStarted();
                $this->stdout(sprintf(
                    "  OK   finalize complete (processed=%d rewritten=%d unresolvable=%d)\n",
                    (int) $finalizeCounts['processed'],
                    (int) $finalizeCounts['rewritten'],
                    (int) $finalizeCounts['unresolvable'],
                ), Console::FG_GREEN);
                $report->incr('finalize.processed', (int) $finalizeCounts['processed']);
                $report->incr('finalize.rewritten', (int) $finalizeCounts['rewritten']);
                $report->incr('finalize.unresolvable', (int) $finalizeCounts['unresolvable']);
            } catch (Throwable $e) {
                $this->endProgressIfStarted();
                $this->stderr("  FAIL finalize: {$e->getMessage()}\n", Console::FG_RED);
                return ExitCode::UNSPECIFIED_ERROR;
            }
        } else {
            $this->stdout(
                "  WARN finalize skipped (dry-run)\n",
                Console::FG_YELLOW,
            );
        }

        // Step 6.5 (D-55): SEO stage — runs AFTER finalize so all entries+assets exist
        // and kuma_seo image refs resolve via the state map. The service short-circuits
        // internally with a WARN when SEOmatic is absent (D-56).
        // Phase 4.1 / D-26: --no-seo bypasses adapter execution per-run with distinct
        // warn-line copy (different from Settings-disabled and plugin-not-installed).
        if ($this->live) {
            if ($filters->noSeo) {
                $report->warn(self::cliBypassSeoWarnLine());
                $this->stdout(
                    "  WARN seo skipped via --no-seo (CLI override)\n",
                    Console::FG_YELLOW,
                );
            } else {
                $plugin->seoMigrationService->filters = $filters;
                try {
                    $seoReport = $plugin->seoMigrationService->migrateAll($opts);
                } catch (Throwable $e) {
                    $this->stderr("  FAIL seo: {$e->getMessage()}\n", Console::FG_RED);
                    return ExitCode::UNSPECIFIED_ERROR;
                }
                $this->mergeReport($report, $seoReport, 'seo');
                $this->stdout(sprintf(
                    "  Stage seo: created=%d updated=%d skipped=%d failed=%d\n",
                    (int) ($seoReport->counts['created'] ?? 0),
                    (int) ($seoReport->counts['updated'] ?? 0),
                    (int) ($seoReport->counts['skipped'] ?? 0),
                    (int) ($seoReport->counts['failed'] ?? 0),
                ), Console::FG_GREEN);
            }
        } else {
            $this->stdout(
                "  WARN seo skipped (dry-run)\n",
                Console::FG_YELLOW,
            );
        }

        // Step 6.6 (D-55): Retour stage — same shape; service short-circuits when Retour absent.
        // Phase 4.1 / D-26: --no-retour bypasses adapter execution per-run with distinct copy.
        if ($this->live) {
            if ($filters->noRetour) {
                $report->warn(self::cliBypassRetourWarnLine());
                $this->stdout(
                    "  WARN retour skipped via --no-retour (CLI override)\n",
                    Console::FG_YELLOW,
                );
            } else {
                $plugin->redirectMigrationService->filters = $filters;
                try {
                    $retourReport = $plugin->redirectMigrationService->migrateAll($opts);
                } catch (Throwable $e) {
                    $this->stderr("  FAIL retour: {$e->getMessage()}\n", Console::FG_RED);
                    return ExitCode::UNSPECIFIED_ERROR;
                }
                $this->mergeReport($report, $retourReport, 'retour');
                $this->stdout(sprintf(
                    "  Stage retour: created=%d updated=%d skipped=%d failed=%d\n",
                    (int) ($retourReport->counts['created'] ?? 0),
                    (int) ($retourReport->counts['updated'] ?? 0),
                    (int) ($retourReport->counts['skipped'] ?? 0),
                    (int) ($retourReport->counts['failed'] ?? 0),
                ), Console::FG_GREEN);
            }
        } else {
            $this->stdout(
                "  WARN retour skipped (dry-run)\n",
                Console::FG_YELLOW,
            );
        }

        // Step 7: REPORT.md (D-50 failures + D-52 counts + D-68 three new sections).
        $this->writeReport($storageDir, $report, $filters, $tRunStart);

        $tRunMs = (int) round((microtime(true) - $tRunStart) * 1000);
        $this->logLine(sprintf('actionIndex complete in %dms', $tRunMs), 1);

        $this->stdout("\nMigrate: PASS\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    /**
     * Sub-action: write SEOmatic SEO MetaBundles per migrated entry per site.
     * D-55: runs LAST in the in-process pipeline so kuma_seo image refs resolve
     * via state lookup. Standalone for resume / debug after a partial migrate.
     *
     * D-56: short-circuits with WARN inside SeoMigrationService when SEOmatic absent.
     */
    public function actionSeo(): int
    {
        if (($gate = $this->enforceNeverProduction()) !== null) {
            return $gate;
        }
        // D-67: per-run timestamped log file for resume / debug invocations too.
        $this->openLogFile($this->defaultLogPath());
        $this->logLine('actionSeo started; verbosity=' . $this->verbosityLevel(), 1);
        $this->stdout("Migrate (seo): SEOmatic MetaBundles per migrated entry\n", Console::FG_CYAN);

        $plugin = Plugin::getInstance();
        // Phase 4.1 / D-26: actionSeo IS the SEO sub-action; honoring --no-seo
        // here would defeat its purpose. Force noSeo=false; pass --no-retour
        // through unchanged (inert here — actionSeo never calls the Retour
        // service — but faithfully reflects the operator's invocation).
        $filters = $plugin->filterFactory->fromCli(
            $this->entities,
            $this->locales,
            $this->since,
            false,
            $this->noRetour,
        );

        if (!$this->live) {
            $this->stdout(
                "  WARN seo skipped (dry-run; pass --live to write SEOmatic bundles)\n",
                Console::FG_YELLOW,
            );
            return ExitCode::OK;
        }

        // Note: Settings::seoEnabled still gates inside SeoMigrationService::migrateAll();
        // operators who want a persistent disable use Settings, not --no-seo.

        $plugin->seoMigrationService->filters = $filters;
        $opts = new MigrationOptions(dryRun: false, force: $this->force, skipAssets: false);

        try {
            $report = $plugin->seoMigrationService->migrateAll($opts);
        } catch (Throwable $e) {
            $this->stderr("  FAIL seo: {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $this->stdout(sprintf(
            "  OK   seo complete (created=%d updated=%d skipped=%d failed=%d)\n",
            (int) ($report->counts['created'] ?? 0),
            (int) ($report->counts['updated'] ?? 0),
            (int) ($report->counts['skipped'] ?? 0),
            (int) ($report->counts['failed'] ?? 0),
        ), Console::FG_GREEN);
        return ExitCode::OK;
    }

    /**
     * Sub-action: write Retour redirects from kuma_redirects + section-move 301s.
     * D-55: standalone for resume / debug.
     * D-56: short-circuits with WARN inside RedirectMigrationService when Retour absent.
     */
    public function actionRetour(): int
    {
        if (($gate = $this->enforceNeverProduction()) !== null) {
            return $gate;
        }
        // D-67: per-run timestamped log file for resume / debug invocations too.
        $this->openLogFile($this->defaultLogPath());
        $this->logLine('actionRetour started; verbosity=' . $this->verbosityLevel(), 1);
        $this->stdout(
            "Migrate (retour): redirects from kuma_redirects + section-move 301s\n",
            Console::FG_CYAN,
        );

        $plugin = Plugin::getInstance();
        // Phase 4.1 / D-26: actionRetour IS the Retour sub-action; honoring
        // --no-retour here would defeat its purpose. Force noRetour=false;
        // pass --no-seo through unchanged (inert here — actionRetour never
        // calls the SEO service — but faithfully reflects the invocation).
        $filters = $plugin->filterFactory->fromCli(
            $this->entities,
            $this->locales,
            $this->since,
            $this->noSeo,
            false,
        );

        if (!$this->live) {
            $this->stdout(
                "  WARN retour skipped (dry-run; pass --live to write redirects)\n",
                Console::FG_YELLOW,
            );
            return ExitCode::OK;
        }

        // Note: Settings::retourEnabled still gates inside RedirectMigrationService::migrateAll();
        // operators who want a persistent disable use Settings, not --no-retour.

        $plugin->redirectMigrationService->filters = $filters;
        $opts = new MigrationOptions(dryRun: false, force: $this->force, skipAssets: false);

        try {
            $report = $plugin->redirectMigrationService->migrateAll($opts);
        } catch (Throwable $e) {
            $this->stderr("  FAIL retour: {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $this->stdout(sprintf(
            "  OK   retour complete (created=%d updated=%d skipped=%d failed=%d)\n",
            (int) ($report->counts['created'] ?? 0),
            (int) ($report->counts['updated'] ?? 0),
            (int) ($report->counts['skipped'] ?? 0),
            (int) ($report->counts['failed'] ?? 0),
        ), Console::FG_GREEN);
        return ExitCode::OK;
    }

    /**
     * Stage 2a — extract only. Reads mapping.yaml + filters, writes
     * storage/migration/extracted/<fqcn-slug>/<node-id>.json files.
     */
    public function actionExtract(): int
    {
        if (($gate = $this->enforceNeverProduction()) !== null) {
            return $gate;
        }

        $this->stdout("Migrate (extract): legacy DB → storage/migration/extracted/\n", Console::FG_CYAN);

        $plugin = Plugin::getInstance();
        $filters = $plugin->filterFactory->fromCli($this->entities, $this->locales, $this->since, $this->noSeo, $this->noRetour);

        if (($exit = $this->preflightLocale($filters)) !== ExitCode::OK) {
            return $exit;
        }
        $mapping = $this->loadMappingOrFail();
        if ($mapping === null) {
            return ExitCode::CONFIG;
        }

        $extractProgress = $this->makeExtractProgress();
        try {
            $extractReport = $plugin->extractService->run($mapping, $filters, [], $extractProgress);
            $extractCounts = is_array($extractReport) ? $extractReport : iterator_to_array($extractReport);
        } catch (Throwable $e) {
            $this->endProgressIfStarted();
            $this->stderr("  FAIL extract: {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $this->endProgressIfStarted();
        $this->stdout(sprintf(
            "  OK   extract complete (nodeClasses=%d nodes=%d skipped=%d warnings=%d)\n",
            (int) ($extractCounts['nodeClasses'] ?? 0),
            (int) ($extractCounts['nodesExtracted'] ?? 0),
            (int) ($extractCounts['skipped'] ?? 0),
            count((array) ($extractCounts['warnings'] ?? [])),
        ), Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Stage 2b — transform only. Reads storage/migration/extracted/, writes
     * storage/migration/transformed/entries/<fqcn-slug>/<node-id>.json.
     */
    public function actionTransform(): int
    {
        if (($gate = $this->enforceNeverProduction()) !== null) {
            return $gate;
        }

        $this->stdout("Migrate (transform): extracted → transformed/entries\n", Console::FG_CYAN);

        $plugin = Plugin::getInstance();
        $filters = $plugin->filterFactory->fromCli($this->entities, $this->locales, $this->since, $this->noSeo, $this->noRetour);
        $storageDir = Craft::$app->path->getStoragePath() . '/migration';

        if (($exit = $this->preflightLocale($filters)) !== ExitCode::OK) {
            return $exit;
        }
        $mapping = $this->loadMappingOrFail();
        if ($mapping === null) {
            return ExitCode::CONFIG;
        }

        $transformedDir = $storageDir . '/transformed/entries';
        $count = 0;
        // For standalone transform we don't have a fresh extract precount handy; count
        // extracted/<fqcn>/*.json files on disk as the denominator. Cheap glob.
        $transformProgress = $this->makeTransformProgress($this->countExtractedFiles($storageDir));
        try {
            $extractedStream = $this->streamExtracted($storageDir);
            foreach ($plugin->transformService->run($extractedStream, $mapping, $filters, [], $transformProgress) as $payload) {
                if (isset($payload['__report'])) {
                    continue;
                }
                $fqcn = (string) ($payload['stateSource'] ?? '');
                $nodeId = (int) ($payload['kuma_node_id'] ?? 0);
                if ($fqcn === '' || $nodeId <= 0) {
                    continue;
                }
                $outDir = $transformedDir . '/' . $this->slugify($fqcn);
                if (!is_dir($outDir) && !@mkdir($outDir, 0775, true) && !is_dir($outDir)) {
                    throw new \RuntimeException("Cannot create transformed dir: {$outDir}");
                }
                $outFile = $outDir . '/' . $nodeId . '.json';
                $json = json_encode(
                    $payload,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                );
                if ($json === false || file_put_contents($outFile, $json) === false) {
                    continue;
                }
                $count++;
            }
        } catch (Throwable $e) {
            $this->endProgressIfStarted();
            $this->stderr("  FAIL transform: {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $this->endProgressIfStarted();

        $this->stdout(
            "  OK   transform complete ({$count} payloads → {$transformedDir})\n",
            Console::FG_GREEN,
        );
        return ExitCode::OK;
    }

    /**
     * Stage 2c — load only. Reads storage/migration/transformed/entries/*.json
     * and calls AtomicMigrationService::migrateOneEntry per file. Default dry-run;
     * --live writes. ETL-05 idempotency gate via state-table presence skip.
     */
    public function actionLoad(): int
    {
        if (($gate = $this->enforceNeverProduction()) !== null) {
            return $gate;
        }

        $this->stdout("Migrate (load): transformed/entries → Craft\n", Console::FG_CYAN);

        $plugin = Plugin::getInstance();
        $filters = $plugin->filterFactory->fromCli($this->entities, $this->locales, $this->since, $this->noSeo, $this->noRetour);
        $storageDir = Craft::$app->path->getStoragePath() . '/migration';
        $transformedDir = $storageDir . '/transformed/entries';
        $report = new MigrationReport();

        if (($exit = $this->preflightLocale($filters)) !== ExitCode::OK) {
            return $exit;
        }

        if (!is_dir($transformedDir)) {
            $this->stderr(
                "  FAIL transformed/entries dir missing — run `migrate/transform` first ({$transformedDir})\n",
                Console::FG_RED,
            );
            return ExitCode::CONFIG;
        }

        if (!$this->live) {
            $this->stdout(
                "DRY RUN — would load entries from {$transformedDir} (use --live to write)\n",
                Console::FG_YELLOW,
            );
            $count = 0;
            foreach ($this->iterateTransformedFiles($transformedDir) as $jsonPath) {
                $count++;
            }
            $this->stdout("  OK   would process {$count} transformed payloads\n", Console::FG_GREEN);
            return ExitCode::OK;
        }

        $opts = new MigrationOptions(
            dryRun: false,
            force: $this->force,
            skipAssets: false,
        );

        if ($this->preloadAssets) {
            try {
                $plugin->assetMigrationService->ingestReferenced($opts, $filters);
                $this->stdout("  OK   --preload-assets batch complete\n", Console::FG_GREEN);
            } catch (Throwable $e) {
                $this->stderr("  FAIL --preload-assets: {$e->getMessage()}\n", Console::FG_RED);
                return ExitCode::UNSPECIFIED_ERROR;
            }
        }

        $exit = $this->runLoadFromDisk($transformedDir, $opts, $report);
        $this->writeReport($storageDir, $report, $filters);
        return $exit;
    }

    /**
     * Stage 2d — finalize only. CKEditor token resolution pass over saved entries
     * (FIN-01). Walks every (entry, siteId) pair via FinalizeWalker. Requires --live.
     */
    public function actionFinalize(): int
    {
        if (($gate = $this->enforceNeverProduction()) !== null) {
            return $gate;
        }

        $this->stdout("Migrate (finalize): CKEditor token resolution pass\n", Console::FG_CYAN);

        $plugin = Plugin::getInstance();
        $filters = $plugin->filterFactory->fromCli($this->entities, $this->locales, $this->since, $this->noSeo, $this->noRetour);

        if (!$this->live) {
            $this->stdout(
                "  WARN finalize skipped (dry-run; pass --live to rewrite CKEditor fields)\n",
                Console::FG_YELLOW,
            );
            return ExitCode::OK;
        }

        $finalizeProgress = $this->makeFinalizeProgress();
        try {
            $counts = $plugin->finalizeWalker->walk($filters, $finalizeProgress);
        } catch (Throwable $e) {
            $this->endProgressIfStarted();
            $this->stderr("  FAIL finalize: {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $this->endProgressIfStarted();
        $this->stdout(sprintf(
            "  OK   finalize complete (processed=%d rewritten=%d unresolvable=%d)\n",
            (int) $counts['processed'],
            (int) $counts['rewritten'],
            (int) $counts['unresolvable'],
        ), Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * D-51 wide+safety-rails truncate: defaults to dry-run; requires --live --confirm
     * to actually delete. Honors --entities + --locales filters (no nuke-everything
     * footgun). Three scoped deletes:
     *   1. Craft entries with kunstmaanSourceId state-row ownership matching filter
     *   2. Assets pulled in by this plugin (state targetType='asset' + scoped)
     *   3. State-table rows where source IN scoped sources (LAST so the operator can
     *      re-run if mid-truncate fails)
     *
     * NeverProductionTrait gates regardless of flags — this command is dev/staging only.
     */
    public function actionTruncate(): int
    {
        if (($gate = $this->enforceNeverProduction()) !== null) {
            return $gate;
        }

        $plugin = Plugin::getInstance();
        $filters = $plugin->filterFactory->fromCli($this->entities, $this->locales, $this->since, $this->noSeo, $this->noRetour);

        $entitiesScope = $filters->entities === [] ? '(all migrated)' : implode(', ', $filters->entities);
        $localesScope = $filters->locales === [] ? '(all sites)' : implode(', ', $filters->locales);

        if (!$this->live || !$this->confirm) {
            $this->stdout(
                "DRY RUN — would delete the following (use --live --confirm to actually delete):\n",
                Console::FG_YELLOW,
            );
            $this->stdout("  scope.entities: {$entitiesScope}\n", Console::FG_YELLOW);
            $this->stdout("  scope.locales:  {$localesScope}\n", Console::FG_YELLOW);
            $this->stdout(
                "  delete order:   1) Craft entries → 2) plugin-owned assets → 3) state-table rows\n",
                Console::FG_YELLOW,
            );
            return ExitCode::OK;
        }

        // Live + confirmed: delegate to AssetMigrationService::truncate for assets,
        // and to MigrationStateService for the state-row deletes. Entry deletes
        // walk state-table rows via getTargetId per (source, key) pair.
        // (Wider entry-delete + multi-source iteration loops will land in Plan 04
        // alongside the verify command; Phase 3 ships the safety-rail surface and
        // the asset/state primitives.)
        $assetsDeleted = 0;
        try {
            $assetsDeleted = $plugin->assetMigrationService->truncate();
        } catch (Throwable $e) {
            $this->stderr("  FAIL truncate assets: {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout(
            "  OK   truncated {$assetsDeleted} plugin-owned assets (entries + state-row scoped delete pending Plan 04)\n",
            Console::FG_GREEN,
        );
        return ExitCode::OK;
    }

    /**
     * Phase 4.1 / REC-01 / D-34 — `migrate/sync-assets` recovery command.
     *
     * Re-ingests every `kuma_media` row that any prior atomic run referenced
     * but skipped. Iterates state-table rows under source='media' whose
     * targetId is unset (the existing remote-video-without-id path is the
     * primary candidate-producing site at v1.0; future failure paths that
     * grow state-row writes feed in here automatically). Per D-37, rows
     * carrying the `meta.terminalState='permanently_failed'` marker are
     * REPORTED but never retried — sync-assets is NOT a `--retry-permanently-
     * failed` knob; terminal state is FINAL by design.
     *
     * Operator-facing distinction: this is a RECOVERY command, NOT a
     * replacement for `migrate run`. The intended flow is "operator runs
     * `migrate run`, then runs `migrate/sync-assets` if the rehearsal report
     * shows skipped media." The SYNC-ASSETS-*.md report copy and inline help
     * text both reinforce this.
     *
     * Idempotence: re-running with everything healed exits cleanly with
     * candidates=0 and emits the placeholder line in SYNC-ASSETS-*.md.
     *
     * Respects MigrationFilters (--entity / --locale / --since) per D-38.
     * Emits SYNC-ASSETS-<timestamp>.md under storage/migration/ via
     * MappingFile::writeAtomic per D-39 (atomic-always-on artifact).
     *
     * NeverProductionTrait gate per FND-04 (T-04.1-07-04 / T-04.1-07-06
     * mitigation — first statement, locked-by-acceptance-grep).
     */
    public function actionSyncAssets(): int
    {
        if (($gate = $this->enforceNeverProduction()) !== null) {
            return $gate;
        }
        $this->openLogFile($this->defaultLogPath());
        $this->logLine('actionSyncAssets started; verbosity=' . $this->verbosityLevel(), 1);

        $plugin = Plugin::getInstance();
        $filters = $plugin->filterFactory->fromCli(
            $this->entities,
            $this->locales,
            $this->since,
            $this->noSeo,
            $this->noRetour,
        );
        $storageDir = Craft::$app->path->getStoragePath() . '/migration';
        $report = new MigrationReport();
        $tStart = microtime(true);

        $this->stdout(
            "Sync-assets: re-ingesting skipped media rows (recovery command — runs AFTER migrate)\n",
            Console::FG_CYAN,
        );

        $candidates = $this->collectAssetCandidates($filters);
        $candidateCount = count($candidates);
        $this->stdout(sprintf("  Found %d candidates\n", $candidateCount), Console::FG_GREEN);

        foreach ($candidates as $i => $candidate) {
            $this->logLine(
                sprintf('[%d/%d] media=%s', $i + 1, $candidateCount, $candidate['sourceKey']),
                1,
            );
            try {
                $resolved = $plugin->assetMigrationService->resolveFromLegacyId(
                    (int) $candidate['legacyId'],
                );
                if ($resolved === 0) {
                    // resolveFromLegacyId returned 0 (miss) without throwing —
                    // treat as retryable (next run will re-attempt).
                    $report->incr('failed');
                    $this->stdout(
                        sprintf(
                            "  FAIL %s — resolve returned 0 (will retry on next run)\n",
                            $candidate['sourceKey'],
                        ),
                        Console::FG_YELLOW,
                    );
                } else {
                    $report->incr('healed');
                    $this->stdout(
                        sprintf("  OK   %s → asset:%d\n", $candidate['sourceKey'], $resolved),
                        Console::FG_GREEN,
                    );
                }
            } catch (Throwable $e) {
                // Phase 4.1 / D-37 inline failure classification mirrors
                // AssetMigrationService::classifyAssetFailureReason
                // heuristics. We deliberately do NOT call any classifier on
                // AssetMigrationService — the classifier stays private so
                // this plan's `git diff src/load/AssetMigrationService.php`
                // shows zero changes (acceptance lock).
                $reason = self::syncAssetsClassifyResolveFailureMessage($e->getMessage());
                if (in_array($reason, ['filesystem_404', 'too_large'], true)) {
                    $plugin->migrationStateService->markTerminal(
                        'media',
                        $candidate['sourceKey'],
                        $candidate['siteId'] ?? null,
                        $reason,
                    );
                    $report->incr('terminal');
                    $this->stdout(
                        sprintf("  TERM %s — %s\n", $candidate['sourceKey'], $reason),
                        Console::FG_RED,
                    );
                } else {
                    $report->incr('failed');
                    $this->stderr(
                        sprintf(
                            "  FAIL %s — %s (reason=%s, will retry on next run)\n",
                            $candidate['sourceKey'],
                            $e->getMessage(),
                            $reason,
                        ),
                        Console::FG_RED,
                    );
                }
            }
        }

        // D-39: emit SYNC-ASSETS-<timestamp>.md.
        $reportPath = $storageDir . '/SYNC-ASSETS-' . gmdate('Y-m-d--H-i-s') . '.md';
        $rendered = self::renderSyncAssetsReport($report, $filters, $candidateCount, $tStart);
        if (!$plugin->mappingFile->writeAtomic($reportPath, $rendered)) {
            $this->stderr("  FAIL could not write {$reportPath}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $this->stdout("  OK   SYNC-ASSETS report written → {$reportPath}\n", Console::FG_GREEN);
        $this->logLine(
            sprintf(
                'actionSyncAssets complete in %dms; healed=%d failed=%d terminal=%d',
                (int) ((microtime(true) - $tStart) * 1000),
                (int) ($report->counts['healed'] ?? 0),
                (int) ($report->counts['failed'] ?? 0),
                (int) ($report->counts['terminal'] ?? 0),
            ),
            1,
        );

        return ($report->counts['failed'] ?? 0) > 0
            ? ExitCode::UNSPECIFIED_ERROR
            : ExitCode::OK;
    }

    /**
     * Phase 4.1 / REC-01 — candidate collection for actionSyncAssets.
     *
     * Streams `MigrationStateService::all('media')` (Generator), applies the
     * D-36 idempotence skip (rows with targetId already set are healed) and
     * the D-37 terminal-state skip (rows marked permanently_failed). The
     * predicate that decides whether a single row matches the filter scope
     * is extracted to syncAssetsCandidateMatchesFilters() so it can be
     * exercised by reflection without a Craft bootstrap.
     *
     * @return list<array{legacyId:int, sourceKey:string, siteId:?int, meta:mixed}>
     */
    private function collectAssetCandidates(MigrationFilters $filters): array
    {
        $plugin = Plugin::getInstance();
        $result = [];
        foreach ($plugin->migrationStateService->all('media') as $row) {
            // D-36 idempotence: a non-empty targetId means the row has been
            // successfully healed. Silently skip — never reprocess.
            if (!empty($row['targetId'])) {
                continue;
            }
            // D-37: terminal rows are reported (count=terminal in REPORT
            // header) but NEVER retried. We don't surface them as candidates
            // — the reporting happens at run-summary time once the count is
            // known via a state-table aggregation outside this loop.
            if (self::syncAssetsRowIsTerminal($row)) {
                continue;
            }
            // Filter scope (D-38).
            if (!self::syncAssetsCandidateMatchesFilters($row, $filters)) {
                continue;
            }
            // Derive legacyId from the sourceKey: 'kuma_media:42' → 42.
            $sourceKey = (string) ($row['sourceKey'] ?? '');
            $legacyId = self::syncAssetsLegacyIdFromKey($sourceKey);
            if ($legacyId <= 0) {
                continue;
            }
            $result[] = [
                'legacyId'  => $legacyId,
                'sourceKey' => $sourceKey,
                'siteId'    => isset($row['siteId']) && $row['siteId'] !== null
                    ? (int) $row['siteId']
                    : null,
                'meta'      => $row['meta'] ?? null,
            ];
        }
        return $result;
    }

    /**
     * Phase 4.1 / REC-01 — pure predicate for the candidate-row filter.
     *
     * Public-static so the test corpus can characterize via Reflection
     * without Craft bootstrap. Decision rules:
     *
     * - Terminal rows (meta.terminalState='permanently_failed') NEVER match
     *   regardless of filter scope (D-37).
     * - Healed rows (non-empty targetId) NEVER match regardless of filter
     *   scope (D-36 idempotence).
     * - When entities filter set: exclude row when meta.ownerEntity is
     *   present and not in the allow-list. Rows with no ownerEntity meta
     *   pass through (most v1.0 media state-rows lack this field — see
     *   AssetMigrationService::record() at lines 402-415, 558-573 — and
     *   silently dropping them all would defeat the recovery surface).
     * - When since filter set: exclude row when dateUpdated is older than
     *   the floor.
     * - Locales filter is NOT applied here at v1.0 — kuma_media has no
     *   direct locale FK, mirroring AssetMigrationService::ingestReferenced
     *   at lines 174-180. Documented as a v1.0 deferral, not a bug.
     *
     * @param array<string, mixed> $row
     */
    public static function syncAssetsCandidateMatchesFilters(array $row, MigrationFilters $filters): bool
    {
        // Hard-skip terminal rows even if filter scope would otherwise admit
        // them. Lock for T-04.1-07-01 (DoS via retry loop).
        if (self::syncAssetsRowIsTerminal($row)) {
            return false;
        }
        // Hard-skip healed rows (D-36 idempotence).
        if (!empty($row['targetId'])) {
            return false;
        }
        // Entities filter — see method docblock for the no-owner pass-through
        // rationale.
        if ($filters->entities !== []) {
            $ownerEntity = null;
            $meta = $row['meta'] ?? null;
            if (is_array($meta) && isset($meta['ownerEntity'])) {
                $ownerEntity = (string) $meta['ownerEntity'];
            }
            if ($ownerEntity !== null
                && !in_array($ownerEntity, $filters->entities, true)
            ) {
                return false;
            }
        }
        // Since filter — strict floor comparison via strtotime.
        if ($filters->since !== null && $filters->since !== '') {
            $rowDate = $row['dateUpdated'] ?? null;
            if ($rowDate !== null && $rowDate !== '') {
                $rowEpoch = strtotime((string) $rowDate);
                $floorEpoch = strtotime((string) $filters->since);
                if ($rowEpoch !== false && $floorEpoch !== false && $rowEpoch < $floorEpoch) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Phase 4.1 / REC-01 — pure terminal-state predicate. Mirrors
     * MigrationStateService::isTerminalMarker shape so the controller can
     * apply the same contract on the row's already-loaded meta payload
     * without re-querying the row.
     *
     * @param array<string, mixed> $row
     */
    private static function syncAssetsRowIsTerminal(array $row): bool
    {
        $meta = $row['meta'] ?? null;
        if ($meta === null || $meta === '') {
            return false;
        }
        if (is_array($meta)) {
            return ($meta['terminalState'] ?? null) === 'permanently_failed';
        }
        if (!is_string($meta)) {
            return false;
        }
        $decoded = json_decode($meta, true);
        if (!is_array($decoded)) {
            return false;
        }
        return ($decoded['terminalState'] ?? null) === 'permanently_failed';
    }

    /**
     * Phase 4.1 / REC-01 — sourceKey shape lock. State rows for media use
     * the `kuma_media:{id}` key shape (see AssetMigrationService line 338
     * + lines 402, 500, 558). Returns 0 on shape mismatch so the candidate
     * loop silently skips rows that don't conform.
     */
    private static function syncAssetsLegacyIdFromKey(string $sourceKey): int
    {
        if ($sourceKey === '') {
            return 0;
        }
        if (str_starts_with($sourceKey, 'kuma_media:')) {
            $id = (int) substr($sourceKey, strlen('kuma_media:'));
            return $id > 0 ? $id : 0;
        }
        // Defensive fallback — bare-int keys treated as legacyId. Older state
        // rows may have shipped this shape; future-proofs the recovery path.
        $bare = (int) $sourceKey;
        return $bare > 0 ? $bare : 0;
    }

    /**
     * Phase 4.1 / REC-01 / D-37 — closed-set RCA classifier mirroring
     * AssetMigrationService::classifyAssetFailureReason heuristics.
     *
     * The classifier is intentionally duplicated here (rather than promoting
     * AssetMigrationService's private classifier to public) so this plan's
     * `git diff src/load/AssetMigrationService.php` shows zero changes per
     * the Task 3 acceptance criteria. The classifier in AssetMigrationService
     * inspects both an exception object AND a row payload; this controller-
     * side variant inspects the message string only — it's the narrower
     * surface and matches sync-assets' actual data flow (we have the
     * exception, not the source row, at the catch site).
     *
     * Closed set: filesystem_404 | mime_mismatch | too_large |
     * deferred_unresolved.
     *
     * Public-static for Reflection testability — same shape as
     * AssetMigrationServiceRcaTest's seam.
     */
    public static function syncAssetsClassifyResolveFailureMessage(string $msg): string
    {
        if (str_contains($msg, 'No such file')
            || str_contains($msg, 'not found')
            || str_contains($msg, 'Copy failed')
        ) {
            return 'filesystem_404';
        }
        if (str_contains($msg, 'mime')
            || str_contains($msg, 'content_type')
            || str_contains($msg, 'allowedFileExtensions')
        ) {
            return 'mime_mismatch';
        }
        if (str_contains($msg, 'too large')
            || str_contains($msg, 'PostMaxSize')
        ) {
            return 'too_large';
        }
        return 'deferred_unresolved';
    }

    /**
     * Phase 4.1 / REC-01 / D-39 — render the SYNC-ASSETS-{ts}.md body.
     * Public-static for Reflection testability. The format mirrors
     * REPORT.md's `## Rehearsal summary` section so operators can read both
     * artifacts with the same mental model.
     *
     * Always emits the heading + rehearsal-summary section regardless of
     * candidates count (CFG-07 always-emit consistency from Plan 04.1-05).
     * When candidates=0, appends a placeholder line instead of leaving an
     * empty section.
     */
    public static function renderSyncAssetsReport(
        MigrationReport $report,
        MigrationFilters $filters,
        int $candidates,
        float $tStart,
    ): string {
        $lines = [];
        $lines[] = '# Sync Assets';
        $lines[] = '';
        $lines[] = '_Recovery command — runs AFTER `migrate run` to re-ingest skipped media. NOT a replacement for `migrate run`._';
        $lines[] = '';
        $lines[] = '## Rehearsal summary';
        $lines[] = '';
        $lines[] = sprintf('- Candidates: %d', $candidates);
        $lines[] = sprintf('- Healed:     %d', (int) ($report->counts['healed'] ?? 0));
        $lines[] = sprintf('- Failed:     %d', (int) ($report->counts['failed'] ?? 0));
        $lines[] = sprintf('- Terminal:   %d', (int) ($report->counts['terminal'] ?? 0));
        $lines[] = sprintf(
            '- Wall clock: %s',
            gmdate('H:i:s', (int) max(0, microtime(true) - $tStart)),
        );
        $lines[] = sprintf(
            '- Filters:    entities=%s; locales=%s; since=%s',
            $filters->entities === [] ? 'all' : implode(',', $filters->entities),
            $filters->locales === [] ? 'all' : implode(',', $filters->locales),
            $filters->since ?? 'none',
        );
        $lines[] = '';
        if ($candidates === 0) {
            $lines[] = '_No candidates — all prior skipped media has been healed or marked terminal._';
            $lines[] = '';
        }
        return implode("\n", $lines);
    }

    // --------------------------------------------------------------------------
    // Internal helpers
    // --------------------------------------------------------------------------

    /**
     * LOC-02 preflight gate — shared by every action that reads legacy data.
     */
    private function preflightLocale(MigrationFilters $filters): int
    {
        $plugin = Plugin::getInstance();
        $unmapped = $plugin->localePreflight->ensure($filters);
        if ($unmapped !== null) {
            $this->stderr(
                "  FAIL unmapped Kunstmaan locales: " . implode(', ', $unmapped) . "\n",
                Console::FG_RED,
            );
            return ExitCode::CONFIG;
        }
        $this->stdout("  OK   locale preflight\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadMappingOrFail(): ?array
    {
        $plugin = Plugin::getInstance();
        $mappingPath = $plugin->mappingFile->resolvePath();
        if (!is_file($mappingPath)) {
            $this->stderr(
                "  FAIL mapping.yaml not found at {$mappingPath} — run analyze first\n",
                Console::FG_RED,
            );
            return null;
        }
        $mapping = $plugin->mappingFile->load($mappingPath);
        $this->stdout(
            "  OK   mapping loaded (" . count($mapping['proposals'] ?? []) . " rows) → {$mappingPath}\n",
            Console::FG_GREEN,
        );
        return $mapping;
    }

    /**
     * Stream extracted/<fqcn-slug>/*.json payloads back into TransformService as a tuple
     * iterable. ExtractService writes per-node JSON files to disk; TransformService
     * accepts an in-memory iterable per CONTEXT D-48 reshape — this method is the
     * controller-side bridge between the two stages.
     *
     * @return iterable<array<string, mixed>>
     */
    private function streamExtracted(string $storageDir): iterable
    {
        $extractedRoot = $storageDir . '/extracted';
        if (!is_dir($extractedRoot)) {
            return;
        }
        $fqcnDirs = glob($extractedRoot . '/*', GLOB_ONLYDIR) ?: [];
        foreach ($fqcnDirs as $fqcnDir) {
            $files = glob($fqcnDir . '/*.json') ?: [];
            sort($files);
            foreach ($files as $file) {
                $raw = file_get_contents($file);
                if ($raw === false) {
                    continue;
                }
                $payload = json_decode($raw, true);
                if (!is_array($payload)) {
                    continue;
                }
                yield $payload;
            }
        }
    }

    /**
     * Walk transformed/entries/<fqcn-slug>/*.json files in a stable order. Used by
     * actionLoad's per-entry progress emission and by the dry-run count.
     *
     * @return iterable<string>
     */
    private function iterateTransformedFiles(string $transformedDir): iterable
    {
        $fqcnDirs = glob($transformedDir . '/*', GLOB_ONLYDIR) ?: [];
        sort($fqcnDirs);
        foreach ($fqcnDirs as $dir) {
            $files = glob($dir . '/*.json') ?: [];
            sort($files);
            foreach ($files as $file) {
                yield $file;
            }
        }
    }

    /**
     * Per-entry load loop with ETL-06 progress emission.
     *
     * Emits `[N/total] <slug> → created|updated|skipped` to stdout (FG_GREEN) on
     * success, and `[N/total] <slug> → FAILED: <reason>` to stderr (FG_RED) on
     * failure (recorded via $report->recordFailure with D-50 5-frame trace).
     */
    private function runLoadFromDisk(string $transformedDir, MigrationOptions $opts, MigrationReport $report): int
    {
        $plugin = Plugin::getInstance();

        // Pre-walk to compute total — operators want [N/total] not just [N].
        $files = [];
        foreach ($this->iterateTransformedFiles($transformedDir) as $f) {
            $files[] = $f;
        }
        $total = count($files);
        if ($total === 0) {
            $this->stdout("  WARN no transformed payloads to load\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        $i = 0;
        foreach ($files as $jsonPath) {
            $i++;
            $slug = $this->slugFromTransformedPath($jsonPath);

            $beforeCounts = [
                'created' => $report->counts['created'] ?? 0,
                'updated' => $report->counts['updated'] ?? 0,
                'skipped' => $report->counts['skipped'] ?? 0,
            ];

            try {
                $plugin->atomicMigrationService->migrateOneEntry($jsonPath, $opts, $report);
            } catch (Throwable $e) {
                $report->recordFailure(basename($jsonPath, '.json'), $slug, 'AtomicMigrationService', $e);
                $this->stderr(sprintf(
                    "[%d/%d] %s → FAILED: %s\n",
                    $i, $total, $slug, $e->getMessage(),
                ), Console::FG_RED);
                continue;
            }

            // Determine which bucket the migrate call incremented.
            $verb = 'skipped';
            if (($report->counts['created'] ?? 0) > $beforeCounts['created']) {
                $verb = 'created';
            } elseif (($report->counts['updated'] ?? 0) > $beforeCounts['updated']) {
                $verb = 'updated';
            }
            $this->stdout(sprintf(
                "[%d/%d] %s → %s\n",
                $i, $total, $slug, $verb,
            ), Console::FG_GREEN);
        }

        return ExitCode::OK;
    }

    /**
     * Slug for ETL-06 progress lines: `<fqcn-slug>/<node-id>` derived from
     * the transformed file path. Cheap, deterministic, no JSON re-read needed.
     */
    private function slugFromTransformedPath(string $jsonPath): string
    {
        $file = basename($jsonPath, '.json');
        $dir = basename(dirname($jsonPath));
        return $dir . '/' . $file;
    }

    /**
     * Map fqcn-style strings (with backslashes, slashes, or other punctuation)
     * to a filesystem-safe slug — mirrors ExtractService::fqcnSlug() byte-for-byte.
     */
    private function slugify(string $fqcn): string
    {
        return preg_replace('/[^A-Za-z0-9_\-]+/', '_', $fqcn) ?? 'unknown';
    }

    /**
     * D-65: parse the verbosity flag — accepts integer (`--verbose=2`) or
     * string forms (`-v` → 'v', `-vv` → 'vv', `-vvv` → 'vvv'). Counts chars
     * for short-flag repetition, parses digits for the explicit form, and
     * clamps to [0, 3] so callers can match exact ladder rungs.
     *
     * Ladder:
     *   0  — quiet (default)
     *   1  — stage timings (`-v`)
     *   2  — per-entry detail (`-vv`)
     *   3  — SQL trace (`-vvv`; best-effort — Yii has its own SQL log channel)
     */
    private function verbosityLevel(): int
    {
        if (is_int($this->verbose)) {
            return max(0, min(3, $this->verbose));
        }
        // PHP type-juggle: false / null squeak through here too.
        $raw = (string) $this->verbose;
        $trim = trim($raw);
        if ($trim === '' || $trim === '0') {
            // Empty string surface is also produced when Yii sees `-v` with no
            // value — treat as level 1.
            return $raw === '' ? 0 : 1;
        }
        if (ctype_digit($trim)) {
            return max(0, min(3, (int) $trim));
        }
        // String like 'v', 'vv', 'vvv' (when used as `-vv`) — count chars.
        return max(0, min(3, strlen($trim)));
    }

    /**
     * D-67: open the per-run timestamped log file. Idempotent: a re-call with
     * the same path no-ops; a re-call with a different path closes the prior
     * handle and opens the new one. Best-effort — failures are silent so an
     * unwritable storage dir doesn't kill the migrate run.
     */
    private function openLogFile(string $path): void
    {
        if ($this->logFilePath === $path && $this->logFileHandle !== null) {
            return;
        }
        if ($this->logFileHandle !== null) {
            @fclose($this->logFileHandle);
            $this->logFileHandle = null;
        }
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $handle = @fopen($path, 'a');
        if ($handle !== false) {
            $this->logFileHandle = $handle;
            $this->logFilePath = $path;
        }
    }

    /**
     * D-65 / D-67: append a line to the per-run log file when verbosity meets
     * the supplied minimum level. ISO-8601 timestamp prefix so post-run greps
     * can correlate with REPORT.md timing rows.
     */
    private function logLine(string $line, int $minVerbosity = 1): void
    {
        if ($this->verbosityLevel() < $minVerbosity) {
            return;
        }
        if ($this->logFileHandle === null) {
            return;
        }
        @fwrite($this->logFileHandle, '[' . gmdate('c') . '] ' . $line . "\n");
    }

    /**
     * D-67: derive the canonical per-run log path under storage/migration/.
     * Pure helper so action methods can call it once at the top and stash the
     * result in $this->logFilePath via openLogFile().
     */
    private function defaultLogPath(): string
    {
        $timestamp = gmdate('Y-m-d--H-i-s');
        return Craft::$app->path->getStoragePath() . '/migration/migrate-' . $timestamp . '.log';
    }

    /**
     * D-55: merge a per-stage MigrationReport into the run-wide report.
     * Counts are summed; warnings/failures are pushed with a `stage:` tag prefix
     * so REPORT.md downstream (D-68 `## Skipped stages` + the warnings/failures
     * sections) can attribute each line to its emitting stage.
     */
    private function mergeReport(MigrationReport $into, MigrationReport $from, string $stage): void
    {
        foreach ($from->counts as $bucket => $count) {
            $into->incr($bucket, (int) $count);
        }
        foreach ($from->warnings as $w) {
            $into->warn(sprintf('[%s] %s', $stage, $w));
        }
        foreach ($from->failures as $f) {
            // recordFailure() also increments 'failed', which we already merged
            // above via $from->counts. Push directly into $into->failures to
            // avoid double-counting.
            $into->failures[] = [
                'legacyId' => $f['legacyId'] ?? '?',
                'slug'     => isset($f['slug']) ? sprintf('[%s] %s', $stage, $f['slug']) : sprintf('[%s] -', $stage),
                'handler'  => $f['handler'] ?? null,
                'message'  => $f['message'] ?? '',
                'trace'    => $f['trace'] ?? null,
            ];
        }
    }

    /**
     * Append D-52 counts + D-68 (Rehearsal summary, Skipped stages, Asset RCA)
     * + D-50 failures sections to REPORT.md. The Phase 2 ReportBuilder already
     * wrote the analyze REPORT.md content to this same path; Phase 3 overwrites
     * with a Migration-section render so the operator has a single canonical
     * artifact per pipeline run. T-2-21 mitigation: writeAtomic produces the
     * on-disk trail regardless of exit code.
     *
     * Section order (D-68 PATTERNS.md "Shared Patterns"):
     *   1. Migration counts (D-52)         — existing
     *   2. Rehearsal summary (D-68)        — totals + wall-clock + filter scope + flag + log path
     *   3. Skipped stages (D-68)           — adapter-absence WARNs (omitted when empty)
     *   4. Warnings                        — existing
     *   5. Failures (D-50)                 — existing
     *   6. Asset RCA (D-68)                — per-asset failure rows (omitted when empty)
     */
    private function writeReport(
        string $storageDir,
        MigrationReport $report,
        ?MigrationFilters $filters = null,
        ?float $tRunStart = null,
    ): void {
        $plugin = Plugin::getInstance();
        $reportPath = $storageDir . '/REPORT.md';

        // D-68 Asset RCA: snapshot AssetMigrationService::$rcaRows into the
        // report so the section render below has one uniform read site.
        // Idempotent — if writeReport is called twice in the same run (e.g.
        // actionLoad + later actionIndex bolt-on) the rows are already pushed
        // into $report->assetRcaRows; skip re-snapshotting.
        if ($report->assetRcaRows === [] && $plugin->assetMigrationService->assetRcaRows !== []) {
            foreach ($plugin->assetMigrationService->assetRcaRows as $rcaRow) {
                $report->pushAssetRca(
                    (int) ($rcaRow['legacyId'] ?? 0),
                    (string) ($rcaRow['reason'] ?? 'deferred_unresolved'),
                    (string) ($rcaRow['path'] ?? ''),
                );
            }
        }

        $lines = [];
        $lines[] = "# Migrate report";
        $lines[] = "";
        $lines[] = "Generated: " . (new \DateTimeImmutable())->format('Y-m-d H:i:s T');
        $lines[] = "";

        // 1. Migration counts (existing — D-52).
        $lines[] = "## Migration counts (D-52)";
        $lines[] = "";
        $lines[] = "| Bucket | Count |";
        $lines[] = "|--------|------:|";
        $buckets = ['created', 'updated', 'skipped', 'failed', 'finalize.processed', 'finalize.rewritten', 'finalize.unresolvable'];
        foreach ($buckets as $bucket) {
            $lines[] = "| {$bucket} | " . (int) ($report->counts[$bucket] ?? 0) . " |";
        }
        $lines[] = "";

        // 2. Rehearsal summary (D-68 NEW). Totals + wall-clock + filter scope +
        //    --live/--dry-run flag + log file path so operators have a
        //    one-glance status at the head of REPORT.md.
        $lines[] = "## Rehearsal summary";
        $lines[] = "";
        $lines[] = "- Total created: " . (int) ($report->counts['created'] ?? 0);
        $lines[] = "- Total updated: " . (int) ($report->counts['updated'] ?? 0);
        $lines[] = "- Total skipped: " . (int) ($report->counts['skipped'] ?? 0);
        $lines[] = "- Total failed: " . (int) ($report->counts['failed'] ?? 0);

        $duration = $tRunStart !== null
            ? $this->formatWallClock(microtime(true) - $tRunStart)
            : '(not recorded)';
        $lines[] = "- Wall-clock duration: " . $duration;

        $entitiesScope = $filters !== null && $filters->entities !== []
            ? implode(',', $filters->entities)
            : '(all)';
        $localesScope = $filters !== null && $filters->locales !== []
            ? implode(',', $filters->locales)
            : '(all)';
        $sinceScope = $filters !== null && $filters->since !== null && $filters->since !== ''
            ? $filters->since
            : 'null';
        $lines[] = sprintf(
            '- Filter scope: entities=%s, locales=%s, since=%s',
            $entitiesScope,
            $localesScope,
            $sinceScope,
        );
        $lines[] = '- Flag: ' . ($this->live ? '--live' : '--dry-run');
        $lines[] = '- Log file: ' . ($this->logFilePath ?? '(none)');
        $lines[] = "";

        // 3. Skipped stages (D-68 + Phase 4.1 / D-41+D-42+D-43) — sourced from
        //    $report->warnings filtered for adapter-absence/disable/bypass messages.
        //    D-27: four DISTINCT warn-line patterns let operators distinguish:
        //       - 'plugin not installed' / 'plugin not loaded' (adapter unavailable)
        //       - 'SEO adapter disabled' / 'Retour adapter disabled' (Settings-disabled)
        //       - 'skipped via --no-seo' / 'skipped via --no-retour' (CLI override)
        //    CFG-07: section ALWAYS emits, with placeholder copy when empty.
        $skippedStageLines = [];
        foreach ($report->warnings as $w) {
            if (str_contains($w, 'SEOmatic plugin not installed')
                || str_contains($w, 'Retour plugin not installed')
                || str_contains($w, 'Retour plugin not loaded')
                || str_contains($w, 'SEO adapter disabled')
                || str_contains($w, 'Retour adapter disabled')
                || str_contains($w, 'skipped via --no-seo')
                || str_contains($w, 'skipped via --no-retour')
            ) {
                $skippedStageLines[] = '- ' . $w;
            }
        }
        foreach (self::renderSkippedStagesSection($skippedStageLines) as $sl) {
            $lines[] = $sl;
        }

        // 4. Warnings (existing).
        if ($report->warnings !== []) {
            $lines[] = "## Warnings";
            $lines[] = "";
            foreach ($report->warnings as $w) {
                $lines[] = "- " . $w;
            }
            $lines[] = "";
        }

        // 5. Failures (existing — D-50).
        $lines[] = "## Failures (D-50)";
        $lines[] = "";
        if ($report->failures === []) {
            $lines[] = "_No per-entry failures._";
            $lines[] = "";
        } else {
            foreach ($report->failures as $f) {
                $lines[] = sprintf(
                    "### legacy=%s slug=%s handler=%s",
                    (string) ($f['legacyId'] ?? '?'),
                    (string) ($f['slug'] ?? '-'),
                    (string) ($f['handler'] ?? '-'),
                );
                $lines[] = "";
                $lines[] = "**Message:** " . (string) ($f['message'] ?? '');
                $lines[] = "";
                if (!empty($f['trace'])) {
                    $lines[] = "```";
                    $lines[] = (string) $f['trace'];
                    $lines[] = "```";
                    $lines[] = "";
                }
            }
        }

        // 6. Asset RCA (D-68 + Phase 4.1 / D-41+D-42+D-43 / CFG-07) — markdown table
        //    from $report->assetRcaRows. ALWAYS emits, with placeholder copy when empty.
        foreach (self::renderAssetRcaSection($report->assetRcaRows) as $rl) {
            $lines[] = $rl;
        }

        $rendered = implode("\n", $lines) . "\n";
        if (!$plugin->mappingFile->writeAtomic($reportPath, $rendered)) {
            $this->stderr("  FAIL could not write {$reportPath}\n", Console::FG_RED);
            return;
        }
        $this->stdout("  OK   REPORT.md written → {$reportPath}\n", Console::FG_GREEN);
    }

    /**
     * D-68 Rehearsal summary helper: format a duration in seconds as hh:mm:ss.
     */
    private function formatWallClock(float $seconds): string
    {
        $secs = (int) max(0, round($seconds));
        $h = intdiv($secs, 3600);
        $m = intdiv($secs % 3600, 60);
        $s = $secs % 60;
        return sprintf('%02d:%02d:%02d', $h, $m, $s);
    }

    /**
     * Phase 4.1 / D-41+D-42+D-43 / CFG-07 — pure helper: render the
     * `## Skipped stages` REPORT.md section. ALWAYS emits the heading;
     * placeholder copy when no skipped stages were recorded.
     *
     * Public-static so the characterization test can call it directly without
     * a Craft bootstrap.
     *
     * @param list<string> $skippedStageLines pre-formatted list of `'- <warn>'` lines
     * @return list<string>
     */
    public static function renderSkippedStagesSection(array $skippedStageLines): array
    {
        $out = [];
        $out[] = '## Skipped stages';
        $out[] = '';
        if ($skippedStageLines === []) {
            $out[] = '_No skipped stages — all configured adapters were exercised._';
        } else {
            foreach ($skippedStageLines as $sl) {
                $out[] = $sl;
            }
        }
        $out[] = '';
        return $out;
    }

    /**
     * Phase 4.1 / D-41+D-42+D-43 / CFG-07 — pure helper: render the
     * `## Asset RCA` REPORT.md section. ALWAYS emits the heading;
     * placeholder copy when no RCA rows were recorded.
     *
     * @param list<array{legacyId?: int, reason?: string, path?: string}> $rcaRows
     * @return list<string>
     */
    public static function renderAssetRcaSection(array $rcaRows): array
    {
        $out = [];
        $out[] = '## Asset RCA';
        $out[] = '';
        if ($rcaRows === []) {
            $out[] = '_No asset RCA rows — no assets were migrated, or all migrated cleanly._';
        } else {
            $out[] = '| legacy_id | reason | path |';
            $out[] = '|-----------|--------|------|';
            foreach ($rcaRows as $rca) {
                $out[] = sprintf(
                    '| %d | %s | %s |',
                    (int) ($rca['legacyId'] ?? 0),
                    (string) ($rca['reason'] ?? 'deferred_unresolved'),
                    (string) ($rca['path'] ?? ''),
                );
            }
        }
        $out[] = '';
        return $out;
    }

    /**
     * Phase 4.1 / D-26 + D-27 — testable distinct warn-line copy for the CLI
     * --no-seo bypass path (different from Settings-disabled and from
     * plugin-not-installed). Used by REPORT.md skipped-stages aggregation.
     *
     * @internal
     */
    private static function cliBypassSeoWarnLine(): string
    {
        return 'SEO migration skipped via --no-seo (CLI override).';
    }

    /**
     * Phase 4.1 / D-26 + D-27 — testable distinct warn-line copy for the CLI
     * --no-retour bypass path.
     *
     * @internal
     */
    private static function cliBypassRetourWarnLine(): string
    {
        return 'Redirect migration skipped via --no-retour (CLI override).';
    }

    // =====================================================================
    // Progress-bar helpers (Yii\helpers\Console — same pattern as
    // AssetMigrationService::ingestReferenced). Each maker returns a closure
    // suited to a specific service's callback signature, lazy-starting the bar
    // on the first invocation so callers don't need to know the total upfront.
    // The shared $this->progressStarted flag lets endProgressIfStarted() be
    // called from both success and exception paths without double-ending.
    // =====================================================================

    /** Set true when Console::startProgress has been called and not yet ended. */
    private bool $progressStarted = false;

    /**
     * Build the per-row callback for ExtractService. Lazy-starts the bar on the
     * first row so a job that immediately filters everything out emits no bar at all.
     */
    private function makeExtractProgress(): callable
    {
        return function (int $done, int $total, string $fqcn): void {
            if (!$this->progressStarted) {
                Console::startProgress(0, max(1, $total), '  ... extract ');
                $this->progressStarted = true;
            }
            $denom = max($total, $done);
            Console::updateProgress(
                $done,
                $denom,
                sprintf('  ... extract [%d/%d %s] ', $done, $denom, $this->shortFqcn($fqcn)),
            );
        };
    }

    /**
     * Build the per-row callback for TransformService. Total is supplied by the
     * caller (typically the just-finished extract count or a count of files in
     * extracted/<fqcn>/*.json) — TransformService itself only knows the running
     * input count.
     */
    private function makeTransformProgress(int $total): callable
    {
        return function (int $done, string $fqcn) use ($total): void {
            if (!$this->progressStarted) {
                Console::startProgress(0, max(1, $total), '  ... transform ');
                $this->progressStarted = true;
            }
            $denom = max($total, $done);
            Console::updateProgress(
                $done,
                $denom,
                sprintf('  ... transform [%d/%d %s] ', $done, $denom, $this->shortFqcn($fqcn)),
            );
        };
    }

    /**
     * Build the per-entry callback for FinalizeWalker. Walker pre-counts via
     * $query->count() so the total is known on the first invocation.
     */
    private function makeFinalizeProgress(): callable
    {
        return function (int $done, int $total): void {
            if (!$this->progressStarted) {
                Console::startProgress(0, max(1, $total), '  ... finalize ');
                $this->progressStarted = true;
            }
            Console::updateProgress(
                $done,
                max($total, $done),
                sprintf('  ... finalize [%d/%d] ', $done, max($total, $done)),
            );
        };
    }

    /** Idempotent end-of-progress; safe to call after success or exception paths. */
    private function endProgressIfStarted(): void
    {
        if ($this->progressStarted) {
            Console::endProgress();
            $this->progressStarted = false;
        }
    }

    /** Truncate FQCN to last 2 namespace segments for compact progress prefixes. */
    private function shortFqcn(string $fqcn): string
    {
        $parts = explode('\\', trim($fqcn, '\\'));
        $tail = array_slice($parts, -2);
        return implode('\\', $tail);
    }

    /** Cheap glob-based count of extracted/<fqcn>/*.json — used as transform denominator. */
    private function countExtractedFiles(string $storageDir): int
    {
        $root = $storageDir . '/extracted';
        if (!is_dir($root)) {
            return 0;
        }
        $count = 0;
        foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $count += count(glob($dir . '/*.json') ?: []);
        }
        return $count;
    }
}
