<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\legacy;

use PDO;

/**
 * Reads the published page tree of one Kunstmaan environment.
 *
 * A Kunstmaan *node* is the thing that becomes one Craft entry; its *translations* become
 * that entry's sites. Each translation points at its own page entity row, so field values
 * genuinely differ per locale — which is exactly Craft's per-site content model.
 */
final class PageReader
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Published nodes with their translations, parent and per-translation page entity.
     *
     * @return list<array{
     *     nodeId:int, parentId:?int, entity:string,
     *     translations: list<array{lang:string, title:string, slug:?string, url:?string,
     *                              entity:string, entityId:int, created:?string}>
     * }>
     */
    public function nodes(): array
    {
        $sql = <<<'SQL'
            SELECT n.id AS nodeId, n.parent_id AS parentId,
                   t.lang, t.title, t.slug, t.url, t.created,
                   v.ref_entity_name AS entity, v.ref_id AS entityId
            FROM kuma_node_translations t
            JOIN kuma_nodes n ON n.id = t.node_id AND n.deleted = 0
            JOIN kuma_node_versions v ON v.id = t.public_node_version_id
            WHERE t.online = 1 AND v.ref_id IS NOT NULL
            ORDER BY n.lft, n.id, t.lang
            SQL;

        $nodes = [];

        foreach ($this->pdo->query($sql) as $row) {
            $id = (int) $row['nodeId'];

            $nodes[$id] ??= [
                'nodeId' => $id,
                'parentId' => $row['parentId'] !== null ? (int) $row['parentId'] : null,
                'entity' => self::shortName((string) $row['entity']),
                'translations' => [],
            ];

            $nodes[$id]['translations'][] = [
                'lang' => (string) $row['lang'],
                'title' => (string) $row['title'],
                'slug' => $row['slug'] !== null ? (string) $row['slug'] : null,
                'url' => $row['url'] !== null ? (string) $row['url'] : null,
                'entity' => self::shortName((string) $row['entity']),
                'entityId' => (int) $row['entityId'],
                'created' => $row['created'] !== null ? (string) $row['created'] : null,
            ];
        }

        // A node whose translations disagree on entity type is a legacy inconsistency, not
        // something to average over; the first published translation wins and the compiler
        // reports it.
        return array_values($nodes);
    }

    /** The set of node ids that are published, so a parentRef can be checked before it is emitted. */
    public function publishedNodeIds(): array
    {
        $ids = [];

        foreach ($this->nodes() as $node) {
            $ids[$node['nodeId']] = true;
        }

        return $ids;
    }

    private static function shortName(string $entity): string
    {
        return substr((string) strrchr($entity, '\\'), 1) ?: $entity;
    }
}
