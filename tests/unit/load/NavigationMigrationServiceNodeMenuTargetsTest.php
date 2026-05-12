<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\load;

use lameco\kunstmaanmigrator\db\LegacyDbService;
use lameco\kunstmaanmigrator\load\MigrationReport;
use lameco\kunstmaanmigrator\load\NavigationMigrationService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Throwable;

/**
 * Gap A (2026-05-11 follow-up) — auto-discovery of NodeMenu seed targets.
 *
 * `migrateNodeMenu` was previously hardcoded to write into a single nav
 * handle (`Settings::nodeMenuNavHandle`, default `headerMain`). Portfolio
 * shapes that surfaced during simac verification:
 *
 *   - dewert: `kuma_menu` empty, scaffolder emits `headerMain` →
 *     target = ['headerMain'] (existing behavior preserved).
 *   - simac: `kuma_menu` has rows ONLY for `secondary_top`; scaffolder
 *     emits `default` + `secondary_top` → MenuBundle covers
 *     `secondary_top`, NodeMenu pass seeds the empty `default` from
 *     the page tree.
 *   - deklerk: `kuma_menu` has rows for both `main` + `top`; scaffolder
 *     emits the same two → NodeMenu pass is a no-op.
 *
 * Discovery rule: verbb navs ∖ kuma_menu.name. These tests lock the
 * resolution against the three portfolio shapes plus back-compat
 * fallbacks for missing-DB / missing-verbb-navs.
 */
final class NavigationMigrationServiceNodeMenuTargetsTest extends TestCase
{
    public function testSimacShapeSeedsOnlyHandlesNotCoveredByMenuBundle(): void
    {
        $service = $this->makeService(
            menuBundleHandles: ['secondary_top' => true],
            verbbHandles: ['default', 'secondary_top'],
        );

        $targets = $this->invokeResolve($service);

        self::assertSame(['default'], $targets);
    }

    public function testDeklerkShapeReturnsEmptyWhenAllHandlesCovered(): void
    {
        $service = $this->makeService(
            menuBundleHandles: ['main' => true, 'top' => true],
            verbbHandles: ['main', 'top'],
        );

        $targets = $this->invokeResolve($service);

        self::assertSame([], $targets);
    }

    public function testDewertShapeSeedsHeaderMainWhenKumaMenuEmpty(): void
    {
        $service = $this->makeService(
            menuBundleHandles: [],
            verbbHandles: ['headerMain'],
        );

        $targets = $this->invokeResolve($service);

        self::assertSame(['headerMain'], $targets);
    }

    public function testFallsBackToSettingsHandleWhenVerbbEnumerationFails(): void
    {
        $service = $this->makeService(
            menuBundleHandles: ['secondary_top' => true],
            verbbHandles: null, // null sentinel from loadVerbbNavHandles
        );

        $targets = $this->invokeResolve($service);

        // Back-compat: when verbb is unreachable, the migrator preserves the
        // pre-multi-handle behavior of writing to Settings::nodeMenuNavHandle.
        self::assertSame(['headerMain'], $targets);
    }

    public function testFallsBackToSettingsHandleWhenNoVerbbNavsExist(): void
    {
        $service = $this->makeService(
            menuBundleHandles: [],
            verbbHandles: [],
        );

        $targets = $this->invokeResolve($service);

        // Fixture / test environments without applied project-config still
        // get a usable seed target.
        self::assertSame(['headerMain'], $targets);
    }

    public function testRespectsOperatorOverriddenSettingsHandleOnFallback(): void
    {
        $service = $this->makeService(
            menuBundleHandles: [],
            verbbHandles: null,
        );
        $service->nodeMenuNavHandle = 'customMain';

        $targets = $this->invokeResolve($service);

        self::assertSame(['customMain'], $targets);
    }

    public function testLoadMenuBundleHandlesReturnsEmptyWhenLegacyDbThrows(): void
    {
        $throwingDb = new class extends LegacyDbService {
            public function queryAll(string $sql, array $params = []): array
            {
                throw new \RuntimeException('kuma_menu table missing');
            }
        };

        $service = new NavigationMigrationService();
        $service->legacyDb = $throwingDb;

        $rm = new ReflectionMethod(NavigationMigrationService::class, 'loadMenuBundleHandles');
        $covered = $rm->invoke($service);

        // Tolerates missing-table shape — same as MenuBundle pass's
        // "no rows" branch (returns silently). Empty map = nothing is
        // covered = every verbb nav becomes a seed candidate.
        self::assertSame([], $covered);
    }

    public function testLoadMenuBundleHandlesExtractsDistinctNamesFromLegacyDb(): void
    {
        $db = new class extends LegacyDbService {
            public string $sql = '';

            public function queryAll(string $sql, array $params = []): array
            {
                $this->sql = $sql;
                return [
                    ['name' => 'main'],
                    ['name' => 'top'],
                    ['name' => ''],     // empty names filtered out
                    ['name' => 'main'], // dedup contract met by SELECT DISTINCT in SQL but our code also tolerates dupes
                ];
            }
        };

        $service = new NavigationMigrationService();
        $service->legacyDb = $db;

        $rm = new ReflectionMethod(NavigationMigrationService::class, 'loadMenuBundleHandles');
        $covered = $rm->invoke($service);

        self::assertSame(['main' => true, 'top' => true], $covered);
        self::assertStringContainsString('SELECT DISTINCT name FROM kuma_menu', $db->sql);
    }

    /**
     * @param array<string, true> $menuBundleHandles
     * @param list<string>|null $verbbHandles
     */
    private function makeService(array $menuBundleHandles, ?array $verbbHandles): NavigationMigrationService
    {
        return new class($menuBundleHandles, $verbbHandles) extends NavigationMigrationService {
            /** @param array<string, true> $menuBundleHandles */
            /** @param list<string>|null $verbbHandles */
            public function __construct(
                private array $menuBundleHandlesStub,
                private ?array $verbbHandlesStub,
            ) {
                parent::__construct();
            }

            protected function loadMenuBundleHandles(): array
            {
                return $this->menuBundleHandlesStub;
            }

            protected function loadVerbbNavHandles(MigrationReport $report): ?array
            {
                return $this->verbbHandlesStub;
            }
        };
    }

    private function invokeResolve(NavigationMigrationService $service): array
    {
        $rm = new ReflectionMethod(NavigationMigrationService::class, 'resolveNodeMenuTargetHandles');
        return $rm->invoke($service, new MigrationReport());
    }
}
