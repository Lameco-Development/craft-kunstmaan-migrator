<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\finalize;

use craft\elements\Asset;
use craft\elements\Entry;
use Lameco\Kunstmaanmigrator\finalize\SearchIndexPass;
use Lameco\Kunstmaanmigrator\tests\support\InMemoryElementWriter;
use Lameco\Kunstmaanmigrator\tests\support\InMemoryMigrationState;
use PHPUnit\Framework\TestCase;
use verbb\navigation\elements\Node;

/**
 * The index stage through its interface: given what the state table says
 * the migration wrote, which ids reach Craft's indexing, in which jobs, and
 * what the run reports.
 */
final class SearchIndexPassTest extends TestCase
{
    private InMemoryElementWriter $writer;

    private InMemoryMigrationState $state;

    protected function setUp(): void
    {
        $this->writer = new InMemoryElementWriter();
        $this->state = new InMemoryMigrationState();
    }

    public function testEveryMigratedElementAndItsNestedEntriesAreQueuedInChunksAndCounted(): void
    {
        $this->state->record('App\Entity\Page', '1', 'entry', 10);
        $this->state->record('App\Entity\Page', '2', 'entry', 11);
        $this->state->record('App\Entity\Page', '2-alias', 'entry', 11);
        $this->state->record('App\Entity\News', '3', 'entry', 12);
        $this->state->record('media', 'kuma_media:1', 'asset', 50);
        $this->state->record('media', 'kuma_media:2', 'asset', 51);
        $this->state->record('media', 'kuma_media:3', 'video', 0);
        $this->state->record('redirect', '7', 'retour_static_redirect', 70);
        $this->state->record('nav', '8', 'navigation_node', 80);
        $this->writer->willOwnNested(10, [100, 101]);
        $this->writer->willOwnNested(100, [1000]);

        $pass = new SearchIndexPass($this->writer, $this->state, chunk: 4);
        $counts = $pass->run();

        self::assertSame(['entry' => 6, 'asset' => 2, 'navigation_node' => 1], $counts, 'an alias is the same element once; a redirect is not an element; a remote video has no asset');
        self::assertSame(2 + 1 + 1, $pass->jobs(), 'six entry ids in chunks of four, then one job per other type');
        self::assertSame([
            ['elementType' => Entry::class, 'elementIds' => [10, 11, 12, 100]],
            ['elementType' => Entry::class, 'elementIds' => [101, 1000]],
            ['elementType' => Asset::class, 'elementIds' => [50, 51]],
            ['elementType' => Node::class, 'elementIds' => [80]],
        ], $this->writer->searchIndexQueued, 'nested entries are indexed as elements of their own, however deep');
        self::assertSame([], $this->writer->saved, 'no element is re-saved');
    }

    public function testAnEmptyStateTableQueuesNothing(): void
    {
        $pass = new SearchIndexPass($this->writer, $this->state);

        self::assertSame(['entry' => 0, 'asset' => 0, 'navigation_node' => 0], $pass->run());
        self::assertSame(0, $pass->jobs());
        self::assertSame([], $this->writer->searchIndexQueued);
    }

    public function testProgressIsReportedPerElementType(): void
    {
        $seen = [];

        (new SearchIndexPass($this->writer, $this->state))->run(static function(string $type, int $done, int $total) use (&$seen): void {
            $seen[] = [$type, $done, $total];
        });

        self::assertSame([['entry', 1, 3], ['asset', 2, 3], ['navigation_node', 3, 3]], $seen);
    }
}
