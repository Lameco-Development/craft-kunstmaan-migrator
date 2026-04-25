<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\db;

use Craft;
use Generator;
use yii\base\Component;
use yii\db\Connection;

/**
 * Read-only accessor for the legacy Kunstmaan MySQL DB.
 *
 * Discipline (D-13): no writes. Code review enforces that no insert / update / delete
 * call ever appears in this file. Any legacy-side mutation belongs in an ad-hoc dev
 * console, not in plugin code.
 *
 * The underlying `legacyDb` Yii application component is registered by `Plugin::init()`
 * (D-11) when the host hasn't already declared one in `config/app.php` — this service
 * resolves it via `Craft::$app->get('legacyDb')` on every call so test doubles can
 * replace the component without re-wiring this class.
 */
class LegacyDbService extends Component
{
    public function db(): Connection
    {
        /** @var Connection $conn */
        $conn = Craft::$app->get('legacyDb');
        return $conn;
    }

    /** @param array<string, mixed> $params */
    public function queryOne(string $sql, array $params = []): ?array
    {
        $row = $this->db()->createCommand($sql, $params)->queryOne();
        return $row ?: null;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function queryAll(string $sql, array $params = []): array
    {
        return $this->db()->createCommand($sql, $params)->queryAll();
    }

    /** @param array<string, mixed> $params */
    public function queryScalar(string $sql, array $params = []): mixed
    {
        return $this->db()->createCommand($sql, $params)->queryScalar();
    }

    /**
     * @param array<string, mixed> $params
     * @return Generator<int, array<string, mixed>>
     */
    public function streamQuery(string $sql, array $params = []): Generator
    {
        $reader = $this->db()->createCommand($sql, $params)->query();
        try {
            foreach ($reader as $row) {
                yield $row;
            }
        } finally {
            $reader->close();
        }
    }
}
