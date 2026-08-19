<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\legacy;

use PDO;

/**
 * Reads the ordered pagepart sequence attached to one page entity, with each part's own row
 * and any child-collection rows.
 *
 * Rows are fetched per page rather than in one sweep: a legacy corpus has ~60 distinct
 * pagepart tables and no single query spans them, so the shape of the work is a join per
 * part class per page. Statements are prepared once and reused.
 */
final class PartReader
{
    /** @var array<string, \PDOStatement> */
    private array $statements = [];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * The ordered part list for one page entity and context.
     *
     * @return list<array{part:string, id:int, sequence:int}>
     */
    public function sequence(string $pageEntity, int $pageId, string $context): array
    {
        $key = 'seq';

        $this->statements[$key] ??= $this->pdo->prepare(
            'SELECT page_part_entityname AS part, page_part_id AS id, sequencenumber AS seq
             FROM kuma_page_part_refs
             WHERE pageEntityname LIKE ? AND pageId = ? AND context = ?
             ORDER BY sequencenumber, id'
        );

        $this->statements[$key]->execute(['%\\\\' . $pageEntity, $pageId, $context]);

        $out = [];

        foreach ($this->statements[$key]->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = [
                'part' => self::shortName((string) $row['part']),
                'id' => (int) $row['id'],
                'sequence' => (int) $row['seq'],
            ];
        }

        return $out;
    }

    /** One pagepart's own row, or null when the row is missing (a legacy dangling ref). */
    public function row(string $table, int $id): ?array
    {
        $key = 'row:' . $table;
        $this->statements[$key] ??= $this->pdo->prepare(sprintf('SELECT * FROM `%s` WHERE id = ?', $table));
        $this->statements[$key]->execute([$id]);
        $row = $this->statements[$key]->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * A child collection's rows for one parent, in editor order.
     *
     * @return list<array<string, mixed>>
     */
    public function children(string $table, string $fk, int $parentId, string $order = 'weight'): array
    {
        $key = sprintf('kids:%s:%s:%s', $table, $fk, $order);
        $this->statements[$key] ??= $this->pdo->prepare(
            sprintf('SELECT * FROM `%s` WHERE `%s` = ? ORDER BY `%s`, id', $table, $fk, $order)
        );
        $this->statements[$key]->execute([$parentId]);

        return $this->statements[$key]->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function shortName(string $entity): string
    {
        $short = substr((string) strrchr($entity, '\\'), 1) ?: $entity;

        return str_ends_with($short, 'PagePart') ? substr($short, 0, -8) : $short;
    }
}
