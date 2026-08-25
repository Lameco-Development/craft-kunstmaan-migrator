<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\load;

use craft\base\ElementInterface;
use craft\elements\Entry;
use craft\fields\Matrix;
use craft\fields\PlainText;
use craft\models\FieldLayout;
use Lameco\Kunstmaanmigrator\craft\ElementWriter;
use Lameco\Kunstmaanmigrator\load\BlockIdentity;
use Lameco\Kunstmaanmigrator\tests\support\ConstructsNoElements;
use Lameco\Kunstmaanmigrator\tests\support\EnvironmentFactory;
use Lameco\Kunstmaanmigrator\tests\support\InMemoryElementWriter;
use PHPUnit\Framework\TestCase;

/**
 * Block identity through its interface: given this entry, this payload and the ids the
 * previous run tracked — which nested entries are deleted, which are threaded back in, and
 * what the next run gets to read.
 *
 * AUDIT §2 — 7,729 (entry, site) pairs of orphaned blocks — was found by running a migration
 * against MySQL and reading the damage afterwards, because the only way to observe what this
 * rule deletes was to let it delete. Here the same question is an assertion on the writer.
 */
final class BlockIdentityTest extends TestCase
{
    private InMemoryElementWriter $writer;

    protected function setUp(): void
    {
        $this->writer = new InMemoryElementWriter();
    }

    /** @param array<string, array<string, string|int>> $tracked */
    private function identity(array $tracked = []): BlockIdentity
    {
        return new BlockIdentity($this->writer, $tracked);
    }

    /** @param array<string, mixed> $fields */
    private static function block(string $ref, array $fields = []): array
    {
        return ['type' => 'text', 'fields' => ['_sourcePartRef' => $ref] + $fields];
    }

    // --- thread -----------------------------------------------------------------

    public function testAKnownSourceRefRekeysTheBlockToItsTrackedId(): void
    {
        $out = $this->identity(['en' => ['Text:5' => '901']])->thread('en', ['pageBuilder' => [
            'new1' => self::block('Text:5'),
            'new2' => self::block('Text:6'),
        ]]);

        self::assertSame([901, 'new2'], array_keys($out['pageBuilder']), 'unknown refs keep their new{n} key, order preserved');
        self::assertSame('Text:5', $out['pageBuilder'][901]['fields']['_sourcePartRef'], 'the marker is left for the strip that follows');
    }

    public function testIdsTrackedForAnotherSiteAreNotThreadedIntoThisOne(): void
    {
        // `propagationMethod: none` keeps separate nested entries per site; keying `en`'s
        // blocks by `default`'s ids would move the primary's blocks instead of updating en's.
        $payload = ['pageBuilder' => ['new1' => self::block('Text:5')]];

        self::assertSame($payload, $this->identity(['default' => ['Text:5' => '901']])->thread('en', $payload));
    }

    public function testANestedMatrixIsThreadedInsideItsParentBlock(): void
    {
        // Reusing a parent in place must reuse its children too, or the children are
        // rebuilt underneath it on every run.
        $out = $this->identity(['en' => ['Content:1' => '800', 'Column:9' => '801']])->thread('en', ['pageBuilder' => [
            'new1' => ['type' => 'contentBlock', 'fields' => [
                '_sourcePartRef' => 'Content:1',
                'columns' => ['new2' => ['type' => 'column', 'fields' => ['_sourcePartRef' => 'Column:9']]],
            ]],
        ]]);

        self::assertSame([800], array_keys($out['pageBuilder']));
        self::assertSame([801], array_keys($out['pageBuilder'][800]['fields']['columns']));
    }

    public function testAFieldThatIsNotAMatrixPayloadIsPassedThrough(): void
    {
        $payload = ['body' => 'just some text', 'tags' => [1, 2], 'empty' => []];

        self::assertSame($payload, $this->identity(['en' => ['Text:5' => '1']])->thread('en', $payload));
    }

    // --- record -----------------------------------------------------------------

    public function testSavedBlockIdsArePairedWithTheRefsByPosition(): void
    {
        $identity = $this->identity();
        $identity->thread('en', ['pageBuilder' => ['new1' => self::block('Text:5'), 'new2' => self::block('Text:6')]]);

        $identity->record('en', IdentityStubEntry::make(siteId: 2, blocks: [
            'pageBuilder' => [IdentityStubBlock::withId(901), IdentityStubBlock::withId(902)],
        ]));

        self::assertSame(['en' => ['Text:5' => '901', 'Text:6' => '902']], $identity->tracked());
    }

    public function testABlockWithoutARecordedRefContributesNothing(): void
    {
        $identity = $this->identity();
        $identity->thread('en', ['pageBuilder' => [
            'new1' => ['type' => 'text', 'fields' => []],
            'new2' => self::block('Text:6'),
        ]]);

        $identity->record('en', IdentityStubEntry::make(siteId: 2, blocks: [
            'pageBuilder' => [IdentityStubBlock::withId(901), IdentityStubBlock::withId(902)],
        ]));

        self::assertSame(['en' => ['Text:6' => '902']], $identity->tracked());
    }

    public function testChildBlockIdsAreRecordedThroughTheirParent(): void
    {
        $identity = $this->identity();
        $identity->thread('en', ['pageBuilder' => [
            'new1' => ['type' => 'contentBlock', 'fields' => [
                '_sourcePartRef' => 'Content:1',
                'columns' => ['new2' => ['type' => 'column', 'fields' => ['_sourcePartRef' => 'Column:9']]],
            ]],
        ]]);

        $child = IdentityStubBlock::withId(801);
        $identity->record('en', IdentityStubEntry::make(siteId: 2, blocks: [
            'pageBuilder' => [IdentityStubBlock::withId(800, children: ['columns' => [$child]])],
        ]));

        self::assertSame(['en' => ['Content:1' => '800', 'Column:9' => '801']], $identity->tracked());
    }

    public function testRecordingMergesIntoWhatThePreviousRunTracked(): void
    {
        $identity = $this->identity(['en' => ['Text:5' => '901']]);
        $identity->thread('en', ['pageBuilder' => ['901' => self::block('Text:5'), 'new2' => self::block('Text:6')]]);

        $identity->record('en', IdentityStubEntry::make(siteId: 2, blocks: [
            'pageBuilder' => [IdentityStubBlock::withId(901), IdentityStubBlock::withId(950)],
        ]));

        self::assertSame(['en' => ['Text:5' => '901', 'Text:6' => '950']], $identity->tracked());
    }

    public function testAFieldTheEntryCannotAnswerForIsSkippedNotFatal(): void
    {
        $identity = $this->identity();
        $identity->thread('en', ['pageBuilder' => ['new1' => self::block('Text:5')]]);

        $identity->record('en', IdentityStubEntry::make(siteId: 2, blocks: []));

        self::assertSame([], $identity->tracked());
    }

    public function testASiteNeverThreadedRecordsNothing(): void
    {
        $identity = $this->identity();

        $identity->record('de', IdentityStubEntry::make(siteId: 3, blocks: ['pageBuilder' => [IdentityStubBlock::withId(1)]]));

        self::assertSame([], $identity->tracked());
    }

    // --- fromMeta / tracked -----------------------------------------------------

    public function testThePerSiteMapIsReadBackFromTheStateRowsMeta(): void
    {
        $identity = BlockIdentity::fromMeta($this->writer, ['blockIds' => ['en' => ['Text:5' => 901]]], 'default');

        self::assertSame(['en' => ['Text:5' => '901']], $identity->tracked());
    }

    public function testMetaStoredAsJsonIsDecoded(): void
    {
        $identity = BlockIdentity::fromMeta($this->writer, '{"blockIds":{"en":{"Text:5":"901"}}}', 'default');

        self::assertSame(['en' => ['Text:5' => '901']], $identity->tracked());
    }

    public function testTheFlatMapAnOlderRowHoldsIsPromotedToThePrimarySite(): void
    {
        // Pre-nesting state rows carried one flat sourceRef→id map; re-runs against them
        // still thread the primary site's blocks at minimum.
        $identity = BlockIdentity::fromMeta($this->writer, ['blockUids' => ['Text:5' => '901']], 'default');

        self::assertSame(['default' => ['Text:5' => '901']], $identity->tracked());
    }

    public function testMetaWithoutBlockIdsTracksNothing(): void
    {
        self::assertSame([], BlockIdentity::fromMeta($this->writer, null, 'default')->tracked());
        self::assertSame([], BlockIdentity::fromMeta($this->writer, ['pendingRefs' => []], 'default')->tracked());
        self::assertSame([], BlockIdentity::fromMeta($this->writer, 'not json', 'default')->tracked());
    }

    // --- reconcile --------------------------------------------------------------

    public function testStaleBlocksOnASiteAreDeletedAndTrackedOnesAreKept(): void
    {
        $entry = IdentityStubEntry::make(siteId: 3, blocks: [
            'pageBuilder' => [IdentityStubBlock::withId(11), IdentityStubBlock::withId(12), IdentityStubBlock::withId(13)],
        ]);

        $this->identity(['de' => ['a' => 11, 'b' => '13']])->reconcile($entry, 'de', ['pageBuilder' => [self::block('a')]]);

        self::assertSame([12], $this->writer->deletedIds(), 'only the untracked block belongs to a previous run');
        self::assertTrue($this->writer->deleted[0]['hardDelete'], 'a stale nested entry must not come back from the recycle bin');
    }

    public function testNothingIsDeletedWhenEveryBlockIsTracked(): void
    {
        $entry = IdentityStubEntry::make(siteId: 3, blocks: [
            'pageBuilder' => [IdentityStubBlock::withId(11), IdentityStubBlock::withId(12)],
        ]);

        $this->identity(['de' => ['a' => 11, 'b' => 12]])->reconcile($entry, 'de', ['pageBuilder' => [self::block('a')]]);

        self::assertSame([], $this->writer->deleted);
    }

    public function testIdsTrackedForAnotherSiteDoNotProtectBlocksOnThisOne(): void
    {
        $entry = IdentityStubEntry::make(siteId: 3, blocks: ['pageBuilder' => [IdentityStubBlock::withId(11)]]);

        $this->identity(['default' => ['a' => 11]])->reconcile($entry, 'de', ['pageBuilder' => [self::block('a')]]);

        self::assertSame([11], $this->writer->deletedIds());
    }

    public function testOnlyFieldsThePayloadIsAboutToWriteAreReconciled(): void
    {
        $entry = IdentityStubEntry::make(siteId: 3, blocks: [
            'pageBuilder' => [IdentityStubBlock::withId(21)],
            'sidebar' => [IdentityStubBlock::withId(22)],
            'footer' => [IdentityStubBlock::withId(23)],
        ]);

        $this->identity()->reconcile($entry, 'de', [
            'pageBuilder' => [self::block('a')],
            'sidebar' => [self::block('b')],
            'body' => 'just some text',
        ]);

        self::assertSame([21, 22], $this->writer->deletedIds(), 'the footer is not in the payload and is left alone');
    }

    public function testTheBlockQueryIsScopedToTheSiteBeingReconciled(): void
    {
        $query = new IdentityStubBlockQuery([IdentityStubBlock::withId(1)]);
        $entry = IdentityStubEntry::make(siteId: 7, blocks: ['pageBuilder' => $query]);

        $this->identity()->reconcile($entry, 'de', ['pageBuilder' => [self::block('a')]]);

        self::assertSame(7, $query->scopedToSiteId, 'an unscoped query would delete other sites\' blocks');
        self::assertTrue($query->statusCleared, 'disabled blocks are still blocks and must be reconciled');
    }

    public function testAFieldWhoseValueCannotBeReadIsSkippedNotFatal(): void
    {
        $entry = IdentityStubEntry::make(siteId: 3, blocks: []);

        $this->identity()->reconcile($entry, 'de', ['pageBuilder' => [self::block('a')]]);

        self::assertSame([], $this->writer->deleted);
    }

    public function testAValueThatIsNotABlockQueryIsLeftAlone(): void
    {
        $entry = IdentityStubEntry::make(siteId: 3, blocks: ['pageBuilder' => new \stdClass()]);

        $this->identity()->reconcile($entry, 'de', ['pageBuilder' => [self::block('a')]]);

        self::assertSame([], $this->writer->deleted);
    }

    public function testABlockWithoutAnIdIsNeverHandedToDelete(): void
    {
        $entry = IdentityStubEntry::make(siteId: 3, blocks: [
            'pageBuilder' => [IdentityStubBlock::withId(null), IdentityStubBlock::withId(41)],
        ]);

        $this->identity()->reconcile($entry, 'de', ['pageBuilder' => [self::block('a')]]);

        self::assertSame([41], $this->writer->deletedIds());
    }

    public function testAFailingDeleteIsSwallowedSoTheRestOfTheRunContinues(): void
    {
        $writer = new RefusingDeleteWriter();
        $entry = IdentityStubEntry::make(siteId: 3, blocks: [
            'pageBuilder' => [IdentityStubBlock::withId(41), IdentityStubBlock::withId(42)],
        ]);

        (new BlockIdentity($writer))->reconcile($entry, 'de', ['pageBuilder' => [self::block('a')]]);

        self::assertSame([41, 42], $writer->attemptedIds, 'the second delete is still attempted after the first fails');
    }

    // --- prune ------------------------------------------------------------------

    /** `default` (1) is primary; `en` (2) and `de` (3) are bound; `fr` (9) is a Craft site no locale claims. */
    private function sites(): \Lameco\Kunstmaanmigrator\sites\SiteMap
    {
        return EnvironmentFactory::sites(
            ['nl' => 'default', 'en' => 'en', 'de' => 'de'],
            ['default' => [1, 'nl-NL', true], 'en' => [2, 'en-GB'], 'de' => [3, 'de-DE'], 'fr' => [9, 'fr-FR']],
        );
    }

    public function testBlocksOnSitesThePayloadNeverNamedAreDeletedUnlessAPayloadedSiteSharesThem(): void
    {
        $entry = IdentityStubEntry::make(siteId: 1, blocks: [], id: 700);
        $onDe = IdentityStubEntry::make(siteId: 3, blocks: [
            'pageBuilder' => [IdentityStubBlock::withId(801, ownerId: 700), IdentityStubBlock::withId(802, ownerId: 700)],
        ], layout: IdentityStubFieldLayout::withFields([self::matrixField('pageBuilder')]), id: 700);
        $this->writer->willFind(700, $onDe, 3);
        $this->writer->willFindOnlyOnKnownSites(700);
        $this->writer->willLiveOn(801, [1]);

        $this->identity()->prune($entry, ['default' => []], $this->sites());

        self::assertSame([802], $this->writer->deletedIds(), '801 lives on `default` too and is kept');
        self::assertTrue($this->writer->deleted[0]['hardDelete']);
    }

    public function testEveryCraftSiteIsPrunedNotOnlyTheEnvironments(): void
    {
        // Propagation does not stop at the mapping, so neither can the pruning: `fr` is
        // bound to no locale and still gets a lookup.
        $entry = IdentityStubEntry::make(siteId: 1, blocks: [], id: 700);
        $onFr = IdentityStubEntry::make(siteId: 9, blocks: [
            'pageBuilder' => [IdentityStubBlock::withId(803, ownerId: 700)],
        ], layout: IdentityStubFieldLayout::withFields([self::matrixField('pageBuilder')]), id: 700);
        $this->writer->willFind(700, $onFr, 9);
        $this->writer->willFindOnlyOnKnownSites(700);

        $this->identity()->prune($entry, ['default' => [], 'en' => []], $this->sites());

        self::assertSame([803], $this->writer->deletedIds());
    }

    public function testAnUnpayloadedSiteWhereTheEntryHasNoRowIsSkipped(): void
    {
        $entry = IdentityStubEntry::make(siteId: 1, blocks: [], id: 700);
        $this->writer->willFindOnlyOnKnownSites(700);

        $this->identity()->prune($entry, ['default' => []], $this->sites());

        self::assertSame([], $this->writer->deleted);
    }

    public function testAnEmptyPayloadPrunesNothing(): void
    {
        // With no payload there is no "keep" set — bailing out beats treating every site
        // as prunable.
        $entry = IdentityStubEntry::make(siteId: 1, blocks: [], id: 700);
        $this->writer->willFind(700, IdentityStubEntry::make(siteId: 3, blocks: [
            'pageBuilder' => [IdentityStubBlock::withId(801, ownerId: 700)],
        ], layout: IdentityStubFieldLayout::withFields([self::matrixField('pageBuilder')]), id: 700), 3);

        $this->identity()->prune($entry, [], $this->sites());

        self::assertSame([], $this->writer->deleted);
    }

    public function testOnlyBlocksThisEntryOwnsAreCandidatesForRemoval(): void
    {
        // An Entries relation field also answers all(); its entries are other people's
        // content and must never surface here.
        $entry = IdentityStubEntry::make(siteId: 1, blocks: [], id: 10);
        $onDe = IdentityStubEntry::make(siteId: 3, blocks: [
            'pageBuilder' => [
                IdentityStubBlock::withId(41, ownerId: 10),
                IdentityStubBlock::withId(42, ownerId: 99),
                IdentityStubBlock::withId(null, ownerId: 10),
            ],
            'related' => [IdentityStubBlock::withId(43, ownerId: 10)],
        ], layout: IdentityStubFieldLayout::withFields([
            self::matrixField('pageBuilder'),
            self::plainTextField('related'),
        ]), id: 10);
        $this->writer->willFind(10, $onDe, 3);
        $this->writer->willFindOnlyOnKnownSites(10);

        $this->identity()->prune($entry, ['default' => []], $this->sites());

        self::assertSame([41], $this->writer->deletedIds());
    }

    // --- withoutBlocks / sourceRefs ---------------------------------------------

    public function testWithoutBlocksEmptiesEveryMatrixPayloadAndLeavesTheRestAlone(): void
    {
        $out = BlockIdentity::withoutBlocks([
            'pageBuilder' => ['new1' => self::block('a')],
            'sidebar' => ['new1' => self::block('b')],
            'body' => 'text',
            'tags' => [1, 2],
        ]);

        self::assertSame(['pageBuilder' => [], 'sidebar' => [], 'body' => 'text', 'tags' => [1, 2]], $out);
    }

    public function testSourceRefsAreASetPerTopLevelMatrixField(): void
    {
        $refs = BlockIdentity::sourceRefs([
            'pageBuilder' => [
                self::block('Text:5', ['columns' => ['new2' => ['type' => 'column', 'fields' => ['_sourcePartRef' => 'Column:9']]]]),
                ['type' => 'text', 'fields' => []],
            ],
            'body' => 'not a matrix payload',
        ]);

        self::assertSame(['pageBuilder' => ['Text:5' => true]], $refs, 'nested refs belong to their parent, not the field');
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
 * Entry is typed on the production signature, so the stub subclasses it — built without the
 * constructor, which resolves Yii components this tier has no booted app for. Answers
 * getFieldValue with a duck-typed block query the way Craft's Matrix field does; an unknown
 * handle throws, the way an unmapped field would.
 *
 * @internal
 */
final class IdentityStubEntry extends Entry
{
    /** @var array<string, mixed> */
    private array $blocksByField = [];

    private ?FieldLayout $stubLayout = null;

    /** @param array<string, mixed> $blocks field handle → list of blocks, a query, or anything else */
    public static function make(int $siteId, array $blocks, ?FieldLayout $layout = null, ?int $id = null): self
    {
        $entry = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $entry->siteId = $siteId;
        $entry->blocksByField = $blocks;
        $entry->stubLayout = $layout;
        $entry->id = $id;

        return $entry;
    }

    public function getFieldValue(string $fieldHandle): mixed
    {
        if (!array_key_exists($fieldHandle, $this->blocksByField)) {
            throw new \RuntimeException('unknown field: ' . $fieldHandle);
        }

        $value = $this->blocksByField[$fieldHandle];

        return is_array($value) ? new IdentityStubBlockQuery($value) : $value;
    }

    public function getFieldLayout(): ?FieldLayout
    {
        return $this->stubLayout;
    }
}

/**
 * A nested entry that can itself carry nested Matrixes, for the child-collection path.
 *
 * @internal
 */
final class IdentityStubBlock extends Entry
{
    private ?int $stubOwnerId = null;

    /** @var array<string, list<IdentityStubBlock>> */
    private array $children = [];

    /** @param array<string, list<IdentityStubBlock>> $children */
    public static function withId(?int $id, ?int $ownerId = null, array $children = []): self
    {
        $block = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $block->id = $id;
        $block->stubOwnerId = $ownerId;
        $block->children = $children;

        return $block;
    }

    public function getPrimaryOwnerId(): ?int
    {
        return $this->stubOwnerId;
    }

    public function getFieldValue(string $fieldHandle): mixed
    {
        if (!isset($this->children[$fieldHandle])) {
            throw new \RuntimeException('not a matrix field: ' . $fieldHandle);
        }

        return new IdentityStubBlockQuery($this->children[$fieldHandle]);
    }
}

/**
 * Stands in for Craft's NestedElementQuery: the production code reaches it duck-typed
 * through method_exists, so this records the scoping calls rather than reimplementing a query.
 *
 * @internal
 */
final class IdentityStubBlockQuery
{
    public ?int $scopedToSiteId = null;
    public bool $statusCleared = false;

    /** @param list<IdentityStubBlock> $blocks */
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

    /** @return list<IdentityStubBlock> */
    public function all(): array
    {
        return $this->blocks;
    }
}

/**
 * @internal
 */
final class IdentityStubFieldLayout extends FieldLayout
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
