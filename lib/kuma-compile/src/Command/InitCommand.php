<?php

declare(strict_types=1);

namespace Lameco\KumaCompile\Command;

use Lameco\KumaCompile\Legacy\Dsn;
use Lameco\KumaCompile\Legacy\EntityTableIndex;
use Lameco\KumaCompile\Legacy\Introspection;
use Lameco\KumaCompile\Legacy\LegacyDatabase;
use Lameco\KumaCompile\Mapping\Skeleton;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'init',
    description: 'Generate a mapping skeleton from the live legacy database',
)]
final class InitCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('env', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Legacy environment as NAME=database, repeatable (e.g. --env=COM=enreach_website)')
            ->addOption('source', null, InputOption::VALUE_REQUIRED,
                'Kunstmaan source checkout, for entity -> table names')
            ->addOption('introspection', null, InputOption::VALUE_REQUIRED,
                'Introspection artifact from `introspect` — exact entity tables and child-collection '
                . 'ownership from booted metadata, instead of the static --source scan')
            ->addOption('out', null, InputOption::VALUE_REQUIRED, 'Write to this path instead of stdout');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dsn = Dsn::fromEnvironment();
        $databases = [];

        foreach ((array) $input->getOption('env') as $pair) {
            if (!str_contains((string) $pair, '=')) {
                $io->error(sprintf('--env expects NAME=database, got `%s`', $pair));

                return Command::INVALID;
            }

            [$name, $database] = explode('=', (string) $pair, 2);
            $databases[$name] = LegacyDatabase::connect($name, $database, $dsn);
        }

        if ($databases === []) {
            $io->error('At least one --env=NAME=database is required.');

            return Command::INVALID;
        }

        $source = $input->getOption('source');
        $artifact = $input->getOption('introspection');
        $introspection = $artifact !== null ? Introspection::fromFile((string) $artifact) : null;
        $entities = match (true) {
            $introspection !== null => EntityTableIndex::fromIntrospection($introspection),
            $source !== null => EntityTableIndex::fromSource((string) $source),
            default => EntityTableIndex::empty(),
        };

        if ($entities->isEmpty()) {
            $io->warning('No --introspection or --source given: table names are left as TODO. Pass the artifact or the Kunstmaan checkout to fill them in.');
        }

        $yaml = (new Skeleton($entities, $introspection))->generate($databases);
        $out = $input->getOption('out');

        if ($out === null) {
            $output->write($yaml);

            return Command::SUCCESS;
        }

        if (is_file((string) $out)) {
            $io->error(sprintf('%s already exists — refusing to overwrite a mapping.', $out));

            return Command::FAILURE;
        }

        @mkdir(dirname((string) $out), 0o775, true);
        file_put_contents((string) $out, $yaml);

        $io->success(sprintf('Wrote %s', $out));
        $io->text('Next: fill in the TODOs, then `kuma-compile validate` and `kuma-compile coverage`.');

        return Command::SUCCESS;
    }
}
