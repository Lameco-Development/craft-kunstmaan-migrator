<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\load;

use craft\base\ElementInterface;
use craft\elements\Entry;
use craft\fields\Matrix;
use craft\fields\PlainText;
use craft\models\FieldLayout;
use Lameco\Kunstmaanmigrator\craft\ElementWriter;
use Lameco\Kunstmaanmigrator\load\EntryMigrationService;
use Lameco\Kunstmaanmigrator\tests\support\ConstructsNoElements;
use Lameco\Kunstmaanmigrator\tests\support\EnvironmentFactory;
use Lameco\Kunstmaanmigrator\tests\support\InMemoryElementWriter;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The failure-tolerance edges of block reconciliation. These paths delete
 * content, so every guard here is the difference between "skipped one field"
 * and "aborted a migration halfway" — or worse, "deleted someone's blocks".
 */
final class EntryMigrationServiceWipeGuardsTest extends TestCase
{
    private function callPrivate(EntryMigrationService $svc, string $method, mixed ...$args): mixed
    {
        return (new ReflectionMethod($svc, $method))->invoke($svc, ...$args);
    }

    private function service(ElementWriter $writer): EntryMigrationService
    {
        $svc = new EntryMigrationService();
        $svc->elementWriter = $writer;

        return $svc;
    }

    public function testAFieldWhoseValueCannotBeReadIsSkippedNotFatal(): void
    {
        $writer = new InMemoryElementWriter();
        $entry = WipeStubEntry::make(siteId: 3, fieldValues: []); // unknown handle → getFieldValue throws

        $this->callPrivate(
            $this->service($writer),
            'wipeStaleSecondarySiteBlocks',
            $entry,
            ['pageBuilder' => [['type' => 'text', 'fields' => []]]],
            [],
        );

        self::assertSame([], $writer->deleted);
    }

    public function testAValueThatIsNotABlockQueryIsLeftAlone(): void
    {
        $writer = new InMemoryElementWriter();
        $entry = WipeStubEntry::make(siteId: 3, fieldValues: ['pageBuilder' => new \stdClass()]);

        $this->callPrivate(
            $this->service($writer),
            'wipeStaleSecondarySiteBlocks',
            $entry,
            ['pageBuilder' => [['type' => 'text', 'fields' => []]]],
            [],
        );

        self::assertSame([], $writer->deleted);
    }

    public function testABlockWithoutAnIdIsNeverHandedToDelete(): void
    {
        $writer = new InMemoryElementWriter();
        $entry = WipeStubEntry::make(siteId: 3, fieldValues: [
            'pageBuilder' => new WipeStubBlockQuery([WipeStubBlock::withId(null), WipeStubBlock::withId(41)]),
        ]);

        $this->callPrivate(
            $this->service($writer),
            'wipeStaleSecondarySiteBlocks',
            $entry,
            ['pageBuilder' => [['type' => 'text', 'fields' => []]]],
            [],
        );

        self::assertSame([41], $writer->deletedIds());
    }

    public function testAFailingDeleteIsSwallowedSoTheRestOfTheRunContinues(): void
    {
        $writer = new RefusingDeleteWriter();
        $entry = WipeStubEntry::make(siteId: 3, fieldValues: [
            'pageBuilder' => new WipeStubBlockQuery([WipeStubBlock::withId(41), WipeStubBlock::withId(42)]),
        ]);

        $this->callPrivate(
            $this->service($writer),
            'wipeStaleSecondarySiteBlocks',
            $entry,
            ['pageBuilder' => [['type' => 'text', 'fields' => []]]],
            [],
        );

        self::assertSame([41, 42], $writer->attemptedIds, 'the second delete is still attempted after the first fails');
    }

    public function testAnEmptyPayloadWipesNothingOnUnpayloadedSites(): void
    {
        // With no payload there is no "keep" set — bailing out beats treating
        // every site as prunable.
        $writer = new InMemoryElementWriter();
        $entry = WipeStubEntry::make(siteId: 1, fieldValues: []);

        $this->callPrivate($this->service($writer), 'wipeBlocksOnUnpayloadedSites', $entry, [], EnvironmentFactory::sites());

        self::assertSame([], $writer->deleted);
    }

    public function testABlockWithoutAnIdCannotBeProvenAliveAnywhere(): void
    {
        $svc = new EntryMigrationService();
        $block = WipeStubBlock::withId(null);

        self::assertFalse($this->callPrivate($svc, 'blockLivesOnAnySite', $block, [1 => true]));
        self::assertFalse($this->callPrivate($svc, 'blockLivesOnAnySite', WipeStubBlock::withId(5), []));
    }

    public function testOnlyBlocksThisEntryOwnsAreCandidatesForRemoval(): void
    {
        // An Entries relation field also answers all(); its entries are other
        // people's content and must never surface here.
        $owned = WipeStubBlock::withId(41, ownerId: 10);
        $foreign = WipeStubBlock::withId(42, ownerId: 99);
        $noId = WipeStubBlock::withId(null, ownerId: 10);

        $entry = WipeStubEntry::make(
            siteId: 3,
            fieldValues: ['pageBuilder' => new WipeStubBlockQuery([$owned, $foreign, $noId])],
            layout: WipeStubFieldLayout::withFields([
                self::matrixField('pageBuilder'),
                self::plainTextField('body'),
            ]),
            id: 10,
        );

        $out = $this->callPrivate(new EntryMigrationService(), 'nestedEntriesOn', $entry);

        self::assertSame([$owned], $out);
    }

    public function testANonMatrixFieldContributesNoNestedEntries(): void
    {
        $entry = WipeStubEntry::make(
            siteId: 3,
            fieldValues: ['body' => new WipeStubBlockQuery([WipeStubBlock::withId(41, ownerId: 10)])],
            layout: WipeStubFieldLayout::withFields([self::plainTextField('body')]),
            id: 10,
        );

        $out = $this->callPrivate(new EntryMigrationService(), 'nestedEntriesOn', $entry);

        self::assertSame([], $out);
    }

    private static function matrixField(string $handle): Matrix
    {
        $field = (new \ReflectionClass(Matrix::class))->newInstanceWithoutConstructor();
        $field->handle = $handle;

        return $field;
    }

    private static function plainTextField(string $handle): PlainText
    {
        $field = (new \ReflectionClass(PlainText::class))->newInstanceWithoutConstructor();
        $field->handle = $handle;

        return $field;
    }
}

/**
 * @internal
 */
final class WipeStubEntry extends Entry
{
    /** @var array<string, mixed> */
    private array $fieldValuesByHandle = [];

    private ?FieldLayout $stubLayout = null;

    /** @param array<string, mixed> $fieldValues */
    public static function make(
        int $siteId,
        array $fieldValues,
        ?FieldLayout $layout = null,
        ?int $id = null,
    ): self {
        $entry = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $entry->siteId = $siteId;
        $entry->fieldValuesByHandle = $fieldValues;
        $entry->stubLayout = $layout;
        $entry->id = $id;

        return $entry;
    }

    public function getFieldValue(string $fieldHandle): mixed
    {
        if (!array_key_exists($fieldHandle, $this->fieldValuesByHandle)) {
            throw new \RuntimeException('unknown field: ' . $fieldHandle);
        }

        return $this->fieldValuesByHandle[$fieldHandle];
    }

    public function getFieldLayout(): ?FieldLayout
    {
        return $this->stubLayout;
    }
}

/**
 * @internal
 */
final class WipeStubBlock extends Entry
{
    private ?int $stubOwnerId = null;

    public static function withId(?int $id, ?int $ownerId = null): self
    {
        $block = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $block->id = $id;
        $block->stubOwnerId = $ownerId;

        return $block;
    }

    public function getPrimaryOwnerId(): ?int
    {
        return $this->stubOwnerId;
    }
}

/**
 * @internal
 */
final class WipeStubBlockQuery
{
    /** @param list<WipeStubBlock> $blocks */
    public function __construct(private readonly array $blocks)
    {
    }

    public function siteId(int $siteId): self
    {
        return $this;
    }

    public function status(?string $status): self
    {
        return $this;
    }

    /** @return list<WipeStubBlock> */
    public function all(): array
    {
        return $this->blocks;
    }
}

/**
 * @internal
 */
final class WipeStubFieldLayout extends FieldLayout
{
    /** @var list<\craft\base\FieldInterface> */
    private array $stubFields = [];

    /** @param list<\craft\base\FieldInterface> $fields */
    public static function withFields(array $fields): self
    {
        $layout = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $layout->stubFields = $fields;

        return $layout;
    }

    public function getCustomFields(): array
    {
        return $this->stubFields;
    }
}

/**
 * Delete always throws — the writer a broken Craft state would present.
 *
 * @internal
 */
final class RefusingDeleteWriter implements ElementWriter
{
    use ConstructsNoElements;

    /** @var list<int> */
    public array $attemptedIds = [];

    public function save(ElementInterface $element, bool $runValidation = true, bool $propagate = false): bool
    {
        return true;
    }

    public function delete(ElementInterface $element, bool $hardDelete = false): void
    {
        $this->attemptedIds[] = (int) $element->id;

        throw new \RuntimeException('delete refused');
    }

    public function findById(int $id, string $class, ?int $siteId = null): ?ElementInterface
    {
        return null;
    }

    public function invalidateCaches(): void
    {
    }

    public function structureEntries(string $sectionHandle): iterable
    {
        return [];
    }

    public function updateSlugAndUri(ElementInterface $element): void
    {
    }
}
