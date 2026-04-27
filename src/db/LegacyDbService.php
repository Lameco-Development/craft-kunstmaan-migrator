<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\db;

use Craft;
use Generator;
use lameco\kunstmaanmigrator\source\KunstmaanCoreTables;
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

    /**
     * Stream every live (non-deleted, online-translation-bearing) Kuma node row
     * for a given Page entity FQCN. Drives ExtractService's per-node-class loop.
     *
     * Ported verbatim from v1 LegacyDbService:98 — the SQL is the canonical
     * "live nodes for class X" query that v1 has shipped against production
     * Kunstmaan installs for years. DISTINCT guards against the join multiplying
     * by translation count.
     *
     * @return Generator<int, array{id: int, parent_id: int|null, ref_entity_name: string}>
     */
    public function streamLiveNodes(string $entityClass): Generator
    {
        return $this->streamQuery(
            'SELECT DISTINCT n.id, n.parent_id, n.ref_entity_name'
            . ' FROM ' . KunstmaanCoreTables::NODES . ' n'
            . ' JOIN ' . KunstmaanCoreTables::NODE_TRANSLATIONS . ' t ON t.node_id = n.id'
            . ' WHERE n.deleted = 0'
            . ' AND n.ref_entity_name = :class'
            . ' AND t.online = 1'
            . ' ORDER BY n.id',
            [':class' => $entityClass],
        );
    }

    /**
     * Returns per-language translation rows for a node, each with the per-locale
     * ref_id resolved from Kunstmaan's authoritative `public_node_version_id`
     * FK on kuma_node_translations.
     *
     * Ported verbatim from v1 LegacyDbService:131. The `public_node_version_id`
     * FK mirrors NodeTranslation::getNodeVersion('public') — the row the live
     * site serves from, and the row whose ref_id still has its child collection
     * rows (e.g. lameco_websitebundle_client_item for ClientsPagePart). Using
     * MAX(id) instead silently pulls versioned clones whose scalar columns were
     * copied but whose child-table rows were never cloned.
     *
     * MAX(id WHERE type='public') fallback only when the FK is NULL (defensive
     * — not observed on live rows in production CQM dumps).
     *
     * @return list<array{lang: string, online: int, title: string, slug: string, url: string, ref_id: int|null}>
     */
    public function translationsFor(int $nodeId): array
    {
        $nv = KunstmaanCoreTables::NODE_VERSIONS;
        return $this->queryAll(
            'SELECT t.lang, t.online, t.title, t.slug, t.url, v.ref_id'
            . ' FROM ' . KunstmaanCoreTables::NODE_TRANSLATIONS . ' t'
            . ' LEFT JOIN ' . $nv . ' v'
            . '   ON v.id = COALESCE('
            . '     t.public_node_version_id,'
            . '     ('
            . '       SELECT MAX(v2.id) FROM ' . $nv . ' v2'
            . '       WHERE v2.node_translation_id = t.id AND v2.type = \'public\''
            . '     )'
            . '   )'
            . '   AND v.type = \'public\''
            . ' WHERE t.node_id = :id'
            . ' ORDER BY t.lang',
            [':id' => $nodeId],
        );
    }

    /**
     * Returns the current MySQL database name. Ported verbatim from v1 — used
     * by DoctorController's connectivity check to confirm the connection points
     * at the expected legacy DB rather than just any reachable server.
     */
    public function getDatabaseName(): string
    {
        $name = $this->queryScalar('SELECT DATABASE()');
        return is_string($name) ? $name : '';
    }
}
