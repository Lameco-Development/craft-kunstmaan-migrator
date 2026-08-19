<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\legacy;

use PDO;

/**
 * Read-only access to one Kunstmaan environment.
 *
 * Everything here resolves through the *published* node version. Kunstmaan clones the
 * whole pagepart graph per node version, so a query that reads `kuma_page_part_refs`
 * directly sees roughly twenty times the live content. That resolution is not optional
 * and deliberately has no toggle.
 */
final class LegacyDatabase
{
    private const LIVE_PAGES = <<<'SQL'
        SELECT DISTINCT v.ref_entity_name AS pageEntityname, v.ref_id AS pageId, t.lang AS lang
        FROM kuma_node_translations t
        JOIN kuma_nodes n ON n.id = t.node_id AND n.deleted = 0
        JOIN kuma_node_versions v ON v.id = t.public_node_version_id
        WHERE t.online = 1 AND v.ref_id IS NOT NULL
        SQL;

    public function __construct(
        private readonly PDO $pdo,
        public readonly string $environment,
        public readonly string $database,
    ) {
    }

    public static function connect(string $environment, string $database, Dsn $dsn): self
    {
        $pdo = new PDO(
            $dsn->forDatabase($database),
            $dsn->user,
            $dsn->password,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ],
        );

        return new self($pdo, $environment, $database);
    }

    /** The live connection, for the readers that need their own prepared statements. */
    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /** Everything the coverage report needs from this environment, in one pass. */
    public function snapshot(): LiveSnapshot
    {
        return new LiveSnapshot(
            environment: $this->environment,
            partPlacements: $this->livePartPlacements(),
            pageTypes: $this->livePageTypes(),
            pagesByLocale: $this->livePagesByLocale(),
            allPartRefs: $this->countAllPartRefs(),
        );
    }

    /** @return list<string> */
    public function tables(): array
    {
        return $this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    }

    public function hasTable(string $table): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute([$table]);

        return (bool) $stmt->fetchColumn();
    }

    /** @return list<string> */
    public function columns(string $table): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION'
        );
        $stmt->execute([$table]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Live page entities, keyed by the short entity name.
     *
     * @return array<string, int> short page entity name => live count
     */
    public function livePageTypes(): array
    {
        $sql = sprintf(
            'SELECT l.pageEntityname AS entity, COUNT(*) AS n FROM (%s) l GROUP BY l.pageEntityname',
            self::LIVE_PAGES,
        );

        $counts = [];

        foreach ($this->pdo->query($sql) as $row) {
            $counts[self::shortName((string) $row['entity'])] = (int) $row['n'];
        }

        arsort($counts);

        return $counts;
    }

    /**
     * Live pagepart placements, keyed by the short pagepart class name.
     *
     * @return array<string, int> short pagepart class name => live placements
     */
    public function livePartPlacements(): array
    {
        $sql = sprintf(
            'SELECT r.page_part_entityname AS part, COUNT(*) AS n
             FROM kuma_page_part_refs r
             JOIN (%s) l ON l.pageEntityname = r.pageEntityname AND l.pageId = r.pageId
             GROUP BY r.page_part_entityname',
            self::LIVE_PAGES,
        );

        $counts = [];

        foreach ($this->pdo->query($sql) as $row) {
            $counts[self::shortName((string) $row['part'], 'PagePart')] = (int) $row['n'];
        }

        arsort($counts);

        return $counts;
    }

    /**
     * Live pages per legacy locale — the figure that decides how much content a
     * missing Craft site strands.
     *
     * @return array<string, int> lang => live pages
     */
    public function livePagesByLocale(): array
    {
        $sql = sprintf('SELECT l.lang AS lang, COUNT(*) AS n FROM (%s) l GROUP BY l.lang', self::LIVE_PAGES);

        $counts = [];

        foreach ($this->pdo->query($sql) as $row) {
            $counts[(string) $row['lang']] = (int) $row['n'];
        }

        arsort($counts);

        return $counts;
    }

    public function countAllPartRefs(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM kuma_page_part_refs')->fetchColumn();
    }

    /** Strips the PHP namespace, and optionally a class-name suffix, from a Doctrine entity name. */
    private static function shortName(string $entity, string $suffix = ''): string
    {
        $short = substr((string) strrchr($entity, '\\'), 1) ?: $entity;

        if ($suffix !== '' && str_ends_with($short, $suffix)) {
            $short = substr($short, 0, -strlen($suffix));
        }

        return $short;
    }
}
