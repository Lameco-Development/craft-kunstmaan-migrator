<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\Compile;

use Lameco\Kunstmaanmigrator\Mapping\Mapping;
use Lameco\Kunstmaanmigrator\Payload\SourceUid;
use Lameco\Kunstmaanmigrator\Source\LegacyDatabase;
use Lameco\Kunstmaanmigrator\Source\PartClass;
use PDO;

/**
 * `forms.submissions:` — Kunstmaan's stored form submissions, one group per
 * form-owning node.
 *
 * A submission names the node it was posted on and nothing else. Which Formie
 * form it belongs in is the node's page — named exactly the way `FormCompiler`
 * names the form it compiles for that page, so a node whose form the lane wrote
 * gets its submissions on that form, and a node whose form is gone (deleted,
 * offline, rebuilt as a Spotler embed) gets an archive form under the same
 * identity the lane would have used.
 *
 * Each value is keyed on the pagepart it answered — `field_name` carries the
 * part's class and id — never on its label. Measured on Berkvens NL, the labels
 * of one live form changed three times in seven years while the part ids did
 * not; a label join loses every submission older than the newest wording.
 *
 * Kunstmaan's FormBundle has two discriminator vocabularies (`string` and, after
 * an upgrade, `stringformsubmissionfield`) over the same single table; both are
 * read.
 */
final class SubmissionCompiler
{
    /** `field_KunstmaanFormBundleEntityPagePartsSingleLineTextPagePart198` => SingleLineText, 198 */
    private const FIELD_NAME = '/([A-Za-z0-9]+)PagePart(\d+)$/D';

    /** @var array<string, int> */
    private array $skipped = [];

    public function __construct(private readonly Mapping $mapping)
    {
    }

    /**
     * @param callable(array<string, mixed>): void $emit one call per node, in node order
     */
    public function compile(LegacyDatabase $db, string $environment, callable $emit): void
    {
        $lane = $this->mapping->forms()->submissions;

        if (!$lane->declared) {
            return;
        }

        $pdo = $db->pdo();

        foreach ($this->nodes($pdo) as $nodeId) {
            if (!$lane->includes($nodeId)) {
                $this->skip('node not opted in');

                continue;
            }

            $owner = $this->owner($pdo, $nodeId);

            if ($owner === null) {
                $this->skip('node has no page version to name its form after');

                continue;
            }

            $submissions = $this->submissions($pdo, $nodeId, $environment);

            if ($submissions === []) {
                // Nothing to land, so no archive form to ask for.
                $this->skip('node has no submission with a value');

                continue;
            }

            $emit([
                'node' => $nodeId,
                'formUid' => SourceUid::forForm($environment, $owner['entity'], $owner['id']),
                'title' => $owner['title'],
                'live' => $owner['live'],
                'fields' => $this->describe($pdo, $nodeId),
                'submissions' => $submissions,
            ]);
        }
    }

    /** @return array<string, int> */
    public function skipped(): array
    {
        return $this->skipped;
    }

    /** @return list<int> every node a submission names */
    private function nodes(PDO $pdo): array
    {
        $ids = $pdo->query(
            'SELECT DISTINCT node_id FROM kuma_form_submissions WHERE node_id IS NOT NULL ORDER BY node_id'
        )->fetchAll(PDO::FETCH_COLUMN);

        return array_map('intval', $ids);
    }

    /**
     * The node's page, as the forms lane would name it: the public version of
     * its translation (online first), else — a node that never published — its
     * newest version.
     *
     * @return array{entity: string, id: int, title: string, live: bool}|null
     */
    private function owner(PDO $pdo, int $nodeId): ?array
    {
        $statement = $pdo->prepare(
            'SELECT v.ref_entity_name AS entity, v.ref_id AS id, t.title, t.online, n.deleted
             FROM kuma_node_translations t
             INNER JOIN kuma_node_versions v ON v.id = t.public_node_version_id
             LEFT JOIN kuma_nodes n ON n.id = t.node_id
             WHERE t.node_id = ?
             ORDER BY t.online DESC, t.id
             LIMIT 1'
        );
        $statement->execute([$nodeId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            $statement = $pdo->prepare(
                'SELECT v.ref_entity_name AS entity, v.ref_id AS id, t.title, 0 AS online, n.deleted
                 FROM kuma_node_versions v
                 INNER JOIN kuma_node_translations t ON t.id = v.node_translation_id
                 LEFT JOIN kuma_nodes n ON n.id = t.node_id
                 WHERE t.node_id = ?
                 ORDER BY v.id DESC
                 LIMIT 1'
            );
            $statement->execute([$nodeId]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
        }

        if ($row === false) {
            return null;
        }

        $entity = PartClass::basename((string) $row['entity']);

        return [
            'entity' => $entity,
            'id' => (int) $row['id'],
            'title' => (string) ($row['title'] ?? '') !== '' ? (string) $row['title'] : sprintf('%s %d', $entity, $row['id']),
            'live' => (int) $row['online'] === 1 && (int) ($row['deleted'] ?? 0) === 0,
        ];
    }

    /**
     * @return list<array{id: int, key: string, created: string, ip: string, lang: string, values: array<string, array{kind: string, label: string, value: mixed}>}>
     */
    private function submissions(PDO $pdo, int $nodeId, string $environment): array
    {
        $out = [];

        foreach ($this->rows($pdo, $nodeId) as $submissionId => $set) {
            if ($set['fields'] === []) {
                $this->skip('submission carries no value');

                continue;
            }

            $values = [];

            foreach ($set['fields'] as $field) {
                $kind = self::kind((string) $field['discr']);
                $values[self::partKey((string) $field['field_name'])] = [
                    'kind' => $kind,
                    'label' => (string) $field['label'],
                    'value' => self::value($kind, $field),
                ];
            }

            $out[] = [
                'id' => $submissionId,
                'key' => sprintf('%s:kuma_form_submission:%d', $environment, $submissionId),
                'created' => (string) $set['row']['created'],
                'ip' => (string) $set['row']['ip_address'],
                'lang' => (string) $set['row']['lang'],
                'values' => $values,
            ];
        }

        return $out;
    }

    /**
     * Every field any submission on the node answered — what an archive form for
     * it has to hold. Ordered and labelled as the newest submission that
     * answered it had it; a field only old submissions answered follows.
     *
     * @return array<string, array{kind: string, label: string, choices?: list<string>, multiple?: bool}>
     */
    private function describe(PDO $pdo, int $nodeId): array
    {
        $fields = [];

        foreach (array_reverse($this->rows($pdo, $nodeId), true) as $set) {
            foreach ($set['fields'] as $field) {
                $key = self::partKey((string) $field['field_name']);
                $kind = self::kind((string) $field['discr']);

                if (!isset($fields[$key])) {
                    $fields[$key] = ['kind' => $kind, 'label' => (string) $field['label']];

                    if ($kind === 'choice') {
                        $fields[$key]['choices'] = self::choices($field['choices'] ?? null);
                        $fields[$key]['multiple'] = false;
                    }
                }

                if ($kind === 'choice' && (
                    (int) ($field['multiple'] ?? 0) === 1
                    || str_starts_with((string) ($field['cfsf_value'] ?? ''), 'a:')
                )) {
                    $fields[$key]['multiple'] = true;
                }
            }
        }

        return $fields;
    }

    /** @var array<int, array<int, array{row: array<string, mixed>, fields: list<array<string, mixed>>}>> */
    private array $rows = [];

    /**
     * The node's submissions and their field rows, oldest first.
     *
     * @return array<int, array{row: array<string, mixed>, fields: list<array<string, mixed>>}>
     */
    private function rows(PDO $pdo, int $nodeId): array
    {
        if (isset($this->rows[$nodeId])) {
            return $this->rows[$nodeId];
        }

        $statement = $pdo->prepare(
            'SELECT id, node_id, ip_address, lang, created FROM kuma_form_submissions WHERE node_id = ? ORDER BY id'
        );
        $statement->execute([$nodeId]);
        $sets = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $sets[(int) $row['id']] = ['row' => $row, 'fields' => []];
        }

        $statement = $pdo->prepare(
            'SELECT f.* FROM kuma_form_submission_fields f
             INNER JOIN kuma_form_submissions s ON s.id = f.form_submission_id
             WHERE s.node_id = ?
             ORDER BY f.form_submission_id, f.sequence, f.id'
        );
        $statement->execute([$nodeId]);

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $field) {
            $sets[(int) $field['form_submission_id']]['fields'][] = $field;
        }

        $this->rows = [$nodeId => $sets];

        return $sets;
    }

    /** The part a value answered, as `<ShortClass>:<id>` — the `partRef` `FormCompiler` gives its fields. */
    public static function partKey(string $fieldName): string
    {
        if (preg_match(self::FIELD_NAME, $fieldName, $match) === 1) {
            $class = $match[1];
            $at = strrpos($class, 'PageParts');

            if ($at !== false) {
                $class = substr($class, $at + strlen('PageParts'));
            }

            return sprintf('%s:%d', $class, (int) $match[2]);
        }

        return str_starts_with($fieldName, 'field_') ? substr($fieldName, 6) : $fieldName;
    }

    private static function kind(string $discr): string
    {
        $kind = strtolower((string) preg_replace('/formsubmissionfield$/i', '', $discr));

        return $kind === 'bool' ? 'boolean' : $kind;
    }

    /** @param array<string, mixed> $field */
    private static function value(string $kind, array $field): mixed
    {
        return match ($kind) {
            'string' => $field['sfsf_value'],
            'text' => $field['tfsf_value'],
            'email' => $field['efsf_value'],
            'boolean' => $field['bfsf_value'] === null ? null : (bool) $field['bfsf_value'],
            'file' => ['name' => $field['ffsf_value'], 'url' => $field['url']],
            'choice' => self::picked($field['cfsf_value'] ?? null, self::choices($field['choices'] ?? null)),
            default => $field['sfsf_value'] ?? $field['tfsf_value'] ?? $field['efsf_value'],
        };
    }

    /**
     * `cfsf_value` is a PHP-serialised index (`i:1;`) or list of indexes
     * (`a:1:{i:0;i:0;}`) into `choices`; `N;` and `a:0:{}` picked nothing.
     *
     * @param list<string> $choices
     * @return list<string>
     */
    private static function picked(mixed $raw, array $choices): array
    {
        $decoded = is_string($raw) ? @unserialize($raw, ['allowed_classes' => false]) : null;
        $indexes = is_array($decoded) ? array_values($decoded) : (is_int($decoded) || is_string($decoded) ? [$decoded] : []);

        return array_map(
            static fn(mixed $index): string => $choices[(int) $index] ?? (string) $index,
            $indexes,
        );
    }

    /** @return list<string> */
    private static function choices(mixed $raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = @unserialize($raw, ['allowed_classes' => false]);

        if (is_array($decoded)) {
            return array_values(array_map('strval', $decoded));
        }

        return array_values(array_filter(array_map('trim', preg_split('/\R/', $raw) ?: [])));
    }

    private function skip(string $reason): void
    {
        $this->skipped[$reason] = ($this->skipped[$reason] ?? 0) + 1;
    }
}
