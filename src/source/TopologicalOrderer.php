<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\source;

/**
 * Pure-function depth-first orderer for kuma_nodes-style hierarchical rows.
 *
 * Used by ContentPageMigrationService (Plan 04-08) to guarantee parent-first
 * insertion into the Craft `contentPages` Structure section. Without this
 * pre-sort, Craft's `Structures::append()` fails for any child whose parent
 * Entry hasn't yet been created.
 *
 * Input rows MUST contain `kuma_node_id` (int) and `kuma_parent_id` (int|null).
 * Result is the same array, sorted ascending by computed depth and then by
 * original input position (stable sort).
 *
 * Cycle detection: if a chain of parent_ids visits the same id twice, every
 * participant in the cycle is pinned to PHP_INT_MAX depth (so it lands at the
 * end of the result) and a `cycle detected` warning is appended to $warnings.
 *
 * Orphan detection: if a row's parent_id points to an id not present in the
 * input set, the row is pinned to PHP_INT_MAX depth and an `orphan` warning
 * is appended to $warnings. Orphans usually indicate a missing parent in the
 * upstream WHERE clause (e.g. parent is a HomePage which lives outside the
 * contentPages destined classes).
 *
 * Threat T-04-08-01: depth recursion is bounded by the number of distinct ids
 * in the input set (no input → no recursion). Each id is visited at most
 * twice (once by the outer foreach, once during a parent's `computeDepth`).
 * For pathological inputs (e.g. 1,000-deep chain), a hard guard at depth 1000
 * forces participants to the end as if they were a cycle.
 *
 * Phase 02.1 v2 reshape (Plan 02.1-03 Task 2): v1's report-handle parameter
 * replaced with an out-parameter `array &$warnings`. declare(strict_types=1)
 * added per v2 convention.
 */
class TopologicalOrderer
{
    private const MAX_DEPTH_GUARD = 1000;

    /**
     * Sort rows so parents come before children. See class docblock.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param list<string>                     $warnings  Out-parameter; cycle/orphan/depth-guard messages appended here.
     * @return array<int, array<string, mixed>>
     */
    public function orderByParent(array $rows, array &$warnings = []): array
    {
        // Index rows by id for O(1) parent lookup.
        $byId = [];
        foreach ($rows as $row) {
            $id = (int) ($row['kuma_node_id'] ?? 0);
            if ($id > 0) {
                $byId[$id] = $row;
            }
        }

        $depth = [];
        $visiting = [];

        $computeDepth = function (int $id) use (&$computeDepth, &$byId, &$depth, &$visiting, &$warnings): int {
            if (isset($depth[$id])) {
                return $depth[$id];
            }
            if (isset($visiting[$id])) {
                // cycle — caller chain detected revisit before depth was finalised
                $warnings[] = sprintf('Cycle detected in kuma_nodes at id=%d', $id);
                return PHP_INT_MAX;
            }

            $visiting[$id] = true;

            $parentId = $byId[$id]['kuma_parent_id'] ?? null;
            if ($parentId === null || (int) $parentId === 0) {
                $d = 0;
            } elseif (!isset($byId[(int) $parentId])) {
                // orphan — parent_id not in the input set
                $warnings[] = sprintf(
                    'Orphan kuma_nodes.id=%d has parent_id=%d not in result set',
                    $id,
                    (int) $parentId,
                );
                $d = PHP_INT_MAX;
            } else {
                $parentDepth = $computeDepth((int) $parentId);
                if ($parentDepth === PHP_INT_MAX) {
                    // Parent is itself orphan/cycle → propagate end-marker
                    $d = PHP_INT_MAX;
                } else {
                    $d = 1 + $parentDepth;
                    if ($d > self::MAX_DEPTH_GUARD) {
                        $warnings[] = sprintf(
                            'Tree depth exceeded safe limit at kuma_nodes.id=%d (depth=%d)',
                            $id,
                            $d,
                        );
                        $d = PHP_INT_MAX;
                    }
                }
            }

            unset($visiting[$id]);
            $depth[$id] = $d;
            return $d;
        };

        // Compute depth for every input row (also covers rows whose ids were
        // null/zero — they get depth 0 below as fallback).
        foreach ($rows as $row) {
            $id = (int) ($row['kuma_node_id'] ?? 0);
            if ($id > 0) {
                $computeDepth($id);
            }
        }

        // Stable sort: pair each row with (depth, originalIndex) and usort.
        $indexed = [];
        foreach ($rows as $i => $row) {
            $id = (int) ($row['kuma_node_id'] ?? 0);
            $d = $id > 0 && isset($depth[$id]) ? $depth[$id] : 0;
            $indexed[] = [$d, $i, $row];
        }

        usort(
            $indexed,
            static fn(array $a, array $b): int => ($a[0] <=> $b[0]) ?: ($a[1] <=> $b[1]),
        );

        return array_map(static fn(array $tuple): array => $tuple[2], $indexed);
    }
}
