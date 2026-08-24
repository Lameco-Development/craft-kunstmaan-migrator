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
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON instead of a table')
            ->addOption('markdown', null, InputOption::VALUE_NONE,
                'Emit the client-facing version: what moves, what does not, and the reason each omission was declared under');
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

        if ($input->getOption('markdown')) {
            $this->markdown($output, $coverage);

            return $coverage->hasHoles() ? Command::FAILURE : Command::SUCCESS;
        }

        if ($input->getOption('json')) {
            $output->writeln((string) json_encode([
                'placements' => $coverage->totalPlacements(),
                'pages' => $coverage->totalPages(),
                'liveShare' => round($coverage->liveShare(), 4),
                'byLane' => $coverage->placementsByLane(),
                'unclaimedParts' => $coverage->unclaimedParts(),
                'unclaimedPageTypes' => $coverage->unclaimedPageTypes(),
                'staleParts' => $coverage->staleParts(),
                'strandedLocales' => $coverage->strandedLocales(),
                'omissions' => $coverage->declaredOmissions(),
            'holes' => $coverage->hasHoles(),
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

    /**
     * The version to send a client.
     *
     * A migration's deliverable is not only what arrived; it is also an accounting of what did
     * not, and why. Both halves are already in hand — the placement counts measured against the
     * live databases, and the written reason every `unmapped:`, `drop:` and `manual:` carries —
     * and putting them in one document is what turns "the data is in Craft" into something
     * reviewable by somebody who does not read YAML.
     */
    private function markdown(OutputInterface $output, Coverage $coverage): void
    {
        $output->writeln('# What moves, and what does not');
        $output->writeln('');
        $output->writeln(sprintf(
            'Measured against the live legacy databases on %s by `kuma-compile coverage`. Every number'
            . ' below counts *published* content: Kunstmaan keeps a copy of a page\'s whole content graph'
            . ' per saved version, and only %.1f%% of those rows belong to a page that is live.',
            date('Y-m-d'),
            $coverage->liveShare() * 100,
        ));
        $output->writeln('');
        $output->writeln(sprintf(
            '**%s content blocks across %s pages.**',
            number_format($coverage->totalPlacements()),
            number_format($coverage->totalPages()),
        ));
        $output->writeln('');
        $output->writeln('## Where it lands');
        $output->writeln('');
        $output->writeln('| destination | blocks | share |');
        $output->writeln('|---|---:|---:|');

        $total = max(1, $coverage->totalPlacements());

        foreach ($coverage->placementsByLane() as $lane => $n) {
            $output->writeln(sprintf('| %s | %s | %.1f%% |', self::LANE_NAMES[$lane] ?? $lane, number_format($n), $n / $total * 100));
        }

        $omissions = $coverage->declaredOmissions();

        if ($omissions !== []) {
            $output->writeln('');
            $output->writeln('## What is deliberately not migrated');
            $output->writeln('');
            $output->writeln('Each of these is a decision, recorded in the mapping with its reason and reviewable in');
            $output->writeln('the same place the migration itself is defined. Nothing here is an oversight.');
            $output->writeln('');
            $output->writeln('| | | blocks | why |');
            $output->writeln('|---|---|---:|---|');

            foreach ($omissions as $omission) {
                $output->writeln(sprintf(
                    '| `%s` | %s | %s | %s |',
                    $omission['subject'],
                    $omission['kind'],
                    number_format($omission['placements']),
                    str_replace('|', '\\|', $omission['reason']),
                ));
            }
        }

        if ($stranded = $coverage->strandedLocales()) {
            $output->writeln('');
            $output->writeln('## Languages with nowhere to go');
            $output->writeln('');
            $output->writeln('These languages exist on the legacy site and have no site on the new one, so their pages');
            $output->writeln('disappear at cutover. Creating a site for one of them is what changes that.');
            $output->writeln('');
            $output->writeln('| language | live pages |');
            $output->writeln('|---|---:|');

            foreach ($stranded as $locale => $pages) {
                $output->writeln(sprintf('| %s | %s |', $locale, number_format($pages)));
            }
        }

        if (!$coverage->hasHoles()) {
            $output->writeln('');
            $output->writeln('## Nothing is unaccounted for');
            $output->writeln('');
            $output->writeln('Every kind of content on the legacy site is either migrated or listed above as a decision.');

            return;
        }

        $output->writeln('');
        $output->writeln('## Unaccounted for — not yet decided');
        $output->writeln('');
        $output->writeln('Live content that is neither migrated nor declared. These need a decision before cutover.');
        $output->writeln('');
        $output->writeln('| | live |');
        $output->writeln('|---|---:|');

        foreach ($coverage->unclaimedParts() as $class => $n) {
            $output->writeln(sprintf('| `%s` (content block) | %s |', $class, number_format($n)));
        }

        foreach ($coverage->unclaimedPageTypes() as $entity => $n) {
            $output->writeln(sprintf('| `%s` (page type) | %s |', $entity, number_format($n)));
        }
    }

    /**
     * Lane names in the client's terms rather than the mapping's.
     *
     * `globals` and `sequence` mean something precise to whoever wrote the mapping and nothing
     * at all to the person deciding whether the migration is acceptable.
     *
     * @var array<string, string>
     */
    private const LANE_NAMES = [
        'blocks' => 'page content',
        'sequence' => 'page content (merged into the block above it)',
        'forms' => 'forms',
        'globals' => 'site-wide (footer, navigation)',
        'manual' => 'rebuilt by hand after migration',
        'dropped' => 'not migrated',
        'unmapped' => 'not migrated (declared)',
        'UNCLAIMED' => 'not yet decided',
    ];
}
