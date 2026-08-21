<?php

declare(strict_types=1);

namespace Lameco\KumaCompile\Command;

use Lameco\KumaCompile\Legacy\Dsn;
use Lameco\KumaCompile\Legacy\LegacyDatabase;
use Lameco\KumaCompile\Mapping\Mapping;
use Lameco\KumaCompile\Report\Coverage;
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
final class CoverageCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('mapping', InputArgument::REQUIRED, 'Path to the mapping YAML')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON instead of a table');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $mapping = Mapping::fromFile((string) $input->getArgument('mapping'));
        $coverage = new Coverage($mapping);
        $dsn = Dsn::fromEnvironment();

        foreach ($mapping->environments() as $env => $spec) {
            if (!isset($spec['database'])) {
                continue;
            }

            $coverage->ingest(LegacyDatabase::connect((string) $env, (string) $spec['database'], $dsn)->snapshot());
        }

        if ($input->getOption('json')) {
            $output->writeln((string) json_encode([
                'placements'        => $coverage->totalPlacements(),
                'pages'             => $coverage->totalPages(),
                'liveShare'         => round($coverage->liveShare(), 4),
                'byLane'            => $coverage->placementsByLane(),
                'unclaimedParts'    => $coverage->unclaimedParts(),
                'unclaimedPageTypes'=> $coverage->unclaimedPageTypes(),
                'staleParts'        => $coverage->staleParts(),
                'strandedLocales'   => $coverage->strandedLocales(),
                'holes'             => $coverage->hasHoles(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $coverage->hasHoles() ? Command::FAILURE : Command::SUCCESS;
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

        foreach ($coverage->unclaimedParts() as $class => $n) {
            $io->writeln(sprintf('  <error>pagepart</error>  %-28s %s live placements', $class, number_format($n)));
        }

        foreach ($coverage->unclaimedPageTypes() as $entity => $n) {
            $io->writeln(sprintf('  <error>page</error>      %-28s %s live pages', $entity, number_format($n)));
        }

        $io->error('The mapping has holes. Claim each item in a lane, or declare it under `unmapped:` with a reason.');

        return Command::FAILURE;
    }
}
