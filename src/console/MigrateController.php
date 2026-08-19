<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\console;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use lameco\kunstmaanmigrator\compile\Compiler;
use lameco\kunstmaanmigrator\compile\PayloadWriter;
use lameco\kunstmaanmigrator\compile\TargetCheck;
use lameco\kunstmaanmigrator\compile\TargetModel;
use lameco\kunstmaanmigrator\compile\Transforms;
use lameco\kunstmaanmigrator\legacy\Dsn;
use lameco\kunstmaanmigrator\legacy\LegacyDatabase;
use lameco\kunstmaanmigrator\mapping\Mapping;
use lameco\kunstmaanmigrator\mapping\Schema;
use lameco\kunstmaanmigrator\payload\CraftSchemaGateway;
use lameco\kunstmaanmigrator\payload\Payload;
use lameco\kunstmaanmigrator\payload\PayloadEntrySaver;
use lameco\kunstmaanmigrator\payload\PayloadValidator;
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

    /** Stop after this many entries per environment. */
    public ?int $limit = null;

    /** Refresh entries that already exist. */
    public bool $force = false;

    /** Compile and report without writing to Craft. */
    public bool $dryRun = false;

    /** Directory to write the compiled payloads into, for inspection. */
    public ?string $dump = null;

    /** Directory of target block specs, used to check field-map coverage. */
    public ?string $specs = null;

    public function options($actionID): array
    {
        return array_merge(
            parent::options($actionID),
            ['mapping', 'legacyEnv', 'limit', 'force', 'dryRun', 'dump', 'specs'],
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

        $transforms = new Transforms($mapping->all()['transforms'] ?? []);
        $compiler = new Compiler($mapping, $transforms, $target);
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

        foreach ($mapping->environments() as $env => $spec) {
            if ($this->legacyEnv !== null && $env !== $this->legacyEnv) {
                continue;
            }

            $db = LegacyDatabase::connect((string) $env, (string) $spec['database'], Dsn::fromEnvironment());

            // Each legacy site has its own uploads directory, so the media root travels with
            // the environment rather than being one global setting.
            // Locale -> site is per environment, not global. COM's `en` is comEnUs while
            // LV's is comLvEn, and one global localeMap cannot hold both: with COM's map
            // configured, every LV entry failed with "unknown site handle comLvEn". The
            // mapping already states this per environment, so read it from there.
            $plugin->entryMigrationService->sites = array_filter(
                array_map(
                    static fn ($handle): string => is_string($handle) ? $handle : '',
                    (array) ($spec['locales'] ?? []),
                ),
                static fn (string $handle): bool => $handle !== '',
            );

            $roots = $spec['mediaRoot'] ?? null;
            $roots = is_array($roots) ? array_values($roots) : ($roots === null ? [] : [(string) $roots]);
            $plugin->assetMigrationService->legacyMediaRoot = $roots[0] ?? null;
            $plugin->assetMigrationService->legacyMediaFallbackRoots = array_slice($roots, 1);
            $writer = $this->dump !== null ? $this->writerFor((string) $env) : null;

            $compiler->compile($db, (string) $env, function (array $raw) use (
                $validator, $saver, $writer, &$counts, &$problems, &$unresolvedAssets
            ): void {
                $counts['compiled']++;
                $writer?->write($raw);

                $payload = Payload::fromArray($raw);
                $violations = $validator->validate($payload);

                if ($violations !== []) {
                    $counts['invalid']++;

                    foreach ($violations as $v) {
                        $problems[] = sprintf('%s %s', $v->code, $v->message);
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
                } catch (\Throwable $e) {
                    $counts['failed']++;
                    $problems[] = sprintf('%s: %s', $payload->sourceUid, $e->getMessage());
                }
            }, $this->limit);
        }

        $this->stdout(json_encode([
            'counts' => $counts,
            'lossyConversions' => $transforms->lossCount(),
            'losses' => $transforms->losses(),
            'skippedSources' => $compiler->skipped(),
            'unresolvedAssets' => count($unresolvedAssets),
            'unresolvedAssetSample' => array_slice(array_unique($unresolvedAssets), 0, 5),
            'problems' => array_slice($problems, 0, 40),
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);

        return $counts['failed'] > 0 || $counts['invalid'] > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
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
