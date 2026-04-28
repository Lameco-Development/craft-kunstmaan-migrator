<?php

namespace lameco\kunstmaanmigrator\verify;

use yii\base\Component;

/**
 * SnapshotDiffer — deep-diff two baseline snapshot arrays.
 *
 * Returns an empty array when snapshots are semantically identical; returns
 * a list of `{path, baseline, current}` entries otherwise. `meta.generatedAt`
 * and `meta.gitSha` are expected to differ run-to-run and are ignored.
 *
 * Element-wise comparison is performed on the `entries`, `files`, `bundles`,
 * and `redirects` arrays using the baseline's authoritative sort order. When
 * an item exists in one snapshot but not the other, a single entry is
 * emitted with the missing side's value set to null.
 */
class SnapshotDiffer extends Component
{
    /** Meta keys ignored during diff (timestamp/environment noise). */
    private const META_IGNORE = ['generatedAt', 'gitSha'];

    /**
     * Diff two snapshots. Empty array = identical.
     *
     * @param array<string, mixed> $baseline
     * @param array<string, mixed> $current
     * @return array<int, array{path: string, baseline: mixed, current: mixed}>
     */
    public function diff(array $baseline, array $current): array
    {
        $diffs = [];

        // Handle meta specially — skip volatile keys.
        $baseMeta = is_array($baseline['meta'] ?? null) ? $baseline['meta'] : [];
        $curMeta = is_array($current['meta'] ?? null) ? $current['meta'] : [];
        foreach (self::META_IGNORE as $ignore) {
            unset($baseMeta[$ignore], $curMeta[$ignore]);
        }
        $this->compareAssoc('meta', $baseMeta, $curMeta, $diffs);

        // Every other top-level key.
        $topKeys = array_unique(array_merge(
            array_diff(array_keys($baseline), ['meta']),
            array_diff(array_keys($current), ['meta']),
        ));
        sort($topKeys);

        foreach ($topKeys as $key) {
            $base = $baseline[$key] ?? null;
            $cur = $current[$key] ?? null;
            $this->compareValue((string) $key, $base, $cur, $diffs);
        }

        return $diffs;
    }

    /**
     * Compare two associative arrays key-by-key.
     *
     * @param array<int, array{path: string, baseline: mixed, current: mixed}> $diffs
     */
    private function compareAssoc(string $path, mixed $base, mixed $cur, array &$diffs): void
    {
        if (!is_array($base) || !is_array($cur)) {
            if ($base !== $cur) {
                $diffs[] = ['path' => $path, 'baseline' => $base, 'current' => $cur];
            }
            return;
        }
        $keys = array_unique(array_merge(array_keys($base), array_keys($cur)));
        sort($keys);
        foreach ($keys as $k) {
            $this->compareValue($path . '.' . (string) $k, $base[$k] ?? null, $cur[$k] ?? null, $diffs);
        }
    }

    /**
     * Compare any value — dispatches to assoc/list/scalar.
     *
     * @param array<int, array{path: string, baseline: mixed, current: mixed}> $diffs
     */
    private function compareValue(string $path, mixed $base, mixed $cur, array &$diffs): void
    {
        if (is_array($base) && is_array($cur)) {
            $baseList = array_is_list($base);
            $curList = array_is_list($cur);
            if ($baseList && $curList) {
                $this->compareList($path, $base, $cur, $diffs);
                return;
            }
            if (!$baseList && !$curList) {
                $this->compareAssoc($path, $base, $cur, $diffs);
                return;
            }
        }
        if ($base !== $cur) {
            $diffs[] = ['path' => $path, 'baseline' => $base, 'current' => $cur];
        }
    }

    /**
     * Compare two lists element-wise. Baseline's order is authoritative.
     *
     * @param list<mixed> $base
     * @param list<mixed> $cur
     * @param array<int, array{path: string, baseline: mixed, current: mixed}> $diffs
     */
    private function compareList(string $path, array $base, array $cur, array &$diffs): void
    {
        $max = max(count($base), count($cur));
        for ($i = 0; $i < $max; $i++) {
            $itemPath = $path . '[' . $i . ']';
            $baseHas = array_key_exists($i, $base);
            $curHas = array_key_exists($i, $cur);
            if ($baseHas && !$curHas) {
                $diffs[] = ['path' => $itemPath, 'baseline' => $base[$i], 'current' => null];
                continue;
            }
            if (!$baseHas && $curHas) {
                $diffs[] = ['path' => $itemPath, 'baseline' => null, 'current' => $cur[$i]];
                continue;
            }
            $this->compareValue($itemPath, $base[$i], $cur[$i], $diffs);
        }
    }
}
