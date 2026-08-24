<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\db;

use craft\helpers\App;
use Generator;
use Lameco\Kunstmaanmigrator\Plugin;
use Lameco\Kunstmaanmigrator\Source\Dsn;
use Lameco\Kunstmaanmigrator\Source\KunstmaanCoreTables;
use PDO;
use yii\base\Component;

/**
 * Read-only accessor for the legacy Kunstmaan MySQL DB.
 *
 * Discipline (D-13): no writes. Code review enforces that no insert / update / delete
 * call ever appears in this file. Any legacy-side mutation belongs in an ad-hoc dev
 * console, not in plugin code.
 *
 * One access layer, one connection: during a migration run the pipeline hands
 * this service the SAME PDO the compile half opened (`usePdo()`, called from
 * `EnvironmentPipeline`), so both halves read one environment through one
 * connection. Outside a run — doctor, JIT lookups from a console command —
 * the connection is opened lazily from Settings, database name included,
 * mirroring what the old `legacyDb` Yii application component did.
 */
class LegacyDbService extends Component
{
    private ?PDO $pdo = null;

    /**
     * Adopt the connection the compile half already holds. Per-environment:
     * the pipeline calls this on every environment switch, so a DE run never
     * reads COM's tables through a stale handle.
     */
    public function usePdo(PDO $pdo): void
    {
        $this->pdo = $pdo;
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $settings = Plugin::getInstance()->getSettings();
            $connection = $settings->legacyConnection();
            $dsn = new Dsn(
                host: (string) $connection['host'],
                port: (int) $connection['port'],
                user: (string) $connection['user'],
                password: (string) $connection['password'],
                charset: (string) $connection['charset'],
            );

            // The database name is per environment and comes from the mapping;
            // this lazy fallback serves commands that run outside a migration.
            $this->pdo = new PDO(
                $dsn->forDatabase((string) App::parseEnv($settings->legacyDbDatabase)),
                $dsn->user,
                $dsn->password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ],
            );
        }

        return $this->pdo;
    }

    /** @param array<string, mixed> $params */
    public function queryOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function queryAll(string $sql, array $params = []): array
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @param array<string, mixed> $params */
    public function queryScalar(string $sql, array $params = []): mixed
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    /**
     * @param array<string, mixed> $params
     * @return Generator<int, array<string, mixed>>
     */
    public function streamQuery(string $sql, array $params = []): Generator
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        try {
            while (($row = $stmt->fetch()) !== false) {
                yield $row;
            }
        } finally {
            $stmt->closeCursor();
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
     * @return list<array{lang: string, online: int, title: string, slug: string, url: string, created: ?string, ref_id: int|null}>
     */
    public function translationsFor(int $nodeId): array
    {
        $nv = KunstmaanCoreTables::NODE_VERSIONS;
        return $this->queryAll(
            'SELECT t.lang, t.online, t.title, t.slug, t.url, t.created, v.ref_id'
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
     * Phase 8 / D-08 — verbatim port of v1's extTranslationsFor() (v1
     * LegacyDbService lines 214-250). Reshape: literal 'ext_translations'
     * replaced with KunstmaanCoreTables::EXT_TRANSLATIONS (v2 convention).
     *
     * Returns Gedmo Translatable rows for the given entity FQCN(s) and legacy
     * id, keyed by locale and field. Empty result `[]` is the consumed-as-
     * monolingual signal — D-09's source-locale fallback lives in
     * TaxonomyMigrationService, NOT here.
     *
     * Named bind parameters (`:fqcn0`, `:fqcn1`, `:foreignKey`) are mandatory:
     * Yii 2 / PDO have a positional-index mismatch when `?` placeholders are
     * mixed with the IN-clause expansion shape.
     *
     * Canonical-FQCN-first iteration semantics: when a caller passes
     * `[$canonical, $alias]`, rows whose `object_class` matches the alias are
     * later overwritten by canonical rows at the same locale+field key.
     *
     * @param string|string[] $fqcns
     * @return array<string, array<string, string>>
     */
    public function extTranslationsFor(string|array $fqcns, int $id): array
    {
        $list = is_array($fqcns) ? array_values($fqcns) : [$fqcns];
        if ($list === []) {
            return [];
        }
        $namedParams = [':foreignKey' => $id];
        $placeholders = [];
        foreach ($list as $i => $fqcn) {
            $key = ':fqcn' . $i;
            $namedParams[$key] = $fqcn;
            $placeholders[] = $key;
        }
        $inClause = implode(',', $placeholders);
        $rows = $this->queryAll(
            'SELECT object_class, locale, field, content FROM ' . KunstmaanCoreTables::EXT_TRANSLATIONS
            . " WHERE object_class IN ($inClause) AND foreign_key = :foreignKey",
            $namedParams,
        );
        $result = [];
        foreach ($rows as $row) {
            $locale = (string) $row['locale'];
            $field = (string) $row['field'];
            $content = (string) ($row['content'] ?? '');
            $result[$locale][$field] = $content;
        }
        return $result;
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
