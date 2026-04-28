<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\source;

use lameco\kunstmaanmigrator\db\LegacyDbService;
use lameco\kunstmaanmigrator\source\KunstmaanCoreTables;
use Craft;
use Throwable;
use yii\base\Component;

/**
 * Auto-discover columns that reference `kuma_media.id` via information_schema scan.
 *
 * Produces the same `list<array{table: string, column: string}>` tuple shape that
 * `AssetScanService::$assetReferenceColumns` consumes today — drop-in replacement
 * when the config key is empty.
 *
 * Rule (see phase 05.5 CONTEXT §Claude's Discretion):
 *   - data-type: `bigint` (kuma_media.id is BIGINT)
 *   - column-name regex: `(image|icon|logo|banner|video|file|media|preview).*_?id$`
 *     OR `^image$|^video$`
 *   - skip KunstmaanCoreTables entries (they're fixed readers, not FK sources)
 *
 * SQL safety (T-05.5-04-01 mitigation): `TABLE_SCHEMA` and `DATA_TYPE` are always
 * bound via named PDO parameters; never string-concatenated.
 *
 * @see .planning/phases/05.5-harden-migrator-plugin-error-handling/05.5-CONTEXT.md §Claude's Discretion
 */
final class MediaFkScanner extends Component
{
    /** DI slot — replaces v1's plugin-level legacyDb accessor. */
    public ?LegacyDbService $legacyDb = null;

    /**
     * Developer-authored inclusions appended to the scan output after regex match.
     * @var list<array{table: string, column: string}>
     */
    public array $includes = [];

    /**
     * Developer-authored exclusions removed from the scan output.
     * @var list<array{table: string, column: string}>
     */
    public array $excludes = [];

    /** @var list<array{table: string, column: string}>|null */
    private ?array $cache = null;

    /**
     * Produce the full FK-column list: regex-matched rows + includes, minus excludes.
     *
     * @return list<array{table: string, column: string}>
     */
    public function scan(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $matched = [];
        try {
            if ($this->legacyDb === null) {
                return $this->cache = [];
            }

            $dbName = (string) $this->legacyDb->db()
                ->createCommand('SELECT DATABASE()')
                ->queryScalar();

            $rows = $this->legacyDb->queryAll(
                'SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS '
                . 'WHERE TABLE_SCHEMA = :db AND DATA_TYPE = :dtype',
                [':db' => $dbName, ':dtype' => 'bigint'],
            );

            $skip = [
                KunstmaanCoreTables::NODES,
                KunstmaanCoreTables::NODE_TRANSLATIONS,
                KunstmaanCoreTables::NODE_VERSIONS,
                KunstmaanCoreTables::PAGE_PART_REFS,
                KunstmaanCoreTables::MEDIA,
            ];

            // Column-name pattern per CONTEXT §Claude's Discretion (D-03).
            $rx = '/(image|icon|logo|banner|video|file|media|preview).*_?id$|^image$|^video$/i';

            foreach ($rows as $row) {
                $table = (string) ($row['TABLE_NAME'] ?? '');
                $column = (string) ($row['COLUMN_NAME'] ?? '');
                if ($table === '' || $column === '') {
                    continue;
                }
                if (in_array($table, $skip, true)) {
                    continue;
                }
                if (preg_match($rx, $column) !== 1) {
                    continue;
                }
                $matched[] = ['table' => $table, 'column' => $column];
            }
        } catch (Throwable $e) {
            Craft::warning(
                "MediaFkScanner scan failed: {$e->getMessage()}",
                __METHOD__,
            );
        }

        // Apply developer overrides: include first, then exclude.
        $merged = array_merge($matched, $this->normalizePairs($this->includes));

        $excludedSet = [];
        foreach ($this->normalizePairs($this->excludes) as $pair) {
            $excludedSet[$pair['table'] . '||' . $pair['column']] = true;
        }

        $result = [];
        $seen = [];
        foreach ($merged as $pair) {
            $key = $pair['table'] . '||' . $pair['column'];
            if (isset($excludedSet[$key])) {
                continue;
            }
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $pair;
        }

        return $this->cache = $result;
    }

    /**
     * @param  array<int, array{table?: string, column?: string}> $pairs
     * @return list<array{table: string, column: string}>
     */
    private function normalizePairs(array $pairs): array
    {
        $out = [];
        foreach ($pairs as $p) {
            if (!is_array($p) || !isset($p['table'], $p['column'])) {
                continue;
            }
            $out[] = ['table' => (string) $p['table'], 'column' => (string) $p['column']];
        }
        return $out;
    }
}
