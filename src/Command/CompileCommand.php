<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\Command;

use Lameco\Kunstmaanmigrator\Compile\Compiler;
use Lameco\Kunstmaanmigrator\Compile\PayloadWriter;
use Lameco\Kunstmaanmigrator\Compile\Transforms;
use Lameco\Kunstmaanmigrator\Mapping\Mapping;
use Lameco\Kunstmaanmigrator\Mapping\Schema;
use Lameco\Kunstmaanmigrator\Source\Dsn;
use Lameco\Kunstmaanmigrator\Source\LegacyDatabase;
use Lameco\Kunstmaanmigrator\Target\CraftSchema;
use Lameco\Kunstmaanmigrator\Target\TargetCheck;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'compile',
    description: 'Compile the legacy database into Kunstmaan Migrator payloads',
)]
final class CompileCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('mapping', InputArgument::REQUIRED, 'Path to the mapping YAML')
            ->addOption('out', null, InputOption::VALUE_REQUIRED, 'Directory to write payloads into', 'payloads')
            ->addOption('env', null, InputOption::VALUE_REQUIRED, 'Compile only this environment')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Stop after N entries per environment')
            ->addOption('craft', null, InputOption::VALUE_REQUIRED,
                'Target Craft project root — derives nested block types and heading placement')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report only, write nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $mapping = Mapping::fromFile((string) $input->getArgument('mapping'));

        if ($errors = (new Schema())->validate($mapping)) {
            $io->error(sprintf('Mapping is not well-formed (%d problems) — run `validate`.', count($errors)));

            return Command::FAILURE;
        }

        if ($conflicts = $mapping->openConflicts()) {
            $io->error(sprintf('%d conflicts still open — run `doctor`.', count($conflicts)));

            return Command::FAILURE;
        }

        $schema = null;

        if ($craftRoot = $input->getOption('craft')) {
            $schema = CraftSchema::fromProjectConfig((string) $craftRoot);

            if ($mismatches = (new TargetCheck($schema))->check($mapping)) {
                $io->error(sprintf('Mapping does not match the target (%d problems) — run `validate --craft`.', count($mismatches)));

                return Command::FAILURE;
            }
        }

        $transforms = new Transforms($mapping->transforms());
        $compiler = new Compiler($mapping, $transforms, $schema);
        $dsn = Dsn::fromEnvironment();
        $only = $input->getOption('env');
        $limit = $input->getOption('limit') !== null ? (int) $input->getOption('limit') : null;
        $dryRun = (bool) $input->getOption('dry-run');
        $outDir = (string) $input->getOption('out');

        if (!$dryRun && !is_dir($outDir) && !mkdir($outDir, 0o775, true) && !is_dir($outDir)) {
            $io->error(sprintf('Cannot create %s', $outDir));

            return Command::FAILURE;
        }

        $io->title('Compile');

        foreach ($mapping->environments() as $env => $spec) {
            if ($only !== null && $env !== $only) {
                continue;
            }

            $db = LegacyDatabase::connect((string) $env, (string) $spec['database'], $dsn);
            $path = sprintf('%s/%s.ndjson', $outDir, strtolower((string) $env));
            $handle = $dryRun ? null : fopen($path, 'w');
            $writer = new PayloadWriter($handle);
            $before = $compiler->entryCount();

            $compiler->compile($db, (string) $env, $writer->write(...), $limit);

            if ($handle !== null) {
                fclose($handle);
            }

            $io->writeln(sprintf(
                '  <info>%-4s</info> %s entries%s',
                $env,
                number_format($compiler->entryCount() - $before),
                $dryRun ? '' : sprintf(' → %s', $path),
            ));
        }

        $io->section('Result');
        $io->writeln(sprintf('  entries: %s', number_format($compiler->entryCount())));
        $io->writeln(sprintf('  blocks:  %s', number_format($compiler->blockCount())));

        if ($losses = $transforms->losses()) {
            $io->section(sprintf('Lossy conversions (%s)', number_format($transforms->lossCount())));

            foreach ($losses as $transform => $counts) {
                foreach (array_slice($counts, 0, 6, true) as $change => $n) {
                    $io->writeln(sprintf('  %-12s %-28s %s', $transform, $change, number_format($n)));
                }
            }
        }

        if ($skipped = $compiler->skipped()) {
            $io->section('Skipped');

            foreach (array_slice($skipped, 0, 12, true) as $reason => $n) {
                $io->writeln(sprintf('  %-42s %s', $reason, number_format($n)));
            }
        }

        return Command::SUCCESS;
    }
}
