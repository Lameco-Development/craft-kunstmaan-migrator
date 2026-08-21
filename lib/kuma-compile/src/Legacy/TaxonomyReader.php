<?php

declare(strict_types=1);

namespace Lameco\KumaCompile\Legacy;

use PDO;

/**
 * Reads a whole non-node table — the taxonomies a Kunstmaan site keeps outside its node tree.
 *
 * There is no published-version resolution to do here: these rows are not versioned content,
 * they are the reference data pages point at. The one filter that matters is the soft delete
 * Doctrine's SoftDeleteable puts on some of them, and a mapping has to name that column
 * because only about half the tables carry one.
 */
final class TaxonomyReader
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Every live row of `$table`, in id order.
     *
     * Ordered because the compiler's output has to be byte-identical between runs, and MySQL
     * does not promise an order for an unordered select.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(string $table, ?string $softDelete = null): array
    {
        $sql = sprintf('SELECT * FROM `%s`', $table);

        if ($softDelete !== null && $softDelete !== '') {
            $sql .= sprintf(' WHERE `%s` IS NULL', $softDelete);
        }

        return $this->pdo->query($sql . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    }
}
