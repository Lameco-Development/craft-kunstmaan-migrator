<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\console;

use Generator;
use lameco\kunstmaanmigrator\console\StateController;
use lameco\kunstmaanmigrator\load\MigrationStateService;
use lameco\kunstmaanmigrator\payload\RefResolver;
use PHPUnit\Framework\TestCase;

/**
 * In-memory stand-in for the state table's DB-touching primitives, same
 * "override only the primitives, inherit the rest" convention as
 * FixupTest's/PayloadEntrySaverTest's *InMemoryMigrationStateService — lets
 * StateController::buildExportRows() and RefResolver both exercise a real
 * MigrationStateService subclass without booting Craft/DB.
 */
final class ExportFakeStateService extends MigrationStateService
{
    /** @var list<array<string, mixed>> */
    private array $rows = [];

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function seedRows(array $rows): void
    {
        $this->rows = $rows;
    }

    public function get(string $source, string $key, ?int $siteId = null): ?array
    {
        foreach ($this->rows as $row) {
            if (($row['source'] ?? null) === $source && (string) ($row['sourceKey'] ?? '') === $key) {
                return $row;
            }
        }

        return null;
    }

    public function getTargetId(string $source, string $key, ?int $siteId = null): ?int
    {
        $row = $this->get($source, $key, $siteId);

        return ($row !== null && $row['targetId'] !== null) ? (int) $row['targetId'] : null;
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    public function entryRows(): Generator
    {
        foreach ($this->rows as $row) {
            if (($row['targetType'] ?? null) === 'entry') {
                yield $row;
            }
        }
    }
}

/**
 * Task 6 — `state/export` NDJSON shape. StateController::buildExportRows()
 * is the pure, Craft-app-free builder behind `actionExport()`: it streams
 * MigrationStateService::entryRows() (reused, not reimplemented — see the
 * brief) and reconstitutes each row's `sourceUid` from the (source,
 * sourceKey) pair the same way RefResolver::parse() splits it, so the two
 * are exact inverses of one another.
 */
final class StateExportTest extends TestCase
{
    private function rowsForOnePrimaryEntry(): array
    {
        return [
            [
                'source' => 'COM:nt_page',
                'sourceKey' => '143',
                'targetType' => 'entry',
                'targetId' => 881,
                'targetUid' => 'uid-881',
                'siteId' => null,
                'meta' => null,
            ],
        ];
    }

    public function testExportRowShapeForAPrimaryEntry(): void
    {
        $state = new ExportFakeStateService();
        $state->seedRows($this->rowsForOnePrimaryEntry());

        $rows = StateController::buildExportRows($state);

        self::assertSame([
            [
                'sourceUid' => 'kuma:COM:nt_page:143',
                'entryId' => 881,
                'targetType' => 'entry',
                'alias_of' => null,
            ],
        ], $rows);
    }

    public function testExportRowKeysAreInDocumentedOrder(): void
    {
        $state = new ExportFakeStateService();
        $state->seedRows($this->rowsForOnePrimaryEntry());

        $rows = StateController::buildExportRows($state);

        self::assertSame(['sourceUid', 'entryId', 'targetType', 'alias_of'], array_keys($rows[0]));
    }

    public function testAliasedRowCarriesPrimarySourceUidInAliasOf(): void
    {
        $state = new ExportFakeStateService();
        $state->seedRows([
            ...$this->rowsForOnePrimaryEntry(),
            [
                'source' => 'COM:nt_page',
                'sourceKey' => '144',
                'targetType' => 'entry',
                'targetId' => 881,
                'targetUid' => '',
                'siteId' => null,
                'meta' => ['alias_of' => 'kuma:COM:nt_page:143'],
            ],
        ]);

        $rows = StateController::buildExportRows($state);

        self::assertSame([
            [
                'sourceUid' => 'kuma:COM:nt_page:143',
                'entryId' => 881,
                'targetType' => 'entry',
                'alias_of' => null,
            ],
            [
                'sourceUid' => 'kuma:COM:nt_page:144',
                'entryId' => 881,
                'targetType' => 'entry',
                'alias_of' => 'kuma:COM:nt_page:143',
            ],
        ], $rows);
    }

    /**
     * Defensive path: meta stored as a JSON string (rather than the
     * already-decoded array Yii's MySQL JSON column reader normally hands
     * back) must still be read correctly — mirrors the same defensive
     * decode MigrationStateService::updateMeta()/isTerminalMarker() apply.
     */
    public function testAliasOfIsReadWhenMetaIsAJsonStringInsteadOfAnArray(): void
    {
        $state = new ExportFakeStateService();
        $state->seedRows([
            [
                'source' => 'COM:nt_page',
                'sourceKey' => '144',
                'targetType' => 'entry',
                'targetId' => 881,
                'targetUid' => '',
                'siteId' => null,
                'meta' => json_encode(['alias_of' => 'kuma:COM:nt_page:143']),
            ],
        ]);

        $rows = StateController::buildExportRows($state);

        self::assertSame('kuma:COM:nt_page:143', $rows[0]['alias_of']);
    }

    public function testMultipleRowsStreamInEntryRowsOrder(): void
    {
        $state = new ExportFakeStateService();
        $state->seedRows([
            [
                'source' => 'DE:nt_page',
                'sourceKey' => '87',
                'targetType' => 'entry',
                'targetId' => 42,
                'targetUid' => 'uid-42',
                'siteId' => null,
                'meta' => null,
            ],
            [
                'source' => 'COM:nt_page',
                'sourceKey' => '143',
                'targetType' => 'entry',
                'targetId' => 881,
                'targetUid' => 'uid-881',
                'siteId' => null,
                'meta' => null,
            ],
        ]);

        $rows = StateController::buildExportRows($state);

        self::assertSame(['kuma:DE:nt_page:87', 'kuma:COM:nt_page:143'], array_column($rows, 'sourceUid'));
    }

    /**
     * The round-trip proof the brief requires: an exported `sourceUid` fed
     * straight back into RefResolver::resolve() must return the exact same
     * Craft entry id the export row carried in `entryId` — proving the
     * export's `kuma:<source>:<sourceKey>` reconstitution is the true
     * inverse of RefResolver::parse()'s `source = "<ENV>:<table>"`,
     * `key = "<id>"` split.
     */
    public function testExportedSourceUidRoundTripsThroughRefResolverToTheSameEntryId(): void
    {
        $state = new ExportFakeStateService();
        $state->seedRows($this->rowsForOnePrimaryEntry());

        $rows = StateController::buildExportRows($state);
        $exportedSourceUid = $rows[0]['sourceUid'];
        $exportedEntryId = $rows[0]['entryId'];

        $resolver = new RefResolver($state);

        self::assertSame($exportedEntryId, $resolver->resolve($exportedSourceUid));
    }

    public function testEmptyStateProducesEmptyExport(): void
    {
        $state = new ExportFakeStateService();
        $state->seedRows([]);

        self::assertSame([], StateController::buildExportRows($state));
    }

    /**
     * `SeoMigrationService` records bookkeeping rows under
     * `source = 'seo_meta'` with a COMPOSITE `sourceKey` like `'881:1'`
     * (entryId:siteId) and `targetType = 'entry'`, so they show up in
     * `entryRows()` alongside genuine primary-entry rows. Naively
     * reconstituting `kuma:seo_meta:881:1` produces a `sourceUid` that
     * `RefResolver::parse()` splits as `source = 'seo_meta:881'`,
     * `key = '1'` — it does not round-trip back to `source = 'seo_meta'`,
     * `key = '881:1'`, so `RefResolver::resolve()` on it returns null.
     * Export must exclude such rows rather than emit lines a resume/verify
     * consumer can never resolve back to an entry.
     */
    public function testBookkeepingRowsThatDoNotRoundTripAreExcludedFromExport(): void
    {
        $state = new ExportFakeStateService();
        $state->seedRows([
            ...$this->rowsForOnePrimaryEntry(),
            [
                'source' => 'seo_meta',
                'sourceKey' => '881:1',
                'targetType' => 'entry',
                'targetId' => 881,
                'targetUid' => '',
                'siteId' => null,
                'meta' => null,
            ],
        ]);

        $excluded = 0;
        $rows = StateController::buildExportRows($state, $excluded);

        self::assertCount(1, $rows);
        self::assertSame(1, $excluded, 'the exclusion is counted for the caller to report — the builder itself stays silent');
        self::assertSame('kuma:COM:nt_page:143', $rows[0]['sourceUid']);

        foreach ($rows as $row) {
            self::assertStringStartsNotWith('kuma:seo_meta:', $row['sourceUid']);
        }

        $resolver = new RefResolver($state);

        self::assertSame(881, $resolver->resolve($rows[0]['sourceUid']));
    }
}
