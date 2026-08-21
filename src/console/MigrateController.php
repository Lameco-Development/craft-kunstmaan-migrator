<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\console;

use Craft;
use craft\console\Controller;
use craft\elements\Entry;
use craft\helpers\Console;
use yii\db\Connection;
use Lameco\KumaCompile\Compile\Compiler;
use Lameco\KumaCompile\Compile\PayloadWriter;
use Lameco\KumaCompile\Compile\RedirectCompiler;
use Lameco\KumaCompile\Compile\Transforms;
use Lameco\KumaCompile\Legacy\Dsn;
use Lameco\KumaCompile\Legacy\LegacyDatabase;
use Lameco\KumaCompile\Mapping\Mapping;
use Lameco\KumaCompile\Mapping\Schema;
use Lameco\KumaCompile\Target\TargetCheck;
use lameco\kunstmaanmigrator\compile\TargetModel;
use lameco\kunstmaanmigrator\payload\FixupService;
use lameco\kunstmaanmigrator\payload\CraftSchemaGateway;
use lameco\kunstmaanmigrator\payload\Payload;
use lameco\kunstmaanmigrator\payload\PayloadEntrySaver;
use lameco\kunstmaanmigrator\load\MigrationOptions;
use lameco\kunstmaanmigrator\load\MigrationReport;
use lameco\kunstmaanmigrator\load\RedirectMigrationService;
use lameco\kunstmaanmigrator\payload\PayloadValidator;
use lameco\kunstmaanmigrator\payload\RefResolver;
use lameco\kunstmaanmigrator\NeverProductionTrait;
use lameco\kunstmaanmigrator\Plugin;
use lameco\kunstmaanmigrator\queue\MigrateEnvironmentJob;
use lameco\kunstmaanmigrator\run\EnvironmentPipeline;
use lameco\kunstmaanmigrator\run\RunSettings;
use lameco\kunstmaanmigrator\run\RunTally;
use lameco\kunstmaanmigrator\sites\SiteMap;
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

    /** Directory of target block specs, used to check field-map coverage. */
    public ?string $specs = null;

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
            ['mapping', 'legacyEnv', 'limit', 'force', 'dryRun', 'dump', 'specs', 'entriesOnly', 'finalizeOnly', 'only', 'queue'],
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
        );

        // Finalize compiles nothing and needs no target schema, but it does need the legacy
        // database: resolving `/uploads/media/...` to a migrated asset goes through `kuma_media`.
        // So it is run per environment, exactly like everything else — repointing `legacyDb` each
        // time. The pass is idempotent and only touches rows that still carry a marker, so a
        // reference the COM database cannot answer is simply retried under DE.
        if ($this->finalizeOnly) {
            $plugin = Plugin::getInstance();
            $report = new MigrationReport();

            foreach ($mapping->environments() as $env => $spec) {
                if ($this->legacyEnv !== null && $env !== $this->legacyEnv) {
                    continue;
                }

                $this->applyLegacyDb(EnvironmentPipeline::dsnFromSettings(), (string) $spec['database']);
                $plugin->ckeditorRewriterService->resetLookupCaches();
                $plugin->ckeditorFinalizeService->run(new MigrationOptions(dryRun: $this->dryRun), $report);
            }

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
            $finalizeReport = $plugin->ckeditorFinalizeService->run(
                new MigrationOptions(dryRun: $this->dryRun),
            );
            $finalize = $finalizeReport->counts;

            foreach (array_slice($finalizeReport->warnings, 0, 20) as $warning) {
                $tally->problem($warning);
            }
        }

        $this->stdout(json_encode([
            'counts' => $tally->counts,
            'fixup' => $fixup,
            'finalize' => $finalize,
            'lossyConversions' => $pipeline->transforms()->lossCount(),
            'losses' => $pipeline->transforms()->losses(),
            'skippedSources' => $pipeline->compiler()->skipped(),
            'droppedAddresses' => $tally->droppedAddresses,
            'unresolvedAssets' => count($tally->unresolvedAssets),
            'unresolvedAssetSample' => array_slice(array_unique($tally->unresolvedAssets), 0, 5),
            'problems' => array_slice($tally->problems, 0, 40),
            'only' => $only,
            'adapters' => $tally->adapters,
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);

        return $tally->hasFailures() || ($tally->counts['invalid'] ?? 0) > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }




    /**
     * Point Craft's `legacyDb` component at one environment's database.
     *
     * `Plugin::init()` registers it once from a single setting, which is right for a
     * one-database migration and wrong for this one. Overwriting the registration — rather
     * than the instance — makes the next `Craft::$app->get('legacyDb')` build a fresh
     * connection, which is what `LegacyDbService` does on every call.
     */
    private function applyLegacyDb(Dsn $dsn, string $database): void
    {
        Craft::$app->set('legacyDb', [
            'class' => Connection::class,
            'dsn' => $dsn->forDatabase($database),
            'username' => $dsn->user,
            'password' => $dsn->password,
            'charset' => $dsn->charset,
            'attributes' => [\PDO::ATTR_EMULATE_PREPARES => false],
        ]);
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
