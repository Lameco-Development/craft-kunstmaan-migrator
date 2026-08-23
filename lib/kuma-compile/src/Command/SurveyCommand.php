<?php

declare(strict_types=1);

namespace Lameco\KumaCompile\Command;

use Lameco\KumaCompile\Legacy\Dsn;
use Lameco\KumaCompile\Legacy\LegacyDatabase;
use Lameco\KumaCompile\Report\Survey;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'survey',
    description: 'Size a legacy corpus before any Craft project exists',
)]
final class SurveyCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('env', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Legacy environment as NAME=database, repeatable (e.g. --env=COM=enreach_website)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Machine-readable, for comparing sites in a loop')
            ->addOption('top', null, InputOption::VALUE_REQUIRED, 'How many pagepart classes to list', '15');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dsn = Dsn::fromEnvironment();
        $surveys = [];

        foreach ((array) $input->getOption('env') as $pair) {
            if (!str_contains((string) $pair, '=')) {
                $io->error(sprintf('--env expects NAME=database, got `%s`', $pair));

                return Command::INVALID;
            }

            [$name, $database] = explode('=', (string) $pair, 2);
            $surveys[] = Survey::of(LegacyDatabase::connect($name, $database, $dsn));
        }

        if ($surveys === []) {
            $io->error('At least one --env=NAME=database is required.');

            return Command::INVALID;
        }

        if ($input->getOption('json')) {
            $output->writeln((string) json_encode(
                array_map(static fn (Survey $s): array => $s->toArray(), $surveys),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return Command::SUCCESS;
        }

        $this->render($io, $surveys, max(1, (int) $input->getOption('top')));

        return Command::SUCCESS;
    }

    /** @param list<Survey> $surveys */
    private function render(SymfonyStyle $io, array $surveys, int $top): void
    {
        $io->title('Corpus survey');
        $io->text('Everything here is resolved to the published node version. Kunstmaan clones the whole');
        $io->text('pagepart graph per version, so the raw tables are roughly twenty times the live content —');
        $io->text('a quote written off a raw row count is twenty times too big.');

        $io->section('Shape');
        $io->table(
            ['env', 'live pages', 'placements', 'live share', 'part classes', 'page types', 'locales'],
            array_map(static fn (Survey $s): array => [
                $s->environment,
                number_format($s->livePages),
                number_format($s->livePlacements),
                sprintf('%.1f%%', $s->liveShare() * 100),
                (string) count($s->partClasses),
                (string) count($s->pageTypes),
                sprintf('%d (%s)', count($s->locales), implode(', ', array_keys($s->locales))),
            ], $surveys),
        );

        $io->section('Volume');
        $io->text('A dash is a table this install does not have, not a zero.');
        $keys = array_keys($surveys[0]->volumes);
        $io->table(
            ['env', ...$keys],
            array_map(static fn (Survey $s): array => [
                $s->environment,
                ...array_map(
                    static fn (string $k): string => $s->volumes[$k] === null ? '—' : number_format($s->volumes[$k]),
                    $keys,
                ),
            ], $surveys),
        );

        $withSidecars = array_filter($surveys, static fn (Survey $s): bool => $s->sidecarTables !== []);

        if ($withSidecars !== []) {
            $io->section('Sidecar tables');
            $io->text('Per-page entities keyed by (ref_entity_name, ref_id) — header/footer tabs, structured');
            $io->text('data. Raw rows: like pageparts they are cloned per version, so live is far lower.');

            foreach ($withSidecars as $survey) {
                $io->writeln(sprintf('  <comment>%s</comment>  %s', $survey->environment, $this->inline($survey->sidecarTables)));
            }
        }

        foreach ($surveys as $survey) {
            $io->section(sprintf('%s — where the work is', $survey->environment));
            $io->writeln('  <comment>contexts</comment>  ' . $this->inline($survey->contexts));
            $io->writeln('');
            $io->writeln(sprintf('  <comment>top %d pagepart classes of %d</comment>', min($top, count($survey->partClasses)), count($survey->partClasses)));
            $io->writeln('  ' . $this->inline(array_slice($survey->partClasses, 0, $top, true)));
            $io->writeln('');
            $io->writeln(sprintf('  <comment>top %d page types of %d</comment>', min($top, count($survey->pageTypes)), count($survey->pageTypes)));
            $io->writeln('  ' . $this->inline(array_slice($survey->pageTypes, 0, $top, true)));
        }

        $io->section('What this does not tell you');
        $io->writeln('  How many of those pagepart classes collapse into one Craft block. That is the half a');
        $io->writeln('  machine cannot know, and it is what decides the estimate. These are the counts the');
        $io->writeln('  decision is made against, not the decision.');
        $io->writeln('');
        $io->writeln('  Comparing sites: run once per site with <info>--json</info> and read `partClassCount`,');
        $io->writeln('  `pageTypeCount`, `localeCount` and `volumes.media` — the four that move an estimate most.');
    }

    /** @param array<string, int> $counts */
    private function inline(array $counts): string
    {
        $parts = [];

        foreach ($counts as $key => $n) {
            $parts[] = sprintf('%s %s', $key, number_format($n));
        }

        return $parts === [] ? '—' : implode('  ·  ', $parts);
    }
}
