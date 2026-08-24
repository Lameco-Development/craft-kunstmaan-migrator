<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\craft;

use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Entry;
use craft\models\Section;
use Throwable;
use yii\db\Exception as DbException;

/**
 * The production adapter: a thin pass-through to `Craft::$app->elements`.
 *
 * Deliberately thin. Every line of behaviour that lives here is a line no test
 * can reach, so the adapter does translation and nothing else — the decisions
 * stay in the modules, where a fake can drive them. The one exception is the
 * write-conflict retry below, which is translation of a database dialect, not
 * a decision.
 */
final class CraftElementWriter implements ElementWriter
{
    /**
     * A structure insert shifts nested-set boundaries, and the moment a CP tab
     * is open Craft's web runner processes slug/search jobs against the same
     * rows — MariaDB answers the loser with 1020 "record has changed since
     * last read" (or a 1213 deadlock). Both are retry-after-backoff conditions,
     * not errors: the migration is correct, it just lost a race a normal
     * operator's open browser tab creates.
     */
    private const RETRYABLE = ['1020', '1213', '40001'];

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
        $attempts = 0;

        while (true) {
            try {
                return Craft::$app->elements->saveElement($element, $runValidation, $propagate);
            } catch (Throwable $e) {
                if (++$attempts >= 4 || !self::isWriteConflict($e)) {
                    throw $e;
                }

                usleep($attempts * 200_000);
            }
        }
    }

    private static function isWriteConflict(Throwable $e): bool
    {
        for ($cursor = $e; $cursor !== null; $cursor = $cursor->getPrevious()) {
            if ($cursor instanceof DbException) {
                $code = (string) ($cursor->errorInfo[1] ?? '');
                $state = (string) ($cursor->errorInfo[0] ?? '');

                if (in_array($code, self::RETRYABLE, true) || in_array($state, self::RETRYABLE, true)) {
                    return true;
                }
            }
        }

        return false;
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
