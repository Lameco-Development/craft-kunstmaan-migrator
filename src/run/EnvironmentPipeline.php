<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\run;

use Craft;
use craft\elements\Entry;
use craft\helpers\App;
use Lameco\KumaCompile\Compile\Compiler;
use Lameco\KumaCompile\Compile\PayloadWriter;
use Lameco\KumaCompile\Compile\RedirectCompiler;
use Lameco\KumaCompile\Compile\Transforms;
use Lameco\KumaCompile\Legacy\Dsn;
use Lameco\KumaCompile\Legacy\LegacyDatabase;
use Lameco\KumaCompile\Mapping\Mapping;
use lameco\kunstmaanmigrator\load\MigrationOptions;
use lameco\kunstmaanmigrator\load\MigrationReport;
use lameco\kunstmaanmigrator\payload\Payload;
use lameco\kunstmaanmigrator\payload\PayloadEntrySaver;
use lameco\kunstmaanmigrator\compile\TargetModel;
use lameco\kunstmaanmigrator\payload\CraftSchemaGateway;
use lameco\kunstmaanmigrator\payload\PayloadValidator;
use lameco\kunstmaanmigrator\Plugin;
use lameco\kunstmaanmigrator\console\LoadController;
use lameco\kunstmaanmigrator\load\RedirectMigrationService;
use lameco\kunstmaanmigrator\payload\RefResolver;
use lameco\kunstmaanmigrator\sites\SiteMap;
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
        $settings = Plugin::getInstance()->getSettings();

        return new Dsn(
            host: (string) (App::parseEnv($settings->legacyDbServer) ?: '127.0.0.1'),
            port: $settings->legacyDbPort,
            user: (string) (App::parseEnv($settings->legacyDbUser) ?: 'root'),
            password: (string) App::parseEnv($settings->legacyDbPassword),
            charset: (string) (App::parseEnv($settings->legacyDbCharset) ?: 'utf8mb4'),
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
        $dsn = self::dsnFromSettings();
        $db = LegacyDatabase::connect($env, (string) $spec['database'], $dsn);

        // The adapters and the media-token rewriter read the legacy database through
        // Craft's `legacyDb` component, which is one connection from one setting. Three
        // environments are three databases, so it is repointed per environment — without
        // this a DE run reads COM's tables and reports them as migrated.
        self::pointLegacyDbAt($dsn, (string) $spec['database']);

        // Locale → site is per environment, not global. COM's `en` is comEnUs while LV's is
        // comLvEn, and one global map cannot hold both. The mapping states it per
        // environment, so it is the only source.
        $siteMap = SiteMap::bind(
            (array) ($spec['locales'] ?? []),
            Craft::$app->sites->getAllSites(),
        );

        $this->plugin->navigationMigrationService->environment = $env;
        $this->plugin->entryMigrationService->sites = $siteMap->configured();

        // Each legacy site has its own uploads directory, so the media root travels with
        // the environment rather than being one global setting.
        $roots = $spec['mediaRoot'] ?? null;
        $roots = is_array($roots) ? array_values($roots) : ($roots === null ? [] : [(string) $roots]);
        $this->plugin->assetMigrationService->legacyMediaRoot = $roots[0] ?? null;
        $this->plugin->assetMigrationService->legacyMediaFallbackRoots = array_slice($roots, 1);

        $this->compiler->compile(
            $db,
            $env,
            function (array $raw) use ($settings, $tally, $writer): void {
                $this->handlePayload($raw, $settings, $tally, $writer);
            },
            $settings->limit,
        );

        if (!$settings->entriesOnly) {
            $tally->adapters[$env] = $this->runAdapters(
                $db,
                $env,
                $siteMap,
                $settings,
                new RedirectCompiler($mapping, $settings->only),
            );
        }
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
     * The four adapters, after the entries of this environment exist — every one of them
     * resolves a legacy id to an entry that has to be there already.
     *
     * @return array<string, mixed>
     */
    private function runAdapters(LegacyDatabase $db, string $env, SiteMap $siteMap, RunSettings $settings, RedirectCompiler $redirects): array
    {
        $opts = new MigrationOptions(dryRun: $settings->dryRun, force: $settings->force);

        return [
            'seo' => self::summarise(
                fn (): MigrationReport => $this->plugin->seoMigrationService->migrateAll($opts, $siteMap),
            ),
            'redirects' => $this->loadRedirects($db, $env, $settings, $redirects),
            'navigation' => self::summarise(
                fn (): MigrationReport => $this->plugin->navigationMigrationService->migrateAll($opts, $siteMap),
            ),
            'translations' => self::summarise(
                fn (): MigrationReport => $this->plugin->translationMigrationService->migrateAll($opts, $siteMap),
            ),
        ];
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
     * Point Craft's `legacyDb` component at one environment's database.
     *
     * Overwrites the registration rather than the instance, so the next
     * `Craft::$app->get('legacyDb')` builds a fresh connection.
     */
    public static function pointLegacyDbAt(Dsn $dsn, string $database): void
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
     * `load/redirects` has read a payload file since before there was a compiler, and nothing
     * ever wrote one — so this compiles the environment's `redirects:` lane and hands it
     * straight to the loader.
     *
     * @return array<string, mixed>
     */
    private function loadRedirects(
        LegacyDatabase $db,
        string $env,
        RunSettings $settings,
        RedirectCompiler $compiler,
    ): array {
        $records = [];
        $compiler->compile($db, $env, static function (array $record) use (&$records): void {
            $records[] = $record;
        });

        if ($records === []) {
            return ['compiled' => 0, 'skipped' => $compiler->skipped()];
        }

        if ($settings->dryRun) {
            return ['compiled' => count($records), 'loaded' => false, 'skipped' => $compiler->skipped()];
        }

        $plugin = $this->plugin;

        $report = LoadController::reportForRedirects(
            $records,
            new RefResolver($plugin->migrationStateService),
            RedirectMigrationService::isRetourAvailable(),
            static function (int $entryId, string $siteHandle): ?string {
                $site = Craft::$app->getSites()->getSiteByHandle($siteHandle);

                if ($site === null) {
                    return null;
                }

                $entry = Entry::find()->id($entryId)->siteId((int) $site->id)->status(null)->one();

                return $entry === null || $entry->uri === null ? null : '/' . ltrim($entry->uri, '/');
            },
            static function (string $from, string $to, int $code, string $key, array $meta) use ($plugin): array {
                $result = $plugin->redirectMigrationService->importOne($from, $to, $code, $key, $meta);

                if (($result->counts['created'] ?? 0) > 0) {
                    return ['outcome' => 'created'];
                }

                if (($result->counts['updated'] ?? 0) > 0) {
                    return ['outcome' => 'updated'];
                }

                return ['outcome' => 'failed', 'message' => $result->warnings[0] ?? 'Retour refused to save the redirect.'];
            },
        );

        // The per-record report is only interesting when something went wrong; a clean run of
        // 156 redirects should not push 156 lines through a summary.
        $report['report'] = array_slice($report['report'], 0, 10);
        $report['skipped'] = $compiler->skipped();

        return $report;
    }
}
