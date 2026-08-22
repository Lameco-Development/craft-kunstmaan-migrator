<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\console;

use Craft;
use craft\console\Controller;
use lameco\kunstmaanmigrator\load\MigrationStateService;
use lameco\kunstmaanmigrator\payload\RefResolver;
use lameco\kunstmaanmigrator\Plugin;
use yii\console\ExitCode;

/**
 * The state table, out. `state/export` streams it as NDJSON; `state/diff` compares two of those
 * streams, which is the question correct-and-re-run actually asks and the one that used to be
 * answered with hand-written SQL against scratch databases.
 *
 * `state/export` reads every entry-producing
 * state row (reusing MigrationStateService::entryRows() rather than
 * reimplementing the query — that generator already covers both primary
 * entries and alias rows, since MigrationStateService::recordAlias() also
 * writes targetType='entry') into a materialized array and reconstitutes
 * each row's `sourceUid` before `actionExport()` emits it as NDJSON.
 *
 * State-key encoding round-trip (see RefResolver, the single source of truth
 * for the grammar): a row is stored with `source = "<ENV>:<table>"`,
 * `sourceKey = "<id>"` — the exact split RefResolver::parse() produces from
 * `kuma:<ENV>:<table>:<id>`. Export reverses that split by simple string
 * concatenation (`"kuma:{$source}:{$sourceKey}"`), so
 * `RefResolver::resolve($exportedSourceUid)` always resolves back to the
 * same `entryId` this row carries — see StateExportTest's round-trip test.
 *
 * Not every `entryRows()` row fits that grammar, though: bookkeeping rows
 * such as the ones `SeoMigrationService` writes under `source = 'seo_meta'`
 * carry a COMPOSITE `sourceKey` (e.g. `'881:1'`, entryId:siteId), so naively
 * concatenating them produces a `sourceUid` that `RefResolver::parse()`
 * splits differently than it was written — it doesn't round-trip. Emitting
 * those would hand a resume/verify consumer a line it can never resolve
 * back to an entry, so `buildExportRows()` runs every candidate `sourceUid`
 * back through `RefResolver::parse()` and only emits rows that match
 * exactly (same convention `RedirectMigrationService::emitSectionMoveRedirects()`
 * uses against the same generator, via an explicit `seo_meta`/`:`-in-key
 * check rather than this round-trip check).
 *
 * Read-only and not legacy-DB-reading, so — unlike doctor and the write
 * commands in LoadController — this does not use NeverProductionTrait: an
 * operator may legitimately need to export/verify state against a
 * production Craft install after a migration has gone live.
 */
class StateController extends Controller
{
    public ?string $from = null;
    public ?string $to = null;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), $actionID === 'diff' ? ['from', 'to'] : []);
    }

    public function actionExport(): int
    {
        $plugin = Plugin::getInstance();

        foreach (self::buildExportRows($plugin->migrationStateService) as $row) {
            $this->stdout(json_encode($row, JSON_UNESCAPED_SLASHES) . PHP_EOL);
        }

        return ExitCode::OK;
    }

    /**
     * Two exports, and what moved between them.
     *
     * `state/export` gives you the files; comparing them was left to the operator, and on the
     * reference corpus that meant six scratch databases and hand-written SQL. Correct-and-re-run
     * is the workflow this plugin is built around, so the question "is this run better than the
     * last one" deserves an answer that exits non-zero rather than one someone has to read.
     *
     * What it can answer is bounded by what the export carries. An entry that stopped being
     * written is a regression, full stop. An entry whose `entryId` moved was re-created rather
     * than updated, which is the churn signal — anything holding the old element id is stale.
     * Block counts are not in the export, so this does not claim to see them.
     */
    public function actionDiff(): int
    {
        if (!is_string($this->from) || !is_string($this->to)) {
            $this->stderr('state/diff needs --from=<export.ndjson> and --to=<export.ndjson>' . PHP_EOL);

            return ExitCode::USAGE;
        }

        try {
            $diff = self::diff(self::readExport($this->from), self::readExport($this->to));
        } catch (\RuntimeException $e) {
            $this->stderr($e->getMessage() . PHP_EOL);

            return ExitCode::NOINPUT;
        }

        $this->stdout(json_encode($diff, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);

        // Only a loss fails. New entries and re-created ones are what a corrected mapping is
        // supposed to produce; an entry the previous run wrote and this one did not is the only
        // outcome that is a regression regardless of intent.
        return $diff['lost'] === [] ? ExitCode::OK : ExitCode::DATAERR;
    }

    /**
     * @param list<array<string, mixed>> $from
     * @param list<array<string, mixed>> $to
     * @return array{counts: array<string, int>, lost: list<string>, added: list<string>, recreated: list<array{sourceUid: string, was: ?int, now: ?int}>}
     */
    public static function diff(array $from, array $to): array
    {
        $before = self::byUid($from);
        $after = self::byUid($to);

        $lost = array_values(array_diff(array_keys($before), array_keys($after)));
        $added = array_values(array_diff(array_keys($after), array_keys($before)));
        $recreated = [];

        foreach ($after as $uid => $row) {
            $was = $before[$uid]['entryId'] ?? null;

            if (!isset($before[$uid]) || $was === $row['entryId']) {
                continue;
            }

            $recreated[] = ['sourceUid' => $uid, 'was' => $was, 'now' => $row['entryId']];
        }

        sort($lost);
        sort($added);
        usort($recreated, static fn (array $a, array $b): int => strcmp($a['sourceUid'], $b['sourceUid']));

        return [
            'counts' => [
                'from' => count($before),
                'to' => count($after),
                'lost' => count($lost),
                'added' => count($added),
                'recreated' => count($recreated),
            ],
            'lost' => $lost,
            'added' => $added,
            'recreated' => $recreated,
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, array{entryId: ?int}>
     */
    private static function byUid(array $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            $uid = (string) ($row['sourceUid'] ?? '');

            if ($uid === '') {
                continue;
            }

            $out[$uid] = ['entryId' => isset($row['entryId']) ? (int) $row['entryId'] : null];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private static function readExport(string $path): array
    {
        $handle = @fopen($path, 'r');

        if ($handle === false) {
            throw new \RuntimeException(sprintf('state/diff: cannot read %s', $path));
        }

        $rows = [];

        while (($line = fgets($handle)) !== false) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, true);

            if (!is_array($decoded)) {
                fclose($handle);

                throw new \RuntimeException(sprintf('state/diff: %s is not NDJSON produced by state/export', $path));
            }

            $rows[] = $decoded;
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @return list<array{sourceUid: string, entryId: ?int, targetType: string, alias_of: ?string}>
     */
    public static function buildExportRows(MigrationStateService $stateService): array
    {
        $rows = [];
        $excluded = 0;
        foreach ($stateService->entryRows() as $row) {
            $source = (string) ($row['source'] ?? '');
            $key = (string) ($row['sourceKey'] ?? '');

            if (!self::sourceUidRoundTrips($source, $key)) {
                $excluded++;
                continue;
            }

            $rows[] = self::exportRow($row);
        }

        if ($excluded > 0) {
            Craft::warning(
                sprintf(
                    'state/export: excluded %d state row(s) whose reconstructed sourceUid does not round-trip through RefResolver::parse() (composite-key bookkeeping rows, e.g. seo_meta).',
                    $excluded,
                ),
                'kunstmaan-migrator',
            );
        }

        return $rows;
    }

    /**
     * A row's `sourceUid` is only safe to export when it genuinely
     * round-trips: reconstructing `kuma:{$source}:{$key}` and parsing it
     * back through `RefResolver::parse()` — the single source of truth for
     * the grammar — must yield the exact same `(source, key)` pair. Rows
     * that fail this (composite `sourceKey`s, or any source not shaped like
     * `<ENV>:<table>`) are bookkeeping, not primary entry mappings, and a
     * resume/verify consumer could never resolve them back anyway.
     */
    private static function sourceUidRoundTrips(string $source, string $key): bool
    {
        $parsed = RefResolver::parse(sprintf('kuma:%s:%s', $source, $key));

        return $parsed !== null && $parsed['source'] === $source && $parsed['key'] === $key;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{sourceUid: string, entryId: ?int, targetType: string, alias_of: ?string}
     */
    private static function exportRow(array $row): array
    {
        $source = (string) ($row['source'] ?? '');
        $key = (string) ($row['sourceKey'] ?? '');
        $meta = self::decodeMeta($row['meta'] ?? null);
        $aliasOf = $meta['alias_of'] ?? null;

        return [
            'sourceUid' => sprintf('kuma:%s:%s', $source, $key),
            'entryId' => isset($row['targetId']) && $row['targetId'] !== null ? (int) $row['targetId'] : null,
            'targetType' => (string) ($row['targetType'] ?? ''),
            'alias_of' => is_string($aliasOf) ? $aliasOf : null,
        ];
    }

    /**
     * Defensive decode mirroring MigrationStateService's own handling of the
     * `meta` JSON column: Yii's MySQL driver normally hands back an
     * already-decoded array, but a row written through a different path may
     * carry a raw JSON string instead.
     *
     * @return array<string, mixed>
     */
    private static function decodeMeta(mixed $meta): array
    {
        if (is_array($meta)) {
            return $meta;
        }
        if (!is_string($meta) || $meta === '') {
            return [];
        }

        $decoded = json_decode($meta, true);

        return is_array($decoded) ? $decoded : [];
    }
}
