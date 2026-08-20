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
use lameco\kunstmaanmigrator\payload\CraftSchemaGateway;
use lameco\kunstmaanmigrator\payload\Payload;
use lameco\kunstmaanmigrator\payload\PayloadEntrySaver;
use lameco\kunstmaanmigrator\load\MigrationOptions;
use lameco\kunstmaanmigrator\load\MigrationReport;
use lameco\kunstmaanmigrator\load\RedirectMigrationService;
use lameco\kunstmaanmigrator\payload\PayloadValidator;
use lameco\kunstmaanmigrator\payload\RefResolver;
use lameco\kunstmaanmigrator\Plugin;
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
     * Compile only these page entities / `entities:` names, comma separated.
     *
     * A full corpus takes over an hour, which is the wrong feedback loop for a fix that
     * touches one page type: `--only=PartnerPage --legacyEnv=DE --entriesOnly` is the same
     * code path over 423 rows instead of 2,000, and answers in a minute.
     */
    public ?string $only = null;

    public function options($actionID): array
    {
        return array_merge(
            parent::options($actionID),
            ['mapping', 'legacyEnv', 'limit', 'force', 'dryRun', 'dump', 'specs', 'entriesOnly', 'only'],
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

        $transforms = new Transforms($mapping->all()['transforms'] ?? []);
        $compiler = new Compiler($mapping, $transforms, $target, $only);
        $plugin = Plugin::getInstance();
        $validator = new PayloadValidator($gateway);

        $saver = $this->dryRun ? null : new PayloadEntrySaver(
            $gateway,
            $plugin->entryMigrationService,
            $plugin->migrationStateService,
            $plugin->assetMigrationService,
            $plugin->ckeditorRewriterService,
            null,
            $this->force,
        );

        $counts = ['compiled' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'invalid' => 0, 'failed' => 0];
        $problems = [];
        $unresolvedAssets = [];
        $droppedAddresses = [];
        $adapters = [];

        foreach ($mapping->environments() as $env => $spec) {
            if ($this->legacyEnv !== null && $env !== $this->legacyEnv) {
                continue;
            }

            $dsn = Dsn::fromEnvironment();
            $db = LegacyDatabase::connect((string) $env, (string) $spec['database'], $dsn);

            // The adapters and the media-token rewriter read the legacy database through
            // Craft's `legacyDb` component, which is one connection from one setting. Three
            // environments are three databases, so it is repointed per environment — without
            // this a DE run reads COM's tables and reports them as migrated.
            $this->applyLegacyDb($dsn, (string) $spec['database']);

            // Each legacy site has its own uploads directory, so the media root travels with
            // the environment rather than being one global setting.
            // Locale -> site is per environment, not global. COM's `en` is comEnUs while
            // LV's is comLvEn, and one global map cannot hold both: with COM's configured,
            // every LV entry failed with "unknown site handle comLvEn". The mapping already
            // states this per environment, so it is the only source.
            $this->applySites($plugin, (array) ($spec['locales'] ?? []));

            $roots = $spec['mediaRoot'] ?? null;
            $roots = is_array($roots) ? array_values($roots) : ($roots === null ? [] : [(string) $roots]);
            $plugin->assetMigrationService->legacyMediaRoot = $roots[0] ?? null;
            $plugin->assetMigrationService->legacyMediaFallbackRoots = array_slice($roots, 1);
            $writer = $this->dump !== null ? $this->writerFor((string) $env) : null;

            $compiler->compile($db, (string) $env, function (array $raw) use (
                $validator, $saver, $writer, &$counts, &$problems, &$unresolvedAssets, &$droppedAddresses
            ): void {
                $counts['compiled']++;
                $writer?->write($raw);

                $payload = Payload::fromArray($raw);
                $violations = $validator->validate($payload);

                if ($violations !== []) {
                    $counts['invalid']++;

                    foreach ($violations as $v) {
                        $problems[] = sprintf('%s %s', $v->code, $v->message);
                        $this->reportProblem(sprintf('%s %s', $v->code, $v->message));
                    }

                    return;
                }

                if ($saver === null) {
                    return;
                }

                try {
                    $result = $saver->save($payload);
                    $counts[$result->created ? 'created' : ($this->force ? 'updated' : 'skipped')]++;

                    foreach ($result->unresolvedAssets as $unresolved) {
                        $unresolvedAssets[] = (string) ($unresolved['asset'] ?? '?');
                    }

                    foreach ($result->droppedAddresses as $dropped) {
                        $key = sprintf('%s on %s', $dropped['field'], $dropped['site']);
                        $droppedAddresses[$key] = ($droppedAddresses[$key] ?? 0) + 1;
                    }
                } catch (\Throwable $e) {
                    $counts['failed']++;
                    $problems[] = sprintf('%s: %s', $payload->sourceUid, $e->getMessage());
                    $this->reportProblem(sprintf('%s: %s', $payload->sourceUid, $e->getMessage()));
                }
            }, $this->limit);

            if (!$this->entriesOnly) {
                $adapters[(string) $env] = $this->runAdapters($plugin, $db, (string) $env, new RedirectCompiler($mapping, $only));
            }
        }

        $this->stdout(json_encode([
            'counts' => $counts,
            'lossyConversions' => $transforms->lossCount(),
            'losses' => $transforms->losses(),
            'skippedSources' => $compiler->skipped(),
            'droppedAddresses' => $droppedAddresses,
            'unresolvedAssets' => count($unresolvedAssets),
            'unresolvedAssetSample' => array_slice(array_unique($unresolvedAssets), 0, 5),
            'problems' => array_slice($problems, 0, 40),
            'only' => $only,
            'adapters' => $adapters,
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);

        return $counts['failed'] > 0 || $counts['invalid'] > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /**
     * Everything that is not an entry: SEO meta, redirects, navigation, translations.
     *
     * These four were configured in `Plugin::init()` and called by nothing — four services,
     * ~2,700 lines, wired to sibling dependencies and a per-environment site map, with no
     * caller anywhere in the plugin. They run here, after the entries of this environment
     * exist, because every one of them resolves a legacy id to an entry that has to be there
     * already.
     *
     * @return array<string, array<string, mixed>>
     */
    private function runAdapters(
        Plugin $plugin,
        LegacyDatabase $db,
        string $env,
        RedirectCompiler $redirects,
    ): array {
        $opts = new MigrationOptions(dryRun: $this->dryRun, force: $this->force);

        $out = [
            'seo' => $this->summarise(fn (): MigrationReport => $plugin->seoMigrationService->migrateAll($opts)),
            'redirects' => $this->loadRedirects($plugin, $db, $env, $redirects),
            'navigation' => $this->summarise(fn (): MigrationReport => $plugin->navigationMigrationService->migrateAll($opts)),
            'translations' => $this->summarise(fn (): MigrationReport => $plugin->translationMigrationService->migrateAll($opts)),
        ];

        return $out;
    }

    /**
     * Compile this environment's `redirects:` lane and hand it straight to the loader.
     *
     * `load/redirects` has read a payload file since before there was a compiler, and nothing
     * ever wrote one. Rather than reintroduce the file as a contract between the two halves,
     * the compiled records go to the same reporting code the file path uses.
     *
     * @return array<string, mixed>
     */
    private function loadRedirects(
        Plugin $plugin,
        LegacyDatabase $db,
        string $env,
        RedirectCompiler $compiler,
    ): array {
        $records = [];
        $compiler->compile($db, $env, static function (array $record) use (&$records): void {
            $records[] = $record;
        });

        if ($records === []) {
            return ['compiled' => 0, 'skipped' => $compiler->skipped()];
        }

        if ($this->dryRun) {
            return ['compiled' => count($records), 'loaded' => false, 'skipped' => $compiler->skipped()];
        }

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

    /**
     * One adapter's outcome, reduced to what an operator reads.
     *
     * An adapter that throws does not take the run with it: three of the four have already
     * written what they could, and a stack trace here would hide the counts for all of them.
     *
     * @param callable(): MigrationReport $run
     * @return array<string, mixed>
     */
    private function summarise(callable $run): array
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

    /**
     * Point every migration service at this environment's locale → site map.
     *
     * Entries were switched per environment while SEO, redirects, navigation and translations
     * kept whatever a global setting had left them with — so a DE or LV run wrote those against
     * COM's sites. One environment, one map, all five services.
     *
     * @param array<string, mixed> $locales
     */
    private function applySites(Plugin $plugin, array $locales): void
    {
        $sites = array_filter(
            array_map(static fn ($handle): string => is_string($handle) ? $handle : '', $locales),
            static fn (string $handle): bool => $handle !== '',
        );

        $plugin->entryMigrationService->sites = $sites;
        $plugin->seoMigrationService->sites = $sites;
        $plugin->redirectMigrationService->sites = $sites;
        $plugin->navigationMigrationService->sites = $sites;
        $plugin->translationMigrationService->sites = $sites;
    }
}
