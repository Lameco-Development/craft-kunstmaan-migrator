<?php

declare(strict_types=1);

namespace Lameco\KumaCompile\Legacy;

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

    /**
     * Live placements per Kunstmaan context — `main`, `form`, the eight `footer-*`.
     *
     * A context is where a lane's work lands: `form` placements need the forms lane, `footer-*`
     * the globals lane, and the editorial ones the blocks lane. Sizing a quote means knowing
     * how many of each, and the number is one GROUP BY away from data already being read.
     *
     * @return array<string, int> context => live placements
     */
    public function livePlacementsByContext(): array
    {
        $sql = sprintf(
            'SELECT r.context AS context, COUNT(*) AS n
             FROM kuma_page_part_refs r
             JOIN (%s) l ON l.pageEntityname = r.pageEntityname AND l.pageId = r.pageId
             GROUP BY r.context',
            self::LIVE_PAGES,
        );

        $counts = [];

        foreach ($this->pdo->query($sql) as $row) {
            $counts[(string) $row['context']] = (int) $row['n'];
        }

        arsort($counts);

        return $counts;
    }

    /**
     * Every live pagepart placement on one node, in the order the page holds them.
     *
     * The per-entry question — "these parts became these blocks, and these did not" — needs the
     * left-hand side, and nothing records it: the state row knows what *was* written, not what
     * was there to write. Resolving it through the published version of each translation is the
     * same rule as everywhere else here, so what comes back is what the live page shows.
     *
     * @return list<array{lang: string, context: string, part: string, entity: string, id: int, sequence: int}>
     */
    public function livePartsOfNode(int $nodeId): array
    {
        $sql = sprintf(
            'SELECT l.lang AS lang, r.context AS context, r.page_part_entityname AS entity,
                    r.page_part_id AS partId, r.sequencenumber AS sequence
             FROM kuma_page_part_refs r
             JOIN (%s) l ON l.pageEntityname = r.pageEntityname AND l.pageId = r.pageId
             JOIN kuma_node_translations t2 ON t2.node_id = :node
             JOIN kuma_node_versions v2 ON v2.id = t2.public_node_version_id
               AND v2.ref_id = r.pageId AND v2.ref_entity_name = r.pageEntityname
             GROUP BY l.lang, r.context, r.page_part_entityname, r.page_part_id, r.sequencenumber
             ORDER BY l.lang, r.context, r.sequencenumber',
            self::LIVE_PAGES,
        );

        $statement = $this->pdo->prepare($sql);
        $statement->execute(['node' => $nodeId]);
        $out = [];

        foreach ($statement as $row) {
            $out[] = [
                'lang' => (string) $row['lang'],
                'context' => (string) $row['context'],
                'part' => self::shortName((string) $row['entity'], 'PagePart'),
                'entity' => (string) $row['entity'],
                'id' => (int) $row['partId'],
                'sequence' => (int) $row['sequence'],
            ];
        }

        return $out;
    }

    /**
     * Live placements grouped by the page type they sit on and the pagepart class they are.
     *
     * Whether a part's block is *permitted* on a page's Matrix is a schema question, and which
     * parts actually land on which page types is a data question. Neither half alone can say
     * whether an allow-list rejection costs anything, which is why the pairing has to be read
     * from the corpus rather than guessed from the mapping.
     *
     * @return array<string, array<string, int>> short page entity => short pagepart class => live placements
     */
    public function livePlacementsByPageType(): array
    {
        $sql = sprintf(
            'SELECT l.pageEntityname AS entity, r.page_part_entityname AS part, COUNT(*) AS n
             FROM kuma_page_part_refs r
             JOIN (%s) l ON l.pageEntityname = r.pageEntityname AND l.pageId = r.pageId
             GROUP BY l.pageEntityname, r.page_part_entityname',
            self::LIVE_PAGES,
        );

        $out = [];

        foreach ($this->pdo->query($sql) as $row) {
            $page = self::shortName((string) $row['entity']);
            $part = self::shortName((string) $row['part'], 'PagePart');
            $out[$page][$part] = ($out[$page][$part] ?? 0) + (int) $row['n'];
        }

        return $out;
    }

    /**
     * The same, for every live node in the environment, in one query.
     *
     * A corpus sweep asking `livePartsOfNode()` two thousand times runs the live-pages subquery
     * two thousand times over a table of 158,000 rows. This walks the join once and buckets the
     * result by node — 7,279 rows on the largest reference environment, which is nothing to
     * hold — so the sweep is one query rather than a query per entry.
     *
     * Written out rather than reusing `LIVE_PAGES`: that constant selects DISTINCT over
     * (page, id, lang) and adding `node_id` to it would change what DISTINCT means for its
     * other three callers.
     *
     * @return array<int, list<array{lang: string, context: string, part: string, entity: string, id: int, sequence: int}>>
     */
    public function livePartsByNode(): array
    {
        $sql = <<<'SQL'
            SELECT t.node_id AS nodeId, t.lang AS lang, r.context AS context,
                   r.page_part_entityname AS entity, r.page_part_id AS partId,
                   r.sequencenumber AS sequence
            FROM kuma_node_translations t
            JOIN kuma_nodes n ON n.id = t.node_id AND n.deleted = 0
            JOIN kuma_node_versions v ON v.id = t.public_node_version_id
            JOIN kuma_page_part_refs r
              ON r.pageEntityname = v.ref_entity_name AND r.pageId = v.ref_id
            WHERE t.online = 1 AND v.ref_id IS NOT NULL
            ORDER BY t.node_id, t.lang, r.context, r.sequencenumber
            SQL;

        $out = [];

        foreach ($this->pdo->query($sql) as $row) {
            $out[(int) $row['nodeId']][] = [
                'lang' => (string) $row['lang'],
                'context' => (string) $row['context'],
                'part' => self::shortName((string) $row['entity'], 'PagePart'),
                'entity' => (string) $row['entity'],
                'id' => (int) $row['partId'],
                'sequence' => (int) $row['sequence'],
            ];
        }

        return $out;
    }

    /**
     * `SELECT COUNT(*)` on a table that may not be there.
     *
     * The twelve surveyed installs share eighteen bundles but not every table: an install
     * without the form bundle has no `kuma_form_submissions`, and a survey that dies on it
     * cannot survey anything. A missing table is null — "not installed" — not zero.
     */
    public function countOrNull(string $table, ?string $where = null): ?int
    {
        if (!$this->hasTable($table)) {
            return null;
        }

        $sql = sprintf('SELECT COUNT(*) FROM %s', $table) . ($where !== null ? ' WHERE ' . $where : '');

        return (int) $this->pdo->query($sql)->fetchColumn();
    }


    /**
     * How often a column is actually populated, across live placements of one pagepart class.
     *
     * The compiler drops empty values rather than writing them, so a required Craft field fed by
     * a column that is empty on part of the corpus fails on exactly those rows. Counting them up
     * front turns that from a load-time surprise into a decision.
     *
     * @return array{rows:int, empty:int}
     */
    /** @param list<string> $partEntities every entity name that shares this pagepart's short class name */
    public function columnFill(array $partEntities, string $table, string $column): array
    {
        $sql = sprintf(
            'SELECT COUNT(*) AS n,
                    SUM(CASE WHEN t.`%s` IS NULL OR TRIM(t.`%s`) = %s THEN 1 ELSE 0 END) AS empties
             FROM kuma_page_part_refs r
             JOIN (%s) l ON l.pageEntityname = r.pageEntityname AND l.pageId = r.pageId
             JOIN `%s` t ON t.id = r.page_part_id
             WHERE r.page_part_entityname IN (%s)',
            $column,
            $column,
            "''",
            self::LIVE_PAGES,
            $table,
            self::placeholders($partEntities),
        );

        return $this->fill($sql, $partEntities);
    }

    /**
     * The same, for a child collection: rows are counted per child row, scoped to children of
     * live parents.
     *
     * @return array{rows:int, empty:int}
     */
    /** @param list<string> $partEntities */
    public function childColumnFill(
        array $partEntities,
        string $table,
        string $childTable,
        string $fk,
        string $column,
    ): array {
        $sql = sprintf(
            'SELECT COUNT(*) AS n,
                    SUM(CASE WHEN c.`%s` IS NULL OR TRIM(c.`%s`) = %s THEN 1 ELSE 0 END) AS empties
             FROM `%s` c
             WHERE c.`%s` IN (
                 SELECT t.id
                 FROM kuma_page_part_refs r
                 JOIN (%s) l ON l.pageEntityname = r.pageEntityname AND l.pageId = r.pageId
                 JOIN `%s` t ON t.id = r.page_part_id
                 WHERE r.page_part_entityname IN (%s)
             )',
            $column,
            $column,
            "''",
            $childTable,
            $fk,
            self::LIVE_PAGES,
            $table,
            self::placeholders($partEntities),
        );

        return $this->fill($sql, $partEntities);
    }

    /**
     * A page entity's own column, across its live pages.
     *
     * @return array{rows:int, empty:int}
     */
    public function pageColumnFill(string $pageEntity, string $table, string $column): array
    {
        $sql = sprintf(
            'SELECT COUNT(*) AS n,
                    SUM(CASE WHEN t.`%s` IS NULL OR TRIM(t.`%s`) = %s THEN 1 ELSE 0 END) AS empties
             FROM (%s) l
             JOIN `%s` t ON t.id = l.pageId
             WHERE l.pageEntityname LIKE ?',
            $column,
            $column,
            "''",
            self::LIVE_PAGES,
            $table,
        );

        return $this->fill($sql, ['%\\\\' . $pageEntity]);
    }

    /**
     * For every pagepart class, how many live placements are immediately preceded by `$head` in
     * the same context.
     *
     * This is what makes a heading supplied by the absorb rule measurable: `officesMapBlock.heading`
     * is required and the mapping never maps it, but the sequence lane fills it — for the
     * placements that actually have a Header in front of them, and no others.
     *
     * @return array<string, array{live:int, preceded:int}> short pagepart class => counts
     */
    /** @param list<string> $head every entity name of the pagepart the absorb rule consumes */
    public function precededBy(array $head): array
    {
        $sql = sprintf(
            'SELECT x.part AS part, COUNT(*) AS n, SUM(x.prev IN (%s)) AS p
             FROM (
                 SELECT r.page_part_entityname AS part,
                        LAG(r.page_part_entityname) OVER (
                            PARTITION BY r.pageEntityname, r.pageId, r.context
                            ORDER BY r.sequencenumber, r.id
                        ) AS prev
                 FROM kuma_page_part_refs r
                 JOIN (%s) l ON l.pageEntityname = r.pageEntityname AND l.pageId = r.pageId
             ) x
             GROUP BY x.part',
            self::placeholders($head),
            self::LIVE_PAGES,
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($head);

        $counts = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $counts[self::shortName((string) $row['part'], 'PagePart')] = [
                'live' => (int) $row['n'],
                'preceded' => (int) $row['p'],
            ];
        }

        return $counts;
    }

    /**
     * Short pagepart class name => every fully qualified entity name that shares it.
     *
     * A list rather than a string because the corpus has genuine collisions: `GoogleMapsPagePart`
     * exists under both `App\Entity\PageParts` and `Lameco\MasterBundle\Entity\PageParts`, and
     * the mapping names the short class. Keeping one of the two silently halves the count.
     *
     * @return array<string, list<string>>
     */
    public function partEntities(): array
    {
        $entities = [];

        foreach ($this->pdo->query('SELECT DISTINCT page_part_entityname FROM kuma_page_part_refs') as $row) {
            $entity = (string) $row['page_part_entityname'];
            $entities[self::shortName($entity, 'PagePart')][] = $entity;
        }

        return $entities;
    }

    /** @param list<string> $values */
    private static function placeholders(array $values): string
    {
        return $values === [] ? 'NULL' : implode(', ', array_fill(0, count($values), '?'));
    }

    /**
     * @param list<string> $params
     * @return array{rows:int, empty:int}
     */
    private function fill(string $sql, array $params): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return ['rows' => (int) ($row['n'] ?? 0), 'empty' => (int) ($row['empties'] ?? 0)];
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
