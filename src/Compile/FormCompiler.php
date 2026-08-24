<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\Compile;

use Lameco\Kunstmaanmigrator\Mapping\Mapping;

use Lameco\Kunstmaanmigrator\Payload\SourceUid;
use Lameco\Kunstmaanmigrator\Source\LegacyDatabase;
use Lameco\Kunstmaanmigrator\Source\PartReader;
use PDO;

/**
 * The `forms:` lane: legacy form-context pageparts become one form per page.
 *
 * `Schema` has validated this block since the mapping DSL was written and
 * `Compiler` never read it, so 495 live placements had no destination and the
 * migrated `formBlock`s pointed at nothing. `globalParts()` and `formFields()`
 * were called only by the lane-collision check — the mapping described two
 * lanes the compiler did not know existed.
 *
 * A Kunstmaan form is not an entity. It is whatever pageparts a page carries in
 * the `form` context, in sequence order: the page owns the submission settings
 * and the parts are the fields. So the unit of compilation is the owning page,
 * and one page produces one form.
 *
 * The unmerged FormMigrationService assumed `FormPage` owned them. Measured
 * against the real corpus, every one of COM's 745 form-context pages is a
 * `PotionsLandingPage` — which is why the owner is read from the data rather
 * than named in the code.
 */
final class FormCompiler
{
    private int $count = 0;

    /** @var array<string, int> */
    private array $skipped = [];

    /** @param ?list<string> $only compile only these page entities; null is everything */
    public function __construct(
        private readonly Mapping $mapping,
        private readonly Transforms $transforms,
        private readonly ?array $only = null,
    ) {
    }

    /**
     * @param callable(array<string, mixed>): void $emit
     */
    public function compile(LegacyDatabase $db, string $environment, callable $emit): void
    {
        $forms = $this->mapping->forms();

        if (!$forms->declared) {
            return;
        }

        $context = $forms->context;
        $fieldSpecs = $this->mapping->formFields();

        if ($fieldSpecs === []) {
            $this->skip('forms: declares no fields');

            return;
        }

        $pdo = $db->pdo();
        $parts = new PartReader($pdo);
        $builder = new BlockBuilder($parts, $this->transforms, $environment);

        foreach ($this->owners($pdo, $context) as $owner) {
            [$entity, $pageId] = $owner;

            if ($this->only !== null && !in_array($entity, $this->only, true)) {
                continue;
            }

            $fields = [];

            foreach ($parts->sequence($entity, $pageId, $context) as $ref) {
                $fieldSpec = $fieldSpecs[$ref['part']] ?? null;

                if ($fieldSpec === null) {
                    // A layout bracket (RowStart/Col/RowEnd) or an editorial part
                    // that happens to sit in a form. `unmapped:` declares those on
                    // purpose, so this is a count rather than a complaint.
                    $this->skip(sprintf('no forms: field for %s', $ref['part']));

                    continue;
                }

                $table = (string) ($fieldSpec['table'] ?? '');
                $row = $table === '' ? null : $parts->row($table, $ref['id']);

                if ($row === null) {
                    $this->skip(sprintf('%s row %d is missing', $ref['part'], $ref['id']));

                    continue;
                }

                $mapped = $builder->fieldsFrom((array) ($fieldSpec['map'] ?? []), $row, $context);

                $fields[] = [
                    'type' => (string) ($fieldSpec['type'] ?? ''),
                    'label' => (string) ($mapped['label'] ?? ''),
                    'handle' => (string) ($mapped['handle'] ?? ''),
                    'required' => (bool) ($mapped['required'] ?? false),
                    'settings' => array_diff_key($mapped, array_flip(['label', 'handle', 'required'])),
                    'sourceRef' => sprintf('%s:%d', $table, $ref['id']),
                ];
            }

            if ($fields === []) {
                $this->skip('page carries no mappable form field');

                continue;
            }

            $this->count++;

            $emit([
                'sourceUid' => SourceUid::forForm($environment, $entity, $pageId),
                'environment' => $environment,
                'owner' => ['entity' => $entity, 'id' => $pageId],
                'title' => $this->titleFor($pdo, $entity, $pageId),
                'fields' => $fields,
            ]);
        }
    }

    /**
     * Every (page entity, page id) that carries at least one part in the form
     * context — read from the data rather than from a page type named in the
     * mapping, because which page type owns a form is a fact about the corpus.
     *
     * @return list<array{0: string, 1: int}>
     */
    private function owners(PDO $pdo, string $context): array
    {
        // Only pages that are actually live. The part refs survive a page being
        // deleted or unpublished, so the raw list is 745 where the live corpus is
        // 495 — and a migration that creates 250 Formie forms for pages nobody
        // can reach has made the control panel worse, not better. Same live
        // definition the rest of the compiler uses: not deleted, published, with
        // a public version.
        $statement = $pdo->prepare(
            'SELECT DISTINCT r.pageEntityname AS entity, r.pageId AS id
             FROM kuma_page_part_refs r
             INNER JOIN kuma_node_versions v
                     ON v.ref_id = r.pageId AND v.ref_entity_name = r.pageEntityname
             INNER JOIN kuma_node_translations t
                     ON t.public_node_version_id = v.id AND t.online = 1
             INNER JOIN kuma_nodes n ON n.id = t.node_id AND n.deleted = 0
             WHERE r.context = ?
             ORDER BY r.pageEntityname, r.pageId'
        );
        $statement->execute([$context]);

        $out = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $entity = (string) $row['entity'];
            $short = substr($entity, (strrpos($entity, '\\') ?: -1) + 1);
            $out[] = [$short, (int) $row['id']];
        }

        return $out;
    }

    /**
     * The form's name in Formie's list, which is an editor-facing string and so
     * worth taking from the page rather than generating. Falls back to the
     * legacy identity when the page has no title in any locale.
     */
    private function titleFor(PDO $pdo, string $entity, int $pageId): string
    {
        // The page row and the node are joined through the node version, not
        // directly: `kuma_nodes` carries the entity name but not the row id, and
        // the id lives on `kuma_node_versions.ref_id`. Same join PageReader uses.
        $statement = $pdo->prepare(
            'SELECT t.title
             FROM kuma_node_translations t
             INNER JOIN kuma_node_versions v ON v.id = t.public_node_version_id
             WHERE v.ref_id = ? AND v.ref_entity_name LIKE ? AND t.title <> \'\'
             ORDER BY t.online DESC, t.id
             LIMIT 1'
        );
        $statement->execute([$pageId, '%\\\\' . $entity]);
        $title = $statement->fetchColumn();

        return is_string($title) && $title !== '' ? $title : sprintf('%s %d', $entity, $pageId);
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
