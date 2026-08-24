<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\finalize;

use craft\elements\Entry;
use Lameco\Kunstmaanmigrator\finalize\StructureUriPass;
use Lameco\Kunstmaanmigrator\Mapping\Mapping;
use Lameco\Kunstmaanmigrator\tests\support\InMemoryElementWriter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The URI pass, driven through the seam.
 *
 * What the resave did by accident — recompute every Structure entry's URI
 * after the parents had settled — the pass does on purpose: parents first,
 * every site, every section the mapping writes into and no other.
 */
final class StructureUriPassTest extends TestCase
{
    private function entry(int $id, int $siteId): Entry
    {
        /** @var Entry $entry */
        $entry = (new ReflectionClass(Entry::class))->newInstanceWithoutConstructor();
        $entry->id = $id;
        $entry->siteId = $siteId;

        return $entry;
    }

    public function testParentsAreSettledBeforeTheirChildrenOnEverySite(): void
    {
        $writer = new InMemoryElementWriter();

        // A root with a child and a grandchild, as the adapter hands them out:
        // in structure order, one entry per element on the site Craft prefers.
        $root = $this->entry(10, 1);
        $child = $this->entry(11, 1);
        $grandchild = $this->entry(12, 2);
        $writer->willLiveOn(10, [1, 2, 3]);
        $writer->willLiveOn(11, [1, 2]);
        $writer->willLiveOn(12, [2]);
        $writer->willWalk('pages', [$root, $child, $grandchild]);

        $counts = (new StructureUriPass($writer))->run(Mapping::fromArray([
            'pages' => ['HomePage' => ['entryType' => 'home']],
        ]));

        self::assertSame(['pages' => 3], $counts);
        self::assertSame(
            [
                ['id' => 10, 'siteIds' => [1, 2, 3]],
                ['id' => 11, 'siteIds' => [1, 2]],
                ['id' => 12, 'siteIds' => [2]],
            ],
            $writer->urisUpdated,
            'a child recomputed before its parent inherits the prefix the parent still had',
        );
    }

    /**
     * The sections come off the kernel rows, so a page row that leaves
     * `section:` to its default is still walked — the raw spec has no key for
     * it, which is what the old re-save read.
     */
    public function testEverySectionTheMappingWritesIntoIsWalkedOnce(): void
    {
        $writer = new InMemoryElementWriter();
        $writer->willWalk('pages', [$this->entry(1, 1)]);
        $writer->willWalk('partners', [$this->entry(2, 1), $this->entry(3, 1)]);
        $writer->willWalk('news', [$this->entry(4, 1)]);

        $counts = (new StructureUriPass($writer))->run(Mapping::fromArray([
            'pages' => [
                'HomePage' => ['entryType' => 'home'],
                'ContentPage' => ['entryType' => 'content'],
                'OldPage' => ['drop' => 'gone'],
                'NewsPage' => ['entryType' => 'news', 'section' => 'news'],
            ],
            'entities' => [
                'Partner' => ['table' => 'partner', 'entryType' => 'partner', 'section' => 'partners'],
                'Tag' => ['table' => 'tag', 'entryType' => 'tag'],
            ],
        ]));

        self::assertSame(['pages' => 1, 'news' => 1, 'partners' => 2], $counts);
        self::assertSame([1, 4, 2, 3], array_column($writer->urisUpdated, 'id'));
    }

    /** A Channel, or a section Craft does not have: the adapter yields nothing, the pass says so. */
    public function testASectionWithNothingToWalkReportsZero(): void
    {
        $writer = new InMemoryElementWriter();

        $counts = (new StructureUriPass($writer))->run(Mapping::fromArray([
            'pages' => ['HomePage' => ['entryType' => 'home']],
        ]));

        self::assertSame(['pages' => 0], $counts);
        self::assertSame([], $writer->urisUpdated);
    }

    public function testProgressIsReportedPerSection(): void
    {
        $writer = new InMemoryElementWriter();
        $seen = [];

        (new StructureUriPass($writer))->run(
            Mapping::fromArray([
                'pages' => ['HomePage' => ['entryType' => 'home']],
                'entities' => ['Partner' => ['table' => 'partner', 'entryType' => 'partner', 'section' => 'partners']],
            ]),
            function(string $handle, int $done, int $total) use (&$seen): void {
                $seen[] = [$handle, $done, $total];
            },
        );

        self::assertSame([['pages', 1, 2], ['partners', 2, 2]], $seen);
    }

    /**
     * The pass is the only URI recomputation the run does, and both callers
     * run it: the console after finalize, the queue as the last job of the
     * chain. A caller that grew its own would be the third copy of a loop —
     * see FinalizePassSurfaceTest for how that went last time.
     */
    public function testBothCallersRunTheOnePass(): void
    {
        $root = dirname(__DIR__, 3);

        foreach (['src/console/MigrateController.php', 'src/queue/RecomputeStructureUrisJob.php'] as $file) {
            self::assertStringContainsString(
                'new StructureUriPass(',
                (string) file_get_contents($root . '/' . $file),
                $file . ' runs the URI pass',
            );
        }
    }
}
