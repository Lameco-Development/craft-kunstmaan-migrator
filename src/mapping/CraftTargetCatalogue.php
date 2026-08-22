<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\mapping;

use Craft;

final class CraftTargetCatalogue implements TargetCatalogue
{
    public function entryTypes(): array
    {
        $handles = array_map(
            static fn ($entryType): string => (string) $entryType->handle,
            Craft::$app->getEntries()->getAllEntryTypes(),
        );

        sort($handles);

        return array_values(array_unique($handles));
    }

    public function sections(): array
    {
        $handles = array_map(
            static fn ($section): string => (string) $section->handle,
            Craft::$app->getEntries()->getAllSections(),
        );

        sort($handles);

        return array_values(array_unique($handles));
    }
}
