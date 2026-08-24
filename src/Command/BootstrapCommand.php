<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'bootstrap',
    description: 'Start a migration: survey the corpus, introspect the source, generate the mapping skeleton — one command',
)]
final class BootstrapCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('env', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Legacy environment as NAME=database, repeatable (e.g. --env=COM=enreach_website)')
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Legacy checkout')
            ->addOption('dir', null, InputOption::VALUE_REQUIRED,
                'Directory the artifacts land in', 'migration');
    }

    /**
     * The three starting steps, in the only order that makes sense — size it, read the
     * code, generate the checklist — so nobody has to remember there are three. Each
     * remains its own command for the re-runs: a fresh `introspect` after the legacy app
     * changes, a `survey --json` to compare sites, an `init` for a second corpus.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $envs = (array) $input->getOption('env');
        $source = (string) $input->getOption('source');

        if ($envs === [] || $source === '') {
            $io->error('bootstrap needs at least one --env=NAME=database and a --source checkout.');

            return Command::INVALID;
        }

        $dir = rtrim((string) $input->getOption('dir'), '/');
        $artifact = $dir . '/introspection.json';
        $mapping = $dir . '/mapping.yaml';

        $io->title('1/3 — Survey: what the databases hold');

        if ($this->delegate('survey', ['--env' => $envs], $output) !== Command::SUCCESS) {
            return Command::FAILURE;
        }

        $io->title('2/3 — Introspect: what the application wired up');

        if ($this->delegate('introspect', ['--source' => $source, '--out' => $artifact], $output) !== Command::SUCCESS) {
            return Command::FAILURE;
        }

        $io->title('3/3 — Init: the mapping skeleton');

        // A mapping that exists holds decisions; init refuses to overwrite it, and so
        // does this — bootstrap is how a migration starts, not how it starts over.
        if (is_file($mapping)) {
            $io->note(sprintf('%s already exists — keeping it. The survey and the artifact above are refreshed.', $mapping));
        } elseif ($this->delegate('init', [
            '--env' => $envs,
            '--introspection' => $artifact,
            '--source' => $source,
            '--out' => $mapping,
        ], $output) !== Command::SUCCESS) {
            return Command::FAILURE;
        }

        $io->section('Next');
        $io->listing([
            sprintf('Decide every row in %s — the skeleton deliberately fails `validate` until each part has a disposition. The control panel mapping editor edits the same file.', $mapping),
            sprintf('Check it three ways: `validate %s --craft=. --introspection=%s` (add `--specs=` when the content-model docs carry migration notes).', $mapping, $artifact),
            sprintf('Then `coverage %s` (nothing live unaccounted for), `readiness %s --craft=.` (every required field has a value), and `compile` for a dry run.', $mapping, $mapping),
        ]);

        return Command::SUCCESS;
    }

    /** @param array<string, mixed> $options */
    private function delegate(string $name, array $options, OutputInterface $output): int
    {
        $application = $this->getApplication();

        if ($application === null) {
            return Command::FAILURE;
        }

        $arguments = [];

        foreach ($options as $option => $value) {
            $arguments[$option] = $value;
        }

        return $application->find($name)->run(new ArrayInput($arguments), $output);
    }
}
