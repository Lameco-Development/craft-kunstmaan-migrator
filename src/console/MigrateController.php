<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\console;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use Lameco\KumaCompile\Compile\PayloadWriter;
use Lameco\KumaCompile\Mapping\Mapping;
use Lameco\KumaCompile\Mapping\Schema;
use Lameco\KumaCompile\Target\TargetCheck;
use lameco\kunstmaanmigrator\compile\TargetModel;
use lameco\kunstmaanmigrator\finalize\FinalizePass;
use lameco\kunstmaanmigrator\payload\FixupService;
use lameco\kunstmaanmigrator\payload\CraftSchemaGateway;
use lameco\kunstmaanmigrator\NeverProductionTrait;
use lameco\kunstmaanmigrator\Plugin;
use lameco\kunstmaanmigrator\queue\FinalizeJob;
use lameco\kunstmaanmigrator\queue\MigrateEnvironmentJob;
use lameco\kunstmaanmigrator\queue\ResolveDeferredRefsJob;
use lameco\kunstmaanmigrator\run\EnvironmentPipeline;
use lameco\kunstmaanmigrator\run\RunOutcome;
use lameco\kunstmaanmigrator\run\RunSettings;
use lameco\kunstmaanmigrator\run\RunTally;
use yii\console\ExitCode;

/**
 * Read the legacy database, compile it against the mapping, and write it into Craft — in
 * one process.
 *
 * Compiling and loading used to be separate tools exchanging NDJSON files. The file was a
 * contract, and contracts drift: the compiler emitted the documented `{type, fields}` block
 * shape while the loader needed a `sourceRef` marker the contract never mentioned, so Matrix
 * rows updated partially and neither side could see why. In one process the compiler's
 * intent reaches the writer directly.
 *
 * `--dump` still writes the payloads out, because reading and diffing them is genuinely
 * useful — but as an artifact of the run, not the seam it travels through.
 */
final class MigrateController extends Controller
{
    use NeverProductionTrait;

    /**
     * Craft's own `ControllerTrait::runAction()` treats a `null` action result as
     * `ExitCode::OK`, so a `beforeAction()` that merely returns `false` would make a
     * production refusal look like success. The gate's exit code is stashed here and
     * re-asserted in `runAction()` below, which is what makes the refusal observable.
     *
     * @see beforeAction()
     */
    private ?int $neverProductionExitCode = null;

    /** Path to the mapping YAML. */
    public string $mapping = '';

    /**
     * Compile only this legacy environment.
     *
     * Not `--env`: Craft's console controllers already own that option for selecting
     * CRAFT_ENVIRONMENT, and the collision silently ignored the filter.
     */
    public ?string $legacyEnv = null;

    /** Stop after this many entries in total, across every environment the run covers. */
    public ?int $limit = null;

    /** Refresh entries that already exist. */
    public bool $force = false;

    /** Compile and report without writing to Craft. */
    public bool $dryRun = false;

    /** Directory to write the compiled payloads into, for inspection. */
    public ?string $dump = null;

    /**
     * Skip the adapters that run after the entries: SEO, redirects, navigation, translations.
     *
     * They read the legacy database directly rather than a compiled payload, and each is
     * slow enough that an operator iterating on the entry pass wants them off.
     */
    public bool $entriesOnly = false;

    /**
     * Run only the finalize pass — rewrite legacy references left in migrated rich text — and
     * nothing else. The pass is idempotent (it only touches rows that still carry a marker) and
     * resolves against state that already exists, so it is safe to re-run on its own after a
     * migration has completed. Also the only practical way to exercise it: the adapters that
     * normally precede it take hours on a full corpus.
     */
    public bool $finalizeOnly = false;

    /**
     * Compile only these page entities / `entities:` names, comma separated.
     *
     * A full corpus takes over an hour, which is the wrong feedback loop for a fix that
     * touches one page type: `--only=PartnerPage --legacyEnv=DE --entriesOnly` is the same
     * code path over 423 rows instead of 2,000, and answers in a minute.
     */
    public ?string $only = null;

    /**
     * Hand the run to Craft's queue instead of doing it here.
     *
     * One job per environment. Every stage is idempotent, so a job that dies
     * halfway is safe to retry — and a run that takes hours stops depending on
     * a terminal staying open.
     *
     * Off by default until a full run has been through it: the inline path is
     * what every migration so far has used, and the two must agree before the
     * default moves.
     */
    public bool $queue = false;

    /**
     * Exit non-zero when the run lost something, rather than only when it failed.
     *
     * A migration that drops 496 pageparts exits 0 and looks exactly like one that dropped
     * none — the losses are counted, reported, and reachable only by reading the JSON. That
     * makes noticing them optional, which is how a lossy run reaches a client. With this on,
     * lossy conversions, unresolved assets and fixup orphans are failures.
     *
     * Off by default because every real corpus loses something and a green CI is not worth
     * more than an honest one; turn it on once a corpus has a known-good loss count.
     */
    public bool $failOnLoss = false;

    /** Skip the asset stage entirely. Documented on `MigrationOptions` and, until now, unreachable. */
    public bool $skipAssets = false;

    /**
     * Re-save the migrated sections when the run finishes.
     *
     * URIs are computed at save time from the parent's URI, so a subtree written before its
     * ancestor's per-site slugs settle keeps a stale prefix. On the reference corpus that is
     * the difference between 76.6% and 97.7% URL fidelity — which means a run without it is
     * not finished, and leaving it to the README leaves a quarter of the site's URLs to
     * whether the operator read one.
     */
    public bool $resave = true;

    public function beforeAction($action): bool
    {
        $this->neverProductionExitCode = $this->enforceNeverProduction();
        if ($this->neverProductionExitCode !== null) {
            return false;
        }

        return parent::beforeAction($action);
    }

    public function runAction($id, $params = []): int
    {
        $result = parent::runAction($id, $params);

        return $this->neverProductionExitCode ?? $result;
    }

    public function options($actionID): array
    {
        return array_merge(
            parent::options($actionID),
            [
                'mapping', 'legacyEnv', 'limit', 'force', 'dryRun', 'dump', 'entriesOnly',
                'finalizeOnly', 'only', 'queue', 'failOnLoss', 'skipAssets', 'resave',
            ],
        );
    }

    public function actionIndex(): int
    {
        if ($this->mapping === '' || !is_file($this->mapping)) {
            $this->stderr("Missing or unreadable --mapping=<file.yaml>\n", Console::FG_RED);

            return ExitCode::USAGE;
        }

        $mapping = Mapping::fromFile($this->mapping);
        $gateway = new CraftSchemaGateway();
        $target = new TargetModel($gateway);

        // Shape first, then the target: a mapping that is not well-formed produces
        // misleading target errors.
        if ($errors = (new Schema())->validate($mapping)) {
            return $this->refuse('Mapping is not well-formed', $errors);
        }

        if ($errors = (new TargetCheck($target))->check($mapping)) {
            return $this->refuse('Mapping does not match this Craft install', $errors);
        }

        if ($conflicts = $mapping->openConflicts()) {
            return $this->refuse(
                sprintf('%d unresolved conflicts — set conflict.status: decided', count($conflicts)),
                array_map(static fn ($c): string => sprintf('%s: %s vs %s', $c->subject, $c->artifact, $c->spec), $conflicts),
            );
        }

        $only = $this->onlyList();

        if ($only !== null) {
            $known = [...array_keys($mapping->pages()), ...array_keys($mapping->entities())];
            $unknown = array_diff($only, $known);

            // A misspelled name would narrow the run to nothing and report a clean zero,
            // which is the most convincing way to be wrong.
            if ($unknown !== []) {
                return $this->refuse(
                    sprintf('--only names nothing in the mapping: %s', implode(', ', $unknown)),
                    ['known names come from `pages:` and `entities:`'],
                );
            }
        }

        $plugin = Plugin::getInstance();

        $tally = new RunTally();
        $tally->onProblem = function (string $problem): void {
            $this->reportProblem($problem);
        };

        $settings = new RunSettings(
            dryRun: $this->dryRun,
            force: $this->force,
            limit: $this->limit,
            entriesOnly: $this->entriesOnly,
            only: $only,
            dumpDir: $this->dump,
            skipAssets: $this->skipAssets,
        );

        // Finalize compiles nothing and needs no target schema, but it does need the legacy
        // database: resolving `/uploads/media/...` to a migrated asset goes through `kuma_media`.
        // So it is run per environment, exactly like everything else — repointing `legacyDb` each
        // time. The pass is idempotent and only touches rows that still carry a marker, so a
        // reference the COM database cannot answer is simply retried under DE.
        if ($this->finalizeOnly) {
            $report = (new FinalizePass())->run($mapping, $this->dryRun, $this->legacyEnv);

            $this->stdout(json_encode([
                'finalize' => $report->counts,
                'problems' => array_slice($report->warnings, 0, 100),
            ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);

            return ($report->counts['failed'] ?? 0) > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
        }

        if ($this->queue) {
            $queued = [];

            foreach ($mapping->environments() as $env => $spec) {
                if ($this->legacyEnv !== null && $env !== $this->legacyEnv) {
                    continue;
                }

                Craft::$app->getQueue()->push(new MigrateEnvironmentJob([
                    'mappingPath' => $this->mapping,
                    'environment' => (string) $env,
                    'dryRun' => $this->dryRun,
                    'force' => $this->force,
                    'limit' => $this->limit,
                    'entriesOnly' => $this->entriesOnly,
                    'only' => $only,
                ]));

                $queued[] = (string) $env;
            }

            // The same two corpus-wide passes an inline run performs at the end.
            // Without them a `--queue` run is not the same migration as the run
            // it replaces. FIFO gives them the ordering they need.
            if (!$this->entriesOnly) {
                Craft::$app->getQueue()->push(new ResolveDeferredRefsJob());
                Craft::$app->getQueue()->push(new FinalizeJob([
                    'mappingPath' => $this->mapping,
                    'dryRun' => $this->dryRun,
                ]));
                $queued[] = 'fixup';
                $queued[] = 'finalize';
            }

            $this->stdout(json_encode([
                'queued' => $queued,
                'note' => 'Run `craft queue/listen`, or open the control panel, to execute.',
            ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);

            return ExitCode::OK;
        }

        $pipeline = EnvironmentPipeline::build($mapping, $settings);

        foreach ($mapping->environments() as $env => $spec) {
            if ($this->legacyEnv !== null && $env !== $this->legacyEnv) {
                continue;
            }

            $pipeline->run(
                $mapping,
                (string) $env,
                (array) $spec,
                $settings,
                $tally,
                $this->dump !== null ? $this->writerFor((string) $env) : null,
            );
        }

        // A payload can name a parent or a relation that no entry had been written for yet, and
        // pass one parks those as `pendingRefs` rather than failing. Nothing resolved them: the
        // fixup pass was only ever reachable through `load/fixup`, so a `migrate` run left every
        // deferred reference dangling and said nothing about it.
        $fixup = null;

        if (!$this->dryRun) {
            $fixup = (new FixupService($plugin->migrationStateService, $plugin->entryMigrationService))->run();

            foreach (($fixup['orphans'] ?? []) as $orphan) {
                $tally->problem(sprintf(
                    '%s: unresolved %s -> %s',
                    (string) ($orphan['sourceUid'] ?? '?'),
                    (string) ($orphan['field'] ?? '?'),
                    (string) ($orphan['ref'] ?? '?'),
                ));
            }
        }

        // Last, and only after every environment: `[NT<id>]` resolves a legacy node translation to
        // the entry it became, and `/uploads/media/...` to a migrated asset. Neither can be
        // answered until the entries and assets exist. The rewriter has always been able to do
        // this and was called by nothing; see CkeditorFinalizeService.
        $finalize = null;

        if (!$this->entriesOnly) {
            // Per environment, not once: the pass resolves against the legacy database,
            // and running it after the loop meant it only ever saw the last one.
            $finalizeReport = (new FinalizePass())->run($mapping, $this->dryRun, $this->legacyEnv);
            $finalize = $finalizeReport->counts;

            foreach (array_slice($finalizeReport->warnings, 0, 20) as $warning) {
                $tally->problem($warning);
            }
        }

        // URIs are computed at save time, so a subtree written before its ancestor's per-site
        // slugs settled keeps a stale prefix until something re-saves it. Part of the run, not
        // a line in the README the operator may or may not have reached.
        $resave = null;

        if ($this->resave && !$this->dryRun) {
            $resave = $this->resaveSections($mapping);
        }

        $lossCount = $pipeline->transforms()->lossCount();
        $unresolvedAssets = count($tally->unresolvedAssets);
        $orphans = count($fixup['orphans'] ?? []);

        // Per-locale block content the target's field configuration cannot hold. Accumulated on
        // the service because the payload path builds no MigrationReport — without this the
        // only trace is a line in the Craft log, which is exactly the "reporting is a side
        // channel" problem one layer down.
        $perSiteBlockLosses = $plugin->entryMigrationService->perSiteBlockLosses;

        $this->stdout(json_encode([
            'counts' => $tally->counts,
            'fixup' => $fixup,
            'finalize' => $finalize,
            'resave' => $resave,
            'lossyConversions' => $lossCount,
            'losses' => $pipeline->transforms()->losses(),
            'skippedSources' => $pipeline->compiler()->skipped(),
            'droppedAddresses' => $tally->droppedAddresses,
            'unresolvedAssets' => $unresolvedAssets,
            'perSiteBlocksNotRepresentable' => count($perSiteBlockLosses),
            'perSiteBlockLossSample' => array_slice($perSiteBlockLosses, 0, 10),
            'unresolvedAssetSample' => array_slice(array_unique($tally->unresolvedAssets), 0, 5),
            'problems' => array_slice($tally->problems, 0, 40),
            'only' => $only,
            'adapters' => $tally->adapters,
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);

        // Losses are counted either way; --fail-on-loss is what makes ignoring them a
        // decision rather than the default.
        if ($this->failOnLoss && RunOutcome::lost($lossCount, $unresolvedAssets, $orphans + count($perSiteBlockLosses))) {
            $this->stderr(sprintf(
                "Run lost content: %d lossy conversions, %d unresolved assets, %d unresolved references.\n",
                $lossCount,
                $unresolvedAssets,
                $orphans,
            ), Console::FG_RED);
        }

        return RunOutcome::exitCode(
            $tally->hasFailures(),
            (int) ($tally->counts['invalid'] ?? 0),
            $this->failOnLoss,
            $lossCount,
            $unresolvedAssets,
            $orphans + count($perSiteBlockLosses),
        );
    }





    /**
     * Re-save every section the mapping writes into.
     *
     * Craft computes a URI at save time from the parent's URI, so an entry written before its
     * ancestors' per-site slugs settled carries a stale prefix until it is saved again. On the
     * reference corpus this pass is worth 21 percentage points of URL fidelity.
     *
     * Sections come from the mapping rather than a list, so a project that migrates into
     * something other than `pages` gets its own sections re-saved without editing anything.
     *
     * @return array<string, string> section handle → outcome
     */
    private function resaveSections(Mapping $mapping): array
    {
        $sections = [];

        foreach ([...array_values($mapping->pages()), ...array_values($mapping->entities())] as $spec) {
            $handle = (string) (((array) $spec)['section'] ?? '');

            if ($handle !== '') {
                $sections[$handle] = true;
            }
        }

        $out = [];

        foreach (array_keys($sections) as $handle) {
            $this->stdout("Re-saving section {$handle} ...\n");

            try {
                // Craft's own console action, so this is the same pass an operator would run
                // by hand — not a second implementation of it that can drift.
                $exit = Craft::$app->runAction('resave/entries', [
                    'section' => $handle,
                    'interactive' => 0,
                ]);

                $out[$handle] = $exit === ExitCode::OK ? 'ok' : "exit {$exit}";
            } catch (\Throwable $e) {
                // A re-save that fails must not discard the migration that already succeeded;
                // the run reports it and the operator can repeat the pass by hand.
                $out[$handle] = 'failed: ' . $e->getMessage();
                $this->reportProblem("resave {$handle}: " . $e->getMessage());
            }
        }

        return $out;
    }

    /**
     * A failure, said out loud the moment it happens.
     *
     * The JSON summary only reaches stdout once every environment has been walked, which on a
     * real corpus is hours. Twenty-two taxonomy entries once failed to save on every one of
     * them and nothing showed it until the run ended — a caught exception, a counter, and
     * silence. stderr is free, so it is used.
     */
    private function reportProblem(string $problem): void
    {
        $this->stderr('  ! ' . $problem . "\n", Console::FG_YELLOW);
    }

    /** @return ?list<string> */
    private function onlyList(): ?array
    {
        if ($this->only === null || trim($this->only) === '') {
            return null;
        }

        $names = array_values(array_filter(array_map(trim(...), explode(',', $this->only)), static fn (string $n): bool => $n !== ''));

        return $names === [] ? null : $names;
    }

    private function writerFor(string $env): PayloadWriter
    {
        $dir = rtrim((string) $this->dump, '/');

        if (!is_dir($dir)) {
            mkdir($dir, 0o775, true);
        }

        return new PayloadWriter(fopen(sprintf('%s/%s.ndjson', $dir, strtolower($env)), 'w') ?: null);
    }

    /** @param list<string> $errors */
    private function refuse(string $headline, array $errors): int
    {
        $this->stderr($headline . ":\n", Console::FG_RED);

        foreach (array_slice($errors, 0, 40) as $error) {
            $this->stderr('  · ' . $error . "\n");
        }

        return ExitCode::UNSPECIFIED_ERROR;
    }

}
