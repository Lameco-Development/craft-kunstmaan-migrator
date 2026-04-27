<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\fields\handlers;

use Generator;
use lameco\kunstmaanmigrator\db\LegacyDbService;
use lameco\kunstmaanmigrator\fields\handlers\MatrixHandler;
use lameco\kunstmaanmigrator\fields\ResolverContext;
use lameco\kunstmaanmigrator\finalize\CkeditorRewriterService;
use lameco\kunstmaanmigrator\load\AssetPathResolver;
use lameco\kunstmaanmigrator\load\MigrationStateReader;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Phase 5 / TST-01 / D-10 — direct unit tests for MatrixHandler.
 *
 * Two dispatch paths:
 *   (a) generic path  — streams from a sibling table via LegacyDbService;
 *   (b) page-part path — receives pre-resolved row hashes from TransformService.
 *
 * Strategy: createStub for LegacyDbService (generic path) and CkeditorRewriter
 * (bodyCol path). Page-part path is pure — no I/O, just shape-wrap.
 *
 * Coverage target: ≥ 70.0% line coverage on
 * src/fields/handlers/MatrixHandler.php (D-08 directory prefix gate).
 */
final class MatrixHandlerTest extends TestCase
{
    private function ctx(
        ?LegacyDbService $legacyDb = null,
        ?CkeditorRewriterService $ck = null,
    ): ResolverContext {
        return new ResolverContext(
            siteId: 1,
            siteHandle: 'default',
            state: $this->createStub(MigrationStateReader::class),
            ck: $ck ?? $this->createStub(CkeditorRewriterService::class),
            paths: $this->createStub(AssetPathResolver::class),
            siteMap: ['nl' => 1],
            legacyDb: $legacyDb,
        );
    }

    /**
     * Build a Generator from a list of rows — matches LegacyDbService::streamQuery shape.
     *
     * @param list<array<string, mixed>> $rows
     */
    private function rowStream(array $rows): Generator
    {
        foreach ($rows as $r) {
            yield $r;
        }
    }

    public function testIdReturnsMatrix(): void
    {
        self::assertSame('matrix', (new MatrixHandler())->id());
    }

    // ---------- generic path ----------

    public function testGenericPathThrowsWhenLegacyDbMissing(): void
    {
        $h = new MatrixHandler();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('legacyDb to be non-null');
        $h->resolve(7, $this->ctx(legacyDb: null), [
            'itemTable' => 'items',
            'fkCol' => 'parent_id',
            'blockType' => 'item',
            'valueCol' => 'value',
        ]);
    }

    public function testGenericPathRequiresItemTableFkColAndBlockType(): void
    {
        $h = new MatrixHandler();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("requires 'itemTable' option");
        $h->resolve(7, $this->ctx(legacyDb: $this->createStub(LegacyDbService::class)), [
            'fkCol' => 'parent_id',
            'blockType' => 'item',
            'valueCol' => 'value',
        ]);
    }

    public function testGenericPathRequiresValueColOrBodyCol(): void
    {
        $h = new MatrixHandler();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("requires one of 'valueCol' or 'bodyCol'");
        $h->resolve(7, $this->ctx(legacyDb: $this->createStub(LegacyDbService::class)), [
            'itemTable' => 'items',
            'fkCol' => 'parent_id',
            'blockType' => 'item',
        ]);
    }

    public function testGenericPathReturnsEmptyForNonPositiveFkValue(): void
    {
        $legacyDb = $this->createMock(LegacyDbService::class);
        $legacyDb->expects($this->never())->method('streamQuery');

        $h = new MatrixHandler();
        $result = $h->resolve(0, $this->ctx(legacyDb: $legacyDb), [
            'itemTable' => 'items',
            'fkCol' => 'parent_id',
            'blockType' => 'item',
            'valueCol' => 'value',
        ]);
        self::assertSame([], $result);
    }

    public function testGenericPathEmitsOneBlockPerRowKeyedNew1New2(): void
    {
        $legacyDb = $this->createMock(LegacyDbService::class);
        $legacyDb->expects($this->once())
            ->method('streamQuery')
            ->with(
                'SELECT * FROM lameco_items WHERE parent_id = :fk ORDER BY id',
                [':fk' => 7],
            )
            ->willReturn($this->rowStream([
                ['id' => 1, 'value' => 'first'],
                ['id' => 2, 'value' => 'second'],
            ]));

        $h = new MatrixHandler();
        $result = $h->resolve(7, $this->ctx(legacyDb: $legacyDb), [
            'itemTable' => 'lameco_items',
            'fkCol' => 'parent_id',
            'blockType' => 'genericItem',
            'valueCol' => 'value',
        ]);

        self::assertSame(['new1', 'new2'], array_keys($result));
        self::assertSame('genericItem', $result['new1']['type']);
        self::assertTrue($result['new1']['enabled']);
        self::assertSame(['value' => 'first'], $result['new1']['fields']);
        self::assertSame(['value' => 'second'], $result['new2']['fields']);
    }

    public function testGenericPathRoutesBodyColThroughCkeditorRewriter(): void
    {
        $ck = $this->createMock(CkeditorRewriterService::class);
        $ck->expects($this->once())
            ->method('rewrite')
            ->with('<p>raw</p>', 1)
            ->willReturn('<p>rewritten</p>');

        $legacyDb = $this->createMock(LegacyDbService::class);
        $legacyDb->method('streamQuery')->willReturn($this->rowStream([
            ['body' => '<p>raw</p>'],
        ]));

        $h = new MatrixHandler();
        $result = $h->resolve(7, $this->ctx(legacyDb: $legacyDb, ck: $ck), [
            'itemTable' => 'lameco_text',
            'fkCol' => 'parent_id',
            'blockType' => 'textBlock',
            'bodyCol' => 'body',
        ]);

        self::assertSame('<p>rewritten</p>', $result['new1']['fields']['value']);
    }

    public function testGenericPathBodyColReturnsEmptyStringForBlankRowValue(): void
    {
        $ck = $this->createMock(CkeditorRewriterService::class);
        $ck->expects($this->never())->method('rewrite');

        $legacyDb = $this->createMock(LegacyDbService::class);
        $legacyDb->method('streamQuery')->willReturn($this->rowStream([
            ['body' => ''],
        ]));

        $h = new MatrixHandler();
        $result = $h->resolve(7, $this->ctx(legacyDb: $legacyDb, ck: $ck), [
            'itemTable' => 'items',
            'fkCol' => 'parent_id',
            'blockType' => 'textBlock',
            'bodyCol' => 'body',
        ]);
        self::assertSame('', $result['new1']['fields']['value']);
    }

    public function testGenericPathOrderColsBuildsMultiColumnOrderBy(): void
    {
        $legacyDb = $this->createMock(LegacyDbService::class);
        $legacyDb->expects($this->once())
            ->method('streamQuery')
            ->with(
                'SELECT * FROM items WHERE parent_id = :fk ORDER BY weight, id',
                [':fk' => 7],
            )
            ->willReturn($this->rowStream([]));

        $h = new MatrixHandler();
        $h->resolve(7, $this->ctx(legacyDb: $legacyDb), [
            'itemTable' => 'items',
            'fkCol' => 'parent_id',
            'blockType' => 'item',
            'valueCol' => 'value',
            'orderCols' => ['weight', 'id'],
        ]);
    }

    public function testGenericPathHandleOptionRenamesTargetField(): void
    {
        $legacyDb = $this->createMock(LegacyDbService::class);
        $legacyDb->method('streamQuery')->willReturn($this->rowStream([
            ['payload' => 'X'],
        ]));

        $h = new MatrixHandler();
        $result = $h->resolve(7, $this->ctx(legacyDb: $legacyDb), [
            'itemTable' => 'items',
            'fkCol' => 'parent_id',
            'blockType' => 'item',
            'valueCol' => 'payload',
            'handle' => 'customField',
        ]);
        self::assertSame(['customField' => 'X'], $result['new1']['fields']);
    }

    public function testGenericPathReturnsEmptyArrayWhenStreamYieldsNoRows(): void
    {
        $legacyDb = $this->createMock(LegacyDbService::class);
        $legacyDb->method('streamQuery')->willReturn($this->rowStream([]));

        $h = new MatrixHandler();
        $result = $h->resolve(7, $this->ctx(legacyDb: $legacyDb), [
            'itemTable' => 'items',
            'fkCol' => 'parent_id',
            'blockType' => 'item',
            'valueCol' => 'value',
        ]);
        self::assertSame([], $result);
    }

    // ---------- page-part path ----------

    public function testPagePartPathRequiresAllTupleOptions(): void
    {
        $h = new MatrixHandler();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("(page-part path) requires 'parentPageClass'");
        $h->resolve([], $this->ctx(), [
            'pagePartClass' => 'App\\Entity\\PageParts\\TextPagePart',
            // missing parentPageClass + others
        ]);
    }

    public function testPagePartPathRequiresFieldsArrayOption(): void
    {
        $h = new MatrixHandler();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("(page-part path) requires 'fields'");
        $h->resolve([], $this->ctx(), [
            'pagePartClass' => 'App\\Entity\\PageParts\\TextPagePart',
            'parentPageClass' => 'App\\Entity\\Pages\\NewsPage',
            'context' => 'main',
            'targetMatrixField' => 'pageBuilder',
            'targetBlockType' => 'textBlock',
        ]);
    }

    public function testPagePartPathReturnsEmptyForNullEmptyOrEmptyArrayInput(): void
    {
        $h = new MatrixHandler();
        $opts = [
            'pagePartClass' => 'App\\Entity\\PageParts\\TextPagePart',
            'parentPageClass' => 'App\\Entity\\Pages\\NewsPage',
            'context' => 'main',
            'targetMatrixField' => 'pageBuilder',
            'targetBlockType' => 'textBlock',
            'fields' => [],
        ];
        self::assertSame([], $h->resolve(null, $this->ctx(), $opts));
        self::assertSame([], $h->resolve('', $this->ctx(), $opts));
        self::assertSame([], $h->resolve([], $this->ctx(), $opts));
    }

    public function testPagePartPathWrapsPreResolvedRowsInNewBlockShape(): void
    {
        $h = new MatrixHandler();
        $opts = [
            'pagePartClass' => 'App\\Entity\\PageParts\\TextPagePart',
            'parentPageClass' => 'App\\Entity\\Pages\\NewsPage',
            'context' => 'main',
            'targetMatrixField' => 'pageBuilder',
            'targetBlockType' => 'textBlock',
            'fields' => [],
        ];
        $rows = [
            ['fields' => ['heading' => 'Hello']],
            ['fields' => ['heading' => 'World']],
        ];
        $result = $h->resolve($rows, $this->ctx(), $opts);

        self::assertSame(['new1', 'new2'], array_keys($result));
        self::assertSame('textBlock', $result['new1']['type']);
        self::assertTrue($result['new1']['enabled']);
        self::assertSame(['heading' => 'Hello'], $result['new1']['fields']);
        self::assertSame(['heading' => 'World'], $result['new2']['fields']);
    }

    public function testPagePartPathThrowsForMalformedRowShape(): void
    {
        $h = new MatrixHandler();
        $opts = [
            'pagePartClass' => 'App\\Entity\\PageParts\\TextPagePart',
            'parentPageClass' => 'App\\Entity\\Pages\\NewsPage',
            'context' => 'main',
            'targetMatrixField' => 'pageBuilder',
            'targetBlockType' => 'textBlock',
            'fields' => [],
        ];
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('array{fields: array');
        $h->resolve([['no_fields_key' => true]], $this->ctx(), $opts);
    }
}
