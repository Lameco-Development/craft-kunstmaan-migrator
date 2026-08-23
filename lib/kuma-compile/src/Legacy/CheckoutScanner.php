<?php

declare(strict_types=1);

namespace Lameco\KumaCompile\Legacy;

/**
 * Kunstmaan checkouts on this machine, found instead of typed.
 *
 * If the assets are being migrated, a working copy of the legacy site is almost certainly
 * sitting in a sibling folder — and that copy already knows everything the wizard's first
 * screens ask for: the database credentials are in its `.env`, the uploads live under
 * `public/uploads/media`, and `composer.lock` says whether it is a Kunstmaan site at all.
 * So the wizard scans and offers, and the operator confirms or overrides, rather than
 * copying values out of a file by hand.
 */
final class CheckoutScanner
{
    /**
     * Every Kunstmaan checkout directly under a folder.
     *
     * @return list<array{
     *   name: string,
     *   path: string,
     *   kunstmaan: ?string,
     *   database: ?array{host: string, port: int, user: string, password: string, database: string},
     *   mediaRoot: ?string,
     * }>
     */
    public function scan(string $root): array
    {
        $root = rtrim($root, '/');

        if (!is_dir($root)) {
            return [];
        }

        $out = [];

        foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $path) {
            $checkout = $this->inspect($path);

            if ($checkout !== null) {
                $out[] = $checkout;
            }
        }

        usort($out, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $out;
    }

    /** @return array{name: string, path: string, kunstmaan: ?string, database: ?array{host: string, port: int, user: string, password: string, database: string}, mediaRoot: ?string}|null */
    public function inspect(string $path): ?array
    {
        $version = $this->kunstmaanVersion($path . '/composer.lock');

        if ($version === null) {
            return null;
        }

        $mediaRoot = $path . '/public/uploads/media';

        return [
            'name' => basename($path),
            'path' => $path,
            'kunstmaan' => $version,
            'database' => $this->databaseFromEnv($path),
            'mediaRoot' => is_dir($mediaRoot) ? $mediaRoot : null,
        ];
    }

    /** The kunstmaan/* version the lock pins, or null when this is not a Kunstmaan site. */
    private function kunstmaanVersion(string $lockFile): ?string
    {
        if (!is_file($lockFile)) {
            return null;
        }

        $lock = json_decode((string) file_get_contents($lockFile), true);

        if (!is_array($lock)) {
            return null;
        }

        $fallback = null;

        foreach ((array) ($lock['packages'] ?? []) as $package) {
            $name = (string) ($package['name'] ?? '');

            if ($name === 'kunstmaan/node-bundle') {
                return (string) ($package['version'] ?? '');
            }

            if ($fallback === null && str_starts_with($name, 'kunstmaan/')) {
                $fallback = (string) ($package['version'] ?? '');
            }
        }

        return $fallback;
    }

    /**
     * The database the checkout's own `.env` names, `.env.local` winning where both speak.
     *
     * Symfony keeps it as one `DATABASE_URL`; the pieces are exactly what the wizard's
     * connect screen asks for, so nobody should be re-typing them out of the file.
     *
     * @return array{host: string, port: int, user: string, password: string, database: string}|null
     */
    private function databaseFromEnv(string $path): ?array
    {
        $values = [];

        foreach ([$path . '/.env', $path . '/.env.local'] as $file) {
            if (is_file($file)) {
                $values = [...$values, ...$this->parseEnv((string) file_get_contents($file))];
            }
        }

        $url = $values['DATABASE_URL'] ?? null;

        if ($url === null) {
            return null;
        }

        $parts = parse_url($url);

        if (!is_array($parts) || !isset($parts['path'])) {
            return null;
        }

        return [
            'host' => (string) ($parts['host'] ?? '127.0.0.1'),
            'port' => (int) ($parts['port'] ?? 3306),
            'user' => rawurldecode((string) ($parts['user'] ?? 'root')),
            'password' => rawurldecode((string) ($parts['pass'] ?? '')),
            'database' => ltrim((string) $parts['path'], '/'),
        ];
    }

    /** @return array<string, string> */
    private function parseEnv(string $contents): array
    {
        $out = [];

        foreach (explode("\n", $contents) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $line, 2);
            $value = trim($value);

            if (str_starts_with($value, '"') && str_ends_with($value, '"') && strlen($value) > 1) {
                $value = substr($value, 1, -1);
            } elseif (str_starts_with($value, "'") && str_ends_with($value, "'") && strlen($value) > 1) {
                $value = substr($value, 1, -1);
            }

            $out[trim($name)] = $value;
        }

        return $out;
    }
}
