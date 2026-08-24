<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\support;

use lameco\kunstmaanmigrator\mapping\TargetCatalogue;

/** The second adapter: whatever the test says this install offers. */
final class InMemoryTargetCatalogue implements TargetCatalogue
{
    /**
     * @param list<string> $entryTypes
     * @param array<string, list<string>> $entryTypesBySection
     */
    public function __construct(
        private readonly array $entryTypes = [],
        private readonly array $entryTypesBySection = [],
    ) {
    }

    public function entryTypes(): array
    {
        return $this->entryTypes;
    }

    public function entryTypesBySection(): array
    {
        return $this->entryTypesBySection;
    }
}
