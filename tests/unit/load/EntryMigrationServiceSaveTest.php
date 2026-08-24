<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\load;

use craft\base\ElementInterface;
use craft\elements\Entry;
use craft\fields\Matrix;
use craft\models\EntryType;
use craft\models\FieldLayout;
use Lameco\Kunstmaanmigrator\craft\ElementWriter;
use Lameco\Kunstmaanmigrator\load\EntryMigrationService;
use Lameco\Kunstmaanmigrator\run\RunTally;
use Lameco\Kunstmaanmigrator\sites\SiteMap;
use Lameco\Kunstmaanmigrator\tests\support\EnvironmentFactory;
use Lameco\Kunstmaanmigrator\tests\support\InMemoryElementWriter;
use Lameco\Kunstmaanmigrator\tests\support\InMemoryMigrationState;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use yii\db\Exception as DbException;

/**
 * `saveEntryForSites()` driven through its interface, start to finish.
 *
 * Every earlier test of this path either stopped at the site guard or replaced
 * the method wholesale with a fake. What kept the real thing off the seam was
 * construction, not reads: `new Entry()`, the Single lookup and one raw query
 * boot Craft. With those three behind `ElementWriter`, the in-memory writer and
 * state are enough to walk the whole save — primary first, reload per site,
 * state row, block-id map — and assert on each decision.
 */
final class EntryMigrationServiceSaveTest extends TestCase
{
    private const SECTION = 4;
    private const TYPE = 7;
    private const SOURCE = 'COM:kuma_nodes';

    private InMemoryElementWriter $writer;
    private InMemoryMigrationState $state;
    private EntryMigrationService $svc;

    /** @var list<SaveStubEntry> every entry the writer handed out */
    private array $built = [];

    protected function setUp(): void
    {
        $this->writer = new InMemoryElementWriter();
        $this->writer->entryFactory = function(int $sectionId, int $typeId, int $siteId): Entry {
            return $this->built[] = SaveStubEntry::make($sectionId, $typeId, $siteId);
        };
        $this->state = new InMemoryMigrationState();
        $this->svc = new EntryMigrationService();
        $this->svc->elementWriter = $this->writer;
        $this->svc->stateService = $this->state;
    }

    /** `default` (1) is Craft's primary; `en` (2) and `de` (3) are bound; `fr` (9) is a Craft site no locale claims. */
    private function sites(): SiteMap
    {
        return EnvironmentFactory::sites(
            ['nl' => 'default', 'en' => 'en', 'de' => 'de'],
            ['default' => [1, 'nl-NL', true], 'en' => [2, 'en-GB'], 'de' => [3, 'de-DE'], 'fr' => [9, 'fr-FR']],
        );
    }

    /** @param array<string, mixed> $fieldValues */
    private function siteData(string $title, array $fieldValues = [], bool $enabled = true): array
    {
        return [
            'enabled' => $enabled,
            'title' => $title,
            'slug' => strtolower(str_replace(' ', '-', $title)),
            'fieldValues' => $fieldValues,
            'parentId' => null,
            'postDate' => null,
        ];
    }

    private function save(array $perSite, bool $force = false, ?RunTally $tally = null, ?SiteMap $sites = null): Entry
    {
        return $this->svc->saveEntryForSites(self::SECTION, self::TYPE, self::SOURCE, 42, $perSite, $sites ?? $this->sites(), $force, null, $tally);
    }

    public function testAFirstSaveBuildsTheEntryInThePrimarySiteAndWritesEverySiteThePayloadNames(): void
    {
        // The primary save stamps the id; the `en` pass reloads that id scoped
        // to site 2 and writes the row it gets back.
        $onEn = SaveStubEntry::make(self::SECTION, self::TYPE, 2);
        $this->writer->nextId = 600;
        $this->writer->willFind(600, $onEn, 2);

        $entry = $this->save([
            'default' => $this->siteData('Over ons'),
            'en' => $this->siteData('About us'),
        ]);

        self::assertSame([['sectionId' => self::SECTION, 'typeId' => self::TYPE, 'siteId' => 1]], $this->writer->created);
        self::assertSame(600, $entry->id);
        self::assertSame([$this->built[0], $onEn], array_column($this->writer->saved, 'element'), 'primary first, then the reload for `en`');
        self::assertSame('Over ons', $this->built[0]->title);
        self::assertSame('About us', $onEn->title);
        self::assertFalse($this->writer->saved[0]['propagate'], 'the migration writes each site itself');
        self::assertSame(600, $this->state->getTargetId(self::SOURCE, '42'));
    }

    public function testASiteThePayloadSaysNothingAboutIsLeftOutOfTheEnabledMapNotSetToFalse(): void
    {
        // `false` is not null: Craft propagates to every site in the map, so
        // naming `de` as disabled would have created a DE row for a page that
        // never existed in DE. Leaving it out is what stops propagation.
        $this->save([
            'default' => $this->siteData('Over ons'),
            'en' => $this->siteData('About us', enabled: false),
        ]);

        self::assertSame([1 => true, 2 => false], $this->built[0]->enabledMap);
    }

    public function testAPayloadWithoutThePrimarySiteDisablesTheRowCraftCreatesThereAnyway(): void
    {
        // The entry is always created in the primary site, so that row exists
        // whether or not the payload names it; unset, Craft would publish a
        // borrowed title at the primary URL.
        $this->save(['en' => $this->siteData('About us')]);

        self::assertSame([2 => true, 1 => false], $this->built[0]->enabledMap);
        self::assertSame('About us', $this->built[0]->title, 'the primary save borrows the one locale that has a title');
    }

    public function testThePrimarySiteIsCraftsNotTheFirstLocaleInTheMapping(): void
    {
        $sites = EnvironmentFactory::sites(
            ['en' => 'en', 'nl' => 'default'],
            ['en' => [2, 'en-GB'], 'default' => [1, 'nl-NL', true]],
        );

        $onEn = SaveStubEntry::make(self::SECTION, self::TYPE, 2);
        $this->writer->nextId = 600;
        $this->writer->willFind(600, $onEn, 2);

        $this->save([
            'en' => $this->siteData('About us'),
            'default' => $this->siteData('Over ons'),
        ], sites: $sites);

        self::assertSame(1, $this->writer->created[0]['siteId']);
        self::assertSame($this->built[0], $this->writer->saved[0]['element'], 'the primary is saved first');
        self::assertSame('Over ons', $this->built[0]->title, 'with its own data, not the first locale\'s');
    }

    public function testARerunWithoutForceReturnsTheExistingEntryAndWritesNothing(): void
    {
        $existing = SaveStubEntry::make(self::SECTION, self::TYPE, 1);
        $existing->id = 500;
        $this->state->willResolve(self::SOURCE, '42', 500);
        $this->writer->willFind(500, $existing, 1);

        $entry = $this->save(['default' => $this->siteData('Over ons')]);

        self::assertSame($existing, $entry);
        self::assertSame([], $this->writer->saved);
        self::assertSame([], $this->writer->created);
    }

    public function testARerunWithForceReloadsTheEntryAndSavesItAgain(): void
    {
        $existing = SaveStubEntry::make(self::SECTION, self::TYPE, 1);
        $existing->id = 500;
        $this->state->willResolve(self::SOURCE, '42', 500);
        $this->writer->willFind(500, $existing, 1);

        $entry = $this->save(['default' => $this->siteData('Over ons (v2)')], force: true);

        self::assertSame($existing, $entry);
        self::assertSame([], $this->writer->created, 'a forced re-run updates in place; it never constructs');
        self::assertSame('Over ons (v2)', $existing->title);
        self::assertSame([500], $this->writer->savedIds());
    }

    public function testASingleSectionReusesTheEntryCraftAlreadyMadeForIt(): void
    {
        $single = SaveStubEntry::make(self::SECTION, self::TYPE, 1);
        $single->id = 77;
        $this->writer->willFindSingle(self::SECTION, $single);

        $entry = $this->save(['default' => $this->siteData('Home')]);

        self::assertSame($single, $entry, 'a second entry in a Single fails URI validation');
        self::assertSame([], $this->writer->created);
        self::assertSame(77, $this->state->getTargetId(self::SOURCE, '42'));
    }

    public function testARefusedPrimarySaveThrowsNamingTheSourceRow(): void
    {
        $this->writer->entryFactory = function(int $sectionId, int $typeId, int $siteId): Entry {
            $entry = SaveStubEntry::make($sectionId, $typeId, $siteId);
            $this->writer->willRefuse($entry);

            return $entry;
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Primary-site save failed for ' . self::SOURCE . ':42');

        $this->save(['default' => $this->siteData('Over ons')]);
    }

    /**
     * A deadlock inside the save is the caller's to handle, not the seam's.
     *
     * The writer adapter used to retry the one element save that raised it.
     * The save runs inside the entry's transaction, and InnoDB rolls that whole
     * transaction back on a deadlock, so the retried element committed on top
     * of an entry whose earlier statements were already gone. Letting it
     * propagate is what makes `run\WriteConflictRetry` — which re-runs the
     * whole payload — the only retry there is.
     */
    public function testADeadlockDuringASavePropagatesOutOfTheEntrySave(): void
    {
        $deadlock = new DbException(
            'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock',
            ['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction'],
        );
        $this->svc->elementWriter = new class($this->writer, $deadlock) implements ElementWriter {
            public int $saves = 0;

            public function __construct(private readonly InMemoryElementWriter $inner, private readonly DbException $deadlock)
            {
            }

            public function createEntry(int $sectionId, int $typeId, int $siteId): Entry
            {
                return $this->inner->createEntry($sectionId, $typeId, $siteId);
            }

            public function singleEntry(int $sectionId, int $siteId): ?Entry
            {
                return $this->inner->singleEntry($sectionId, $siteId);
            }

            public function livesOnAnySite(int $elementId, array $siteIds): bool
            {
                return $this->inner->livesOnAnySite($elementId, $siteIds);
            }

            public function save(ElementInterface $element, bool $runValidation = true, bool $propagate = false): bool
            {
                $this->saves++;

                throw $this->deadlock;
            }

            public function delete(ElementInterface $element, bool $hardDelete = false): void
            {
                $this->inner->delete($element, $hardDelete);
            }

            public function findById(int $id, string $class, ?int $siteId = null): ?ElementInterface
            {
                return $this->inner->findById($id, $class, $siteId);
            }

            public function invalidateCaches(): void
            {
                $this->inner->invalidateCaches();
            }
        };

        try {
            $this->save(['default' => $this->siteData('Over ons'), 'en' => $this->siteData('About us')]);
            self::fail('the deadlock has to reach the caller');
        } catch (DbException $e) {
            self::assertSame($deadlock, $e, 'unchanged: the payload-level retry recognises it by its driver code');
        }

        self::assertSame(1, $this->svc->elementWriter->saves, 'the seam retried nothing');
        self::assertNull($this->state->getTargetId(self::SOURCE, '42'), 'no state row for an entry that never landed');
    }

    public function testARefusedSecondarySiteSaveIsAWarningAndTheEntryStillLands(): void
    {
        // Each site save is independent: a locale Craft refuses must not take
        // the primary row — or the state row — with it.
        $entry = SaveStubEntry::make(self::SECTION, self::TYPE, 1);
        $localised = SaveStubEntry::make(self::SECTION, self::TYPE, 2);
        $this->writer->entryFactory = static fn(): Entry => $entry;
        $this->writer->willRefuse($localised);

        // The primary save stamps the id; the `en` reload has to find its own row.
        $this->writer->nextId = 600;
        $this->writer->willFind(600, $localised, 2);

        $saved = $this->save([
            'default' => $this->siteData('Over ons'),
            'en' => $this->siteData('About us'),
        ]);

        self::assertSame($entry, $saved);
        self::assertSame([600], $this->writer->savedIds(), 'only the primary save went through');
        self::assertSame(600, $this->state->getTargetId(self::SOURCE, '42'));
    }

    public function testBlockIdsAreCollectedByPositionAndThreadedBackInOnTheNextRun(): void
    {
        // First run: the payload's blocks carry `_sourcePartRef`; after the
        // save the entry's field answers with the ids Craft assigned, in order.
        $this->writer->entryFactory = function(int $sectionId, int $typeId, int $siteId): Entry {
            $entry = SaveStubEntry::make($sectionId, $typeId, $siteId);
            $entry->blocksByField['pageBuilder'] = [SaveStubBlock::withId(901), SaveStubBlock::withId(902)];

            return $this->built[] = $entry;
        };

        $entry = $this->save(['default' => $this->siteData('Over ons', [
            'pageBuilder' => [
                'new1' => ['type' => 'text', 'fields' => ['_sourcePartRef' => 'TextPagePart:11', 'body' => 'a']],
                'new2' => ['type' => 'text', 'fields' => ['_sourcePartRef' => 'TextPagePart:12', 'body' => 'b']],
            ],
        ])]);

        $written = $this->built[0]->capturedFieldValues['pageBuilder'];
        self::assertSame(['new1', 'new2'], array_keys($written));
        self::assertArrayNotHasKey('_sourcePartRef', $written['new1']['fields'], 'the marker never reaches Craft');
        self::assertSame(
            ['blockIds' => ['default' => ['TextPagePart:11' => '901', 'TextPagePart:12' => '902']]],
            $this->state->metaOf(self::SOURCE, '42'),
        );

        // Second run, forced: the same refs come back keyed by the ids Craft
        // knows, so the blocks are updated in place rather than rebuilt.
        $this->writer->willFind((int) $entry->id, $this->built[0], 1);
        $this->save(['default' => $this->siteData('Over ons', [
            'pageBuilder' => [
                'new1' => ['type' => 'text', 'fields' => ['_sourcePartRef' => 'TextPagePart:11', 'body' => 'a2']],
                'new2' => ['type' => 'text', 'fields' => ['_sourcePartRef' => 'TextPagePart:12', 'body' => 'b2']],
            ],
        ])], force: true);

        self::assertSame([901, 902], array_keys($this->built[0]->capturedFieldValues['pageBuilder']));
    }

    public function testBlocksOnASiteThePayloadNeverWroteAreRemovedUnlessAPayloadedSiteStillOwnsThem(): void
    {
        // `de` gets no payload, so whatever Craft propagated there is an
        // artefact — except a nested entry that also has a row on `default`:
        // that is the same element the payloaded site renders.
        $matrix = (new \ReflectionClass(Matrix::class))->newInstanceWithoutConstructor();
        $matrix->handle = 'pageBuilder';

        $onDe = SaveStubEntry::make(self::SECTION, self::TYPE, 3);
        $onDe->id = 700;
        $onDe->layout = SaveStubFieldLayout::withFields([$matrix]);
        $onDe->blocksByField['pageBuilder'] = [
            SaveStubBlock::withId(801, ownerId: 700),
            SaveStubBlock::withId(802, ownerId: 700),
        ];
        $this->writer->willLiveOn(801, [1]);
        $this->writer->nextId = 700;
        $this->writer->willFind(700, $onDe, 3);

        $this->save(['default' => $this->siteData('Over ons')]);

        self::assertSame([802], $this->writer->deletedIds(), '801 lives on `default` too and is kept');
        self::assertTrue($this->writer->deleted[0]['hardDelete'], 'a stale nested entry must not come back from the recycle bin');
    }

    public function testAnUnpayloadedSiteWhereTheEntryHasNoRowIsSkipped(): void
    {
        // The in-memory writer answers a site-scoped lookup with the unscoped
        // element by default; here the entry must be absent on `fr`.
        $entry = SaveStubEntry::make(self::SECTION, self::TYPE, 1);
        $this->writer->entryFactory = static fn(): Entry => $entry;
        $this->writer->nextId = 650;
        $this->writer->willFindOnlyOnKnownSites(650);

        $this->save(['default' => $this->siteData('Over ons')]);

        self::assertSame([], $this->writer->deleted);
    }

    public function testTheSourceIdFieldIsStampedOnEverySiteSoTheRowJoinsBackToState(): void
    {
        $this->save([
            'default' => $this->siteData('Over ons'),
            'en' => $this->siteData('About us'),
        ]);

        self::assertSame(self::SOURCE . ':42', $this->built[0]->capturedFieldValues['kunstmaanSourceId']);
    }

    public function testALossTheTargetCannotRepresentIsCountedOnTheRunsTally(): void
    {
        $shared = (new \ReflectionClass(Matrix::class))->newInstanceWithoutConstructor();
        $shared->handle = 'pageBuilder';
        $shared->propagationMethod = \craft\enums\PropagationMethod::All;
        $this->writer->entryFactory = function(int $sectionId, int $typeId, int $siteId) use ($shared): Entry {
            $entry = SaveStubEntry::make($sectionId, $typeId, $siteId);
            $entry->layout = SaveStubFieldLayout::withFields([$shared]);

            return $this->built[] = $entry;
        };
        $tally = new RunTally();

        $this->save([
            'default' => $this->siteData('Over ons', ['pageBuilder' => ['new1' => ['type' => 'text', 'fields' => ['_sourcePartRef' => 'Text:1']]]]),
            'en' => $this->siteData('About us', ['pageBuilder' => ['new1' => ['type' => 'text', 'fields' => ['_sourcePartRef' => 'Text:2']]]]),
        ], tally: $tally);

        self::assertCount(1, $tally->perSiteBlockLosses);
        self::assertStringContainsString(self::SOURCE . ':42 field "pageBuilder"', $tally->perSiteBlockLosses[0]);
    }
}

/**
 * Built without Entry's constructor (no booted app). Answers the layout and
 * type from test-supplied stubs, captures what the save wrote, and serves a
 * Matrix field's blocks the way a saved entry would.
 *
 * @internal
 */
final class SaveStubEntry extends Entry
{
    /** @var array<string, mixed> */
    public array $capturedFieldValues = [];

    /** @var array<int, bool> */
    public array $enabledMap = [];

    /** @var array<string, list<SaveStubBlock>> */
    public array $blocksByField = [];

    public ?FieldLayout $layout = null;

    public static function make(int $sectionId, int $typeId, int $siteId): self
    {
        $entry = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $entry->sectionId = $sectionId;
        $entry->typeId = $typeId;
        $entry->siteId = $siteId;

        return $entry;
    }

    public function setEnabledForSite(array|bool $enabledForSite): void
    {
        $this->enabledMap = is_array($enabledForSite) ? $enabledForSite : [];
    }

    public function setFieldValues(array $values): void
    {
        $this->capturedFieldValues = $values;
    }

    public function getFieldValue(string $fieldHandle): mixed
    {
        if (!isset($this->blocksByField[$fieldHandle])) {
            throw new \RuntimeException('unknown field: ' . $fieldHandle);
        }

        return new SaveStubBlockQuery($this->blocksByField[$fieldHandle]);
    }

    public function getFieldLayout(): ?FieldLayout
    {
        return $this->layout;
    }

    public function getType(): EntryType
    {
        $type = (new \ReflectionClass(EntryType::class))->newInstanceWithoutConstructor();
        $type->handle = 'stubType';

        return $type;
    }

    public function getErrors($attribute = null): array
    {
        return ['title' => ['Refused by the test writer.']];
    }
}

/**
 * @internal
 */
final class SaveStubBlock extends Entry
{
    private ?int $stubOwnerId = null;

    public static function withId(int $id, ?int $ownerId = null): self
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
final class SaveStubBlockQuery
{
    /** @param list<SaveStubBlock> $blocks */
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

    /** @return list<SaveStubBlock> */
    public function all(): array
    {
        return $this->blocks;
    }
}

/**
 * @internal
 */
final class SaveStubFieldLayout extends FieldLayout
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

    public function getFieldByHandle(string $handle): ?\craft\base\FieldInterface
    {
        foreach ($this->stubFields as $field) {
            if ($field->handle === $handle) {
                return $field;
            }
        }

        return null;
    }
}
