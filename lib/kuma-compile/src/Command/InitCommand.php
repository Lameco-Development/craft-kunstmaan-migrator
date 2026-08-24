<?php

declare(strict_types=1);

namespace Lameco\KumaCompile\Command;

use Lameco\KumaCompile\Legacy\Dsn;
use Lameco\KumaCompile\Mapping\MappingException;
use Lameco\KumaCompile\Mapping\MappingInit;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(
    name: 'init',
    description: 'Generate a mapping skeleton from the live legacy database',
)]
/**
 * Thin adapter over `Mapping\MappingInit` — the same engine
 * `./craft kunstmaan-migrator/mapping/init` runs. Use this one from a machine
 * that has no Craft install; the DSN comes from the environment instead of
 * plugin settings.
 */
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

        try {
            $databases = MappingInit::parsePairs(array_map(strval(...), (array) $input->getOption('env')));
        } catch (MappingException $e) {
            $io->error('--env ' . $e->getMessage());

            return Command::INVALID;
        }

        if ($databases === []) {
            $io->error('At least one --env=NAME=database is required.');

            return Command::INVALID;
        }

        $source = $input->getOption('source');
        $artifact = $input->getOption('introspection');

        try {
            $connections = MappingInit::connect($databases, Dsn::fromEnvironment());
            $result = MappingInit::skeleton(
                $connections,
                $source !== null ? (string) $source : null,
                $artifact !== null ? (string) $artifact : null,
            );
        } catch (Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($result->tablesUnresolved) {
            $io->warning('No --introspection or --source given: table names are left as TODO. Pass the artifact or the Kunstmaan checkout to fill them in.');
        }

        $out = $input->getOption('out');

        if ($out === null) {
            $output->write($result->yaml);

            return Command::SUCCESS;
        }

        try {
            MappingInit::write((string) $out, $result->yaml);
        } catch (MappingException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('Wrote %s', $out));
        $io->text('Next: fill in the TODOs, then `kuma-compile validate` and `kuma-compile coverage`.');

        return Command::SUCCESS;
    }
}
