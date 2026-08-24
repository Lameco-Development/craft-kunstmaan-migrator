<?php

declare(strict_types=1);

namespace Lameco\KumaCompile\Command;

use Lameco\KumaCompile\Legacy\Introspector;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'introspect',
    description: 'Dump the legacy application\'s own account of itself — entities, relations, sidecars, form fields — as a committed artifact',
)]
final class IntrospectCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Legacy checkout to introspect')
            ->addOption('out', null, InputOption::VALUE_REQUIRED, 'Artifact path', 'migration/introspection.json')
            ->addOption('static', null, InputOption::VALUE_NONE, 'Skip the kernel boot and read attributes statically');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $source = (string) $input->getOption('source');

        if ($source === '' || !is_dir($source)) {
            $io->error('--source must point at a legacy checkout.');

            return Command::INVALID;
        }

        // Booting the legacy kernel gives resolved metadata — inheritance, join tables,
        // exact column types — but requires a checkout that runs on this machine's PHP.
        // Runs as a child process so the legacy app's dependencies never mix with ours,
        // and a checkout that cannot boot fails that process, not this one.
        $note = null;
        $introspector = new Introspector();
        $artifact = $introspector->introspect($source, (bool) $input->getOption('static'), $note);
        $entities = (array) $artifact['entities'];
        $mode = (string) $artifact['mode'];

        if ($note !== null) {
            $io->note(sprintf('Kernel boot failed (%s) — falling back to the static scan.', $note));
        }

        if ($mode === 'static' && !$input->getOption('static')) {
            $io->warning('Reading ORM attributes statically — inheritance and associations are best-effort. A booted kernel gives the exact metadata.');
        }

        $out = (string) $input->getOption('out');

        try {
            $introspector->write($artifact, $out);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $m2m = 0;

        foreach ($entities as $spec) {
            foreach ((array) ($spec['associations'] ?? []) as $assoc) {
                if (($assoc['kind'] ?? '') === 'ManyToMany' && !isset($assoc['mappedBy'])) {
                    $m2m++;
                }
            }
        }

        $io->success(sprintf(
            '%s — %d entities (%s), %d owning ManyToMany, %d sidecar wirings, %d form types.',
            $out,
            count($entities),
            $mode,
            $m2m,
            count($artifact['sidecars']),
            count($artifact['formTypes']),
        ));
        $io->text('Commit the artifact; `validate --introspection=' . $out . '` checks the mapping against it.');

        return Command::SUCCESS;
    }
}
