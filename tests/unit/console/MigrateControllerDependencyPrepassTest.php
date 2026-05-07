<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\console;

use lameco\kunstmaanmigrator\console\MigrateController;
use lameco\kunstmaanmigrator\load\MigrationReport;
use lameco\kunstmaanmigrator\workflow\MigrateWorkflow;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class MigrateControllerDependencyPrepassTest extends TestCase
{
    private static function source(): string
    {
        return (string) file_get_contents(
            (new ReflectionClass(MigrateWorkflow::class))->getFileName(),
        );
    }

    public function testDependencyPrepassRunsBetweenExtractAndTransform(): void
    {
        $source = self::source();

        self::assertGreaterThan(
            strpos($source, 'extractService->run('),
            strpos($source, 'migrateMissingPageDependencies('),
            'Referenced page dependencies must be migrated after owner extraction.',
        );
        self::assertLessThan(
            strpos($source, 'transformService->run($extractedStream'),
            strpos($source, 'migrateMissingPageDependencies('),
            'Referenced page dependencies must be migrated before owner transform resolves relation fields.',
        );
    }

    public function testDependencyPrepassUsesRelationHandlerStateKeySemantics(): void
    {
        $source = self::source();

        self::assertStringContainsString('joinTranslation', $source);
        self::assertStringContainsString('joinTable', $source);
        self::assertStringContainsString('migrationStateService->getTargetId($stateSource, (string) $stateKey)', $source);
        self::assertStringContainsString('nodeIdForPageRef($targetFqcn, $stateKey)', $source);
    }

    public function testDirectRelationValuesNormalizeToUniquePositiveStateKeys(): void
    {
        $controller = (new ReflectionClass(MigrateController::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(MigrateController::class, 'dependencyStateKeysForRelationValue');
        $method->setAccessible(true);

        self::assertSame(
            [12, 14],
            $method->invoke($controller, ['12', 0, '', 12, 14], [], new MigrationReport()),
        );
    }

    public function testInvalidJoinIdentifiersAreRejectedBeforeQuerying(): void
    {
        $controller = (new ReflectionClass(MigrateController::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(MigrateController::class, 'dependencyStateKeysForRelationValue');
        $method->setAccessible(true);
        $report = new MigrationReport();

        self::assertSame(
            [],
            $method->invoke($controller, 43, [
                'joinTranslation' => [
                    'table' => 'safe_table;DROP',
                    'sourceColumn' => 'employee_id',
                    'targetColumn' => 'id',
                ],
            ], $report),
        );
        self::assertNotEmpty($report->warnings);
    }
}
