<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\Report;

use Lameco\Kunstmaanmigrator\Mapping\Mapping;
use Lameco\Kunstmaanmigrator\Source\LegacyDatabase;

/**
 * Fills in the half of a requirement that only the legacy database can answer: of the live rows
 * that will produce this block, how many actually carry a value for the column the mapping reads.
 *
 * Counts accumulate across environments, because the decision is per field and the corpus is the
 * three databases together — a column populated in COM and empty in LV is still a decision.
 */
final class FillMeasurer
{
    /** @var array<string, array{live:int, preceded:int}> */
    private array $preceded = [];

    /** @var array<string, list<string>> */
    private array $entities = [];

    /** @var list<string> */
    private array $problems = [];

    /** @var array<string, list<string>> */
    private array $columns = [];

    public function __construct(private readonly Mapping $mapping)
    {
    }

    /**
     * Columns the mapping reads that the environment does not have.
     *
     * The three legacy databases are not the same schema — LV predates columns COM and DE both
     * carry — so a mapping written against one environment can read a column that is simply absent
     * in another. That is a migration finding, not a reason to stop measuring.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        return $this->problems;
    }

    /** @param list<Requirement> $requirements */
    public function ingest(array $requirements, LegacyDatabase $db): void
    {
        $this->entities = $db->partEntities();
        $this->columns = [];
        $this->preceded = $this->headPart() !== null ? $db->precededBy($this->headEntities()) : [];

        foreach ($requirements as $requirement) {
            $measured = $this->measure($requirement, $db);

            if ($measured === null) {
                continue;
            }

            $requirement->rows = ($requirement->rows ?? 0) + $measured['rows'];
            $requirement->empty = ($requirement->empty ?? 0) + $measured['empty'];
        }
    }

    private function has(LegacyDatabase $db, string $table, string $column): bool
    {
        $this->columns[$table] ??= $db->columns($table);

        if (in_array($column, $this->columns[$table], true)) {
            return true;
        }

        $this->problems[] = sprintf('%s: `%s`.`%s` does not exist', $db->environment, $table, $column);

        return false;
    }

    /** @return array{rows:int, empty:int}|null */
    private function measure(Requirement $requirement, LegacyDatabase $db): ?array
    {
        if ($requirement->supplier === 'sequence') {
            return $this->measureAbsorbed($requirement);
        }

        $column = $requirement->measurableColumn();

        if ($column === null) {
            return null;
        }

        if ($requirement->lane === 'pages') {
            $table = (string) ($this->mapping->pages()[$requirement->subject]['table'] ?? '');

            return $table === '' || !$this->has($db, $table, $column)
                ? null
                : $db->pageColumnFill($requirement->subject, $table, $column);
        }

        if ($requirement->lane !== 'parts') {
            return null;
        }

        $spec = $this->mapping->parts()[$requirement->subject] ?? null;
        $entities = $this->entities[$requirement->subject] ?? [];
        $table = is_array($spec) ? (string) ($spec['table'] ?? '') : '';

        if ($entities === [] || $table === '') {
            return null;
        }

        $child = $this->childOf($spec, $requirement->target);

        if ($child === null) {
            return $this->has($db, $table, $column) ? $db->columnFill($entities, $table, $column) : null;
        }

        if (!$this->has($db, (string) $child['table'], $column)) {
            return null;
        }

        return $db->childColumnFill(
            $entities,
            $table,
            (string) $child['table'],
            (string) $child['fk'],
            $column,
        );
    }

    /**
     * A heading that arrives by absorption is present exactly as often as a Header precedes the
     * part. The gap is not a data-quality problem — it is the share of placements that will reach
     * Craft with a required field empty.
     *
     * @return array{rows:int, empty:int}|null
     */
    private function measureAbsorbed(Requirement $requirement): ?array
    {
        $counts = $this->preceded[$requirement->subject] ?? null;

        if ($counts === null) {
            return null;
        }

        return ['rows' => $counts['live'], 'empty' => $counts['live'] - $counts['preceded']];
    }

    /**
     * The child collection behind a `block.field[]` target, or null when the target is the block
     * itself or a nested row read straight off the parent's own columns.
     *
     * @param array<string, mixed> $spec
     * @return array{table:string, fk:string}|null
     */
    private function childOf(array $spec, string $target): ?array
    {
        if (preg_match('/\.(\w+)\[\]$/', $target, $m) !== 1) {
            return null;
        }

        $child = $spec['children'][$m[1]] ?? null;

        return is_array($child) && isset($child['table'], $child['fk'])
            ? ['table' => (string) $child['table'], 'fk' => (string) $child['fk']]
            : null;
    }

    /** The pagepart the absorb rules consume, read off the sequence lane rather than assumed. */
    private function headPart(): ?string
    {
        foreach ($this->mapping->sequence() as $rule) {
            if (($rule['action'] ?? '') === 'absorb' && preg_match('/^(\w+)\s*>/', (string) ($rule['match'] ?? ''), $m) === 1) {
                return $m[1];
            }
        }

        return null;
    }

    /** @return list<string> */
    private function headEntities(): array
    {
        $part = (string) $this->headPart();

        return $this->entities[$part] ?? [$part];
    }
}
