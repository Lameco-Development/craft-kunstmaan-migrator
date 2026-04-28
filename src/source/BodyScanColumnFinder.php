<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\source;

use lameco\kunstmaanmigrator\db\LegacyDbService;
use lameco\kunstmaanmigrator\source\KunstmaanCoreTables;
use Craft;
use Throwable;
use yii\base\Component;

/**
 * Auto-discover longtext/text columns likely to contain CKEditor body content.
 *
 * Produces the same `array<string, list<string>>` shape (`[$table => [$col1, $col2]]`)
 * that `AssetScanService::$assetBodyScanTables` consumes today — drop-in replacement
 * when that config key is empty.
 *
 * Rule (see phase 05.5 CONTEXT §Claude's Discretion):
 *   - data-type: `longtext` or `text`
 *   - column-name regex: `(content|intro|summary|body|description)` (case-insensitive)
 *   - skip KunstmaanCoreTables entries
 *
 * SQL safety (T-05.5-04-01 mitigation): `TABLE_SCHEMA` and `DATA_TYPE` are always
 * bound via named PDO parameters; never string-concatenated.
 *
 * @see .planning/phases/05.5-harden-migrator-plugin-error-handling/05.5-CONTEXT.md §Claude's Discretion
 */
final class BodyScanColumnFinder extends Component
{
    /** DI slot — replaces v1's plugin-level legacyDb accessor. */
    public ?LegacyDbService $legacyDb = null;

    /**
     * Developer-authored inclusions appended to scan output.
     * @var list<array{table: string, column: string}>
     */
    public array $includes = [];

    /**
     * Developer-authored exclusions removed from scan output.
     * @var list<array{table: string, column: string}>
     */
    public array $excludes = [];

    /** @var array<string, list<string>>|null */
    private ?array $cache = null;

    /**
     * @return array<string, list<string>>
     */
    public function scan(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $pairs = [];
        try {
            if ($this->legacyDb === null) {
                return $this->cache = [];
            }

            $dbName = (string) $this->legacyDb->db()
                ->createCommand('SELECT DATABASE()')
                ->queryScalar();

            $rows = $this->legacyDb->queryAll(
                'SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS '
                . 'WHERE TABLE_SCHEMA = :db AND DATA_TYPE IN (:dt1, :dt2)',
                [':db' => $dbName, ':dt1' => 'longtext', ':dt2' => 'text'],
            );

            $skip = [
                KunstmaanCoreTables::NODES,
                KunstmaanCoreTables::NODE_TRANSLATIONS,
                KunstmaanCoreTables::NODE_VERSIONS,
                KunstmaanCoreTables::PAGE_PART_REFS,
                KunstmaanCoreTables::MEDIA,
            ];

            $rx = '/(content|intro|summary|body|description)/i';

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
                $pairs[] = ['table' => $table, 'column' => $column];
            }
        } catch (Throwable $e) {
            Craft::warning(
                "BodyScanColumnFinder scan failed: {$e->getMessage()}",
                __METHOD__,
            );
        }

        // Apply overrides at the (table, column) pair level.
        $pairs = array_merge($pairs, $this->normalizePairs($this->includes));

        $excludedSet = [];
        foreach ($this->normalizePairs($this->excludes) as $p) {
            $excludedSet[$p['table'] . '||' . $p['column']] = true;
        }

        // Group into the array<string, list<string>> shape AssetScanService expects.
        $grouped = [];
        foreach ($pairs as $p) {
            $key = $p['table'] . '||' . $p['column'];
            if (isset($excludedSet[$key])) {
                continue;
            }
            $grouped[$p['table']][] = $p['column'];
        }

        // Dedup columns per table, preserve order.
        foreach ($grouped as $table => $cols) {
            $grouped[$table] = array_values(array_unique($cols));
        }

        return $this->cache = $grouped;
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
