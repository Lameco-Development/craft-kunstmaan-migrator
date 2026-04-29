<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\extract;

use lameco\kunstmaanmigrator\db\LegacyDbService;
use lameco\kunstmaanmigrator\extract\ExtractService;
use lameco\kunstmaanmigrator\source\DoctrineColumnInfo;
use lameco\kunstmaanmigrator\source\DoctrineEntityInfo;
use lameco\kunstmaanmigrator\source\DoctrineEntityParser;
use lameco\kunstmaanmigrator\source\DoctrineRelationInfo;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Phase 8.5 / D-21 — verify ExtractService::joinManyToOneRelations embeds
 * each ManyToOne related row's columns under `_rel:<property>.<column>`
 * keys, gates on Settings::joinFkRelations, and degrades safely when the
 * parser/target table is missing or the FK value is null.
 *
 * Tests target the private helper directly via Reflection so the
 * fixture surface stays tight (no Craft bootstrap, no real DB). The
 * stubs subclass LegacyDbService + DoctrineEntityParser to bypass the
 * lazy warming + connection bootstrap their parent classes do.
 */
final class ExtractServiceFkJoinTest extends TestCase
{
    /** Synthetic CQM-shaped fixture (EmployeePage→Employee via employee_id FK). */
    public const PAGE_FQCN     = 'App\\Entity\\Pages\\EmployeePage';
    public const TARGET_FQCN   = 'App\\Entity\\Employee';
    public const TARGET_TABLE  = 'lameco_websitebundle_employee_employees';

    public function testEmbedsRelKeysWhenFkValuePresent(): void
    {
        $svc = $this->buildService(
            joinFlag: true,
            relatedRow: [
                'id'        => 12,
                'name'      => 'Jan van Doremalen',
                'email'     => 'jvd@cqm.nl',
                'job_title' => 'Senior Engineer',
            ],
        );

        $row = ['id' => 28, 'employee_id' => 12, 'title' => 'Jan'];
        $result = $this->invokeJoin($svc, self::PAGE_FQCN, $row);

        // FK column itself is preserved (operator can still map it directly).
        self::assertSame(12, $result['employee_id']);
        // Related columns are surfaced under the `_rel:employee.` namespace.
        self::assertSame(12, $result['_rel:employee.id']);
        self::assertSame('Jan van Doremalen', $result['_rel:employee.name']);
        self::assertSame('jvd@cqm.nl', $result['_rel:employee.email']);
        self::assertSame('Senior Engineer', $result['_rel:employee.job_title']);
    }

    public function testRespectsJoinFkRelationsFlagOff(): void
    {
        $svc = $this->buildService(
            joinFlag: false,
            relatedRow: ['id' => 12, 'name' => 'Jan'],
        );

        $row = ['id' => 28, 'employee_id' => 12];
        $result = $this->invokeJoin($svc, self::PAGE_FQCN, $row);

        // Flag off — row passes through untouched.
        self::assertSame(['id' => 28, 'employee_id' => 12], $result);
    }

    public function testSkipsWhenFkValueIsNull(): void
    {
        $svc = $this->buildService(
            joinFlag: true,
            relatedRow: ['id' => 12, 'name' => 'Jan'],
        );

        $row = ['id' => 28, 'employee_id' => null];
        $result = $this->invokeJoin($svc, self::PAGE_FQCN, $row);

        self::assertArrayNotHasKey('_rel:employee.name', $result);
    }

    public function testSkipsWhenFkValueIsZero(): void
    {
        $svc = $this->buildService(
            joinFlag: true,
            relatedRow: ['id' => 12, 'name' => 'Jan'],
        );

        $row = ['id' => 28, 'employee_id' => 0];
        $result = $this->invokeJoin($svc, self::PAGE_FQCN, $row);

        self::assertArrayNotHasKey('_rel:employee.name', $result);
    }

    public function testSkipsWhenRelatedRowNotFound(): void
    {
        $svc = $this->buildService(
            joinFlag: true,
            relatedRow: null, // queryOne returns null
        );

        $row = ['id' => 28, 'employee_id' => 999];
        $result = $this->invokeJoin($svc, self::PAGE_FQCN, $row);

        self::assertArrayNotHasKey('_rel:employee.name', $result);
        // FK column remains intact regardless.
        self::assertSame(999, $result['employee_id']);
    }

    public function testNoOpWhenParserMissing(): void
    {
        $svc = new ExtractService();
        $svc->joinFkRelations = true;
        $svc->entityParser = null;

        $row = ['id' => 28, 'employee_id' => 12];
        $result = $this->invokeJoin($svc, self::PAGE_FQCN, $row);

        self::assertSame($row, $result);
    }

    public function testLoadDetailRowDoesNotDuplicateDoctrineRelationWithTableAlias(): void
    {
        $svc = $this->buildServiceWithDb(
            joinFlag: true,
            db: new FkJoinAndInformationSchemaDbStub(
                detailRow: ['id' => 78, 'employee_id' => 31, 'title' => 'News'],
                relatedRow: [
                    'id' => 31,
                    'name' => 'Bram Kranenburg',
                    'email' => 'kranenburg@cqm.nl',
                ],
            ),
        );

        $result = $this->invokeLoadDetailRow($svc, 'lameco_websitebundle_newspages', 78, self::PAGE_FQCN);

        self::assertSame(31, $result['employee_id']);
        self::assertSame(31, $result['_rel:employee.id']);
        self::assertSame('Bram Kranenburg', $result['_rel:employee.name']);
        self::assertArrayNotHasKey('employee_employees.id', $result);
        self::assertArrayNotHasKey('employee_employees.name', $result);
    }

    /**
     * D-21 invariant: the existing key is preserved on collision (the join
     * never overwrites a column that already exists in the row). This guards
     * against unexpected key clashes — extract-level data never silently
     * disappears.
     */
    public function testDoesNotOverwriteExistingKeyOnCollision(): void
    {
        $svc = $this->buildService(
            joinFlag: true,
            relatedRow: ['id' => 12, 'name' => 'Jan'],
        );

        $row = [
            'id' => 28,
            'employee_id' => 12,
            '_rel:employee.name' => 'PREEXISTING', // operator-set or earlier merge
        ];
        $result = $this->invokeJoin($svc, self::PAGE_FQCN, $row);

        self::assertSame('PREEXISTING', $result['_rel:employee.name']);
        // Other related columns still merged.
        self::assertSame(12, $result['_rel:employee.id']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed>|null $relatedRow row returned by the legacy DB stub
     */
    private function buildService(bool $joinFlag, ?array $relatedRow): ExtractService
    {
        $employeeInfo = new DoctrineEntityInfo(
            fqcn: self::TARGET_FQCN,
            tableName: self::TARGET_TABLE,
            columns: [
                new DoctrineColumnInfo('id', 'integer', false, 'id', false),
                new DoctrineColumnInfo('name', 'string', true, 'name', false),
                new DoctrineColumnInfo('email', 'string', true, 'email', false),
                new DoctrineColumnInfo('job_title', 'string', true, 'jobTitle', false),
            ],
            relations: [],
        );
        $pageInfo = new DoctrineEntityInfo(
            fqcn: self::PAGE_FQCN,
            tableName: 'lameco_websitebundle_employee_pages',
            columns: [],
            relations: [
                new DoctrineRelationInfo(
                    relationType: 'ManyToOne',
                    targetEntity: self::TARGET_FQCN,
                    propertyName: 'employee',
                    fkColumn: 'employee_id',
                ),
            ],
        );

        $parser = $this->buildParser([
            self::PAGE_FQCN   => $pageInfo,
            self::TARGET_FQCN => $employeeInfo,
        ]);

        return $this->buildServiceWithDb($joinFlag, new FkJoinDbStub($relatedRow));
    }

    private function buildServiceWithDb(bool $joinFlag, LegacyDbService $db): ExtractService
    {
        $employeeInfo = new DoctrineEntityInfo(
            fqcn: self::TARGET_FQCN,
            tableName: self::TARGET_TABLE,
            columns: [
                new DoctrineColumnInfo('id', 'integer', false, 'id', false),
                new DoctrineColumnInfo('name', 'string', true, 'name', false),
                new DoctrineColumnInfo('email', 'string', true, 'email', false),
                new DoctrineColumnInfo('job_title', 'string', true, 'jobTitle', false),
            ],
            relations: [],
        );
        $pageInfo = new DoctrineEntityInfo(
            fqcn: self::PAGE_FQCN,
            tableName: 'lameco_websitebundle_employee_pages',
            columns: [],
            relations: [
                new DoctrineRelationInfo(
                    relationType: 'ManyToOne',
                    targetEntity: self::TARGET_FQCN,
                    propertyName: 'employee',
                    fkColumn: 'employee_id',
                ),
            ],
        );

        $parser = $this->buildParser([
            self::PAGE_FQCN   => $pageInfo,
            self::TARGET_FQCN => $employeeInfo,
        ]);

        $svc = new ExtractService();
        $svc->legacyDb = $db;
        $svc->entityParser = $parser;
        $svc->joinFkRelations = $joinFlag;

        return $svc;
    }

    /**
     * Seed a real (final) DoctrineEntityParser instance with our fixture
     * entries via Reflection. This bypasses the parser's lazy filesystem
     * warm path while keeping the public surface (getByFqcn / getByTable)
     * fully functional.
     *
     * @param  array<string, DoctrineEntityInfo> $byFqcn
     */
    private function buildParser(array $byFqcn): DoctrineEntityParser
    {
        $parser = new DoctrineEntityParser();
        $rc = new \ReflectionClass(DoctrineEntityParser::class);

        $byFqcnProp = $rc->getProperty('byFqcn');
        $byFqcnProp->setValue($parser, $byFqcn);

        $byTableMap = [];
        foreach ($byFqcn as $info) {
            if ($info->tableName !== '') {
                $byTableMap[$info->tableName] = $info;
            }
        }
        $byTableProp = $rc->getProperty('byTable');
        $byTableProp->setValue($parser, $byTableMap);

        return $parser;
    }

    /**
     * @param  array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function invokeJoin(ExtractService $svc, string $fqcn, array $row): array
    {
        $m = new ReflectionMethod(ExtractService::class, 'joinManyToOneRelations');
        /** @var array<string, mixed> $result */
        $result = $m->invoke($svc, $fqcn, $row);
        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function invokeLoadDetailRow(ExtractService $svc, string $table, int $refId, string $fqcn): array
    {
        $m = new ReflectionMethod(ExtractService::class, 'loadDetailRow');
        /** @var array<string, mixed> $result */
        $result = $m->invoke($svc, $table, $refId, $fqcn);
        return $result;
    }
}

/**
 * Minimal LegacyDbService stub: returns the seeded related row from
 * queryOne(...) regardless of arguments. queryAll/queryScalar return
 * empty/zero so any unforeseen call path degrades safely.
 */
final class FkJoinDbStub extends LegacyDbService
{
    /** @param array<string, mixed>|null $relatedRow */
    public function __construct(private readonly ?array $relatedRow)
    {
        // Skip parent constructor — we never open a real connection.
    }

    public function queryOne(string $sql, array $params = []): ?array
    {
        return $this->relatedRow;
    }

    /** @return array<int, array<string, mixed>> */
    public function queryAll(string $sql, array $params = []): array
    {
        return [];
    }

    public function queryScalar(string $sql, array $params = []): mixed
    {
        return 0;
    }
}

final class FkJoinAndInformationSchemaDbStub extends LegacyDbService
{
    /**
     * @param array<string, mixed> $detailRow
     * @param array<string, mixed> $relatedRow
     */
    public function __construct(
        private readonly array $detailRow,
        private readonly array $relatedRow,
    ) {
    }

    public function queryOne(string $sql, array $params = []): ?array
    {
        if (str_contains($sql, '`lameco_websitebundle_newspages`')) {
            return $this->detailRow;
        }

        if (str_contains($sql, '`' . ExtractServiceFkJoinTest::TARGET_TABLE . '`')) {
            return $this->relatedRow;
        }

        return null;
    }

    /** @return array<int, array<string, mixed>> */
    public function queryAll(string $sql, array $params = []): array
    {
        if (str_contains($sql, 'information_schema.KEY_COLUMN_USAGE')) {
            return [[
                'COLUMN_NAME' => 'employee_id',
                'REFERENCED_TABLE_NAME' => ExtractServiceFkJoinTest::TARGET_TABLE,
                'REFERENCED_COLUMN_NAME' => 'id',
            ]];
        }

        return [];
    }

    public function queryScalar(string $sql, array $params = []): mixed
    {
        return 'legacy';
    }

    public function getDatabaseName(): string
    {
        return 'legacy';
    }
}
