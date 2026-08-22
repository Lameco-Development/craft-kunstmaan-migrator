<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\craft;

use Craft;
use craft\base\ElementInterface;

/**
 * The production adapter: a thin pass-through to `Craft::$app->elements`.
 *
 * Deliberately thin. Every line of behaviour that lives here is a line no test
 * can reach, so the adapter does translation and nothing else — the decisions
 * stay in the modules, where a fake can drive them.
 */
final class CraftElementWriter implements ElementWriter
{
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
