<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\console;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use craft\helpers\Queue as QueueHelper;
use Lameco\Kunstmaanmigrator\Compile\PayloadWriter;
use Lameco\Kunstmaanmigrator\craft\CraftSchemaGateway;
use Lameco\Kunstmaanmigrator\craft\TargetModel;
use Lameco\Kunstmaanmigrator\finalize\FinalizePass;
use Lameco\Kunstmaanmigrator\load\FixupService;
use Lameco\Kunstmaanmigrator\Mapping\Mapping;
use Lameco\Kunstmaanmigrator\Mapping\MappingCheck;
use Lameco\Kunstmaanmigrator\Mapping\Schema;
use Lameco\Kunstmaanmigrator\Plugin;
use Lameco\Kunstmaanmigrator\queue\MigrateEnvironmentJob;
use Lameco\Kunstmaanmigrator\Report\BlockPlacement;
use Lameco\Kunstmaanmigrator\Report\Coverage;
use Lameco\Kunstmaanmigrator\Report\CoverageReport;
use Lameco\Kunstmaanmigrator\run\EnvironmentPipeline;
use Lameco\Kunstmaanmigrator\run\RunLog;
use Lameco\Kunstmaanmigrator\run\RunOutcome;
use Lameco\Kunstmaanmigrator\run\RunSettings;
use Lameco\Kunstmaanmigrator\run\RunTally;
use Lameco\Kunstmaanmigrator\safety\NeverProductionTrait;
use Lameco\Kunstmaanmigrator\Source\LegacyDatabase;
use Lameco\Kunstmaanmigrator\Target\TargetSchema;
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

    /**
     * Run anyway when the corpus has grown past the mapping.
     *
     * The legacy site stays live while the migration is being built. Editors add pages, and
     * three weeks in someone adds a *new pagepart class* — which `coverage` catches only if
     * somebody remembers to run it, and no run did. Anything not named in the mapping is an
     * error rather than a silent skip, so the run now asks the question itself.
     */
    public bool $allowDrift = false;

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
                'allowDrift',
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

        // The shared verdict: shape, install, blocks-nothing-accepts, open
        // conflicts. Drift and the --only list stay here — they are facts
        // about this run, not about the mapping.
        if (($verdict = (new MappingCheck($target))->verdict($mapping)) !== null) {
            return $this->refuse($verdict[0], $verdict[1]);
        }

        if ($drift = $this->refuseOnDrift($mapping, $target)) {
            return $drift;
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
        $tally->onProblem = function(string $problem): void {
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

            foreach (array_keys($mapping->environments()) as $env) {
                if ($this->legacyEnv !== null && $env !== $this->legacyEnv) {
                    continue;
                }

                $queued[] = (string) $env;
            }

            if ($queued === []) {
                $this->stderr(sprintf("Mapping names no environment \"%s\"\n", (string) $this->legacyEnv));

                return ExitCode::UNSPECIFIED_ERROR;
            }

            // One job starts the chain (#48): each environment's last batch
            // pushes its adapters, each adapter pass pushes the next
            // environment, and the corpus-wide fixup + finalize run only after
            // the last — the same migration as an inline run, with the
            // ordering enforced structurally instead of FIFO-hopeful (#47).
            QueueHelper::push(job: new MigrateEnvironmentJob([
                'mappingPath' => $this->mapping,
                'environment' => $queued[0],
                'remainingEnvironments' => array_values(array_slice($queued, 1)),
                'dryRun' => $this->dryRun,
                'force' => $this->force,
                'limit' => $this->limit,
                'entriesOnly' => $this->entriesOnly,
                'only' => $only,
                'chainCorpusPasses' => true,
                'mappingHash' => sha1((string) file_get_contents($this->mapping)),
            ]), priority: 512);

            if (!$this->entriesOnly) {
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

            // The same run-log entry a queued MigrateEnvironmentJob writes. The log
            // utility read only queue runs, so a console migrate — the way every e2e
            // verification actually runs — left the screen empty and the operator
            // wondering whether the CP was looking at the right database.
            RunLog::default()->track('migrate', [
                'environment' => (string) $env,
                'dryRun' => $this->dryRun,
                'force' => $this->force,
                'limit' => $this->limit,
                'only' => $only,
            ], function(array &$extra) use ($pipeline, $mapping, $env, $spec, $settings, $tally): void {
                $before = $tally->counts;

                try {
                    $pipeline->run(
                        $mapping,
                        (string) $env,
                        (array) $spec,
                        $settings,
                        $tally,
                        $this->dump !== null ? $this->writerFor((string) $env) : null,
                    );
                } finally {
                    $delta = [];

                    foreach ($tally->counts as $name => $count) {
                        $delta[$name] = $count - ($before[$name] ?? 0);
                    }

                    $extra['counts'] = $delta;
                    $extra['problems'] = count($tally->problems);
                }
            });
        }

        // A payload can name a parent or a relation that no entry had been written for yet, and
        // pass one parks those as `pendingRefs` rather than failing. Nothing resolved them: the
        // fixup pass was only ever reachable through `load/fixup`, so a `migrate` run left every
        // deferred reference dangling and said nothing about it.
        $fixup = null;

        if (!$this->dryRun) {
            RunLog::default()->track('fixup', [], function(array &$extra) use ($plugin, &$fixup): void {
                $fixup = (new FixupService($plugin->migrationStateService, $plugin->entryMigrationService))->run();
                $extra['patched'] = $fixup['patched'] ?? 0;
                $extra['orphans'] = count($fixup['orphans'] ?? []);
            });

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
            $finalizeReport = null;
            RunLog::default()->track('finalize', ['dryRun' => $this->dryRun], function(array &$extra) use ($mapping, &$finalizeReport): void {
                $finalizeReport = (new FinalizePass())->run($mapping, $this->dryRun, $this->legacyEnv);
                $extra['counts'] = $finalizeReport->counts;
            });
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

        $names = array_values(array_filter(array_map(trim(...), explode(',', $this->only)), static fn(string $n): bool => $n !== ''));

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
    /**
     * Coverage against the live legacy databases, at the top of the run rather than whenever
     * somebody remembers.
     *
     * The other two preflights read the mapping and the target. Neither can see the source
     * moving underneath both, and the source is a production site with editors in it. This
     * costs one snapshot query per environment against a database the run is about to read
     * anyway.
     *
     * A narrowed run warns instead of refusing. `--only=PartnerPage --limit=10` is the tight
     * iteration loop the whole workflow depends on, and a run that is not claiming to be
     * complete has no business failing on incompleteness.
     *
     * @return int|null the exit code to return, or null to carry on
     */
    private function refuseOnDrift(Mapping $mapping, TargetSchema $target): ?int
    {
        if ($this->allowDrift || $this->finalizeOnly) {
            return null;
        }

        $coverage = new Coverage($mapping);
        $placement = new BlockPlacement($mapping, $target);
        $dsn = EnvironmentPipeline::dsnFromSettings();
        $rejections = [];

        foreach ($mapping->environments() as $env => $spec) {
            if (!isset($spec['database']) || ($this->legacyEnv !== null && $env !== $this->legacyEnv)) {
                continue;
            }

            $db = LegacyDatabase::connect((string) $env, (string) $spec['database'], $dsn);
            $coverage->ingest($db->snapshot());

            foreach ($placement->rejections($db->livePlacementsByPageType()) as $rejection) {
                $rejections[] = ['env' => (string) $env] + $rejection;
            }
        }

        // Warned about before the coverage verdict, because a mapping with no holes can still
        // be dropping content — and a clean coverage result is exactly when nobody looks further.
        $this->warnAboutRejectedPlacements($rejections);

        if (!$coverage->hasHoles()) {
            return null;
        }

        $holes = (new CoverageReport($coverage))->holes();
        $holes[] = 'Claim each in a lane, or declare it under `unmapped:` with a reason.';
        $holes[] = 'Run `mapping/coverage <mapping>` for the whole picture, or --allow-drift to run anyway.';

        if ($this->narrowed()) {
            $this->stderr("The corpus has grown past the mapping:\n", Console::FG_YELLOW);

            foreach ($holes as $hole) {
                $this->stderr('  · ' . $hole . "\n");
            }

            $this->stderr("Continuing: this run is narrowed and is not claiming to be complete.\n");

            return null;
        }

        return $this->refuse('The corpus has grown past the mapping', $holes);
    }

    /**
     * Parts whose block the page they actually sit on will not accept.
     *
     * A warning rather than a refusal, and the reason is the same one that made
     * `pagesWithNoBlockField()` a warning: the fix is usually a Craft-side change — adding the
     * block type to that Matrix's allow-list — which is not always the migrator's call, and
     * refusing would block a run that is otherwise correct. What it must not do is stay silent
     * until a run report two hours later, which is how 41 placements on `blogPage` came to be
     * found by sweeping migrated entries afterwards instead.
     *
     * @param list<array<string, mixed>> $rejections
     */
    private function warnAboutRejectedPlacements(array $rejections): void
    {
        if ($rejections === []) {
            return;
        }

        $total = array_sum(array_map(static fn(array $r): int => (int) $r['placements'], $rejections));

        $this->stderr(sprintf(
            "%s live placements will be dropped: the block is not on the target Matrix's allow-list.\n",
            number_format($total),
        ), Console::FG_YELLOW);

        foreach (array_slice($rejections, 0, 20) as $r) {
            $this->stderr(sprintf(
                "  · %s  %s -> %s is not allowed on %s.%s — %s placements\n",
                (string) $r['env'],
                (string) $r['part'],
                (string) $r['block'],
                (string) $r['entryType'],
                (string) $r['field'],
                number_format((int) $r['placements']),
            ));
        }

        if (count($rejections) > 20) {
            $this->stderr(sprintf("  … and %d more pairings\n", count($rejections) - 20));
        }

        $this->stderr("  Add the block type to that field's allow-list in Craft, or map the part elsewhere.\n");
    }

    /**
     * Whether the operator asked for a slice rather than the whole corpus.
     *
     * `--entries-only` is not a slice: it skips the adapters and the two closing passes, and
     * still walks every node. A new pagepart class is exactly as lost there.
     */
    private function narrowed(): bool
    {
        return $this->onlyList() !== null || $this->limit !== null;
    }

    private function refuse(string $headline, array $errors): int
    {
        $this->stderr($headline . ":\n", Console::FG_RED);

        foreach (array_slice($errors, 0, 40) as $error) {
            $this->stderr('  · ' . $error . "\n");
        }

        return ExitCode::UNSPECIFIED_ERROR;
    }
}
