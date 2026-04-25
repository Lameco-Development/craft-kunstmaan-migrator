<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\mapping;

use yii\base\Component;

/**
 * Coverage gate (D-14, MAP-06): a "data-bearing legacy column" is every column
 * with fillRate > 0 AND name not in STRUCTURAL_IGNORE. A column is "covered" when
 * mapping.yaml has at least one row with matching (table, column) and status in
 * {accepted, dropped}. proposed/needs-review do NOT count as covered.
 *
 * D-15: this service produces the verdict only. Consumer code (Phase 3 migrate)
 * decides hard-fail (--live) vs warn-and-continue (--dry-run).
 */
final class CoverageAuditor extends Component
{
    /**
     * Kunstmaan structural columns we never migrate as data — seed list per D-14.
     * These are the bookkeeping columns Doctrine / nested-set / soft-delete stuff
     * that have no semantic content for migration.
     */
    private const STRUCTURAL_IGNORE = [
        'id', 'parent_id', 'lft', 'rgt', 'lvl', 'tree_root',
        'created', 'updated', 'createdBy', 'updatedBy',
        'createdBy_id', 'updatedBy_id',
        'internal_name', 'discr', 'public', 'hidden_from_nav',
        'children_index', 'sequencenumber', 'ref',
        'deletedAt', 'version',
        'kunstmaanSourceId', // set programmatically at load time, never migrated as data
    ];

    /**
     * Audit coverage. Returns a list of unmapped data-bearing columns.
     *
     * @param array{tables: array<string, int>, columns: array<string, list<array<string, mixed>>>} $schemaDump
     * @param list<array<string, mixed>> $mappingProposals
     * @return list<array{table: string, column: string, fillRate: float, rows: int}>
     */
    public function audit(array $schemaDump, array $mappingProposals): array
    {
        // Index covered columns by (table|column) — covered means status ∈ {accepted, dropped}.
        $covered = [];
        foreach ($mappingProposals as $row) {
            $status = (string) ($row['status'] ?? '');
            if ($status !== 'accepted' && $status !== 'dropped') {
                continue;
            }
            $key = ($row['table'] ?? '') . '|' . ($row['column'] ?? '');
            $covered[$key] = true;
        }

        $violations = [];
        foreach ($schemaDump['columns'] ?? [] as $table => $cols) {
            $rowCount = (int) ($schemaDump['tables'][$table] ?? 0);
            foreach ($cols as $col) {
                $name = (string) ($col['column'] ?? '');
                $fillRate = (float) ($col['fillRate'] ?? 0);
                if ($name === '') { continue; }
                if (in_array($name, self::STRUCTURAL_IGNORE, true)) { continue; }
                if ($fillRate <= 0) { continue; }
                $key = $table . '|' . $name;
                if (isset($covered[$key])) { continue; }
                $violations[] = [
                    'table'    => (string) $table,
                    'column'   => $name,
                    'fillRate' => $fillRate,
                    'rows'     => $rowCount,
                ];
            }
        }
        return $violations;
    }

    /**
     * Render a v1-shaped stderr block. Caller writes via $this->stderr(...).
     *
     * @param list<array{table: string, column: string, fillRate: float, rows: int}> $violations
     */
    public function renderViolations(array $violations): string
    {
        if ($violations === []) {
            return '';
        }
        // Group by table for readability.
        $byTable = [];
        foreach ($violations as $v) {
            $byTable[$v['table']][] = $v;
        }
        $out = '';
        foreach ($byTable as $table => $rows) {
            $n = count($rows);
            $out .= "FAIL {$table}: {$n} unmapped data-bearing column(s)\n";
            foreach ($rows as $r) {
                $out .= sprintf("     - %s (fill=%.1f%%, rows=%d)\n", $r['column'], $r['fillRate'] * 100, $r['rows']);
            }
        }
        return $out;
    }
}
