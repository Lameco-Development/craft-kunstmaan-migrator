<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\load;

use Lameco\Kunstmaanmigrator\db\LegacyDbService;
use Lameco\Kunstmaanmigrator\load\NavigationMigrationService;
use Lameco\Kunstmaanmigrator\tests\support\InMemoryMigrationState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

/**
 * How a menu item finds the entry its page became — and which pages never
 * get a nav row at all.
 *
 * These helpers are pure decisions over the state map and one legacy lookup,
 * so they are pinned directly (ReflectionMethod, the same way the
 * neighbouring nav tests reach private judgement). The identity model here
 * has already broken once: the service asked for the v1 per-FQCN key that
 * nothing writes any more and navigation silently migrated zero nodes — the
 * env-first / v1-fallback ladder is exactly what these tests hold in place
 * while the legacy reads move behind a shared reader.
 */
final class NavigationEntryResolutionTest extends TestCase
{
    /**
     * @param list<array<string, mixed>|\Throwable|null> $oneRows
     */
    private function service(array $oneRows = []): NavigationMigrationService
    {
        $svc = new NavigationMigrationService();
        $svc->stateService = new InMemoryMigrationState();
        $svc->legacyDb = new class($oneRows) extends LegacyDbService {
            /** @param list<array<string, mixed>|\Throwable|null> $oneRows */
            public function __construct(private array $oneRows)
            {
                parent::__construct();
            }

            public function queryOne(string $sql, array $params = []): ?array
            {
                $next = array_shift($this->oneRows);
                if ($next instanceof \Throwable) {
                    throw $next;
                }

                return $next;
            }
        };

        return $svc;
    }

    private function state(NavigationMigrationService $svc): InMemoryMigrationState
    {
        \assert($svc->stateService instanceof InMemoryMigrationState);

        return $svc->stateService;
    }

    private function invoke(NavigationMigrationService $svc, string $method, mixed ...$args): mixed
    {
        return (new ReflectionMethod($svc, $method))->invoke($svc, ...$args);
    }

    // ------------------------------------------------------------------
    // resolveEntryIdFromNodeTranslation
    // ------------------------------------------------------------------

    public function testAnUnreadableTranslationTableThrowsSoTheItemLoopReportsAFailure(): void
    {
        // null means "no migrated entry yet — re-run later"; a legacy-DB
        // outage swallowed into null used to hand the operator exactly that
        // wrong guidance. The item loop's catch turns the throw into a
        // per-item failure with the real message.
        $svc = $this->service([new RuntimeException('translations gone')]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('translations gone');
        $this->invoke($svc, 'resolveEntryIdFromNodeTranslation', 44, '');
    }

    public function testAMissingTranslationRowResolvesToNothing(): void
    {
        $svc = $this->service([null]);

        self::assertNull($this->invoke($svc, 'resolveEntryIdFromNodeTranslation', 44, ''));
    }

    public function testATranslationWithoutALiveVersionResolvesToNothing(): void
    {
        $svc = $this->service([['node_id' => 7, 'ref_id' => null, 'ref_entity_name' => null]]);

        self::assertNull($this->invoke($svc, 'resolveEntryIdFromNodeTranslation', 44, ''));
    }

    public function testATranslationResolvesThroughTheEnvironmentScopedNodeKey(): void
    {
        $svc = $this->service(
            [['node_id' => 7, 'ref_id' => 3, 'ref_entity_name' => 'App\\Entity\\Pages\\ContentPage']],
        );
        $this->state($svc)->willResolve('COM:kuma_nodes', '7', 500);

        self::assertSame(500, $this->invoke($svc, 'resolveEntryIdFromNodeTranslation', 44, 'COM'));
    }

    // ------------------------------------------------------------------
    // resolveEntryIdForNode
    // ------------------------------------------------------------------

    public function testTheV1PerFqcnStateKeyIsStillHonouredAsAFallback(): void
    {
        $svc = $this->service();
        $this->state($svc)->willResolve('App_Entity_Pages_BlogPage', '3', 600);

        self::assertSame(
            600,
            $this->invoke($svc, 'resolveEntryIdForNode', 7, 3, 'App\\Entity\\Pages\\BlogPage', 'COM'),
            'a host still carrying v1 state rows keeps resolving',
        );
    }

    public function testTheEnvironmentKeyWinsOverTheV1Key(): void
    {
        $svc = $this->service();
        $this->state($svc)->willResolve('COM:kuma_nodes', '7', 500);
        $this->state($svc)->willResolve('App_Entity_Pages_BlogPage', '3', 600);

        self::assertSame(500, $this->invoke($svc, 'resolveEntryIdForNode', 7, 3, 'App\\Entity\\Pages\\BlogPage', 'COM'));
    }

    public function testWithoutAnEnvironmentOnlyTheV1KeyIsTried(): void
    {
        $svc = $this->service();
        $this->state($svc)->willResolve('App_Entity_Pages_BlogPage', '3', 600);

        self::assertSame(600, $this->invoke($svc, 'resolveEntryIdForNode', 7, 3, '\\App\\Entity\\Pages\\BlogPage', ''));
    }

    public function testANodeWithNeitherFqcnNorRefIdResolvesToNothing(): void
    {
        $svc = $this->service();

        self::assertNull($this->invoke($svc, 'resolveEntryIdForNode', 7, 3, '', 'COM'));
        self::assertNull($this->invoke($svc, 'resolveEntryIdForNode', 7, 0, 'App\\Entity\\Pages\\BlogPage', 'COM'));
    }

    // ------------------------------------------------------------------
    // isSingletonFqcn
    // ------------------------------------------------------------------

    /** @return array<string, array{string, bool}> */
    public static function singletonFqcnCases(): array
    {
        return [
            'empty string' => ['', false],
            'namespaced singleton' => ['App\\Entity\\Pages\\FooterPage', true],
            'leading backslash' => ['\\App\\Entity\\Pages\\SettingsPage', true],
            'bare short name' => ['FooterPage', true],
            'ordinary page' => ['App\\Entity\\Pages\\ContentPage', false],
            'suffix must be the whole short name' => ['App\\Entity\\Pages\\NotAFooterPage', false],
        ];
    }

    #[DataProvider('singletonFqcnCases')]
    public function testSingletonPagesAreRecognisedByShortName(string $fqcn, bool $expected): void
    {
        $svc = $this->service();

        self::assertSame($expected, $this->invoke($svc, 'isSingletonFqcn', $fqcn));
    }

    // ------------------------------------------------------------------
    // Adapter-setting overrides
    // ------------------------------------------------------------------

    public function testAnOverriddenNavHandleIsAnsweredWithoutConsultingPluginSettings(): void
    {
        $svc = $this->service();
        $svc->nodeMenuNavHandle = 'customNav';

        self::assertSame('customNav', $this->invoke($svc, 'navHandle'));
    }

    public function testOverriddenExclusionsAreAnsweredWithoutConsultingPluginSettings(): void
    {
        $svc = $this->service();
        $svc->nodeMenuExcludedInternalNames = ['settings', 'dienst'];

        self::assertSame(['settings', 'dienst'], $this->invoke($svc, 'excludedInternalNames'));
    }
}
