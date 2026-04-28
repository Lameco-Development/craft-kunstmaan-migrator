<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\source;

use lameco\kunstmaanmigrator\db\LegacyDbService;
use lameco\kunstmaanmigrator\source\KnowledgeBase;
use PHPUnit\Framework\TestCase;

/**
 * Plan 02.1-03 Task 3 smoke test: the partial KnowledgeBase port has many
 * transitively-reachable private helpers (loadAllColumns, renderTableColumns,
 * fetchSamples, formatSamples, truncateSample, isSafeIdent, plus the
 * CORE_TABLES + IDENT_RX consts). If any helper is missing from the port,
 * one of the two render methods will hit a fatal Error: Call to undefined
 * method KnowledgeBase::xxx() during execution.
 *
 * The test:
 *   - constructs a fake LegacyDbService whose queryAll/queryScalar return
 *     fixed empty/scalar fixtures suitable for the SQL patterns hit by both
 *     render methods,
 *   - calls renderPagesMarkdown() and renderPagePartsMarkdown() against null
 *     mapping + a fixed DateTimeImmutable,
 *   - asserts the returned strings are non-empty and contain the expected
 *     top-level Markdown headers.
 *
 * The fixture is intentionally minimal — discovery queries return empty
 * arrays so the per-page / per-pagepart loops short-circuit (the test caps
 * the dropped-helpers risk at the orchestration layer, not the per-row layer
 * — Phase 5 / TST-02 owns full corpus characterization).
 */
final class KnowledgeBaseSmokeTest extends TestCase
{
    public function testRenderPagesMarkdownReturnsHeaderedString(): void
    {
        $kb = $this->buildKnowledgeBase();

        $markdown = $kb->renderPagesMarkdown(null, new \DateTimeImmutable('2026-01-01T00:00:00Z'));

        self::assertNotSame('', $markdown, 'renderPagesMarkdown must return a non-empty string');
        self::assertStringStartsWith('# Kunstmaan Pages', $markdown, 'renderPagesMarkdown header must be present');
    }

    public function testRenderPagePartsMarkdownReturnsHeaderedString(): void
    {
        $kb = $this->buildKnowledgeBase();

        $markdown = $kb->renderPagePartsMarkdown(null, new \DateTimeImmutable('2026-01-01T00:00:00Z'));

        self::assertNotSame('', $markdown, 'renderPagePartsMarkdown must return a non-empty string');
        self::assertStringStartsWith('# Kunstmaan Page Parts', $markdown, 'renderPagePartsMarkdown header must be present');
    }

    private function buildKnowledgeBase(): KnowledgeBase
    {
        $kb = new KnowledgeBase();
        $kb->legacyDb = new StubLegacyDbService();
        // entityParser intentionally null — both render methods handle a null
        // parser gracefully (fall back to DB column metadata).
        return $kb;
    }
}

/**
 * Test stub: subclasses LegacyDbService and overrides every method KnowledgeBase
 * touches. queryAll/queryScalar return canned shapes; db() is never invoked
 * (KnowledgeBase calls $this->legacyDb->queryScalar('SELECT DATABASE()') after
 * the Plan 02.1-03 Task 3 reshape).
 */
final class StubLegacyDbService extends LegacyDbService
{
    /**
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function queryAll(string $sql, array $params = []): array
    {
        // information_schema.COLUMNS scan in loadAllColumns — return empty so
        // the table column index is empty (per-table loops short-circuit).
        // page_part_refs / kuma_nodes discovery queries — also empty so the
        // outer foreach loops short-circuit, exercising the orchestration
        // path without per-row helpers.
        return [];
    }

    /** @param array<string, mixed> $params */
    public function queryScalar(string $sql, array $params = []): mixed
    {
        // 'SELECT DATABASE()' returns a non-empty string; COUNT(*) queries
        // return 0 (no rows). A fixed scalar covers both cases for the smoke
        // test because the empty queryAll() means no per-row queries fire.
        if (stripos($sql, 'DATABASE()') !== false) {
            return 'test_db';
        }
        return 0;
    }

    /** @param array<string, mixed> $params */
    public function queryOne(string $sql, array $params = []): ?array
    {
        return null;
    }
}
