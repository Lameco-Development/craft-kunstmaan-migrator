<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\console;

use craft\console\Controller;
use craft\helpers\Console;
use Lameco\KumaCompile\Legacy\EntityTableIndex;
use Lameco\KumaCompile\Legacy\LegacyDatabase;
use Lameco\KumaCompile\Mapping\Mapping;
use Lameco\KumaCompile\Mapping\MappingCheck;
use Lameco\KumaCompile\Mapping\Skeleton;
use Lameco\Kunstmaanmigrator\compile\TargetModel;
use Lameco\Kunstmaanmigrator\payload\CraftSchemaGateway;
use Lameco\Kunstmaanmigrator\run\EnvironmentPipeline;
use Lameco\Kunstmaanmigrator\safety\NeverProductionTrait;
use Throwable;
use yii\console\ExitCode;

/**
 * Making a mapping, and checking one.
 *
 * The engine for this has existed since the DSL did, as a second binary
 * shipped inside the plugin — `php vendor/lameco/craft-kunstmaan-migrator/lib/
 * kuma-compile/bin/kuma-compile init`. A plugin you install and then have to
 * find a vendored CLI inside is not a plugin you can hand to somebody, so the
 * two commands that start a migration are Craft commands now.
 *
 * The binary stays: the compile half genuinely runs without Craft, and being
 * able to point it at a legacy database from a machine that has no Craft
 * install is worth keeping.
 */
final class MappingController extends Controller
{
    use NeverProductionTrait;

    private ?int $neverProductionExitCode = null;

    /**
     * Legacy environments as `NAME=database`, comma separated.
     *
     * A Kunstmaan corpus is routinely several databases — Enreach is three —
     * and which locales each publishes is a per-database fact, so discovery
     * has to see all of them at once to write the environments block.
     */
    public string $environments = '';

    /** The Kunstmaan checkout, so entity classes can be resolved to real table names. */
    public ?string $source = null;

    /** Where to write. Prints to stdout when omitted. */
    public ?string $out = null;

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
        return array_merge(parent::options($actionID), match ($actionID) {
            'init' => ['environments', 'source', 'out'],
            default => [],
        });
    }

    /**
     * Discover a legacy corpus and write a mapping skeleton for it.
     *
     * Deterministic: everything in the output is read from the databases — the
     * pagepart classes and page types ordered by live volume, their real table
     * names, each part's unplaced columns, child collections with their foreign
     * keys, and every locale with its live page count. What it deliberately
     * does not do is guess a target. A generator that filled in `block:` would
     * be inventing the one decision a human is here to make, and the file would
     * look finished while being wrong.
     */
    public function actionInit(): int
    {
        $databases = [];

        foreach (array_filter(array_map(trim(...), explode(',', $this->environments))) as $pair) {
            if (!str_contains($pair, '=')) {
                $this->stderr(sprintf("--environments expects NAME=database, got `%s`\n", $pair), Console::FG_RED);

                return ExitCode::USAGE;
            }

            [$name, $database] = explode('=', $pair, 2);

            try {
                $databases[$name] = LegacyDatabase::connect($name, $database, EnvironmentPipeline::dsnFromSettings());
            } catch (Throwable $e) {
                $this->stderr(sprintf("Cannot reach %s (%s): %s\n", $name, $database, $e->getMessage()), Console::FG_RED);

                return ExitCode::UNAVAILABLE;
            }
        }

        if ($databases === []) {
            $this->stderr("At least one --environments=NAME=database is required.\n", Console::FG_RED);

            return ExitCode::USAGE;
        }

        $entities = $this->source !== null
            ? EntityTableIndex::fromSource($this->source)
            : EntityTableIndex::empty();

        if ($entities->isEmpty()) {
            $this->stderr(
                "No --source given: table names are left as TODO. Pass the Kunstmaan checkout to fill them in.\n",
                Console::FG_YELLOW,
            );
        }

        $yaml = (new Skeleton($entities))->generate($databases);

        if ($this->out === null) {
            $this->stdout($yaml);

            return ExitCode::OK;
        }

        // Refusing rather than overwriting: the mapping is the migration, and
        // an accidental `init` over a finished one is hours of decisions gone.
        if (is_file($this->out)) {
            $this->stderr(sprintf("%s already exists — refusing to overwrite a mapping.\n", $this->out), Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        @mkdir(dirname($this->out), 0o775, true);
        file_put_contents($this->out, $yaml);

        $this->stdout(sprintf("Wrote %s\n", $this->out), Console::FG_GREEN);
        $this->stdout("Next: fill it in — Utilities → Kunstmaan Migrator, or the file directly — then `mapping/check`.\n");

        return ExitCode::OK;
    }

    /**
     * Whether a mapping is well-formed, and whether this Craft install can
     * receive it.
     *
     * Two questions in one command because they are always asked together and
     * the order matters: a mapping that is not well-formed produces misleading
     * target errors, so the shape is checked first. `migrate` runs exactly
     * these before it writes anything; this is how you ask without starting a
     * migration.
     *
     * @param string $path the mapping to check
     */
    public function actionCheck(string $path): int
    {
        if (!is_file($path)) {
            $this->stderr(sprintf("Mapping file not found: %s\n", $path), Console::FG_RED);

            return ExitCode::USAGE;
        }

        try {
            $mapping = Mapping::fromFile($path);
        } catch (Throwable $e) {
            $this->stderr(sprintf("Mapping is unreadable: %s\n", $e->getMessage()), Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $verdict = (new MappingCheck(new TargetModel(new CraftSchemaGateway())))->verdict($mapping);

        if ($verdict !== null) {
            return $this->report($verdict[0], $verdict[1]);
        }

        $this->stdout(sprintf(
            "%s is well-formed and matches this install: %d page types, %d parts, %d entities.\n",
            $path,
            count($mapping->pages()),
            count($mapping->parts()),
            count($mapping->entities()),
        ), Console::FG_GREEN);

        return ExitCode::OK;
    }

    /** @param list<string> $errors */
    private function report(string $headline, array $errors): int
    {
        $this->stderr($headline . ":\n", Console::FG_RED);

        foreach (array_slice($errors, 0, 40) as $error) {
            $this->stderr('  · ' . $error . "\n");
        }

        if (count($errors) > 40) {
            $this->stderr(sprintf("  … and %d more\n", count($errors) - 40));
        }

        return ExitCode::UNSPECIFIED_ERROR;
    }
}
