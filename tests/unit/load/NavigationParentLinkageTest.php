<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\load;

use Lameco\Kunstmaanmigrator\load\MigrationReport;
use Lameco\Kunstmaanmigrator\load\NavigationMigrationService;
use Lameco\Kunstmaanmigrator\tests\support\FakeLegacyDb;
use Lameco\Kunstmaanmigrator\tests\support\InMemoryElementWriter;
use Lameco\Kunstmaanmigrator\tests\support\InMemoryNavigationGateway;
use Lameco\Kunstmaanmigrator\tests\support\ThrowingLegacyDb;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use verbb\navigation\elements\Node as NavNode;

/**
 * Parent linkage, end to end.
 *
 * This used to stop short of a saved node: linkage reached
 * `Navigation::$plugin->getNodes()->setTempNodes()` statically, so the write
 * path could not be driven even with Craft's element writes already faked.
 * With NavigationGateway alongside ElementWriter the whole path is assertable
 * — including the ordering that makes it correct, since verbb reads a node's
 * parent from its temp-node registry rather than from the database, and a node
 * saved before it is registered lands at the root.
 */
final class NavigationParentLinkageTest extends TestCase
{
    private function service(
        InMemoryElementWriter $writer,
        FakeLegacyDb|ThrowingLegacyDb $db,
        ?InMemoryNavigationGateway $navigation = null,
    ): NavigationMigrationService {
        $svc = new NavigationMigrationService();
        $svc->elementWriter = $writer;
        $svc->navigationGateway = $navigation ?? new InMemoryNavigationGateway();
        $svc->legacyDb = $db;

        return $svc;
    }

    private function node(int $id): NavNode
    {
        $node = (new \ReflectionClass(NavNode::class))->newInstanceWithoutConstructor();
        $node->id = $id;

        return $node;
    }

    private function link(NavigationMigrationService $svc, array $itemToNodeId, MigrationReport $report): void
    {
        (new ReflectionMethod($svc, 'applyParentLinkage'))->invoke($svc, $itemToNodeId, $report);
    }

    public function testAChildWhoseNodeCannotBeFoundIsNotSaved(): void
    {
        $writer = new InMemoryElementWriter();
        $report = new MigrationReport();

        // Rows say item 2's parent is item 1; both migrated, but the child's
        // node has since gone. findById returns null and the row is skipped.
        $this->link(
            $this->service($writer, new FakeLegacyDb([[['id' => 2, 'parent_id' => 1]]])),
            [1 => 100, 2 => 200],
            $report,
        );

        self::assertSame([], $writer->saved, 'a lookup that found nothing must not produce a write');
    }

    public function testAChildWhoseParentNeverMigratedIsReportedAndLeftAtTheRoot(): void
    {
        $writer = new InMemoryElementWriter();
        $report = new MigrationReport();

        $this->link(
            $this->service($writer, new FakeLegacyDb([[['id' => 2, 'parent_id' => 99]]])),
            [2 => 200],
            $report,
        );

        self::assertSame([], $writer->saved);
        self::assertStringContainsString(
            'kuma_menu_item id=2 parent_id=99 did not migrate; child remains as root.',
            implode("\n", $report->warnings),
        );
    }

    public function testARowWhoseChildNeverMigratedIsSkippedSilently(): void
    {
        $writer = new InMemoryElementWriter();
        $report = new MigrationReport();

        $this->link(
            $this->service($writer, new FakeLegacyDb([[['id' => 7, 'parent_id' => 1]]])),
            [1 => 100],
            $report,
        );

        self::assertSame([], $writer->saved);
        self::assertSame([], $report->warnings, 'a child that never migrated is not news about the parent');
    }

    public function testNothingIsQueriedWhenNoItemsMigrated(): void
    {
        $writer = new InMemoryElementWriter();
        $report = new MigrationReport();

        $this->link($this->service($writer, new ThrowingLegacyDb()), [], $report);

        self::assertSame([], $report->warnings, 'an empty set must not reach the legacy database at all');
    }

    public function testALegacyDatabaseFailureIsReportedRatherThanThrown(): void
    {
        $writer = new InMemoryElementWriter();
        $report = new MigrationReport();

        $this->link($this->service($writer, new ThrowingLegacyDb()), [1 => 100], $report);

        self::assertStringContainsString('nav tree may be flat', implode("\n", $report->warnings));
        self::assertSame([], $writer->saved);
    }

    public function testAChildIsRegisteredWithVerbbAndThenSaved(): void
    {
        $writer = new InMemoryElementWriter();
        $navigation = new InMemoryNavigationGateway();
        $child = $this->node(200);
        $writer->willFind(200, $child);
        $report = new MigrationReport();

        $this->link(
            $this->service($writer, new FakeLegacyDb([[['id' => 2, 'parent_id' => 1]]]), $navigation),
            [1 => 100, 2 => 200],
            $report,
        );

        self::assertSame([200], $navigation->registeredNodeIds());
        self::assertSame([$child], array_column($writer->saved, 'element'));
        self::assertSame([], $report->warnings);
    }

    public function testTheChildIsGivenItsParentBeforeItIsSaved(): void
    {
        $writer = new InMemoryElementWriter();
        $child = $this->node(200);
        $writer->willFind(200, $child);

        $this->link(
            $this->service($writer, new FakeLegacyDb([[['id' => 2, 'parent_id' => 1]]])),
            [1 => 100, 2 => 200],
            new MigrationReport(),
        );

        self::assertSame(100, $child->getParentId(), 'the saved node must already know its parent');
    }

    public function testASaveVerbbRefusesIsReported(): void
    {
        $writer = new InMemoryElementWriter();
        $child = $this->node(200);
        $writer->willFind(200, $child);
        $writer->willRefuse($child);
        $report = new MigrationReport();

        $this->link(
            $this->service($writer, new FakeLegacyDb([[['id' => 2, 'parent_id' => 1]]])),
            [1 => 100, 2 => 200],
            $report,
        );

        self::assertStringContainsString(
            'failed to set parent on nav node id=200 (kuma_menu_item id=2)',
            implode("\n", $report->warnings),
        );
    }
}
