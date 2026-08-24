<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\load;

use Lameco\Kunstmaanmigrator\load\MigrationOptions;
use Lameco\Kunstmaanmigrator\load\MigrationReport;
use Lameco\Kunstmaanmigrator\load\NavigationMigrationService;
use Lameco\Kunstmaanmigrator\sites\SiteMap;
use Lameco\Kunstmaanmigrator\tests\support\FakeLegacyDb;
use Lameco\Kunstmaanmigrator\tests\support\InMemoryElementWriter;
use Lameco\Kunstmaanmigrator\tests\support\InMemoryMigrationState;
use Lameco\Kunstmaanmigrator\tests\support\InMemoryNavigationGateway;
use Lameco\Kunstmaanmigrator\tests\support\ThrowingLegacyDb;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use verbb\navigation\elements\Node as NavNode;

/**
 * The NodeMenu pass, driven end to end.
 *
 * This half of the class had one static check against it and shipped two
 * undefined-variable bugs, both on error paths — precisely the paths a
 * reflection check cannot reach. It was untestable for one reason: it read
 * Craft's primary site statically, so nothing could drive it without a booted
 * Craft. That read is now the caller's job, and the pass takes the two facts
 * it actually needs, which is all it took to make the behaviour assertable
 * with the fakes that already existed.
 *
 * What is pinned here is the pass's judgement — which nodes it drops and why,
 * and the ordering verbb requires — rather than the shape of its SQL.
 */
final class NavigationNodeMenuPassTest extends TestCase
{
    private const PRIMARY_SITE_ID = 1;
    private const NAV_ID = 7;

    private function service(
        FakeLegacyDb|ThrowingLegacyDb $db,
        InMemoryElementWriter $writer,
        InMemoryNavigationGateway $navigation,
        InMemoryMigrationState $state,
    ): NavigationMigrationService {
        $svc = new class() extends NavigationMigrationService {
            // Craft's element constructor boots the application. The object
            // itself is fine once built, so build it without running that.
            protected function newNavNode(): NavNode
            {
                return (new \ReflectionClass(NavNode::class))->newInstanceWithoutConstructor();
            }
        };
        $svc->elementWriter = $writer;
        $svc->navigationGateway = $navigation;
        $svc->legacyDb = $db;
        $svc->stateService = $state;
        // Both non-default, so navHandle() and excludedInternalNames() answer
        // from the properties instead of reaching for plugin settings.
        $svc->nodeMenuNavHandle = 'mainNav';
        $svc->nodeMenuExcludedInternalNames = ['settings', 'dienst'];

        return $svc;
    }

    private function sites(): SiteMap
    {
        return SiteMap::bind(
            ['nl' => 'default'],
            [(object) ['id' => self::PRIMARY_SITE_ID, 'handle' => 'default', 'language' => 'nl-NL']],
        );
    }

    private function runPass(
        NavigationMigrationService $svc,
        MigrationReport $report,
        bool $dryRun = false,
    ): void {
        (new ReflectionMethod($svc, 'migrateNodeMenu'))->invoke(
            $svc,
            ['nl' => self::PRIMARY_SITE_ID],
            $this->sites(),
            self::PRIMARY_SITE_ID,
            'default',
            new MigrationOptions(dryRun: $dryRun),
            $report,
        );
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function row(int $id, int $parentId, array $overrides = []): array
    {
        return $overrides + [
            'id' => $id,
            'parent_id' => $parentId,
            'lvl' => 1,
            'lft' => $id,
            'internal_name' => 'page' . $id,
            'ref_entity_name' => 'App\\Entity\\Pages\\ContentPage',
            'ref_id' => 100 + $id,
            'sort_weight' => $id,
        ];
    }

    /** Entry that kuma_node $id's ref_id resolves to, via the FQCN state source. */
    private function entryExistsFor(InMemoryMigrationState $state, int $id, int $entryId): void
    {
        $state->willResolve('App_Entity_Pages_ContentPage', (string) (100 + $id), $entryId);
    }

    public function testAMissingNavIsReportedAndNothingIsRead(): void
    {
        $db = new FakeLegacyDb([[$this->row(2, 1)]]);
        $svc = $this->service($db, $w = new InMemoryElementWriter(), new InMemoryNavigationGateway(), new InMemoryMigrationState());
        $report = new MigrationReport();

        $this->runPass($svc, $report);

        self::assertSame([], $w->saved, 'no nav means nothing to write into');
        self::assertNotEmpty($report->warnings);
        self::assertStringContainsString('mainNav', $report->warnings[0]);
    }

    public function testALegacyDatabaseFailureIsReportedRatherThanThrown(): void
    {
        $svc = $this->service(
            new ThrowingLegacyDb(),
            $w = new InMemoryElementWriter(),
            new InMemoryNavigationGateway(['mainNav' => self::NAV_ID]),
            new InMemoryMigrationState(),
        );
        $report = new MigrationReport();

        $this->runPass($svc, $report);

        self::assertSame([], $w->saved);
        self::assertNotEmpty($report->warnings);
        self::assertStringContainsString('kuma_nodes', $report->warnings[0]);
    }

    public function testANodeIsRegisteredWithVerbbBeforeItIsSaved(): void
    {
        $state = new InMemoryMigrationState();
        $this->entryExistsFor($state, 2, 500);
        $nav = new InMemoryNavigationGateway(['mainNav' => self::NAV_ID]);
        $svc = $this->service(new FakeLegacyDb([[$this->row(2, 1)]]), $w = new InMemoryElementWriter(), $nav, $state);
        $report = new MigrationReport();

        $this->runPass($svc, $report);

        self::assertCount(1, $w->saved);
        $node = $w->saved[0]['element'];
        self::assertInstanceOf(NavNode::class, $node);
        self::assertSame(500, $node->elementId, 'the node points at the migrated entry');
        self::assertSame(self::NAV_ID, $node->navId);
        self::assertNotEmpty($nav->registeredNodeIds(), 'verbb reads a node from its temp registry, so registration must precede the save');
    }

    public function testTheSavedNodeIsRecordedSoARerunUpdatesRatherThanDuplicates(): void
    {
        $state = new InMemoryMigrationState();
        $this->entryExistsFor($state, 2, 500);
        $svc = $this->service(
            new FakeLegacyDb([[$this->row(2, 1)]]),
            new InMemoryElementWriter(),
            new InMemoryNavigationGateway(['mainNav' => self::NAV_ID]),
            $state,
        );

        $this->runPass($svc, new MigrationReport());

        self::assertCount(1, $state->recorded);
        self::assertSame('kuma_node:2', $state->recorded[0]['key']);
        self::assertSame('navigation_node', $state->recorded[0]['targetType']);
    }

    public function testANodeWhoseEntryHasNotMigratedYetIsSkippedWithAWarningRatherThanKillingThePass(): void
    {
        $state = new InMemoryMigrationState();
        $this->entryExistsFor($state, 3, 501);
        $svc = $this->service(
            new FakeLegacyDb([[$this->row(2, 1), $this->row(3, 1)]]),
            $w = new InMemoryElementWriter(),
            new InMemoryNavigationGateway(['mainNav' => self::NAV_ID]),
            $state,
        );
        $report = new MigrationReport();

        $this->runPass($svc, $report);

        self::assertCount(1, $w->saved, 'the resolvable node still migrates');
        self::assertSame(1, $report->counts['skipped'] ?? 0);
        self::assertStringContainsString('kuma_node id=2', implode("\n", $report->warnings));
    }

    public function testANodeWithNoRefIdIsSkipped(): void
    {
        $svc = $this->service(
            new FakeLegacyDb([[$this->row(2, 1, ['ref_id' => null])]]),
            $w = new InMemoryElementWriter(),
            new InMemoryNavigationGateway(['mainNav' => self::NAV_ID]),
            new InMemoryMigrationState(),
        );
        $report = new MigrationReport();

        $this->runPass($svc, $report);

        self::assertSame([], $w->saved);
        self::assertSame(1, $report->counts['skipped'] ?? 0);
    }

    public function testASingletonPageIsNotGivenANavRow(): void
    {
        $state = new InMemoryMigrationState();
        $state->willResolve('App_Entity_Pages_FooterPage', '102', 500);
        $svc = $this->service(
            new FakeLegacyDb([[$this->row(2, 1, ['ref_entity_name' => 'App\\Entity\\Pages\\FooterPage'])]]),
            $w = new InMemoryElementWriter(),
            new InMemoryNavigationGateway(['mainNav' => self::NAV_ID]),
            $state,
        );
        $report = new MigrationReport();

        $this->runPass($svc, $report);

        self::assertSame([], $w->saved, 'singletons are Craft Singles, surfaced through globals rather than nav');
        self::assertSame(1, $report->counts['skipped'] ?? 0);
    }

    public function testExcludingAParentDropsItsWholeSubtreeEvenWhenTheChildIsSeenFirst(): void
    {
        // Rows arrive in translation-weight order, so a child can precede its
        // parent. The exclusion set is therefore built in a pre-pass; without
        // it the child is judged against a parent nobody has looked at yet.
        $state = new InMemoryMigrationState();
        $this->entryExistsFor($state, 3, 500);
        $this->entryExistsFor($state, 2, 501);
        $svc = $this->service(
            new FakeLegacyDb([[
                $this->row(3, 2),                                  // child first
                $this->row(2, 1, ['internal_name' => 'dienst']),    // excluded parent second
            ]]),
            $w = new InMemoryElementWriter(),
            new InMemoryNavigationGateway(['mainNav' => self::NAV_ID]),
            $state,
        );
        $report = new MigrationReport();

        $this->runPass($svc, $report);

        self::assertSame([], $w->saved, 'excluding a parent excludes everything under it');
        self::assertSame(2, $report->counts['skipped'] ?? 0);
    }

    public function testACycleInTheParentChainDoesNotHang(): void
    {
        $state = new InMemoryMigrationState();
        $this->entryExistsFor($state, 2, 500);
        $this->entryExistsFor($state, 3, 501);
        $svc = $this->service(
            new FakeLegacyDb([[$this->row(2, 3), $this->row(3, 2)]]),
            new InMemoryElementWriter(),
            new InMemoryNavigationGateway(['mainNav' => self::NAV_ID]),
            $state,
        );

        $this->runPass($svc, $report = new MigrationReport());

        self::assertSame([], $report->failures, 'a corrupt source tree is walked once, not forever');
    }

    public function testAChildIsGivenItsParentAndATopLevelNodeIsLeftAtTheRoot(): void
    {
        $state = new InMemoryMigrationState();
        $this->entryExistsFor($state, 2, 500);
        $this->entryExistsFor($state, 3, 501);
        $svc = $this->service(
            new FakeLegacyDb([[$this->row(2, 1), $this->row(3, 2)]]),
            $writer = new InMemoryElementWriter(),
            new InMemoryNavigationGateway(['mainNav' => self::NAV_ID]),
            $state,
        );

        $this->runPass($svc, new MigrationReport());

        $byEntry = [];
        $saveCounts = [];
        foreach (array_column($writer->saved, 'element') as $node) {
            $byEntry[$node->elementId] = $node;
            $saveCounts[$node->elementId] = ($saveCounts[$node->elementId] ?? 0) + 1;
        }

        self::assertSame($byEntry[500]->id, $byEntry[501]->getParentId(), 'the child is reparented onto the migrated parent');
        self::assertSame(2, $saveCounts[501], 'the child is written once on create and again once it has a parent');
        // Asserting the top-level node's parent directly would read through to
        // Craft — an unset parent id falls back to loading the parent element.
        // That it was never written a second time is the same fact: linkage
        // skipped it, because kuma_node 2 hangs off the tree root and the root
        // has no verbb node.
        self::assertSame(1, $saveCounts[500], 'a node with no migrated parent stays at the root, untouched by linkage');
    }

    public function testADryRunWritesNothing(): void
    {
        $state = new InMemoryMigrationState();
        $this->entryExistsFor($state, 2, 500);
        $svc = $this->service(
            new FakeLegacyDb([[$this->row(2, 1)]]),
            $w = new InMemoryElementWriter(),
            new InMemoryNavigationGateway(['mainNav' => self::NAV_ID]),
            $state,
        );

        $this->runPass($svc, $report = new MigrationReport(), dryRun: true);

        self::assertSame([], $w->saved);
        self::assertSame([], $state->recorded);
        self::assertSame(1, $report->counts['skipped'] ?? 0);
    }
}
