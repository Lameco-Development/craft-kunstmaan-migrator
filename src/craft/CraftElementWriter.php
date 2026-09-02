<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\craft;

use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Entry;
use craft\helpers\Db;
use craft\helpers\Queue as QueueHelper;
use craft\models\Section;
use craft\queue\jobs\UpdateSearchIndex;

/**
 * The production adapter: a thin pass-through to `Craft::$app->elements`.
 *
 * Deliberately thin. Every line of behaviour that lives here is a line no test
 * can reach, so the adapter does translation and nothing else — the decisions
 * stay in the modules, where a fake can drive them.
 *
 * In particular it does not retry. A save runs inside the entry's transaction,
 * and a deadlock rolls that whole transaction back; a retry here would commit
 * one element on top of an entry that is already gone. `run\WriteConflictRetry`
 * retries the payload instead, where the unit of work is idempotent.
 *
 * The search-index deferral is the one piece of state, and it is static:
 * `Plugin::wireServices()` hands every service its own instance of this
 * adapter, and the deferral is a fact about the run. Armed and disarmed by the
 * pipeline around the run; a process that dies armed takes the flag with it.
 */
final class CraftElementWriter implements ElementWriter
{
    private static bool $searchIndexDeferred = false;

    private static int $savesWhileDeferred = 0;

    public function createEntry(int $sectionId, int $typeId, int $siteId): Entry
    {
        $entry = new Entry();
        $entry->sectionId = $sectionId;
        $entry->typeId = $typeId;
        $entry->siteId = $siteId;

        return $entry;
    }

    public function singleEntry(int $sectionId, int $siteId): ?Entry
    {
        $section = Craft::$app->getEntries()->getSectionById($sectionId);

        if ($section === null || $section->type !== Section::TYPE_SINGLE) {
            return null;
        }

        $entry = Entry::find()->sectionId($sectionId)->siteId($siteId)->status(null)->one();

        if ($entry !== null) {
            return $entry;
        }

        // A Single has exactly one entry for the whole section, not one per site — so "no row
        // for this site" is not the same as "no entry exists". A section on propagationMethod
        // custom only gets an `elements_sites` row for a site once something actually saves it
        // there, and a second source environment landing its own HomePage row is often the
        // first save this section ever sees for that environment's site. Scoped straight to
        // $siteId, that read as "the Single doesn't exist yet" and the loader created a second
        // entry beside the one Craft already had. Look across every site before deciding that;
        // Craft's own CP never lets a Single grow a second entry, only this loader's direct
        // element-API writes could.
        $existing = Entry::find()->sectionId($sectionId)->site('*')->status(null)->one();

        if ($existing === null) {
            return null;
        }

        // Retarget the found entry at the requested site rather than the one it was found
        // through — the caller always writes into $siteId, and a site this entry has no row
        // for yet gets one on save, the same way Craft grows any existing entry into a newly
        // added site.
        $existing->siteId = $siteId;

        return $existing;
    }

    public function livesOnAnySite(int $elementId, array $siteIds): bool
    {
        if ($siteIds === []) {
            return false;
        }

        return (new Query())
            ->from(Table::ELEMENTS_SITES)
            ->where(['elementId' => $elementId])
            ->andWhere(['siteId' => $siteIds])
            ->exists();
    }

    public function siteIdsOf(int $elementId): array
    {
        $siteIds = (new Query())
            ->select('siteId')
            ->from(Table::ELEMENTS_SITES)
            ->where(['elementId' => $elementId])
            ->column();

        return array_map(intval(...), $siteIds);
    }

    public function save(ElementInterface $element, bool $runValidation = true, bool $propagate = false): bool
    {
        if (self::$searchIndexDeferred) {
            self::$savesWhileDeferred++;

            return Craft::$app->elements->saveElement($element, $runValidation, $propagate, updateSearchIndex: false);
        }

        return Craft::$app->elements->saveElement($element, $runValidation, $propagate);
    }

    public function deferSearchIndexing(): void
    {
        if (self::$searchIndexDeferred) {
            return;
        }

        self::$searchIndexDeferred = true;
        self::$savesWhileDeferred = 0;
    }

    public function resumeSearchIndexing(): int
    {
        self::$searchIndexDeferred = false;
        $deferred = self::$savesWhileDeferred;
        self::$savesWhileDeferred = 0;

        return $deferred;
    }

    public function nestedEntryIds(array $ownerIds): array
    {
        if ($ownerIds === []) {
            return [];
        }

        $ids = (new Query())
            ->select('entries.id')
            ->from(['entries' => Table::ENTRIES])
            ->innerJoin(['elements' => Table::ELEMENTS], '[[elements.id]] = [[entries.id]]')
            ->where(['entries.primaryOwnerId' => $ownerIds])
            ->andWhere(['elements.dateDeleted' => null, 'elements.draftId' => null, 'elements.revisionId' => null])
            ->column();

        return array_map(intval(...), $ids);
    }

    public function queueSearchIndex(string $elementType, array $elementIds): void
    {
        if ($elementIds === []) {
            return;
        }

        // Ahead of whatever Craft's own maintenance left at default priority,
        // with the chain's TTR: a chunk is a few hundred elements on every
        // site, and the channel default of 300 seconds is for one.
        QueueHelper::push(
            job: new UpdateSearchIndex([
                'elementType' => $elementType,
                'elementId' => $elementIds,
                'siteId' => '*',
            ]),
            priority: 512,
            ttr: 3600,
        );
    }

    public function delete(ElementInterface $element, bool $hardDelete = false): void
    {
        Craft::$app->elements->deleteElement($element, $hardDelete);
    }

    /**
     * @template T of ElementInterface
     * @param class-string<T> $class
     * @return T|null
     */
    public function findById(int $id, string $class, ?int $siteId = null): ?ElementInterface
    {
        return Craft::$app->elements->getElementById($id, $class, $siteId);
    }

    public function invalidateCaches(): void
    {
        Craft::$app->elements->invalidateAllCaches();
    }

    public function structureEntries(string $sectionHandle): iterable
    {
        $section = Craft::$app->getEntries()->getSectionByHandle($sectionHandle);

        if ($section === null || $section->type !== Section::TYPE_STRUCTURE) {
            return [];
        }

        // One row per element, on whichever site Craft prefers; the other
        // sites are reached through updateSlugAndUri(). Batched the way
        // Craft's own resave walks a section, so a corpus-sized Structure is
        // never all in memory at once.
        $query = Entry::find()
            ->sectionId($section->id)
            ->siteId('*')
            ->unique()
            ->status(null)
            ->orderBy(['structureelements.lft' => SORT_ASC]);

        /** @var iterable<Entry> */
        return Db::each($query);
    }

    public function updateSlugAndUri(ElementInterface $element): void
    {
        Craft::$app->elements->updateElementSlugAndUri(
            $element,
            updateOtherSites: true,
            updateDescendants: false,
            queue: false,
        );
    }
}
