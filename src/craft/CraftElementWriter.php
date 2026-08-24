<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\craft;

use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Entry;
use craft\models\Section;

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
 */
final class CraftElementWriter implements ElementWriter
{
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

        return Entry::find()->sectionId($sectionId)->siteId($siteId)->status(null)->one();
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

    public function save(ElementInterface $element, bool $runValidation = true, bool $propagate = false): bool
    {
        return Craft::$app->elements->saveElement($element, $runValidation, $propagate);
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
}
