<?php

declare(strict_types=1);

namespace Lameco\KumaCompile\Legacy;

use PDO;
use Throwable;

/**
 * What Kunstmaan sites are on this database server.
 *
 * Setting a migration up begins with three facts nobody should have to type:
 * which databases exist, which of them are Kunstmaan, and which locales each
 * one publishes. All three are a query away, and asking an operator to
 * remember `enreach_website_lv` — or to find out that `enreach_website_oss` is
 * a near-duplicate of it — is how a migration starts against the wrong corpus.
 */
final class LegacyCatalogue
{
    /**
     * The three tables every Kunstmaan schema has. A database carrying all
     * three is one; anything else on the server is somebody else's.
     */
    private const CORE_TABLES = ['kuma_nodes', 'kuma_node_translations', 'kuma_node_versions'];

    public function __construct(private readonly Dsn $dsn)
    {
    }

    /**
     * Every Kunstmaan database on the server, with how much live content it
     * holds — one query against information_schema rather than a connection
     * per database.
     *
     * @return list<array{database: string, nodes: int}>
     */
    public function kunstmaanDatabases(): array
    {
        $pdo = $this->connect('information_schema');

        $statement = $pdo->prepare(sprintf(
            'SELECT TABLE_SCHEMA AS db FROM information_schema.TABLES
             WHERE TABLE_NAME IN (%s)
             GROUP BY TABLE_SCHEMA
             HAVING COUNT(DISTINCT TABLE_NAME) = %d
             ORDER BY TABLE_SCHEMA',
            implode(', ', array_fill(0, count(self::CORE_TABLES), '?')),
            count(self::CORE_TABLES),
        ));
        $statement->execute(self::CORE_TABLES);

        $out = [];

        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $database) {
            $out[] = ['database' => (string) $database, 'nodes' => $this->nodeCount((string) $database)];
        }

        return $out;
    }

    /**
     * The locales a database publishes, with live pages each.
     *
     * The counts are the point: an operator deciding which Craft site a locale
     * writes to needs to know that `sp` is 335 pages and `ru` is 63, because
     * that is the difference between a locale worth a site and one worth an
     * `!unmapped` line.
     *
     * @return array<string, int> lang => live pages
     */
    public function locales(string $database): array
    {
        try {
            $statement = $this->connect($database)->query(
                'SELECT t.lang, COUNT(*) AS pages
                 FROM kuma_node_translations t
                 INNER JOIN kuma_nodes n ON n.id = t.node_id AND n.deleted = 0
                 WHERE t.online = 1 AND t.public_node_version_id IS NOT NULL
                 GROUP BY t.lang
                 ORDER BY pages DESC'
            );
        } catch (Throwable) {
            return [];
        }

        $out = [];

        foreach ($statement?->fetchAll(PDO::FETCH_ASSOC) ?? [] as $row) {
            $out[(string) $row['lang']] = (int) $row['pages'];
        }

        return $out;
    }

    /**
     * The columns of one legacy table, minus the ones every table has.
     *
     * So a field map can be chosen from what is actually in the database rather
     * than typed from memory — which is the difference between picking `niv`
     * and finding out an hour into a run that you wrote `level`.
     *
     * @return list<string>
     */
    public function columns(string $database, string $table): array
    {
        if ($table === '') {
            return [];
        }

        try {
            $statement = $this->connect($database)->prepare(
                'SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
                 ORDER BY ORDINAL_POSITION'
            );
            $statement->execute([$database, $table]);
        } catch (Throwable) {
            return [];
        }

        return array_values(array_diff(
            array_map(strval(...), $statement->fetchAll(PDO::FETCH_COLUMN)),
            ['id'],
        ));
    }

    private function nodeCount(string $database): int
    {
        try {
            return (int) $this->connect($database)
                ->query('SELECT COUNT(*) FROM kuma_nodes WHERE deleted = 0')
                ?->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * A handful of rows from a table, for showing an operator what a column
     * actually holds. A sample, never the basis of a migration read.
     *
     * @return list<array<string, mixed>>
     */
    public function sampleRows(string $database, string $table, int $limit = 8): array
    {
        // Identifiers cannot be bound; refusing anything but a plain name is
        // the whole injection surface.
        if (!preg_match('/^\w+$/', $database) || !preg_match('/^\w+$/', $table)) {
            return [];
        }

        try {
            $statement = $this->connect($database)->query(sprintf(
                'SELECT * FROM `%s`.`%s` LIMIT %d',
                $database,
                $table,
                max(1, $limit),
            ));

            return $statement === false ? [] : $statement->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return [];
        }
    }

    private function connect(string $database): PDO
    {
        return new PDO(
            $this->dsn->forDatabase($database),
            $this->dsn->user,
            $this->dsn->password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
        );
    }
}
