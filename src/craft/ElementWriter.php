<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\craft;

use craft\base\ElementInterface;

/**
 * The write half's one seam at Craft.
 *
 * Every module that writes elements reached for `Craft::$app->elements`
 * directly. A static call admits no second adapter, so the only test surface
 * for 7,457 lines was a real database and a two-hour migration — which is why
 * the defects found in this half all cost hours to find and would each have
 * been a three-line assertion behind a seam.
 *
 * The surface is deliberately the one the write half actually uses, derived by
 * reading all nineteen call sites rather than by guessing: eleven saves, four
 * lookups, two deletes and two cache invalidations. Nothing else from Craft's
 * elements service belongs here until something needs it.
 *
 * Two adapters make it a real seam rather than a hypothetical one:
 * CraftElementWriter in production, InMemoryElementWriter in tests.
 */
interface ElementWriter
{
    /**
     * Saves an element, returning false rather than throwing when Craft
     * refuses it — callers read `$element->getErrors()` for the reason.
     *
     * `$propagate` defaults to false because this migration writes each site
     * explicitly: letting Craft propagate a save to sites the payload never
     * named is what leaked nested entries onto them.
     */
    public function save(ElementInterface $element, bool $runValidation = true, bool $propagate = false): bool;

    /**
     * Deletes an element. `$hardDelete` skips the soft-delete window, which is
     * what block reconciliation wants: a stale nested entry should not come
     * back from the recycle bin on the next run.
     */
    public function delete(ElementInterface $element, bool $hardDelete = false): void;

    /**
     * @param class-string<ElementInterface> $class
     */
    public function findById(int $id, string $class, ?int $siteId = null): ?ElementInterface;

    /**
     * Invalidates Craft's element caches after a bulk write.
     */
    public function invalidateCaches(): void;
}
