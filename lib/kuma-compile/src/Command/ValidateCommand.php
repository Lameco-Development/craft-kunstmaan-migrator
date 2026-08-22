<?php

declare(strict_types=1);

namespace Lameco\KumaCompile\Command;

use Lameco\KumaCompile\Mapping\Mapping;
use Lameco\KumaCompile\Mapping\Schema;
use Lameco\KumaCompile\Report\SpecDivergence;
use Lameco\KumaCompile\Target\CraftSchema;
use Lameco\KumaCompile\Target\TargetSchema;
use Lameco\KumaCompile\Target\SpecNotes;
use Lameco\KumaCompile\Target\TargetCheck;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'validate',
    description: 'Check a mapping is well-formed, without touching a database',
)]
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
                . 'give a source for that the mapping does not fill (repeatable)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $mapping = Mapping::fromFile((string) $input->getArgument('mapping'));
        $errors = (new Schema())->validate($mapping);
        $warnings = [];

        $specDirs = (array) $input->getOption('specs');

        if ($craftRoot = $input->getOption('craft')) {
            $schema = CraftSchema::fromProjectConfig((string) $craftRoot);
            $target = new TargetCheck($schema);
            $errors = [...$errors, ...$target->check($mapping), ...$target->blocksNoPageAccepts($mapping)];
            $warnings = $target->unfilledRequired($mapping);

            foreach ($specDirs as $dir) {
                $divergence = new SpecDivergence($mapping, SpecNotes::fromDirectory((string) $dir), $schema);
                $errors = [...$errors, ...$divergence->divergences()];
            }
        } elseif ($specDirs !== []) {
            $io->error('--specs needs --craft: the built content model is what says which of the spec\'s fields exist.');

            return Command::INVALID;
        }

        $unreasoned = $mapping->unreasonedIgnores();

        if ($errors === [] && $warnings === [] && $unreasoned === []) {
            $io->success(sprintf('%s is well-formed.', $mapping->path));

            return Command::SUCCESS;
        }

        if ($unreasoned !== []) {
            $columns = array_sum(array_map('count', $unreasoned));
            $io->section(sprintf('%d columns ignored without a reason, across %d subjects', $columns, count($unreasoned)));
            $io->writeln('  The list form of `ignore:` records that a column is not migrated, but not that anyone');
            $io->writeln('  decided so — it is also what the generator used to emit. Give each a reason to tell the');
            $io->writeln('  two apart: <info>ignore: {column: "why"}</info>. Run with -v to list them.');

            if ($output->isVerbose()) {
                foreach ($unreasoned as $subject => $cols) {
                    $io->writeln(sprintf('  <comment>·</comment> %s: %s', $subject, implode(', ', $cols)));
                }
            }
        }

        if ($warnings !== []) {
            $io->section(sprintf('%d required fields never mapped', count($warnings)));

            foreach ($warnings as $warning) {
                $io->writeln('  <comment>·</comment> ' . $warning);
            }
        }

        if ($errors === []) {
            $io->success(sprintf('%s is well-formed; see the warnings above.', $mapping->path));

            return Command::SUCCESS;
        }

        $io->section(sprintf('%d problems', count($errors)));

        foreach ($errors as $error) {
            $io->writeln('  <error>·</error> ' . $error);
        }

        $io->error('Mapping does not match the target.');

        return Command::FAILURE;
    }
}
