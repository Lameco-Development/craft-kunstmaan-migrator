<?php

declare(strict_types=1);

namespace Lameco\KumaCompile\Command;

use Lameco\KumaCompile\Legacy\SourceScanner;
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

        $scanner = new SourceScanner($source);
        $entities = null;
        $mode = 'static';

        // Booting the legacy kernel gives resolved metadata — inheritance, join tables,
        // exact column types — but requires a checkout that runs on this machine's PHP.
        // Runs as a child process so the legacy app's dependencies never mix with ours,
        // and a checkout that cannot boot fails that process, not this one.
        if (!$input->getOption('static')) {
            $probed = $this->probe($source, $io);

            if ($probed !== null) {
                $entities = (array) ($probed['entities'] ?? []);
                $mode = 'boot';
            }
        }

        if ($entities === null) {
            $io->warning('Reading ORM attributes statically — inheritance and associations are best-effort. A booted kernel gives the exact metadata.');
            $entities = $scanner->staticEntities();
        }

        $artifact = [
            'mode' => $mode,
            'source' => rtrim($source, '/'),
            'entities' => $entities,
            // The two scans no Doctrine metadata carries, static either way: what the
            // NodeListener wires into the page UI, and which fields each form draws.
            'sidecars' => $scanner->sidecarListeners(),
            'formTypes' => $scanner->formTypes(),
        ];

        $out = (string) $input->getOption('out');
        $dir = dirname($out);

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            $io->error(sprintf('Cannot create %s', $dir));

            return Command::FAILURE;
        }

        file_put_contents($out, json_encode($artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

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

    /** @return array<string, mixed>|null the probe's JSON, or null when the checkout cannot boot */
    private function probe(string $source, SymfonyStyle $io): ?array
    {
        $script = dirname(__DIR__, 2) . '/resources/introspect-probe.php';
        $process = proc_open(
            [PHP_BINARY, $script, $source],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $source,
        );

        if (!is_resource($process)) {
            return null;
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        if ($exit !== 0) {
            $io->note(sprintf('Kernel boot failed (%s) — falling back to the static scan.', trim($stderr) !== '' ? trim(strtok($stderr, "\n") ?: '') : 'exit ' . $exit));

            return null;
        }

        $data = json_decode($stdout, true);

        return is_array($data) ? $data : null;
    }
}
