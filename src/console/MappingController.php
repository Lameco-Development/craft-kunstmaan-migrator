<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\console;

use craft\console\Controller;
use craft\helpers\Console;
use Lameco\KumaCompile\Mapping\Mapping;
use Lameco\KumaCompile\Mapping\MappingCheck;
use Lameco\KumaCompile\Mapping\MappingException;
use Lameco\KumaCompile\Mapping\MappingInit;
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
 * install is worth keeping. Both surfaces are thin adapters over
 * `Mapping\MappingInit` — same grammar, same skeleton, same refusals; only
 * option syntax and the DSN source differ.
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

    /**
     * Introspection artifact from `kuma-compile introspect` — exact entity
     * tables and child-collection ownership from booted Doctrine metadata,
     * instead of the static --source scan. Wins over --source when both are given.
     */
    public ?string $introspection = null;

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
            'init' => ['environments', 'source', 'introspection', 'out'],
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
        try {
            $databases = MappingInit::parsePairs(
                array_values(array_filter(array_map(trim(...), explode(',', $this->environments)))),
            );
        } catch (MappingException $e) {
            $this->stderr(sprintf("--environments %s\n", $e->getMessage()), Console::FG_RED);

            return ExitCode::USAGE;
        }

        if ($databases === []) {
            $this->stderr("At least one --environments=NAME=database is required.\n", Console::FG_RED);

            return ExitCode::USAGE;
        }

        try {
            $connections = MappingInit::connect($databases, EnvironmentPipeline::dsnFromSettings());
            $result = MappingInit::skeleton($connections, $this->source, $this->introspection);
        } catch (Throwable $e) {
            $this->stderr($e->getMessage() . "\n", Console::FG_RED);

            return ExitCode::UNAVAILABLE;
        }

        if ($result->tablesUnresolved) {
            $this->stderr(
                "No --introspection or --source given: table names are left as TODO. Pass the artifact or the Kunstmaan checkout to fill them in.\n",
                Console::FG_YELLOW,
            );
        }

        if ($this->out === null) {
            $this->stdout($result->yaml);

            return ExitCode::OK;
        }

        try {
            MappingInit::write($this->out, $result->yaml);
        } catch (MappingException $e) {
            $this->stderr($e->getMessage() . "\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

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
