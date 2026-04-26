<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\source;

use lameco\kunstmaanmigrator\db\LegacyDbService;
use lameco\kunstmaanmigrator\source\KunstmaanCoreTables;
use lameco\kunstmaanmigrator\source\DoctrineEntityParser;
use Craft;
use Throwable;
use yii\base\Component;

/**
 * FQCN → legacy detail-table resolver.
 *
 * Resolution priority (first match wins):
 *  1. Per-FQCN $overrides array — operator escape hatch.
 *  2. DoctrineEntityParser — reads #[ORM\Table(name: '...')] from source code.
 *     This is the authoritative answer: source code = structure.
 *  3. information_schema prefix scan — fallback for cases where the source
 *     checkout is unavailable or an entity has no explicit @Table annotation.
 *  4. All-tables suffix scan — last resort for non-standard table prefixes.
 *
 * SQL safety (T-05.5-03-01 mitigation): TABLE_SCHEMA and TABLE_NAME LIKE
 * are always bound via named PDO parameters; never string-concatenated.
 *
 * @see .planning/phases/05.5-harden-migrator-plugin-error-handling/05.5-CONTEXT.md §Claude's Discretion
 */
final class DetailTableResolver extends Component
{
    /** DI slot — replaces v1's plugin-level legacyDb accessor. */
    public ?LegacyDbService $legacyDb = null;

    /**
     * Doctrine entity parser for source-code–driven table resolution.
     * When set, #[ORM\Table(name: '...')] is consulted before the DB scan.
     */
    public ?DoctrineEntityParser $entityParser = null;

    /** @var array<string, string> FQCN → table override (from config/kunstmaan-migrator.php overrides.detailTableOverrides) */
    public array $overrides = [];

    /** Configurable legacy-table prefix; defaults to the Kunstmaan convention. */
    public string $legacyTablePrefix = 'lameco_websitebundle_';

    /** @var array<string, string>|null Scanned-table map (basename without prefix → full table name); null = unwarmed */
    private ?array $scanCache = null;

    /**
     * All table names in the legacy schema, loaded lazily for suffix-based fallback matching.
     * Populated only when the primary prefix scan yields no match for a candidate.
     *
     * @var string[]|null
     */
    private ?array $allTablesCache = null;

    /** @var array<string, string> Per-request resolution cache: FQCN → resolved table name */
    private array $resolveCache = [];

    /**
     * Resolve an FQCN to its legacy detail table name.
     *
     * @throws \InvalidArgumentException when neither overrides nor the schema scan can resolve the FQCN
     */
    public function resolve(string $fqcn): string
    {
        if (isset($this->resolveCache[$fqcn])) {
            return $this->resolveCache[$fqcn];
        }

        // 1. Overrides win outright — operator escape hatch.
        if (isset($this->overrides[$fqcn])) {
            return $this->resolveCache[$fqcn] = (string) $this->overrides[$fqcn];
        }

        // 2. Doctrine entity parser — source code is the authoritative table name.
        if ($this->entityParser !== null) {
            $info = $this->entityParser->getByFqcn($fqcn);
            if ($info !== null && $info->tableName !== '') {
                return $this->resolveCache[$fqcn] = $info->tableName;
            }
        }

        // 3. Derive candidate basenames from the FQCN tail (DB scan fallback).
        $parts = explode('\\', $fqcn);
        $suffix = end($parts);
        if (!is_string($suffix) || $suffix === '') {
            throw new \InvalidArgumentException("Cannot resolve {$fqcn} to a legacy detail table.");
        }

        $snake = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $suffix));
        $candidates = [
            $snake . 's',   // plural (HomePage → home_pages)
            $snake,         // singular (error_page)
            $snake . 'es',  // edge-case plural
        ];

        $map = $this->scanMap();
        foreach ($candidates as $cand) {
            if (isset($map[$cand])) {
                return $this->resolveCache[$fqcn] = $map[$cand];
            }
        }

        // Fallback: some installations use a custom prefix (e.g. lameco_websitebundle_)
        // instead of the standard kuma_ prefix. Scan all tables and match by suffix.
        foreach ($this->allTables() as $table) {
            foreach ($candidates as $cand) {
                if (str_ends_with($table, '_' . $cand)) {
                    Craft::info(
                        "DetailTableResolver: resolved {$fqcn} via suffix match → {$table}",
                        __METHOD__,
                    );
                    return $this->resolveCache[$fqcn] = $table;
                }
            }
        }

        throw new \InvalidArgumentException("Cannot resolve {$fqcn} to a legacy detail table.");
    }

    /**
     * Build or return the `{basename_without_prefix → full_table_name}` map.
     *
     * Scans `information_schema.TABLES` for every table whose name starts with
     * `$this->legacyTablePrefix`. Kunstmaan core tables (fixed-contract readers,
     * not consumer detail tables) are excluded from the map.
     *
     * @return array<string, string>
     */
    private function scanMap(): array
    {
        if ($this->scanCache !== null) {
            return $this->scanCache;
        }

        $map = [];
        try {
            if ($this->legacyDb === null) {
                return $this->scanCache = $map;
            }

            $dbName = (string) $this->legacyDb->db()
                ->createCommand('SELECT DATABASE()')
                ->queryScalar();

            // Bounded parameters — never string-concat user/config values into SQL.
            $rows = $this->legacyDb->queryAll(
                'SELECT TABLE_NAME FROM information_schema.TABLES '
                . 'WHERE TABLE_SCHEMA = :db AND TABLE_NAME LIKE :prefix',
                [':db' => $dbName, ':prefix' => $this->legacyTablePrefix . '%'],
            );

            $coreSkip = [
                KunstmaanCoreTables::NODES,
                KunstmaanCoreTables::NODE_TRANSLATIONS,
                KunstmaanCoreTables::NODE_VERSIONS,
                KunstmaanCoreTables::PAGE_PART_REFS,
                KunstmaanCoreTables::MEDIA,
                KunstmaanCoreTables::SEO,
                KunstmaanCoreTables::REDIRECTS,
            ];
            foreach ($rows as $row) {
                $table = (string) ($row['TABLE_NAME'] ?? '');
                if ($table === '' || in_array($table, $coreSkip, true)) {
                    continue;
                }
                $basename = substr($table, strlen($this->legacyTablePrefix));
                if ($basename !== '' && $basename !== false) {
                    $map[$basename] = $table;
                }
            }
        } catch (Throwable $e) {
            Craft::warning(
                "DetailTableResolver scan failed: {$e->getMessage()}",
                __METHOD__,
            );
        }

        return $this->scanCache = $map;
    }

    /**
     * Return all table names in the legacy schema (cached per request).
     * Used as a fallback when the primary prefix scan can't resolve an FQCN —
     * e.g. when a project stores its page-part tables under a bundle-specific
     * prefix (lameco_websitebundle_*, acme_bundle_*, …) instead of kuma_*.
     *
     * @return string[]
     */
    private function allTables(): array
    {
        if ($this->allTablesCache !== null) {
            return $this->allTablesCache;
        }

        $list = [];
        try {
            if ($this->legacyDb !== null) {
                $dbName = (string) $this->legacyDb->db()
                    ->createCommand('SELECT DATABASE()')
                    ->queryScalar();

                $rows = $this->legacyDb->queryAll(
                    'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = :db',
                    [':db' => $dbName],
                );
                foreach ($rows as $row) {
                    $t = (string) ($row['TABLE_NAME'] ?? '');
                    if ($t !== '') {
                        $list[] = $t;
                    }
                }
            }
        } catch (Throwable $e) {
            Craft::warning(
                "DetailTableResolver allTables scan failed: {$e->getMessage()}",
                __METHOD__,
            );
        }

        return $this->allTablesCache = $list;
    }
}
