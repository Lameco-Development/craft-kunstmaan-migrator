<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\load;

use craft\elements\Entry;
use lameco\kunstmaanmigrator\load\EntryMigrationService;
use lameco\kunstmaanmigrator\tests\support\InMemoryElementWriter;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Nested-entry reconciliation, asserted on directly.
 *
 * AUDIT §2 — 7,729 (entry, site) pairs of orphaned blocks — was found by
 * running a migration against MySQL and reading the damage afterwards, because
 * the only way to observe what this module deletes was to let it delete. With
 * the ElementWriter seam the same question is a unit test: given this entry and
 * this payload, which blocks does it ask Craft to remove?
 */
final class EntryBlockReconciliationTest extends TestCase
{
    private function service(InMemoryElementWriter $writer): EntryMigrationService
    {
        $svc = new EntryMigrationService();
        $svc->elementWriter = $writer;

        return $svc;
    }

    private function callPrivate(EntryMigrationService $svc, string $method, mixed ...$args): mixed
    {
        return (new ReflectionMethod($svc, $method))->invoke($svc, ...$args);
    }

    public function testStaleBlocksOnASiteAreDeletedAndTrackedOnesAreKept(): void
    {
        $writer = new InMemoryElementWriter();
        $entry = StubEntry::with(siteId: 3, blocksByField: [
            'pageBuilder' => [StubBlock::withId(11), StubBlock::withId(12), StubBlock::withId(13)],
        ]);

        $this->callPrivate(
            $this->service($writer),
            'wipeStaleSecondarySiteBlocks',
            $entry,
            ['pageBuilder' => [['type' => 'text', 'fields' => []]]],
            [11, 13],
        );

        self::assertSame([12], $writer->deletedIds(), 'only the untracked block belongs to the previous run');
    }

    public function testDeletesAreHardSoAStaleBlockCannotReturnFromTheRecycleBin(): void
    {
        $writer = new InMemoryElementWriter();
        $entry = StubEntry::with(siteId: 3, blocksByField: [
            'pageBuilder' => [StubBlock::withId(99)],
        ]);

        $this->callPrivate(
            $this->service($writer),
            'wipeStaleSecondarySiteBlocks',
            $entry,
            ['pageBuilder' => [['type' => 'text', 'fields' => []]]],
            [],
        );

        self::assertTrue($writer->deleted[0]['hardDelete']);
    }

    public function testNothingIsDeletedWhenEveryBlockIsTracked(): void
    {
        $writer = new InMemoryElementWriter();
        $entry = StubEntry::with(siteId: 3, blocksByField: [
            'pageBuilder' => [StubBlock::withId(11), StubBlock::withId(12)],
        ]);

        $this->callPrivate(
            $this->service($writer),
            'wipeStaleSecondarySiteBlocks',
            $entry,
            ['pageBuilder' => [['type' => 'text', 'fields' => []]]],
            [11, 12],
        );

        self::assertSame([], $writer->deleted);
    }

    public function testAFieldThatIsNotAMatrixPayloadIsLeftAlone(): void
    {
        $writer = new InMemoryElementWriter();
        $entry = StubEntry::with(siteId: 3, blocksByField: [
            'body' => [StubBlock::withId(50)],
        ]);

        $this->callPrivate(
            $this->service($writer),
            'wipeStaleSecondarySiteBlocks',
            $entry,
            ['body' => 'just some text'],
            [],
        );

        self::assertSame([], $writer->deleted, 'a plain-text field carries no blocks to reconcile');
    }

    public function testTheBlockQueryIsScopedToTheSiteBeingReconciled(): void
    {
        $writer = new InMemoryElementWriter();
        $query = new StubBlockQuery([StubBlock::withId(1)]);
        $entry = StubEntry::with(siteId: 7, blocksByField: ['pageBuilder' => $query]);

        $this->callPrivate(
            $this->service($writer),
            'wipeStaleSecondarySiteBlocks',
            $entry,
            ['pageBuilder' => [['type' => 'text', 'fields' => []]]],
            [],
        );

        self::assertSame(7, $query->scopedToSiteId, 'an unscoped query would delete other sites\' blocks');
        self::assertTrue($query->statusCleared, 'disabled blocks are still blocks and must be reconciled');
    }

    public function testEveryPayloadedFieldIsReconciledNotJustTheFirst(): void
    {
        $writer = new InMemoryElementWriter();
        $entry = StubEntry::with(siteId: 3, blocksByField: [
            'pageBuilder' => [StubBlock::withId(21)],
            'sidebar' => [StubBlock::withId(22)],
        ]);

        $this->callPrivate(
            $this->service($writer),
            'wipeStaleSecondarySiteBlocks',
            $entry,
            [
                'pageBuilder' => [['type' => 'text', 'fields' => []]],
                'sidebar' => [['type' => 'text', 'fields' => []]],
            ],
            [],
        );

        self::assertSame([21, 22], $writer->deletedIds());
    }
}

/**
 * A block query stands in for Craft's NestedElementQuery: the production code
 * reaches it duck-typed through method_exists, so this records the scoping
 * calls rather than reimplementing a query.
 *
 * @internal
 */
final class StubBlockQuery
{
    public ?int $scopedToSiteId = null;
    public bool $statusCleared = false;

    /** @param list<StubBlock> $blocks */
    public function __construct(private readonly array $blocks)
    {
    }

    public function siteId(int $siteId): self
    {
        $this->scopedToSiteId = $siteId;

        return $this;
    }

    public function status(?string $status): self
    {
        $this->statusCleared = $status === null;

        return $this;
    }

    /** @return list<StubBlock> */
    public function all(): array
    {
        return $this->blocks;
    }
}

/**
 * Nested entries in Craft 5 are Entry elements, so the stub is one — the
 * production signature takes ElementInterface and a duck-typed stand-in would
 * only prove the test's own shape.
 *
 * @internal
 */
final class StubBlock extends Entry
{
    public static function withId(int $id): self
    {
        $block = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $block->id = $id;

        return $block;
    }
}

/**
 * Entry is typed on the production signature, so the stub subclasses it and
 * supplies field values without the field layer a real one would need.
 *
 * @internal
 */
class StubEntry extends Entry
{
    /** @var array<string, StubBlockQuery|list<StubBlock>> */
    private array $blocksByField = [];

    /**
     * Entry's constructor resolves Yii application components, which this tier
     * has no booted app for — so the instance is built without it and the two
     * properties the reconciliation path reads are set directly.
     *
     * @param array<string, StubBlockQuery|list<StubBlock>> $blocksByField
     */
    public static function with(int $siteId, array $blocksByField): self
    {
        $entry = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $entry->siteId = $siteId;
        $entry->blocksByField = $blocksByField;

        return $entry;
    }

    public function getFieldValue(string $fieldHandle): mixed
    {
        $value = $this->blocksByField[$fieldHandle] ?? null;

        if ($value === null) {
            return null;
        }

        return $value instanceof StubBlockQuery ? $value : new StubBlockQuery($value);
    }
}
