<?php

declare(strict_types=1);

namespace Lameco\KumaCompile\Report;

use Lameco\KumaCompile\Mapping\Mapping;
use Lameco\KumaCompile\Target\TargetSchema;
use Lameco\KumaCompile\Target\SpecNotes;

/**
 * Craft fields a spec's migration notes give a legacy source for, that the mapping never fills.
 *
 * The content model's specs each carry a `Migration notes (Kunstmaan → Craft)` table — the field
 * map somebody already thought through, in the same repo as the mapping. Nothing compared the two,
 * so the mapping could silently disagree with the document describing it: `partnerPage.md` names a
 * target for `country`, `postal_code`, `city`, `latitude`, `longitude`, `phone_number`,
 * `fax_number` and `website`, and the mapping migrated none of them.
 *
 * Deliberately no database: this compares the spec's targets against the mapping's map keys, both
 * of which are text. Which legacy column feeds a target is `suggest`'s job.
 */
final class SpecDivergence
{
    public function __construct(
        private readonly Mapping $mapping,
        private readonly SpecNotes $notes,
        private readonly TargetSchema $schema,
    ) {
    }

    /** @return list<string> one line per legacy column the spec gives a target for and the mapping drops */
    public function divergences(): array
    {
        $out = [];

        foreach ($this->mapping->pages() as $page => $spec) {
            if (is_array($spec) && !isset($spec['manual']) && isset($spec['entryType'])) {
                $out = [...$out, ...$this->against('page', (string) $page, (string) $spec['entryType'], $spec)];
            }
        }

        foreach ($this->mapping->parts() as $part => $spec) {
            if (!is_array($spec) || !isset($spec['block']) || isset($spec['drop']) || isset($spec['manual'])) {
                continue;
            }

            $out = [...$out, ...$this->against('part', (string) $part, (string) $spec['block'], $spec)];
        }

        // Several notes can name the same column; the disagreement is one fact, not three.
        return array_values(array_unique($out));
    }

    /**
     * Compare one spec entry's dropped columns against what its spec says they become.
     *
     * Source-driven on purpose. Asking "does the mapping fill every target the spec names" flags
     * a part for rows describing a different part — one spec covers several legacy classes, and
     * `Text` has no link columns to put in `contentBlock.button`. Asking "does the mapping drop a
     * column this spec gives a target for" only fires on a real disagreement, and needs no
     * database: both sides are text in this repo.
     *
     * @param array<string, mixed> $spec
     * @return list<string>
     */
    private function against(string $lane, string $subject, string $handle, array $spec): array
    {
        $notes = $this->notes->forBlock($handle);

        if ($notes === [] || !$this->schema->hasEntryType($handle)) {
            return [];
        }

        $out = [];

        foreach ([[$spec, null], ...$this->childScopes($spec)] as [$scope, $child]) {
            $dropped = $this->droppedColumns($scope);

            if ($dropped === []) {
                continue;
            }

            foreach ($notes as $note) {
                if (!$note->isMapped()) {
                    continue;
                }

                foreach ($note->sources as $source) {
                    $column = $this->columnFor($source, $dropped);

                    if ($column === null) {
                        continue;
                    }

                    $out[] = sprintf(
                        '%s `%s`%s: `%s` is dropped, but %s.md gives it a target (`%s`)',
                        $lane,
                        $subject,
                        $child === null ? '' : sprintf(', child `%s`', $child),
                        $column,
                        $handle,
                        implode('`/`', $note->targets),
                    );
                }
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $spec
     * @return list<array{0: array<string, mixed>, 1: string}>
     */
    private function childScopes(array $spec): array
    {
        $scopes = [];

        foreach ($spec['children'] ?? [] as $field => $child) {
            if (is_array($child)) {
                $scopes[] = [$child, (string) $field];
            }
        }

        return $scopes;
    }

    /**
     * Columns this scope says it is not migrating, whether reviewed or not.
     *
     * A reasoned `ignore:` entry counts too: someone overriding the spec should say so in the
     * spec, otherwise the two documents disagree and the next reader cannot tell which is current.
     *
     * @param array<string, mixed> $scope
     * @return list<string>
     */
    private function droppedColumns(array $scope): array
    {
        $ignore = $scope['ignore'] ?? [];
        $ignore = is_array($ignore) ? (array_is_list($ignore) ? $ignore : array_keys($ignore)) : [];
        $unreviewed = is_array($scope['unreviewed'] ?? null) ? $scope['unreviewed'] : [];

        return array_values(array_map(strval(...), [...$ignore, ...$unreviewed]));
    }

    /**
     * Match a spec's property name against a dropped column.
     *
     * Specs write Doctrine property names (`backgroundColor`, `country`); the database has snake
     * case, and a relation carries an `_id` suffix the spec omits.
     *
     * @param list<string> $columns
     */
    private function columnFor(string $property, array $columns): ?string
    {
        $snake = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $property));

        foreach ([$snake, $snake . '_id', $property] as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }

        return null;
    }
}
