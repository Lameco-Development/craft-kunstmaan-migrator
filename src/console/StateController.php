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
 * Task 6 — resume/verify export. `state/export` reads every entry-producing
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
    public function actionExport(): int
    {
        $plugin = Plugin::getInstance();

        foreach (self::buildExportRows($plugin->migrationStateService) as $row) {
            $this->stdout(json_encode($row, JSON_UNESCAPED_SLASHES) . PHP_EOL);
        }

        return ExitCode::OK;
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
                'kuma-loader',
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
