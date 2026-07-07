<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\console;

use craft\console\Controller;
use lameco\kunstmaanmigrator\load\MigrationStateService;
use lameco\kunstmaanmigrator\Plugin;
use yii\console\ExitCode;

/**
 * Task 6 — resume/verify export. `state/export` streams every entry-producing
 * state row (reusing MigrationStateService::entryRows() rather than
 * reimplementing the query — that generator already covers both primary
 * entries and alias rows, since MigrationStateService::recordAlias() also
 * writes targetType='entry') and reconstitutes each row's `sourceUid`.
 *
 * State-key encoding round-trip (see RefResolver, the single source of truth
 * for the grammar): a row is stored with `source = "<ENV>:<table>"`,
 * `sourceKey = "<id>"` — the exact split RefResolver::parse() produces from
 * `kuma:<ENV>:<table>:<id>`. Export reverses that split by simple string
 * concatenation (`"kuma:{$source}:{$sourceKey}"`), so
 * `RefResolver::resolve($exportedSourceUid)` always resolves back to the
 * same `entryId` this row carries — see StateExportTest's round-trip test.
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
        foreach ($stateService->entryRows() as $row) {
            $rows[] = self::exportRow($row);
        }

        return $rows;
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
