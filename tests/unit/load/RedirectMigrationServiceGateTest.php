<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\load;

use lameco\kunstmaanmigrator\db\LegacyDbService;
use lameco\kunstmaanmigrator\load\RedirectMigrationService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Phase 4.1 / Plan 04.1-05 / Task 1 — characterization tests for the
 * Settings-disabled gate at the head of RedirectMigrationService::migrateAll().
 *
 * Mirrors SeoMigrationServiceGateTest. Locks the D-27 distinct-warn-copy
 * invariant on the testable static helper.
 */
final class RedirectMigrationServiceGateTest extends TestCase
{
    public function testDisabledWarnLineCopyMatchesD27(): void
    {
        $rm = new ReflectionMethod(RedirectMigrationService::class, 'disabledWarnLine');
        self::assertSame(
            'Retour adapter disabled (explicitly via Settings::retourEnabled); redirect migration skipped.',
            $rm->invoke(null),
        );
    }

    public function testDisabledWarnLineIsDistinctFromPluginAbsentCopy(): void
    {
        $rm = new ReflectionMethod(RedirectMigrationService::class, 'disabledWarnLine');
        $line = (string) $rm->invoke(null);
        self::assertStringContainsString('explicitly via Settings::retourEnabled', $line);
        // The plugin-not-installed copy says "Retour plugin not installed; redirect migration skipped."
        self::assertStringNotContainsString('plugin not installed', $line);
        self::assertStringNotContainsString('plugin not loaded', $line);
    }

    public function testDisabledWarnLineIsRecognisableForSkippedStagesAggregation(): void
    {
        $rm = new ReflectionMethod(RedirectMigrationService::class, 'disabledWarnLine');
        $line = (string) $rm->invoke(null);
        self::assertStringContainsString('Retour adapter disabled', $line);
    }

    public function testLegacyLocalePrefixParsingUsesConfiguredSitesMap(): void
    {
        $service = new RedirectMigrationService();
        $service->sites = ['fr' => 'default', 'de' => 'de'];

        $rm = new ReflectionMethod(RedirectMigrationService::class, 'stripLegacyLocalePrefix');
        self::assertSame(['jobs/senior-consultant', 'fr'], $rm->invoke($service, '/fr/jobs/senior-consultant'));
        self::assertSame(['over-cqm/jobs', null], $rm->invoke($service, '/over-cqm/jobs'));
    }

    public function testLegacyNodeLookupUsesLocaleMapInsteadOfHardcodedNlEnJoins(): void
    {
        $db = new class extends LegacyDbService {
            public string $sql = '';

            /** @var array<string, mixed> */
            public array $params = [];

            public function queryOne(string $sql, array $params = []): ?array
            {
                $this->sql = $sql;
                $this->params = $params;
                return ['kuma_node_id' => 123, 'class' => 'App\\Entity\\Pages\\ArticlePage'];
            }
        };

        $service = new RedirectMigrationService();
        $service->legacyDb = $db;
        $service->sites = ['fr' => 'default', 'de' => 'de'];

        $rm = new ReflectionMethod(RedirectMigrationService::class, 'legacyNodeRowForUrl');
        $row = $rm->invoke($service, 'actualites/example', null);

        self::assertSame(['kuma_node_id' => 123, 'class' => 'App\\Entity\\Pages\\ArticlePage'], $row);
        self::assertSame('fr', $db->params[':locale0'] ?? null);
        self::assertSame('de', $db->params[':locale1'] ?? null);
        self::assertStringContainsString('nt.lang IN (:locale0, :locale1)', $db->sql);
        self::assertStringNotContainsString('nt_nl', $db->sql);
        self::assertStringNotContainsString(':langNl', $db->sql);
        self::assertStringNotContainsString(':langEn', $db->sql);
    }

    public function testRedirectServiceNoLongerHardcodesEmployeePageWrapper(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/src/load/RedirectMigrationService.php');
        self::assertIsString($source);
        self::assertStringNotContainsString('App\\\\Entity\\\\Pages\\\\EmployeePage', $source);
        self::assertStringNotContainsString('kumaNodeIdForEmployee', $source);
    }
}
