<?php

declare(strict_types=1);

namespace Lameco\KumaCompile\Compile;

use Lameco\KumaCompile\Legacy\LegacyDatabase;
use Lameco\KumaCompile\Legacy\PartReader;
use Lameco\KumaCompile\Mapping\Mapping;
use PDO;

/**
 * The `globals:` lane: site-wide pageparts that belong to no single page.
 *
 * Validated by `Schema` since the DSL was written and read by nothing, so 169
 * placements of footer content had no destination and the mapping's own note
 * said "Targets unresolved".
 *
 * A Kunstmaan footer is a page — one `FooterPage` per locale, carrying its
 * columns as pageparts in `footer-*` contexts. The same contexts also appear on
 * 900-odd ordinary pages as per-page overrides, which is a different thing, so
 * the lane is scoped to the page types the mapping names under `globals:`.
 *
 * Where each context lands is the mapping's decision, not this class's: a
 * context declares `target: nav:<handle>` and the loader writes there. The
 * shape it emits is deliberately target-agnostic — a title, a URL, an optional
 * list of children — because that is what the legacy data is, and binding it to
 * verbb's node model here would put a target decision in the compile half,
 * which is the half that knows nothing about Craft.
 */
final class GlobalsCompiler
{
    private int $count = 0;

    /** @var array<string, int> */
    private array $skipped = [];

    public function __construct(
        private readonly Mapping $mapping,
        private readonly Transforms $transforms,
    ) {
    }

    /**
     * @param callable(array<string, mixed>): void $emit
     */
    public function compile(LegacyDatabase $db, string $environment, callable $emit): void
    {
        $lanes = $this->mapping->globals();

        if ($lanes === []) {
            return;
        }

        $pdo = $db->pdo();
        $parts = new PartReader($pdo);
        $builder = new BlockBuilder($parts, $this->transforms, $environment);

        foreach ($lanes as $pageEntity => $spec) {
            $contexts = (array) ($spec['contexts'] ?? []);
            $partSpecs = (array) ($spec['parts'] ?? []);

            foreach ($this->owners($pdo, (string) $pageEntity) as [$pageId, $locale]) {
                foreach ($contexts as $context => $contextSpec) {
                    $target = (string) (((array) $contextSpec)['target'] ?? '');

                    if ($target === '') {
                        $this->skip(sprintf('%s has no target', $context));

                        continue;
                    }

                    $this->compileContext(
                        $parts,
                        $builder,
                        (string) $pageEntity,
                        $pageId,
                        $locale,
                        (string) $context,
                        $target,
                        $partSpecs,
                        $environment,
                        $emit,
                    );
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $partSpecs
     * @param callable(array<string, mixed>): void $emit
     */
    private function compileContext(
        PartReader $parts,
        BlockBuilder $builder,
        string $pageEntity,
        int $pageId,
        string $locale,
        string $context,
        string $target,
        array $partSpecs,
        string $environment,
        callable $emit,
    ): void {
        foreach ($parts->sequence($pageEntity, $pageId, $context) as $index => $ref) {
            $partSpec = $partSpecs[$ref['part']] ?? null;

            if ($partSpec === null) {
                $this->skip(sprintf('no globals: part for %s', $ref['part']));

                continue;
            }

            if (isset($partSpec['drop'])) {
                // Declared as having no target, with the reason written down. A
                // decision recorded is not the same as a gap, and a report that
                // cannot tell them apart teaches people to ignore it.
                $this->skip(sprintf('%s dropped by the mapping', $ref['part']));

                continue;
            }

            $table = (string) ($partSpec['table'] ?? '');
            $row = $table === '' ? null : $parts->row($table, $ref['id']);

            if ($row === null) {
                $this->skip(sprintf('%s row %d is missing', $ref['part'], $ref['id']));

                continue;
            }

            $fields = $builder->fieldsFrom((array) ($partSpec['map'] ?? []), $row, $context);
            $this->count++;

            $emit([
                'sourceUid' => sprintf(
                    'kuma:%s:global:%s:%s:%d',
                    $environment,
                    $context,
                    $ref['part'],
                    $ref['id'],
                ),
                'environment' => $environment,
                'locale' => $locale,
                'context' => $context,
                'target' => $target,
                'sequence' => $index,
                'title' => (string) ($fields['title'] ?? ''),
                'url' => (string) ($fields['url'] ?? ''),
                'newWindow' => (bool) ($fields['newWindow'] ?? false),
                'children' => $this->children($parts, $builder, $partSpec, $ref['id'], $context, $environment),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $partSpec
     * @return list<array<string, mixed>>
     */
    private function children(
        PartReader $parts,
        BlockBuilder $builder,
        array $partSpec,
        int $parentId,
        string $context,
        string $environment,
    ): array {
        $out = [];

        foreach ((array) ($partSpec['children'] ?? []) as $childSpec) {
            $childSpec = (array) $childSpec;
            $table = (string) ($childSpec['table'] ?? '');
            $fk = (string) ($childSpec['fk'] ?? '');

            if ($table === '' || $fk === '') {
                continue;
            }

            foreach ($parts->children($table, $fk, $parentId, (string) ($childSpec['order'] ?? 'id')) as $row) {
                $fields = $builder->fieldsFrom((array) ($childSpec['map'] ?? []), $row, $context);

                $out[] = [
                    'sourceUid' => sprintf('kuma:%s:global:%s:%d', $environment, $table, (int) ($row['id'] ?? 0)),
                    'title' => (string) ($fields['title'] ?? ''),
                    'url' => (string) ($fields['url'] ?? ''),
                    'newWindow' => (bool) ($fields['newWindow'] ?? false),
                ];
            }
        }

        return $out;
    }

    /**
     * The live pages of this type, with the locale each one speaks — the footer
     * is one page per locale, and which Craft site it writes to is decided by
     * the loader from the environment's locale map.
     *
     * @return list<array{0: int, 1: string}>
     */
    private function owners(PDO $pdo, string $pageEntity): array
    {
        $statement = $pdo->prepare(
            'SELECT DISTINCT v.ref_id AS id, t.lang AS lang
             FROM kuma_node_translations t
             INNER JOIN kuma_node_versions v ON v.id = t.public_node_version_id
             INNER JOIN kuma_nodes n ON n.id = t.node_id AND n.deleted = 0
             WHERE t.online = 1 AND v.ref_entity_name LIKE ?
             ORDER BY v.ref_id'
        );
        $statement->execute(['%\\\\' . $pageEntity]);

        $out = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = [(int) $row['id'], (string) $row['lang']];
        }

        return $out;
    }

    private function skip(string $reason): void
    {
        $this->skipped[$reason] = ($this->skipped[$reason] ?? 0) + 1;
    }

    public function count(): int
    {
        return $this->count;
    }

    /** @return array<string, int> */
    public function skipped(): array
    {
        return $this->skipped;
    }
}
