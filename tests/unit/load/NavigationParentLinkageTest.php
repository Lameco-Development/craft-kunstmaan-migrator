<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\load;

use lameco\kunstmaanmigrator\load\MigrationReport;
use lameco\kunstmaanmigrator\load\NavigationMigrationService;
use lameco\kunstmaanmigrator\tests\support\FakeLegacyDb;
use lameco\kunstmaanmigrator\tests\support\InMemoryElementWriter;
use lameco\kunstmaanmigrator\tests\support\ThrowingLegacyDb;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Parent linkage — the paths reachable behind the ElementWriter seam.
 *
 * Coverage here stops short of a saved node on purpose. Once linkage finds a
 * child it calls `Navigation::$plugin->getNodes()->setTempNodes()`, a static
 * reach into verbb/navigation that this seam does not cover and a unit test
 * cannot satisfy — see the note in the class docblock of the service. What is
 * assertable is everything up to that point, which is where the decisions are:
 * which rows are skipped, what is reported, and whether a lookup that finds
 * nothing still writes.
 */
final class NavigationParentLinkageTest extends TestCase
{
    private function service(InMemoryElementWriter $writer, FakeLegacyDb|ThrowingLegacyDb $db): NavigationMigrationService
    {
        $svc = new NavigationMigrationService();
        $svc->elementWriter = $writer;
        $svc->legacyDb = $db;

        return $svc;
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
}
