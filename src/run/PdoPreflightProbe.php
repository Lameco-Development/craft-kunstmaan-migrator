<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\run;

use Lameco\KumaCompile\Legacy\Dsn;
use PDO;
use Throwable;

/**
 * The production probe: one PDO connection per database, and is_readable().
 *
 * Thin, like every other adapter here — a connection is opened, a count is
 * read, nothing is decided.
 */
final class PdoPreflightProbe implements PreflightProbe
{
    /** @var array<string, PDO|false> */
    private array $connections = [];

    /** @var array<string, string> */
    private array $errors = [];

    public function __construct(private readonly Dsn $dsn)
    {
    }

    public function reachable(string $database): bool
    {
        return $this->connect($database) !== false;
    }

    public function nodeCount(string $database): ?int
    {
        $pdo = $this->connect($database);

        if ($pdo === false) {
            return null;
        }

        try {
            $statement = $pdo->query('SELECT COUNT(*) FROM `kuma_nodes`');

            return $statement === false ? null : (int) $statement->fetchColumn();
        } catch (Throwable) {
            return null;
        }
    }

    public function connectionError(string $database): ?string
    {
        $this->connect($database);

        return $this->errors[$database] ?? null;
    }

    public function directoryReadable(string $path): bool
    {
        return $path !== '' && is_dir($path) && is_readable($path);
    }

    private function connect(string $database): PDO|false
    {
        if (array_key_exists($database, $this->connections)) {
            return $this->connections[$database];
        }

        try {
            return $this->connections[$database] = new PDO(
                $this->dsn->forDatabase($database),
                $this->dsn->user,
                $this->dsn->password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5],
            );
        } catch (Throwable $e) {
            $this->errors[$database] = $e->getMessage();

            return $this->connections[$database] = false;
        }
    }
}
