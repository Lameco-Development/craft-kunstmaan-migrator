<?php

declare(strict_types=1);

namespace Lameco\KumaCompile\Command;

use Lameco\KumaCompile\Legacy\Dsn;
use Lameco\KumaCompile\Legacy\LegacyDatabase;
use Lameco\KumaCompile\Mapping\Mapping;
use Lameco\KumaCompile\Report\Coverage;
use Lameco\KumaCompile\Report\CoverageReport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'coverage',
    description: 'Measure a mapping against the live legacy content it claims to describe',
)]
/**
 * Thin renderer over `Report\Coverage` + `Report\CoverageReport` — the same
 * measurement `./craft kunstmaan-migrator/mapping/coverage` takes and the
 * migrate preflight refuses on. This one reads its DSN from the environment,
 * so it runs from a machine with no Craft install.
 */
final class CoverageCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('mapping', InputArgument::REQUIRED, 'Path to the mapping YAML')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON instead of a table')
            ->addOption('markdown', null, InputOption::VALUE_NONE,
                'Emit the client-facing version: what moves, what does not, and the reason each omission was declared under');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $mapping = Mapping::fromFile((string) $input->getArgument('mapping'));
        $coverage = Coverage::measure($mapping, LegacyDatabase::connectAll($mapping->databases(), Dsn::fromEnvironment()));
        $report = new CoverageReport($coverage);
        $exit = $coverage->hasHoles() ? Command::FAILURE : Command::SUCCESS;

        if ($input->getOption('markdown')) {
            $output->write($report->markdown('kuma-compile coverage', date('Y-m-d')));

            return $exit;
        }

        if ($input->getOption('json')) {
            $output->writeln((string) json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $exit;
        }

        $io->title('Coverage');
        $io->text(sprintf(
            '%s live placements across %s live pages — %.1f%% of all pagepart rows (the rest belong to superseded versions).',
            number_format($coverage->totalPlacements()),
            number_format($coverage->totalPages()),
            $coverage->liveShare() * 100,
        ));

        $total = max(1, $coverage->totalPlacements());
        $rows = [];

        foreach ($coverage->placementsByLane() as $lane => $n) {
            $rows[] = [$lane, number_format($n), sprintf('%.1f%%', $n / $total * 100)];
        }

        $io->table(['lane', 'placements', 'share'], $rows);

        if ($stranded = $coverage->strandedLocales()) {
            $io->section('Locales with no Craft site');

            foreach ($stranded as $locale => $pages) {
                $io->writeln(sprintf('  <comment>%-12s</comment> %s live pages stranded', $locale, number_format($pages)));
            }
        }

        if ($stale = $coverage->staleParts()) {
            $io->section('Described but no longer live');
            $io->writeln('  ' . implode(', ', $stale));
        }

        if (!$coverage->hasHoles()) {
            $io->success('No holes — every live pagepart class and page type is claimed by a lane.');

            return Command::SUCCESS;
        }

        $io->section('Holes — live content no lane claims');

        foreach ($report->holes() as $hole) {
            $io->writeln('  <error>·</error> ' . $hole);
        }

        $io->error('The mapping has holes. Claim each item in a lane, or declare it under `unmapped:` with a reason.');

        return Command::FAILURE;
    }
}
