<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\Command;

use Lameco\Kunstmaanmigrator\Mapping\Mapping;
use Lameco\Kunstmaanmigrator\Mapping\MappingCheck;
use Lameco\Kunstmaanmigrator\Source\Dsn;
use Lameco\Kunstmaanmigrator\Source\EntityTableIndex;
use Lameco\Kunstmaanmigrator\Source\Introspection;
use Lameco\Kunstmaanmigrator\Source\LegacyDatabase;
use Lameco\Kunstmaanmigrator\Source\PartClass;
use Lameco\Kunstmaanmigrator\Target\CraftSchema;
use Lameco\Kunstmaanmigrator\Target\SpecNotes;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'validate',
    description: 'Check a mapping is well-formed — without touching a database unless --live',
)]
/**
 * Thin renderer over `Mapping\MappingCheck` — the same engine
 * `./craft kunstmaan-migrator/mapping/check`, the migrate preflight and the
 * CP Check button ask. This one answers from `config/project/**` on disk
 * (`--craft`) instead of the live schema gateway, so it runs before a Craft
 * install exists; without `--craft` the verdict covers what is checkable —
 * shape and conflicts. `--live` adds the checks that need the corpus: a
 * short-name row reading one table for two live classes that share the name, and a
 * `lookup()` into a table or column the database does not have.
 */
final class ValidateCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('mapping', InputArgument::REQUIRED, 'Path to the mapping YAML')
            ->addOption('craft', null, InputOption::VALUE_REQUIRED,
                'Target Craft project root — also checks every handle the mapping names exists')
            ->addOption('specs', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Directory of content-model specs — fails on any field their migration notes '
                . 'give a source for that the mapping does not fill (repeatable)')
            ->addOption('live', null, InputOption::VALUE_NONE,
                'Read the mapping\'s legacy databases (KUMA_DB_* credentials) — fails on a short-name row '
                . 'that reads one table for several live classes sharing that short name (with --introspection, '
                . 'classes the artifact shows reading the same table are no collision), and on a lookup() '
                . 'into a table or column the database does not have')
            ->addOption('introspection', null, InputOption::VALUE_REQUIRED,
                'Introspection artifact from `introspect` — checks the mapping against the legacy '
                . 'app\'s own wiring: unclaimed ManyToMany joins, editor-facing columns ignored '
                . 'without a reason, mapped columns the entity does not have');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $mapping = Mapping::fromFile((string) $input->getArgument('mapping'));

        $craftRoot = $input->getOption('craft');
        $specDirs = (array) $input->getOption('specs');

        if ($craftRoot === null && $specDirs !== []) {
            $io->error('--specs needs --craft: the built content model is what says which of the spec\'s fields exist.');

            return Command::INVALID;
        }

        $artifact = $input->getOption('introspection');
        $introspection = $artifact !== null ? Introspection::fromFile((string) $artifact) : null;
        $liveParts = null;
        $legacyColumns = null;

        if ($input->getOption('live')) {
            $liveParts = [];
            $legacyColumns = [];
            $lookedUp = array_unique(array_column($mapping->lookups(), 'table'));

            foreach (LegacyDatabase::connectAll($mapping->databases(), Dsn::fromEnvironment()) as $environment => $db) {
                $liveParts = PartClass::tally($liveParts, $db->livePartPlacements());

                // A missing table has no columns — which is how the check reads "missing".
                foreach ($lookedUp as $table) {
                    $legacyColumns[$environment][$table] = $db->columns($table);
                }
            }
        }

        $check = new MappingCheck(
            $craftRoot !== null ? CraftSchema::fromProjectConfig((string) $craftRoot) : null,
            $liveParts,
            // Two classes the artifact shows reading one table are no collision.
            $introspection !== null ? EntityTableIndex::fromIntrospection($introspection) : null,
            $legacyColumns,
        );
        $specNotes = array_map(static fn($dir): SpecNotes => SpecNotes::fromDirectory((string) $dir), $specDirs);

        $verdict = $check->verdict($mapping, ...$specNotes);
        $warnings = $check->warnings($mapping, $introspection);

        if ($warnings !== []) {
            $io->section(sprintf('%d warnings', count($warnings)));

            foreach ($warnings as $warning) {
                $io->writeln('  <comment>·</comment> ' . $warning);
            }
        }

        if ($verdict === null) {
            $io->success(sprintf(
                $warnings === [] ? '%s is well-formed.' : '%s is well-formed; see the warnings above.',
                $mapping->path,
            ));

            return Command::SUCCESS;
        }

        $io->section(sprintf('%s — %d problems', $verdict[0], count($verdict[1])));

        foreach ($verdict[1] as $error) {
            $io->writeln('  <error>·</error> ' . $error);
        }

        $io->error($verdict[0] . '.');

        return Command::FAILURE;
    }
}
