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
        $filters = $plugin->filterFactory->fromCli($this->entities, $this->locales, $this->since);
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
        try {
            $extractReport = $plugin->extractService->run($mapping, $filters);
            $extractCounts = is_array($extractReport) ? $extractReport : iterator_to_array($extractReport);
        } catch (Throwable $e) {
            $this->stderr("  FAIL extract: {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
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
        try {
            $extractedStream = $this->streamExtracted($storageDir);
            foreach ($plugin->transformService->run($extractedStream, $mapping, $filters) as $payload) {
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
            $this->stderr("  FAIL transform: {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
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
            try {
                $finalizeCounts = $plugin->finalizeWalker->walk($filters);
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
        if ($this->live) {
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
        } else {
            $this->stdout(
                "  WARN seo skipped (dry-run)\n",
                Console::FG_YELLOW,
            );
        }

        // Step 6.6 (D-55): Retour stage — same shape; service short-circuits when Retour absent.
        if ($this->live) {
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
        $filters = $plugin->filterFactory->fromCli($this->entities, $this->locales, $this->since);

        if (!$this->live) {
            $this->stdout(
                "  WARN seo skipped (dry-run; pass --live to write SEOmatic bundles)\n",
                Console::FG_YELLOW,
            );
            return ExitCode::OK;
        }

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
        $filters = $plugin->filterFactory->fromCli($this->entities, $this->locales, $this->since);

        if (!$this->live) {
            $this->stdout(
                "  WARN retour skipped (dry-run; pass --live to write redirects)\n",
                Console::FG_YELLOW,
            );
            return ExitCode::OK;
        }

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
        $filters = $plugin->filterFactory->fromCli($this->entities, $this->locales, $this->since);

        if (($exit = $this->preflightLocale($filters)) !== ExitCode::OK) {
            return $exit;
        }
        $mapping = $this->loadMappingOrFail();
        if ($mapping === null) {
            return ExitCode::CONFIG;
        }

        try {
            $extractReport = $plugin->extractService->run($mapping, $filters);
            $extractCounts = is_array($extractReport) ? $extractReport : iterator_to_array($extractReport);
        } catch (Throwable $e) {
            $this->stderr("  FAIL extract: {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
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
        $filters = $plugin->filterFactory->fromCli($this->entities, $this->locales, $this->since);
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
        try {
            $extractedStream = $this->streamExtracted($storageDir);
            foreach ($plugin->transformService->run($extractedStream, $mapping, $filters) as $payload) {
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
            $this->stderr("  FAIL transform: {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

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
        $filters = $plugin->filterFactory->fromCli($this->entities, $this->locales, $this->since);
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
        $filters = $plugin->filterFactory->fromCli($this->entities, $this->locales, $this->since);

        if (!$this->live) {
            $this->stdout(
                "  WARN finalize skipped (dry-run; pass --live to rewrite CKEditor fields)\n",
                Console::FG_YELLOW,
            );
            return ExitCode::OK;
        }

        try {
            $counts = $plugin->finalizeWalker->walk($filters);
        } catch (Throwable $e) {
            $this->stderr("  FAIL finalize: {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
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
        $filters = $plugin->filterFactory->fromCli($this->entities, $this->locales, $this->since);

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

        // 3. Skipped stages (D-68 NEW) — sourced from $report->warnings filtered
        //    for adapter-absence messages. Omit section entirely when empty.
        $skippedStageLines = [];
        foreach ($report->warnings as $w) {
            if (str_contains($w, 'SEOmatic plugin not installed')
                || str_contains($w, 'Retour plugin not installed')
                || str_contains($w, 'Retour plugin not loaded')
            ) {
                $skippedStageLines[] = '- ' . $w;
            }
        }
        if ($skippedStageLines !== []) {
            $lines[] = "## Skipped stages";
            $lines[] = "";
            foreach ($skippedStageLines as $sl) {
                $lines[] = $sl;
            }
            $lines[] = "";
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

        // 6. Asset RCA (D-68 NEW) — markdown table from $report->assetRcaRows.
        //    Omit section entirely when empty.
        if ($report->assetRcaRows !== []) {
            $lines[] = "## Asset RCA";
            $lines[] = "";
            $lines[] = "| legacy_id | reason | path |";
            $lines[] = "|-----------|--------|------|";
            foreach ($report->assetRcaRows as $rca) {
                $lines[] = sprintf(
                    '| %d | %s | %s |',
                    (int) ($rca['legacyId'] ?? 0),
                    (string) ($rca['reason'] ?? 'deferred_unresolved'),
                    (string) ($rca['path'] ?? ''),
                );
            }
            $lines[] = "";
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
}
