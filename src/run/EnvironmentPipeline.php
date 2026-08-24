<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\run;

use Craft;
use craft\helpers\App;
use Lameco\Kunstmaanmigrator\adapters\AdapterRegistry;
use Lameco\Kunstmaanmigrator\Compile\Compiler;
use Lameco\Kunstmaanmigrator\Compile\PayloadWriter;
use Lameco\Kunstmaanmigrator\Compile\Transforms;
use Lameco\Kunstmaanmigrator\craft\CraftSchemaGateway;
use Lameco\Kunstmaanmigrator\craft\TargetModel;
use Lameco\Kunstmaanmigrator\load\MigrationOptions;
use Lameco\Kunstmaanmigrator\load\MigrationReport;
use Lameco\Kunstmaanmigrator\load\PayloadEntrySaver;
use Lameco\Kunstmaanmigrator\Mapping\Mapping;
use Lameco\Kunstmaanmigrator\Payload\Payload;
use Lameco\Kunstmaanmigrator\Payload\PayloadValidator;
use Lameco\Kunstmaanmigrator\Plugin;
use Lameco\Kunstmaanmigrator\sites\SiteMap;
use Lameco\Kunstmaanmigrator\Source\Dsn;
use Lameco\Kunstmaanmigrator\Source\LegacyDatabase;

/**
 * One environment, start to finish: compile its legacy database, load what
 * comes out, then run the adapters against it.
 *
 * This was the body of a foreach inside MigrateController, reading the
 * controller's flags and writing four by-reference accumulators. That shape
 * has exactly one possible caller — a console command — which is why the
 * control panel and the queue could not run a migration.
 *
 * Everything an environment needs now arrives as arguments, and everything it
 * produces lands in a tally the caller owns. Two callers can use it, and a job
 * that handles one environment can hand the next to another job.
 */
final class EnvironmentPipeline
{
    public function __construct(
        private readonly PayloadValidator $validator,
        private readonly ?PayloadEntrySaver $saver,
        private readonly Compiler $compiler,
        private readonly Transforms $transforms,
    ) {
    }

    /**
     * Everything a run needs, built the same way for both callers.
     *
     * The console and a queue job must not assemble this differently — the
     * compile and load halves already drifted apart once when a file was the
     * contract between them, and two constructions of the same pipeline is the
     * same mistake with fewer symptoms.
     */
    public static function build(Mapping $mapping, RunSettings $settings): self
    {
        $gateway = new CraftSchemaGateway();
        $plugin = Plugin::getInstance();
        $transforms = new Transforms($mapping->all()['transforms'] ?? []);

        return new self(
            new PayloadValidator($gateway),
            $settings->dryRun ? null : self::saver($gateway, self::optionsFor($settings)),
            new Compiler($mapping, $transforms, new TargetModel($gateway), $settings->only),
            $transforms,
        );
    }

    /**
     * The one assembly of the saver. The standalone `load/entry` command
     * used to build its own with the same seven arguments, which is the
     * second assembly path the pipeline exists to rule out.
     */
    public static function saver(CraftSchemaGateway $gateway, MigrationOptions $options): PayloadEntrySaver
    {
        $plugin = Plugin::getInstance();

        return new PayloadEntrySaver(
            $gateway,
            $plugin->entryMigrationService,
            $plugin->migrationStateService,
            $plugin->assetMigrationService,
            $plugin->ckeditorRewriterService,
            null,
            $options,
        );
    }

    /**
     * What the compile half has to report, onto the tally both callers read.
     *
     * The compiler's skips and the transforms' losses accumulate per process:
     * the console holds one pipeline for the whole run and folds once at the
     * end; a queue batch is a fresh pipeline and folds after every batch.
     */
    public function foldCompileReport(RunTally $tally): void
    {
        $tally->absorbCompileReport(
            $this->compiler->skipped(),
            $this->transforms->losses(),
            $this->transforms->lossCount(),
        );
    }

    /**
     * The legacy connection, from the plugin's settings.
     *
     * `Dsn::fromEnvironment()` read KUMA_DB_* directly, which meant a `migrate`
     * run ignored the settings screen entirely: host, user and password came
     * from one place and everything else from another, and nothing said so.
     * Settings still falls back to KUMA_DB_* itself, so a project configured
     * the old way is unaffected — there is just one source now.
     *
     * Only the database name varies per environment, and that comes from the
     * mapping, which is where the topology lives.
     */
    public static function dsnFromSettings(): Dsn
    {
        $connection = Plugin::getInstance()->getSettings()->legacyConnection();

        return new Dsn(
            host: $connection['host'],
            port: $connection['port'],
            user: $connection['user'],
            password: $connection['password'],
            charset: $connection['charset'],
        );
    }

    public function transforms(): Transforms
    {
        return $this->transforms;
    }

    public function compiler(): Compiler
    {
        return $this->compiler;
    }

    /**
     * @param array<string, mixed> $spec the mapping's block for this environment
     */
    public function run(
        Mapping $mapping,
        string $env,
        array $spec,
        RunSettings $settings,
        RunTally $tally,
        ?PayloadWriter $writer = null,
    ): void {
        $context = $this->prepare($mapping, $env, $spec, $settings);

        $this->compiler->compile(
            $context->legacy,
            $env,
            function(array $raw) use ($context, $settings, $tally, $writer): void {
                $this->handlePayload($raw, $context, $settings, $tally, $writer);
            },
            $settings->limit,
        );

        if (!$settings->entriesOnly) {
            $tally->adapters[$env] = $this->runAdapters($context, $settings);
        }
    }

    /**
     * Open one environment — connection, site map, media roots — as the value
     * every pass of it takes.
     *
     * Extracted from `run()` for the batched queue path (#48): a batch job
     * rebuilds this in every process, compiles a window, and lets the process
     * end. Both callers prepare identically or the halves drift again.
     *
     * @param array<string, mixed> $spec the mapping's block for this environment
     */
    public function prepare(Mapping $mapping, string $env, array $spec, RunSettings $settings): EnvironmentContext
    {
        return self::open($mapping, $env, $spec, $settings);
    }

    /**
     * The same opening, for a caller that has no pipeline — the finalize pass
     * runs per environment after every pipeline has finished.
     *
     * @param array<string, mixed> $spec the mapping's block for this environment
     */
    public static function open(Mapping $mapping, string $env, array $spec, RunSettings $settings): EnvironmentContext
    {
        $db = LegacyDatabase::connect($env, (string) $spec['database'], self::dsnFromSettings());

        // Locale → site is per environment, not global. COM's `en` is comEnUs while LV's is
        // comLvEn, and one global map cannot hold both. The mapping states it per
        // environment, so it is the only source.
        $context = new EnvironmentContext(
            name: $env,
            database: (string) $spec['database'],
            sites: SiteMap::bind((array) ($spec['locales'] ?? []), Craft::$app->sites->getAllSites()),
            mediaRoots: self::mediaRootsFrom($spec),
            mapping: $mapping,
            legacy: $db,
            only: $settings->only,
        );

        self::adoptEnvironment($context, self::optionsFor($settings));

        return $context;
    }

    /**
     * One payload, validated and saved — the public face of `handlePayload`,
     * for the batched job whose processItem() is exactly this.
     *
     * @param array<string, mixed> $raw
     */
    public function processOne(
        array $raw,
        EnvironmentContext $context,
        RunSettings $settings,
        RunTally $tally,
        ?PayloadWriter $writer = null,
    ): void {
        $this->handlePayload($raw, $context, $settings, $tally, $writer);
    }

    /**
     * The adapter passes for one prepared environment, as `run()` executes them.
     *
     * @return array<string, mixed>
     */
    public function runAdaptersFor(EnvironmentContext $context, RunSettings $settings): array
    {
        return $this->runAdapters($context, $settings);
    }

    /** @param array<string, mixed> $raw */
    private function handlePayload(
        array $raw,
        EnvironmentContext $context,
        RunSettings $settings,
        RunTally $tally,
        ?PayloadWriter $writer,
    ): void {
        $tally->count('compiled');
        $writer?->write($raw);

        $payload = Payload::fromArray($raw);
        $violations = $this->validator->validate($payload);

        if ($violations !== []) {
            $tally->count('invalid');

            foreach ($violations as $violation) {
                $tally->problem(sprintf('%s %s', $violation->code, $violation->message));
            }

            return;
        }

        if ($this->saver === null) {
            return;
        }

        try {
            $tally->absorb($this->saver->save($payload, $context, $tally), $this->saver->refreshesExisting());
        } catch (\Throwable $e) {
            $tally->count('failed');
            $tally->problem(sprintf('%s: %s', $payload->sourceUid, $e->getMessage()));
        }
    }

    /**
     * Every registered adapter, after the entries of this environment exist — each of
     * them resolves a legacy id to an entry that has to be there already.
     *
     * @return array<string, mixed>
     */
    private function runAdapters(EnvironmentContext $context, RunSettings $settings): array
    {
        $opts = self::optionsFor($settings);
        $out = [];

        // The registry, not a hard-coded four. A project that registers its own
        // adapter through EVENT_REGISTER_ADAPTERS now has it run, which is what
        // the registry always claimed and could not deliver while this was a
        // literal array.
        foreach ((new AdapterRegistry())->all() as $adapter) {
            $service = $adapter->service();

            if ($service !== null) {
                $out[$adapter->handle] = self::summarise(
                    static fn(): MigrationReport => $service->migrateAll($opts, $context),
                );

                continue;
            }

            // An adapter with no factory and no special case would run silently
            // never. Saying so beats a missing key in the report.
            $out[$adapter->handle] = ['error' => sprintf(
                'Adapter "%s" declares no factory, so nothing ran it.',
                $adapter->handle,
            )];
        }

        return $out;
    }

    /**
     * The run's flags as the load half reads them — one construction, so the
     * saver, the adapters and the JIT asset lookups all honour the same
     * `--skip-assets` and `--force`.
     */
    private static function optionsFor(RunSettings $settings): MigrationOptions
    {
        return new MigrationOptions(
            dryRun: $settings->dryRun,
            force: $settings->force,
            skipAssets: $settings->skipAssets,
        );
    }

    /**
     * An adapter that throws does not take the run with it: the others have already written
     * what they could, and a stack trace here would hide the counts for all of them.
     *
     * @param callable(): MigrationReport $run
     * @return array<string, mixed>
     */
    private static function summarise(callable $run): array
    {
        try {
            $report = $run();
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }

        return [
            'counts' => $report->counts,
            'failures' => $report->failureCount(),
            'warnings' => array_slice($report->warnings, 0, 5),
        ];
    }

    /**
     * One environment's uploads directories, with `$VAR` and Craft aliases expanded.
     *
     * Each legacy site has its own uploads directory, so the media root travels
     * with the environment rather than being one global setting. The mapping
     * accepts either a single path or an ordered list — Enreach's DE
     * environment looks in its own directory first and falls back to COM's.
     *
     * A mapping is committed and shared, so a media root written as an absolute
     * path is a path that exists on one machine — the same problem as a password
     * in project config, one field over.
     *
     * @param array<string, mixed> $spec
     * @return list<string>
     */
    public static function mediaRootsFrom(array $spec): array
    {
        $roots = $spec['mediaRoot'] ?? null;
        $roots = is_array($roots) ? array_values($roots) : ($roots === null ? [] : [$roots]);

        return array_values(array_filter(array_map(
            static fn($path): string => (string) App::parseEnv((string) $path),
            $roots,
        ), static fn(string $path): bool => $path !== ''));
    }

    /**
     * Point the two modules that still hold per-environment state at this
     * environment: `LegacyDbService` adopts the compile half's connection —
     * one environment, one PDO, both halves — and the CKEditor rewriter gets
     * an asset resolver bound to this environment's media roots.
     *
     * The rewriter's lookup caches are dropped here rather than by each caller,
     * because they are keyed on bare legacy ids and a legacy id is only unique
     * within one database. Three of this plugin's four paths through the
     * rewriter repointed the connection and kept the caches: COM's
     * `kuma_media` 412 stayed cached and DE's 412 — a different file — resolved
     * to COM's asset, and a miss under one environment was cached as a miss for
     * the other two. Two callers remembered to reset and the entry-load pass,
     * which runs the rewriter for every rich-text field of every environment,
     * did not. Switching the database and forgetting the caches is now
     * unexpressible: this is the one place that switches it.
     *
     * The rewriter is the last module the environment is written onto rather
     * than handed to. Its entry points take HTML and a site, and every private
     * lookup behind them reads the connection and the resolver off the
     * instance; threading the environment through them is the follow-up this
     * one place keeps contained.
     */
    public static function adoptEnvironment(EnvironmentContext $context, MigrationOptions $opts): void
    {
        $plugin = Plugin::getInstance();

        if ($plugin === null || $context->legacy === null) {
            return;
        }

        $plugin->legacyDbService->usePdo($context->legacy->pdo());
        $plugin->ckeditorRewriterService->resetLookupCaches();
        $plugin->ckeditorRewriterService->assetResolver = $plugin->assetMigrationService->resolverFor($context, $opts);
    }
}
