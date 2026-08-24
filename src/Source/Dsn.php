<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\Source;

/** Connection details shared by every legacy environment; only the database name varies. */
final readonly class Dsn
{
    public function __construct(
        public string $host = '127.0.0.1',
        public int $port = 3306,
        public string $user = 'root',
        public string $password = '',
        public string $charset = 'utf8mb4',
    ) {
    }

    public static function fromEnvironment(): self
    {
        return new self(
            host: getenv('KUMA_DB_HOST') ?: '127.0.0.1',
            port: (int) (getenv('KUMA_DB_PORT') ?: 3306),
            user: getenv('KUMA_DB_USER') ?: 'root',
            password: getenv('KUMA_DB_PASSWORD') ?: '',
        );
    }

    public function forDatabase(string $database): string
    {
        return sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $this->host,
            $this->port,
            $database,
            $this->charset,
        );
    }
}
