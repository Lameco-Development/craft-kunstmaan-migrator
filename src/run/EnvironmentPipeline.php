<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\run;

use Craft;
use craft\elements\Entry;
use craft\helpers\App;
use Lameco\KumaCompile\Compile\Compiler;
use Lameco\KumaCompile\Compile\PayloadWriter;
use Lameco\KumaCompile\Compile\Transforms;
use Lameco\KumaCompile\Legacy\Dsn;
use Lameco\KumaCompile\Legacy\LegacyDatabase;
use Lameco\KumaCompile\Mapping\Mapping;
use Lameco\Kunstmaanmigrator\adapters\AdapterRegistry;
use Lameco\Kunstmaanmigrator\compile\TargetModel;
use Lameco\Kunstmaanmigrator\load\MigrationOptions;
use Lameco\Kunstmaanmigrator\load\MigrationReport;
use Lameco\Kunstmaanmigrator\payload\CraftSchemaGateway;
use Lameco\Kunstmaanmigrator\payload\Payload;
use Lameco\Kunstmaanmigrator\payload\PayloadEntrySaver;
use Lameco\Kunstmaanmigrator\payload\PayloadValidator;
use Lameco\Kunstmaanmigrator\Plugin;
use Lameco\Kunstmaanmigrator\sites\SiteMap;
use yii\db\Connection;

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
        private readonly Plugin $plugin,
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
            $plugin,
            new PayloadValidator($gateway),
            $settings->dryRun ? null : new PayloadEntrySaver(
                $gateway,
                $plugin->entryMigrationService,
                $plugin->migrationStateService,
                $plugin->assetMigrationService,
                $plugin->ckeditorRewriterService,
                null,
                $settings->force,
            ),
            new Compiler($mapping, $transforms, new TargetModel($gateway), $settings->only),
            $transforms,
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
        [$db, $siteMap] = $this->prepare($mapping, $env, $spec, $settings);

        $this->compiler->compile(
            $db,
            $env,
            function(array $raw) use ($settings, $tally, $writer): void {
                $this->handlePayload($raw, $settings, $tally, $writer);
            },
            $settings->limit,
        );

        if (!$settings->entriesOnly) {
            $tally->adapters[$env] = $this->runAdapters(
                new EnvironmentContext(
                    name: $env,
                    database: (string) $spec['database'],
                    sites: $siteMap,
                    mediaRoots: self::mediaRootsFrom($spec),
                    mapping: $mapping,
                    legacy: $db,
                    only: $settings->only,
                ),
                $settings,
            );
        }
    }

    /**
     * Point every shared component at one environment — connection, site map,
     * media roots, asset flags — and open the legacy database.
     *
     * Extracted from `run()` for the batched queue path (#48): a batch job
     * rebuilds this in every process, compiles a window, and lets the process
     * end. Both callers prepare identically or the halves drift again.
     *
     * @param array<string, mixed> $spec the mapping's block for this environment
     * @return array{0: LegacyDatabase, 1: SiteMap}
     */
    public function prepare(Mapping $mapping, string $env, array $spec, RunSettings $settings): array
    {
        $dsn = self::dsnFromSettings();
        $db = LegacyDatabase::connect($env, (string) $spec['database'], $dsn);

        // The adapters and the media-token rewriter read the legacy database through
        // LegacyDbService. Three environments are three databases, so it is repointed
        // per environment — without this a DE run reads COM's tables and reports them
        // as migrated. It adopts the SAME connection the compiler just opened: one
        // environment, one PDO, both halves.
        self::adoptLegacyDb($db);

        // Locale → site is per environment, not global. COM's `en` is comEnUs while LV's is
        // comLvEn, and one global map cannot hold both. The mapping states it per
        // environment, so it is the only source.
        $siteMap = SiteMap::bind(
            (array) ($spec['locales'] ?? []),
            Craft::$app->sites->getAllSites(),
        );

        $this->plugin->entryMigrationService->sites = $siteMap->configured();

        self::applyMediaRoots($spec, $env, count($mapping->environments()) > 1);

        // The JIT entry points build their own MigrationOptions, so the flag has to live on
        // the service to reach them.
        $this->plugin->assetMigrationService->skipAssets = $settings->skipAssets;

        return [$db, $siteMap];
    }

    /**
     * One payload, validated and saved — the public face of `handlePayload`,
     * for the batched job whose processItem() is exactly this.
     *
     * @param array<string, mixed> $raw
     */
    public function processOne(array $raw, RunSettings $settings, RunTally $tally, ?PayloadWriter $writer = null): void
    {
        $this->handlePayload($raw, $settings, $tally, $writer);
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
    private function handlePayload(array $raw, RunSettings $settings, RunTally $tally, ?PayloadWriter $writer): void
    {
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
            $result = $this->saver->save($payload);
            $tally->count($result->created ? 'created' : ($settings->force ? 'updated' : 'skipped'));

            foreach ($result->unresolvedAssets as $unresolved) {
                $tally->unresolvedAsset((string) ($unresolved['asset'] ?? '?'));
            }

            foreach ($result->droppedAddresses as $dropped) {
                $tally->droppedAddress((string) $dropped['field'], (string) $dropped['site']);
            }
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
        $opts = new MigrationOptions(
            dryRun: $settings->dryRun,
            force: $settings->force,
            skipAssets: $settings->skipAssets,
        );
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
     * @param callable(): MigrationReport $run
     * @return array<string, mixed>
     */
    /**
     * An adapter that throws does not take the run with it: the others have already written
     * what they could, and a stack trace here would hide the counts for all of them.
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
     * Point the asset service at one environment's uploads directories.
     *
     * Each legacy site has its own uploads directory, so the media root travels
     * with the environment rather than being one global setting. The mapping
     * accepts either a single path or an ordered list — Enreach's DE
     * environment looks in its own directory first and falls back to COM's.
     *
     * Static and shared because the finalize pass needs the same roots for the
     * same reason: resolving `/uploads/media/...` to an asset ingests the file
     * from these directories when no payload pulled it in. It ran without them
     * and rewrote 24 of 177 image references.
     *
     * @param array<string, mixed> $spec the mapping's block for this environment
     * @param ?string $env  the environment's name, which `legacy-tree` roots its folders under
     * @param bool $prefixEnvironment whether the corpus has more than one source
     */
    public static function applyMediaRoots(array $spec, ?string $env = null, bool $prefixEnvironment = false): void
    {
        $roots = self::mediaRootsFrom($spec);

        $assets = Plugin::getInstance()?->assetMigrationService;

        if ($assets === null) {
            return;
        }

        $assets->legacyMediaRoot = $roots[0] ?? null;
        $assets->legacyMediaFallbackRoots = array_slice($roots, 1);
        $assets->environmentName = $env;
        $assets->prefixEnvironment = $prefixEnvironment;
    }

    /**
     * One environment's uploads directories, with `$VAR` and Craft aliases expanded.
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
     * Point Craft's `legacyDb` component at one environment's database.
     *
     * Overwrites the registration rather than the instance, so the next
     * `Craft::$app->get('legacyDb')` builds a fresh connection.
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
     */
    public static function pointLegacyDbAt(Dsn $dsn, string $database): void
    {
        self::adoptLegacyDb(LegacyDatabase::connect($database, $database, $dsn));
    }

    /**
     * Hand LegacyDbService the compile half's connection — one environment,
     * one PDO, both halves — and drop the rewriter caches: they are keyed on
     * bare legacy ids, which only mean something inside one database.
     */
    public static function adoptLegacyDb(LegacyDatabase $db): void
    {
        $plugin = Plugin::getInstance();
        $plugin?->legacyDbService->usePdo($db->pdo());
        $plugin?->ckeditorRewriterService->resetLookupCaches();
    }
}
