<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\analyze;

use lameco\kunstmaanmigrator\Plugin;
use lameco\kunstmaanmigrator\filter\MigrationFilters;
use yii\base\Component;

/**
 * Reads the legacy MySQL schema and returns a structured dump array.
 *
 * Output shape:
 *   [
 *     'generatedAt' => '2026-04-25T17:30:00+00:00',
 *     'driver'      => 'mysql',
 *     'tables'      => [ '<tableName>' => <rowCount>, ... ],
 *     'columns'     => [ '<tableName>' => [ {column, sqlType, fillRate, samples}, ... ], ... ],
 *     'locales'     => [ 'nl', 'fr', ... ],
 *   ]
 *
 * Samples are collected via `LegacyDbService::streamQuery` (T-2-09 mitigation:
 * SELECT * on a 5k-entry table would OOM with queryAll; the generator yields
 * rows one at a time). The LIMIT clause caps the scan so we never iterate the
 * full table even when streaming.
 *
 * Filter integration (D-13): when $filters->entities is non-empty, only those
 * tables pass through the entity-snake-case prefix match. Empty entities ⇒
 * every kuma_* table is dumped. Locales are detected separately via
 * LocalePreflight::detect (single source of truth).
 *
 * No file I/O — caller writes via MappingFile::writeAtomicJson.
 */
final class SchemaDumper extends Component
{
    /** @var int Max distinct sample values to collect per column. */
    public int $maxSamplesPerColumn = 5;

    /** @var int Max rows to scan per table during sample collection. */
    public int $maxRowsScanPerTable = 1000;

    /**
     * @param ?list<string> $sourceTableList  When provided (Phase 02.1 / D-42 step 6 wiring),
     *   skips the `LIKE 'kuma\_%'` discovery query and uses this caller-supplied list
     *   instead. Phase 02.1's `KunstmaanSourceScanner.scan()['tables']` is the canonical
     *   source — it parses `@ORM\Table(name=...)` from the operator's PHP entity files,
     *   which is more accurate than a runtime DB prefix scan. When null, the legacy
     *   `LIKE 'kuma\_%'` fallback path is preserved (keeps the helper unit-testable
     *   and continues to work for callers that haven't migrated to the scanner yet).
     * @return array{
     *   generatedAt: string,
     *   driver:      string,
     *   tables:      array<string, int>,
     *   columns:     array<string, list<array<string, mixed>>>,
     *   locales:     list<string>,
     * }
     */
    public function dump(MigrationFilters $filters, ?array $sourceTableList = null): array
    {
        $db = Plugin::getInstance()->legacyDbService;
        $conn = $db->db();
        $driver = $conn->getDriverName();
        $schema = $this->extractSchemaName($conn->dsn);

        // 1. List candidate tables. When the caller supplied a discovered table list
        //    (Phase 02.1 / D-42 step 6 — KunstmaanSourceScanner.scan()['tables']), use
        //    it directly. Otherwise fall back to the legacy `kuma_*` prefix scan.
        //    The entities filter still applies in both modes.
        $allTables = $sourceTableList !== null
            ? array_values(array_filter($sourceTableList, static fn($t): bool => is_string($t) && $t !== ''))
            : $this->listKunstmaanTables($schema);
        $tables = $this->applyEntitiesFilter($allTables, $filters);

        // 2. Per-table row count.
        $rowCounts = [];
        foreach ($tables as $t) {
            $count = (int) $db->queryScalar("SELECT COUNT(*) FROM `{$t}`");
            $rowCounts[$t] = $count;
        }

        // 3. Per-table column metadata (information_schema).
        $columnsByTable = [];
        foreach ($tables as $t) {
            $cols = $db->queryAll(
                'SELECT COLUMN_NAME, DATA_TYPE FROM information_schema.columns WHERE TABLE_SCHEMA = :s AND TABLE_NAME = :t ORDER BY ORDINAL_POSITION',
                [':s' => $schema, ':t' => $t],
            );
            $columnsByTable[$t] = [];
            foreach ($cols as $col) {
                $colName = (string) ($col['COLUMN_NAME'] ?? '');
                $sqlType = (string) ($col['DATA_TYPE'] ?? '');
                if ($colName === '') { continue; }
                $columnsByTable[$t][] = [
                    'column'   => $colName,
                    'sqlType'  => $sqlType,
                    'fillRate' => 0.0,
                    'samples'  => [],
                ];
            }
        }

        // 4. Per-table sample collection via streamQuery (T-2-09 streaming).
        foreach ($columnsByTable as $t => &$cols) {
            $rowCount = $rowCounts[$t] ?? 0;
            if ($rowCount === 0) { continue; }
            $scanLimit = min($rowCount, $this->maxRowsScanPerTable);

            // Initialize per-column counters.
            $nonNullByCol = [];
            $samplesByCol = [];
            foreach ($cols as $c) {
                $nonNullByCol[$c['column']] = 0;
                $samplesByCol[$c['column']] = [];
            }

            $rowsSeen = 0;
            foreach ($db->streamQuery("SELECT * FROM `{$t}` LIMIT {$scanLimit}") as $row) {
                $rowsSeen++;
                foreach ($row as $colName => $value) {
                    $colName = (string) $colName;
                    if (!isset($nonNullByCol[$colName])) { continue; }
                    if ($value !== null && $value !== '') {
                        $nonNullByCol[$colName]++;
                        if (count($samplesByCol[$colName]) < $this->maxSamplesPerColumn
                            && !in_array($value, $samplesByCol[$colName], true)) {
                            $samplesByCol[$colName][] = $value;
                        }
                    }
                }
                if ($rowsSeen >= $scanLimit) { break; }
            }

            foreach ($cols as &$c) {
                $name = $c['column'];
                $c['fillRate'] = $rowsSeen > 0 ? round($nonNullByCol[$name] / $rowsSeen, 4) : 0.0;
                $c['samples']  = array_values($samplesByCol[$name]);
            }
            unset($c);
        }
        unset($cols);

        // 5. Locales (delegate to LocalePreflight::detect — single source of truth).
        $locales = Plugin::getInstance()->localePreflight->detect();

        return [
            'generatedAt' => date('c'),
            'driver'      => $driver,
            'tables'      => $rowCounts,
            'columns'     => $columnsByTable,
            'locales'     => $locales,
        ];
    }

    /**
     * @return list<string>
     */
    private function listKunstmaanTables(string $schema): array
    {
        $rows = Plugin::getInstance()->legacyDbService->queryAll(
            'SELECT TABLE_NAME FROM information_schema.tables WHERE TABLE_SCHEMA = :s AND TABLE_NAME LIKE :p ORDER BY TABLE_NAME',
            [':s' => $schema, ':p' => 'kuma\\_%'],
        );
        $out = [];
        foreach ($rows as $r) {
            $name = (string) ($r['TABLE_NAME'] ?? '');
            if ($name !== '') {
                $out[] = $name;
            }
        }
        return $out;
    }

    /**
     * Apply $filters->entities to a candidate table list.
     * Empty entities = unbounded (every kuma_* table passes).
     * Non-empty = include only tables matching `kuma_<snake_case_of_entity>` heuristic.
     *
     * @param list<string> $tables
     * @return list<string>
     */
    private function applyEntitiesFilter(array $tables, MigrationFilters $filters): array
    {
        if ($filters->entities === []) {
            return $tables;
        }
        $needles = [];
        foreach ($filters->entities as $e) {
            // 'NewsPage' → 'news_page' (Kunstmaan table convention).
            // The pattern is a literal so preg_replace can only return null
            // if PCRE itself is broken. The `?? $e` fallback documents that
            // and prevents a silent type-juggle to null on the strtolower call.
            $snake = preg_replace('/(?<!^)[A-Z]/', '_$0', $e);
            assert(is_string($snake), 'preg_replace returned null for literal snake-case pattern');
            $needles[] = 'kuma_' . strtolower($snake ?? $e);
        }
        $out = [];
        foreach ($tables as $t) {
            foreach ($needles as $n) {
                if (str_starts_with($t, $n)) {
                    $out[] = $t;
                    break;
                }
            }
        }
        return $out;
    }

    /**
     * Pull the schema name out of the legacy DSN. Fail-fast on parse miss
     * (IN-03): if `dbname=...` isn't present (e.g. unix-socket-only DSN,
     * custom DSN forms), returning an empty string would silently propagate
     * into the `:s` bind on the information_schema queries and surface as a
     * baffling "0 tables found". Throwing here aligns with the preflight
     * philosophy — operators see a clear, actionable error.
     */
    private function extractSchemaName(string $dsn): string
    {
        if (preg_match('/dbname=([^;]+)/', $dsn, $m)) {
            return $m[1];
        }
        throw new \RuntimeException(
            'Could not extract dbname from legacy DSN. '
            . 'Set legacyDbDatabase in plugin Settings (or include dbname=... in the DSN).',
        );
    }
}
