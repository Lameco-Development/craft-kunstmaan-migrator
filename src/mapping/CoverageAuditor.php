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
     * Audit coverage. Returns a heterogeneous list of violations:
     *
     *  - kind=column     → unmapped data-bearing columns (Phase 2 / D-14)
     *  - kind=pagePart   → page-part classes with DB rows but no
     *                      (kind=pagePart, status accepted|dropped) row
     *                      in mapping.yaml (Phase 02.1 / D-14 patch per D-36)
     *
     * Both kinds block migrate --live (Phase 3 consumer treats either as fail).
     *
     * @param array{tables?: array<string, int>, columns?: array<string, list<array<string, mixed>>>} $schemaDump
     * @param list<array<string, mixed>>                                                              $mappingProposals
     * @param array<string, array{contexts?: list<array{name?: string, allowedPagePartClasses?: list<array{class?: string, table?: string}>}>}> $pageStructure
     * @return list<array<string, mixed>>
     */
    public function audit(array $schemaDump, array $mappingProposals, array $pageStructure = []): array
    {
        // Index covered columns by (table|column) — covered means status ∈ {accepted, dropped}.
        $covered = [];
        foreach ($mappingProposals as $row) {
            $kind = (string) ($row['kind'] ?? 'column');
            if ($kind !== 'column') { continue; }
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
            // Patched in Phase 02.1 / Plan 09 from v1 CoverageAuditor.php:82-85 per RECONCILIATION.md.
            // Empty table → no possible coverage gap; skip the per-column walk wholesale.
            if ($rowCount === 0) {
                continue;
            }
            foreach ($cols as $col) {
                $name = (string) ($col['column'] ?? '');
                $fillRate = (float) ($col['fillRate'] ?? 0);
                if ($name === '') { continue; }
                if (in_array($name, self::STRUCTURAL_IGNORE, true)) { continue; }
                if ($fillRate <= 0) { continue; }
                $key = $table . '|' . $name;
                if (isset($covered[$key])) { continue; }
                $violations[] = [
                    'kind'     => 'column',
                    'table'    => (string) $table,
                    'column'   => $name,
                    'fillRate' => $fillRate,
                    'rows'     => $rowCount,
                ];
            }
        }

        // Phase 02.1 / D-14 patch (D-36): page-part coverage enforcement.
        // Every page-part class with at least one DB row in scope must have a
        // (kind=pagePart) row with status ∈ {accepted, dropped} in mapping.yaml.
        $pagePartViolations = $this->auditPagePartCoverage($pageStructure, $mappingProposals);
        foreach ($pagePartViolations as $v) {
            $violations[] = $v;
        }

        return $violations;
    }

    /**
     * Page-part coverage check (D-14 patch per D-36). Returns one violation tuple per
     * page-part class declared in the page-structure scan but lacking an
     * accepted/dropped (kind=pagePart) mapping.yaml row. Caller (Phase 3 migrate --live)
     * blocks on any non-empty result.
     *
     * "In scope" = present in any pageRecord.contexts[*].allowedPagePartClasses set.
     * The DB-rows-≥1 test is implicit in pageStructure: KunstmaanPageStructureScanner
     * only emits page-part class entries for classes that appear in the legacy
     * page-builder configuration; classes never used produce no scan record.
     *
     * @param array<string, array{contexts?: list<array{name?: string, allowedPagePartClasses?: list<array{class?: string, table?: string}>}>}> $pageStructure
     * @param list<array<string, mixed>> $mappingProposals
     * @return list<array{kind: string, pagePartClass: string, sourceTable: string, detail: string}>
     */
    private function auditPagePartCoverage(array $pageStructure, array $mappingProposals): array
    {
        // Build set of all page-part classes declared in the scan.
        $allPagePartClasses = [];
        foreach ($pageStructure as $pageRecord) {
            if (!is_array($pageRecord)) { continue; }
            foreach ((array) ($pageRecord['contexts'] ?? []) as $ctx) {
                if (!is_array($ctx)) { continue; }
                foreach ((array) ($ctx['allowedPagePartClasses'] ?? []) as $allowedClass) {
                    if (!is_array($allowedClass)) { continue; }
                    $cls = (string) ($allowedClass['class'] ?? '');
                    if ($cls === '') { continue; }
                    // Last-write-wins on duplicate FQCNs — fine, we just need the table hint.
                    $allPagePartClasses[$cls] = $allowedClass;
                }
            }
        }

        // Build set of page-part classes covered by mapping.yaml (accepted|dropped).
        $coveredPagePartClasses = [];
        foreach ($mappingProposals as $row) {
            if (!is_array($row)) { continue; }
            if (((string) ($row['kind'] ?? 'column')) !== 'pagePart') { continue; }
            $status = (string) ($row['status'] ?? '');
            if (!in_array($status, ['accepted', 'dropped'], true)) { continue; }
            $cls = (string) ($row['pagePartClass'] ?? '');
            if ($cls === '') { continue; }
            $coveredPagePartClasses[$cls] = true;
        }

        $violations = [];
        foreach ($allPagePartClasses as $class => $allowedClass) {
            if (isset($coveredPagePartClasses[$class])) { continue; }
            $violations[] = [
                'kind'          => 'pagePartCoverage',
                'pagePartClass' => $class,
                'sourceTable'   => (string) ($allowedClass['table'] ?? ''),
                'detail'        => "Page-part class {$class} has no mapping.yaml row with status accepted or dropped.",
            ];
        }
        return $violations;
    }

    /**
     * Render a v1-shaped stderr block. Caller writes via $this->stderr(...).
     *
     * Handles two violation kinds:
     *   - kind=column            → grouped by table (legacy stderr block)
     *   - kind=pagePart (D-36)   → grouped under "Unmapped page-part classes" heading
     *
     * @param list<array<string, mixed>> $violations
     */
    public function renderViolations(array $violations): string
    {
        if ($violations === []) {
            return '';
        }

        // Split by kind. Column violations preserve their existing render shape;
        // page-part coverage violations get their own block (D-14 patch per D-36).
        $columnViolations = [];
        $pagePartViolations = [];
        foreach ($violations as $v) {
            if (((string) ($v['kind'] ?? 'column')) === 'pagePartCoverage') {
                $pagePartViolations[] = $v;
            } else {
                $columnViolations[] = $v;
            }
        }

        $out = '';
        // Column block — grouped by table, preserves Phase 2 / D-14 stderr shape.
        if ($columnViolations !== []) {
            $byTable = [];
            foreach ($columnViolations as $v) {
                $byTable[(string) ($v['table'] ?? '')][] = $v;
            }
            foreach ($byTable as $table => $rows) {
                $n = count($rows);
                $out .= "FAIL {$table}: {$n} unmapped data-bearing column(s)\n";
                foreach ($rows as $r) {
                    $out .= sprintf(
                        "     - %s (fill=%.1f%%, rows=%d)\n",
                        (string) ($r['column'] ?? ''),
                        ((float) ($r['fillRate'] ?? 0)) * 100,
                        (int) ($r['rows'] ?? 0),
                    );
                }
            }
        }

        // Page-part coverage block — D-14 patch per D-36. Each unmapped class blocks
        // migrate --live the same way as an unmapped data-bearing column.
        if ($pagePartViolations !== []) {
            $n = count($pagePartViolations);
            $out .= "FAIL Unmapped page-part classes: {$n}\n";
            foreach ($pagePartViolations as $v) {
                $cls = (string) ($v['pagePartClass'] ?? '');
                $tbl = (string) ($v['sourceTable'] ?? '');
                $out .= sprintf("     - %s%s\n", $cls, $tbl !== '' ? " (table={$tbl})" : '');
            }
        }

        return $out;
    }
}
