<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\Source;

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
    /**
     * @param ?string $offlineCutoff a date an offline translation must have been saved on or
     *                               after to be read anyway; null reads only published ones
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly ?string $offlineCutoff = null,
    ) {
    }

    /**
     * Nodes that become entries, with their translations, parent and per-translation page entity.
     *
     * A translation is here when it is published, or when it is switched off but was saved on
     * or after `offlineCutoff` — the second kind is editorial work in progress, and it arrives
     * with `online` false so the compiler writes it disabled rather than publishing it.
     *
     * The join onto the *public* version is what bounds the rescue: a translation switched off
     * after having been live keeps that pointer, so its content is readable. One that was never
     * published has no public version and no page entity to read parts from — its content lives
     * in a draft version — so no cutoff can reach it.
     *
     * @return list<array{
     *     nodeId:int, parentId:?int, entity:string,
     *     translations: list<array{lang:string, title:string, slug:?string, url:?string,
     *                              entity:string, entityClass:string, entityId:int,
     *                              created:?string, online:bool}>
     * }>
     */
    public function nodes(): array
    {
        // `v.updated` is when the content was last saved, which is the question the cutoff
        // asks. The translation row's own timestamp moves for reasons that are not edits.
        $liveOnly = $this->offlineCutoff === null;

        $sql = sprintf(
            <<<'SQL'
                SELECT n.id AS nodeId, n.parent_id AS parentId,
                       t.lang, t.title, t.slug, t.url, t.created, t.online,
                       v.ref_entity_name AS entity, v.ref_id AS entityId
                FROM kuma_node_translations t
                JOIN kuma_nodes n ON n.id = t.node_id AND n.deleted = 0
                JOIN kuma_node_versions v ON v.id = t.public_node_version_id
                WHERE (t.online = 1%s) AND v.ref_id IS NOT NULL
                ORDER BY n.lft, n.id, t.lang
                SQL,
            $liveOnly ? '' : ' OR v.updated >= :cutoff',
        );

        $statement = $this->pdo->prepare($sql);
        $statement->execute($liveOnly ? [] : [':cutoff' => $this->offlineCutoff]);

        $nodes = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
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
                // The fully qualified name as Kunstmaan stores it. `kuma_seo` keys its rows
                // on exactly this string, so the short name cannot stand in for it.
                'entityClass' => (string) $row['entity'],
                'entityId' => (int) $row['entityId'],
                'created' => $row['created'] !== null ? (string) $row['created'] : null,
                'online' => ((int) ($row['online'] ?? 0)) === 1,
            ];
        }

        // A node whose translations disagree on entity type is a legacy inconsistency, not
        // something to average over; the first published translation wins and the compiler
        // reports it.
        return array_values($nodes);
    }

    /**
     * Published node id => its page entity name.
     *
     * The entity is carried so a parentRef can be checked for more than existence: a parent
     * whose entry lands in a different Craft section cannot be a structure parent.
     *
     * Published means *published*, not merely present in `nodes()`. A node whose only
     * translations were rescued by `offlineCutoff` is live nowhere, so the compiler emits no
     * page for it — counting it here would end the ancestor walk at a node nothing ever
     * writes, and its published descendants would point at a parent that never arrives. Left
     * out, it becomes a structural placeholder instead and still hands over its segment.
     *
     * @return array<int, string>
     */
    public function publishedNodeIds(): array
    {
        $ids = [];

        foreach ($this->nodes() as $node) {
            foreach ($node['translations'] as $translation) {
                if ($translation['online']) {
                    $ids[$node['nodeId']] = $node['entity'];

                    break;
                }
            }
        }

        return $ids;
    }

    /**
     * Every non-deleted node, published or not, with its parent and its per-locale slugs.
     *
     * `nodes()` deliberately sees only what is live. A URL does not: Kunstmaan builds a page's
     * path from the slugs of its ancestors whether or not those ancestors are themselves
     * published, and a section landing page is routinely an offline node or a RedirectPage.
     * Reading the tree without them re-roots every descendant and silently shortens its URL,
     * so the compiler needs the whole spine, not just the lit parts of it.
     *
     * The entity comes from `kuma_nodes.ref_entity_name` rather than the published version,
     * because an offline node has no published version to read it from.
     *
     * `lft` travels with each row because it is the tree order: in a nested set a parent's
     * `lft` is always lower than its children's, so emitting by `lft` is what guarantees a
     * parent payload is loaded before anything that references it as a parent.
     *
     * @return array<int, array{parentId:?int, entity:string, lft:int, slugs:array<string,string>,
     *                          titles:array<string,string>, offline:array<string,bool>}>
     */
    public function ancestry(): array
    {
        $sql = <<<'SQL'
            SELECT n.id AS nodeId, n.parent_id AS parentId, n.ref_entity_name AS entity, n.lft,
                   t.lang, t.slug, t.title, t.online
            FROM kuma_nodes n
            LEFT JOIN kuma_node_translations t ON t.node_id = n.id
            WHERE n.deleted = 0
            ORDER BY n.lft, n.id, t.lang
            SQL;

        $tree = [];

        foreach ($this->pdo->query($sql) as $row) {
            $id = (int) $row['nodeId'];

            $tree[$id] ??= [
                'parentId' => $row['parentId'] !== null ? (int) $row['parentId'] : null,
                'entity' => self::shortName((string) $row['entity']),
                'lft' => (int) $row['lft'],
                'slugs' => [],
                'titles' => [],
                'offline' => [],
            ];

            $lang = $row['lang'] !== null ? (string) $row['lang'] : null;

            if ($lang === null) {
                continue;
            }

            // A blank slug is the home node's, and is not a path segment.
            if ($row['slug'] !== null && (string) $row['slug'] !== '') {
                $tree[$id]['slugs'][$lang] = (string) $row['slug'];
            }

            if ($row['title'] !== null && (string) $row['title'] !== '') {
                $tree[$id]['titles'][$lang] = (string) $row['title'];
            }

            // An offline translation still owns its slug in Kunstmaan's URL for that locale.
            $tree[$id]['offline'][$lang] = ((int) ($row['online'] ?? 0)) !== 1;
        }

        return $tree;
    }

    /**
     * Node translation id => node id, for every translation the database holds.
     *
     * Kunstmaan writes an internal link as `[NT<id>]`, addressing a *translation* rather
     * than the node — and the node is what becomes a Craft entry. Unpublished translations
     * are included deliberately: a redirect that points at one is a dangling target that
     * should be reported as such, not silently missing from this index.
     *
     * @return array<int, int>
     */
    public function nodeIdByTranslation(): array
    {
        $ids = [];

        foreach ($this->pdo->query('SELECT id, node_id FROM kuma_node_translations') as $row) {
            $ids[(int) $row['id']] = (int) $row['node_id'];
        }

        return $ids;
    }

    /**
     * Node translation id => the legacy URL Kunstmaan served for it.
     *
     * `[NT<id>]` addresses a translation whose node may never become a Craft entry — deleted,
     * or a page type that only compiles into the `redirects:` lane. Kunstmaan still served
     * this URL, so it is what a manual `kuma_redirects` row is keyed on; the compiler's
     * redirect-fallback for an unresolvable `_linkRef` needs it, the same reason
     * `nodeIdByTranslation()` reads unpublished translations too.
     *
     * @return array<int, string>
     */
    public function urlByTranslation(): array
    {
        $urls = [];

        foreach ($this->pdo->query('SELECT id, url FROM kuma_node_translations WHERE url IS NOT NULL') as $row) {
            $url = trim((string) $row['url']);

            if ($url !== '') {
                $urls[(int) $row['id']] = $url;
            }
        }

        return $urls;
    }

    private static function shortName(string $entity): string
    {
        return substr((string) strrchr($entity, '\\'), 1) ?: $entity;
    }
}
