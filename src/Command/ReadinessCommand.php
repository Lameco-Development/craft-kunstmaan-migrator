<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\Command;

use Lameco\Kunstmaanmigrator\Mapping\Mapping;
use Lameco\Kunstmaanmigrator\Report\FillMeasurer;
use Lameco\Kunstmaanmigrator\Report\Readiness;
use Lameco\Kunstmaanmigrator\Report\Requirement;
use Lameco\Kunstmaanmigrator\Source\Dsn;
use Lameco\Kunstmaanmigrator\Source\LegacyDatabase;
use Lameco\Kunstmaanmigrator\Target\CraftSchema;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'readiness',
    description: 'Every required Craft field, and whether the mapping has a value to put in it',
)]
final class ReadinessCommand extends Command
{
    /**
     * What a verdict actually costs at load time.
     *
     * Without this the report reads as a list of load blockers, which is what its author assumed
     * and got wrong. Craft only validates a required custom field in SCENARIO_LIVE
     * (`Element.php`, `$scenario === self::SCENARIO_LIVE && $layoutElement->required`); nested
     * Matrix entries inherit their owner's scenario (`Matrix.php`), and a required relation is the
     * same (`BaseRelationField.php`, validateRelationCount on SCENARIO_LIVE). The Kunstmaan Migrator
     * never sets that scenario — its only setScenario call is `Asset::SCENARIO_CREATE` — so
     * saveElement() validates under the default scenario and required custom fields are skipped.
     *
     * Re-verify with: grep -rn "setScenario" <loader>/src
     *
     * @var list<string>
     */
    private const ENFORCEMENT = [
        'None of these stop a load. Craft enforces a required custom field only in SCENARIO_LIVE, and',
        'the loader never sets it, so every entry here saves with the field empty.',
        '',
        'What they cost instead: an editor opening the entry in the control panel cannot save it —',
        'the CP does save enabled entries under SCENARIO_LIVE — and a block whose payload is the',
        'missing field renders as an empty block on a live page.',
        '',
        'So this is a punch list for what editors will find waiting for them, not a preflight gate.',
    ];

    protected function configure(): void
    {
        $this
            ->addArgument('mapping', InputArgument::REQUIRED, 'Path to the mapping YAML')
            ->addOption('craft', null, InputOption::VALUE_REQUIRED, 'Target Craft project root')
            ->addOption('offline', null, InputOption::VALUE_NONE, 'Skip fill rates — schema check only, no database')
            ->addOption('unfilled', null, InputOption::VALUE_NONE, 'Invert it: the optional fields no lane fills at all')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Include the required fields that are already satisfied')
            ->addOption('markdown', null, InputOption::VALUE_NONE, 'Emit a Markdown table, for committing next to the mapping')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON')
            ->addOption('strict', null, InputOption::VALUE_NONE, 'Exit non-zero while any required field is unsatisfied');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $mapping = Mapping::fromFile((string) $input->getArgument('mapping'));
        $craftRoot = $input->getOption('craft');

        if (!is_string($craftRoot) || $craftRoot === '') {
            $io->error('--craft is required: the target content model is what says which fields are required.');

            return Command::INVALID;
        }

        $readiness = new Readiness($mapping, CraftSchema::fromProjectConfig($craftRoot));

        if ($input->getOption('unfilled')) {
            return $this->unfilled($io, $output, $readiness->unfilled(), (bool) $input->getOption('json'));
        }

        $requirements = $readiness->requirements();
        $problems = [];

        if (!$input->getOption('offline')) {
            $measurer = new FillMeasurer($mapping);
            $dsn = Dsn::fromEnvironment();

            foreach ($mapping->environments() as $env => $spec) {
                if (!isset($spec['database'])) {
                    continue;
                }

                $measurer->ingest($requirements, LegacyDatabase::connect((string) $env, (string) $spec['database'], $dsn));
            }

            $problems = $measurer->problems();
        }

        $shown = $input->getOption('all')
            ? $requirements
            : array_values(array_filter($requirements, static fn(Requirement $r): bool => $r->verdict() !== Requirement::OK));

        usort($shown, static function(Requirement $a, Requirement $b): int {
            $rank = [
                Requirement::MISSING => 0,
                Requirement::PARTIAL => 1,
                Requirement::DEFAULTED => 2,
                Requirement::OK => 3,
            ];

            return [$rank[$a->verdict()], -$a->affected()] <=> [$rank[$b->verdict()], -$b->affected()];
        });

        if ($input->getOption('json')) {
            $output->writeln((string) json_encode(array_map($this->toArray(...), $shown), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } elseif ($input->getOption('markdown')) {
            $this->markdown($output, $shown, $requirements, $problems);
        } else {
            $this->table($io, $shown, $requirements);

            if ($problems !== []) {
                $io->section('Columns the mapping reads that an environment does not have');

                foreach ($problems as $problem) {
                    $io->writeln('  <error>·</error> ' . $problem);
                }

                $io->writeln('  These rows could not be measured there. The environments are not one schema.');
            }
        }

        $unsatisfied = count(array_filter($requirements, static fn(Requirement $r): bool => $r->verdict() !== Requirement::OK));

        return $input->getOption('strict') && $unsatisfied > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * The fields nothing writes to, grouped by handle.
     *
     * Per-placement rows are the wrong unit: `heroTitle` unfilled on twenty entry types is one
     * decision, not twenty findings, and reading it as twenty is how it stayed unnoticed. The
     * handle with the most placements is the one an editor meets first.
     *
     * @param list<Requirement> $unfilled
     */
    private function unfilled(SymfonyStyle $io, OutputInterface $output, array $unfilled, bool $json): int
    {
        $byField = [];

        foreach ($unfilled as $r) {
            $byField[$r->field]['field'] = $r->field;
            $byField[$r->field]['targets'][] = $r->target;
            $byField[$r->field]['lanes'][$r->lane] = true;
            $byField[$r->field]['live'] = ($byField[$r->field]['live'] ?? 0) + ($r->live ?? 0);

            if ($r->craftDefault !== null) {
                $byField[$r->field]['defaults'][$r->craftDefault] = true;
            }
        }

        foreach ($byField as &$row) {
            $row['targets'] = array_values(array_unique($row['targets']));
            $row['placements'] = count($row['targets']);
            $row['lanes'] = array_keys($row['lanes']);
            $row['craftDefaults'] = array_keys($row['defaults'] ?? []);
            unset($row['defaults']);
        }

        unset($row);
        usort($byField, static fn(array $a, array $b): int => [$b['placements'], $b['live']] <=> [$a['placements'], $a['live']]);

        if ($json) {
            $output->writeln((string) json_encode(array_values($byField), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        $io->title('Fields no lane fills');
        $io->text(sprintf(
            '%d field placements across the targets this mapping writes to have no source in any lane — not the'
            . ' map, not a child collection, not a promote, not the sequence, not the block stream.',
            count($unfilled),
        ));

        if ($byField === []) {
            $io->success('Every field on every target the mapping writes to is filled by something.');

            return Command::SUCCESS;
        }

        $io->table(
            ['field', 'entry types', 'lanes', 'live rows', 'Craft writes'],
            array_map(static fn(array $row): array => [
                $row['field'],
                $row['placements'] . ($row['placements'] > 3
                    ? sprintf(' (%s, …)', implode(', ', array_slice($row['targets'], 0, 3)))
                    : sprintf(' (%s)', implode(', ', $row['targets']))),
                implode(', ', $row['lanes']),
                $row['live'] > 0 ? number_format($row['live']) : '—',
                $row['craftDefaults'] === [] ? '—' : implode(', ', $row['craftDefaults']),
            ], $byField),
        );

        $io->writeln('  Not an error: plenty of fields are meant to be left to editors. It is a list to walk once,');
        $io->writeln('  because the alternative is finding out from the client which of them they expected filled.');
        $io->writeln('');
        $io->writeln('  <comment>Craft writes</comment> is the trap column. A field with a default is populated on every migrated');
        $io->writeln('  entry without one byte of legacy data behind it, so a spot-check that stops at "is the field');
        $io->writeln('  set" reads it as migrated. It is on this list because it is not.');

        return Command::SUCCESS;
    }

    /**
     * @param list<Requirement> $shown
     * @param list<Requirement> $all
     */
    private function table(SymfonyStyle $io, array $shown, array $all): void
    {
        $io->title('Required-field readiness');
        $io->text($this->headline($all));

        if ($shown === []) {
            $io->success('Every required field on every target the mapping writes to has a value.');

            return;
        }

        $rows = [];

        foreach ($shown as $r) {
            $rows[] = [
                $r->lane,
                $r->subject,
                sprintf('%s.%s', $r->target, $r->field),
                $r->source ?? ($r->supplier ?? '—'),
                match ($r->verdict()) {
                    Requirement::MISSING => '<error> missing </error>',
                    Requirement::PARTIAL => '<comment> partial </comment>',
                    Requirement::DEFAULTED => sprintf('<info> default </info> %s', (string) $r->craftDefault),
                    default => 'ok',
                },
                $this->scale($r),
            ];
        }

        $io->table(['lane', 'from', 'required field', 'source', 'verdict', 'affected'], $rows);

        foreach (self::ENFORCEMENT as $line) {
            $io->writeln($line === '' ? '' : '  ' . $line);
        }

        $io->writeln('');
        $io->writeln('  <error>missing</error>  nothing supplies it, and the field has no default — set one in the mapping, or relax the field in Craft.');
        $io->writeln('  <comment>partial</comment>  supplied, but empty on some live rows, and no default catches them — add a fallback, or relax the field.');
        $io->writeln('  <info>default</info>  Craft fills it itself on a fresh element. Not a blocker; listed because the migration is choosing that value.');
    }

    /**
     * @param list<Requirement> $shown
     * @param list<Requirement> $all
     * @param list<string> $problems
     */
    private function markdown(OutputInterface $output, array $shown, array $all, array $problems): void
    {
        $output->writeln('# Required-field readiness');
        $output->writeln('');
        $output->writeln(sprintf(
            'Generated by `kuma-compile readiness`, measured against the live legacy databases on %s.',
            date('Y-m-d'),
        ));
        $output->writeln('');
        $output->writeln($this->headline($all));
        $output->writeln('');
        $output->writeln('A required Craft field with no legacy source is invisible until a load rejects the entry.');
        $output->writeln('There are two fixes and both are decisions: **set a default in the mapping**, or **relax the');
        $output->writeln('field in Craft**. `partial` means the field *is* mapped but its column is empty on some live');
        $output->writeln('rows — the compiler drops empty values rather than writing them, so those rows fail the same');
        $output->writeln('way, just later and only sometimes.');
        $output->writeln('');
        $output->writeln('`default` is neither: Craft applies the field\'s own default to a fresh element when the');
        $output->writeln('payload omits it, so nothing has to be decided before loading. It is listed because the');
        $output->writeln('migration is choosing that value for every affected row, and an editor sees the result.');
        $output->writeln('');
        $output->writeln('## What a verdict costs');
        $output->writeln('');

        foreach (self::ENFORCEMENT as $line) {
            $output->writeln($line);
        }

        $output->writeln('');
        $output->writeln('| lane | from | required field | source | verdict | affected |');
        $output->writeln('|---|---|---|---|---|---:|');

        foreach ($shown as $r) {
            $output->writeln(sprintf(
                '| %s | `%s` | `%s.%s` | %s | %s | %s |',
                $r->lane,
                $r->subject,
                $r->target,
                $r->field,
                $r->source !== null ? sprintf('`%s`', str_replace('|', '\\|', $r->source)) : ($r->supplier ?? '—'),
                match ($r->verdict()) {
                    Requirement::MISSING => '**missing**',
                    Requirement::DEFAULTED => sprintf('default → `%s`', (string) $r->craftDefault),
                    default => $r->verdict(),
                },
                $this->scale($r),
            ));
        }

        if ($problems === []) {
            return;
        }

        $output->writeln('');
        $output->writeln('## Columns the mapping reads that an environment does not have');
        $output->writeln('');
        $output->writeln('The legacy databases are not one schema, so these rows could not be measured everywhere.');
        $output->writeln('');

        foreach ($problems as $problem) {
            $output->writeln('- ' . $problem);
        }
    }

    /** @param list<Requirement> $all */
    private function headline(array $all): string
    {
        $by = [Requirement::OK => 0, Requirement::DEFAULTED => 0, Requirement::PARTIAL => 0, Requirement::MISSING => 0];

        foreach ($all as $r) {
            ++$by[$r->verdict()];
        }

        return sprintf(
            '%d required fields across every target the mapping writes to — %d filled by the mapping, %d left to a Craft'
            . ' default, %d partial, %d with nothing to fill them.',
            count($all),
            $by[Requirement::OK],
            $by[Requirement::DEFAULTED],
            $by[Requirement::PARTIAL],
            $by[Requirement::MISSING],
        );
    }

    private function scale(Requirement $r): string
    {
        if (in_array($r->verdict(), [Requirement::PARTIAL, Requirement::DEFAULTED], true) && $r->rows !== null) {
            return sprintf('%s of %s rows', number_format((int) $r->empty), number_format((int) $r->rows));
        }

        return $r->live !== null ? sprintf('%s live', number_format($r->live)) : '—';
    }

    /** @return array<string, mixed> */
    private function toArray(Requirement $r): array
    {
        return [
            'lane' => $r->lane,
            'from' => $r->subject,
            'target' => $r->target,
            'field' => $r->field,
            'source' => $r->source,
            'supplier' => $r->supplier,
            'verdict' => $r->verdict(),
            'live' => $r->live,
            'rows' => $r->rows,
            'empty' => $r->empty,
            'action' => $r->action(),
        ];
    }
}
