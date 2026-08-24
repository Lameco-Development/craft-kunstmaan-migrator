<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\Command;

use Lameco\Kunstmaanmigrator\Mapping\Mapping;
use Lameco\Kunstmaanmigrator\Mapping\MappingCheck;
use Lameco\Kunstmaanmigrator\Source\Introspection;
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
    description: 'Check a mapping is well-formed, without touching a database',
)]
/**
 * Thin renderer over `Mapping\MappingCheck` — the same engine
 * `./craft kunstmaan-migrator/mapping/check`, the migrate preflight and the
 * CP Check button ask. This one answers from `config/project/**` on disk
 * (`--craft`) instead of the live schema gateway, so it runs before a Craft
 * install exists; without `--craft` the verdict covers what is checkable —
 * shape and conflicts.
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

        $check = new MappingCheck($craftRoot !== null ? CraftSchema::fromProjectConfig((string) $craftRoot) : null);
        $specNotes = array_map(static fn($dir): SpecNotes => SpecNotes::fromDirectory((string) $dir), $specDirs);

        $artifact = $input->getOption('introspection');
        $introspection = $artifact !== null ? Introspection::fromFile((string) $artifact) : null;

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
