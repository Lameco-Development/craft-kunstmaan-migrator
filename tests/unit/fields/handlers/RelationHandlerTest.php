<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\fields\handlers;

use lameco\kunstmaanmigrator\db\LegacyDbService;
use lameco\kunstmaanmigrator\fields\handlers\RelationHandler;
use lameco\kunstmaanmigrator\fields\ResolverContext;
use lameco\kunstmaanmigrator\finalize\CkeditorRewriterService;
use lameco\kunstmaanmigrator\load\AssetPathResolver;
use lameco\kunstmaanmigrator\load\MigrationStateReader;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Phase 5 / TST-01 / D-10 — direct unit tests for RelationHandler.
 *
 * Strategy: createStub for MigrationStateReader (handlers consume it for
 * legacy-id → migrated-id lookup) and for LegacyDbService (joinTable +
 * joinTranslation paths). Stubs return deterministic values; tests assert
 * the handler's behavior given a stubbed lookup result, not that the lookup
 * itself happens.
 *
 * Coverage target: ≥ 70.0% line coverage on
 * src/fields/handlers/RelationHandler.php (D-08 directory prefix gate).
 */
final class RelationHandlerTest extends TestCase
{
    private function ctx(
        ?MigrationStateReader $state = null,
        ?LegacyDbService $legacyDb = null,
        int $siteId = 1,
    ): ResolverContext {
        return new ResolverContext(
            siteId: $siteId,
            siteHandle: 'default',
            state: $state ?? $this->createStub(MigrationStateReader::class),
            ck: $this->createStub(CkeditorRewriterService::class),
            paths: $this->createStub(AssetPathResolver::class),
            siteMap: ['nl' => 1, 'en' => 2],
            legacyDb: $legacyDb,
        );
    }

    public function testIdReturnsRelation(): void
    {
        self::assertSame('relation', (new RelationHandler())->id());
    }

    public function testMissingStateSourceOptionThrows(): void
    {
        $h = new RelationHandler();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("requires 'stateSource'");
        $h->resolve(42, $this->ctx(), []);
    }

    // ---------- direct path (no joinTable / joinTranslation) ----------

    public function testDirectPathResolvesScalarLegacyIdToTargetId(): void
    {
        $state = $this->createMock(MigrationStateReader::class);
        $state->expects($this->once())
            ->method('getTargetId')
            ->with('news', '7', 1)
            ->willReturn(123);

        $h = new RelationHandler();
        $result = $h->resolve(7, $this->ctx(state: $state), ['stateSource' => 'news']);
        self::assertSame([123], $result);
    }

    public function testDirectPathFallsBackToSiteAgnosticLookupOnSiteScopedMiss(): void
    {
        $state = $this->createMock(MigrationStateReader::class);
        // First call (site-scoped) returns null → second call (siteId=null) returns 88.
        $state->expects($this->exactly(2))
            ->method('getTargetId')
            ->willReturnOnConsecutiveCalls(null, 88);

        $h = new RelationHandler();
        $result = $h->resolve(5, $this->ctx(state: $state), ['stateSource' => 'cases']);
        self::assertSame([88], $result);
    }

    public function testDirectPathDropsUnresolvedIdsSilently(): void
    {
        $state = $this->createStub(MigrationStateReader::class);
        $state->method('getTargetId')->willReturn(null);

        $h = new RelationHandler();
        $result = $h->resolve([1, 2, 3], $this->ctx(state: $state), ['stateSource' => 'news']);
        self::assertSame([], $result);
    }

    public function testDirectPathPreservesInputOrderAndDedupes(): void
    {
        $state = $this->createStub(MigrationStateReader::class);
        $state->method('getTargetId')->willReturnMap([
            // [source, key, siteId, returnValue]
            ['news', '1', 1, 100],
            ['news', '2', 1, 200],
            ['news', '3', 1, 100], // duplicate of the first
            ['news', '1', null, null],
            ['news', '2', null, null],
            ['news', '3', null, null],
        ]);

        $h = new RelationHandler();
        $result = $h->resolve([1, 2, 3], $this->ctx(state: $state), ['stateSource' => 'news']);
        // Dedup keeps first occurrence; insertion order preserved.
        self::assertSame([100, 200], $result);
    }

    public function testDirectPathReturnsEmptyForNullEmptyOrEmptyArrayInput(): void
    {
        $h = new RelationHandler();
        self::assertSame([], $h->resolve(null, $this->ctx(), ['stateSource' => 'news']));
        self::assertSame([], $h->resolve('', $this->ctx(), ['stateSource' => 'news']));
        self::assertSame([], $h->resolve([], $this->ctx(), ['stateSource' => 'news']));
    }

    public function testDirectPathFiltersOutNonPositiveIds(): void
    {
        $state = $this->createMock(MigrationStateReader::class);
        // Only the positive id 5 should reach the lookup. The site-scoped call
        // hits, so the site-agnostic fallback is NOT triggered (the OR-fallback
        // only fires on null). Exactly one call expected.
        $state->expects($this->once())
            ->method('getTargetId')
            ->willReturnCallback(function (string $src, string $key, ?int $siteId) {
                self::assertSame('5', $key);
                self::assertSame(1, $siteId);
                return 555;
            });

        $h = new RelationHandler();
        $result = $h->resolve([0, -3, 5], $this->ctx(state: $state), ['stateSource' => 'news']);
        self::assertSame([555], $result);
    }

    // ---------- joinTable path ----------

    public function testJoinTablePathRequiresJoinLocalAndForeignColumns(): void
    {
        $h = new RelationHandler();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("'joinLocalColumn' is required");
        $h->resolve(1, $this->ctx(legacyDb: $this->createStub(LegacyDbService::class)), [
            'stateSource' => 'news',
            'joinTable' => 'news_tag',
        ]);
    }

    public function testJoinTablePathRejectsInvalidIdentifierCharacters(): void
    {
        $h = new RelationHandler();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('contains invalid characters');
        $h->resolve(1, $this->ctx(legacyDb: $this->createStub(LegacyDbService::class)), [
            'stateSource' => 'news',
            'joinTable' => 'news; DROP TABLE foo',
            'joinLocalColumn' => 'page_id',
            'joinForeignColumn' => 'tag_id',
        ]);
    }

    public function testJoinTablePathExpandsViaQueryAndResolvesEachForeignId(): void
    {
        $legacyDb = $this->createMock(LegacyDbService::class);
        $legacyDb->expects($this->once())
            ->method('queryAll')
            ->with(
                'SELECT tag_id FROM news_tag WHERE page_id = :ref',
                [':ref' => 7],
            )
            ->willReturn([
                ['tag_id' => 11],
                ['tag_id' => 22],
            ]);

        $state = $this->createStub(MigrationStateReader::class);
        $state->method('getTargetId')->willReturnMap([
            ['tags', '11', 1, 1100],
            ['tags', '22', 1, 2200],
        ]);

        $h = new RelationHandler();
        $result = $h->resolve(7, $this->ctx(state: $state, legacyDb: $legacyDb), [
            'stateSource' => 'tags',
            'joinTable' => 'news_tag',
            'joinLocalColumn' => 'page_id',
            'joinForeignColumn' => 'tag_id',
        ]);
        self::assertSame([1100, 2200], $result);
    }

    public function testJoinTablePathEmitsDeferredAssetTokenForMediaSourceMisses(): void
    {
        $legacyDb = $this->createMock(LegacyDbService::class);
        $legacyDb->expects($this->once())
            ->method('queryAll')
            ->willReturn([['media_id' => 99]]);

        $state = $this->createStub(MigrationStateReader::class);
        $state->method('getTargetId')->willReturn(null);

        $h = new RelationHandler();
        $result = $h->resolve(7, $this->ctx(state: $state, legacyDb: $legacyDb), [
            'stateSource' => 'media',
            'stateKeyPrefix' => 'kuma_media:',
            'joinTable' => 'page_media',
            'joinLocalColumn' => 'page_id',
            'joinForeignColumn' => 'media_id',
        ]);
        self::assertSame(['asset:99'], $result);
    }

    public function testJoinTablePathThrowsWhenLegacyDbMissing(): void
    {
        $h = new RelationHandler();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('legacyDb must be non-null');
        $h->resolve(7, $this->ctx(legacyDb: null), [
            'stateSource' => 'tags',
            'joinTable' => 'news_tag',
            'joinLocalColumn' => 'page_id',
            'joinForeignColumn' => 'tag_id',
        ]);
    }

    public function testJoinTablePathReturnsEmptyForNonPositiveRefId(): void
    {
        $legacyDb = $this->createMock(LegacyDbService::class);
        // queryAll must NOT be called when refId <= 0.
        $legacyDb->expects($this->never())->method('queryAll');

        $h = new RelationHandler();
        $result = $h->resolve(0, $this->ctx(legacyDb: $legacyDb), [
            'stateSource' => 'tags',
            'joinTable' => 'news_tag',
            'joinLocalColumn' => 'page_id',
            'joinForeignColumn' => 'tag_id',
        ]);
        self::assertSame([], $result);
    }

    // ---------- joinTranslation path ----------

    public function testJoinTranslationPathThrowsForMalformedOption(): void
    {
        $h = new RelationHandler();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("'joinTranslation' must be an array");
        $h->resolve(1, $this->ctx(legacyDb: $this->createStub(LegacyDbService::class)), [
            'stateSource' => 'employees',
            'joinTranslation' => 'broken',
        ]);
    }

    public function testJoinTranslationPathResolvesViaTranslationLookup(): void
    {
        $legacyDb = $this->createMock(LegacyDbService::class);
        $legacyDb->expects($this->once())
            ->method('queryOne')
            ->with(
                'SELECT employee_id FROM kuma_employee_translations WHERE id = :id LIMIT 1',
                [':id' => 7],
            )
            ->willReturn(['employee_id' => 42]);

        $state = $this->createMock(MigrationStateReader::class);
        $state->expects($this->once())
            ->method('getTargetId')
            ->with('employees', '42', 1)
            ->willReturn(420);

        $h = new RelationHandler();
        $result = $h->resolve(7, $this->ctx(state: $state, legacyDb: $legacyDb), [
            'stateSource' => 'employees',
            'joinTranslation' => [
                'table' => 'kuma_employee_translations',
                'sourceColumn' => 'id',
                'targetColumn' => 'employee_id',
            ],
        ]);
        self::assertSame([420], $result);
    }
}
