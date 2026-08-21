<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\support;

use craft\base\ElementInterface;
use lameco\kunstmaanmigrator\craft\ElementWriter;

/**
 * The second adapter, and the reason the seam is real rather than
 * hypothetical: it records what the write half asked Craft to do, so a test
 * can assert on the decision instead of on the database afterwards.
 */
final class InMemoryElementWriter implements ElementWriter
{
    /** @var list<array{element: ElementInterface, runValidation: bool, propagate: bool}> */
    public array $saved = [];

    /** @var list<array{element: ElementInterface, hardDelete: bool}> */
    public array $deleted = [];

    public int $cacheInvalidations = 0;

    /** @var array<string, ElementInterface> */
    private array $findable = [];

    /** Elements this writer should refuse to save, by spl_object_id. */
    private array $refuse = [];

    public function save(ElementInterface $element, bool $runValidation = true, bool $propagate = false): bool
    {
        if (isset($this->refuse[spl_object_id($element)])) {
            return false;
        }

        $this->saved[] = [
            'element' => $element,
            'runValidation' => $runValidation,
            'propagate' => $propagate,
        ];

        return true;
    }

    public function delete(ElementInterface $element, bool $hardDelete = false): void
    {
        $this->deleted[] = ['element' => $element, 'hardDelete' => $hardDelete];
    }

    public function findById(int $id, string $class, ?int $siteId = null): ?ElementInterface
    {
        return $this->findable[$this->key($id, $siteId)] ?? $this->findable[$this->key($id, null)] ?? null;
    }

    public function invalidateCaches(): void
    {
        $this->cacheInvalidations++;
    }

    public function willFind(int $id, ElementInterface $element, ?int $siteId = null): void
    {
        $this->findable[$this->key($id, $siteId)] = $element;
    }

    public function willRefuse(ElementInterface $element): void
    {
        $this->refuse[spl_object_id($element)] = true;
    }

    /** @return list<int> ids of the elements deleted, in call order */
    public function deletedIds(): array
    {
        return array_map(static fn (array $row): int => (int) $row['element']->id, $this->deleted);
    }

    private function key(int $id, ?int $siteId): string
    {
        return $id . ':' . ($siteId ?? '*');
    }
}
