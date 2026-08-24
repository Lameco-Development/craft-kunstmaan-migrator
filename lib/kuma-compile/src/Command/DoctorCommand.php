<?php

declare(strict_types=1);

namespace Lameco\KumaCompile\Command;

use Lameco\KumaCompile\Legacy\Dsn;
use Lameco\KumaCompile\Legacy\KunstmaanCoreTables;
use Lameco\KumaCompile\Legacy\LegacyDatabase;
use Lameco\KumaCompile\Mapping\Mapping;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'doctor',
    description: 'Preflight: mapping parses, every environment is reachable, no conflict is still open',
)]
/**
 * The compile-scope doctor — everything checkable without a Craft install.
 *
 * `./craft kunstmaan-migrator/doctor` asks a superset of these questions:
 * the Craft-side `run\Diagnostics` reads the same mapping-state answers
 * (conflicts, unreviewed, todos) and adds the install checks this binary
 * cannot see (state table, adapters, production guard). Use this one while
 * authoring a mapping; use the Craft one before a run.
 */
final class DoctorCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('mapping', InputArgument::REQUIRED, 'Path to the mapping YAML');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $mapping = Mapping::fromFile((string) $input->getArgument('mapping'));
        $failed = false;

        $io->title('Doctor');
        $io->writeln(sprintf('  mapping   <info>%s</info> (version %d)', $mapping->path, $mapping->version()));

        // Environments must be reachable, and must actually look like Kunstmaan.
        $dsn = Dsn::fromEnvironment();

        foreach ($mapping->environments() as $env => $spec) {
            $database = (string) ($spec['database'] ?? '');

            if ($database === '') {
                $io->writeln(sprintf('  <error>env %s</error> has no database', $env));
                $failed = true;

                continue;
            }

            try {
                $db = LegacyDatabase::connect((string) $env, $database, $dsn);
                $missing = array_filter(
                    [
                        KunstmaanCoreTables::NODES,
                        KunstmaanCoreTables::NODE_TRANSLATIONS,
                        KunstmaanCoreTables::NODE_VERSIONS,
                        KunstmaanCoreTables::PAGE_PART_REFS,
                    ],
                    static fn(string $t): bool => !$db->hasTable($t),
                );

                if ($missing !== []) {
                    $io->writeln(sprintf('  <error>env %-4s</error> %s is not a Kunstmaan schema (missing %s)', $env, $database, implode(', ', $missing)));
                    $failed = true;

                    continue;
                }

                $io->writeln(sprintf('  <info>env %-4s</info> %s reachable', $env, $database));
            } catch (\PDOException $e) {
                $io->writeln(sprintf('  <error>env %-4s</error> %s unreachable: %s', $env, $database, $e->getMessage()));
                $failed = true;
            }
        }

        // The conflict gate. A mapping that still contradicts itself is not a program.
        $conflicts = $mapping->openConflicts();

        if ($conflicts !== []) {
            $io->section(sprintf('%d unresolved conflicts', count($conflicts)));

            foreach ($conflicts as $c) {
                $io->writeln(sprintf(
                    '  <comment>%-22s</comment> %s  artifact: <fg=yellow>%s</> | spec: <fg=yellow>%s</>',
                    $c->subject,
                    $c->live !== null ? str_pad(number_format($c->live) . ' live', 12) : str_repeat(' ', 12),
                    $c->artifact,
                    $c->spec,
                ));
            }

            $io->writeln('');
            $io->writeln('  Resolve each by setting <info>conflict.status: decided</info> on the reading you keep.');
            $failed = true;
        }

        // Unreviewed columns are a validate-level error, but doctor is what people run before a
        // load, so the backlog is visible here rather than only where it blocks.
        if ($unreviewed = $mapping->unreviewed()) {
            $io->section(sprintf('%d subjects with unreviewed columns', count($unreviewed)));

            foreach ($unreviewed as $subject => $columns) {
                $io->writeln(sprintf('  <error>%s</error> %s', $subject, implode(', ', $columns)));
            }

            $failed = true;
        }

        if ($todos = $mapping->todos()) {
            $io->section(sprintf('%d open todos (not blocking)', count($todos)));

            foreach ($todos as $todo) {
                $io->writeln('  · ' . strtok($todo, "\n"));
            }
        }

        if ($failed) {
            $io->error('Not ready to compile.');

            return Command::FAILURE;
        }

        $io->success('Ready to compile.');

        return Command::SUCCESS;
    }
}
