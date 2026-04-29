<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\source;

use lameco\kunstmaanmigrator\db\LegacyDbService;
use lameco\kunstmaanmigrator\source\DoctrineColumnInfo;
use lameco\kunstmaanmigrator\source\DoctrineEntityInfo;
use lameco\kunstmaanmigrator\source\DoctrineEntityParser;
use lameco\kunstmaanmigrator\source\DoctrineRelationInfo;
use lameco\kunstmaanmigrator\source\KunstmaanKnowledgeBase;
use lameco\kunstmaanmigrator\source\KunstmaanCoreTables;
use PHPUnit\Framework\TestCase;

/**
 * Phase 8.5 / D-20 — verify KunstmaanKnowledgeBase emits the canonical Relations
 * summary table for both pages and pageparts. The table is the single
 * source of truth for the LLM proposer when interpreting `_rel:<prop>.<col>`
 * synthetic columns produced by ExtractService::joinManyToOneRelations
 * (D-21). It must surface property name, relation type, target FQCN, FK
 * column, target table, and target columns inline so no further hops are
 * required.
 */
final class KunstmaanKnowledgeBaseRelationsRenderingTest extends TestCase
{
    private const PAGE_FQCN     = 'App\\Entity\\Pages\\EmployeePage';
    private const PAGE_TABLE    = 'lameco_websitebundle_employee_pages';
    private const TARGET_FQCN   = 'App\\Entity\\Employee';
    private const TARGET_TABLE  = 'lameco_websitebundle_employee_employees';

    public function testRendersRelationsTableForPageEntity(): void
    {
        $kb = $this->buildKbWithEmployeePage();
        $md = $kb->renderPagesMarkdown(null, new \DateTimeImmutable('2026-01-01T00:00:00Z'));

        // Header
        self::assertStringContainsString('### Relations', $md);
        // Table column headers (the canonical six columns)
        self::assertStringContainsString(
            '| property | type | target | FK column | target table | target columns |',
            $md,
        );
        // Row content — substrings spread across cells
        self::assertStringContainsString('| employee | ManyToOne | App\\Entity\\Employee', $md);
        self::assertStringContainsString('`employee_id`', $md);
        self::assertStringContainsString('`' . self::TARGET_TABLE . '`', $md);
        // Target columns rendered inline (operator + LLM both consume this).
        self::assertStringContainsString('id, name, email, job_title', $md);
    }

    public function testRendersRelationsTableForPagePart(): void
    {
        // Reuse the EmployeePage→Employee fixture but in the pageparts
        // discovery path: we need the page-part-refs query to surface the
        // entity FQCN. The KB uses `KunstmaanCoreTables::PAGE_PART_REFS` for
        // discovery — the stub returns it as a fake "page part" so the
        // existing render path picks up the relations rendering.
        $kb = $this->buildKbWithFakePagePart();
        $md = $kb->renderPagePartsMarkdown(null, new \DateTimeImmutable('2026-01-01T00:00:00Z'));

        self::assertStringContainsString('### Relations', $md);
        self::assertStringContainsString(
            '| property | type | target | FK column | target table | target columns |',
            $md,
        );
        self::assertStringContainsString('| employee | ManyToOne | App\\Entity\\Employee', $md);
        self::assertStringContainsString('id, name, email, job_title', $md);
    }

    public function testOmitsTableWhenNoManyToOneOrManyToManyRelations(): void
    {
        // Page with only a OneToMany relation — the table filter must drop it.
        $employeeInfo = new DoctrineEntityInfo(
            self::TARGET_FQCN,
            self::TARGET_TABLE,
            [new DoctrineColumnInfo('id', 'integer', false, 'id', false)],
            [],
        );
        $pageInfo = new DoctrineEntityInfo(
            self::PAGE_FQCN,
            self::PAGE_TABLE,
            [],
            [
                new DoctrineRelationInfo('OneToMany', self::TARGET_FQCN, 'employees', null),
            ],
        );

        $kb = $this->buildKb(
            byFqcn: [
                self::PAGE_FQCN   => $pageInfo,
                self::TARGET_FQCN => $employeeInfo,
            ],
            db: new RelationsRenderingDbStub(['kuma_nodes_discover' => [
                ['ref_entity_name' => self::PAGE_FQCN, 'node_count' => 1],
            ]]),
        );
        $md = $kb->renderPagesMarkdown(null, new \DateTimeImmutable('2026-01-01T00:00:00Z'));

        // ManyToOne / ManyToMany filter — OneToMany alone produces no table.
        self::assertStringNotContainsString(
            '| property | type | target | FK column | target table | target columns |',
            $md,
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildKbWithEmployeePage(): KunstmaanKnowledgeBase
    {
        return $this->buildKb(
            byFqcn: $this->buildEmployeePageFixture(),
            db: new RelationsRenderingDbStub([
                'kuma_nodes_discover' => [['ref_entity_name' => self::PAGE_FQCN, 'node_count' => 1]],
            ]),
        );
    }

    private function buildKbWithFakePagePart(): KunstmaanKnowledgeBase
    {
        return $this->buildKb(
            byFqcn: $this->buildEmployeePageFixture(),
            db: new RelationsRenderingDbStub([
                'page_part_refs_distinct' => [
                    ['page_part_entityname' => self::PAGE_FQCN],
                ],
                'page_part_refs_pages' => [
                    ['pageEntityname' => self::PAGE_FQCN, 'page_part_entityname' => self::PAGE_FQCN],
                ],
            ]),
        );
    }

    /** @return array<string, DoctrineEntityInfo> */
    private function buildEmployeePageFixture(): array
    {
        $employeeInfo = new DoctrineEntityInfo(
            self::TARGET_FQCN,
            self::TARGET_TABLE,
            [
                new DoctrineColumnInfo('id', 'integer', false, 'id', false),
                new DoctrineColumnInfo('name', 'string', true, 'name', false),
                new DoctrineColumnInfo('email', 'string', true, 'email', false),
                new DoctrineColumnInfo('job_title', 'string', true, 'jobTitle', false),
            ],
            [],
        );
        $pageInfo = new DoctrineEntityInfo(
            self::PAGE_FQCN,
            self::PAGE_TABLE,
            [
                new DoctrineColumnInfo('id', 'integer', false, 'id', false),
                new DoctrineColumnInfo('employee_id', 'integer', true, 'employee', false),
            ],
            [
                new DoctrineRelationInfo('ManyToOne', self::TARGET_FQCN, 'employee', 'employee_id'),
            ],
        );
        return [
            self::PAGE_FQCN   => $pageInfo,
            self::TARGET_FQCN => $employeeInfo,
        ];
    }

    /**
     * @param array<string, DoctrineEntityInfo> $byFqcn
     */
    private function buildKb(array $byFqcn, RelationsRenderingDbStub $db): KunstmaanKnowledgeBase
    {
        $parser = new DoctrineEntityParser();
        $rc = new \ReflectionClass(DoctrineEntityParser::class);
        $rc->getProperty('byFqcn')->setValue($parser, $byFqcn);
        $byTableMap = [];
        foreach ($byFqcn as $info) {
            if ($info->tableName !== '') {
                $byTableMap[$info->tableName] = $info;
            }
        }
        $rc->getProperty('byTable')->setValue($parser, $byTableMap);

        $kb = new KunstmaanKnowledgeBase();
        $kb->legacyDb = $db;
        $kb->entityParser = $parser;
        return $kb;
    }
}

/**
 * LegacyDbService stub: pattern-matches incoming SQL to dispatch the right
 * fixture rows for the KB render queries we care about. Anything we don't
 * model returns empty so the loops short-circuit gracefully.
 */
final class RelationsRenderingDbStub extends LegacyDbService
{
    /**
     * @param array<string, list<array<string, mixed>>> $fixtures
     *        keyed by symbolic name (kuma_nodes_discover, page_part_refs_distinct, page_part_refs_pages)
     */
    public function __construct(private readonly array $fixtures)
    {
        // skip parent ctor — no real connection
    }

    public function queryAll(string $sql, array $params = []): array
    {
        // kuma_nodes discovery (renderPagesMarkdown)
        if (str_contains($sql, KunstmaanCoreTables::NODES) && str_contains($sql, 'GROUP BY ref_entity_name')) {
            return $this->fixtures['kuma_nodes_discover'] ?? [];
        }
        // page-part refs reverse index (renderPagePartsMarkdown line 97-101)
        if (str_contains($sql, KunstmaanCoreTables::PAGE_PART_REFS) && str_contains($sql, 'DISTINCT pageEntityname')) {
            return $this->fixtures['page_part_refs_pages'] ?? [];
        }
        // page-part FQCN discovery
        if (str_contains($sql, KunstmaanCoreTables::PAGE_PART_REFS) && str_contains($sql, 'DISTINCT page_part_entityname')) {
            return $this->fixtures['page_part_refs_distinct'] ?? [];
        }
        // information_schema column index — return empty so per-row queries
        // short-circuit (we don't need fill rates / samples for the
        // Relations-rendering assertions).
        return [];
    }

    public function queryScalar(string $sql, array $params = []): mixed
    {
        if (stripos($sql, 'DATABASE()') !== false) {
            return 'test_db';
        }
        return 0;
    }

    public function queryOne(string $sql, array $params = []): ?array
    {
        return null;
    }
}
