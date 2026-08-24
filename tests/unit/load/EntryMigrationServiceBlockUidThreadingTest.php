<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\load;

use craft\elements\Entry;
use Lameco\Kunstmaanmigrator\load\EntryMigrationService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The re-run identity chain: source refs are read off the payload before the
 * strip (extractSourceRefPositions), just-saved block ids are matched back by
 * position (collectBlockUidsByPosition), and the next run keys blocks by those
 * ids (threadBlockUidsIntoPageBuilder) so Craft updates in place instead of
 * duplicating every nested entry on every run.
 */
final class EntryMigrationServiceBlockUidThreadingTest extends TestCase
{
    private function callPrivate(string $method, mixed ...$args): mixed
    {
        return (new ReflectionMethod(EntryMigrationService::class, $method))
            ->invoke(new EntryMigrationService(), ...$args);
    }

    public function testAKnownSourceRefRekeysTheBlockToItsPersistedId(): void
    {
        $out = $this->callPrivate('threadBlockUidsIntoPageBuilder', [
            'new1' => ['type' => 'text', 'fields' => ['_sourcePartRef' => 'Text:5']],
            'new2' => ['type' => 'text', 'fields' => ['_sourcePartRef' => 'Text:6']],
        ], ['Text:5' => '901']);

        self::assertSame([901, 'new2'], array_keys($out), 'unknown refs keep their new{n} key, order preserved');
    }

    public function testAnEmptyUidMapLeavesThePayloadAlone(): void
    {
        $payload = ['new1' => ['type' => 'text', 'fields' => ['_sourcePartRef' => 'Text:5']]];

        self::assertSame($payload, $this->callPrivate('threadBlockUidsIntoPageBuilder', $payload, []));
    }

    public function testANestedMatrixIsThreadedInsideItsParentBlock(): void
    {
        // Reusing a parent in place must reuse its children too, or the
        // children are rebuilt underneath it on every run.
        $out = $this->callPrivate('threadBlockUidsIntoPageBuilder', [
            'new1' => [
                'type' => 'contentBlock',
                'fields' => [
                    '_sourcePartRef' => 'Content:1',
                    'columns' => [
                        'new2' => ['type' => 'column', 'fields' => ['_sourcePartRef' => 'Column:9']],
                    ],
                ],
            ],
        ], ['Content:1' => '800', 'Column:9' => '801']);

        self::assertSame([800], array_keys($out));
        self::assertSame([801], array_keys($out[800]['fields']['columns']));
    }

    public function testSourceRefPositionsAreExtractedPerFieldInBlockOrder(): void
    {
        $positions = $this->callPrivate('extractSourceRefPositions', [
            'pageBuilder' => [
                ['type' => 'text', 'fields' => ['_sourcePartRef' => 'Text:5']],
                ['type' => 'text', 'fields' => []],
            ],
            'body' => 'not a matrix payload',
        ]);

        self::assertSame(['pageBuilder'], array_keys($positions));
        self::assertSame('Text:5', $positions['pageBuilder'][0]['ref']);
        self::assertNull($positions['pageBuilder'][1]['ref']);
    }

    public function testNestedRefsAreExtractedAsChildrenOfTheirParentPosition(): void
    {
        $positions = $this->callPrivate('extractSourceRefPositions', [
            'pageBuilder' => [
                [
                    'type' => 'contentBlock',
                    'fields' => [
                        '_sourcePartRef' => 'Content:1',
                        'columns' => [
                            ['type' => 'column', 'fields' => ['_sourcePartRef' => 'Column:9']],
                        ],
                    ],
                ],
            ],
        ]);

        self::assertSame('Column:9', $positions['pageBuilder'][0]['children']['columns'][0]['ref']);
    }

    public function testSavedBlockIdsAreMatchedBackToRefsByPosition(): void
    {
        $entry = ThreadingStubEntry::withBlocks([
            'pageBuilder' => [ThreadingStubBlock::withId(901), ThreadingStubBlock::withId(902)],
        ]);

        $map = $this->callPrivate('collectBlockUidsByPosition', $entry, [
            'pageBuilder' => [
                ['ref' => 'Text:5', 'children' => []],
                ['ref' => 'Text:6', 'children' => []],
            ],
        ]);

        self::assertSame(['Text:5' => '901', 'Text:6' => '902'], $map);
    }

    public function testABlockWithoutARecordedRefContributesNothing(): void
    {
        $entry = ThreadingStubEntry::withBlocks([
            'pageBuilder' => [ThreadingStubBlock::withId(901), ThreadingStubBlock::withId(902)],
        ]);

        $map = $this->callPrivate('collectBlockUidsByPosition', $entry, [
            'pageBuilder' => [['ref' => null, 'children' => []]],
        ]);

        self::assertSame([], $map, 'a null ref and a missing position are both skipped');
    }

    public function testChildBlockIdsAreCollectedThroughTheirParent(): void
    {
        $child = ThreadingStubBlock::withId(801);
        $parent = ThreadingStubBlock::withId(800, ['columns' => [$child]]);
        $entry = ThreadingStubEntry::withBlocks(['pageBuilder' => [$parent]]);

        $map = $this->callPrivate('collectBlockUidsByPosition', $entry, [
            'pageBuilder' => [
                ['ref' => 'Content:1', 'children' => ['columns' => [['ref' => 'Column:9', 'children' => []]]]],
            ],
        ]);

        self::assertSame(['Content:1' => '800', 'Column:9' => '801'], $map);
    }

    public function testAFieldThatIsNotAMatrixIsSkippedInsteadOfFailingTheCollection(): void
    {
        $entry = ThreadingStubEntry::withBlocks([]);

        $map = $this->callPrivate('collectBlockUidsByPosition', $entry, [
            'body' => [['ref' => 'Text:5', 'children' => []]],
        ]);

        self::assertSame([], $map);
    }
}

/**
 * Answers getFieldValue with a duck-typed block query, the way Craft's Matrix
 * field does; an unknown handle throws, the way an unmapped field would.
 *
 * @internal
 */
final class ThreadingStubEntry extends Entry
{
    /** @var array<string, list<ThreadingStubBlock>> */
    private array $blocksByField = [];

    /** @param array<string, list<ThreadingStubBlock>> $blocksByField */
    public static function withBlocks(array $blocksByField): self
    {
        $entry = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $entry->blocksByField = $blocksByField;

        return $entry;
    }

    public function getFieldValue(string $fieldHandle): mixed
    {
        if (!isset($this->blocksByField[$fieldHandle])) {
            throw new \RuntimeException('not a matrix field: ' . $fieldHandle);
        }

        return new ThreadingStubBlockQuery($this->blocksByField[$fieldHandle]);
    }
}

/**
 * A nested entry that can itself carry nested matrixes, for the recursive
 * child-collection path.
 *
 * @internal
 */
final class ThreadingStubBlock extends Entry
{
    /** @var array<string, list<ThreadingStubBlock>> */
    private array $childBlocksByField = [];

    /** @param array<string, list<ThreadingStubBlock>> $childBlocksByField */
    public static function withId(int $id, array $childBlocksByField = []): self
    {
        $block = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $block->id = $id;
        $block->childBlocksByField = $childBlocksByField;

        return $block;
    }

    public function getFieldValue(string $fieldHandle): mixed
    {
        if (!isset($this->childBlocksByField[$fieldHandle])) {
            throw new \RuntimeException('not a matrix field: ' . $fieldHandle);
        }

        return new ThreadingStubBlockQuery($this->childBlocksByField[$fieldHandle]);
    }
}

/**
 * @internal
 */
final class ThreadingStubBlockQuery
{
    /** @param list<ThreadingStubBlock> $blocks */
    public function __construct(private readonly array $blocks)
    {
    }

    /** @return list<ThreadingStubBlock> */
    public function all(): array
    {
        return $this->blocks;
    }
}
