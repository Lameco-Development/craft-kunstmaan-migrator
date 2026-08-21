<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\load;

use lameco\kunstmaanmigrator\db\LegacyDbService;
use lameco\kunstmaanmigrator\load\SeoMigrationService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Phase 4.1 / Plan 04.1-05 / Task 1 — characterization tests for the
 * Settings-disabled gate at the head of SeoMigrationService::migrateAll().
 *
 * The gate decision body is exercised end-to-end in Phase 5 / TST-02 with a
 * real Craft bootstrap; here we lock the D-27 invariant on the testable
 * helper:
 *   - the warn-line copy is exact (so REPORT.md aggregation can str_contains it)
 *   - it is distinct from the existing 'plugin not installed' copy
 *
 * Mirrors the Reflection-on-private-helper pattern from Phase 4 / Plan 12
 * (AssetMigrationServiceRcaTest).
 */
final class SeoMigrationServiceGateTest extends TestCase
{
    public function testDisabledWarnLineCopyMatchesD27(): void
    {
        $rm = new ReflectionMethod(SeoMigrationService::class, 'disabledWarnLine');
        self::assertSame(
            'SEO adapter disabled (explicitly via Settings::seoEnabled); SEO migration skipped.',
            $rm->invoke(null),
        );
    }

    public function testDisabledWarnLineIsDistinctFromPluginAbsentCopy(): void
    {
        $rm = new ReflectionMethod(SeoMigrationService::class, 'disabledWarnLine');
        $line = (string) $rm->invoke(null);
        self::assertStringContainsString('explicitly via Settings::seoEnabled', $line);
        // The plugin-not-installed copy says "SEOmatic plugin not installed; SEO migration skipped."
        self::assertStringNotContainsString('plugin not installed', $line);
    }

    public function testDisabledWarnLineIsRecognisableForSkippedStagesAggregation(): void
    {
        // D-27 + D-43: REPORT.md skipped-stages aggregation matches via str_contains
        // on a stable substring; lock that the 'SEO adapter disabled' substring
        // (which a future MigrateController aggregator can match on) is present.
        $rm = new ReflectionMethod(SeoMigrationService::class, 'disabledWarnLine');
        $line = (string) $rm->invoke(null);
        self::assertStringContainsString('SEO adapter disabled', $line);
    }

    public function testLegacyRefFallbackDerivesClassFromSourceAndIdFromSourceKey(): void
    {
        // 2026-05-09 — closes the stale-meta gap. Older state rows lack
        // meta.legacyClass / meta.legacyEntityId; both can be derived
        // directly from the state row itself:
        //   legacyClass    ← state.source with `_` → `\` (FQCN-shaped slug)
        //   legacyEntityId ← (int) state.sourceKey (FQCN entity row id)
        //
        // No DB query — the previous kuma_nodes-tree fallback was broken
        // (assumed sourceKey was a kuma_node_id, but it's the FQCN entity
        // row id under EntryMigrationService's state-write convention).
        $db = new class extends LegacyDbService {
            public int $queryCount = 0;

            public function queryOne(string $sql, array $params = []): ?array
            {
                $this->queryCount++;
                return null;
            }
        };

        $service = new SeoMigrationService();
        $service->legacyDb = $db;
        $service->sites = ['fr' => 'default', 'de' => 'de'];

        $rm = new ReflectionMethod(SeoMigrationService::class, 'resolveLegacyRef');
        $result = $rm->invoke($service, 'App_Entity_Pages_ArticlePage', '123', null);

        self::assertSame(['App\\Entity\\Pages\\ArticlePage', 123], $result);
        // Derivation must NOT hit the DB at all on the fallback path —
        // the previous implementation paid a per-skipped-entry roundtrip
        // for nothing.
        self::assertSame(0, $db->queryCount);
    }

    public function testLegacyRefFallbackPrefersMetaWhenPresent(): void
    {
        $db = new class extends LegacyDbService {
            public int $queryCount = 0;

            public function queryOne(string $sql, array $params = []): ?array
            {
                $this->queryCount++;
                return null;
            }
        };
        $service = new SeoMigrationService();
        $service->legacyDb = $db;

        $rm = new ReflectionMethod(SeoMigrationService::class, 'resolveLegacyRef');
        $result = $rm->invoke($service, 'App_Entity_Pages_ArticlePage', '123', [
            // Meta cache wins over the source-derived value: cached id 999
            // beats the would-be-derived 123. Confirms the per-type migrators
            // can override the derivation when they have richer info.
            'legacyClass' => 'App\\Entity\\Pages\\ArticlePage',
            'legacyEntityId' => 999,
        ]);

        self::assertSame(['App\\Entity\\Pages\\ArticlePage', 999], $result);
        self::assertSame(0, $db->queryCount);
    }

    public function testLegacyRefReturnsNullOnSingleton(): void
    {
        $service = new SeoMigrationService();
        $rm = new ReflectionMethod(SeoMigrationService::class, 'resolveLegacyRef');
        $result = $rm->invoke($service, 'singleton', 'globalSettings', null);

        self::assertSame([null, 0], $result);
    }

    public function testLegacyRefReturnsNullWhenSourceKeyIsNotNumeric(): void
    {
        // Defensive — non-numeric sourceKey can't be cast to a meaningful
        // FQCN entity row id, so the fallback gives up rather than silently
        // associating with row id 0.
        $service = new SeoMigrationService();
        $rm = new ReflectionMethod(SeoMigrationService::class, 'resolveLegacyRef');
        $result = $rm->invoke($service, 'App_Entity_Pages_ArticlePage', 'not-a-number', null);

        self::assertSame([null, 0], $result);
    }
}
