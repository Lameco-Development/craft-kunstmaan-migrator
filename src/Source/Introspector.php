<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\Source;

/**
 * Produces the introspection artifact — shared by the `introspect` command and the setup
 * wizard, so the CP path gets the same booted metadata the CLI path does.
 */
final class Introspector
{
    /**
     * @return array{mode: string, source: string, entities: array<string, mixed>, sidecars: list<array<string, mixed>>, formTypes: array<string, mixed>}
     */
    public function introspect(string $source, bool $forceStatic = false, ?string &$note = null): array
    {
        $scanner = new SourceScanner($source);
        $entities = null;
        $mode = 'static';

        if (!$forceStatic) {
            $probed = $this->probe($source, $note);

            if ($probed !== null) {
                $entities = (array) ($probed['entities'] ?? []);
                $mode = 'boot';
            }
        }

        if ($entities === null) {
            $entities = $scanner->staticEntities();
        }

        return [
            'mode' => $mode,
            'source' => rtrim($source, '/'),
            'entities' => $entities,
            'sidecars' => $scanner->sidecarListeners(),
            'formTypes' => $scanner->formTypes(),
        ];
    }

    /** @param array<string, mixed> $artifact */
    public function write(array $artifact, string $out): void
    {
        $dir = dirname($out);

        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Cannot create %s', $dir));
        }

        file_put_contents($out, json_encode($artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }

    /** @return array<string, mixed>|null the probe's JSON, or null when the checkout cannot boot */
    private function probe(string $source, ?string &$note): ?array
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
            $note = trim($stderr) !== '' ? trim((string) strtok($stderr, "\n")) : 'exit ' . $exit;

            return null;
        }

        $data = json_decode($stdout, true);

        return is_array($data) ? $data : null;
    }
}
