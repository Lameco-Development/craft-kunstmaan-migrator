<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\workflow;

use Craft;
use yii\base\Component;
use craft\db\MigrationManager;
use craft\helpers\Console;
use craft\helpers\FileHelper;
use lameco\kunstmaanmigrator\NeverProductionTrait;
use lameco\kunstmaanmigrator\Plugin;
use lameco\kunstmaanmigrator\filter\FilterFactory;
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
class MigrateWorkflow extends Component
{
    use NeverProductionTrait;

    public ?int $batchOffset = null;
    public ?int $batchLimit = null;

    /** @var null|callable(array<string, mixed>): void */
    private $progress = null;

    /** @var list<string> */
    private array $stdoutBuffer = [];

    /** @var list<string> */
    private array $stderrBuffer = [];

    private int $lastProcessed = 0;
    private int $lastTotal = 0;
    private ?int $lastNextBatchOffset = null;

    /**
     * Run shared migrate orchestration for CLI, CP, and queue adapters.
     *
     * Supported option keys are exactly live, confirm, preloadAssets, force,
     * entities, locales, since, noSeo, noRetour, noRelJoin,
     * includeUnreferencedTaxonomies, limit, onlyId, verbose, batchOffset,
     * and batchLimit. Dry-run remains the default when live is false.
     *
     * @param array<string, mixed> $options
     * @param null|callable(array<string, mixed>): void $progress
     * @return array{status:string, mode:string, filters:array<string,mixed>, artifactPaths:list<string>, logPath:?string, summary:array<string,mixed>, failure:?array<string,mixed>, processed:int, total:int, nextBatchOffset:?int}
     */
    public function run(array $options, ?callable $progress = null): array
    {
        $allowed = ['live', 'confirm', 'preloadAssets', 'force', 'entities', 'locales', 'since', 'noSeo', 'noRetour', 'noRelJoin', 'includeUnreferencedTaxonomies', 'limit', 'onlyId', 'verbose', 'batchOffset', 'batchLimit'];
        $unknown = array_values(array_diff(array_keys($options), $allowed));
        if ($unknown !== []) {
            throw new \InvalidArgumentException('Unsupported migrate workflow option(s): ' . implode(', ', $unknown));
        }

        foreach ($allowed as $key) {
            if (!array_key_exists($key, $options)) {
                continue;
            }
            if (in_array($key, ['entities', 'locales', 'since'], true)) {
                $this->{$key} = $options[$key] === null ? null : (string) $options[$key];
                continue;
            }
            if (in_array($key, ['limit', 'onlyId', 'batchOffset', 'batchLimit'], true)) {
                $this->{$key} = $options[$key] === null ? null : (int) $options[$key];
                continue;
            }
            if ($key === 'verbose') {
                $this->verbose = is_int($options[$key]) ? $options[$key] : (string) $options[$key];
                continue;
            }
            $this->{$key} = (bool) $options[$key];
        }

        $this->progress = $progress;
        $this->stdoutBuffer = [];
        $this->stderrBuffer = [];
        $this->lastProcessed = 0;
        $this->lastTotal = 0;
        $this->lastNextBatchOffset = null;
        $this->logFilePath = null;
        $this->logFileHandle = null;

        try {
            $exitCode = $this->actionIndex();
        } catch (\Throwable $e) {
            return [
                'status' => 'failed',
                'mode' => $this->live ? 'live' : 'dryRun',
                'filters' => $this->filtersForResult(),
                'artifactPaths' => $this->artifactPathsForResult(),
                'logPath' => $this->logFilePath,
                'summary' => $this->summaryForResult(\yii\console\ExitCode::UNSPECIFIED_ERROR),
                'failure' => ['message' => $e->getMessage(), 'type' => $e::class],
                'processed' => $this->lastProcessed,
                'total' => $this->lastTotal,
                'nextBatchOffset' => $this->lastNextBatchOffset,
            ];
        }

        return [
            'status' => $exitCode === \yii\console\ExitCode::OK ? 'succeeded' : 'failed',
            'mode' => $this->live ? 'live' : 'dryRun',
            'filters' => $this->filtersForResult(),
            'artifactPaths' => $this->artifactPathsForResult(),
            'logPath' => $this->logFilePath,
            'summary' => $this->summaryForResult($exitCode),
            'failure' => $exitCode === \yii\console\ExitCode::OK ? null : ['exitCode' => $exitCode, 'stderr' => $this->stderrBuffer],
            'processed' => $this->lastProcessed,
            'total' => $this->lastTotal,
            'nextBatchOffset' => $this->lastNextBatchOffset,
        ];
    }

    public function stdout($string, $color = null): int
    {
        $this->stdoutBuffer[] = (string) $string;
        if ($this->progress !== null) {
            ($this->progress)(['stream' => 'stdout', 'message' => (string) $string]);
        }
        return strlen((string) $string);
    }

    public function stderr($string, $color = null): int
    {
        $this->stderrBuffer[] = (string) $string;
        if ($this->progress !== null) {
            ($this->progress)(['stream' => 'stderr', 'message' => (string) $string]);
        }
        return strlen((string) $string);
    }

    /** @return array<string, mixed> */
    private function filtersForResult(): array
    {
        return [
            'entities' => $this->entities,
            'locales' => $this->locales,
            'since' => $this->since,
            'noSeo' => $this->noSeo,
            'noRetour' => $this->noRetour,
            'noRelJoin' => $this->noRelJoin,
            'includeUnreferencedTaxonomies' => $this->includeUnreferencedTaxonomies,
            'limit' => $this->limit,
            'onlyId' => $this->onlyId,
            'batchOffset' => $this->batchOffset,
            'batchLimit' => $this->batchLimit,
        ];
    }

    /** @return list<string> */
    private function artifactPathsForResult(): array
    {
        $storageDir = Craft::$app->path->getStoragePath() . '/migration';
        $paths = [$storageDir . '/REPORT.md'];
        if ($this->logFilePath !== null) {
            $paths[] = $this->logFilePath;
        }
        return $paths;
    }

    /** @return array<string, mixed> */
    private function summaryForResult(int $exitCode): array
    {
        return [
            'exitCode' => $exitCode,
            'stdout' => $this->stdoutBuffer,
            'stderr' => $this->stderrBuffer,
        ];
    }


    private const TRANSFORM_BLOCK_MARKER = 'transform-block.json';

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
     * Phase 7 debug flags. `--limit=N` caps per-FQCN extraction (combine with
     * --entities for "first N rows of class X"). `--only-id=N` extracts the
     * single legacy node row whose `kuma_nodes.id` matches — combined with
     * --entities for an unambiguous "this exact entry" target.
     *
     * Both null by default → no scoping. Designed for fast-iteration debug
     * cycles where re-running the whole migration is too slow.
     */
    public ?int $limit  = null;
    public ?int $onlyId = null;

    // Phase 4.1 / D-26 — CLI override flags for adapter bypass per-run. Distinct
    // from Settings::seoEnabled / retourEnabled (which persist across runs);
    // these flip the adapter for THIS invocation only and emit a distinct
    // warn-line copy so REPORT.md skipped-stages aggregation can tell them apart.
    public bool $noSeo = false;
    public bool $noRetour = false;

    /**
     * Phase 8.5 / D-24 — `--no-rel-join` per-run override. Bypasses optional
     * ExtractService Doctrine ManyToOne FK expansion (the helper that embeds
     * `_rel:<prop>.<col>` keys into detail rows + page-part rows). The setting
     * now defaults off so extracted JSON stays source-faithful; this flag
     * remains as a per-run safety override for installations that opt in.
     */
    public bool $noRelJoin = false;

    /**
     * Phase 10: full taxonomy vocabulary import is explicit opt-in. Default
     * actionIndex behavior is page-rooted referenced-only lazy taxonomy
     * resolution; this flag restores the pre-page-load migrateAll() path when
     * operators intentionally want unreferenced taxonomy rows too.
     */
    public bool $includeUnreferencedTaxonomies = false;

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
        $filters = $this->buildRuntimeFilters($plugin, $this->noSeo, $this->noRetour);
        $this->applyNoRelJoinOverride($plugin);
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
        $compiledPreflight = $this->preflightCompiledMapping($mapping, $this->migrateTargetSchema($plugin));
        if ($compiledPreflight['missing'] !== [] || $compiledPreflight['fatal'] !== []) {
            $this->stderr(
                "  FAIL compiled mapping preflight blocked migrate --live\n",
                Console::FG_RED,
            );
            foreach ($compiledPreflight['messages'] as $message) {
                $this->stderr("  - {$message}\n", Console::FG_RED);
            }
            return ExitCode::CONFIG;
        }
        $this->clearFullPipelineArtifacts($storageDir);
        $opts = new MigrationOptions(
            dryRun: !$this->live,
            force: $this->force,
            skipAssets: false,
        );

        // Step 3: extract (writes storage/migration/extracted/<fqcn-slug>/<node-id>.json).
        $extractProgress = $this->makeExtractProgress();
        try {
            $extractReport = $plugin->extractService->run($mapping, $filters, $this->buildExtractOptions(), $extractProgress);
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

        if ($this->live) {
            $dependencyExit = $this->migrateMissingPageDependencies($storageDir, $mapping, $filters, $opts, $report);
            if ($dependencyExit !== ExitCode::OK) {
                return $dependencyExit;
            }
            $extractedNodes = $this->countExtractedFiles($storageDir);
        }

        // Step 4: transform — stream extracted/*.json through TransformService and persist
        // each yielded payload to transformed/entries/<fqcn-slug>/<node-id>.json so the
        // load stage can read by file path (AtomicMigrationService::migrateOneEntry's
        // verbatim v1 contract takes a file path, not a tuple — Plan 03-12 SUMMARY).
        $transformedDir = $storageDir . '/transformed/entries';
        $transformedCount = 0;
        $this->clearTransformBlockMarker($storageDir);
        // Use the just-completed extract count as the transform denominator — extract and
        // transform are 1:1 at the input level (locale fan-out happens in transform's output).
        $transformProgress = $this->makeTransformProgress($extractedNodes);
        $hasBlockingTransformRelationFailure = false;
        try {
            $extractedStream = $this->streamExtracted($storageDir);
            foreach ($plugin->transformService->run($extractedStream, $mapping, $filters, $this->buildTransformOptions($report), $transformProgress) as $payload) {
                if (isset($payload['__report'])) {
                    $hasBlockingTransformRelationFailure =
                        $this->mergeTransformReportSentinel($payload, $report)
                        || $hasBlockingTransformRelationFailure;
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

        if ($hasBlockingTransformRelationFailure) {
            $this->writeTransformBlockMarker($storageDir, $report);
        }

        if ($this->live && $hasBlockingTransformRelationFailure) {
            $this->recordBlockingTransformFailure($report);
            $this->writeReport($storageDir, $report, $filters, $tRunStart);
            return $this->reportExitCode($report);
        }

        // FH-03: --preload-assets ingests the full referenced asset set in one batch
        // before the per-entry loop. Default JIT materialises inside migrateOneEntry().
        // Hoisted above the load step so the SEO + Retour bolt-ons (D-55) downstream
        // can re-use the same MigrationOptions instance without redeclaring.
        // Step 4.5 (Phase 10): default taxonomy mode is page-rooted and
        // referenced-only. The full pre-load migrateAll() import runs only when
        // operators explicitly opt in via CLI/settings; the standalone
        // migrate/taxonomies sub-action remains the explicit full import path.
        $settings = $plugin->getSettings();
        $includeUnreferencedTaxonomies = $this->includeUnreferencedTaxonomies
            || (bool) $settings->includeUnreferencedTaxonomies;
        $taxonomyMode = $includeUnreferencedTaxonomies ? 'full' : 'referenced-only';
        $report->warn('taxonomyMode=' . $taxonomyMode);
        $this->stdout("  Taxonomy mode: {$taxonomyMode}\n", Console::FG_CYAN);
        if ($includeUnreferencedTaxonomies) {
            try {
                $plugin->taxonomyMigrationService->filters = $filters;
                $taxonomyReport = $plugin->taxonomyMigrationService->migrateAll($opts);
            } catch (Throwable $e) {
                $this->stderr("  FAIL taxonomies: {$e->getMessage()}\n", Console::FG_RED);
                return ExitCode::UNSPECIFIED_ERROR;
            }
            $this->mergeReport($report, $taxonomyReport, 'taxonomies');
            $this->stdout(sprintf(
                "  Stage taxonomies: created=%d updated=%d skipped=%d failed=%d\n",
                (int) ($taxonomyReport->counts['created'] ?? 0),
                (int) ($taxonomyReport->counts['updated'] ?? 0),
                (int) ($taxonomyReport->counts['skipped'] ?? 0),
                (int) ($taxonomyReport->counts['failed']  ?? 0),
            ), Console::FG_GREEN);
        }

        // Step 5: load — per-entry atomic write (or dry-run print).
        if (!$this->live) {
            $this->stdout(
                "  WARN load skipped (dry-run; pass --live to write entries)\n",
                Console::FG_YELLOW,
            );
        } else {
            if ($this->preloadAssets) {
                try {
                    $referencedAssetIds = $this->collectReferencedAssetIdsFromPayloadDirectory($transformedDir, $filters);
                    $plugin->assetMigrationService->ingestReferenced($opts, $filters, $referencedAssetIds);
                    $this->stdout("  OK   --preload-assets batch complete\n", Console::FG_GREEN);
                } catch (Throwable $e) {
                    $this->stderr("  FAIL --preload-assets: {$e->getMessage()}\n", Console::FG_RED);
                    return ExitCode::UNSPECIFIED_ERROR;
                }
            }
            $loadExit = $this->runLoadFromDisk($transformedDir, $opts, $report, $filters);
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
                $this->recordFinalizeUnresolvedGate($report, $finalizeCounts);
            } catch (Throwable $e) {
                $this->endProgressIfStarted();
                $this->stderr(sprintf(
                    "  FAIL finalize: %s %s @ %s:%d\n",
                    $e::class,
                    $e->getMessage() !== '' ? $e->getMessage() : '(no message)',
                    $e->getFile(),
                    $e->getLine(),
                ), Console::FG_RED);
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

        return $this->reportExitCode($report);
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
        $filters = $this->buildRuntimeFilters($plugin, false, $this->noRetour);

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
        $filters = $this->buildRuntimeFilters($plugin, $this->noSeo, false);

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
     * Sub-action: migrate Doctrine standalone taxonomy/classifier entities into Craft entries.
     *
     * Phase 8 / D-03 / TAX-08: standalone resume / debug entry point. The
     * actionIndex bolt-on already runs taxonomies BEFORE pages on every full
     * pipeline; this sub-action lets operators re-run only the taxonomies
     * stage after a partial migrate (e.g. after fixing a mapping row).
     *
     * Gating mirrors actionSeo / actionRetour:
     *   - enforceNeverProduction() FIRST (D-20).
     *   - Default dry-run; --live writes to Craft.
     *   - --no-seo / --no-retour are inert here (passed through to filters
     *     for shape parity but never consulted by TaxonomyMigrationService).
     *
     * D-04 / D-12 invariant: NO opt-out flag for the taxonomies stage —
     * the three-flag cap (--live / --confirm / --force) is preserved.
     */
    public function actionTaxonomies(): int
    {
        if (($gate = $this->enforceNeverProduction()) !== null) {
            return $gate;
        }
        // D-67: per-run timestamped log file for resume / debug invocations.
        $this->openLogFile($this->defaultLogPath());
        $this->logLine('actionTaxonomies started; verbosity=' . $this->verbosityLevel(), 1);
        $this->stdout(
            "Migrate (taxonomies): Doctrine standalone taxonomies → Craft entries\n",
            Console::FG_CYAN,
        );

        $plugin = Plugin::getInstance();
        // Phase 4.1 / D-26: pass --no-seo / --no-retour through unchanged for
        // filter shape parity. TaxonomyMigrationService never reads them.
        $filters = $this->buildRuntimeFilters($plugin, $this->noSeo, $this->noRetour);

        if (!$this->live) {
            $this->stdout(
                "  WARN taxonomies skipped (dry-run; pass --live to write entries)\n",
                Console::FG_YELLOW,
            );
            return ExitCode::OK;
        }

        $opts = new MigrationOptions(dryRun: false, force: $this->force, skipAssets: false);
        $report = new MigrationReport();

        try {
            $plugin->taxonomyMigrationService->filters = $filters;
            $taxonomyReport = $plugin->taxonomyMigrationService->migrateAll($opts);
        } catch (Throwable $e) {
            $this->stderr("  FAIL taxonomies: {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $this->mergeReport($report, $taxonomyReport, 'taxonomies');
        $this->stdout(sprintf(
            "  Stage taxonomies: created=%d updated=%d skipped=%d failed=%d\n",
            (int) ($taxonomyReport->counts['created'] ?? 0),
            (int) ($taxonomyReport->counts['updated'] ?? 0),
            (int) ($taxonomyReport->counts['skipped'] ?? 0),
            (int) ($taxonomyReport->counts['failed']  ?? 0),
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
        $filters = $this->buildRuntimeFilters($plugin, $this->noSeo, $this->noRetour);
        $this->applyNoRelJoinOverride($plugin);

        if (($exit = $this->preflightLocale($filters)) !== ExitCode::OK) {
            return $exit;
        }
        $mapping = $this->loadMappingOrFail();
        if ($mapping === null) {
            return ExitCode::CONFIG;
        }

        $extractProgress = $this->makeExtractProgress();
        try {
            $extractReport = $plugin->extractService->run($mapping, $filters, $this->buildExtractOptions(), $extractProgress);
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
        $filters = $this->buildRuntimeFilters($plugin, $this->noSeo, $this->noRetour);
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
        $report = new MigrationReport();
        $hasBlockingTransformRelationFailure = false;
        $this->clearTransformBlockMarker($storageDir);
        // For standalone transform we don't have a fresh extract precount handy; count
        // extracted/<fqcn>/*.json files on disk as the denominator. Cheap glob.
        $transformProgress = $this->makeTransformProgress($this->countExtractedFiles($storageDir));
        try {
            $extractedStream = $this->streamExtracted($storageDir);
            foreach ($plugin->transformService->run($extractedStream, $mapping, $filters, $this->buildTransformOptions($report), $transformProgress) as $payload) {
                if (isset($payload['__report'])) {
                    $hasBlockingTransformRelationFailure =
                        $this->mergeTransformReportSentinel($payload, $report)
                        || $hasBlockingTransformRelationFailure;
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

        if ($hasBlockingTransformRelationFailure) {
            $this->writeTransformBlockMarker($storageDir, $report);
        }

        if ($this->live && $hasBlockingTransformRelationFailure) {
            $this->recordBlockingTransformFailure($report);
            $this->writeReport($storageDir, $report, $filters);
            return $this->reportExitCode($report);
        }
        if ($report->warnings !== [] || $report->hasFailures()) {
            $this->writeReport($storageDir, $report, $filters);
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
        $filters = $this->buildRuntimeFilters($plugin, $this->noSeo, $this->noRetour);
        $storageDir = Craft::$app->path->getStoragePath() . '/migration';
        $transformedDir = $storageDir . '/transformed/entries';
        $report = new MigrationReport();

        if (($exit = $this->preflightLocale($filters)) !== ExitCode::OK) {
            return $exit;
        }

        $mapping = $this->loadMappingOrFail();
        if ($mapping === null) {
            return ExitCode::CONFIG;
        }
        $compiledPreflight = $this->preflightCompiledMapping($mapping, $this->migrateTargetSchema($plugin));
        if ($compiledPreflight['missing'] !== [] || $compiledPreflight['fatal'] !== []) {
            $this->stderr(
                "  FAIL compiled mapping preflight blocked migrate --live\n",
                Console::FG_RED,
            );
            foreach ($compiledPreflight['messages'] as $message) {
                $this->stderr("  - {$message}\n", Console::FG_RED);
            }
            return ExitCode::CONFIG;
        }

        if (!is_dir($transformedDir)) {
            $this->stderr(
                "  FAIL transformed/entries dir missing — run `migrate/transform` first ({$transformedDir})\n",
                Console::FG_RED,
            );
            return ExitCode::CONFIG;
        }

        $transformBlockMarker = $this->readTransformBlockMarker($storageDir);
        if ($transformBlockMarker !== null) {
            $message = $this->transformBlockMarkerLoadFailureMessage($transformBlockMarker);
            if ($this->live) {
                $report->warn($message);
                $report->recordFailure(
                    'transform',
                    'transform',
                    'TransformService',
                    new \RuntimeException($message),
                );
                $this->stderr("  FAIL {$message}\n", Console::FG_RED);
                $this->writeReport($storageDir, $report, $filters);
                return $this->reportExitCode($report);
            }
            $this->stdout("  WARN {$message}\n", Console::FG_YELLOW);
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
                $referencedAssetIds = $this->collectReferencedAssetIdsFromPayloadDirectory($transformedDir, $filters);
                $plugin->assetMigrationService->ingestReferenced($opts, $filters, $referencedAssetIds);
                $this->stdout("  OK   --preload-assets batch complete\n", Console::FG_GREEN);
            } catch (Throwable $e) {
                $this->stderr("  FAIL --preload-assets: {$e->getMessage()}\n", Console::FG_RED);
                return ExitCode::UNSPECIFIED_ERROR;
            }
        }

        $exit = $this->runLoadFromDisk($transformedDir, $opts, $report, $filters);
        $this->writeReport($storageDir, $report, $filters);
        if ($exit !== ExitCode::OK) {
            return $exit;
        }
        return $this->reportExitCode($report);
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
        $filters = $this->buildRuntimeFilters($plugin, $this->noSeo, $this->noRetour);

        if (!$this->live) {
            $this->stdout(
                "  WARN finalize skipped (dry-run; pass --live to rewrite CKEditor fields)\n",
                Console::FG_YELLOW,
            );
            return ExitCode::OK;
        }

        $report = new MigrationReport();
        $storageDir = Craft::$app->path->getStoragePath() . '/migration';
        $finalizeProgress = $this->makeFinalizeProgress();
        try {
            $counts = $plugin->finalizeWalker->walk($filters, $finalizeProgress);
        } catch (Throwable $e) {
            $this->endProgressIfStarted();
            $this->stderr(sprintf(
                "  FAIL finalize: %s %s @ %s:%d\n",
                $e::class,
                $e->getMessage() !== '' ? $e->getMessage() : '(no message)',
                $e->getFile(),
                $e->getLine(),
            ), Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $this->endProgressIfStarted();
        $this->stdout(sprintf(
            "  OK   finalize complete (processed=%d rewritten=%d unresolvable=%d)\n",
            (int) $counts['processed'],
            (int) $counts['rewritten'],
            (int) $counts['unresolvable'],
        ), Console::FG_GREEN);
        $report->incr('finalize.processed', (int) $counts['processed']);
        $report->incr('finalize.rewritten', (int) $counts['rewritten']);
        $report->incr('finalize.unresolvable', (int) $counts['unresolvable']);
        $this->recordFinalizeUnresolvedGate($report, $counts);
        if ($report->warnings !== [] || $report->hasFailures() || $report->finalizeUnresolvedDiagnostics !== []) {
            $this->writeReport($storageDir, $report, $filters);
        }

        return $this->reportExitCode($report);
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
        $filters = $this->buildRuntimeFilters($plugin, $this->noSeo, $this->noRetour);

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
        $filters = $this->buildRuntimeFilters($plugin, $this->noSeo, $this->noRetour);
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
     * Phase 8.5 / D-24 — apply `--no-rel-join` per-run override on the
     * extract service. The flag only DISABLES (mirrors `--no-seo`); it never
     * enables. So when `Settings::joinFkRelations` is false (the source-
     * faithful default), this method is a no-op even with the flag set.
     *
     * Plugin::init() seeds `extractService->joinFkRelations` from Settings;
     * we only need to override here when the operator opts out per-run.
     */
    private function applyNoRelJoinOverride(Plugin $plugin): void
    {
        if ($this->noRelJoin) {
            $plugin->extractService->joinFkRelations = false;
            $this->stdout(
                "  OK   --no-rel-join → ManyToOne FK join disabled for this run\n",
                Console::FG_YELLOW,
            );
        }
    }

    private function buildRuntimeFilters(Plugin $plugin, bool $noSeo, bool $noRetour): MigrationFilters
    {
        return $plugin->filterFactory->fromCli(
            entitiesArg: $this->entities,
            localesArg: $this->locales,
            sinceArg: $this->since,
            noSeo: $noSeo,
            noRetour: $noRetour,
            relationGraph: $this->loadRuntimeRelationGraph(),
        );
    }

    /**
     * Load the analyzer's relation-graph artifact for all runtime actions.
     *
     * Missing artifact is non-fatal: unscoped runs and first-run dry paths keep
     * working, while scoped runs still have exact FQCN/basename filtering.
     *
     * @return array<string, list<string>>
     */
    private function loadRuntimeRelationGraph(): array
    {
        $path = Craft::$app->path->getStoragePath() . '/migration/relation-graph.json';
        if (!is_file($path)) {
            return [];
        }

        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded)
            ? FilterFactory::relationGraphFromArtifact($decoded)
            : [];
    }

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
     * Phase 9 / D-10 + D-11: migrate must not silently run against a mapping
     * that has accepted operator decisions but lacks the compiled runtime blocks
     * generated by `kunstmaan-migrator/compile`.
     *
     * Required base blocks are always structural runtime prerequisites. Optional
     * compiled blocks are required only when accepted proposal rows imply that
     * runtime surface will be used.
     *
     * @param array<string, mixed> $mapping
     * @return array{missing: list<string>, fatal: list<string>, messages: list<string>}
     */
    private function preflightCompiledMapping(array $mapping, ?array $targetSchema = null): array
    {
        $missing = [];
        foreach (['nodeClasses', 'sections', 'sites'] as $block) {
            if (!$this->mappingBlockPresent($mapping, $block)) {
                $missing[] = $block;
            }
        }

        $acceptedKinds = [];
        foreach ((array) ($mapping['proposals'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            if ((string) ($row['status'] ?? '') !== 'accepted') {
                continue;
            }
            $acceptedKinds[(string) ($row['kind'] ?? 'column')] = true;
        }

        $conditionalBlocks = [
            'pagePart' => 'pageParts',
            'taxonomy' => 'taxonomies',
            'dataProvider' => 'dataProviders',
        ];
        foreach ($conditionalBlocks as $kind => $block) {
            if (isset($acceptedKinds[$kind]) && !$this->mappingBlockPresent($mapping, $block)) {
                $missing[] = $block;
            }
        }

        $fatal = [];
        if ($targetSchema !== null && $this->mappingBlockPresent($mapping, 'sections')) {
            $validation = (new \lameco\kunstmaanmigrator\compile\CraftTargetIntrospector())
                ->validateWithSeverity($mapping, $targetSchema);
            $fatal = $validation['fatal'];
        }

        if ($missing === [] && $fatal === []) {
            return ['missing' => [], 'fatal' => [], 'messages' => []];
        }

        $messages = [];
        if ($missing !== []) {
            $messages[] = 'Run `./craft kunstmaan-migrator/compile` after analyze/map so mapping.yaml contains compiled runtime blocks.';
            $messages[] = 'Missing compiled block(s): ' . implode(', ', $missing);
        }
        foreach ($fatal as $message) {
            $messages[] = 'Load-fatal target validation: ' . $message;
        }

        return [
            'missing' => $missing,
            'fatal' => $fatal,
            'messages' => $messages,
        ];
    }

    /**
     * @param array<string, mixed> $mapping
     */
    private function mappingBlockPresent(array $mapping, string $block): bool
    {
        return isset($mapping[$block])
            && is_array($mapping[$block])
            && $mapping[$block] !== [];
    }

    /**
     * Build the schema facade consumed by CraftTargetIntrospector during live
     * migrate preflight. This mirrors CompileController's target schema but is
     * local to migrate so stale hand-edited compiled mappings are blocked even
     * when operators skip a fresh compile.
     *
     * @return array<string, mixed>
     */
    private function migrateTargetSchema(Plugin $plugin): array
    {
        $sections = [];
        foreach ($plugin->craftKnowledgeBase->sectionToEntryTypes() as $handle => $entryTypes) {
            $sections[(string) $handle] = ['entryTypes' => $entryTypes];
        }

        $entryTypes = [];
        foreach ($plugin->craftKnowledgeBase->buildFieldIndex() as $entryType => $fields) {
            $fieldMap = [];
            foreach ((array) $fields as $field) {
                if (!is_array($field)) { continue; }
                $handle = (string) ($field['handle'] ?? '');
                if ($handle === '' || str_contains($handle, '.')) { continue; }
                $fieldMap[$handle] = ['type' => strtolower((string) ($field['classification'] ?? $field['type'] ?? 'plain'))];
                if (isset($field['blocks']) && is_array($field['blocks'])) {
                    $fieldMap[$handle]['blocks'] = $field['blocks'];
                }
            }
            $entryTypes[(string) $entryType] = ['fields' => $fieldMap];
        }

        $volumes = [];
        try {
            foreach (Craft::$app->volumes->getAllVolumes() as $volume) {
                $handle = (string) $volume->handle;
                if ($handle !== '') { $volumes[] = $handle; }
            }
        } catch (Throwable) {
            $volumes = [];
        }

        return [
            'sections' => $sections,
            'entryTypes' => $entryTypes,
            'volumes' => array_values(array_unique($volumes)),
            'plugins' => [
                'seomatic' => Craft::$app->plugins->getPlugin('seomatic') !== null,
                'retour' => Craft::$app->plugins->getPlugin('retour') !== null,
            ],
        ];
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
     * Phase 9 / D-20: collect the referenced kuma_media ids from the current
     * in-scope transformed payload tree. This keeps --preload-assets aligned
     * with the page-rooted load set instead of walking the full legacy media
     * table. CKEditor [M123] tokens and deferred asset:123 tokens are both
     * preserved by transform/load and are safe preload inputs.
     *
     * @return list<int>
     */
    private function collectReferencedAssetIdsFromPayloadDirectory(string $transformedDir, ?MigrationFilters $filters = null): array
    {
        $ids = [];
        foreach ($this->iterateTransformedFiles($transformedDir) as $jsonPath) {
            if ($filters !== null && !$this->payloadFileMatchesFilters($jsonPath, $filters)) {
                continue;
            }
            $raw = file_get_contents($jsonPath);
            if ($raw === false) {
                continue;
            }
            $payload = json_decode($raw, true);
            if (!is_array($payload)) {
                continue;
            }
            foreach (self::collectReferencedAssetIdsFromPayload($payload) as $id) {
                $ids[$id] = true;
            }
        }

        $out = array_keys($ids);
        sort($out, SORT_NUMERIC);
        return array_map('intval', $out);
    }

    private function payloadFileMatchesFilters(string $jsonPath, MigrationFilters $filters): bool
    {
        if ($filters->entities === []) {
            return true;
        }
        if ($this->isPromotedTargetPayloadFile($jsonPath)) {
            return true;
        }

        $fqcn = str_replace('_', '\\', basename(dirname($jsonPath)));
        return $filters->allows($fqcn);
    }

    private function isPromotedTargetPayloadFile(string $jsonPath): bool
    {
        $raw = file_get_contents($jsonPath);
        if ($raw === false) {
            return false;
        }
        $payload = json_decode($raw, true);
        return is_array($payload)
            && (
                ($payload['kind'] ?? '') === 'promotedTarget'
                || (bool) ($payload['promotedTarget'] ?? false)
            )
            && (string) ($payload['stateSource'] ?? '') !== '';
    }

    /**
     * Pure recursive collector for in-payload media references.
     *
     * @param array<string, mixed> $payload
     * @return list<int>
     */
    private static function collectReferencedAssetIdsFromPayload(array $payload): array
    {
        $ids = [];
        $explicitKeys = [
            'referencedAssetIds' => true,
            'referencedMediaIds' => true,
            'assetIds' => true,
            'mediaIds' => true,
        ];

        $walk = static function (mixed $value, bool $allowBareIds = false) use (&$walk, &$ids, $explicitKeys): void {
            if (is_int($value) && $allowBareIds && $value > 0) {
                $ids[$value] = true;
                return;
            }
            if (is_string($value)) {
                if ($allowBareIds && ctype_digit($value) && (int) $value > 0) {
                    $ids[(int) $value] = true;
                }
                if (preg_match_all('/\basset:(\d+)\b/', $value, $assetMatches)) {
                    foreach ($assetMatches[1] as $id) {
                        $ids[(int) $id] = true;
                    }
                }
                if (preg_match_all('/\[M(\d+)\]/', $value, $mediaMatches)) {
                    foreach ($mediaMatches[1] as $id) {
                        $ids[(int) $id] = true;
                    }
                }
                return;
            }
            if (!is_array($value)) {
                return;
            }
            foreach ($value as $key => $child) {
                $walk($child, $allowBareIds || (is_string($key) && isset($explicitKeys[$key])));
            }
        };

        $walk($payload);
        $out = array_keys($ids);
        sort($out, SORT_NUMERIC);
        return array_map('intval', $out);
    }

    /**
     * Per-entry load loop with ETL-06 progress emission.
     *
     * Emits `[N/total] <slug> → created|updated|skipped` to stdout (FG_GREEN) on
     * success, and `[N/total] <slug> → FAILED: <reason>` to stderr (FG_RED) on
     * failure (recorded via $report->recordFailure with D-50 5-frame trace).
     */
    private function runLoadFromDisk(
        string $transformedDir,
        MigrationOptions $opts,
        MigrationReport $report,
        ?MigrationFilters $filters = null,
    ): int {
        $plugin = Plugin::getInstance();

        // Pre-walk to compute total — operators want [N/total] not just [N].
        // Phase 8.6 — when --entities is set, filter files at the load site so
        // stale `transformed/entries/` payloads from previous runs don't leak
        // through. Transform's `--entities` filter prevents NEW writes, but
        // load was reading every file on disk regardless. Match by FQCN slug
        // (path is `transformed/entries/<fqcnSlug>/<nodeId>.json`).
        $files = [];
        foreach ($this->iterateTransformedFiles($transformedDir) as $f) {
            if ($filters !== null && !$this->payloadFileMatchesFilters($f, $filters)) {
                continue;
            }
            $files[] = $f;
        }
        usort($files, fn(string $a, string $b): int => [
            $this->isPromotedTargetPayloadFile($a) ? 0 : 1,
            $a,
        ] <=> [
            $this->isPromotedTargetPayloadFile($b) ? 0 : 1,
            $b,
        ]);
        $total = count($files);
        $this->lastTotal = $total;
        $offset = max(0, (int) ($this->batchOffset ?? 0));
        if ($this->batchLimit !== null && $this->batchLimit > 0) {
            $files = array_slice($files, $offset, $this->batchLimit);
            $this->lastNextBatchOffset = ($offset + count($files)) < $total
                ? $offset + count($files)
                : null;
        } else {
            $this->lastNextBatchOffset = null;
        }
        $this->lastProcessed = count($files);
        if ($total === 0 || $files === []) {
            $this->stdout("  WARN no transformed payloads to load for this batchOffset/batchLimit\n", Console::FG_YELLOW);
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
     * Phase 9 / D-18: final command status must reflect the report after all
     * diagnostic continuation and REPORT.md writing has happened. Per-entry
     * failures still continue through runLoadFromDisk(); this method only
     * translates the central report state into truthful process status.
     */
    private function reportExitCode(MigrationReport $report): int
    {
        if ($report->hasFailures()) {
            $this->stdout(
                sprintf("\nMigrate: FAIL (%d failures)\n", $report->failureCount()),
                Console::FG_RED,
            );
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("\nMigrate: PASS\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    /**
     * Merge TransformService's local sentinel report into the main MigrationReport.
     *
     * Decision: dry-run keeps relation/taxonomy handler failures as visible
     * warnings so operators can inspect the blast radius without writes. Live
     * mode treats relation/taxonomy handler failures as blocking before load,
     * because preserving page-owned relation fidelity is more important than
     * saving entries with omitted relation fields.
     *
     * @param array<string, mixed> $payload
     */
    private function mergeTransformReportSentinel(array $payload, MigrationReport $report): bool
    {
        $hasBlockingRelationOrTaxonomyFailure = false;
        $warnings = (array) ($payload['__report']['warnings'] ?? []);
        foreach ($warnings as $warning) {
            $mergedWarning = 'Transform: ' . (string) $warning;
            $report->warn($mergedWarning);
            $report->incr('transform.warning');
            if ($this->isBlockingTransformRelationWarning($mergedWarning)) {
                $hasBlockingRelationOrTaxonomyFailure = true;
            }
        }
        return $hasBlockingRelationOrTaxonomyFailure;
    }

    private function isBlockingTransformRelationWarning(string $warning): bool
    {
        return str_contains($warning, "Handler 'relation' failed")
            || str_contains($warning, "Handler 'taxonomy' failed")
            || str_contains($warning, 'TaxonomyMigrationService')
            || (str_contains($warning, 'Handler ') && stripos($warning, 'taxonomy') !== false);
    }

    private function recordBlockingTransformFailure(MigrationReport $report): void
    {
        $report->recordFailure(
            'transform',
            'transform',
            'TransformService',
            new \RuntimeException(
                'TransformService relation/taxonomy handler failure blocked live load; inspect Transform: warnings in REPORT.md.',
            ),
        );
    }

    /** @param array<string, mixed> $finalizeCounts */
    private function recordFinalizeUnresolvedGate(MigrationReport $report, array $finalizeCounts): void
    {
        $count = (int) ($finalizeCounts['unresolvable'] ?? 0);
        foreach ((array) ($finalizeCounts['unresolvedDiagnostics'] ?? []) as $row) {
            if (is_array($row)) {
                $report->finalizeUnresolvedDiagnostics[] = $row;
            }
        }
        $outOfScopeCount = count((array) ($finalizeCounts['outOfScopeDiagnostics'] ?? []));
        if ($outOfScopeCount > 0) {
            $report->warn(
                'finalize classified ' . $outOfScopeCount
                . ' CKEditor references outside live page-rooted scope; release gate did not block on these rows.',
            );
        }
        if ($count <= 0) {
            return;
        }
        $message = 'FAIL finalize unresolved: finalize.unresolvable=' . $count
            . ' live finalize left unresolved CKEditor references; release is blocked until resolved or explicitly classified outside Page-rooted release scope.';
        $report->warn($message);
        $report->recordFailure('finalize', 'finalize', 'FinalizeWalker', new \RuntimeException($message));
        $this->stderr("  {$message}\n", Console::FG_RED);
    }

    private function transformBlockMarkerPath(string $storageDir): string
    {
        return rtrim($storageDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . self::TRANSFORM_BLOCK_MARKER;
    }

    private function clearTransformBlockMarker(string $storageDir): void
    {
        $path = $this->transformBlockMarkerPath($storageDir);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function clearFullPipelineArtifacts(string $storageDir): void
    {
        foreach ([$storageDir . '/extracted', $storageDir . '/transformed/entries'] as $path) {
            if (is_dir($path)) {
                FileHelper::removeDirectory($path);
            }
        }
    }

    private function writeTransformBlockMarker(string $storageDir, MigrationReport $report): void
    {
        $path = $this->transformBlockMarkerPath($storageDir);
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            $report->warn("Could not create transform block marker directory: {$dir}");
            return;
        }

        $payload = [
            'blocked' => true,
            'createdAt' => gmdate('c'),
            'reason' => 'TransformService relation/taxonomy handler failure blocked live load.',
            'warnings' => array_values(array_filter(
                $report->warnings,
                fn (string $warning): bool => $this->isBlockingTransformRelationWarning($warning),
            )),
        ];
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false || file_put_contents($path, $json . "\n") === false) {
            $report->warn("Could not write transform block marker: {$path}");
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readTransformBlockMarker(string $storageDir): ?array
    {
        $path = $this->transformBlockMarkerPath($storageDir);
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return ['path' => $path];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded)
            ? ['path' => $path] + $decoded
            : ['path' => $path];
    }

    /**
     * @param array<string, mixed> $marker
     */
    private function transformBlockMarkerLoadFailureMessage(array $marker): string
    {
        $path = (string) ($marker['path'] ?? self::TRANSFORM_BLOCK_MARKER);
        return sprintf(
            'prior transform relation/taxonomy failure marker blocks live load (%s); re-run migrate/transform after fixing transform warnings.',
            $path,
        );
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
        $buckets = ['created', 'updated', 'skipped', 'failed', 'finalize.processed', 'finalize.rewritten', 'finalize.unresolvable', 'relation.unresolved', 'relation.intent.drop', 'relation.intent.out_of_scope', 'relation.promoted'];
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

        // 3b. Validation-required fallbacks (Phase 10): successful Matrix
        // native-title and sparse-locale primary-save fallbacks must be visible
        // to operators without inflating entry/stage failure counts.
        foreach (self::renderFallbacksSection($report) as $fl) {
            $lines[] = $fl;
        }

        foreach (self::renderFinalizeUnresolvedDiagnosticsSection($report->finalizeUnresolvedDiagnostics) as $dl) {
            $lines[] = $dl;
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

    /** @param list<array<string, mixed>> $diagnostics @return list<string> */
    public static function renderFinalizeUnresolvedDiagnosticsSection(array $diagnostics): array
    {
        if ($diagnostics === []) {
            return [];
        }
        $out = [];
        $out[] = '## Finalize unresolved diagnostics';
        $out[] = '';
        $out[] = '| token_family | legacy_id | token | site_id | entry_id | field | reason |';
        $out[] = '|--------------|----------:|-------|--------:|---------:|-------|--------|';
        foreach (array_slice($diagnostics, 0, 100) as $row) {
            $out[] = sprintf(
                '| %s | %d | `%s` | %d | %d | `%s` | %s |',
                self::reportCell((string) ($row['tokenFamily'] ?? '')),
                (int) ($row['legacyId'] ?? 0),
                self::reportCell((string) ($row['token'] ?? '')),
                (int) ($row['siteId'] ?? 0),
                (int) ($row['entryId'] ?? 0),
                self::reportCell((string) ($row['fieldHandle'] ?? '')),
                self::reportCell((string) ($row['reason'] ?? '')),
            );
        }
        if (count($diagnostics) > 100) {
            $out[] = '';
            $out[] = sprintf('_Showing 100 of %d diagnostics._', count($diagnostics));
        }
        $out[] = '';
        return $out;
    }

    private static function reportCell(string $value): string
    {
        return str_replace('|', '\\|', $value);
    }

    public static function renderFallbacksSection(MigrationReport $report): array
    {
        $fallbackCounts = [];
        foreach ($report->counts as $bucket => $count) {
            if (str_starts_with((string) $bucket, 'fallback.')) {
                $fallbackCounts[(string) $bucket] = (int) $count;
            }
        }

        $fallbackWarnings = [];
        foreach ($report->warnings as $warning) {
            if (str_contains($warning, 'fallback:')
                || str_contains($warning, ' fallback')
                || str_contains($warning, 'Fallback')
            ) {
                $fallbackWarnings[] = $warning;
            }
        }

        if ($fallbackCounts === [] && $fallbackWarnings === []) {
            return [];
        }

        $out = [];
        $out[] = '## Fallbacks';
        $out[] = '';
        $out[] = '| Category | Count |';
        $out[] = '|----------|------:|';
        foreach ($fallbackCounts as $bucket => $count) {
            $out[] = sprintf('| %s | %d |', substr($bucket, strlen('fallback.')), $count);
        }
        if ($fallbackCounts === []) {
            $out[] = '| (warning-only) | 0 |';
        }
        $out[] = '';

        if ($fallbackWarnings !== []) {
            $out[] = '### Fallback details';
            $out[] = '';
            foreach ($fallbackWarnings as $warning) {
                $out[] = '- ' . $warning;
            }
            $out[] = '';
        }

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
     * Phase 7 — build the options array passed to ExtractService::run() from the
     * controller's --limit / --only-id flags. Returns [] when neither is set so
     * the default no-scoping behavior is preserved.
     *
     * --only-id is only meaningful in combination with --entities (an unscoped
     * id lookup against multiple node-class tables is ambiguous on conflicting
     * ids). When both --limit and --only-id are set, --only-id wins (limit is
     * implicitly 1).
     *
     * @return array<string, mixed>
     */
    private function buildExtractOptions(): array
    {
        $opts = [];
        if ($this->onlyId !== null) {
            $opts['onlyId'] = (int) $this->onlyId;
        } elseif ($this->limit !== null) {
            $opts['limit'] = (int) $this->limit;
        }
        return $opts;
    }

    /**
     * Phase 7 — same shape as buildExtractOptions but for TransformService.
     * Transform consumes the on-disk extracted/ tree, so --limit has the
     * effect of capping the number of payloads emitted.
     *
     * @return array<string, mixed>
     */
    private function buildTransformOptions(?MigrationReport $report = null): array
    {
        $opts = [
            'dryRun' => !$this->live,
        ];
        if ($report !== null) {
            $opts['migrationReport'] = $report;
        }
        if ($this->limit !== null) {
            $opts['limit'] = (int) $this->limit;
        }
        return $opts;
    }

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

    private function migrateMissingPageDependencies(
        string $storageDir,
        array $mapping,
        MigrationFilters $parentFilters,
        MigrationOptions $opts,
        MigrationReport $report,
    ): int {
        $attempted = [];
        $loaded = 0;

        for ($pass = 1; $pass <= 5; $pass++) {
            $dependencies = $this->collectMissingPageRelationDependencies($storageDir, $mapping, $attempted, $report);
            if ($dependencies === []) {
                if ($loaded > 0) {
                    $this->stdout("  OK   dependency pre-pass migrated {$loaded} referenced page entries\n", Console::FG_GREEN);
                }
                return ExitCode::OK;
            }

            $count = array_sum(array_map('count', $dependencies));
            $this->stdout("  ... dependency pre-pass {$pass}: migrating {$count} referenced page entries\n", Console::FG_YELLOW);

            foreach ($dependencies as $fqcn => $nodeIds) {
                foreach (array_keys($nodeIds) as $nodeId) {
                    $attempted[$fqcn . ':' . (int) $nodeId] = true;
                    try {
                        Plugin::getInstance()->extractService->run(
                            $mapping,
                            new MigrationFilters(
                                entities: [$fqcn],
                                locales: $parentFilters->locales,
                                since: null,
                                noSeo: $parentFilters->noSeo,
                                noRetour: $parentFilters->noRetour,
                                relationGraph: $parentFilters->relationGraph,
                            ),
                            [
                                'onlyNodeClass' => $fqcn,
                                'onlyId' => (int) $nodeId,
                            ],
                        );
                    } catch (Throwable $e) {
                        $this->stderr("  FAIL dependency extract {$this->shortFqcn($fqcn)}#{$nodeId}: {$e->getMessage()}\n", Console::FG_RED);
                        return ExitCode::UNSPECIFIED_ERROR;
                    }
                }
            }

            $transformExit = $this->transformDependencyPayloads($storageDir, $mapping, $parentFilters, $dependencies, $report);
            if ($transformExit !== ExitCode::OK) {
                return $transformExit;
            }

            $dependencyFilters = new MigrationFilters(
                entities: array_keys($dependencies),
                locales: $parentFilters->locales,
                since: null,
                noSeo: $parentFilters->noSeo,
                noRetour: $parentFilters->noRetour,
                relationGraph: $parentFilters->relationGraph,
            );
            $loadExit = $this->runLoadFromDisk($storageDir . '/transformed/entries', $opts, $report, $dependencyFilters);
            if ($loadExit !== ExitCode::OK) {
                return $loadExit;
            }
            $loaded += $count;
        }

        $report->warn('Dependency pre-pass stopped after 5 passes; possible relation cycle or unresolved dependency.');
        return ExitCode::OK;
    }

    /**
     * @param array<string, true> $attempted
     * @return array<string, array<int, true>> FQCN => nodeId set
     */
    private function collectMissingPageRelationDependencies(
        string $storageDir,
        array $mapping,
        array $attempted,
        MigrationReport $report,
    ): array {
        $nodeClasses = (array) ($mapping['nodeClasses'] ?? []);
        $stateSourceToFqcn = [];
        foreach (array_keys($nodeClasses) as $fqcn) {
            if (is_string($fqcn)) {
                $stateSourceToFqcn[$this->slugify($fqcn)] = $fqcn;
                $stateSourceToFqcn[$fqcn] = $fqcn;
            }
        }

        $dependencies = [];
        foreach ($this->streamExtracted($storageDir) as $payload) {
            $ownerFqcn = (string) ($payload['fqcn'] ?? '');
            if ($ownerFqcn === '') {
                continue;
            }
            $nodeSpec = $nodeClasses[$ownerFqcn] ?? null;
            if (!is_array($nodeSpec)) {
                continue;
            }

            foreach ((array) ($nodeSpec['fields'] ?? []) as $fieldSpec) {
                if (!is_array($fieldSpec) || (string) ($fieldSpec['handler'] ?? '') !== 'relation') {
                    continue;
                }
                $options = (array) ($fieldSpec['handlerOptions'] ?? []);
                $stateSource = (string) ($options['stateSource'] ?? '');
                $targetFqcn = $stateSourceToFqcn[$stateSource] ?? null;
                if ($targetFqcn === null || (($options['stateKeyPrefix'] ?? '') !== '')) {
                    continue;
                }

                $sourceColumn = (string) ($fieldSpec['source'] ?? '');
                if ($sourceColumn === '') {
                    continue;
                }

                foreach ((array) ($payload['perSite'] ?? []) as $siteData) {
                    $detail = (array) ($siteData['detail'] ?? []);
                    $legacyValue = $detail[$sourceColumn] ?? null;
                    foreach ($this->dependencyStateKeysForRelationValue($legacyValue, $options, $report) as $stateKey) {
                        if (Plugin::getInstance()->migrationStateService->getTargetId($stateSource, (string) $stateKey) !== null) {
                            continue;
                        }
                        $nodeId = $this->nodeIdForPageRef($targetFqcn, $stateKey);
                        if ($nodeId === null) {
                            $report->warn("Could not locate dependency page node for {$targetFqcn} ref {$stateKey}");
                            continue;
                        }
                        if (isset($attempted[$targetFqcn . ':' . $nodeId])) {
                            continue;
                        }
                        $dependencies[$targetFqcn][$nodeId] = true;
                    }
                }
            }
        }

        return $dependencies;
    }

    /**
     * @param array<string, mixed> $options
     * @return list<int>
     */
    private function dependencyStateKeysForRelationValue(mixed $legacyValue, array $options, MigrationReport $report): array
    {
        $ids = $this->normaliseLegacyIds($legacyValue);
        if ($ids === []) {
            return [];
        }

        if (isset($options['joinTranslation']) && is_array($options['joinTranslation'])) {
            return $this->translateDependencyKeys($ids, $options['joinTranslation'], $report);
        }

        if (isset($options['joinTable'], $options['joinLocalColumn'], $options['joinForeignColumn'])) {
            return $this->joinDependencyKeys($ids, $options, $report);
        }

        return $ids;
    }

    /** @return list<int> */
    private function normaliseLegacyIds(mixed $value): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }

        $values = is_array($value) ? $value : [$value];
        $ids = [];
        foreach ($values as $item) {
            $id = (int) $item;
            if ($id > 0) {
                $ids[$id] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * @param list<int> $ids
     * @param array<string, mixed> $joinTranslation
     * @return list<int>
     */
    private function translateDependencyKeys(array $ids, array $joinTranslation, MigrationReport $report): array
    {
        $table = (string) ($joinTranslation['table'] ?? '');
        $sourceColumn = (string) ($joinTranslation['sourceColumn'] ?? '');
        $targetColumn = (string) ($joinTranslation['targetColumn'] ?? '');
        if (!$this->validLegacyIdentifiers([$table, $sourceColumn, $targetColumn])) {
            $report->warn('Skipping dependency relation with invalid joinTranslation identifiers.');
            return [];
        }

        $sql = sprintf('SELECT %s FROM %s WHERE %s = :id LIMIT 1', $targetColumn, $table, $sourceColumn);
        $out = [];
        foreach ($ids as $id) {
            $row = Plugin::getInstance()->legacyDbService->queryOne($sql, [':id' => $id]);
            if (is_array($row)) {
                $mappedId = (int) ($row[$targetColumn] ?? 0);
                if ($mappedId > 0) {
                    $out[$mappedId] = true;
                }
            }
        }

        return array_keys($out);
    }

    /**
     * @param list<int> $ids
     * @param array<string, mixed> $options
     * @return list<int>
     */
    private function joinDependencyKeys(array $ids, array $options, MigrationReport $report): array
    {
        $table = (string) ($options['joinTable'] ?? '');
        $localColumn = (string) ($options['joinLocalColumn'] ?? '');
        $foreignColumn = (string) ($options['joinForeignColumn'] ?? '');
        $orderBy = isset($options['joinOrderBy']) ? (string) $options['joinOrderBy'] : '';
        $identifiers = $orderBy === '' ? [$table, $localColumn, $foreignColumn] : [$table, $localColumn, $foreignColumn, $orderBy];
        if (!$this->validLegacyIdentifiers($identifiers)) {
            $report->warn('Skipping dependency relation with invalid joinTable identifiers.');
            return [];
        }

        $sql = sprintf(
            'SELECT %s FROM %s WHERE %s = :id%s',
            $foreignColumn,
            $table,
            $localColumn,
            $orderBy !== '' ? " ORDER BY {$orderBy} ASC" : '',
        );
        $out = [];
        foreach ($ids as $id) {
            foreach (Plugin::getInstance()->legacyDbService->queryAll($sql, [':id' => $id]) as $row) {
                $mappedId = (int) ($row[$foreignColumn] ?? 0);
                if ($mappedId > 0) {
                    $out[$mappedId] = true;
                }
            }
        }

        return array_keys($out);
    }

    /** @param list<string> $identifiers */
    private function validLegacyIdentifiers(array $identifiers): bool
    {
        foreach ($identifiers as $identifier) {
            if ($identifier === '' || !preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
                return false;
            }
        }

        return true;
    }

    private function nodeIdForPageRef(string $fqcn, int $refId): ?int
    {
        $row = Plugin::getInstance()->legacyDbService->queryOne(
            <<<'SQL'
SELECT nt.node_id
FROM kuma_node_versions nv
JOIN kuma_node_translations nt ON nt.public_node_version_id = nv.id
JOIN kuma_nodes n ON n.id = nt.node_id
WHERE n.ref_entity_name = :fqcn AND nv.ref_id = :refId AND n.deleted = 0
ORDER BY nt.node_id ASC
LIMIT 1
SQL,
            [':fqcn' => $fqcn, ':refId' => $refId],
        );

        $nodeId = is_array($row) ? (int) ($row['node_id'] ?? 0) : 0;
        return $nodeId > 0 ? $nodeId : null;
    }

    /**
     * @param array<string, array<int, true>> $dependencies
     */
    private function transformDependencyPayloads(
        string $storageDir,
        array $mapping,
        MigrationFilters $parentFilters,
        array $dependencies,
        MigrationReport $report,
    ): int {
        $plugin = Plugin::getInstance();
        $transformedDir = $storageDir . '/transformed/entries';
        $dependencyFilters = new MigrationFilters(
            entities: array_keys($dependencies),
            locales: $parentFilters->locales,
            since: null,
            noSeo: $parentFilters->noSeo,
            noRetour: $parentFilters->noRetour,
            relationGraph: $parentFilters->relationGraph,
        );

        try {
            foreach ($plugin->transformService->run(
                $this->streamExtractedSubset($storageDir, $dependencies),
                $mapping,
                $dependencyFilters,
                ['dryRun' => false, 'migrationReport' => $report],
            ) as $payload) {
                if (isset($payload['__report'])) {
                    $this->mergeTransformReportSentinel($payload, $report);
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
                $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if ($json === false || file_put_contents($outDir . '/' . $nodeId . '.json', $json) === false) {
                    throw new \RuntimeException("Cannot write transformed dependency JSON for {$fqcn}#{$nodeId}");
                }
            }
        } catch (Throwable $e) {
            $this->stderr("  FAIL dependency transform: {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    /**
     * @param array<string, array<int, true>> $dependencies
     * @return iterable<array<string, mixed>>
     */
    private function streamExtractedSubset(string $storageDir, array $dependencies): iterable
    {
        foreach ($dependencies as $fqcn => $nodeIds) {
            foreach (array_keys($nodeIds) as $nodeId) {
                $file = $storageDir . '/extracted/' . $this->slugify($fqcn) . '/' . (int) $nodeId . '.json';
                $raw = is_file($file) ? file_get_contents($file) : false;
                if ($raw === false) {
                    continue;
                }
                $payload = json_decode($raw, true);
                if (is_array($payload)) {
                    yield $payload;
                }
            }
        }
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
